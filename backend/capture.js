'use strict';

const dns = require('node:dns').promises;
const net = require('node:net');
const { chromium } = require('playwright');
const { extractSections } = require('./sections');

const MAX_SECTIONS = 60;
const MAX_HTML = 2 * 1024 * 1024;
const MAX_REQUESTS = 250;
const ALLOWED_HOSTS = new Set((process.env.SYNDAMORPH_CAPTURE_HOSTS || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean));

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

function validateURL(value) {
    let url;
    try { url = new URL(value); } catch (_) { throw new Error('Invalid absolute URL.'); }
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password ||
        net.isIP(url.hostname) || !ALLOWED_HOSTS.has(url.hostname.toLowerCase()) ||
        (url.port && !['80', '443'].includes(url.port))) {
        throw new Error('URL host is not approved for this POC.');
    }
    return url;
}

async function assertPublicHost(url) {
    const records = await dns.resolve4(url.hostname);
    if (!records.length || !records.every(publicIPv4)) throw new Error('Non-public DNS response blocked.');
}

// Extraction only: never follow linked pages, click links, or submit forms.
async function capture(urlValue, mode = 'auto') {
    const url = validateURL(urlValue);
    if (!['auto', 'static', 'browser'].includes(mode)) throw new Error('Unsupported render mode.');
    await assertPublicHost(url);
    let browser;
    try {
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({
            viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1,
            serviceWorkers: 'block', acceptDownloads: false,
            permissions: [], javaScriptEnabled: mode !== 'static',
        });
        let count = 0;
        await context.route('**/*', async (route) => {
            try {
                if (++count > MAX_REQUESTS) return route.abort('blockedbyclient');
                const target = validateURL(route.request().url());
                await assertPublicHost(target);
                // Prevent active operations from the captured site.
                if (!['GET', 'HEAD'].includes(route.request().method())) return route.abort('blockedbyclient');
                return route.continue();
            } catch (_) { return route.abort('blockedbyclient'); }
        });
        const page = await context.newPage();
        page.on('popup', (popup) => popup.close().catch(() => {}));
        const response = await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 15000 });
        if (!response || response.status() >= 400) throw new Error('Page request failed (HTTP ' + (response?.status() || 'unknown') + ').');
        if (mode !== 'static') {
            await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
            await page.waitForTimeout(350);
        }
        const result = await page.evaluate(extractSections, { maxSections: MAX_SECTIONS, maxHtml: MAX_HTML });
        if (!result.length) throw new Error('No visible content sections found in the rendered document.');
        return { url: page.url(), mode: mode === 'static' ? 'static' : 'browser', sections: result, warnings: [
            'Captured DOM and computed styles are an approximation; scripts and interactive behavior are not converted.',
            'Images remain remote for this POC; asset portability is not implemented.',
        ] };
    } finally { await browser?.close().catch(() => {}); }
}

module.exports = { capture, validateURL, publicIPv4 };
