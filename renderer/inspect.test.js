'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const url = process.env.SYNDA_RENDER_TEST_URL || 'http://127.0.0.1:3999';

async function request(route, html, viewports, options = {}) {
    const response = await fetch(url + route, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body:JSON.stringify({html,viewports,...options}),
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

test('section inspection hashes only the visible, decoded original image',async()=>{
    const png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=';
    const src='data:image/png;base64,'+png;
    const html='<style>img{width:40px;height:40px;filter:grayscale(1) contrast(1.15);mix-blend-mode:multiply}'+
        '@media(max-width:1023px){img{display:none}}</style><section><p>Original image</p>'+
        '<img data-sm-source-image="marker-is-not-a-hash" alt="Illustration" src="'+src+'"></section>';
    const {evidence}=await request('/inspect',html,[1440,1024,1023,375],{extent:'section'});
    const expected={alt:'Illustration',pixels:[1,1],filter:'grayscale(1) contrast(1.15)',blend:'multiply',
        sha256:createHash('sha256').update(Buffer.from(png,'base64')).digest('hex')};
    assert.deepEqual(evidence.map(v=>v.snapshot.literal.assets),[1,1,0,0]);
    assert.deepEqual(evidence.map(v=>v.snapshot.literal.originalImage),[expected,expected,undefined,undefined]);
    const unmarked=await request('/inspect',html.replace(' data-sm-source-image="marker-is-not-a-hash"',''),[1440],{extent:'section'});
    assert.equal(unmarked.evidence[0].snapshot.literal.assets,1);
    assert.equal(unmarked.evidence[0].snapshot.literal.originalImage,undefined);
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

test('scroll inspection distinguishes a native component from an arbitrary scrolling element',async()=>{
    const html='<style>.strip{display:flex;width:300px;overflow-x:auto}.strip>div{flex:0 0 250px}.strip div:first-child{border:1px solid #fff}</style>'+
        '<section><div class="wp-block-group sm-scroll-strip strip" data-sm-node="scroll-native"><div>First</div><div>Second</div></div>'+
        '<div class="strip" data-sm-node="scroll-unknown"><div>First</div><div>Second</div></div></section>';
    const {evidence}=await request('/inspect',html,[768,375]);
    for(const {snapshot} of evidence){
        const strips=snapshot.elements.filter(element=>element.localHorizontalScroll);
        assert.deepEqual(strips.map(element=>[element.node,element.scrollReference]),[
            ['scroll-native','scroll-native'],['scroll-unknown',null],
        ]);
        const {styles}=snapshot.elements.find(element=>element.normalizedText==='First');
        assert.deepEqual([styles.borderTopWidth,styles.borderTopStyle,styles.borderTopColor],['1px','solid','rgb(255, 255, 255)']);
    }
});

test('single-image strip verifies actual local wheel and keyboard scrolling',async()=>{
    const png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=';
    const html='<style>body{margin:0}.sm-source-image-strip:focus-visible{outline:2px solid black}'+
        'figure{margin:0}img{display:block;width:500px;height:120px}'+
        '@media(max-width:500px){.sm-source-image-strip{overflow-x:auto}}</style>'+
        '<div class="wp-block-group sm-source-image-strip" role="group" aria-label="Artwork" tabindex="0">'+
        '<figure class="wp-block-image"><img data-sm-source-image="fixture" alt="Archived artwork" src="data:image/png;base64,'+png+'"></figure></div>';
    const {evidence}=await request('/check-image-strip',html,[768,375]);
    assert.equal(evidence[0].proof.range,0);
    const active=evidence[1].proof;
    assert.equal(active.sha256,createHash('sha256').update(Buffer.from(png,'base64')).digest('hex'));
    assert.equal(active.alt,'Archived artwork');
    assert.equal(active.range,125);
    assert.equal(active.wheelOffset,125);
    assert.equal(active.returnOffset,0);
    assert.ok(active.keyboardOffset>0&&active.focus&&active.insidePage);
});

test('existing DOM render endpoint remains available',async()=>{
    const result=await request('/render','<main><p>Original mode</p></main>');
    assert.match(result.html,/<p>Original mode<\/p>/);
});

test('compact Rooms proof measures wheel reversal and keyboard movement and holds destination links',async()=>{
    const labels=['Standard','Premium','One Bedroom Suite','Thonglor Suite'];
    const html='<style>body{margin:0}.sm-rooms-strip{display:flex;overflow-x:auto}'+
        '.sm-rooms-chip{flex:0 0 125px;margin:0}.sm-rooms-strip:focus-visible{outline:2px solid black}</style>'+
        '<section class="sm-rooms-layout"><div class="sm-rooms-strip" tabindex="0">'+
        labels.map(label=>`<p class="sm-rooms-chip">${label}</p>`).join('')+'</div></section>';
    const {evidence}=await request('/check-rooms',html,[768,375],{runtime:'/* Static compact layout. */'});
    assert.deepEqual(evidence[0].proof,{copy:labels,wheel:[0,0,0],keyboard:null});
    assert.deepEqual(evidence[1].proof,{copy:labels,wheel:[0,125,0],keyboard:40});
    const invented=await request('/check-rooms',html.replace('>Premium<','><a href="#invented">Premium</a><'),[375],
        {runtime:'/* Static compact layout. */'});
    assert.equal(invented.evidence[0].proof,null);
});

test('native reveal cards need visible keyboard focus and stay inactive at compact widths',async()=>{
    const cards=[0,1].map(id=>`<div tabindex="0" role="group" aria-label="Card ${id}" data-sm-reveal-card="${id}">`+
        '<div class="sm-reveal-cover"><p>Cover</p><div class="sm-reveal-panel"><p>Copy</p><p class="sm-reveal-label">Discover</p></div></div>'+
        '<p>Title</p><p>Detail</p></div>').join('');
    const markup='<style>.sm-reveal-layout{display:flex;gap:16px;margin:20px}[data-sm-reveal-card]{width:180px}'+
        '.sm-reveal-cover{position:relative;height:180px}.sm-reveal-panel{position:absolute;inset:0;opacity:0;background:#333;color:white}'+
        '[data-sm-reveal-card]:is(:hover,:focus-visible) .sm-reveal-panel{opacity:1}'+
        '[data-sm-reveal-card]:focus-visible{outline:2px solid black}'+
        '@media(max-width:1023px){[data-sm-reveal-card]{display:none}}</style><section class="sm-reveal-layout">'+cards+'</section>';
    const {evidence}=await request('/check-reveal-cards',markup,[1440,1023]);
    assert.deepEqual(evidence[0].proof.keyboard,['0','1']);
    assert.equal(evidence[0].proof.closed,true);
    assert.deepEqual(evidence[0].proof.cards.map(c=>c.opacity),[[0,1,1,0],[0,1,1,0]]);
    assert.deepEqual(evidence[1].proof,{insidePage:true,cards:[],keyboard:[]});
    const missingFocus=await request('/check-reveal-cards',markup.replace('outline:2px solid black','outline:none'),[1440]);
    assert.equal(missingFocus.evidence[0].proof,null);
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
