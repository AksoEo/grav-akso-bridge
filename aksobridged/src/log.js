function timestamp() {
    return new Date().toISOString();
}

let name = '';
export function setThreadName (newName) {
    name = newName;
}

function mkPrefix (type) {
    if (name) return `[${name}:${type}]`;
    return `[${type}]`;
}

export function debug (msg) {
    process.stderr.write(`\x1b[90m${mkPrefix('DBG')} ${msg}\x1b[m\n`);
}

export function info (msg) {
    const time = timestamp();
    process.stderr.write(`\x1b[34m${mkPrefix('INFO')} [${time}] ${msg}\x1b[m\n`);
}

export function warn (msg) {
    const time = timestamp();
    process.stderr.write(`\x1b[33m${mkPrefix('WARN')} [${time}] ${msg}\x1b[m\n`);
}

export function error (msg) {
    const time = timestamp();
    process.stderr.write(`\x1b[31m${mkPrefix('ERR')} [${time}] ${msg}\x1b[m\n`);
}
