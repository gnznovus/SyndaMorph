'use strict';
const assert = require('node:assert/strict');
const test = require('node:test');
const { runCommunicationPoc } = require('../purr-poc');

const priorFetch = global.fetch;
const priorUrl = process.env.PURR_GATEWAY_URL;
const priorToken = process.env.PURR_CLIENT_TOKEN;
test.after(() => {
    global.fetch = priorFetch;
    if (priorUrl === undefined) delete process.env.PURR_GATEWAY_URL;
    else process.env.PURR_GATEWAY_URL = priorUrl;
    if (priorToken === undefined) delete process.env.PURR_CLIENT_TOKEN;
    else process.env.PURR_CLIENT_TOKEN = priorToken;
});
test('Purr adapter sends real authenticated session + four messages and returns no secrets', async () => {
    const token = 'synthetic-test-token-do-not-share';
    process.env.PURR_GATEWAY_URL = 'https://purr-gateway-production.up.railway.app';
    process.env.PURR_CLIENT_TOKEN = token;
    const seen = [];
    global.fetch = async (url, init) => {
        seen.push({ url, init });
        assert.equal(init.headers.Authorization, 'Bearer ' + token);
        assert.equal(init.redirect, 'error');
        const step = seen.length;
        if (step === 1) {
            assert.match(url, /\/v1\/sessions$/);
            assert.equal(init.headers['X-Purr-Session'], undefined);
            return { ok: true, json: async () => ({ session_id: 'session-test', session_token: 'session-secret' }) };
        }
        assert.match(url, /\/v1\/chat$/);
        assert.equal(init.headers['X-Purr-Session'], 'session-secret');
        assert.equal(JSON.parse(init.body).session_id, 'session-test');
        return { ok: true, json: async () => ({ message: 'ACK ' + step, status: 'completed' }) };
    };
    const response = await runCommunicationPoc('Landing page test');
    assert.equal(response.success, true);
    assert.equal(response.steps_completed, 4);
    assert.equal(seen.length, 5);
    assert.deepEqual(response.conversation.map(item => item.agent), ['Boss Agent', 'Designer Agent', 'Developer Agent', 'Boss Agent']);
    const serialized = JSON.stringify(response);
    assert.equal(serialized.includes(token), false);
    assert.equal(serialized.includes('session-secret'), false);
});
test('Purr adapter rejects missing credentials before making any external request', async () => {
    delete process.env.PURR_CLIENT_TOKEN;
    await assert.rejects(runCommunicationPoc('Test job'), /purr_not_configured/);
});
test('Purr adapter does not send token to an unapproved endpoint', async () => {
    process.env.PURR_CLIENT_TOKEN = 'synthetic-token';
    process.env.PURR_GATEWAY_URL = 'https://untrusted.example';
    await assert.rejects(runCommunicationPoc('Test job'), /purr_not_configured/);
});
