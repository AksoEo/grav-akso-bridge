import { Server } from 'net';
import { workerData, parentPort } from 'worker_threads';
import { AppClient, UserClient, generateTotp, util } from '@tejo/akso-client';
import { evaluate, currencies } from '@tejo/akso-script';
import '@tejo/akso-script/country_fmt.js';
import '@tejo/akso-script/phone_fmt.js';
import libPhoneNumber from 'google-libphonenumber';
const { PhoneNumberUtil, PhoneNumberFormat } = libPhoneNumber;
import { CookieJar } from 'tough-cookie';
import { encode, decode } from '@msgpack/msgpack';
import { setThreadName, info, debug, warn, error } from './log.js';
import path from 'path';
import { promisify } from 'util';
import fs from 'fs';
import crypto from 'crypto';
import { Cashify } from 'cashify';
import fetch from 'cross-fetch';
import Markdown from 'markdown-it';
import MarkdownMultiMdTable from 'markdown-it-multimd-table';
import { remark } from 'remark';
import { formatAddress, normalizeAddress } from '@cpsdqs/google-i18n-address';
import { URL } from 'url';
import { visit } from 'unist-util-visit';

process.on('uncaughtException', err => {
    error(`!!!! uncaught exception`);
    console.error(err);
});

const pendingAcquire = new Map();
const pendingRelease = new Map();

setThreadName(`W${workerData.id}`);
parentPort.on('message', message => {
    if (!message) return;
    if (message.type === 'init') {
        init(message.channel);
    } else if (message.type === 'acquire-ack') {
        if (pendingAcquire.has(message.id)) {
            pendingAcquire.get(message.id)();
            pendingAcquire.delete(message.id);
        }
    } else if (message.type === 'release-ack') {
        if (pendingRelease.has(message.id)) {
            pendingRelease.get(message.id)();
            pendingRelease.delete(message.id);
        }
    }
});

// The disk cache uses file names as an index for easy retrieval.
// File names are the following base64(msgpack)-encoded objects:
//
// ```json
// [apiHost, method, path, query]
// ```
//
// object keys in query must be alphanumerically sorted.
//
// The file contents will then hold the following msgpack-encoded data:
//
// ```json
// { maxAge: max age in seconds, data: (data object), raw: string? }
// ```
//
// If raw is set to a file name, this item is associated with a raw data file.
// Raw files MUST be refcounted (using acquire & release).
//
// Temporary files are used for writing. They will always start with a $ character.
const cachePath = workerData.cachePath;

// The raw cache stores raw data files (such as images). They are referenced by cache files.
const rawCachePath = workerData.rawCachePath;

function alphaSortObject (value) {
    if (Array.isArray(value)) return value;
    else if (typeof value === 'object' && value !== null) {
        const keys = Object.keys(value).sort();
        const res = {};
        for (const k of keys) res[k] = value[k];
        return res;
    } else return value;
}
function getCacheKey (host, method, path, query) {
    const hash = crypto.createHash('sha256');
    hash.update(Buffer.from(encode([host, method, path, alphaSortObject(query)])));
    return hash.digest('hex');
}

const fsStat = promisify(fs.stat);
const fsReadFile = promisify(fs.readFile);
const fsWriteFile = promisify(fs.writeFile);
const fsRename = promisify(fs.rename);

const cache = {
    get: async (host, method, resPath, query) => {
        const filePath = path.join(cachePath, getCacheKey(host, method, resPath, query));

        // first, figure out file age
        let stats;
        try {
            stats = await fsStat(filePath);
        } catch (err) {
            if (err.code === 'ENOENT') {
                // no cache file
                debug('No cache file: ' + filePath);
                return null;
            } else throw err;
        }
        const age = (Date.now() - stats.mtimeMs) / 1000;

        // read the file
        const contents = await fsReadFile(filePath);
        const rootNode = decode(contents);

        // if the file is older than we want, we pretend we don’t have anything
        // cached
        if (age > rootNode.maxAge) return null;
        else return rootNode.data; // otherwise return the data
    },
    acquire: async (host, method, resPath, key) => {
        const filePath = path.join(cachePath, getCacheKey(host, method, resPath, key));
        const id = Math.random().toString(36);
        parentPort.postMessage({ type: 'acquire', id, path: filePath });
        await new Promise(r => {
            pendingAcquire.set(id, r);
        });
    },
    release: async (host, method, resPath, key) => {
        const filePath = path.join(cachePath, getCacheKey(host, method, resPath, key));
        const id = Math.random().toString(36);
        parentPort.postMessage({ type: 'release', id, path: filePath });
        await new Promise(r => {
            pendingRelease.set(id, r);
        });
    },
    insert: async (host, method, resPath, key, maxCacheSecs, data, raw = null) => {
        const filePath = path.join(cachePath, getCacheKey(host, method, resPath, key));
        debug(`Insert cache for ${method} ${resPath}: ${filePath}`);

        const tmpWritePath = path.join(cachePath, '$' + Math.random().toString(36));

        const fileData = encode({
            maxAge: maxCacheSecs,
            data,
            raw,
        });

        // write the data into a new tmp file
        await fsWriteFile(tmpWritePath, fileData);

        // rename the file to the target name
        await fsRename(tmpWritePath, filePath);
    },
};

function init (channel) {
    debug('initializing');

    channel.on('message', message => {
        if (message.type === 'close') {
            debug('closing server');
            server.close();
            info('server closed');
            setTimeout(() => {
                channel.close();
            }, 30);
        }
    });

    const server = new Server(conn => {
        new ClientHandler(conn);
    });

    const listenAddr = `${workerData.path}/ipc${workerData.id}`;
    server.listen({
        path: listenAddr,
        readableAll: true,
        writeableAll: true
    }, () => {
        fs.chmodSync(listenAddr, 0o777);
        info(`listening on ${listenAddr}`);
    });
}

const MAGIC = Buffer.from('abx1');
const MAX_SANE_MESSAGE_LEN = 10 * 1024 * 1024; // 10 MiB

const encodeMessage = msg => {
    const packed = Buffer.from(encode(msg));
    const buf = Buffer.allocUnsafe(packed.length + 4);
    buf.writeInt32LE(packed.length, 0);
    packed.copy(buf, 4);
    return buf;
};

class ClientHandler {
    constructor (connection) {
        this.connection = connection;
        this.connection.setTimeout(1000);
        this.connection.on('data', data => this.onData(data));
        this.connection.on('timeout', () => this.onTimeout());
        this.connection.on('close', () => this.onClose().catch(err => {
            error('failed to close connection: ' + err);
        }));

        this.didInit = false;
        this.currentMessageLen = null;
        this.currentMessageBuffer = null;
        this.currentMessageCursor = 0;
        this.didEnd = false;

        // -----
        this.apiHost = null;
        this.didHandshake = false;
        this.ip = null;
        this.cookies = null;
        this.client = null;
        this.waitTasks = 0;
        this.rawResourceLocks = new Map();
    }

    flushMessage () {
        this.currentMessageLen = null;
        let decoded;
        try {
            decoded = decode(this.currentMessageBuffer);
            if (typeof decoded !== 'object') throw new Error('expected object as root');
            if (typeof decoded.t !== 'string') throw new Error('expected t: string');
            if (typeof decoded.i !== 'string' && !(decoded.i instanceof Buffer)) throw new Error('expected i: string or Buffer');
        } catch (err) {
            this.close('TXERR', 402, `failed to decode input: ${err}`);
            return;
        }

        this.handleInput(decoded);
    }

    onData (data) {
        let cursor = 0;

        if (!this.didInit) {
            if (data.slice(0, MAGIC.length).equals(MAGIC)) {
                this.didInit = true;
                cursor += MAGIC.length;
            } else {
                this.close('TXERR', 400, 'bad magic');
                return;
            }
        }

        while (cursor < data.length) {
            if (this.currentMessageLen === null) {
                // awaiting message length
                this.currentMessageLen = data.readInt32LE(cursor);
                if (this.currentMessageLen < 0) {
                    this.close('TXERR', 401, 'message has negative length');
                    return;
                } else if (this.currentMessageLen > MAX_SANE_MESSAGE_LEN) {
                    this.close('TXERR', 401, 'message is too long');
                    return;
                }
                cursor += 4;
                this.currentMessageBuffer = Buffer.allocUnsafe(this.currentMessageLen);
                this.currentMessageCursor = 0;
            } else {
                // message contents
                const messageBytesLeft = this.currentMessageLen - this.currentMessageCursor;
                const inputBytesLeft = data.length - cursor;

                if (inputBytesLeft >= messageBytesLeft) {
                    // rest of message is entirely contained in data
                    data.copy(this.currentMessageBuffer, this.currentMessageCursor, cursor, cursor + messageBytesLeft);
                    this.flushMessage();
                    cursor += messageBytesLeft;
                } else {
                    // data contains a part of the message
                    data.copy(this.currentMessageBuffer, this.currentMessageCursor, cursor, cursor + inputBytesLeft);
                    this.currentMessageCursor += inputBytesLeft;
                    cursor += inputBytesLeft;
                }
            }
        }
    }

    send (data) {
        if (this.didEnd) return;
        this.connection.write(encodeMessage(data));
    }

    close (t, c, m) {
        if (this.didEnd) return;
        this.didEnd = true;
        this.connection.end(encodeMessage({ t, c, m }));
    }

    async onClose () {
        this.flushSendCookies();
        this.didEnd = true;
        for (const [key, lock] of this.rawResourceLocks) {
            for (let i = 0; i < lock.rc; i++) {
                await cache.release(this.apiHost, lock.method, lock.resPath, lock.key);
            }
        }
        this.rawResourceLocks.clear();
    }

    onTimeout () {
        if (this.waitTasks > 0) {
            this.send({ t: '❤' });
        } else {
            debug('Connection timed out');
            this.close('TXERR', 103, 'timed out');
        }
    }

    acquire(method, resPath, key) {
        const res = cache.acquire(this.apiHost, method, resPath, key);
        const cacheKey = getCacheKey(this.apiHost, method, resPath, key);
        let k = this.rawResourceLocks.get(cacheKey)?.rc | 0;
        this.rawResourceLocks.set(cacheKey, {
            method,
            resPath,
            key,
            rc: k + 1,
        });
        return res;
    }

    // -----

    handleInput (message) {
        if (!this.didHandshake && message.t !== 'hi' && message.t !== 'hic') return;

        const handler = messageHandlers[message.t];
        if (!handler) {
            this.close('TXERR', 200, `unknown message type ${message.t}`);
            return;
        }

        const onStreamChunk = (streamData) => {
            this.send({
                t: '~s',
                i: message.i,
                ...streamData,
            });
        };

        this.waitTasks++;
        handler(this, message, onStreamChunk).then(response => {
            this.send({
                t: '~',
                i: message.i,
                ...response,
            });
        }).catch(err => {
            this.send({
                t: '~!',
                i: message.i,
                m: err.toString(),
            });
        }).then(() => {
            this.waitTasks--;
        });
    }

    debouncedSetCookie = null;
    cookieQueue = [];
    flushSendCookies () {
        clearTimeout(this.debouncedSetCookie);
        if (this.cookieQueue.length) {
            this.send({ t: 'co', co: this.cookieQueue });
            this.cookieQueue = [];
        }
    }

    recordSetCookie (cookie) {
        this.cookieQueue.push(cookie);
        if (!this.debouncedSetCookie) {
            this.debouncedSetCookie = setTimeout(() => this.flushSendCookies(), 1);
        }
    }
}

function assertType (v, t, n) {
    let chk = typeof v === t;
    if (t === 'array') chk = Array.isArray(v);
    if (!chk) {
        throw new Error(n);
    }
}

const transformUserAgent = ua => ua ? `${ua} (via ${workerData.userAgent})` : ua;

const messageHandlers = {
    hi: async (conn, { ip, co, api, ua }) => {
        debug(`connection handshake from ip ${ip}`);
        assertType(api, 'string', 'expected api to be a string');
        assertType(ip, 'string', 'expected ip to be a string');
        assertType(co, 'object', 'expected co to be an object');
        assertType(ua, 'string', 'expected ua to be a string');
        if (conn.didHandshake) {
            throw new Error('double handshake');
        }

        const apiHost = api;

        conn.apiHost = apiHost;
        conn.cookies = {
            conn,
            jar: new CookieJar(),
            // fetch-cookie only uses these two methods
            getCookieString (...args) {
                return this.jar.getCookieString(...args);
            },
            setCookie (cookie, url, callback) {
                const { conn, jar } = this;
                conn.recordSetCookie(cookie); // FIXME: should url be checked?
                return jar.setCookie(cookie, url, callback);
            },
        };

        // initialize cookies
        for (const k in co) {
            const v = co[k];
            assertType(v, 'string', 'expected cookie value to be a string');
            conn.cookies.jar.setCookieSync(`${k}=${v}`, apiHost);
        }

        conn.client = new UserClient({
            host: apiHost,
            userAgent: transformUserAgent(ua) || workerData.userAgent,
            cookieJar: conn.cookies,
            headers: {
                'X-Forwarded-For': ip,
            },
        });

        conn.didHandshake = true;

        const sesx = await conn.client.restoreSession();
        if (sesx === false) return { auth: false };

        const needsTotp = sesx.isAdmin && !sesx.totpSetUp;
        return {
            auth: true,
            uea: sesx.newCode,
            id: sesx.id,
            totp: needsTotp || (sesx.totpSetUp && !sesx.totpUsed),
            needs_totp: needsTotp,
            member: sesx.isActiveMember,
        };
    },
    hic: async (conn, { api, key, sec, ua }) => {
        debug(`api client handshake for ${key}`);
        assertType(api, 'string', 'expected api to be a string');
        assertType(key, 'string', 'expected key to be a string');
        assertType(sec, 'string', 'expected sec to be a string');
        assertType(ua, 'string', 'expected ua to be a string');
        if (conn.didHandshake) {
            throw new Error('double handshake');
        }

        const apiHost = api;
        conn.apiHost = apiHost;

        conn.client = new AppClient({
            host: apiHost,
            userAgent: transformUserAgent(ua) || workerData.userAgent,
            apiKey: key,
            apiSecret: sec,
        });


        conn.didHandshake = true;

        return {};
    },
    login: async (conn, { un, pw }) => {
        assertType(un, 'string', 'expected un to be a string');
        assertType(pw, 'string', 'expected pw to be a string');
        try {
            const sesx = await conn.client.logIn(un, pw);
            const needsTotp = sesx.isAdmin && !sesx.totpSetUp;
            return {
                s: true,
                uea: sesx.newCode,
                id: sesx.id,
                totp: sesx.totpSetUp && !sesx.totpUsed,
                needs_totp: needsTotp,
                member: sesx.isActiveMember,
            };
        } catch (err) {
            if (err.statusCode === 400 || err.statusCode === 401) {
                return { s: false, nopw: false };
            } else if (err.statusCode === 409) {
                return { s: false, nopw: true };
            } else {
                throw err;
            }
        }
    },
    logout: async (conn) => {
        try {
            await conn.client.logOut();
            return { s: true };
        } catch (err) {
            if (err.statusCode === 404) {
                return { s: false };
            } else {
                throw err;
            }
        }
    },
    totp: async (conn, { co, se, r }) => {
        assertType(co, 'string', 'expected co to be a string');
        assertType(r, 'boolean', 'expected r to be a bool');
        try {
            if (se) {
                await conn.client.totpSetup(se, co, r);
            } else {
                await conn.client.totpLogIn(co, r);
            }
            return { s: true };
        } catch (err) {
            if (err.statusCode === 400 || err.statusCode === 401) {
                return { s: false, bad: false, nosx: false };
            } else if (err.statusCode === 403) {
                return { s: false, bad: true, nosx: false };
            } else if (err.statusCode === 404) {
                return { s: false, bad: false, nosx: true };
            } else {
                throw err;
            }
        }
    },
    '-totp': async (conn) => {
        try {
            await conn.client.totpRemove();
            return { s: true };
        } catch (err) {
            if (err.statusCode === 401 || err.statusCode === 404) {
                return { s: false };
            } else {
                throw err;
            }
        }
    },
    forgot_pw: async (conn, { un }) => {
        assertType(un, 'string', 'expected un to be a string');
        try {
            const res = await conn.client.req({
                method: 'POST',
                path: `/codeholders/${un}/!forgot_password`,
                body: { org: 'uea' },
                _allowLoggedOut: true,
            });
            return { k: res.ok, sc: res.res.status, h: {}, b: res.body };
        } catch (err) {
            return { k: false, sc: err.statusCode, h: {}, b: err.toString() };
        }
    },
    create_pw: async (conn, { un, pw, tok }) => {
        assertType(un, 'string', 'expected un to be a string');
        assertType(pw, 'string', 'expected pw to be a string');
        assertType(tok, 'string', 'expected tok to be a string');
        try {
            const res = await conn.client.req({
                method: 'POST',
                path: `/codeholders/${un}/!create_password_use`,
                body: {
                    key: Buffer.from(tok, 'hex'),
                    password: pw,
                },
                _allowLoggedOut: true,
            });
            return { k: res.ok, sc: res.res.status, h: {}, b: res.body };
        } catch (err) {
            return { k: false, sc: err.statusCode, h: {}, b: err.toString() };
        }
    },
    get: async (conn, { p, q, c }) => {
        assertType(p, 'string', 'expected p to be a string');
        assertType(q, 'object', 'expected q to be an object');
        assertType(c, 'number', 'expected c to be a number');
        if (c < 0) throw new Error('negative cache time');

        if (c) {
            const cachedResponse = await cache.get(conn.apiHost, 'GET', p, q);
            if (cachedResponse !== null) return cachedResponse;
        }

        try {
            const res = await conn.client.get(p, q);
            const response = {
                k: res.ok,
                sc: res.res.status,
                h: collectHeaders(res.res.headers),
                b: res.body,
            };

            if (c) await cache.insert(conn.apiHost, 'GET', p, q, c, response);

            return response;
        } catch (err) {
            return {
                k: false,
                sc: err.statusCode,
                h: {},
                b: err.toString(),
            };
        }
    },
    delete: async (conn, { p, q }) => {
        assertType(p, 'string', 'expected p to be a string');
        assertType(q, 'object', 'expected q to be an object');

        try {
            const res = await conn.client.delete(p, q);
            return {
                k: res.ok,
                sc: res.res.status,
                h: collectHeaders(res.res.headers),
                b: res.body,
            };
        } catch (err) {
            return {
                k: false,
                sc: err.statusCode,
                h: {},
                b: err.toString(),
            };
        }
    },
    post: async (conn, { p, b, q, f }) => {
        assertType(p, 'string', 'expected p to be a string');
        if (b !== null) assertType(b, 'object', 'expected b to be an object or null');
        assertType(q, 'object', 'expected q to be an object');
        assertType(f, 'object', 'expected f to be an object');

        const files = [];
        for (const n in f) {
            const file = f[n];
            assertType(file, 'object', 'expected file to be an object');
            assertType(file.t, 'string', 'expected file.t to be a string');
            assertType(file.b, 'string', 'expected file.b to be a string');
            files.push({
                name: n,
                type: file.t,
                value: Buffer.from(file.b, 'base64'),
            });
        }

        try {
            const res = await conn.client.post(p, b, q, files);
            return {
                k: res.ok,
                sc: res.res.status,
                h: collectHeaders(res.res.headers),
                b: res.body,
            };
        } catch (err) {
            return {
                k: false,
                sc: err.statusCode,
                h: {},
                b: err.toString(),
            };
        }
    },
    put: async (conn, { p, b, q, f }) => {
        assertType(p, 'string', 'expected p to be a string');
        if (b !== null) assertType(b, 'object', 'expected b to be an object or null');
        assertType(q, 'object', 'expected q to be an object');
        assertType(f, 'object', 'expected f to be an object');

        const files = [];
        for (const n in f) {
            const file = f[n];
            assertType(file, 'object', 'expected file to be an object');
            assertType(file.t, 'string', 'expected file.t to be a string');
            assertType(file.b, 'string', 'expected file.b to be a string');
            files.push({
                name: n,
                type: file.t,
                value: Buffer.from(file.b, 'base64'),
            });
        }

        try {
            const res = await conn.client.put(p, b, q, files);
            return {
                k: res.ok,
                sc: res.res.status,
                h: collectHeaders(res.res.headers),
                b: res.body,
            };
        } catch (err) {
            return {
                k: false,
                sc: err.statusCode,
                h: {},
                b: err.toString(),
            };
        }
    },
    patch: async (conn, { p, b, q }) => {
        assertType(p, 'string', 'expected p to be a string');
        if (b !== null) assertType(b, 'object', 'expected b to be an object or null');
        assertType(q, 'object', 'expected q to be an object');

        try {
            const res = await conn.client.patch(p, b, q);
            return {
                k: res.ok,
                sc: res.res.status,
                h: collectHeaders(res.headers),
                b: res.body,
            };
        } catch (err) {
            return {
                k: false,
                sc: err.statusCode,
                h: {},
                b: err.toString(),
            };
        }
    },
    perms: async (conn, { p }) => {
        assertType(p, 'array', 'expected p to be an array');
        const res = [];
        for (const perm of p) {
            assertType(perm, 'string', 'expected permission to be a string');
            res.push(await conn.client.hasPerm(perm));
        }
        return { p: res };
    },
    permscf: async (conn, { f }) => {
        assertType(f, 'array', 'expected f to be an array');
        const res = [];
        for (const fieldFlags of f) {
            assertType(fieldFlags, 'string', 'expected field.flags to be a string');
            const parts = fieldFlags.split('.');
            const field = parts[0];
            const flags = parts[1];
            res.push(await conn.client.hasCodeholderField(field, flags));
        }
        return { f: res };
    },
    permsocf: async (conn, { f }) => {
        assertType(f, 'array', 'expected f to be an array');
        const res = [];
        for (const fieldFlags of f) {
            assertType(fieldFlags, 'string', 'expected field.flags to be a string');
            const parts = fieldFlags.split('.');
            const field = parts[0];
            const flags = parts[1];
            res.push(await conn.client.hasOwnCodeholderField(field, flags));
        }
        return { f: res };
    },
    regexp: async (conn, { r, s }) => {
        assertType(r, 'string', 'expected r to be a string');
        assertType(s, 'string', 'expected s to be a string');
        try {
            const re = new RegExp(r);
            return { m: !!re.test(s) };
        } catch (err) {
            return { m: false };
        }
    },
    asc: async (conn, { s, fv, e }) => {
        assertType(s, 'array', 'expected s to be an array');
        assertType(fv, 'object', 'expected fv to be an object');
        assertType(e, 'object', 'expected e to be an object');
        try {
            let iter = 0;
            const sym = Symbol('result');
            const res = evaluate(s.concat({
                [sym]: e,
            }), sym, id => {
                const value = (id in fv) ? fv[id] : null;
                if (value && value.type === 'date') return new Date(value.time * 1000);
                return value;
            }, {
                shouldHalt () {
                    iter++;
                    return iter > 4096;
                },
            });
            return { s: true, v: res, e: null };
        } catch (err) {
            return { s: false, v: null, e: err.toString() };
        }
    },
    currencies: async (conn) => {
        return { v: currencies };
    },
    convertCurrency: async (conn, { r, fc, tc, v }) => {
        assertType(r, 'object', 'expected r to be an object');
        assertType(fc, 'string', 'expected fc to be a string');
        assertType(tc, 'string', 'expected tc to be a string');
        assertType(v, 'number', 'expected f to be a number');

        const cashify = new Cashify({ base: fc, rates: r });
        return { v: cashify.convert(v, { from: fc, to: tc }) };
    },
    get_raw: async (conn, { p, o, c, sr }, onStreamChunk) => {
        assertType(p, 'string', 'expected p to be a string');
        assertType(c, 'number', 'expected c to be a number');
        if (c < 0) throw new Error('negative cache time');

        const cachedResponse = await cache.get(conn.apiHost, 'GET_RAW', p, o);
        debug(`Cache for raw resource ${p} (${c}s): ${!!cachedResponse}`);
        if (cachedResponse !== null) {
            await conn.acquire('GET_RAW', p, o);
            return {
                ...cachedResponse,
                cached: true,
                ref: cachedResponse.ref && path.resolve(path.join(rawCachePath, cachedResponse.ref)),
            };
        }

        const runCache = async (doAcquire) => {
            const res = await conn.client.get(p, o || {});
            let body = Buffer.from(res.body);

            let refName = null;
            if (res.ok) {
                const hash = crypto.createHash('sha256');
                hash.update(body);
                refName = hash.digest('hex');
            }

            const response = {
                k: res.ok,
                sc: res.status,
                h: collectHeaders(res.res.headers),
                ref: refName,
            };

            if (res.ok) {
                const filePath = path.join(rawCachePath, refName);
                await fsWriteFile(filePath, body);
                await cache.insert(conn.apiHost, 'GET_RAW', p, o, c, response, refName);
                if (doAcquire) {
                    await conn.acquire('GET_RAW', p, o);
                }
            }

            if (!doAcquire) return { ...response, ref: null };

            return {
                ...response,
                cache: true,
                ref: response.ref && path.resolve(path.join(rawCachePath, response.ref)),
            };
        };

        if (sr) {
            debug(`Start background cache of ${p}`);
            // FIXME: multiple parallel requests may trigger multiple parallel background caches
            // -> use a write lock
            runCache(false).then(() => {
                debug(`Background cache of ${p} complete`);
            }).catch(e => {
                warn(`Error during background cache of ${p}: ${e}`);
            });

            let streamStart = sr[0] || 0;
            let streamEnd = sr[1] || Infinity;
            assertType(streamStart, 'number', 'expected stream start offset to be a number');
            assertType(streamEnd, 'number', 'expected stream end offset to be a number');

            const fetchURL = conn.client.client.createURL(p);
            const cookie = await promisify(conn.cookies.getCookieString.bind(conn.cookies))(fetchURL);
            const res = await fetch(fetchURL, {
                headers: {
                    range: `bytes=${streamStart}-${Number.isFinite(streamEnd) ? streamEnd : ''}`,
                    cookie,
                },
            });
            if (!res.ok) {
                return {
                    k: false,
                    sc: res.status,
                    h: collectHeaders(res.headers),
                    ref: null,
                };
            }

            const h = collectHeaders(res.headers);

            let dataLen = 0;
            for await (const chunk of res.body) {
                if (res.size > 0 && dataLen + chunk.length > res.size) {
                    return { k: false, sc: 500, h: [], ref: null };
                }
                const isFirst = dataLen === 0;
                dataLen += chunk.length;
                if (isFirst) {
                    onStreamChunk({
                        k: true,
                        sc: res.status,
                        h,
                        chunk: Buffer.from(chunk).toString('base64'),
                    });
                } else {
                    onStreamChunk({ chunk: Buffer.from(chunk).toString('base64') });
                }
            }

            return {
                k: true,
                sc: res.status,
                h,
                ref: null,
            };
        } else {
            try {
                return await runCache(true);
            } catch (err) {
                if (err?.statusCode) {
                    return {
                        k: false,
                        sc: err.statusCode,
                        h: {},
                        ref: null,
                    };
                } else throw err;
            }
        }
    },
    release_raw: async (conn, { p }) => {
        // TODO: deprecate this
        return {};
    },
    render_md: async (conn, { c, r, i }) => {
        assertType(c, 'string', 'expected c to be a string');
        assertType(r, 'array', 'expected r to be an array');

        const md = new Markdown('zero').use(MarkdownMultiMdTable);
        md.enable('newline');
        md.enable(r);
        if (i) {
            return { c: md.renderInline(c) };
        } else {
            return { c: md.render(c) };
        }
    },
    absolute_md_urls: async (conn, { c, u }) => {
        assertType(c, 'string', 'expected v to be a string');
        assertType(u, 'string', 'expected u to be a string');

        return await remark()
            .use(remarkPluginAbsoluteUrls, { base: u })
            .process(c);
    },
    render_addr: async (conn, { f, c }) => {
        return { c: await formatAddress(f, false, undefined, c) };
    },
    validate_addr: async (conn, { f }) => {
        try {
            await normalizeAddress(f);
        } catch {
            return { c: false };
        }
        return { c: true };
    },
    parse_phone_local: async (conn, { n, c }) => {
        try {
            const phoneUtil = PhoneNumberUtil.getInstance();
            const number = phoneUtil.parse(n, c.toUpperCase());
            return { s: true, n: phoneUtil.format(number, PhoneNumberFormat.E164) };
        } catch (err) {
            return { s: false, e: err.toString() };
        }
    },
    generate_totp: async (conn, { u }) => {
        assertType(u, 'string', 'expected u to be a string');
        return await generateTotp(u);
    },
    valid_search: async (conn, { s }) => {
        assertType(s, 'string', 'expected s to be a string');
        try {
            return { v: util.isValidSearch(s) };
        } catch {
            return { v: false };
        }
    },
    trf_search: async (conn, { s }) => {
        assertType(s, 'string', 'expected s to be a string');
        try {
            return { s: util.transformSearch(s) };
        } catch {
            // fall back to identity
            return { s };
        }
    },
    flush_cookies: async (conn) => {
        conn.flushSendCookies();
        return {};
    },
    x: async (conn) => {
        conn.flushSendCookies();
        return {};
    },
};

function collectHeaders (headers) {
    const entries = {};
    if (!headers) return entries;
    for (const [k, v] of headers.entries()) {
        entries[k.toLowerCase()] = v;
    }
    return entries;
}

function remarkPluginAbsoluteUrls ({ base }) {
    return tree => visit(tree, node => {
        if (node.type === 'link' || node.type === 'image') {
            node.url = new URL(node.url, base).href;
        }
    });
}
