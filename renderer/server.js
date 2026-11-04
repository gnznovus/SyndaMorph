'use strict';

const http = require('node:http');
const { chromium } = require('playwright');

const MAX_REQUEST = 2 * 1024 * 1024;
const MAX_RESULT = 2 * 1024 * 1024;
const MAX_RUNTIME = 8500;
let busy = false;

const send = (res, code, result) => {
    res.writeHead(code, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(result));
};

// Development POC only. This worker has no published port and lives on an
// internal Docker network. External requests are blocked at browser routing.
async function render(html) {
    let browser;
    let watchdog;
    try {
        browser = await chromium.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });
        watchdog = setTimeout(() => {
            // Kill a renderer executing a blocking/infinite synchronous JS loop.
            try { browser.process()?.kill('SIGKILL'); } catch (_) { /* closed */ }
        }, MAX_RUNTIME);
        const context = await browser.newContext({
            javaScriptEnabled: true,
            serviceWorkers: 'block',
            acceptDownloads: false,
        });
        // Abort all HTTP(S) traffic, including external scripts, images,
        // API calls and popups. Only embedded inline JS/CSS is in scope.
        await context.route('**/*', (route) => route.abort('blockedbyclient'));
        const page = await context.newPage();
        page.on('popup', (popup) => popup.close().catch(() => {}));
        await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 5500 });
        // Allow DOMContentLoaded handlers and short asynchronous DOM updates.
        await page.waitForTimeout(600);
        const snapshot = await page.evaluate(() => {
            document.querySelectorAll('script, iframe, object, embed').forEach((el) => el.remove());
            return '<!doctype html>\n' + document.documentElement.outerHTML;
        });
        if (Buffer.byteLength(snapshot) > MAX_RESULT) {
            throw new Error('Rendered DOM exceeded the 2 MB limit.');
        }
        return snapshot;
    } finally {
        if (watchdog) { clearTimeout(watchdog); }
        if (browser) {
            // Do not stall forever during a broken browser shutdown.
            await Promise.race([browser.close().catch(() => {}), new Promise((resolve) => setTimeout(resolve, 900))]);
        }
    }
}

http.createServer(async (req, res) => {
    if (req.method !== 'POST' || req.url !== '/render') {
        send(res, 404, { error: 'Not found.' });
        return;
    }
    if (busy) { send(res, 429, { error: 'Renderer busy. Retry preview.' }); return; }

    busy = true;
    try {
        const chunks = [];
        let bytes = 0;
        for await (const chunk of req) {
            bytes += chunk.length;
            if (bytes > MAX_REQUEST + 1024) {
                send(res, 413, { error: 'HTML exceeds 2 MB.' });
                return;
            }
            chunks.push(chunk);
        }
        let body;
        try { body = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch (_) {
            send(res, 400, { error: 'Invalid JSON.' }); return;
        }
        if (typeof body.html !== 'string' || Buffer.byteLength(body.html) > MAX_REQUEST) {
            send(res, 400, { error: 'Provide an HTML string smaller than 2 MB.' });
            return;
        }
        const html = await render(body.html);
        send(res, 200, { html });
    } catch (error) {
        send(res, 422, { error: 'Rendering failed or timed out: ' + error.message });
    } finally {
        busy = false;
    }
}).listen(3000, '0.0.0.0');
