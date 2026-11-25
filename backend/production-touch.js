'use strict';

const { createHash } = require('node:crypto');

const MAX_ACTIONS = 5;
const MAX_TARGETS = 60;
const WIDTHS = [1440, 768, 375];
const digest = value => createHash('sha256').update(value).digest('hex');
const actionNames = new Set(['click','hover','scroll','drag','resize']);
const targetId = value => typeof value==='string' && /^[a-f0-9]{24}$/.test(value);

function validateTouchActions(actions) {
    if(!Array.isArray(actions) || actions.length>MAX_ACTIONS)throw new Error('Invalid Touch action count.');
    return actions.map(action=>{
        if(!action || typeof action!=='object' || !actionNames.has(action.type))throw new Error('Invalid Touch action.');
        const { type,target,dy,dx,width }=action;
        if(type==='resize'){
            if(!Number.isInteger(width) || width<375 || width>1440)throw new Error('Touch width is outside the supported range.');
            return { type,width };
        }
        if(type==='scroll'){
            if(!Number.isInteger(dy) || dy===0 || Math.abs(dy)>900)throw new Error('Touch scroll is outside the supported range.');
            return { type,dy };
        }
        if(!targetId(target))throw new Error('Touch requires a discovered target identity.');
        if(type==='drag'){
            if(!Number.isInteger(dx) || dx===0 || Math.abs(dx)>300)throw new Error('Touch drag is outside the supported range.');
            return { type,target,dx };
        }
        return { type,target };
    });
}

function inspectTouchPage(maximum) {
    const normalize = value => String(value??'').replace(/\s+/gu,' ').trim();
    const selector = element => {
        const chain=[];
        for(let node=element; node && node!==document.body; node=node.parentElement){
            const index=Array.prototype.indexOf.call(node.parentElement.children,node);
            chain.unshift(`:nth-child(${index+1})`);
        }
        return ['body',...chain].join(' > ');
    };
    const visible = element => {
        const box=element.getBoundingClientRect();
        return box.width>0 && box.height>0 && element.checkVisibility({opacityProperty:true,visibilityProperty:true});
    };
    const candidates=[...document.querySelectorAll('a[href],button,summary,[role="button"],[role="tab"],[role="slider"],input,select,textarea,[aria-expanded],[draggable="true"]')];
    const visibleCandidates=candidates.filter(visible);
    const withinViewport=element=>{
        const box=element.getBoundingClientRect();
        return box.bottom>0 && box.right>0 && box.top<innerHeight && box.left<innerWidth;
    };
    visibleCandidates.sort((a,b)=>Number(withinViewport(b))-Number(withinViewport(a)));
    const elements=visibleCandidates.slice(0,maximum).map(element=>{
        const attributes=Object.fromEntries(['aria-controls','aria-expanded','aria-selected','aria-checked','aria-pressed','aria-valuenow']
            .filter(attr=>element.hasAttribute(attr)).map(attr=>[attr,element.getAttribute(attr).slice(0,240)]));
        const tag=element.localName;
        const style=getComputedStyle(element);
        const box=element.getBoundingClientRect();
        const href=tag==='a'?element.getAttribute('href'):null;
        let destination=null;
        if(href) {
            try {
                const url=new URL(href,location.href);
                if(['http:','https:'].includes(url.protocol) && !url.username && !url.password)
                    destination=url.href.slice(0,2048);
            } catch {}
        }
        const role=element.getAttribute('role')??'';
        const name=normalize(element.getAttribute('aria-label') || element.innerText || element.title || element.getAttribute('placeholder')).slice(0,120);
        const insideForm=Boolean(element.closest('form'));
        const sensitive=/\b(book|pay|buy|submit|sign[ -]?in|log[ -]?in|logout|checkout|reset password|delete|upload|download|purchase)\b/i.test(name);
        const unsafe=insideForm || sensitive || element.hasAttribute('download') || element.hasAttribute('formaction') ||
            Boolean(element.disabled || element.closest('[inert], [aria-disabled="true"]'));
        const localLink=tag==='a' && /^#[^#]+$/.test(href??'') && element.target==='';
        const regularButton=tag==='button' && element.type!=='reset';
        const widget=tag==='summary' || ['button','tab'].includes(role) || element.hasAttribute('aria-expanded');
        const permitsClick=!unsafe && (tag==='a'?localLink:(regularButton||widget));
        const permitsDrag=!unsafe && (role==='slider' || (tag==='input' && element.type==='range') || element.draggable===true);
        return { selector:selector(element),tag,role,name,attributes,destination,permitsClick,permitsDrag,
            bounds:[box.x,box.y,box.width,box.height].map(value=>Math.round(value*10)/10),
            styles:{ display:style.display,position:style.position,visibility:style.visibility,cursor:style.cursor,
                transform:style.transform } };
    });
    return { width:innerWidth,scroll:Math.round(scrollY),height:document.documentElement.scrollHeight,
        text:normalize(document.body.innerText),elements,truncated:visibleCandidates.length>maximum };
}

async function touchState(page) {
    const data=await page.evaluate(inspectTouchPage,MAX_TARGETS);
    const targets=data.elements.map(element=>({ ...element,id:digest(JSON.stringify([
        element.selector,element.tag,element.role,element.name,
    ])).slice(0,24) }));
    const copySha256=digest(data.text);
    const signature=digest(JSON.stringify([data.width,data.scroll,data.height,copySha256,targets.map(item=>[
        item.id,item.attributes,item.bounds,item.styles.transform,
    ])]));
    return { width:data.width,scroll:data.scroll,height:data.height,copySha256,targets,
        truncated:data.truncated,signature };
}

// The source capture supplies a guard that denies all page-initiated network
// requests. This module never accepts URLs, selectors or code from an agent.
async function inspectTouch(page, input, sealNetwork) {
    if(!WIDTHS.includes(input.width) || page.viewportSize()?.width!==input.width)
        throw new Error('Touch viewport does not match the pinned source.');
    const actions=validateTouchActions(input.actions);
    if(typeof sealNetwork!=='function')throw new Error('Touch network guard is missing.');
    const denied=sealNetwork();
    const initial=await touchState(page);
    if(typeof input.copySha256!=='string' || initial.copySha256!==input.copySha256)
        return { version:1,status:'unknown',reason:'live_source_changed',initial,scenarios:[] };
    const scenarios=[];
    let current=initial;
    const deadline=Date.now()+18000;
    for(const action of actions){
        if(Date.now()>=deadline){
            scenarios.push({ action,status:'unknown',reason:'time_limit' });
            continue;
        }
        const target=current.targets.find(entry=>entry.id===action.target);
        const button=action.type==='click';
        const drag=action.type==='drag';
        const unsupported=action.target && (!target || (button && !target.permitsClick) || (drag && !target.permitsDrag));
        if(unsupported){
            scenarios.push({ action,target,status:target?.destination?'inferred':'unknown',reason:'unsafe_or_unavailable' });
            continue;
        }
        const snapshot=async()=>{
            const bytes=await page.screenshot({ type:'jpeg',quality:45,animations:'disabled',timeout:2500 });
            if(bytes.length>150000)throw new Error('Touch screenshot exceeds the evidence limit.');
            return { sha256:digest(bytes),image:bytes.toString('base64') };
        };
        const before=await snapshot();
        const attemptStart=denied.length;
        let reason=null;
        try {
            if(action.type==='resize')await page.setViewportSize({ width:action.width,height:900 });
            else if(action.type==='scroll')await page.evaluate(dy=>scrollBy({ top:dy,behavior:'instant' }),action.dy);
            else {
                const locator=page.locator(target.selector);
                if(button)await locator.click({ timeout:2000 });
                else if(action.type==='hover')await locator.hover({ timeout:2000 });
                else {
                    await locator.scrollIntoViewIfNeeded({ timeout:2000 });
                    const box=await locator.boundingBox({ timeout:2000 });
                    if(!box)throw new Error('Drag target is unavailable.');
                    const x=box.x+box.width/2;
                    const y=box.y+box.height/2;
                    if(x+action.dx<0 || x+action.dx>=page.viewportSize().width)throw new Error('Drag exceeds viewport.');
                    await page.mouse.move(x,y);
                    await page.mouse.down();
                    try { await page.mouse.move(x+action.dx,y,{ steps:5 }); }
                    finally { await page.mouse.up(); }
                }
            }
        } catch { reason='action_did_not_complete'; }
        await page.waitForTimeout(350);
        const afterState=await touchState(page);
        const after=await snapshot();
        const transition=current.signature!==afterState.signature;
        const blockedRequests=denied.slice(attemptStart);
        const observed=!reason && transition;
        scenarios.push({ action,target,status:observed?'observed':'unknown',
            reason:reason??(transition?null:'state_unchanged'),before,after,blockedRequests,
            remoteEffects:'unverified',
            from:{ signature:current.signature,width:current.width,scroll:current.scroll,target },
            to:{ signature:afterState.signature,width:afterState.width,scroll:afterState.scroll,
                target:afterState.targets.find(entry=>entry.id===action.target) } });
        current=afterState;
    }
    return { version:1,status:'observed',initial,scenarios,final:current };
}

module.exports={ validateTouchActions,inspectTouch };
