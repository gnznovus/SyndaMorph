'use strict';

const http = require('node:http');
const { spawn } = require('node:child_process');

const MAX_BYTES = 2 * 1024 * 1024;
let busy = false;

const reply = (res, status, payload) => {
    if (res.writableEnded || res.destroyed) { return; }
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(payload));
};

const render = (html) => new Promise((resolve, reject) => {
    // One fresh process per conversion means a runaway synchronous JS loop can
    // be forcibly terminated, rather than wedging the shared service.
    const child = spawn(process.execPath, ['/app/render-worker.js'], {
        detached: true,
        stdio: ['pipe', 'pipe', 'pipe'],
    });
    const chunks = [];
    let size = 0;
    let settled = false;
    const stop = () => {
        try { process.kill(-child.pid, 'SIGKILL'); } catch (_) { /* already exited */ }
    };
    const finish = (error, htmlResult) => {
        if (settled) { return; }
        settled = true;
        clearTimeout(timeout);
        if (error) { stop(); reject(error); }
        else { resolve(htmlResult); }
    };
    const timeout = setTimeout(() => finish(new Error('DOM renderer timed out after 8 seconds.')), 8000);
    child.on('error', (err) => finish(err));
    child.stdout.on('data', (buf) => {
        size += buf.length;
        if (size > MAX_BYTES + 4096) { finish(new Error('Rendered HTML exceeds 2 MB.')); return; }
        chunks.push(buf);
    });
    child.on('exit', (code) => {
        if (settled) { return; }
        try {
            const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            if (result.error) { finish(new Error(result.error)); return; }
            if (typeof result.html !== 'string') { throw new Error('Renderer produced no HTML.'); }
            finish(null, result.html);
        } catch (err) { finish(err); }
    });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify({ html }));
});

http.createServer(async (req, res) => {
    if (req.method !== 'POST' || req.url !== '/render') { reply(res, 404, { error: 'Not found.' }); return; }
    if (busy) { reply(res, 429, { error: 'Renderer busy. Retry preview.' }); return; }
    busy = true;
    try {
        const chunks = [];
        let size = 0;
        for await (const part of req) {
            size += part.length;
            if (size > MAX_BYTES + 1024) { reply(res, 413, { error: 'Input exceeds 2 MB.' }); return; }
            chunks.push(part);
        }
        let body;
        try { body = JSON.parse(Buffer.concat(chunks).toString('utf8')); }
        catch (_) { reply(res, 400, { error: 'Invalid JSON.' }); return; }
        if (typeof body.html !== 'string' || !body.html.trim() || Buffer.byteLength(body.html) > MAX_BYTES) {
            reply(res, 400, { error: 'Provide an HTML string smaller than 2 MB.' }); return;
        }
        reply(res, 200, { html: await render(body.html) });
    } catch (error) {
        reply(res, 422, { error: 'JavaScript render failed: ' + error.message });
    } finally {
        busy = false;
    }
}).listen(3000, '0.0.0.0');
