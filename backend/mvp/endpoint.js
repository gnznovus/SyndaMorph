'use strict';

const { makeClaudeProvider } = require('./claude');

function createAnalyzeHandler({ capture, makeProvider = makeClaudeProvider }) {
    if (typeof capture !== 'function') throw new TypeError('Capture function required.');
    return async function analyze(input) {
        if (!input || typeof input.url !== 'string' || input.url.length > 2048 ||
            !/^section-\d+$/.test(input.sectionId || '') ||
            !['auto', 'browser', 'static'].includes(input.mode || 'auto')) {
            throw new Error('Invalid MVP analysis request.');
        }
        if (process.env.SYNDAMORPH_MVP_AI_ENABLED !== '1') throw new Error('MVP AI analysis is disabled.');
        const captured = await capture(input.url, input.mode || 'auto');
        const section = captured.sections.find((item) => item.id === input.sectionId);
        if (!section || !section.html || section.error) throw new Error('Requested section is not available in the capture.');
        const { plan, usage, model } = await makeProvider().analyzeSource(section);
        return {
            source: { url: captured.url, sectionId: section.id, label: section.label },
            plan, model, usage,
            warnings: [
                'Desktop-only captured evidence; mobile plan remains provisional until measured at 390px.',
                'AI planning does not compile or publish Gutenberg blocks.',
            ],
        };
    };
}

module.exports = { createAnalyzeHandler };
