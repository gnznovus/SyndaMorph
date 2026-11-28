'use strict';

const { createHash } = require('node:crypto');
const { captureResponsiveViewport } = require('./responsive-capture');
const { inspectTouch } = require('./production-touch');
const { recordedPng } = require('./production-reference');

async function replayRecordedFooter(page, sections, diagnostics, input, reference, seal) {
    const { width, sourceRef, pinnedSha256 } = input;
    const receipt = { version:1, width, sourceRef, sourceCopySha256:reference.copySha256 };
    const pending = reason => ({...receipt,status:'pending',reason});
    const root = page.locator(sourceRef);
    const target = sections.find(item=>item.sourceRef===sourceRef && item.geometryAvailable);
    if (!target || await root.count()!==1) return pending('footer_region_missing');
    const touch = await inspectTouch(page,{width,actions:[],copySha256:reference.copySha256},seal);
    if (touch.status!=='observed') return pending('footer_source_unverified');
    const source = await captureResponsiveViewport(page,sections.filter(item=>item===target||item.kind==='overlay'),width,diagnostics);
    const original = source.sections.find(item=>item.sourceRef===sourceRef);
    if (!original?.screenshot || createHash('sha256').update(Buffer.from(original.screenshot,'base64')).digest('hex')!==pinnedSha256)
        return pending('footer_crop_changed');
    if (width===1440) {
        const dismiss=touch.initial.targets.find(item=>item.permitsClick&&item.destination===null&&/^Continue to website/.test(item.name));
        if (!dismiss) return pending('footer_overlay_not_dismissible');
        await page.locator(dismiss.selector).click({timeout:2500});
    }
    const image=root.locator('img:visible');
    if (await image.count()!==1) return pending('footer_logo_missing');
    const painted=await image.evaluate(el=>({url:el.currentSrc,alt:el.alt,pixels:[el.naturalWidth,el.naturalHeight]}));
    const logo=recordedPng(reference.runtime,painted.url);
    if (!logo || JSON.stringify(logo.pixels)!==JSON.stringify(painted.pixels)||original.inventory?.assets?.[0]?.alt!==painted.alt)
        return pending('footer_logo_unverified');
    const state=()=>root.evaluate(el=>({markup:el.innerHTML,copy:el.innerText,url:location.href,y:scrollY}));
    const actions=width===1440?root.locator('a[href="#"]:visible'):root.getByText('+',{exact:true});
    const count=width===1440?5:4;
    if (await actions.count()!==count) return pending('footer_actions_changed');
    const local=[];
    for(let i=0;i<count;i++){
        const action=actions.nth(i);
        await page.evaluate(()=>history.replaceState(null,'',location.pathname+location.search));
        await action.scrollIntoViewIfNeeded({timeout:2500});
        const before=await state();
        const label=(await action.innerText()).trim();
        await action.click({timeout:2500});
        if(width===1440)await page.waitForFunction(()=>scrollY===0,null,{timeout:4500});
        const after=await state();
        if(before.markup!==after.markup||before.copy!==after.copy||seal().length||
            (width===1440?(before.y<=0||after.y!==0||after.url!==before.url+'#'):after.url!==before.url))
            return pending('footer_action_unverified');
        local.push({label,effect:width===1440?'scroll_to_document_start':'inert'});
    }
    const { body,...asset }=logo;
    return {...receipt,status:'observed',pinnedSha256,blockedRequestCount:seal().length,
        copy:original.inventory.text,links:original.inventory.links,local,
        logo:{asset,assetBase64:body.toString('base64')}};
}

module.exports = { replayRecordedFooter };
