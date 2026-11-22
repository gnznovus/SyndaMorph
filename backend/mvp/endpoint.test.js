'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { createAnalyzeHandler } = require('./endpoint');

const section = { id: 'section-1', label: 'Hero', html: '<section><h1>Hero</h1></section>' };
const capture = async () => ({ url: 'https://www.syndacast.com/example', sections: [section] });
const fake = () => ({ analyzeSource: async () => ({ plan: { schemaVersion: 1 }, model: 'mock', usage: {} }) });
test('disabled analysis never captures or calls Claude', async () => {
    const previous = process.env.SYNDAMORPH_MVP_AI_ENABLED;
    delete process.env.SYNDAMORPH_MVP_AI_ENABLED;
    let called = 0;
    try {
        const analyze = createAnalyzeHandler({ capture: async () => { called++; return capture(); }, makeProvider: fake });
        await assert.rejects(() => analyze({ url: 'https://www.syndacast.com/', sectionId: 'section-1' }), /disabled/);
        assert.equal(called, 0);
    } finally { if (previous === undefined) delete process.env.SYNDAMORPH_MVP_AI_ENABLED; else process.env.SYNDAMORPH_MVP_AI_ENABLED = previous; }
});
test('enabled analysis chooses requested captured section', async () => {
    const previous = process.env.SYNDAMORPH_MVP_AI_ENABLED;
    process.env.SYNDAMORPH_MVP_AI_ENABLED = '1';
    try {
        const analyze = createAnalyzeHandler({ capture, makeProvider: fake });
        const result = await analyze({ url: 'https://www.syndacast.com/', sectionId: 'section-1' });
        assert.equal(result.source.sectionId, 'section-1');
        assert.equal(result.model, 'mock');
        await assert.rejects(() => analyze({ url: 'https://www.syndacast.com/', sectionId: 'section-2' }), /not available/);
    } finally { if (previous === undefined) delete process.env.SYNDAMORPH_MVP_AI_ENABLED; else process.env.SYNDAMORPH_MVP_AI_ENABLED = previous; }
});
