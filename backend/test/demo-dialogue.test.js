'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { buildConversation, POOLS, FLOW } = require('../../plugin/assets/demo-dialogue');

test('all three agents own three intent pools with multiple lines', () => {
    assert.deepEqual(Object.keys(POOLS).sort(), ['boss', 'designer', 'developer']);
    for (const intents of Object.values(POOLS)) {
        assert.equal(Object.keys(intents).length, 3);
        for (const lines of Object.values(intents)) {
            assert.ok(lines.length >= 2);
            assert.ok(lines.every(line => typeof line === 'string' && line.length));
        }
    }
});
test('role sequence uses authored intents and follows the discussion', () => {
    const messages = buildConversation({ kind: 'package', brief: 'Make Gutenberg sections' });
    assert.equal(messages.length, FLOW.length);
    assert.deepEqual(messages.map(item => item.agent), FLOW.map(item => item[0]));
    assert.deepEqual(messages.map(item => item.intent), FLOW.map(item => item[1]));
    assert.ok(messages[0].action.includes('ZIP package'));
    assert.ok(messages.every(item => !item.action.includes('{source}')));
});
test('all source kinds work and source types are validated', () => {
    for (const kind of ['html', 'url', 'package']) {
        assert.equal(buildConversation({ kind }).length, 9);
    }
    assert.throws(() => buildConversation({ kind: 'invalid' }), /Unsupported/);
});
test('repeatable demo variants never request an AI provider', () => {
    const first = buildConversation({ kind: 'html', brief: 'Sample' });
    const second = buildConversation({ kind: 'html', brief: 'Sample' });
    assert.deepEqual(first, second);
    assert.notEqual(buildConversation({ kind: 'html', brief: 'Longer brief' })[0].action, first[0].action);
});
