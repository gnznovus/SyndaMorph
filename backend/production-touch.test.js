'use strict';

const assert=require('node:assert/strict');
const test=require('node:test');
const { createHash }=require('node:crypto');
const { chromium }=require('playwright');
const { inspectTouch,validateTouchActions }=require('./production-touch');

test('Touch actions are limited to server target identities and bounded gestures',()=>{
    assert.throws(()=>validateTouchActions([{type:'click',target:'#submit'}]));
    assert.throws(()=>validateTouchActions([{type:'resize',width:1441}]));
    assert.throws(()=>validateTouchActions([{type:'scroll',dy:950}]));
    assert.throws(()=>validateTouchActions(Array(6).fill({type:'scroll',dy:20})));
    assert.deepEqual(validateTouchActions([{type:'drag',target:'a'.repeat(24),dx:12}]),
        [{type:'drag',target:'a'.repeat(24),dx:12}]);
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
