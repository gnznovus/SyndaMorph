'use strict';

const dns = require('node:dns').promises;
const net = require('node:net');
const { chromium } = require('playwright');
const { extractSections } = require('./sections');
const { inspectDocument } = require('./diagnostics');
const { VIEWPORTS, captureResponsiveViewport, reconcileResponsive } = require('./responsive-capture');
const { enabled: referenceEnabled, prepareReferencePage, captureReference, storeReferences } = require('./production-reference');
const { referenceFetch } = require('./reference-fetch');

const MAX_SECTIONS = 60;
const MAX_HTML = 2 * 1024 * 1024;
const MAX_REQUESTS = 250;
const hosts = (value) => new Set((value || '').split(',').map((item) => item.trim().toLowerCase()).filter(Boolean));
const ALLOWED_HOSTS = hosts(process.env.SYNDAMORPH_CAPTURE_HOSTS);
const RESOURCE_HOSTS = hosts(process.env.SYNDAMORPH_RESOURCE_HOSTS);


function publicIPv4(ip) {
    if (net.isIP(ip) !== 4) return false;
    const [a, b, c] = ip.split('.').map(Number);
    return !(a === 0 || a === 10 || a === 127 || a >= 224 ||
        a === 100 && b >= 64 && b <= 127 ||
        a === 169 && b === 254 || a === 172 && b >= 16 && b <= 31 ||
        a === 192 && (b === 168 || b === 0 && (c === 0 || c === 2)) ||
        a === 198 && (b === 18 || b === 19 || b === 51 && c === 100) ||
        a === 203 && b === 0 && c === 113);
}

function validateAgainstHosts(value, allowedHosts) {
    let url;
    try { url = new URL(value); } catch (_) { throw new Error('Invalid absolute URL.'); }
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password ||
        net.isIP(url.hostname) || !allowedHosts.has(url.hostname.toLowerCase()) ||
        (url.port && !['80', '443'].includes(url.port))) {
        throw new Error('URL host is not approved for this POC.');
    }
    return url;
}

function validateURL(value) {
    return validateAgainstHosts(value, ALLOWED_HOSTS);
}

function validateResourceURL(value) {
    return validateAgainstHosts(value, new Set([...ALLOWED_HOSTS, ...RESOURCE_HOSTS]));
}

async function assertPublicHost(url) {
    const records = await dns.resolve4(url.hostname);
    if (!records.length || !records.every(publicIPv4)) throw new Error('Non-public DNS response blocked.');
}

// Extraction only: never follow linked pages, click links, or submit forms.
async function capture(urlValue, mode = 'auto', options = {}) {
    const url = validateURL(urlValue);
    const archive = options.archive === true;
    const touch = options.touch === true;
    const guarded = archive || touch;
    if(guarded)url.hash='';
    if (!['auto', 'static', 'browser'].includes(mode)) throw new Error('Unsupported render mode.');
    if(!options.recordedResponses)await assertPublicHost(url);
    let browser;
    try {
        browser = await chromium.launch(guarded ? { headless:true,
            proxy:{ server:'http://127.0.0.1:9',bypass:'<-loopback>' },
            args:['--disable-web-security','--force-webrtc-ip-handling-policy=disable_non_proxied_udp'] } : { headless:true });
        const context = await browser.newContext({
            viewport: { width: options.width || 1440, height: 900 }, deviceScaleFactor: 1,
            serviceWorkers: 'block', acceptDownloads: false,
            permissions: [], javaScriptEnabled: mode !== 'static',
            ...(guarded ? { colorScheme:'light',reducedMotion:'reduce',locale:'en-US',timezoneId:'Asia/Bangkok' } : {}),
        });
        let count = 0;
        const archiveTransport = options.archiveTransport ?? { responses:new Map(), bytes:0 };
        const archiveResponses = archiveTransport.responses;
        const touchBlocked=[];
        let networkSealed=false;
        const sealNetwork=()=>{
            if(!touch)throw new Error('Touch network guard is unavailable.');
            networkSealed=true;
            return touchBlocked;
        };
        const requestFailures = [];
        const responses = { success: 0, error: 0, denied: 0, byType: {} };
        const browserErrors = [];
        const addFailure = (kind, detail) => {
            if (requestFailures.length < 25) requestFailures.push({ kind, detail: String(detail).slice(0, 180) });
        };
        await context.route('**/*', async (route) => {
            if(networkSealed){
                const request=route.request();
                if(touchBlocked.length<32)touchBlocked.push({ method:request.method(),resourceType:request.resourceType(),
                    navigation:request.isNavigationRequest() });
                return route.abort('blockedbyclient');
            }
            try {
                if (++count > MAX_REQUESTS) { responses.denied++; addFailure('request_limit', route.request().resourceType()); return route.abort('blockedbyclient'); }
                // Navigation stays on explicitly approved source hosts.
                // Third-party dependencies are separate and cannot become crawl targets.
                const request = route.request();
                const target = request.isNavigationRequest() ? validateURL(request.url()) : validateResourceURL(request.url());
                if (!['GET', 'HEAD'].includes(request.method())) {
                    responses.denied++;
                    addFailure('blocked_method',request.resourceType());
                    return route.abort('blockedbyclient');
                }
                if (guarded) {
                    if(request.isNavigationRequest() && (request.frame().parentFrame() || target.href!==url.href)){
                        responses.denied++;
                        addFailure('blocked_navigation',request.resourceType());
                        return route.abort('blockedbyclient');
                    }
                    const key=request.method()+' '+target.href;
                    if(options.recordedResponses){
                        const recorded=options.recordedResponses.get(key);
                        if(recorded)return route.fulfill(recorded);
                        return route.abort('blockedbyclient');
                    }
                    if(!archiveResponses.has(key)){
                        const retrieval=referenceFetch(target,request,publicIPv4).then(response=>{
                            if(response.status<300 && archiveResponses.size<=250 && archiveTransport.bytes+response.body.length<=32000000)
                                archiveTransport.bytes+=response.body.length;
                            else archiveResponses.delete(key);
                            return response;
                        });
                        archiveResponses.set(key,retrieval);
                        retrieval.catch(()=>archiveResponses.delete(key));
                    }
                    return route.fulfill(await archiveResponses.get(key));
                }
                await assertPublicHost(target);
                return route.continue();
            } catch (error) {
                responses.denied++;
                let host = 'unknown';
                try { host = new URL(route.request().url()).hostname; } catch (_) {}
                // Hostnames are sufficient for diagnosing missing approved CDN
                // dependencies. Never log URL paths, queries or headers.
                addFailure('blocked_resource', route.request().resourceType() + ' host=' + host + ': ' + error.message);
                return route.abort('blockedbyclient');
            }
        });
        if(guarded)await context.routeWebSocket(/.*/,socket => {
            responses.denied++;
            addFailure('blocked_websocket','WebSocket');
            if(networkSealed && touchBlocked.length<32)touchBlocked.push({ method:'CONNECT',resourceType:'websocket',navigation:false });
            socket.close({ code:1008,reason:'Reference capture' });
        });
        const page = await context.newPage();
        if(archive)await prepareReferencePage(page);
        page.on('popup', (popup) => popup.close().catch(() => {}));
        page.on('pageerror', (error) => { if (browserErrors.length < 12) browserErrors.push(String(error.message).slice(0, 200)); });
        page.on('response', (response) => {
            const kind = response.request().resourceType();
            responses.byType[kind] = (responses.byType[kind] || 0) + 1;
            if (response.status() >= 400) { responses.error++; addFailure('http_error', kind + ' HTTP ' + response.status()); }
            else responses.success++;
        });
        const response = await page.goto(url.toString(), { waitUntil: guarded?'load':'domcontentloaded', timeout: 15000 });
        if (!response || response.status() >= 400) throw new Error('Page request failed (HTTP ' + (response?.status() || 'unknown') + ').');
        if (mode !== 'static') {
            // Async app bootstraps may need React and other CDN scripts before
            // the temporary display:none root is removed.
            await page.waitForFunction(() => {
                const body = document.body;
                if (!body) return false;
                const candidate = body.querySelector('main,section,[role="main"]') || body;
                const box = candidate.getBoundingClientRect();
                return box.width > 20 && box.height > 20 &&
                    (body.innerText || '').trim().length > 30;
            }, undefined, { timeout: 8000 }).catch(() => {});
            await page.waitForTimeout(350);
        }
        if (options.inspect) await page.evaluate(async () => {
            await Promise.race([document.fonts.ready, new Promise((resolve) => setTimeout(resolve, 2500))]);
        });
        const reference = archive ? await captureReference(page,options.width,{ network:{ ...responses,samples:requestFailures },browserErrors }) : null;
        const diagnostics = await page.evaluate(inspectDocument);
        const result = await page.evaluate(extractSections, { maxSections: MAX_SECTIONS + (options.includeRequiredRoots ? 1 : 0),
            maxHtml: MAX_HTML, inventoryOnly: Boolean(options.inspect) && !options.includeHtml,
            includeRequiredRoots: Boolean(options.includeRequiredRoots) });
        if (result.length > MAX_SECTIONS) throw new Error('Responsive discovery exceeds the section limit.');
        diagnostics.network = { ...responses, samples: requestFailures };
        diagnostics.browserErrors = browserErrors;
        diagnostics.sectionCount = result.length;
        diagnostics.structureOnlySections = result.filter((section) => section.geometryAvailable === false).length;
        diagnostics.contentReady = diagnostics.visibleElements > 4 &&
            (diagnostics.headings.some((heading) => heading.bounds.width > 2 && heading.bounds.height > 2) ||
             diagnostics.bodyChildren.some((child) => child.display !== 'none' && child.height > 2));
        if (!diagnostics.contentReady) {
            result.length = 0;
            diagnostics.sectionCount = 0;
            diagnostics.structureOnlySections = 0;
        }
        if (options.inspect) return await options.inspect(page,result,diagnostics,reference,touch?sealNetwork:null);
        return { url: page.url(), mode: mode === 'static' ? 'static' : 'browser', diagnostics, sections: result, warnings: [
            'Captured DOM and computed styles are an approximation; scripts and interactive behavior are not converted.',
            'Images remain remote for this POC; asset portability is not implemented.',
            ...(!diagnostics.contentReady ? ['Page did not render visible content. Inspect blocked CDN dependencies and browser errors; unrendered DOM cannot be converted faithfully.'] : []),
        ] };
    } finally { await browser?.close().catch(() => {}); }
}

async function captureResponsive(url) {
    const views = [];
    const snapshots = [];
    const archive=referenceEnabled();
    const archiveTransport=archive?{ responses:new Map(),bytes:0 }:undefined;
    let canonical;
    for (const width of VIEWPORTS) {
        const { describeSource } = require('./production-source');
        const captured = await capture(url, 'browser', { width, includeRequiredRoots: true, includeHtml: true, archive, archiveTransport,
            inspect: async (page, sections, diagnostics, reference) => ({ url: page.url(), reference,
                evidence: await captureResponsiveViewport(page, sections, width, diagnostics, describeSource) }) });
        if (canonical && captured.url !== canonical) throw new Error('The capture resolved to different URLs across widths.');
        canonical = captured.url;
        views.push(captured.evidence);
        if(captured.reference)snapshots.push(captured.reference);
    }
    return { url: canonical, mode: 'browser', sections: reconcileResponsive(canonical, views),
        ...(archive ? { referenceArchive:await storeReferences(snapshots,archiveTransport.responses) } : {}),
        views: views.map(({ sections, ...view }) => ({ ...view, discovered: sections.length,
            order: sections.map(section => section.sourceRef) })) };
}

module.exports = { capture, captureResponsive, validateURL, validateResourceURL, publicIPv4 };
