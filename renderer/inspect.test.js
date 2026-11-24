'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const url = process.env.SYNDA_RENDER_TEST_URL || 'http://127.0.0.1:3999';

async function request(route, html) {
    const response = await fetch(url + route, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body:JSON.stringify({html}),
        signal:AbortSignal.timeout(40000),
    });
    assert.equal(response.status,200);
    return response.json();
}

test('actual Chromium inspection provides independent responsive screenshots and computed geometry', async () => {
    const html='<section style="background:#17384c;padding:24px"><h2 style="color:#fff">Inspect me</h2><a href="#more">Learn more</a><img src="about:blank"></section>';
    const result=await request('/inspect',html);
    assert.deepEqual(result.evidence.map(e=>e.width),[1440,768,390]);
    for (const e of result.evidence) {
        assert.equal(e.snapshot.overflow,false);
        assert.ok(e.screenshot.length>100);
        assert.equal(Buffer.from(e.screenshot,'base64').subarray(0,3).toString('hex'),'ffd8ff');
        assert.ok(e.snapshot.elements.some(el=>el.tag==='H2' && el.styles.color==='rgb(255, 255, 255)'));
        assert.ok(e.snapshot.elements.some(el=>el.missingAlt===true));
    }
});

test('existing DOM render endpoint remains available',async()=>{
    const result=await request('/render','<main><p>Original mode</p></main>');
    assert.match(result.html,/<p>Original mode<\/p>/);
});
