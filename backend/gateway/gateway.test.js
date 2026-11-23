'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { createGateway } = require('./core');
const { createFileRepository } = require('./store');
const { makeServer } = require('./server');

function fixture() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sm-gateway-'));
    const repo = createFileRepository(path.join(dir, 'state.json'));
    const gateway = createGateway({ repository: repo, pepper: 'this-is-a-local-test-pepper-of-more-than-32-bytes' });
    const artifacts = path.join(dir, 'artifacts');
    fs.mkdirSync(artifacts);
    return { dir, repo, gateway, artifacts, cleanup: () => fs.rmSync(dir, { recursive: true, force: true }) };
}

test('customer keys are distinct, private packages require lease, revocation is isolated', () => {
    const f = fixture();
    try {
        const a = f.gateway.customer({ name: 'Client A' });
        const b = f.gateway.customer({ name: 'Client B' });
        const ka = f.gateway.issueKey(a.id), kb = f.gateway.issueKey(b.id);
        assert.notEqual(ka.token, kb.token);
        assert.ok(!JSON.stringify(f.repo.read()).includes(ka.token));
        assert.equal(f.gateway.authenticate('Bearer ' + ka.token).id, a.id);
        assert.equal(f.gateway.authenticate('Bearer ' + kb.token).id, b.id);
        assert.throws(() => f.gateway.authenticate('Bearer wrong'), { status: 401 });
        const pkg = f.gateway.registerPackage(a.id, { name: 'A private package' });
        assert.deepEqual(f.gateway.packagesFor(a), []);
        assert.throws(() => f.gateway.versionsFor(b, pkg.id), { status: 404 });
        const lease = f.gateway.lease(a.id, pkg.id, {});
        assert.equal(f.gateway.packagesFor(a).length, 1);
        assert.throws(() => f.gateway.lease(a.id, pkg.id, {}), { status: 409 });
        f.gateway.updateLease(a.id, lease.id, { status: 'suspended' });
        assert.deepEqual(f.gateway.packagesFor(a), []);
        f.gateway.updateLease(a.id, lease.id, { status: 'active' });
        assert.equal(f.gateway.packagesFor(a).length, 1);
        f.gateway.revokeKey(a.id, ka.id);
        assert.throws(() => f.gateway.authenticate('Bearer ' + ka.token), { status: 401 });
        assert.equal(f.gateway.authenticate('Bearer ' + kb.token).id, b.id);
    } finally { f.cleanup(); }
});

test('expired and revoked leases deny access and cannot be resumed', () => {
    const f = fixture();
    try {
        const a = f.gateway.customer({ name: 'Client A' });
        const pkg = f.gateway.registerPackage(a.id, { name: 'Project' });
        const lease = f.gateway.lease(a.id, pkg.id, { startsAt: '2020-01-01T00:00:00.000Z', expiresAt: '2020-02-01T00:00:00.000Z' });
        assert.deepEqual(f.gateway.packagesFor(a), []);
        f.gateway.updateLease(a.id, lease.id, { status: 'active', expiresAt: '2099-01-01T00:00:00.000Z' });
        assert.equal(f.gateway.packagesFor(a).length, 1);
        f.gateway.updateLease(a.id, lease.id, { status: 'revoked' });
        assert.deepEqual(f.gateway.packagesFor(a), []);
        assert.throws(() => f.gateway.updateLease(a.id, lease.id, { status: 'active' }), { status: 409 });
    } finally { f.cleanup(); }
});

test('download is one-time, revocation checked again on use, metadata has no storage path', () => {
    const f = fixture();
    try {
        const a = f.gateway.customer({ name: 'Client A' });
        const key = f.gateway.issueKey(a.id);
        const principal = f.gateway.authenticate('Bearer ' + key.token);
        const pkg = f.gateway.registerPackage(a.id, { name: 'Project' });
        const bytes = Buffer.from('PK\x03\x04test-archive');
        const checksum = crypto.createHash('sha256').update(bytes).digest('hex');
        f.gateway.publishVersion(a.id, pkg.id, { version: '1.0.0', sha256: checksum, size: bytes.length, storageKey: 'artifact.zip' });
        assert.throws(() => f.gateway.authorizeDownload(principal, pkg.id, '1.0.0'), { status: 403 });
        const lease = f.gateway.lease(a.id, pkg.id, {});
        const version = f.gateway.versionsFor(a, pkg.id)[0];
        assert.equal(version.sha256, checksum);
        assert.equal(Object.hasOwn(version, 'storageKey'), false);
        const download = f.gateway.authorizeDownload(principal, pkg.id, '1.0.0');
        assert.equal(f.gateway.consumeDownload(download.grant).storageKey, 'artifact.zip');
        assert.throws(() => f.gateway.consumeDownload(download.grant), { status: 404 });
        const newer = f.gateway.authorizeDownload(principal, pkg.id, '1.0.0');
        f.gateway.updateLease(a.id, lease.id, { status: 'revoked' });
        assert.throws(() => f.gateway.consumeDownload(newer.grant), { status: 403 });
        const nextLease = f.gateway.lease(a.id, pkg.id, {});
        const grantAfterLease = f.gateway.authorizeDownload(principal, pkg.id, '1.0.0');
        f.gateway.revokeKey(a.id, key.id);
        assert.throws(() => f.gateway.consumeDownload(grantAfterLease.grant), { status: 403 });
    } finally { f.cleanup(); }
});

test('HTTP admin separation, bearer auth, version publication, and authorized ZIP download', async () => {
    const f = fixture();
    const server = makeServer({ gateway: f.gateway, adminToken: 'test-admin-token-32-bytes-long-for-tests', artifactDir: f.artifacts });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const root = 'http://127.0.0.1:' + server.address().port;
    const admin = { 'x-syndamorph-admin-token': 'test-admin-token-32-bytes-long-for-tests', 'content-type': 'application/json' };
    const request = (url, method = 'GET', headers = {}, body) => fetch(root + url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    try {
        let res = await request('/admin/v1/customers', 'POST', { 'content-type': 'application/json' }, { name: 'A' });
        assert.equal(res.status, 401);
        res = await request('/admin/v1/customers', 'POST', admin, { name: 'A' });
        assert.equal(res.status, 201);
        const a = await res.json();
        res = await request('/admin/v1/customers/' + a.id + '/keys', 'POST', admin, {});
        const key = (await res.json()).token;
        res = await request('/v1/client/me', 'GET', { authorization: 'Bearer ' + key });
        assert.equal((await res.json()).id, a.id);
        res = await request('/admin/v1/customers/' + a.id + '/packages', 'POST', admin, { name: 'A only' });
        const pkg = await res.json();
        const bytes = Buffer.from('PK\x03\x04test-package');
        fs.writeFileSync(path.join(f.artifacts, 'package.zip'), bytes);
        const sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
        res = await request('/admin/v1/customers/' + a.id + '/packages/' + pkg.id + '/versions', 'POST', admin, { version: '1.0.0', size: bytes.length, sha256, storageKey: 'package.zip' });
        assert.equal(res.status, 201);
        res = await request('/v1/packages/' + pkg.id + '/downloads', 'POST', { authorization: 'Bearer ' + key, 'content-type': 'application/json' }, { version: '1.0.0' });
        assert.equal(res.status, 403);
        res = await request('/admin/v1/customers/' + a.id + '/packages/' + pkg.id + '/leases', 'POST', admin, {});
        assert.equal(res.status, 201);
        res = await request('/v1/packages/' + pkg.id + '/downloads', 'POST', { authorization: 'Bearer ' + key, 'content-type': 'application/json' }, { version: '1.0.0' });
        assert.equal(res.status, 200);
        const grant = (await res.json()).grant;
        res = await request('/v1/downloads/' + grant);
        assert.equal(res.status, 200);
        assert.equal(Buffer.compare(Buffer.from(await res.arrayBuffer()), bytes), 0);
        assert.equal((await request('/v1/downloads/' + grant)).status, 404);
    } finally {
        await new Promise(resolve => server.close(resolve));
        f.cleanup();
    }
});
