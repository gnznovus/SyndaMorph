'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const { extractSections } = require('./sections');

function withDocument(html, fn) {
    const dom = new JSDOM(html, { url: 'https://www.syndacast.com/2026/BKKGOPrototype1/index.html' });
    const previous = { document: global.document, getComputedStyle: global.getComputedStyle, TextEncoder: global.TextEncoder };
    global.document = dom.window.document;
    global.getComputedStyle = dom.window.getComputedStyle.bind(dom.window);
    const original = dom.window.Element.prototype.getBoundingClientRect;
    dom.window.Element.prototype.getBoundingClientRect = function () {
        if (this.tagName === 'X-DC' || this.tagName === 'HELMET') {
            return { x: 0, y: 0, left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0 };
        }
        const order = [...dom.window.document.querySelectorAll('section,header,footer')].indexOf(this);
        const top = order >= 0 ? order * 400 : 0;
        return { x: 0, y: top, left: 0, top, right: 1000, bottom: top + 360, width: 1000, height: 360 };
    };
    try { fn(dom.window.document); } finally {
        dom.window.Element.prototype.getBoundingClientRect = original;
        global.document = previous.document;
        global.getComputedStyle = previous.getComputedStyle;
        global.TextEncoder = previous.TextEncoder;
        dom.window.close();
    }
}

test('layoutless custom wrapper does not become the sole zero-height section', () => {
    withDocument(`<body><x-dc><helmet><title>Not content</title><link rel="stylesheet" href="/style.css"></helmet>
      <main><section><h1>Hero Hotel Indigo</h1><p>Discover the city at your doorstep.</p></section>
      <section><h2>Our Story</h2><p>Learn about the neighborhood.</p></section>
      <section><h2>Stay</h2><p>Explore rooms and suites.</p></section></main></x-dc></body>`, () => {
        const sections = extractSections();
        assert.equal(sections.length, 3);
        assert.deepEqual(sections.map((s) => s.label), ['Hero Hotel Indigo', 'Our Story', 'Stay']);
        assert.ok(sections.every((s) => s.bounds.height > 0));
        assert.ok(sections.every((s) => !/helmet|<link|<title/i.test(s.html)));
        assert.ok(sections.every((s) => s.html.length < 10000));
    });
});

test('document-only elements and active embedded content never leak into a selected section', () => {
    withDocument(`<body><main><section><h2>Hero</h2><p>Visible content is preserved.</p>
      <style>.hero{color:red}</style><script>window.evil=true</script>
      <link href="/style.css"><iframe src="https://example.org"></iframe>
      <img src="/images/hero.webp" onerror="alert(1)"></section>
      <section><h2>Offers</h2><p>Offer description for readers.</p></section></main></body>`, () => {
        const sections = extractSections();
        assert.equal(sections.length, 2);
        assert.doesNotMatch(sections[0].html, /<script|<style|<iframe|<link|onerror/i);
        assert.match(sections[0].html, /https:\/\/www.syndacast.com\/images\/hero.webp/);
    });
});

test('excessive section markup is reported rather than truncated', () => {
    withDocument('<main><section><h2>Large section</h2><p>Visible sentence of content.</p></section><section><h2>Second section</h2><p>More visible content.</p></section></main>', () => {
        const sections = extractSections({ maxHtml: 50 });
        assert.equal(sections[0].html, null);
        assert.match(sections[0].error, /exceeds capture limit/);
    });
});

test('structure-only fallback segments an all-zero-geometry custom element page', () => {
    withDocument(`<body><x-dc><helmet><link rel="stylesheet" href="/styles.css"></helmet>
      <div class="site"><main>
        <div class="hero"><h1>Hotel Indigo Bangkok Thonglor</h1><p>Welcome to our neighborhood.</p></div>
        <div class="story"><h2>Our Story</h2><p>Discover stories of Thonglor.</p></div>
        <div class="stay"><h2>Stay</h2><p>Explore our rooms and suites.</p></div>
      </main></div></x-dc></body>`, (doc) => {
        const original = doc.defaultView.Element.prototype.getBoundingClientRect;
        doc.defaultView.Element.prototype.getBoundingClientRect = () => ({
            x: 0, y: 0, left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0,
        });
        try {
            const sections = extractSections();
            assert.equal(sections.length, 3);
            assert.deepEqual(sections.map((s) => s.label), [
                'Hotel Indigo Bangkok Thonglor', 'Our Story', 'Stay',
            ]);
            assert.ok(sections.every((s) => s.geometryAvailable === false));
            assert.ok(sections.every((s) => s.bounds.height === null));
            assert.ok(sections.every((s) => s.html && !/<helmet|<link/i.test(s.html)));
            assert.match(sections[0].html, /Welcome to our neighborhood/);
            assert.doesNotMatch(sections[0].html, /Explore our rooms/);
        } finally { doc.defaultView.Element.prototype.getBoundingClientRect = original; }
    });
});
