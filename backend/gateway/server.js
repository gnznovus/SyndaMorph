'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { createGateway, AccessError } = require('./core');
const { createFileRepository } = require('./store');

function makeServer({ gateway, adminToken, artifactDir }) {
    if (!gateway || typeof adminToken !== 'string' || Buffer.byteLength(adminToken) < 32) throw new Error('Admin token must contain at least 32 bytes');
    if (!path.isAbsolute(artifactDir)) throw new Error('Absolute artifact directory required');
    const json = (res, status, value) => {
        res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
        res.end(JSON.stringify(value));
    };
    const verifyAdmin = req => {
        const supplied = req.headers['x-syndamorph-admin-token'];
        const a = Buffer.from(adminToken), b = Buffer.from(typeof supplied === 'string' ? supplied : '');
        if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) throw new AccessError(401, 'unauthorized');
    };
    const artifact = storageKey => {
        if (!/^[a-zA-Z0-9_-]{1,128}\.zip$/.test(storageKey)) throw new AccessError(400, 'invalid_artifact');
        const location = path.join(artifactDir, storageKey);
        // Reject symlinks; file bytes are checked at publish AND download.
        const stat = fs.lstatSync(location);
        if (!stat.isFile() || stat.size > 25 * 1024 * 1024 || stat.size === 0) throw new AccessError(400, 'invalid_artifact');
        return fs.readFileSync(location);
    };
    return http.createServer(async (req, res) => {
        try {
            const url = new URL(req.url, 'http://localhost');
            const pathname = url.pathname;
            if (req.method === 'GET' && pathname === '/healthz') return json(res, 200, { status: 'ok' });
            const admin = pathname.startsWith('/admin/v1/');
            if (admin) verifyAdmin(req);
            if (req.method === 'GET' && pathname === '/admin/v1/healthz')
                return json(res, 200, { service: 'syndamorph-gateway', protocol: 1 });
            const client = !admin && pathname.startsWith('/v1/') && pathname !== '/v1/downloads';
            const owner = client && !pathname.startsWith('/v1/downloads/') ? gateway.authenticate(req.headers.authorization) : null;
            let body = {};
            if (['POST', 'PATCH'].includes(req.method)) {
                if (!String(req.headers['content-type'] || '').startsWith('application/json')) throw new AccessError(415, 'json_required');
                const chunks = [];
                let length = 0;
                for await (const chunk of req) {
                    length += chunk.length;
                    if (length > 16384) throw new AccessError(413, 'body_too_large');
                    chunks.push(chunk);
                }
                try { body = JSON.parse(Buffer.concat(chunks).toString('utf8')); }
                catch { throw new AccessError(400, 'invalid_json'); }
                if (!body || typeof body !== 'object' || Array.isArray(body)) throw new AccessError(400, 'invalid_json');
            }
            let match;
            if (req.method === 'GET' && pathname === '/admin/v1/overview') return json(res, 200, gateway.adminOverview());
            if (req.method === 'POST' && pathname === '/admin/v1/customers') return json(res, 201, gateway.customer(body));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/status$/)) && req.method === 'PATCH')
                return json(res, 200, gateway.setCustomerStatus(match[1], body.status));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/keys$/)) && req.method === 'POST')
                return json(res, 201, gateway.issueKey(match[1]));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/keys\/(key_[a-f0-9]{24})\/revoke$/)) && req.method === 'POST')
                return json(res, 200, gateway.revokeKey(match[1], match[2]));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/packages$/)) && req.method === 'POST')
                return json(res, 201, gateway.registerPackage(match[1], body));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/packages\/(pkg_[a-f0-9]{24})\/versions$/)) && req.method === 'POST') {
                const bytes = artifact(body.storageKey);
                if (crypto.createHash('sha256').update(bytes).digest('hex') !== body.sha256 || bytes.length !== body.size) throw new AccessError(400, 'artifact_integrity_mismatch');
                return json(res, 201, gateway.publishVersion(match[1], match[2], body));
            }
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/packages\/(pkg_[a-f0-9]{24})\/leases$/)) && req.method === 'POST')
                return json(res, 201, gateway.lease(match[1], match[2], body));
            if ((match = pathname.match(/^\/admin\/v1\/customers\/(cus_[a-f0-9]{24})\/leases\/(lease_[a-f0-9]{24})$/)) && req.method === 'PATCH')
                return json(res, 200, gateway.updateLease(match[1], match[2], body));
            if (req.method === 'GET' && pathname === '/v1/client/me') return json(res, 200, { id: owner.id, name: owner.name });
            if (req.method === 'GET' && pathname === '/v1/packages') return json(res, 200, { packages: gateway.packagesFor(owner) });
            if ((match = pathname.match(/^\/v1\/packages\/(pkg_[a-f0-9]{24})\/versions$/)) && req.method === 'GET')
                return json(res, 200, { versions: gateway.versionsFor(owner, match[1]) });
            if ((match = pathname.match(/^\/v1\/packages\/(pkg_[a-f0-9]{24})\/downloads$/)) && req.method === 'POST')
                return json(res, 200, gateway.authorizeDownload(owner, match[1], body.version));
            if ((match = pathname.match(/^\/v1\/downloads\/([a-zA-Z0-9_-]{43})$/)) && req.method === 'GET') {
                const grant = gateway.consumeDownload(match[1]);
                let bytes;
                try { bytes = artifact(grant.storageKey); }
                catch { throw new AccessError(503, 'artifact_unavailable'); }
                if (bytes.length !== grant.size || crypto.createHash('sha256').update(bytes).digest('hex') !== grant.sha256) throw new AccessError(503, 'artifact_integrity_mismatch');
                res.writeHead(200, { 'Content-Type': 'application/zip', 'Content-Length': bytes.length, 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff', 'X-Artifact-SHA256': grant.sha256 });
                return res.end(bytes);
            }
            return json(res, 404, { error: 'not_found' });
        } catch (error) {
            if (error instanceof AccessError) return json(res, error.status, { error: error.code });
            // No secrets, file paths, or stack traces in public responses.
            return json(res, 500, { error: 'internal_error' });
        }
    });
}
if (require.main === module) {
    const secret = process.env.SYNDAMORPH_GATEWAY_PEPPER;
    const adminToken = process.env.SYNDAMORPH_GATEWAY_ADMIN_TOKEN;
    const dataFile = process.env.SYNDAMORPH_GATEWAY_DATA;
    const artifactDir = process.env.SYNDAMORPH_GATEWAY_ARTIFACTS;
    if (!secret || !adminToken || !dataFile || !artifactDir) throw new Error('Gateway secrets and absolute data/artifact paths are required');
    const gateway = createGateway({ repository: createFileRepository(dataFile), pepper: secret });
    makeServer({ gateway, adminToken, artifactDir }).listen(Number(process.env.SYNDAMORPH_GATEWAY_PORT || 3200), process.env.SYNDAMORPH_GATEWAY_HOST || '127.0.0.1');
}
module.exports = { makeServer };
