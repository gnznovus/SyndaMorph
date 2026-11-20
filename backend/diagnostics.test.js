'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const { inspectDocument } = require('./diagnostics');

test('diagnostics report layoutless wrappers, headings and stylesheets without dumping content', () => {
    const dom = new JSDOM(`<!doctype html><html><head><title>Test Capture</title><style>h1{color:red}</style></head>
        <body><x-dc><main><section><h1>Hotel Indigo Bangkok Thonglor</h1><p>Visible text</p></section>
        <section><h2>Our Story</h2><p>Neighborhood content</p></section></main></x-dc></body></html>`,
        { url: 'https://www.syndacast.com/2026/BKKGOPrototype1/index.html' });
    const oldDocument = global.document;
    const oldComputed = global.getComputedStyle;
    const original = dom.window.Element.prototype.getBoundingClientRect;
    global.document = dom.window.document;
    global.getComputedStyle = dom.window.getComputedStyle.bind(dom.window);
    dom.window.Element.prototype.getBoundingClientRect = () => ({
        x: 0, y: 0, width: 0, height: 0,
    });
    try {
        const report = inspectDocument();
        assert.equal(report.visibleElements, 0);
        assert.equal(report.semanticSections, 2);
        assert.deepEqual(report.headings.map((h) => h.text), [
            'Hotel Indigo Bangkok Thonglor', 'Our Story',
        ]);
        assert.ok(report.customElements.includes('x-dc'));
        assert.equal(report.body.children, 1);
        assert.ok(report.totalElements > 0);
        assert.ok(report.stylesheets.length >= 1);
        assert.equal(JSON.stringify(report).includes('Neighborhood content'), false);
    } finally {
        dom.window.Element.prototype.getBoundingClientRect = original;
        global.document = oldDocument;
        global.getComputedStyle = oldComputed;
        dom.window.close();
    }
});
