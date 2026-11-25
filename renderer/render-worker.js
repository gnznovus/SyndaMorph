'use strict';

const { chromium } = require('playwright');
const axe = require('axe-core');
const { HEIGHT, MAX_JPEG, MAX_METADATA, DEFAULT_VIEWPORTS, RendererError } = require('./inspection-contract');

async function boundedScreenshot(page) {
    for (const quality of [65, 50, 35, 20, 10]) {
        const bytes = await page.screenshot({ type: 'jpeg', quality, timeout: 5000 });
        if (bytes.length <= MAX_JPEG) return bytes.toString('base64');
    }
    throw new RendererError('screenshot_limit', 507);
}

const input = [];
process.stdin.on('data', (chunk) => input.push(chunk));
process.stdin.on('end', async () => {
    let browser;
    let crashed = false;
    const evidence = [];
    try {
        const { html, inspect, viewports = DEFAULT_VIEWPORTS } = JSON.parse(Buffer.concat(input).toString('utf8'));
        browser = await chromium.launch({
            headless: true, timeout: 10000,
            args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
        });
        const context = await browser.newContext({
            javaScriptEnabled: !inspect,
            serviceWorkers: 'block',
            acceptDownloads: false,
        });
        // Completely deny external scripts, stylesheets, images and API calls.
        // This preview only supports inline scripts and inline styles.
        await context.route('**/*', (route) => route.abort('blockedbyclient'));
        const page = await context.newPage();
        page.on('crash', () => { crashed = true; });
        page.on('popup', (popup) => popup.close().catch(() => {}));
        await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 5000 });
        await page.waitForTimeout(600);
        if (inspect) {
            // Both inputs are embeddable fragments. Remove the UA's body
            // margin without overriding a more specific authored style.
            await page.evaluate(() => {
                const frameStyle = document.createElement('style');
                frameStyle.textContent = ':where(body){margin:0}';
                document.head.prepend(frameStyle);
            });
            // Render untrusted input with JS disabled. Run axe separately on a
            // sanitized DOM copy, never on the original script-bearing document.
            const safeMarkup = await page.evaluate(() => {
                const doc = document.documentElement.cloneNode(true);
                doc.querySelectorAll('script,iframe,object,embed,form,link,base,meta[http-equiv]').forEach(el=>el.remove());
                doc.querySelectorAll('*').forEach(el => {
                    for(const attr of [...el.attributes]) {
                        if(/^on/i.test(attr.name) || /^(?:srcdoc|formaction|action)$/i.test(attr.name) ||
                            (/^(?:href|src)$/i.test(attr.name) && /^(?:javascript:|data:text\/html)/i.test(attr.value)))
                            el.removeAttribute(attr.name);
                    }
                });
                return '<!doctype html>' + doc.outerHTML;
            });
            const a11yContext = await browser.newContext({
                javaScriptEnabled:true, serviceWorkers:'block', acceptDownloads:false
            });
            await a11yContext.route('**/*', route=>route.abort('blockedbyclient'));
            const a11yPage = await a11yContext.newPage();
            a11yPage.on('crash', () => { crashed = true; });
            await a11yPage.setContent(safeMarkup, {waitUntil:'domcontentloaded',timeout:5000});
            await a11yPage.evaluate(axe.source);
            for (const width of viewports) {
                await page.setViewportSize({ width, height: HEIGHT });
                const snapshot = await page.evaluate(() => {
                    const elements = [...document.body.querySelectorAll('*')].filter(el => !['STYLE','SCRIPT'].includes(el.tagName));
                    const positions = new Map(elements.map((el, index) => [el, index]));
                    const rect = r => [r.x, r.y, r.width, r.height].map(value => Math.round(value * 10) / 10);
                    // Compare literal authored copy, including inline formatting and
                    // long text, independently of geometry and text-transform.
                    const copyParts = [];
                    function gatherCopy(node) {
                        if (node.nodeType === Node.TEXT_NODE) {
                            copyParts.push(node.nodeValue);
                            return;
                        }
                        if (node.nodeType !== Node.ELEMENT_NODE ||
                            /^(?:STYLE|SCRIPT|NOSCRIPT|TEMPLATE|SVG)$/.test(node.tagName)) return;
                        const { display, visibility } = getComputedStyle(node);
                        if (display === 'none') return;
                        const boundary = node.tagName === 'BR' || !/^(?:inline|contents)/.test(display);
                        if (boundary) copyParts.push(' ');
                        for (const child of node.childNodes) {
                            if (child.nodeType !== Node.TEXT_NODE || visibility === 'visible') gatherCopy(child);
                        }
                        if (boundary) copyParts.push(' ');
                    }
                    gatherCopy(document.body);
                    return {
                        copy: copyParts.join('').replace(/\s+/gu, ' ').trim(),
                        overflow: document.documentElement.scrollWidth > innerWidth,
                        height: document.documentElement.scrollHeight,
                        elements: elements.slice(0, 200).map(el => {
                            const r = el.getBoundingClientRect(), s = getComputedStyle(el);
                            const text = el.children.length ? '' : el.textContent.replace(/\s+/gu, ' ').trim().slice(0, 250);
                            const range = document.createRange();
                            if (text) range.selectNodeContents(el);
                            const lines = text ? [...range.getClientRects()].slice(0, 12).map(rect) : [];
                            const wrapStyle = s.textWrapStyle;
                            let wrapChangesGeometry = false;
                            if (lines.length > 1 && ['balance','pretty'].includes(wrapStyle)) {
                                const old = el.style.getPropertyValue('text-wrap-style');
                                const priority = el.style.getPropertyPriority('text-wrap-style');
                                try {
                                    el.style.setProperty('text-wrap-style', 'auto', 'important');
                                    const natural = [...range.getClientRects()].slice(0, 12).map(rect);
                                    wrapChangesGeometry = JSON.stringify(lines) !== JSON.stringify(natural);
                                } finally {
                                    if (old) el.style.setProperty('text-wrap-style', old, priority);
                                    else el.style.removeProperty('text-wrap-style');
                                }
                            }
                            const id = el.getAttribute('data-sm-node') || (el.tagName === 'A' ? el.parentElement?.getAttribute('data-sm-node') : null);
                            return { tag: el.tagName.slice(0, 32), id: el.id.slice(0, 128), text: el.children.length ? '' : el.textContent.slice(0, 250),
                                node: id?.slice(0, 128) ?? null,
                                parent: positions.get(el.parentElement) ?? null,
                                normalizedText: text,
                                rect: rect(r),
                                textRect: text ? rect(range.getBoundingClientRect()) : null,
                                lineRects: lines,
                                wrapChangesGeometry,
                                styles: Object.fromEntries(['color','backgroundColor','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','maxWidth','overflowWrap','padding','margin','gap','display','textAlign','textTransform','textWrapStyle','alignItems','justifyContent','borderRadius'].map(k => [k,s[k].slice(0, 128)])),
                                widthValue: el.computedStyleMap().get('width')?.toString().slice(0,128) ?? null,
                                clipped: /^(?:hidden|clip)$/.test(s.overflowX) && el.scrollWidth > el.clientWidth + 2,
                                missingAlt: el.tagName === 'IMG' && !el.hasAttribute('alt'),
                                unnamedControl: ['A','BUTTON'].includes(el.tagName) && !(el.textContent.trim() || el.getAttribute('aria-label') || el.querySelector('img[alt]:not([alt=""])'))
                            };
                        })
                    };
                });
                await a11yPage.setViewportSize({width,height:HEIGHT});
                const a11y = await a11yPage.evaluate(async () => {
                    const report=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});
                    return report.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,
                        nodes:v.nodes.slice(0,8).map(n=>({
                            target:n.target.slice(0,8).map(selector=>(typeof selector==='string'?selector:JSON.stringify(selector)).slice(0,256)),
                            summary:n.failureSummary?.slice(0,600),
                        }))}));
                });
                if (Buffer.byteLength(JSON.stringify({ width, snapshot, a11y })) > MAX_METADATA)
                    throw new RendererError('evidence_limit', 507);
                const screenshot = await boundedScreenshot(page);
                evidence.push({width,snapshot,screenshot,a11y});
            }
            process.stdout.write(JSON.stringify({ evidence }));
            return;
        }
        const snapshot = await page.evaluate(() => {
            document.querySelectorAll('script, iframe, object, embed').forEach((el) => el.remove());
            return '<!doctype html>\n' + document.documentElement.outerHTML;
        });
        process.stdout.write(JSON.stringify({ html: snapshot }));
    } catch (error) {
        const category = error instanceof RendererError ? error.code :
            error.name === 'TimeoutError' ? 'browser_timeout' :
                (crashed || !browser || !browser.isConnected()) ? 'browser_crash' : 'worker_failure';
        if (evidence.length && ['browser_timeout','browser_crash'].includes(category))
            process.stdout.write(JSON.stringify({ evidence }));
        else process.stdout.write(JSON.stringify({ error: category }));
    } finally {
        if (browser) { await browser.close().catch(() => {}); }
    }
});
