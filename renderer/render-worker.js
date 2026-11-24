'use strict';

const { chromium } = require('playwright');
const axe = require('axe-core');

const input = [];
process.stdin.on('data', (chunk) => input.push(chunk));
process.stdin.on('end', async () => {
    let browser;
    try {
        const { html, inspect } = JSON.parse(Buffer.concat(input).toString('utf8'));
        browser = await chromium.launch({
            headless: true,
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
        page.on('popup', (popup) => popup.close().catch(() => {}));
        await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 5000 });
        await page.waitForTimeout(600);
        if (inspect) {
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
            await a11yPage.setContent(safeMarkup, {waitUntil:'domcontentloaded',timeout:5000});
            await a11yPage.evaluate(axe.source);
            const evidence = [];
            for (const width of [1440, 768, 390]) {
                await page.setViewportSize({ width, height: 900 });
                const snapshot = await page.evaluate(() => {
                    const elements = [...document.body.querySelectorAll('*')].filter(el => !['STYLE','SCRIPT'].includes(el.tagName));
                    return {
                        overflow: document.documentElement.scrollWidth > innerWidth,
                        height: document.documentElement.scrollHeight,
                        elements: elements.slice(0, 200).map(el => {
                            const r = el.getBoundingClientRect(), s = getComputedStyle(el);
                            return { tag: el.tagName, id: el.id, text: el.children.length ? '' : el.textContent.slice(0, 250),
                                rect: [r.x,r.y,r.width,r.height].map(Math.round),
                                styles: Object.fromEntries(['color','backgroundColor','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','padding','margin','gap','display','textAlign','borderRadius'].map(k => [k,s[k]])),
                                missingAlt: el.tagName === 'IMG' && !el.hasAttribute('alt'),
                                unnamedControl: ['A','BUTTON'].includes(el.tagName) && !(el.textContent.trim() || el.getAttribute('aria-label') || el.querySelector('img[alt]:not([alt=""])'))
                            };
                        })
                    };
                });
                const screenshot = (await page.screenshot({type:'jpeg',quality:65,timeout:3000})).toString('base64');
                await a11yPage.setViewportSize({width,height:900});
                const a11y = await a11yPage.evaluate(async () => {
                    const report=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});
                    return report.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,
                        nodes:v.nodes.slice(0,8).map(n=>({target:n.target,summary:n.failureSummary}))}));
                });
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
        process.stdout.write(JSON.stringify({ error: error.message }));
    } finally {
        if (browser) { await browser.close().catch(() => {}); }
    }
});
