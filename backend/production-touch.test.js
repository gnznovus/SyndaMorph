'use strict';

const assert=require('node:assert/strict');
const test=require('node:test');
const { createHash }=require('node:crypto');
const { chromium }=require('playwright');
const { inspectTouch,validateTouchActions,inspectDisclosure,disclosureRef,inspectImageStrip,inspectLinkedImage,inspectRevealCards,inspectRooms,inspectRelocatedCopy }=require('./production-touch');
const { recordedPng }=require('./production-reference');
const { settleEntrancePaint,captureResponsiveViewport,captureOwnedRegion,captureSettledPair,captureCompactGuarantee }=require('./responsive-capture');

test('Touch actions are limited to server target identities and bounded gestures',()=>{
    assert.throws(()=>validateTouchActions([{type:'click',target:'#submit'}]));
    assert.throws(()=>validateTouchActions([{type:'resize',width:1441}]));
    assert.throws(()=>validateTouchActions([{type:'scroll',dy:950}]));
    assert.throws(()=>validateTouchActions([{type:'scroll-x',target:'a'.repeat(24),dx:901}]));
    assert.throws(()=>validateTouchActions(Array(6).fill({type:'scroll',dy:20})));
    assert.deepEqual(validateTouchActions([{type:'drag',target:'a'.repeat(24),dx:12}]),
        [{type:'drag',target:'a'.repeat(24),dx:12}]);
});

test('fresh discovery waits for the visible hero entrance and leaves infinite animation alone',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage();
        await page.setContent('<h1 id="title">Visible hero title</h1><p id="ticker">Unrelated animation</p>');
        await page.evaluate(()=>{
            document.querySelector('#ticker').animate([{opacity:.5},{opacity:1}],{duration:500,iterations:Infinity});
            document.querySelector('#title').animate([
                {opacity:0,transform:'translateY(12px)'},{opacity:1,transform:'none'},
            ],{duration:450,fill:'both'});
        });
        await settleEntrancePaint(page);
        const paint=await page.evaluate(()=>{
            const style=getComputedStyle(document.querySelector('#title'));
            return [style.opacity,style.transform,document.querySelector('#ticker').getAnimations()[0].playState,scrollY];
        });
        assert.deepEqual(paint,['1','matrix(1, 0, 0, 1, 0, 0)','running',0]);
    }finally{await browser.close();}
});

test('compact guarantee replay requires desktop absence, the pinned crop and decorative badges',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:375,height:900}});
        const hash=value=>createHash('sha256').update(value).digest('hex');
        const width=375,sourceRef='div[id="guarantee"]';
        const rows=['First','Second','Third'].map(word=>'<div><span><svg width="20" height="20">'+
            '<rect width="10" height="10"/></svg></span><div><span>'+word+'</span><span>Terms</span></div></div>').join('');
        await page.setContent('<style>body{margin:0;height:1200px}#guarantee{height:204px}'+
            '@media(min-width:1024px){#guarantee{display:none}}</style><div id="guarantee">'+rows+'</div>');
        const sections=[{kind:'content',geometryAvailable:true,sourceRef,bounds:{x:0,y:0,width,height:204}}];
        const diagnostics={contentReady:true};
        const original=await captureResponsiveViewport(page,sections,width,diagnostics);
        const sourceCopy=async()=>hash((await page.locator('body').innerText()).replace(/\s+/gu,' ').trim());
        const request={width,sourceRef,copySha256:await sourceCopy(),
            pinnedSha256:hash(Buffer.from(original.sections[0].screenshot,'base64'))};
        const run=(input,denied=[])=>captureCompactGuarantee(page,sections,diagnostics,input,()=>denied);
        assert.equal((await run({...request,pinnedSha256:'0'.repeat(64)})).reason,'pinned_crop_changed');
        assert.equal((await run(request,['blocked'])).reason,'source_changed_during_replay');
        const accepted=await run(request);
        assert.equal(accepted.status,'observed',accepted.reason);
        assert.deepEqual(accepted.copy,['First','Terms','Second','Terms','Third','Terms']);
        assert.ok(accepted.icons.every(icon=>icon.asset.sha256===hash(Buffer.from(icon.assetBase64,'base64'))));
        await page.locator('#guarantee').evaluate(root=>root.setAttribute('role','button'));
        await page.locator('#guarantee div').first().evaluate(row=>row.setAttribute('onclick',''));
        assert.equal((await run(request)).reason,'unsupported_guarantee_markup');
        await page.setViewportSize({width:1440,height:900});
        const desktop={...request,width:1440,copySha256:await sourceCopy(),pinnedSha256:null};
        assert.equal((await run(desktop)).present,false);
        await page.locator('#guarantee').evaluate(root=>root.style.display='block');
        assert.equal((await run({...desktop,copySha256:await sourceCopy()})).reason,'visible_at_desktop');
    }finally{await browser.close();}
});

test('a settled pair replays only the pinned local dismissal and reproduces the original crop',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const width=1440;
        const page=await browser.newPage({viewport:{width,height:900}});
        const source='<style>body{margin:0}#hero,#intro{height:180px}#lead{opacity:0;transform:translateY(12px);'+
            'transition:opacity 150ms,transform 150ms}#offer{position:fixed;inset:0;background:#444;z-index:2}</style>'+
            '<div id="hero"><h1 id="lead">Hotel</h1></div><div id="intro">Intro</div>'+
            '<div id="offer"><button type="button">Continue to website</button></div>'+
            '<script>document.querySelector("button").onclick=()=>{document.querySelector("#offer").remove();'+
            'const lead=document.querySelector("#lead");lead.style.opacity=1;lead.style.transform="none"};</script>';
        await page.setContent(source);
        const refs={ownerRef:'[id="hero"]',adjacentRef:'[id="intro"]',leadRef:'[id="lead"]',overlayRef:'[id="offer"]'};
        const sections=[{kind:'content',geometryAvailable:true,sourceRef:refs.ownerRef,bounds:{y:0,height:180}},
            {kind:'content',geometryAvailable:true,sourceRef:refs.adjacentRef,bounds:{y:180,height:180}},
            {kind:'overlay',geometryAvailable:true,sourceRef:refs.overlayRef,bounds:{y:0,height:900}}];
        const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
        const original=await captureResponsiveViewport(page,sections,width,{contentReady:true});
        const copySha256=sha((await page.locator('body').innerText()).replace(/\s+/gu,' ').trim());
        const dismiss=(await inspectTouch(page,{width,copySha256,actions:[]},()=>[])).initial.targets[0];
        const request={...refs,width,copySha256,dismissTargetId:dismiss.id,
            pinnedHeroSha256:sha(Buffer.from(original.sections[0].screenshot,'base64'))};
        assert.equal((await captureSettledPair(page,sections,{...request,pinnedHeroSha256:'0'.repeat(64)},()=>[])).reason,
            'pinned_hero_mismatch');
        assert.equal(await page.locator('#offer').isVisible(),true);
        assert.equal((await captureSettledPair(page,sections,{...request,dismissTargetId:'0'.repeat(24)},()=>[])).reason,
            'unverified_local_dismissal');
        const proof=await captureSettledPair(page,sections,request,()=>[]);
        assert.equal(proof.status,'observed',proof.reason);
        assert.equal(proof.hero.extent,180);
        assert.equal(proof.pair.extent,360);
        assert.notEqual(proof.hero.sha256,request.pinnedHeroSha256);
        assert.equal(proof.pair.sha256,sha(Buffer.from(proof.pair.image,'base64')));
        assert.equal(proof.blockedRequestCount,0);
        await page.setContent(source.replace('document.querySelector("#offer").remove()',
            'document.querySelector("#offer button").remove()'));
        const stillCovered=await captureSettledPair(page,sections,request,()=>[]);
        assert.equal(stillCovered.reason,'post_dismissal_paint_unverified');
        assert.equal(await page.locator('#offer').isVisible(),true);
    }finally{await browser.close();}
});

test('recorded responsive copy requires one exact placement per viewport',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const markup='<style>@media(max-width:1023px){#standalone{display:none}}'+
            '@media(min-width:1024px){#hero p{display:none}}</style>'+
            '<section id="hero"><h1>Hotel</h1><p>From sunrise to skyline</p></section>'+
            '<section id="standalone"><div>From sunrise<br>to skyline</div><p>Long description.</p></section>';
        const sha=value=>createHash('sha256').update(value).digest('hex');
        const input={sourceRef:'[id="standalone"]',destinationRef:'[id="hero"]',copy:'From sunrise to skyline'};
        const inspect=async(width,html,denied=[])=>{
            await page.setViewportSize({width,height:900});
            await page.setContent(html);
            const copySha256=sha(await page.locator('body').innerText()
                .then(value=>value.replace(/\s+/gu,' ').trim()));
            return inspectRelocatedCopy(page,{...input,width,copySha256},()=>denied);
        };
        const desktop=await inspect(1440,markup);
        assert.equal(desktop.placement,'source');
        assert.equal(desktop.originVisible,true);
        assert.equal(desktop.copySha256,sha(input.copy));
        for(const width of [768,375]){
            const compact=await inspect(width,markup);
            assert.equal(compact.placement,'destination');
            assert.equal(compact.originVisible,false);
            assert.equal(compact.tag,'p');
        }
        const duplicate=await inspect(375,markup+'<p>From sunrise to skyline</p>');
        assert.equal(duplicate.reason,'responsive_copy_not_relocated');
        const absent=await inspect(768,markup.replace('<p>From sunrise to skyline</p>',''));
        assert.equal(absent.reason,'responsive_copy_not_relocated');
        assert.equal((await inspect(375,markup,[{}])).reason,'source_changed_during_replay');
        const changed=await inspectRelocatedCopy(page,{...input,width:375,copySha256:sha('Changed')},()=>[]);
        assert.equal(changed.reason,'live_source_changed');
    }finally{await browser.close();}
});

test('owned region crops reproduce the composed image before hiding the adjacent overhang',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const width=1440;
        const page=await browser.newPage({viewport:{width,height:900}});
        await page.setContent('<style>body{margin:0}#owner{position:relative;height:120px}'+
            '#bar{position:absolute;left:180px;bottom:-30px;width:300px;height:50px;background:black}'+
            '#region{height:200px;background:#ff0}#other{position:absolute;top:130px;left:600px;width:60px;height:30px;background:red}</style>'+
            '<div id="owner"><div id="bar"></div></div><div id="region"><div id="other"></div>Editorial text</div>');
        const sourceRef='[id="region"]',destinationRef='[id="owner"]',overlapRef='[id="bar"]';
        const sections=[
            {kind:'content',sourceRef:destinationRef,geometryAvailable:true,bounds:{y:0,height:120},
                overhangs:[{sourceRef:overlapRef,position:'absolute',edges:{bottom:30}}]},
            {kind:'content',sourceRef,geometryAvailable:true,bounds:{y:120,height:200}},
        ];
        const diagnostics={contentReady:true};
        const capture=async()=> (await captureResponsiveViewport(page,sections,width,diagnostics)).sections[1].screenshot;
        const sha=encoded=>createHash('sha256').update(Buffer.from(encoded,'base64')).digest('hex');
        const original=await capture();
        const input={width,sourceRef,destinationRef,overlapRef,compositedSha256:sha(original)};
        const isolated=await captureOwnedRegion(page,sections,diagnostics,input);
        assert.equal(isolated.overlap,30);
        assert.equal(isolated.top,120);
        assert.equal(isolated.height,200);
        assert.equal(isolated.cropSha256,sha(isolated.screenshot));
        assert.notEqual(isolated.cropSha256,input.compositedSha256);
        const sample=await page.evaluate(async base64=>{
            const image=new Image();
            image.src='data:image/jpeg;base64,'+base64;
            await image.decode();
            const canvas=document.createElement('canvas');
            canvas.width=image.width;canvas.height=image.height;
            const context=canvas.getContext('2d');
            context.drawImage(image,0,0);
            return [200,620].map(x=>[...context.getImageData(x,20,1,1).data]);
        },isolated.screenshot);
        assert.ok(sample[0][0]>240&&sample[0][1]>240,'the removed overhang exposes the yellow background');
        assert.ok(sample[1][0]>240&&sample[1][1]<15,'the independent red rectangle remains visible');
        assert.equal(await capture(),original);
        assert.equal(await captureOwnedRegion(page,sections,diagnostics,{...input,compositedSha256:'0'.repeat(64)}),null);
        assert.equal(await captureOwnedRegion(page,sections,diagnostics,{...input,overlapRef:'[id="other"]'}),null);
        assert.equal(await capture(),original);
    }finally{await browser.close();}
});

const fixturePng=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a1ioAAAAASUVORK5CYII=','base64');
const fixtureImage='https://image-strip.invalid/brand.png';
test('recorded image lookup binds one PNG to its exact archive request',()=>{
    const response={status:200,headers:{'content-type':'image/png'},body:fixturePng};
    const responses=new Map([['GET '+fixtureImage,response]]);
    assert.deepEqual(recordedPng(responses,fixtureImage).pixels,[1,1]);
    assert.equal(recordedPng(responses,fixtureImage+'?different'),null);
    responses.set('GET '+fixtureImage,{...response,body:fixturePng.subarray(8)});
    assert.equal(recordedPng(responses,fixtureImage),null);
    responses.set('GET '+fixtureImage,{...response,headers:{'content-type':'image/svg+xml'}});
    assert.equal(recordedPng(responses,fixtureImage),null);
});

test('recorded image strip preserves alt text and proves actual compact scroll movement',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:375,height:900}});
        await page.route(fixtureImage,route=>route.fulfill({contentType:'image/png',body:fixturePng}));
        const image='<img src="'+fixtureImage+'" alt="Archived artwork" style="display:block;width:500px;height:120px">';
        const fixture=markup=>'<style>body{margin:0}</style><section id="artwork" style="overflow-x:auto">'+markup+'</section>';
        const input={width:375,sourceRef:'[id="artwork"]',copySha256:createHash('sha256').update('').digest('hex')};
        await page.setContent(fixture(image));
        const observed=await inspectImageStrip(page,input,()=>[]);
        assert.equal(observed.status,'observed');
        assert.equal(observed.initial.alt,'Archived artwork');
        assert.equal(observed.initial.maximum,125);
        assert.equal(observed.finalOffset,125);
        assert.equal(observed.keyboard,'unverified');
        await page.setContent(fixture('<a href="#destination">'+image+'</a>'));
        assert.equal((await inspectImageStrip(page,input,()=>[])).reason,'unsupported_image_strip');
        await page.setContent(fixture(image));
        assert.equal((await inspectImageStrip(page,input,()=>[{}])).reason,'source_changed_during_replay');
    }finally{await browser.close();}
});

test('recorded linked image keeps the exact link and each viewport’s optional artwork',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const source='https://image-strip.invalid/story.html';
        const destination='https://image-strip.invalid/history.html';
        const copySha256=createHash('sha256').update('Story Read the history').digest('hex');
        let destinationRequests=0;
        await page.route(destination,route=>{destinationRequests++;return route.abort();});
        await page.route(fixtureImage,route=>route.fulfill({contentType:'image/png',body:fixturePng}));
        await page.route(source,route=>route.fulfill({contentType:'text/html',body:'<!doctype html>'+
            '<style>@media(max-width:1023px){img{display:none}}</style><section id="story"><p>Story</p>'+
            '<img src="'+fixtureImage+'" alt="Recorded illustration"><a href="'+destination+'">Read the history</a></section>'}));
        const evidence=width=>inspectLinkedImage(page,{width,sourceRef:'[id="story"]',copySha256},()=>[]);
        await page.goto(source);
        const desktop=await evidence(1440);
        assert.equal(desktop.status,'observed');
        assert.deepEqual(desktop.link,{href:destination,text:'Read the history'});
        assert.deepEqual(desktop.image.pixels,[1,1]);
        assert.equal(desktop.destinationStatus,'declared');
        await page.setViewportSize({width:375,height:900});
        const compact=await evidence(375);
        assert.deepEqual(compact.link,desktop.link);
        assert.equal(compact.image,null);
        for(const href of ['https://outside.invalid/history.html',destination+'#section']){
            await page.locator('a').evaluate((link,url)=>link.href=url,href);
            assert.equal((await evidence(375)).reason,'unsupported_linked_image_markup');
        }
        assert.equal(destinationRequests,0);
    }finally{await browser.close();}
});

test('recorded pointer reveals bind each card and hold a link inside revealed copy',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        const cards=[1,2].map(id=>`<div class="card"><div class="cover"><span>Card ${id}</span>`+
            '<div class="panel"><p>Reveal content</p><span>Discover</span></div></div><div>Title</div><div>Detail</div></div>').join('');
        const markup='<!doctype html><style>section{margin:24px;display:flex;gap:20px}.card{cursor:pointer;width:180px}'+
            '.cover{position:relative;height:180px;background:#ccc}.panel{position:absolute;inset:0;opacity:0;background:#333;color:white}'+
            '.card:hover .panel{opacity:1}.compact{display:none}'+
            '@media(max-width:1023px){.card{display:none}.compact{display:block}}</style><section id="reveal">'+
            cards+'<p class="compact">Compact card copy</p></section>';
        const run=async(width,html)=>{
            await page.setViewportSize({width,height:900});
            await page.setContent(html);
            const copy=await page.locator('body').evaluate(body=>body.innerText.replace(/\s+/gu,' ').trim());
            return inspectRevealCards(page,{width,sourceRef:'[id="reveal"]',copySha256:createHash('sha256').update(copy).digest('hex')},()=>[]);
        };
        const desktop=await run(1440,markup);
        assert.equal(desktop.status,'observed',JSON.stringify(desktop));
        assert.deepEqual(desktop.cards.map(c=>c.opacity),[[0,1,1,0],[0,1,1,0]]);
        assert.ok(desktop.cards.every(c=>c.initialSha256!==c.revealedSha256&&c.destination==='absent'));
        const compact=await run(375,markup);
        assert.equal(compact.modeAtWidth,'static');
        assert.deepEqual(compact.cards,[]);
        const unsupported=await run(1440,markup.replace('<span>Discover</span>','<a href="/unverified">Discover</a>'));
        assert.equal(unsupported.reason,'unsupported_reveal_controls');
    }finally{await browser.close();}
});

test('compact Rooms source proof distinguishes inert labels from actual scrolling',{
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:375,height:900}});
        const chips=['Standard','Premium','One Bedroom Suite','Thonglor Suite'];
        const html='<!doctype html><style>body{margin:0}section>div:nth-child(2){display:flex;overflow:auto}'+
            'section>div:nth-child(2)>span{flex:0 0 125px;width:125px}</style><section id="rooms">'+
            '<div>Rooms</div><div>'+chips.map(label=>`<span>${label}</span>`).join('')+'</div>'+
            '<div><span>Book</span> <span>View room</span></div></section>';
        const run=async markup=>{
            await page.setContent(markup);
            const text=await page.locator('body').evaluate(body=>body.innerText.replace(/\s+/gu,' ').trim());
            return inspectRooms(page,{width:375,sourceRef:'[id="rooms"]',copySha256:createHash('sha256').update(text).digest('hex')},()=>[]);
        };
        const result=await run(html);
        assert.equal(result.status,'observed',JSON.stringify(result));
        assert.deepEqual(result.strip,{copy:chips,wheel:[0,125,0],keyboard:40});
        const link=await run(html.replace('<span>Book</span>','<a href="#unverified">Book</a>'));
        assert.equal(link.reason,'room_assets_or_destinations_unverified');
    }finally{await browser.close();}
});

test('Disclosure replay binds exact controls, ordered answers and exclusive open states', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:375,height:900}});
        const indicator='<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" '+
            'stroke-width="1.75" stroke-linecap="square"><path d="M6 9l6 6 6-6"></path></svg>';
        const fixture=(icon,script='')=>'<section id="faq">'+
            ['First answer','Second answer','Third answer'].map((answer,index)=>
                '<article'+(index===0?' class="active"':'')+'><button>Are these ordered?'+icon+
                '</button><p>'+answer+'</p></article>').join('')+
            '</section><style>article:not(.active) p{display:none}.active svg{transform:rotate(180deg)}</style>'+
            '<script>document.querySelectorAll("button").forEach(button=>button.onclick=()=>{'+
                'const target=button.parentElement,open=target.classList.contains("active");'+
                'document.querySelectorAll("article").forEach(row=>row.classList.remove("active"));'+
                'if(!open)target.classList.add("active");'+script+'});</script>';
        const replay=async(html,lastInput=null,denied=[])=>{
            await page.setContent(html);
            const copy=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
            const input={width:375,sourceRef:'[id="faq"]',copySha256:createHash('sha256').update(copy).digest('hex')};
            return inspectDisclosure(page,lastInput??input,()=>denied);
        };
        assert.equal(disclosureRef('[id="faq"]'),true);
        assert.equal(disclosureRef('body > button'),false);
        const result=await replay(fixture(indicator));
        assert.equal(result.status,'observed');
        assert.deepEqual(result.clicks.map(row=>row.open),[[1],[2],[0],[]]);
        assert.deepEqual(result.panels.map(row=>row.answer),['First answer','Second answer','Third answer']);
        assert.equal(new Set(result.panels.map(row=>row.targetId)).size,3);
        const invalid=await replay(fixture(indicator.replace('M6 9l6 6 6-6','M0 0h10')));
        assert.equal(invalid.reason,'unsupported_controls_or_artwork');
        const changed=await replay(fixture(indicator),{width:375,sourceRef:'[id="faq"]',copySha256:'0'.repeat(64)});
        assert.equal(changed.reason,'live_source_changed');
    }finally{await browser.close();}
});

test('anonymous horizontal scrollers require a capability and record actual local movement', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const page=await browser.newPage({viewport:{width:375,height:900}});
        await page.setContent('<section id="fixtures" style="height:250px">'+
            '<div style="width:270px;overflow-x:auto"><div style="width:780px;height:70px">Scrollable source cards</div></div>'+
            '<div style="width:270px;overflow-x:hidden"><div style="width:780px;height:70px">Clipped decoration</div></div></section>');
        const capture=await captureResponsiveViewport(page,[{sourceRef:'#fixtures',kind:'content',geometryAvailable:true,
            bounds:{y:0,height:250}}],375,{contentReady:true});
        assert.deepEqual(capture.sections[0].inventory.controls,[{type:'horizontal-scroll',name:''}]);
        const copy=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
        const args={width:375,copySha256:createHash('sha256').update(copy).digest('hex')};
        const initial=await inspectTouch(page,{...args,actions:[]},()=>[]);
        const scrollable=initial.initial.targets.filter(target=>target.permitsScrollX);
        assert.equal(scrollable.length,1);
        const result=await inspectTouch(page,{...args,actions:[{type:'scroll-x',target:scrollable[0].id,dx:240}]},()=>[]);
        const scenario=result.scenarios[0];
        assert.equal(scenario.status,'observed');
        assert.deepEqual(scenario.changes,['horizontal-scroll']);
        assert.equal(scenario.from.target.attributes.scrollLeft,0);
        assert.ok(scenario.to.target.attributes.scrollLeft>0);
    }finally{await browser.close();}
});

test('a supported strip binds ordered card copy to an exact off-screen Touch target', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try {
        const page=await browser.newPage({viewport:{width:375,height:900}});
        await page.setContent('<div style="height:1000px">'+ '<button>Other target</button>'.repeat(65)+'</div>'+
            '<section id="strip" style="display:flex;box-sizing:border-box;width:340px;overflow-x:auto;'+
            'scroll-snap-type:x mandatory;gap:12px;padding-inline-end:20px">'+
            ['First / Ελληνικά','', 'Third'].map(copy=>`<article style="height:180px;flex:0 0 80%;scroll-snap-align:start">${copy}</article>`).join('')+
            '</section>');
        const capture=await captureResponsiveViewport(page,[{sourceRef:'#strip',kind:'content',geometryAvailable:true,
            bounds:{y:1008,height:180}}],375,{contentReady:true});
        const [control]=capture.sections[0].inventory.controls;
        assert.equal(control.type,'horizontal-scroll');
        assert.deepEqual(control.strip.cardCopy,['First / Ελληνικά','','Third']);
        const digest=text=>createHash('sha256').update(text).digest('hex');
        assert.equal(control.strip.copySha256,digest(JSON.stringify(control.strip.cardCopy)));
        const copy=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
        const input={width:375,copySha256:digest(copy)};
        const regular=await inspectTouch(page,{...input,actions:[]},()=>[]);
        assert.equal(regular.initial.targets.some(target=>target.id===control.strip.targetId),false);
        const action={type:'scroll-x',target:control.strip.targetId,dx:360};
        const result=await inspectTouch(page,{...input,actions:[action]},()=>[]);
        assert.equal(result.scenarios[0].target.id,control.strip.targetId);
        assert.equal(result.scenarios[0].status,'observed');
        assert.deepEqual(result.scenarios[0].changes,['horizontal-scroll']);
    }finally{await browser.close();}
});

test('Touch records local changes while blocking read and mutation requests', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try{
        const context=await browser.newContext({viewport:{width:375,height:900},serviceWorkers:'block'});
        const denied=[];
        let sealed=false;
        const url='https://touch-fixture.invalid/';
        await context.route('**/*',route=>{
            if(!sealed && route.request().url()===url)return route.fulfill({contentType:'text/html',body:`
                <button aria-expanded="false">Show options</button><a href="https://unavailable.invalid/">Off-site</a>
                <form><button>Confirm place</button></form><p hidden>Extra option revealed</p>
                <script>document.querySelector('button').addEventListener('click',event=>{
                    event.target.setAttribute('aria-expanded','true');
                    document.querySelector('p').hidden=false;
                    fetch('/availability').catch(()=>{});fetch('/submit',{method:'POST'}).catch(()=>{});
                });</script>`});
            denied.push({method:route.request().method(),resourceType:route.request().resourceType()});
            return route.abort('blockedbyclient');
        });
        const page=await context.newPage();
        await page.goto(url);
        const copy=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
        const args={ width:375,copySha256:createHash('sha256').update(copy).digest('hex') };
        const arm=()=>{ sealed=true; return denied; };
        const inventory=await inspectTouch(page,{...args,actions:[]},arm);
        assert.equal(inventory.status,'observed');
        const byName=label=>inventory.initial.targets.find(target=>target.name===label).id;
        const result=await inspectTouch(page,{...args,actions:[
            {type:'click',target:byName('Off-site')},
            {type:'click',target:byName('Confirm place')},
            {type:'click',target:byName('Show options')},
            {type:'resize',width:768},
        ]},arm);
        assert.deepEqual(result.scenarios.map(row=>row.status),['inferred','unknown','observed','observed']);
        assert.deepEqual(denied.map(row=>row.method).sort(),['GET','POST']);
        const transition=result.scenarios[2];
        assert.equal(transition.remoteEffects,'unverified');
        assert.notEqual(transition.before.sha256,transition.after.sha256);
        assert.equal(transition.from.target.attributes['aria-expanded'],'false');
        assert.equal(transition.to.target.attributes['aria-expanded'],'true');
        assert.equal((await inspectTouch(page,{...args,width:768,actions:[]},arm)).reason,'live_source_changed');
        await context.close();
    }finally{await browser.close();}
});

test('Touch records hover, drag and scroll using discovered controls and bounded motion', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try {
        const page=await browser.newPage({ viewport:{ width:375,height:900 } });
        await page.setContent(`
            <button aria-expanded="false">Hover panel</button>
            <div role="slider" aria-label="Local stage" aria-valuenow="0" style="width:130px;height:24px;background:#555"></div>
            <div style="height:1700px"></div>
            <script>
                document.querySelector('button').onmouseenter=event=>event.target.setAttribute('aria-expanded','true');
                const slider=document.querySelector('[role=slider]');
                slider.onpointerup=()=>slider.setAttribute('aria-valuenow','1');
            </script>`);
        const text=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
        const input={ width:375,copySha256:createHash('sha256').update(text).digest('hex'),actions:[] };
        const initial=await inspectTouch(page,input,()=>[]);
        const id=label=>initial.initial.targets.find(target=>target.name===label).id;
        const result=await inspectTouch(page,{...input,actions:[
            {type:'hover',target:id('Hover panel')},{type:'drag',target:id('Local stage'),dx:20},{type:'scroll',dy:210},
        ]},()=>[]);
        assert.deepEqual(result.scenarios.map(row=>row.status),['observed','observed','observed']);
        assert.equal(result.scenarios[0].to.target.attributes['aria-expanded'],'true');
        assert.equal(result.scenarios[1].to.target.attributes['aria-valuenow'],'1');
        assert.equal(result.scenarios[2].to.scroll,210);
    }finally{await browser.close();}
});

test('Touch distinguishes locator scrolling from a native disclosure action', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1',timeout:30000,
},async()=>{
    const browser=await chromium.launch();
    try {
        const page=await browser.newPage({viewport:{width:375,height:900}});
        await page.setContent('<div style="height:1400px"></div><button>Decorative control</button>'+
            '<details><summary>Room details</summary><p>Two twin beds</p></details>');
        const copy=await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim());
        const args={width:375,copySha256:createHash('sha256').update(copy).digest('hex')};
        const initial=await inspectTouch(page,{...args,actions:[]},()=>[]);
        const id=name=>initial.initial.targets.find(target=>target.name===name).id;
        const result=await inspectTouch(page,{...args,actions:[
            {type:'click',target:id('Decorative control')},{type:'click',target:id('Room details')},
        ]},()=>[]);
        assert.notEqual(result.scenarios[0].from.scroll,result.scenarios[0].to.scroll);
        assert.equal(result.scenarios[0].status,'unknown');
        assert.equal(result.scenarios[0].reason,'state_unchanged');
        assert.equal(result.scenarios[1].status,'observed');
        assert.deepEqual(result.scenarios[1].changes,['visible-copy','target-state']);
        assert.equal(result.scenarios[1].from.target.attributes.open,false);
        assert.equal(result.scenarios[1].to.target.attributes.open,true);
    }finally{await browser.close();}
});
