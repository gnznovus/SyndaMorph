'use strict';
const fs = require('node:fs');
const path = require('node:path');

/** Single-process, local-disk MVP store. Do not run multiple gateway replicas on one file. */
function createFileRepository(filename) {
    if (!path.isAbsolute(filename)) throw new Error('Absolute datastore path required');
    fs.mkdirSync(path.dirname(filename), { recursive: true, mode: 0o700 });
    if (!fs.existsSync(filename)) {
        const fd = fs.openSync(filename, 'wx', 0o600);
        try { fs.writeFileSync(fd, JSON.stringify({ schema: 1, customers: [], credentials: [], packages: [], leases: [] })); }
        finally { fs.closeSync(fd); }
    }
    const read = () => {
        const db = JSON.parse(fs.readFileSync(filename, 'utf8'));
        if (db.schema !== 1 || !['customers', 'credentials', 'packages', 'leases'].every(k => Array.isArray(db[k]))) throw new Error('Invalid Gateway datastore schema');
        return db;
    };
    // The Gateway server serializes synchronous mutations in one Node process.
    const write = action => {
        const db = read();
        const result = action(db);
        const temp = filename + '.' + process.pid + '.tmp';
        try {
            fs.writeFileSync(temp, JSON.stringify(db), { mode: 0o600, flag: 'wx' });
            fs.renameSync(temp, filename);
        } finally {
            if (fs.existsSync(temp)) fs.unlinkSync(temp);
        }
        return result;
    };
    return { read, write };
}
module.exports = { createFileRepository };
