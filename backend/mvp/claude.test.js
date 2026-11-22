'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { makeClaudeProvider, safeEvidence } = require('./claude');

const section = {
    id: 'section-1', label: 'Hotel Hero', kind: 'content', sourceRef: 'main section',
    html: '<section><h1>Welcome</h1><form action="https://example.com/secret"><input type="password" value="supersecret" oninput="steal()"></form><script>secretToken()</script></section>',
};
const plan = {
    schemaVersion: 1, sectionId: 'section-1',
    components: [{ id: 'heading', kind: 'native', block: 'core/heading', contentRefs: ['main section h1'] }],
    viewports: {
        desktop: { layout: 'stack', order: ['heading'] },
        mobile: { layout: 'stack', order: ['heading'] },
    },
};
const success = (p = plan) => ({
    ok: true,
    json: async () => ({
        model: 'claude-test', stop_reason: 'end_turn',
        content: [{ type: 'text', text: JSON.stringify(p) }],
        usage: { input_tokens: 22, output_tokens: 11 },
    }),
});
test('capture minimizer excludes attribute values and scripts', () => {
    const data = JSON.stringify(safeEvidence(section));
    for (const secret of ['supersecret', 'steal()', 'secretToken()', 'example.com/secret']) {
        assert.equal(data.includes(secret), false);
    }
    assert.deepEqual(safeEvidence(section).availableViewports, ['desktop']);
});
test('API key must be provided without exposing it in errors', () => {
    assert.throws(() => makeClaudeProvider({ apiKey: '' }), /not configured/);
});
test('Claude analysis uses structured output and validates section identity', async () => {
    let outbound;
    const provider = makeClaudeProvider({
        apiKey: 'test-key', model: 'claude-test',
        fetchImpl: async (url, options) => {
            outbound = { url, options };
            return success();
        },
    });
    const result = await provider.analyzeSource(section);
    assert.equal(result.plan.sectionId, 'section-1');
    assert.equal(result.usage.inputTokens, 22);
    assert.equal(outbound.url, 'https://api.anthropic.com/v1/messages');
    assert.equal(outbound.options.headers['x-api-key'], 'test-key');
    assert.equal(JSON.parse(outbound.options.body).output_config.format.type, 'json_schema');
    assert.equal(outbound.options.body.includes('supersecret'), false);
});
test('repair uses independent failures, not an acceptance claim', async () => {
    const provider = makeClaudeProvider({ apiKey: 'test-key', fetchImpl: async () => success() });
    assert.equal((await provider.repairCandidate({ plan, failures: ['Column width'] })).plan.sectionId, 'section-1');
});
test('reject truncated output, changed identity and upstream failure safely', async () => {
    const stopped = makeClaudeProvider({
        apiKey: 'test-key',
        fetchImpl: async () => ({ ok: true, json: async () => ({ ...await success().json(), stop_reason: 'max_tokens' }) }),
    });
    await assert.rejects(() => stopped.analyzeSource(section), /incomplete/);
    const wrongId = makeClaudeProvider({
        apiKey: 'test-key',
        fetchImpl: async () => success({ ...plan, sectionId: 'other' }),
    });
    await assert.rejects(() => wrongId.analyzeSource(section), /identity/);
    const error = makeClaudeProvider({
        apiKey: 'test-key',
        fetchImpl: async () => ({ ok: false, status: 401, text: async () => 'secret' }),
    });
    await assert.rejects(() => error.analyzeSource(section), /^Error: Claude API request failed \(HTTP 401\)\.$/);
});
