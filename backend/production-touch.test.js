'use strict';

const assert=require('node:assert/strict');
const test=require('node:test');
const { createHash }=require('node:crypto');
const { chromium }=require('playwright');
const { inspectTouch,validateTouchActions,inspectDisclosure,disclosureRef,inspectImageStrip }=require('./production-touch');
const { recordedPng }=require('./production-reference');
const { captureResponsiveViewport }=require('./responsive-capture');

test('Touch actions are limited to server target identities and bounded gestures',()=>{
    assert.throws(()=>validateTouchActions([{type:'click',target:'#submit'}]));
    assert.throws(()=>validateTouchActions([{type:'resize',width:1441}]));
    assert.throws(()=>validateTouchActions([{type:'scroll',dy:950}]));
    assert.throws(()=>validateTouchActions([{type:'scroll-x',target:'a'.repeat(24),dx:901}]));
    assert.throws(()=>validateTouchActions(Array(6).fill({type:'scroll',dy:20})));
    assert.deepEqual(validateTouchActions([{type:'drag',target:'a'.repeat(24),dx:12}]),
        [{type:'drag',target:'a'.repeat(24),dx:12}]);
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
