'use strict';

const assert=require('node:assert/strict');
const test=require('node:test');
const { createHash }=require('node:crypto');
const fs=require('node:fs/promises');
const os=require('node:os');
const path=require('node:path');
const { chromium }=require('playwright');
const { captureReference, prepareReferencePage, storeReferences,readReference }=require('./production-reference');

const url='https://reference-fixture.invalid/index.html';
const source=`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<style>body{margin:0;font:16px Arial}main{padding:28px;background:linear-gradient(#142837,#28445c);color:white}
h1{font-size:clamp(1.5rem,3.5vw,4rem)}p{max-width:36rem}</style>
<main></main><script>document.querySelector('main').innerHTML=innerWidth>=1024
? '<h1>Full hospitality reference</h1><p>A stable layout from the source runtime.</p>'
: '<h1>Compact hospitality reference</h1><p>A measured compact source tree.</p>';</script></html>`;
const sha=content=>createHash('sha256').update(content).digest('hex');

test('archive admission rejects absent viewport evidence',async()=>{
    await assert.rejects(storeReferences([{ facts:{ width:1440,verified:true },url }]),
        error=>error.code==='sm_reference_unverified');
});

test('normal capture can verify the archived runtime DOM at exact widths', {
    skip:process.env.SYNDAMORPH_TEST_REFERENCE!=='1', timeout:90000,
},async()=>{
    const browser=await chromium.launch();
    const directory=await fs.mkdtemp(path.join(os.tmpdir(),'sm-reference-test-'));
    const old=process.env.SYNDAMORPH_REFERENCE_DIR;
    const oldEnabled=process.env.SYNDAMORPH_REFERENCE_ARCHIVE;
    process.env.SYNDAMORPH_REFERENCE_DIR=directory;
    process.env.SYNDAMORPH_REFERENCE_ARCHIVE='1';
    try {
        const views=[];
        for(const width of [1440,768,375]){
            const context=await browser.newContext({ viewport:{width,height:900},reducedMotion:'reduce' });
            try {
                await context.route(url,route=>route.fulfill({ contentType:'text/html',body:source }));
                const page=await context.newPage();
                await prepareReferencePage(page);
                await page.goto(url);
                const result=await captureReference(page,width,{});
                assert.equal(result.facts.pixelDifferenceRatio,0);
                assert.match(result.originalHtml,width===1440?/<h1>Full hospitality reference<\/h1>/:/<h1>Compact hospitality reference<\/h1>/);
                assert.equal(result.facts.javascriptEnabled,false);
                assert.equal(result.facts.externalRequests,0);
                if(width===375){
                    await page.evaluate(()=>{ globalThis.singlefile={ getPageData:()=>({ content:'<p>Wrong frozen reference HTML</p>' }) }; });
                    await assert.rejects(captureReference(page,width,{}),error=>error.code==='sm_reference_unverified');
                }
                views.push(result);
            } finally { await context.close(); }
        }
        const responses=new Map([['GET '+url,Promise.resolve({ status:200,headers:{'content-type':'text/html'},
            body:Buffer.from(source) })]]);
        const receipt=await storeReferences(views,responses);
        assert.deepEqual(receipt.views.map(view=>view.width),[1440,768,375]);
        assert.notEqual(receipt.views[0].copySha256,receipt.views[2].copySha256);
        for(const view of receipt.views) {
            const file=await fs.readFile(path.join(directory,receipt.id,view.width+'.html'));
            assert.equal(sha(file),view.archiveSha256);
        }
        assert.deepEqual(await storeReferences(views,responses),receipt);
        const runtime=await readReference(receipt.id,375);
        assert.equal(runtime.runtime.get('GET '+url).body.toString(),source);
        await fs.writeFile(path.join(directory,receipt.id,'runtime-0.bin'),'Modified response');
        await assert.rejects(readReference(receipt.id,375),error=>error.code==='sm_reference_unverified');
    } finally {
        if(old===undefined)delete process.env.SYNDAMORPH_REFERENCE_DIR;
        else process.env.SYNDAMORPH_REFERENCE_DIR=old;
        if(oldEnabled===undefined)delete process.env.SYNDAMORPH_REFERENCE_ARCHIVE;
        else process.env.SYNDAMORPH_REFERENCE_ARCHIVE=oldEnabled;
        await browser.close();
        await fs.rm(directory,{ recursive:true,force:true });
    }
});
