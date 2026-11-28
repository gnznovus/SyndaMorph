'use strict';
const crypto = require('node:crypto');

class AccessError extends Error {
    constructor(status, code) {
        super(code);
        this.status = status;
        this.code = code;
    }
}
const fail = (status, code) => { throw new AccessError(status, code); };
const id = prefix => prefix + '_' + crypto.randomBytes(12).toString('hex');
const validName = value => typeof value === 'string' && value.trim().length > 0 && value.trim().length <= 120;
const validId = value => typeof value === 'string' && /^[a-z]+_[0-9a-f]{24}$/.test(value);
const validVersion = value => typeof value === 'string' && /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(value);
const validDate = value => value === null || (typeof value === 'string' && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString() === value);
const digest = (pepper, value) => crypto.createHmac('sha256', pepper).update(value).digest('hex');
const eq = (a, b) => {
    const left = Buffer.from(a, 'hex'), right = Buffer.from(b, 'hex');
    return left.length === right.length && crypto.timingSafeEqual(left, right);
};

function createGateway({ repository, pepper, now = () => new Date(), grants = new Map() }) {
    if (!repository || typeof repository.read !== 'function' || typeof repository.write !== 'function') throw new Error('Repository required');
    if (typeof pepper !== 'string' || Buffer.byteLength(pepper) < 32) throw new Error('Secret pepper must contain at least 32 bytes');
    const load = () => repository.read();
    const change = action => repository.write(action);
    const current = () => now().toISOString();
    const active = value => value.status === 'active';
    const validLease = lease => active(lease) && lease.startsAt <= current() && (lease.expiresAt === null || lease.expiresAt > current());

    function adminOverview() {
        const db = load();
        return {
            customers: db.customers.map(({ id, name, status, createdAt }) => ({ id, name, status, createdAt })),
            credentials: db.credentials.map(({ id, customerId, prefix, status, createdAt }) => ({ id, customerId, prefix, status, createdAt })),
            packages: db.packages.map(({ id, customerId, name, versions, createdAt }) => ({
                id, customerId, name, createdAt,
                versions: versions.map(({ version, sha256, size, status, publishedAt }) => ({ version, sha256, size, status, publishedAt })),
            })),
            leases: db.leases.map(row => ({
                ...row,
                effectiveStatus: row.status === 'active'
                    ? (row.startsAt > current() ? 'scheduled' : row.expiresAt !== null && row.expiresAt <= current() ? 'expired' : 'active')
                    : row.status,
            })),
        };
    }
    function customer(input) {
        if (!validName(input?.name)) fail(400, 'invalid_customer');
        return change(db => {
            const row = { id: id('cus'), name: input.name.trim(), status: 'active', createdAt: current() };
            db.customers.push(row);
            return row;
        });
    }
    function setCustomerStatus(customerId, status) {
        if (!['active', 'suspended'].includes(status)) fail(400, 'invalid_status');
        return change(db => {
            const row = db.customers.find(c => c.id === customerId);
            if (!row) fail(404, 'customer_not_found');
            row.status = status;
            return row;
        });
    }
    function issueKey(customerId) {
        return change(db => {
            const owner = db.customers.find(c => c.id === customerId && active(c));
            if (!owner) fail(404, 'customer_not_found');
            const keyId = id('key');
            const token = 'smup_' + crypto.randomBytes(32).toString('base64url');
            const row = { id: keyId, customerId, hash: digest(pepper, token), prefix: token.slice(0, 12), status: 'active', createdAt: current() };
            db.credentials.push(row);
            // The raw token is returned once, and is never persisted.
            return { id: row.id, customerId, token, createdAt: row.createdAt };
        });
    }
    function revokeKey(customerId, keyId) {
        return change(db => {
            const row = db.credentials.find(k => k.id === keyId && k.customerId === customerId);
            if (!row) fail(404, 'key_not_found');
            row.status = 'revoked';
            return { id: row.id, status: row.status };
        });
    }
    function authenticate(header) {
        if (typeof header !== 'string' || !/^Bearer smup_[a-zA-Z0-9_-]{30,100}$/.test(header)) fail(401, 'unauthorized');
        const token = header.slice(7);
        const hash = digest(pepper, token);
        const db = load();
        const credential = db.credentials.find(k => active(k) && eq(k.hash, hash));
        if (!credential) fail(401, 'unauthorized');
        const owner = db.customers.find(c => c.id === credential.customerId && active(c));
        if (!owner) fail(401, 'unauthorized');
        return { ...owner, credentialId: credential.id };
    }
    function registerPackage(customerId, input) {
        if (!validName(input?.name)) fail(400, 'invalid_package');
        return change(db => {
            if (!db.customers.some(c => c.id === customerId)) fail(404, 'customer_not_found');
            const row = { id: id('pkg'), customerId, name: input.name.trim(), versions: [], createdAt: current() };
            db.packages.push(row);
            return row;
        });
    }
    function publishVersion(customerId, packageId, input) {
        if (!validVersion(input?.version) || typeof input?.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(input.sha256) ||
            !Number.isSafeInteger(input.size) || input.size <= 0 || typeof input.storageKey !== 'string' ||
            !/^[a-zA-Z0-9_-]{1,128}\.zip$/.test(input.storageKey)) fail(400, 'invalid_artifact');
        return change(db => {
            const pkg = db.packages.find(p => p.id === packageId && p.customerId === customerId);
            if (!pkg) fail(404, 'package_not_found');
            if (pkg.versions.some(v => v.version === input.version)) fail(409, 'version_exists');
            const row = { version: input.version, sha256: input.sha256, size: input.size, storageKey: input.storageKey, status: 'published', publishedAt: current() };
            pkg.versions.push(row);
            return row;
        });
    }
    function lease(customerId, packageId, input) {
        if (!validDate(input?.expiresAt ?? null) || !validDate(input?.startsAt ?? current())) fail(400, 'invalid_dates');
        return change(db => {
            const pkg = db.packages.find(p => p.id === packageId && p.customerId === customerId);
            if (!pkg) fail(404, 'package_not_found');
            const exists = db.leases.some(l => l.packageId === packageId && l.status !== 'revoked');
            if (exists) fail(409, 'lease_exists');
            const startsAt = input.startsAt ?? current(), expiresAt = input.expiresAt ?? null;
            if (expiresAt !== null && expiresAt <= startsAt) fail(400, 'invalid_dates');
            const row = { id: id('lease'), customerId, packageId, status: 'active', startsAt, expiresAt, createdAt: current() };
            db.leases.push(row);
            return row;
        });
    }
    function updateLease(customerId, leaseId, input) {
        if (!['active', 'suspended', 'revoked'].includes(input?.status) || (input.expiresAt !== undefined && !validDate(input.expiresAt))) fail(400, 'invalid_lease');
        return change(db => {
            const row = db.leases.find(l => l.id === leaseId && l.customerId === customerId);
            if (!row) fail(404, 'lease_not_found');
            if (row.status === 'revoked') fail(409, 'revoked_lease');
            if (input.expiresAt !== undefined) {
                if (input.expiresAt !== null && input.expiresAt <= row.startsAt) fail(400, 'invalid_dates');
                row.expiresAt = input.expiresAt;
            }
            row.status = input.status;
            return row;
        });
    }
    const permitted = (db, owner, packageId) => {
        const pkg = db.packages.find(p => p.id === packageId && p.customerId === owner.id);
        if (!pkg) fail(404, 'package_not_found');
        if (!db.leases.some(l => l.customerId === owner.id && l.packageId === packageId && validLease(l))) fail(403, 'lease_required');
        return pkg;
    };
    function packagesFor(owner) {
        const db = load();
        return db.packages.filter(p => p.customerId === owner.id && db.leases.some(l => l.packageId === p.id && l.customerId === owner.id && validLease(l)))
            .map(p => ({ id: p.id, name: p.name, versions: p.versions.filter(v => v.status === 'published').map(v => ({ version: v.version, sha256: v.sha256, size: v.size })) }));
    }
    function versionsFor(owner, packageId) {
        return permitted(load(), owner, packageId).versions.filter(v => v.status === 'published').map(v => ({ version: v.version, sha256: v.sha256, size: v.size }));
    }
    function authorizeDownload(owner, packageId, version) {
        const pkg = permitted(load(), owner, packageId);
        const item = pkg.versions.find(v => v.version === version && v.status === 'published');
        if (!item) fail(404, 'version_not_found');
        const token = crypto.randomBytes(32).toString('base64url');
        const grant = { customerId: owner.id, credentialId: owner.credentialId, packageId, version, storageKey: item.storageKey, sha256: item.sha256, size: item.size, expiresAt: now().getTime() + 60000 };
        grants.set(digest(pepper, token), grant);
        return { grant: token, expiresIn: 60, sha256: item.sha256, size: item.size };
    }
    function consumeDownload(token) {
        if (typeof token !== 'string' || !/^[a-zA-Z0-9_-]{43}$/.test(token)) fail(404, 'download_not_found');
        const hash = digest(pepper, token);
        const grant = grants.get(hash);
        grants.delete(hash);
        if (!grant || grant.expiresAt <= now().getTime()) fail(404, 'download_not_found');
        const db = load();
        const owner = db.customers.find(c => c.id === grant.customerId && active(c));
        if (!owner || !db.credentials.some(k => k.id === grant.credentialId && k.customerId === owner.id && active(k))) fail(403, 'access_revoked');
        const pkg = permitted(db, owner, grant.packageId);
        if (!pkg.versions.some(v => v.version === grant.version && v.status === 'published' && v.sha256 === grant.sha256)) fail(403, 'access_revoked');
        return grant;
    }
    return { adminOverview, customer, setCustomerStatus, issueKey, revokeKey, authenticate, registerPackage, publishVersion, lease, updateLease, packagesFor, versionsFor, authorizeDownload, consumeDownload };
}
module.exports = { createGateway, AccessError };
