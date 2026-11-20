'use strict';
const http = require('node:http');
const { capture } = require('./capture');
const TOKEN = process.env.SYNDAMORPH_BACKEND_TOKEN;
if (!TOKEN || !process.env.SYNDAMORPH_CAPTURE_HOSTS) throw new Error('Backend token and approved host list are required.');

const respond = (res, status, value) => {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(value));
};
http.createServer(async (req, res) => {
    if (req.url !== '/capture' || req.method !== 'POST') return respond(res, 404, { error: 'Not found.' });
    if (req.headers['x-syndamorph-token'] !== TOKEN) return respond(res, 403, { error: 'Forbidden.' });
    let total = 0;
    const parts = [];
    try {
        for await (const part of req) {
            total += part.length;
            if (total > 8192) return respond(res, 413, { error: 'Request exceeds limit.' });
            parts.push(part);
        }
        const input = JSON.parse(Buffer.concat(parts).toString('utf8'));
        if (typeof input.url !== 'string' || input.url.length > 2048) return respond(res, 400, { error: 'Invalid URL.' });
        const result = await capture(input.url, input.mode || 'auto');
        return respond(res, 200, result);
    } catch (error) { return respond(res, 422, { error: error.message }); }
}).listen(3100, '0.0.0.0');
