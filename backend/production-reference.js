'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { pathToFileURL } = require('node:url');

const WIDTHS = [1440, 768, 375];
const HEIGHT = 900;
const MAX_HTML = 8_000_000;
const MAX_PIXELS = 18_000_000;
const MAX_DIFFERENCE = 0.015;
const OFFLINE = 'https://archive.invalid/reference.html';
const hash = data => createHash('sha256').update(data).digest('hex');
const enabled = () => process.env.SYNDAMORPH_REFERENCE_ARCHIVE === '1';
const archiveError = cause => Object.assign(new Error('Rendered reference verification is incomplete.', { cause }),
    { code: 'sm_reference_unverified' });

let modules;
async function referenceModules() {
    if (!modules) {
        const paths=[path.join(__dirname,'reference-dependencies')];
        const singlefile = await import(pathToFileURL(require.resolve('single-file-cli/lib/single-file-script.js',{paths})));
        const pixels = (await import(pathToFileURL(require.resolve('pixelmatch',{paths})))).default;
        const { PNG } = require(require.resolve('pngjs',{paths}));
        modules = { singlefile, pixels, PNG };
    }
    return modules;
}

async function prepareReferencePage(page) {
    try {
        const { singlefile } = await referenceModules();
        await page.addInitScript({ content: singlefile.getHookScriptSource() });
        await page.addInitScript({ content: await singlefile.getScriptSource({}, 'globalThis.singlefile = singlefile;') });
    } catch(error) { throw archiveError(error); }
}

function inspectReferenceState() {
    const root = document.documentElement;
    const text = value => value.replace(/\s+/gu, ' ').trim();
    const present = element => {
        const bounds = element.getBoundingClientRect();
        return bounds.width > 0 && bounds.height > 0 &&
            element.checkVisibility({ opacityProperty: true, visibilityProperty: true });
    };
    const typography = Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6,p,[role="heading"]'))
        .filter(present).slice(0, 60).map(element => {
            const { fontFamily, fontSize, fontWeight, color, lineHeight } = getComputedStyle(element);
            return [element.localName, text(element.innerText).slice(0, 120), fontFamily, fontSize, fontWeight, color, lineHeight];
        });
    const images = Array.from(document.images).filter(present)
        .map(image => [image.alt.slice(0, 200), image.complete, image.naturalWidth, image.naturalHeight]);
    const copy = text(document.body?.innerText || '');
    const bodyElements = document.body?.getElementsByTagName('*').length ?? 0;
    const fonts = Array.from(document.fonts).filter(font => font.status === 'loaded')
        .map(font => [font.family, font.weight, font.style]).sort();
    const resources = performance.getEntriesByType('resource').map(entry => {
        const url = new URL(entry.name, location.href);
        return [entry.initiatorType, url.protocol === 'http:' || url.protocol === 'https:' ? url.origin : url.protocol];
    });
    return { width: innerWidth, scrollWidth: root.scrollWidth, height: root.scrollHeight, fontStatus: document.fonts.status,
        copy, bodyElements, typography, images, fonts, resourceOrigins: [...new Set(resources.map(entry => entry.join(' ')))] };
}

async function stableReference(page) {
    for (let step=0; step<40; step++) {
        const atEnd = await page.evaluate(index => {
            scrollTo({ top: index * innerHeight * 0.76, behavior: 'instant' });
            return scrollY + innerHeight >= document.documentElement.scrollHeight - 2;
        }, step);
        await page.waitForTimeout(85);
        if (atEnd) break;
    }
    await page.evaluate(() => scrollTo({ top: 0, behavior: 'instant' }));
    let prior;
    let same=0;
    let state;
    for (let step=0; step<16; step++) {
        state = await page.evaluate(inspectReferenceState);
        if (state.height * Math.max(state.width, state.scrollWidth)>MAX_PIXELS || state.bodyElements>15000 ||
            state.copy.length>400000 || state.images.length>300) throw new Error('Reference exceeds inspection limits.');
        const signature = hash(JSON.stringify([state.width,state.scrollWidth,state.height,state.copy,
            state.typography,state.images,state.fonts]));
        same = prior===signature ? same+1 : 0;
        if (same>=2 && state.copy!=='' && state.fontStatus==='loaded' && state.images.every(image => image[1] && image[2]>0))
            return { ...state, copySha256: hash(state.copy), signature };
        prior=signature;
        await page.waitForTimeout(200);
    }
    throw Object.assign(new Error('Reference did not settle with its visible assets and fonts.'),{
        state:{ width:state.width,height:state.height,stableSamples:same,copyLength:state.copy.length,
            fonts:state.fontStatus,brokenImages:state.images.filter(image=>!image[1]||!image[2]).length,
            resourceOrigins:state.resourceOrigins },
    });
}

async function verifySnapshot(page, html, width, baseline, baselineImage, libraries) {
    const context = await page.context().browser().newContext({ viewport: { width, height: HEIGHT },
        deviceScaleFactor: 1, javaScriptEnabled: false, serviceWorkers: 'block', acceptDownloads: false,
        reducedMotion: 'reduce', colorScheme: 'light' });
    const blocked = [];
    let served=false;
    try {
        await context.route('**/*', route => {
            if(!served && route.request().isNavigationRequest() && route.request().url()===OFFLINE){
                served=true;
                return route.fulfill({ status:200,contentType:'text/html; charset=utf-8',body:html });
            }
            blocked.push(route.request().resourceType());
            return route.abort('blockedbyclient');
        });
        await context.routeWebSocket(/.*/, socket => {
            blocked.push('websocket');
            socket.close({ code: 1008, reason: 'Offline reference verification' });
        });
        const replay=await context.newPage();
        replay.on('console', entry => {
            if(entry.type()==='error' && /violates .*content security policy|refused to load/i.test(entry.text()))blocked.push('csp');
        });
        if(!(await replay.goto(OFFLINE,{ waitUntil:'load',timeout:15000 }))?.ok())throw new Error('Archive could not be loaded.');
        const state=await stableReference(replay);
        const archived=await replay.screenshot({ fullPage:true,animations:'disabled',caret:'hide',timeout:8000 });
        const { PNG,pixels }=libraries;
        const original=PNG.sync.read(baselineImage);
        const candidate=PNG.sync.read(archived);
        const sameDimensions=original.width===candidate.width && original.height===candidate.height;
        const ratio=sameDimensions ? pixels(original.data,candidate.data,null,original.width,original.height,{ threshold:0.1 })/
            (original.width*original.height) : null;
        if(blocked.length || baseline.signature!==state.signature || !sameDimensions || ratio>MAX_DIFFERENCE)
            throw Object.assign(new Error('Offline archive differs from the pinned source.'),{ state:{
                width, blocked, ratio,
                original:[baseline.scrollWidth,baseline.height,baseline.fonts.length,baseline.images.length],
                archived:[state.scrollWidth,state.height,state.fonts.length,state.images.length],
                changed:['copy','typography','images','fonts'].filter(key=>JSON.stringify(baseline[key])!==JSON.stringify(state[key])),
            } });
        return { image:archived, ratio, state };
    } finally { await context.close(); }
}

async function captureReference(page,width,diagnostics) {
    try {
        const libraries=await referenceModules();
        const { singlefile }=libraries;
        const baseline=await stableReference(page);
        if(baseline.width!==width)throw new Error('Capture viewport changed.');
        const baselineImage=await page.screenshot({ fullPage:true,animations:'disabled',caret:'hide',timeout:8000 });
        const originalHtml=await page.content();
        if(Buffer.byteLength(originalHtml)>MAX_HTML)throw new Error('Source DOM exceeds archive limit.');
        const html=await page.evaluate(async options => (await globalThis.singlefile.getPageData(options)).content, {
            zipScript:singlefile.getZipScriptSource(),blockScripts:true,blockVideos:false,removeHiddenElements:false,
            removeUnusedStyles:false,removeUnusedFonts:false,removeAlternativeFonts:false,removeAlternativeMedias:false,
            removeAlternativeImages:false,loadDeferredContent:false,compressHTML:true,insertMetaCSP:true,
        });
        if(!html || Buffer.byteLength(html)>MAX_HTML)throw new Error('Archive exceeds limits or is empty.');
        const replay=await verifySnapshot(page,html,width,baseline,baselineImage,libraries);
        const observedURL=new URL(page.url());
        observedURL.hash='';
        const facts={ width, viewportHeight:HEIGHT, scrollWidth:baseline.scrollWidth, pageHeight:baseline.height,
            sourceDOMSha256:hash(originalHtml), sourceScreenshotSha256:hash(baselineImage),
            archiveSha256:hash(html), replayScreenshotSha256:hash(replay.image), copySha256:baseline.copySha256,
            pixelDifferenceRatio:replay.ratio, externalRequests:0, javascriptEnabled:false, verified:true,
            sourceFontFaces:baseline.fonts.length, sourceVisibleImages:baseline.images.length };
        return { facts, url:observedURL.href, html, originalHtml, baselineImage, replayImage:replay.image,
            baseline, diagnostics };
    } catch(error) {
        throw Object.assign(archiveError(error),{ diagnostics });
    }
}

async function storeReferences(views,runtimeResponses=null) {
    if(views.map(view=>view.facts.width).join(',')!==WIDTHS.join(',') ||
        views.some(view=>view.url!==views[0].url || view.facts.verified!==true))throw archiveError(new Error('Exact-width verification missing.'));
    const root=process.env.SYNDAMORPH_REFERENCE_DIR;
    if(!root || !path.isAbsolute(root))throw archiveError(new Error('Reference directory is not configured.'));
    let temporary=null;
    try {
        const runtime=[];
        for(const [request,pending] of runtimeResponses??[]) {
            const response=await pending.catch(()=>null);
            if(response)runtime.push({ request,response,sha256:hash(response.body) });
        }
        const frozen=runtime.map(({ request,response,sha256 })=>({
            request,status:response.status,headers:response.headers,sha256,bytes:response.body.length,
        }));
        const recorded=runtime.length?JSON.stringify(frozen):null;
        const data={ version:1,format:'singlefile-2.27.3',sourceUrl:views[0].url,views:views.map(view=>view.facts),
            ...(recorded?{ runtimeSha256:hash(recorded) }:{}) };
        const id=hash(JSON.stringify(referenceIdentity(data)));
        await fs.mkdir(root,{ recursive:true,mode:0o700 });
        temporary=await fs.mkdtemp(path.join(root,'.capture-'));
        for(const item of views){
            const width=String(item.facts.width);
            await Promise.all([
                fs.writeFile(path.join(temporary,width+'.html'),item.html,{ flag:'wx',mode:0o600 }),
                fs.writeFile(path.join(temporary,width+'-source.html'),item.originalHtml,{ flag:'wx',mode:0o600 }),
                fs.writeFile(path.join(temporary,width+'-source.png'),item.baselineImage,{ flag:'wx',mode:0o600 }),
                fs.writeFile(path.join(temporary,width+'-replay.png'),item.replayImage,{ flag:'wx',mode:0o600 }),
                fs.writeFile(path.join(temporary,width+'-evidence.json'),JSON.stringify({
                    measurements:item.baseline,diagnostics:item.diagnostics }),{ flag:'wx',mode:0o600 }),
            ]);
        }
        if(recorded){
            await fs.writeFile(path.join(temporary,'runtime.json'),recorded,{ flag:'wx',mode:0o600 });
            for(const [i,item] of runtime.entries()){
                await fs.writeFile(path.join(temporary,'runtime-'+i+'.bin'),item.response.body,{ flag:'wx',mode:0o600 });
            }
        }
        const receipt={ ...data,id };
        await fs.writeFile(path.join(temporary,'receipt.json'),JSON.stringify(receipt),{ flag:'wx',mode:0o600 });
        try { await fs.rename(temporary,path.join(root,id)); temporary=null; }
        catch(error){
            if(!['EEXIST','ENOTEMPTY'].includes(error.code))throw error;
            const stored=JSON.parse(await fs.readFile(path.join(root,id,'receipt.json'),'utf8'));
            if(JSON.stringify(stored)!==JSON.stringify(receipt))throw error;
            for(const view of receipt.views) {
                const files={ '.html':view.archiveSha256,'-source.html':view.sourceDOMSha256,
                    '-source.png':view.sourceScreenshotSha256,'-replay.png':view.replayScreenshotSha256 };
                for(const [suffix,checksum] of Object.entries(files)) {
                    if(hash(await fs.readFile(path.join(root,id,view.width+suffix)))!==checksum)throw error;
                }
            }
            if(recorded) {
                if((await fs.readFile(path.join(root,id,'runtime.json'),'utf8'))!==recorded)throw error;
                for(const [i,item] of runtime.entries()){
                    if(hash(await fs.readFile(path.join(root,id,'runtime-'+i+'.bin')))!==item.sha256)throw error;
                }
            }
        }
        return receipt;
    } catch(error) { throw archiveError(error); }
    finally { if(temporary)await fs.rm(temporary,{ recursive:true,force:true }); }
}

function referenceIdentity(data) {
    const base=[data.version,data.format,data.sourceUrl,...data.views.map(view=>[
        view.width,view.sourceDOMSha256,view.sourceScreenshotSha256,view.archiveSha256,view.replayScreenshotSha256,
    ])];
    if(data.runtimeSha256)base.push(data.runtimeSha256);
    return base;
}

async function readReference(id,width) {
    if(typeof id!=='string' || !/^[a-f0-9]{64}$/.test(id) || !WIDTHS.includes(width))throw archiveError();
    const root=process.env.SYNDAMORPH_REFERENCE_DIR;
    if(!enabled() || !root || !path.isAbsolute(root))throw archiveError();
    try {
        const directory=path.join(root,id);
        const receipt=JSON.parse(await fs.readFile(path.join(directory,'receipt.json'),'utf8'));
        const view=receipt.views.find(item=>item.width===width);
        if(receipt.id!==id || hash(JSON.stringify(referenceIdentity(receipt)))!==id ||
            view?.verified!==true || view.externalRequests!==0 ||
            view.archiveSha256!==hash(await fs.readFile(path.join(directory,width+'.html'))))throw new Error('Reference mismatch.');
        const runtime=new Map();
        if(receipt.runtimeSha256){
            const text=await fs.readFile(path.join(directory,'runtime.json'),'utf8');
            if(hash(text)!==receipt.runtimeSha256)throw new Error('Source runtime receipt mismatch.');
            const entries=JSON.parse(text);
            if(!Array.isArray(entries) || entries.length>250 ||
                entries.some(entry=>!Number.isInteger(entry.bytes)||entry.bytes<0||entry.bytes>6000000) ||
                entries.reduce((total,entry)=>total+entry.bytes,0)>32000000)throw new Error('Source runtime exceeds limits.');
            for(const [i,entry] of entries.entries()){
                const file=path.join(directory,'runtime-'+i+'.bin');
                if((await fs.stat(file)).size!==entry.bytes)throw new Error('Source runtime asset size mismatch.');
                const body=await fs.readFile(file);
                if(hash(body)!==entry.sha256)throw new Error('Source runtime asset mismatch.');
                runtime.set(entry.request,{ status:entry.status,headers:entry.headers,body });
            }
        }
        return { sourceUrl:receipt.sourceUrl,copySha256:view.copySha256,runtime };
    } catch(error) { throw archiveError(error); }
}

module.exports={ enabled,prepareReferencePage,captureReference,storeReferences,readReference };
