const assert = require('node:assert/strict');
const { chromium } = require('../backend/node_modules/playwright');

const origin = process.env.SM_SCOPE_ORIGIN || 'http://127.0.0.1:8085';
const target = origin + '/wp-admin/tools.php?page=syndamorph-transport';
const field = 'syndamorph_check_transport';

async function login(page, user, password) {
    await page.goto(origin + '/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(password);
    await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);
}

async function main() {
    const channel = process.env.SM_BROWSER_CHANNEL;
    const browser = await chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
    try {
        const page = await browser.newPage();
        const anonymous = await page.request.get(target, { maxRedirects: 0 });
        assert.equal(anonymous.status(), 302);
        assert.match(anonymous.headers().location, /wp-login\.php/);

        const subscriber = await browser.newPage();
        await login(subscriber, 'transport_subscriber', 'subscriber-local-test-password');
        assert.equal((await subscriber.request.get(target)).status(), 403);
        assert.equal((await subscriber.request.post(target, { form: { [field]: '1' } })).status(), 403);
        await subscriber.close();

        await login(page, 'ciadmin', 'ci-only-test-password');
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
        assert.deepEqual(statuses, ['Verified', 'Verified', 'Verified']);
        assert.doesNotMatch(await page.content(), /Private renderer check/);
        console.log('PASS: administrator transport check verifies Capture, framed Chromium inspection and Gateway. Anonymous, subscriber and invalid-nonce requests are denied.');
    } finally {
        await browser.close();
    }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
