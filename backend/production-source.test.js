'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { identity, stableJSON, productionSource } = require('./production-source');

test('manifest encoding is stable despite property order and retains viewport array order', () => {
    assert.equal(stableJSON({ viewport: [1440, 768, 375], content: { b: true, a: 2 } }),
        stableJSON({ content: { a: 2, b: true }, viewport: [1440, 768, 375] }));
    assert.notEqual(stableJSON({ viewport: [768, 1440, 375] }), stableJSON({ viewport: [1440, 768, 375] }));
    assert.notEqual(identity('https://example.org/', '#hero'), identity('https://example.org/', '#intro'));
});

test('responsive variants require exactly one captured ID per viewport before network acquisition', async () => {
    const valid = 'f'.repeat(24);
    await assert.rejects(productionSource('invalid URL', null, {1440: valid, 768: valid}), /Invalid production source section selection/);
    await assert.rejects(productionSource('invalid URL', valid, {1440: valid, 768: valid, 375: valid}),
        /Invalid production source section selection/);
});
