'use strict';

const http = require('node:http');
const { spawn } = require('node:child_process');
const { createHash, timingSafeEqual } = require('node:crypto');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { compareScreenshots, cropScreenshots } = require('./compare');
const { PROTOCOL, DEFAULT_VIEWPORTS, MAX_RESPONSE, MAX_COMPARE_REQUEST,
    MAX_SCREENSHOT, RendererError, validViewports } = require('./inspection-contract');

const MAX_HTML = 2 * 1024 * 1024;
const MAX_JSON_REQUEST = MAX_HTML + 1024;
const INSPECT_TIMEOUT = 60000;
const build = ['server.js', 'render-worker.js', 'compare.js', 'inspection-contract.js', 'package.json']
    .reduce((hash, file) => hash.update(readFileSync(join(__dirname, file))).update('\0'), createHash('sha256')).digest('hex');
const diagnostic = { service: 'syndamorph-renderer', inspectionProtocol: PROTOCOL, build,
    maxResponseBytes: MAX_RESPONSE, maxScreenshotBytes: MAX_SCREENSHOT };
const workerErrors = {
    browser_timeout: 504, browser_crash: 503, worker_failure: 502,
    screenshot_limit: 507, evidence_limit: 507, source_frame_unavailable: 422,
};

function runBrowserWorker(html, viewports, signal, options = {}) {
    const inspect = viewports !== null;
    return new Promise((resolve, reject) => {
        const env = { ...process.env };
        delete env.SYNDAMORPH_RENDERER_TOKEN;
        const child = spawn(process.execPath, options.workerArgs ?? [join(__dirname, 'render-worker.js')], {
            detached: true, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'], env,
        });
        let failure;
        let forceKill;
        let bytes = 0;
        const chunks = [];
        const fail = (code, status) => {
            if (failure) return;
            failure = new RendererError(code, status);
            if (!child.pid) return;
            // Playwright handles SIGTERM and closes its Chromium process group.
            // Retain the hard deadline if the worker or browser is wedged.
            child.kill('SIGTERM');
            forceKill = setTimeout(() => {
                try {
                    if (process.platform === 'win32') child.kill('SIGKILL');
                    else process.kill(-child.pid, 'SIGKILL');
                } catch (_) { /* already exited */ }
            }, 1500);
        };
        const onAbort = () => fail('request_aborted', 499);
        const timer = setTimeout(() => fail('browser_timeout', 504),
            options.timeoutMs ?? (inspect ? INSPECT_TIMEOUT : 8000));
        signal?.addEventListener('abort', onAbort, { once: true });
        if (signal?.aborted) onAbort();
        child.on('error', () => fail('worker_crash', 503));
        // Drain stderr without persisting anything Chromium may have echoed.
        child.stderr.resume();
        child.stdout.on('data', buf => {
            if (failure) return;
            bytes += buf.length;
            if (bytes > (inspect ? MAX_RESPONSE : MAX_HTML + 4096)) {
                fail('evidence_limit', 507);
                return;
            }
            chunks.push(buf);
        });
        child.on('close', code => {
            clearTimeout(timer);
            clearTimeout(forceKill);
            signal?.removeEventListener('abort', onAbort);
            if (failure) return reject(failure);
            if (code !== 0 || bytes === 0) return reject(new RendererError('worker_crash', 503));
            let result;
            try { result = JSON.parse(Buffer.concat(chunks, bytes).toString('utf8')); }
            catch (_) { return reject(new RendererError('worker_invalid_json', 502)); }
            if (result?.error) {
                const code = Object.hasOwn(workerErrors, result.error) ? result.error : 'worker_failure';
                return reject(new RendererError(code, workerErrors[code]));
            }
            if (inspect) {
                if (!Array.isArray(result?.evidence) || result.evidence.length === 0)
                    return reject(new RendererError('missing_evidence', 502));
                const widths = result.evidence.map(sample => sample?.width);
                if (!validViewports(widths) || widths.some(width => !viewports.includes(width)))
                    return reject(new RendererError('viewport_mismatch', 502));
                return resolve(result.evidence);
            }
            if (typeof result?.html !== 'string') return reject(new RendererError('worker_failure', 502));
            return resolve(result.html);
        });
        child.stdin.on('error', () => {});
        child.stdin.end(JSON.stringify({ html, inspect, viewports, extent:options.extent,sourceFrames:options.sourceFrames,
            tabsRuntime:options.tabsRuntime,scrollStrips:options.scrollStrips,disclosures:options.disclosures,imageStrip:options.imageStrip,
            revealCards:options.revealCards,roomsRuntime:options.roomsRuntime }));
    });
}

async function readBody(req, limit) {
    const length = Number(req.headers['content-length']);
    if (length > limit) {
        req.resume();
        throw new RendererError('request_limit', 413);
    }
    const chunks = [];
    let size = 0;
    // Leaving the iterator on rejection must not destroy the socket before
    // the status and bounded error body can be sent.
    for await (const part of req.iterator({ destroyOnReturn: false })) {
        size += part.length;
        if (size > limit) {
            req.resume();
            throw new RendererError('request_limit', 413);
        }
        chunks.push(part);
    }
    try { return JSON.parse(Buffer.concat(chunks, size).toString('utf8')); }
    catch (_) { throw new RendererError('invalid_request_json', 400); }
}

function createRendererServer(render = runBrowserWorker, token = process.env.SYNDAMORPH_RENDERER_TOKEN) {
    if (typeof token !== 'string' || token.length < 32 || token.length > 256 || /[^!-~]/.test(token))
        throw new Error('A private renderer token of at least 32 printable characters is required.');
    const expected = Buffer.from(token);
    const authenticated = req => {
        const supplied = req.headers['x-syndamorph-renderer-token'];
        if (typeof supplied !== 'string' || Buffer.byteLength(supplied) !== expected.length) return false;
        return timingSafeEqual(expected, Buffer.from(supplied));
    };
    let busy = false;
    return http.createServer({ requestTimeout: 30000, headersTimeout: 10000 }, async (req, res) => {
        function reply(status, payload, includeProtocol = true) {
            if (res.writableEnded || res.destroyed) return;
            const bytes = Buffer.from(JSON.stringify(payload));
            if (req.url === '/inspect' && bytes.length > MAX_RESPONSE)
                throw new RendererError('evidence_limit', 507);
            res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store',
                'content-length': bytes.length, ...(includeProtocol ? { 'x-syndamorph-inspection-protocol': PROTOCOL,
                    'x-syndamorph-renderer-build': build } : {}), ...(status === 429 ? { 'retry-after': '1' } : {}),
                ...(status === 401 ? { connection: 'close' } : {}) });
            res.end(bytes);
        }
        if (req.method === 'GET' && req.url === '/healthz') {
            if (req.headers['x-syndamorph-renderer-token'] === undefined) return reply(200, { status: 'ok' }, false);
            return authenticated(req) ? reply(200, diagnostic) : reply(401, { error: 'unauthorized' }, false);
        }
        if (req.method !== 'POST' || !['/render','/inspect','/compare','/crop','/check-tabs','/check-scroll-strips','/check-disclosures','/check-image-strip','/check-reveal-cards','/check-rooms'].includes(req.url))
            return reply(404, { error: 'not_found' }, authenticated(req));
        if (!authenticated(req)) return reply(401, { error: 'unauthorized' }, false);
        if (busy) {
            req.resume();
            return reply(429, { error: 'renderer_busy' });
        }
        busy = true;
        const controller = new AbortController();
        res.once('close', () => { if (!res.writableFinished) controller.abort(); });
        try {
            const limit = req.url === '/compare' ? MAX_COMPARE_REQUEST :
                req.url === '/crop' ? 4 * MAX_SCREENSHOT + 4096 : MAX_JSON_REQUEST;
            const body = await readBody(req, limit);
            if (!body || typeof body !== 'object' || Array.isArray(body)) throw new RendererError('invalid_request_json', 400);
            if (req.url === '/crop') return reply(200, { images: cropScreenshots(body.items) });
            if (req.url === '/compare') return reply(200,{ comparison:compareScreenshots(
                body.reference,body.native,body.fitExtent===true,body.includeOverlay===true,body.localize===true) });
            if (typeof body.html !== 'string' || !body.html.trim()) throw new RendererError('invalid_html', 400);
            if (Buffer.byteLength(body.html) > MAX_HTML) throw new RendererError('request_limit', 413);
            if (req.url === '/render') return reply(200, { html: await render(body.html, null, controller.signal) });
            if (Object.hasOwn(body, 'inspectionProtocol') && body.inspectionProtocol !== PROTOCOL)
                throw new RendererError('protocol_mismatch', 409);
            const viewports = Object.hasOwn(body, 'viewports') ? body.viewports : DEFAULT_VIEWPORTS;
            if (!validViewports(viewports)) throw new RendererError('invalid_viewports', 400);
            if(req.url==='/check-tabs'){
                if(typeof body.runtime!=='string'||!body.runtime||Buffer.byteLength(body.runtime)>16384)
                    throw new RendererError('invalid_html',400);
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{tabsRuntime:body.runtime})});
            }
            if(req.url==='/check-scroll-strips')
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{scrollStrips:true})});
            if(req.url==='/check-disclosures')
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{disclosures:true})});
            if(req.url==='/check-image-strip')
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{imageStrip:true})});
            if(req.url==='/check-reveal-cards')
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{revealCards:true})});
            if(req.url==='/check-rooms'){
                if(typeof body.runtime!=='string'||!body.runtime||Buffer.byteLength(body.runtime)>16384)
                    throw new RendererError('invalid_html',400);
                return reply(200,{evidence:await render(body.html,viewports,controller.signal,{roomsRuntime:body.runtime})});
            }
            if (body.extent !== undefined && !['section', 'page', 'page-fold'].includes(body.extent)) throw new RendererError('invalid_html', 400);
            const frames=body.sourceFrames;
            if(frames!==undefined&&(body.extent!=='section'||!frames||typeof frames!=='object'||Array.isArray(frames)||
                Object.keys(frames).length!==viewports.length||viewports.some(width=>!Number.isFinite(frames[width])||
                    frames[width]<0||frames[width]>36000)))throw new RendererError('source_frame_unavailable',422);
            reply(200, { evidence: await render(body.html, viewports, controller.signal, { extent: body.extent,sourceFrames:frames }) });
        } catch (error) {
            const known = error instanceof RendererError;
            // Only server-owned categories cross the response boundary.
            reply(known ? error.status : 422, { error: known ? error.code : 'invalid_renderer_input' });
        } finally {
            busy = false;
        }
    });
}

if (require.main === module) createRendererServer().listen(3000, '0.0.0.0');
module.exports = { createRendererServer, runBrowserWorker };
