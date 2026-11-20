'use strict';

const dns = require('node:dns').promises;
const net = require('node:net');
const { chromium } = require('playwright');

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
        const result = await page.evaluate(({ maxSections, maxHtml }) => {
            const candidates = Array.from(document.querySelectorAll('main > *, body > section, body > header, body > footer')).filter((el) => !['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName));
            const selected = candidates.filter((el) => !candidates.some((other) => other !== el && other.contains(el)));
            const roots = selected.length ? selected : Array.from(document.body.children).filter((el) => !['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName));
            const styleKeys = ['display', 'color', 'backgroundColor', 'backgroundImage', 'backgroundSize', 'backgroundPosition', 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'textAlign', 'padding', 'margin', 'gap', 'columnGap', 'rowGap', 'gridTemplateColumns', 'flexDirection', 'alignItems', 'justifyContent', 'borderRadius', 'width', 'maxWidth'];
            const styleNames = ['display', 'color', 'background-color', 'background-image', 'background-size', 'background-position', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'padding', 'margin', 'gap', 'column-gap', 'row-gap', 'grid-template-columns', 'flex-direction', 'align-items', 'justify-content', 'border-radius', 'width', 'max-width'];
            const convert = (root) => {
                const clone = root.cloneNode(true);
                const oldNodes = [root, ...root.querySelectorAll('*')];
                const newNodes = [clone, ...clone.querySelectorAll('*')];
                oldNodes.forEach((original, i) => {
                    const copy = newNodes[i];
                    if (!copy) return;
                    for (const attr of [...copy.attributes]) {
                        if (/^on/i.test(attr.name)) copy.removeAttribute(attr.name);
                    }
                    if (['SCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'FORM'].includes(copy.tagName)) {
                        if (copy.tagName === 'FORM') { copy.removeAttribute('action'); }
                        else { copy.remove(); return; }
                    }
                    const computed = getComputedStyle(original);
                    styleKeys.forEach((key, k) => {
                        const val = computed[key];
                        if (val && val !== 'none' && val !== 'normal' && val !== 'auto' && val !== 'initial') {
                            copy.style.setProperty(styleNames[k], val);
                        }
                    });
                    if (copy.tagName === 'IMG' && original.currentSrc) copy.setAttribute('src', original.currentSrc);
                    if (copy.hasAttribute('src')) copy.setAttribute('src', new URL(copy.getAttribute('src'), document.baseURI).href);
                    if (copy.hasAttribute('href')) copy.setAttribute('href', new URL(copy.getAttribute('href'), document.baseURI).href);
                });
                return clone.outerHTML;
            };
            return roots.slice(0, maxSections).map((node, index) => {
                const bounds = node.getBoundingClientRect();
                const label = node.querySelector('h1,h2,h3,h4')?.textContent?.trim() || node.getAttribute('aria-label') || node.id || node.tagName.toLowerCase();
                return { id: 'section-' + (index + 1), label: label.slice(0, 100),
                    tag: node.tagName.toLowerCase(), bounds: { x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height },
                    html: convert(node).slice(0, maxHtml) };
            });
        }, { maxSections: MAX_SECTIONS, maxHtml: MAX_HTML });
        return { url: page.url(), mode: mode === 'static' ? 'static' : 'browser', sections: result, warnings: [
            'Captured DOM and computed styles are an approximation; scripts and interactive behavior are not converted.',
            'Images remain remote for this POC; asset portability is not implemented.',
        ] };
    } finally { await browser?.close().catch(() => {}); }
}

module.exports = { capture, validateURL, publicIPv4 };
