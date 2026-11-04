'use strict';

const { chromium } = require('playwright');

const input = [];
process.stdin.on('data', (chunk) => input.push(chunk));
process.stdin.on('end', async () => {
    let browser;
    try {
        const { html } = JSON.parse(Buffer.concat(input).toString('utf8'));
        browser = await chromium.launch({
            headless: true,
            args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
        });
        const context = await browser.newContext({
            javaScriptEnabled: true,
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
