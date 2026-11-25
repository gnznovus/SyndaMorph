'use strict';
const http = require('node:http');
const { capture, captureResponsive } = require('./capture');
const { productionSource } = require('./production-source');
const { enabled: referenceEnabled } = require('./production-reference');
const { createAnalyzeHandler } = require('./mvp/endpoint');
const { runCommunicationPoc } = require('./purr-poc');

const TOKEN = process.env.SYNDAMORPH_BACKEND_TOKEN;
if (!TOKEN || !process.env.SYNDAMORPH_CAPTURE_HOSTS) throw new Error('Backend token and approved host list are required.');
const analyze = createAnalyzeHandler({ capture });
let responsiveInProgress = false;

const respond = (res, status, value) => {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(value));
};
http.createServer(async (req, res) => {
    if (!['/capture', '/production-source', '/mvp/analyze', '/purr/communication-poc'].includes(req.url) || req.method !== 'POST') {
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
        if (req.url === '/purr/communication-poc') {
            if (typeof input.job !== 'string' || input.job.length > 200) return respond(res, 400, { error: 'Invalid POC job.' });
            const result = await runCommunicationPoc(input.job);
            return respond(res, 200, result);
        }
        if (typeof input.url !== 'string' || input.url.length > 2048) return respond(res, 400, { error: 'Invalid URL.' });
        if (req.url === '/production-source') {
            const archives=referenceEnabled() && (input.sectionId!=null || input.variants!=null);
            if(archives && responsiveInProgress)return respond(res,429,{ error:'Responsive capture is already running.' });
            if(archives)responsiveInProgress=true;
            try { return respond(res,200,await productionSource(input.url,input.sectionId,input.variants)); }
            finally { if(archives)responsiveInProgress=false; }
        }
        if (req.url === '/mvp/analyze') {
            const result = await analyze(input);
            return respond(res, 200, result);
        }
        if (input.responsive === true) {
            if (responsiveInProgress) return respond(res, 429, { error: 'Responsive capture is already running.' });
            responsiveInProgress = true;
            try {
                const result = await captureResponsive(input.url);
                const body = JSON.stringify(result);
                if (Buffer.byteLength(body) > 24000000) return respond(res, 413, { error: 'Responsive capture exceeds the archive limit.' });
                res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
                return res.end(body);
            } catch (error) {
                if (error.code === 'sm_reference_unverified')
                    return respond(res, 422, { error: 'Rendered reference verification is incomplete.' });
                return respond(res, 422, { error: error.message === 'URL host is not approved for this POC.'
                    ? error.message : 'Responsive page capture failed. Verify all three viewport renders.' });
            } finally { responsiveInProgress = false; }
        }
        const result = await capture(input.url, input.mode || 'auto');
        return respond(res, 200, result);
    } catch (error) {
        // Never include API credentials, upstream response body, or source HTML in errors.
        const message = String(error.message || '');
        const allowed = /^(MVP AI analysis is disabled\.|ANTHROPIC_API_KEY is not configured\.|Invalid MVP analysis request\.|Requested section is not available in the capture\.|Claude API request failed \(HTTP \d+\)\.|Claude response incomplete or interrupted\.|Claude returned invalid JSON\.|Claude returned no bounded JSON output\.|Claude changed section identity\.)$/;
        if (req.url === '/purr/communication-poc') {
            const errorTypes = {
                purr_not_configured: [503, 'Purr Gateway is not configured in the backend.'],
                purr_auth: [502, 'Purr Gateway denied the client credentials.'],
                purr_invalid_job: [400, 'Invalid POC job.'],
                purr_invalid: [502, 'Purr Gateway returned an unexpected response.'],
            };
            const [code, publicMessage] = errorTypes[message] || [502, 'Purr Gateway request failed or timed out.'];
            return respond(res, code, { error: publicMessage });
        }
        if (req.url === '/mvp/analyze' && !allowed.test(message)) {
            return respond(res, 422, { error: 'MVP analysis failed; inspect server diagnostics.' });
        }
        return respond(res, 422, { error: message });
    }
}).listen(3100, '0.0.0.0');
