const assert = require('node:assert/strict');
const { chromium } = require('../backend/node_modules/playwright');

const origin = process.env.SM_SCOPE_ORIGIN || 'http://127.0.0.1:8085';
const target = origin + '/wp-admin/tools.php?page=syndamorph-transport';
const field = 'syndamorph_check_transport';

async function main() {
    const channel = process.env.SM_BROWSER_CHANNEL;
    const browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
    try {
        const page = await browser.newPage();
        const anonymous = await page.request.get(target, { maxRedirects: 0 });
        assert.equal(anonymous.status(), 302);
        assert.match(anonymous.headers().location, /wp-login\.php/);

        await page.goto(origin + '/wp-login.php');
        await page.locator('#user_login').fill('ciadmin');
        await page.locator('#user_pass').fill('ci-only-test-password');
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);
        for (const form of [{ [field]: '1' }, { [field]: '1', _wpnonce: 'invalid' }]) {
            const denied = await page.request.post(target, { form });
            assert.equal(denied.status(), 403, 'A missing or invalid nonce must be rejected before admin output');
        }
        const settings = await page.goto(target);
        assert.equal(settings.status(), 200);
        assert.equal(await page.locator('dl dd').count(), 0, 'A GET must not run the probes');
        const submitted = page.waitForResponse(response => response.url() === target && response.request().method() === 'POST');
        await page.getByRole('button', { name: 'Run private service checks' }).click();
        assert.equal((await submitted).status(), 200);
        await page.locator('dl dd').last().waitFor();
        const services = await page.locator('dl dt').allTextContents();
        const statuses = await page.locator('dl dd').allTextContents();
        assert.deepEqual(services, ['Capture', 'Renderer', 'Gateway']);
        assert.equal(statuses[services.indexOf('Renderer')], 'Verified');
        assert.doesNotMatch(await page.content(), /Private renderer check/);
        console.log('PASS: administrator transport page requires a valid nonce and verifies framed Chromium evidence.');
    } finally {
        await browser.close();
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
