'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const url = process.env.SYNDA_RENDER_TEST_URL || 'http://127.0.0.1:3999';

async function request(route, html, viewports) {
    const response = await fetch(url + route, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body:JSON.stringify({html,viewports}),
        signal:AbortSignal.timeout(40000),
    });
    const payload=await response.json();
    assert.equal(response.status,200,JSON.stringify(payload).slice(0,300));
    return payload;
}

test('actual Chromium inspection provides independent responsive screenshots and computed geometry', async () => {
    const html='<section style="background:#17384c;padding:24px"><h2 data-sm-node="heading" style="color:#fff">Inspect\n me</h2><a href="#more">Learn more</a><img src="about:blank"></section>';
    const result=await request('/inspect',html,[1440,768,375]);
    assert.deepEqual(result.evidence.map(e=>e.width),[1440,768,375]);
    for (const e of result.evidence) {
        assert.equal(e.snapshot.overflow,false);
        assert.ok(e.screenshot.length>100);
        assert.equal(Buffer.from(e.screenshot,'base64').subarray(0,3).toString('hex'),'ffd8ff');
        const heading=e.snapshot.elements.find(el=>el.tag==='H2');
        assert.equal(heading.styles.color,'rgb(255, 255, 255)');
        assert.equal(heading.node,'heading');
        assert.equal(heading.normalizedText,'Inspect me');
        assert.equal(heading.parent,0);
        assert.ok(heading.textRect[2] < heading.rect[2]);
        assert.ok(e.snapshot.elements.some(el=>el.missingAlt===true));
        assert.ok(Array.isArray(e.a11y));
        assert.ok(e.a11y.some(v=>v.id==='image-alt'));
    }
    const compared=await fetch(url+'/compare',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reference:result.evidence,native:result.evidence}),signal:AbortSignal.timeout(10000)});
    assert.equal(compared.status,200);
    const metrics=(await compared.json()).comparison;
    assert.deepEqual(metrics.map(x=>x.width),[1440,768,375]);
    assert.ok(metrics.every(x=>x.pixelDifferenceRatio===0 && x.meanChannelDifference===0));
    const crop=await fetch(url+'/crop',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:[{screenshot:result.evidence[0].screenshot,rect:[24,24,180,40]}]})});
    assert.equal(crop.status,200);
    const images=(await crop.json()).images;
    assert.equal(images.length,1);
    assert.equal(Buffer.from(images[0].data,'base64').subarray(0,3).toString('hex'),'ffd8ff');
    assert.ok(images[0].data.length<result.evidence[0].screenshot.length);
});

test('literal copy retains long inline-formatted text independently of rendered casing',async()=>{
    const intro='Approved hotel introduction. '.repeat(12)+'The complete closing sentence.';
    const html='<section><h2 style="text-transform:uppercase">Welcome</h2><p>'+intro.replace('closing','<em>closing</em>')+'</p><p hidden>Hidden copy</p></section>';
    const result=await request('/inspect',html,[375,768,1440]);
    for(const {snapshot} of result.evidence) {
        assert.equal(snapshot.copy,'Welcome '+intro);
        assert.ok(snapshot.copy.length>250);
        assert.equal(snapshot.elements.find(el=>el.tag==='H2').styles.textTransform,'uppercase');
    }
});

test('inspection shares a fragment origin and measures effective unsupported styles',async()=>{
    const html='<style>section>div{max-width:44rem;margin:auto}h2{font:700 60px system-ui;text-wrap:balance}a{display:inline-block}@media(max-width:480px){a{width:100%}}</style><section><div><h2>A calm, considered place to stay</h2><a href="#rooms">See rooms</a></div></section>';
    const {evidence}=await request('/inspect',html,[375,768,1440]);
    const byWidth=Object.fromEntries(evidence.map(sample=>[sample.width,sample.snapshot.elements]));
    for(const elements of Object.values(byWidth)) {
        assert.equal(elements.find(el=>el.tag==='SECTION').rect[0],0);
        assert.equal(elements.find(el=>el.tag==='SECTION').rect[1],0);
    }
    assert.equal(byWidth[1440].find(el=>el.tag==='H2').wrapChangesGeometry,true);
    assert.equal(byWidth[375].find(el=>el.tag==='A').widthValue,'100%');
    assert.equal(byWidth[768].find(el=>el.tag==='A').widthValue,'auto');
});

test('existing DOM render endpoint remains available',async()=>{
    const result=await request('/render','<main><p>Original mode</p></main>');
    assert.match(result.html,/<p>Original mode<\/p>/);
});

test('browser facts expose painted markers, typography and live fragment semantics',async()=>{
    const html='<section class="hero" style="background:linear-gradient(#18283a,#294c77)">'+
        '<span id="gold-mark" style="display:block;width:2px;height:2px;background:#ffc900" aria-hidden="true"></span>'+
        '<h2 style="font-family:serif">Welcome</h2><a class="room-link" href="#rooms">Rooms</a>'+
        '<a href="#missing">Broken</a><p id="rooms">Available rooms</p></section>';
    const {evidence}=await request('/inspect',html,[375]);
    const [section,marker,title,link,broken]=evidence[0].snapshot.elements;
    assert.match(section.styles.backgroundImage,/linear-gradient/);
    assert.equal(marker.visible,true);
    assert.equal(marker.id,'gold-mark');
    assert.equal(marker.styles.backgroundColor,'rgb(255, 201, 0)');
    assert.equal(title.styles.fontFamily,'serif');
    assert.deepEqual(link.classes,['room-link']);
    assert.equal(link.href,'#rooms');
    assert.equal(link.fragmentTarget,true);
    assert.equal(link.keyboardReachable,true);
    assert.equal(broken.fragmentTarget,false);
});
