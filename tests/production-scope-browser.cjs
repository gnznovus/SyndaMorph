'use strict';
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { chromium } = require('../backend/node_modules/playwright');
const { captureResponsiveViewport } = require('../backend/responsive-capture');

const origin = process.env.SM_SCOPE_ORIGIN || 'http://127.0.0.1:8085';
const login = process.env.SM_SCOPE_USER || 'ciadmin';
const password = process.env.SM_SCOPE_PASSWORD || 'ci-only-test-password';
const deck = readFileSync(join(__dirname, 'production-scope-wordpress.php'));

async function fixture(phase, job = '') {
    return new Promise((resolve, reject) => {
        const child = spawn('docker', ['compose','exec','-T','-e','SM_SCOPE_USER','-e','SM_SCOPE_PASSWORD',
            '-e','SM_SCOPE_TEST_PHASE','-e','SM_SCOPE_JOB','wordpress','php'], {
            cwd:join(__dirname,'..'), env:{...process.env, SM_SCOPE_USER:login, SM_SCOPE_PASSWORD:password,
                SM_SCOPE_TEST_PHASE:phase, SM_SCOPE_JOB:String(job)},
            stdio:['pipe','pipe','pipe'],
        });
        const chunks = [];
        child.stdout.on('data', data => chunks.push(data));
        child.stderr.on('data', data => process.stderr.write(data));
        child.on('error',reject);
        child.on('close', code => {
            const output = Buffer.concat(chunks).toString();
            if (code) return reject(new Error('Offline fixture failed: ' + output));
            try { resolve(JSON.parse(output.trim().split(/\r?\n/).at(-1))); }
            catch (error) { reject(new Error('Invalid fixture report: ' + output, {cause:error})); }
        });
        child.stdin.end(deck);
    });
}

async function verifyOverlayEvidence(browser) {
    const page = await browser.newPage({viewport:{width:768,height:900}});
    try {
        await page.setContent('<style>body{margin:0}section{height:450px;background:#f00}'+
            '#shade{position:absolute;inset:0;z-index:10;background:rgba(0,0,0,.55)}'+
            '#offer{position:absolute;inset:100px 120px auto;height:200px;background:white;z-index:11}</style>'+
            '<section id="content"><h2 style="margin:0">Content</h2></section><div id="shade"></div><div id="offer">Offer</div>');
        const sections = [{sourceRef:'#content',kind:'content',geometryAvailable:true,bounds:{y:0,height:450}},
            {sourceRef:'#offer',kind:'overlay',geometryAvailable:true,bounds:{y:100,height:200}}];
        const evidence = await captureResponsiveViewport(page,sections,768,{contentReady:true});
        const samples = await page.evaluate(async images => Promise.all(images.map(async data => {
            const img = new Image();
            img.src = 'data:image/jpeg;base64,' + data;
            await img.decode();
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img,0,0);
            return ctx.getImageData(6,90,1,1).data[0];
        })), [evidence.fold,evidence.sections[0].screenshot]);
        assert.ok(samples[0]<135 && samples[1]>245,'Default page evidence must keep its shade and section evidence must show the underlay.');
        assert.equal(evidence.sections[0].hiddenOverlayLayers,2);
        assert.equal(evidence.sections[1].hiddenOverlayLayers,0);
        assert.equal(await page.locator('#shade').evaluate(el=>getComputedStyle(el).opacity),'1');
    } finally { await page.close(); }
}

(async () => {
    const archive = await fixture('setup');
    const channel = process.env.SM_BROWSER_CHANNEL;
    const browser = await chromium.launch({headless:true,args:['--no-sandbox'],...(channel?{channel}:{})});
    try {
        await verifyOverlayEvidence(browser);
        const page = await browser.newPage();
        await page.goto(origin+'/wp-login.php');
        await page.locator('#user_login').fill(login);
        await page.locator('#user_pass').fill(password);
        await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
        const setup = origin+'/wp-admin/tools.php?page=syndamorph';
        await page.goto(setup);
        assert.deepEqual(await page.locator('#syndamorph-team-scope option').allTextContents(),['Section','Full Page']);
        await page.locator('#syndamorph-team-scope').selectOption('page');
        assert.equal(await page.locator('#syndamorph-team-output-type').inputValue(),'gutenberg');
        const run = (operation, ...args) => page.evaluate(async ({operation,args}) => {
            const [id,key] = args;
            const cfg = window.SyndaMorphTeam;
            const endpoint = cfg.scopeUrl + '/' + id;
            const request = async (target, payload) => {
                const response = await fetch(target, {method:payload?'POST':'GET',credentials:'same-origin',headers:{
                    'X-WP-Nonce':cfg.nonce,'Content-Type':'application/json'},body:payload?JSON.stringify(payload):undefined});
                const data = await response.json();
                if (!response.ok) throw Error(data.code + ': ' + data.message);
                return data;
            };
            const validate = window.SyndaMorphAIExport.validateNative;
            if(operation==='state') return request(endpoint);
            if(operation==='open') {
                await window.SyndaMorphScope.open(id);
                return document.querySelector('#syndamorph-team-scope-progress').innerText;
            }
            if(operation==='pattern') {
                const native = await request(endpoint + '?region=' + key);
                if(!native.eligible)throw Error('Expected full region source and Designer-reference QA');
                const blockCount = validate(native.content);
                const validation = await request(cfg.historyUrl + '/' + native.id + '/validate-native',
                    {valid:true,contentSha256:native.checksum,blockCount});
                return {validation,blockCount,state:await request(endpoint,{action:'save_pattern',region:key})};
            }
            if(operation==='assemble') {
                await request(endpoint,{action:'assemble'});
                const native = await request(endpoint + '?draft=1');
                const blockCount = validate(native.content,7200);
                return request(endpoint,{action:'verify_page',valid:true,checksum:native.checksum,blockCount});
            }
            throw Error('Unexpected browser fixture operation');
        },{operation,args});
        const [first,middle,last] = archive.keys;
        const selected = await run('pattern',archive.section, first);
        assert.equal(selected.state.status,'completed');
        assert.equal(selected.blockCount,3);
        assert.equal(selected.validation.status,'completed');
        assert.equal(Object.values(selected.state.regions).filter(region=>region.patternId).length,1);
        await run('pattern',archive.page,first);
        await run('pattern',archive.page,last);
        const partial = await run('assemble',archive.page);
        assert.equal(partial.status,'uncompleted');
        assert.equal(partial.page.editorVerified,true);
        assert.deepEqual(partial.page.placements.map(p=>p.id),[first,last]);
        assert.match(await run('open',archive.page),/Partial draft/);
        const recovered = await fixture('retry',archive.page);
        assert.equal(recovered.mockCalls,5);
        const middlePattern = await run('pattern',archive.page,middle);
        assert.equal(middlePattern.blockCount,6);
        const complete = await run('assemble',archive.page);
        assert.equal(complete.status,'completed',JSON.stringify(complete.page.qa));
        assert.equal(complete.page.editorVerified,true);
        assert.equal(complete.page.blockCount,12);
        assert.equal(complete.page.id,partial.page.id);
        assert.deepEqual(complete.page.placements.map(p=>p.id),archive.keys);
        assert.deepEqual(complete.page.placements.filter(p=>p.id!==middle).map(p=>p.patternId),partial.page.placements.map(p=>p.patternId));
        assert.equal(complete.regions[middle].attempts.length,2);
        assert.equal(complete.regions[first].attempts.length,1);
        assert.equal(complete.regions[last].attempts.length,1);

        for (const [width,label] of [[1440,'Centre desktop'],[768,'Centre compact'],[375,'Centre compact']]) {
            await page.setViewportSize({width,height:900});
            await page.goto(complete.page.preview);
            const regions = page.locator('#syndamorph-page > .wp-block-group');
            assert.equal(await regions.count(),3);
            assert.equal(await regions.nth(1).locator('h2:visible').textContent(),label);
            assert.equal(await regions.nth(1).locator('h2:visible').count(),1);
        }
        await page.setViewportSize({width:1280,height:900});

        await page.goto(complete.page.editor);
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length===3);
        const draft = await page.evaluate(() => ({status:wp.data.select('core/editor').getCurrentPost().status,
            roots:wp.data.select('core/block-editor').getBlocks().map(block=>block.name)}));
        assert.equal(draft.status,'draft');
        assert.deepEqual(draft.roots,['core/group','core/group','core/group']);

        await page.goto(selected.state.regions[first].patternEditor);
        await page.waitForFunction(() => !!window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.innerBlocks?.length);
        await page.evaluate(async () => {
            const heading = wp.data.select('core/block-editor').getBlocks()[0].innerBlocks[0];
            wp.data.dispatch('core/block-editor').updateBlockAttributes(heading.clientId,{content:'North remains editable'});
            await wp.data.dispatch('core/editor').savePost();
        });
        await page.reload();
        await page.waitForFunction(() => String(window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.innerBlocks[0]?.attributes?.content)
            .includes('North remains editable'));
        console.log(JSON.stringify({sectionPattern:selected.state.regions[first].patternId,partialDraft:partial.page.id,
            fullDraft:complete.page.id,patterns:complete.page.placements.map(p=>p.patternId),blockCount:complete.page.blockCount,
            responsivePageQa:complete.page.qa,offlineModelResponses:archive.mockCalls+recovered.mockCalls,paidCalls:0}));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
