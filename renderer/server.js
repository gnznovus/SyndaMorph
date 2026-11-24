'use strict';

const http = require('node:http');
const { spawn } = require('node:child_process');
const { compareScreenshots, cropScreenshots, validViewports } = require('./compare');

const MAX_BYTES = 2 * 1024 * 1024;
let busy = false;

const reply = (res, status, payload) => {
    if (res.writableEnded || res.destroyed) { return; }
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(payload));
};

const render = (html, viewports = null) => new Promise((resolve, reject) => {
    const inspect = viewports !== null;
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
    const timeout = setTimeout(() => finish(new Error('Isolated browser worker exceeded its execution limit.')), inspect ? 60000 : 8000);
    child.on('error', (err) => finish(err));
    child.stdout.on('data', (buf) => {
        size += buf.length;
        if (size > (inspect ? 8 * MAX_BYTES : MAX_BYTES + 4096)) { finish(new Error('Rendered HTML exceeds 2 MB.')); return; }
        chunks.push(buf);
    });
    child.on('close', (code) => {
        if (settled) { return; }
        try {
            const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            if (result.error) { finish(new Error(result.error)); return; }
            if (inspect) {
                if (!Array.isArray(result.evidence) || result.evidence.length !== viewports.length ||
                    result.evidence.some((sample, index) => sample.width !== viewports[index])) {
                    throw new Error('Incomplete inspection evidence.');
                }
                finish(null, result.evidence);
                return;
            }
            if (typeof result.html !== 'string') { throw new Error('Renderer produced no HTML.'); }
            finish(null, result.html);
        } catch (err) { finish(err); }
    });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify({ html, inspect, viewports }));
});

http.createServer(async (req, res) => {
    if (req.method !== 'POST' || !['/render','/inspect','/compare','/crop'].includes(req.url)) { reply(res, 404, { error: 'Not found.' }); return; }
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
        if (req.url === '/crop') { reply(res,200,{images:cropScreenshots(body.items)}); return; }
        if (req.url === '/compare') {
            reply(res, 200, {comparison:compareScreenshots(body.reference,body.native)});
            return;
        }
        if (typeof body.html !== 'string' || !body.html.trim() || Buffer.byteLength(body.html) > MAX_BYTES) {
            reply(res, 400, { error: 'Provide an HTML string smaller than 2 MB.' }); return;
        }
        if (req.url === '/inspect') {
            const viewports = body.viewports ?? [1440, 768, 390];
            if (!validViewports(viewports)) {
                reply(res, 400, { error: 'Provide one to five distinct viewport widths from 320 to 1920 CSS px.' });
                return;
            }
            reply(res, 200, { evidence: await render(body.html, viewports) });
        } else reply(res, 200, { html: await render(body.html) });
    } catch (error) {
        reply(res, 422, { error: 'JavaScript render failed: ' + error.message });
    } finally {
        busy = false;
    }
}).listen(3000, '0.0.0.0');
