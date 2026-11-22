'use strict';
const http = require('node:http');
const { capture } = require('./capture');
const { createAnalyzeHandler } = require('./mvp/endpoint');

const TOKEN = process.env.SYNDAMORPH_BACKEND_TOKEN;
if (!TOKEN || !process.env.SYNDAMORPH_CAPTURE_HOSTS) throw new Error('Backend token and approved host list are required.');
const analyze = createAnalyzeHandler({ capture });

const respond = (res, status, value) => {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(value));
};
http.createServer(async (req, res) => {
    if (!['/capture', '/mvp/analyze'].includes(req.url) || req.method !== 'POST') {
        return respond(res, 404, { error: 'Not found.' });
    }
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
        if (req.url === '/mvp/analyze') {
            const result = await analyze(input);
            return respond(res, 200, result);
        }
        const result = await capture(input.url, input.mode || 'auto');
        return respond(res, 200, result);
    } catch (error) {
        // Never include API credentials, upstream response body, or source HTML in errors.
        const message = String(error.message || '');
        const allowed = /^(MVP AI analysis is disabled\.|ANTHROPIC_API_KEY is not configured\.|Invalid MVP analysis request\.|Requested section is not available in the capture\.|Claude API request failed \(HTTP \d+\)\.|Claude response incomplete or interrupted\.|Claude returned invalid JSON\.|Claude returned no bounded JSON output\.|Claude changed section identity\.)$/;
        if (req.url === '/mvp/analyze' && !allowed.test(message)) {
            return respond(res, 422, { error: 'MVP analysis failed; inspect server diagnostics.' });
        }
        return respond(res, 422, { error: message });
    }
}).listen(3100, '0.0.0.0');
