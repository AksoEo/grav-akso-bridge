import { Worker, isMainThread, MessageChannel } from 'worker_threads';
import { cpus as getCPUs } from 'os';
import { promisify } from 'util';
import { URL } from 'url';
import path from 'path';
import fs from 'fs';
import { decode } from '@msgpack/msgpack';
import { setThreadName, debug, info, error } from './log.js';
import packageJson from '../package.json' assert { type: 'json' };

const { version } = packageJson;

if (!isMainThread) {
    error('main.js running off-thread');
    process.exit(1);
}

setThreadName('MAIN');

// set up disk cache directory
const cachePath = 'run/cache';
const rawCachePath = 'run/cache_raw';
fs.mkdirSync(cachePath, { recursive: true, mode: 0o755 });
fs.mkdirSync(rawCachePath, { recursive: true, mode: 0o755 });

// set up socket directory
const bridgePath = 'run/aksobridge';
fs.mkdirSync(bridgePath, { recursive: true, mode: 0o755 });
const userAgent = `AKSOBridge/${version} (+https://akso.org)`;

try {
    // if aksobridged crashed or was sigkilled, ipc pipes will still exist in the bridge path
    // so first delete them here
    const items = fs.readdirSync(bridgePath);
    for (const item of items) {
        if (item.startsWith('ipc')) fs.unlinkSync(path.join(bridgePath, item));
    }
} catch {}

let isClosing = false;
const writeLocks = new Map();

const __dirname = new URL('.', import.meta.url).pathname;

function createWorkerInSlot (id) {
    const worker = new Worker(path.join(__dirname, 'worker.js'), {
        workerData: { path: bridgePath, id, userAgent, cachePath, rawCachePath },
    });
    const { port1: mainPort, port2: workerPort } = new MessageChannel();
    worker.postMessage({ type: 'init', channel: workerPort }, [workerPort]);
    worker.on('message', message => {
        if (!message) return;
        if (message.type === 'acquire') {
            if (!writeLocks.has(message.path)) writeLocks.set(message.path, 0);
            writeLocks.set(message.path, writeLocks.get(message.path) + 1);
            worker.postMessage({ type: 'acquire-ack', id: message.id });
            debug('Acquire lock on ' + message.path);
        } else if (message.type === 'release') {
            if (writeLocks.has(message.path)) {
                writeLocks.set(message.path, writeLocks.get(message.path) - 1);
                if (!writeLocks.get(message.path)) writeLocks.delete(message.path);
            }
            worker.postMessage({ type: 'release-ack', id: message.id });
            debug('Release lock on ' + message.path + ' (remaining: ' + (writeLocks.get(message.path) || 0) + ')');
        }
    });
    worker.on('error', err => {
        mainPort.close();
        error(`worker ${id} terminated: ${err.stack}`);
    });
    worker.on('exit', code => {
        if (!isClosing) {
            info(`worker exited with code ${code}; creating new worker in slot ${id}`);
            createWorkerInSlot(id);
        }
    });
    pool[id] = { worker, channel: mainPort };
}

const cpus = getCPUs().length;
debug(`found ${cpus} cpu threads`);
const pool = [];
for (let i = 0; i < cpus; i++) {
    createWorkerInSlot(i);
}

let gcInterval;

function close () {
    if (isClosing) return;
    clearInterval(gcInterval);
    isClosing = true;
    info('closing all workers');
    const terminatePromises = [];
    for (const item of pool) {
        item.channel.postMessage({ type: 'close' });
        terminatePromises.push(new Promise(resolve => {
            item.channel.on('close', () => {
                item.worker.terminate();
                resolve();
            });
        }));
    }
    Promise.all(terminatePromises).then(() => {
        fs.rmdirSync(bridgePath);
    }).catch(error);
}

process.on('SIGINT', close);

// cache garbage collector
// see worker.js for details on file structure
const fsReaddir = promisify(fs.readdir);
const fsStat = promisify(fs.stat);
const fsReadFile = promisify(fs.readFile);
const fsUnlink = promisify(fs.unlink);

async function cacheGC () {
    const files = await fsReaddir(cachePath);

    for (const file of files) {
        if (file.startsWith('$')) continue; // tmp file
        const filePath = path.join(cachePath, file);
        if (writeLocks.has(filePath) && writeLocks.get(filePath) > 0) {
            debug(`skipping gc for ${filePath} because it’s locked (${writeLocks.get(filePath)})`);
            continue;
        }

        try {
            const stats = await fsStat(filePath);
            const contents = await fsReadFile(filePath);

            let shouldDelete = false;
            let rawDataFile = null;
            try {
                const rootNode = decode(contents);
                const age = (Date.now() - stats.mtimeMs) / 1000;
                shouldDelete = age > rootNode.maxAge;
                rawDataFile = rootNode.raw;
            } catch {
                // decode error; file can’t be read anyway
                shouldDelete = true;
            }

            if (shouldDelete) {
                await fsUnlink(filePath);
                if (rawDataFile) {
                    await fsUnlink(path.join(rawCachePath, rawDataFile));
                }
            }
        } catch (err) {
            error(`GC error for file ${file}: ${err}`);
        }
    }

    debug('completed GC sweep');
}
gcInterval = setInterval(() => {
    cacheGC().catch(err => error(`GC error: ${err}`));
}, 120000);
