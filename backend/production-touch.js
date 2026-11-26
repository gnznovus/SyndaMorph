'use strict';

const { createHash } = require('node:crypto');

const MAX_ACTIONS = 5;
const MAX_TARGETS = 60;
const WIDTHS = [1440, 768, 375];
const digest = value => createHash('sha256').update(value).digest('hex');
const touchTargetId = ({ selector, tag, role, name }) => digest(JSON.stringify([selector,tag,role,name])).slice(0,24);
const actionNames = new Set(['click','hover','scroll','scroll-x','drag','resize']);
const targetId = value => typeof value==='string' && /^[a-f0-9]{24}$/.test(value);
const disclosureRef = value => typeof value==='string' &&
    /^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/.test(value);

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
        if(type==='drag'||type==='scroll-x'){
            if(!Number.isInteger(dx) || dx===0 || Math.abs(dx)>(type==='drag'?300:900))
                throw new Error('Touch horizontal gesture is outside the supported range.');
            return { type,target,dx };
        }
        return { type,target };
    });
}

function inspectTouchPage({ maximum, preferHorizontal }) {
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
    const isScrollArea = element => element.clientWidth>0&&element.scrollWidth-element.clientWidth>4&&
        ['auto','scroll'].includes(getComputedStyle(element).overflowX);
    const candidates=[...document.querySelectorAll('body *')].filter(element=>element.matches(
        'a[href],button,summary,[role="button"],[role="tab"],[role="slider"],input,select,textarea,[aria-expanded],[draggable="true"]')||
        isScrollArea(element));
    const visibleCandidates=candidates.filter(visible);
    const withinViewport=element=>{
        const box=element.getBoundingClientRect();
        return box.bottom>0 && box.right>0 && box.top<innerHeight && box.left<innerWidth;
    };
    const horizontal = new Set(preferHorizontal ? visibleCandidates.filter(isScrollArea) : []);
    visibleCandidates.sort((a,b)=>Number(horizontal.has(b))-Number(horizontal.has(a))||
        Number(withinViewport(b))-Number(withinViewport(a)));
    const elements=visibleCandidates.slice(0,maximum).map(element=>{
        const attributes=Object.fromEntries(['aria-controls','aria-expanded','aria-selected','aria-checked','aria-pressed','aria-valuenow']
            .filter(attr=>element.hasAttribute(attr)).map(attr=>[attr,element.getAttribute(attr).slice(0,240)]));
        const tag=element.localName;
        if(tag==='summary'&&element.parentElement?.localName==='details')attributes.open=element.parentElement.open;
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
        const permitsScrollX=!insideForm&&!element.closest('[inert],[aria-disabled="true"]')&&isScrollArea(element);
        if(permitsScrollX)attributes.scrollLeft=Math.round(element.scrollLeft);
        return { selector:selector(element),tag,role,name,attributes,destination,permitsClick,permitsDrag,permitsScrollX,
            bounds:[box.x,box.y,box.width,box.height].map(value=>Math.round(value*10)/10),
            styles:{ display:style.display,position:style.position,visibility:style.visibility,cursor:style.cursor,
                transform:style.transform,opacity:style.opacity,color:style.color,backgroundColor:style.backgroundColor } };
    });
    return { width:innerWidth,scroll:Math.round(scrollY),height:document.documentElement.scrollHeight,
        fragment:location.hash,text:normalize(document.body.innerText),elements,truncated:visibleCandidates.length>maximum };
}

async function touchState(page,preferHorizontal) {
    const data=await page.evaluate(inspectTouchPage,{maximum:MAX_TARGETS,preferHorizontal});
    const targets=data.elements.map(element=>({ ...element,id:touchTargetId(element) }));
    const copySha256=digest(data.text);
    const signature=digest(JSON.stringify([data.width,data.scroll,data.height,copySha256,data.fragment,targets.map(item=>[
        item.id,item.attributes,item.bounds,item.styles.transform,
    ])]));
    return { width:data.width,scroll:data.scroll,height:data.height,fragment:data.fragment,copySha256,targets,
        truncated:data.truncated,signature };
}

function transitionFacts(action, before, after, target, nextTarget) {
    if(action.type==='resize')return before.width!==after.width?['viewport']:[];
    if(action.type==='scroll')return before.scroll!==after.scroll?['scroll']:[];
    if(action.type==='scroll-x')return nextTarget?.permitsScrollX&&
        target.attributes.scrollLeft!==nextTarget.attributes.scrollLeft?['horizontal-scroll']:[];
    const changes=[];
    if(before.copySha256!==after.copySha256)changes.push('visible-copy');
    if(before.fragment!==after.fragment)changes.push('local-fragment');
    if(nextTarget){
        if(JSON.stringify(target.attributes)!==JSON.stringify(nextTarget.attributes))changes.push('target-state');
        if(JSON.stringify(target.styles)!==JSON.stringify(nextTarget.styles)||
            target.bounds[2]!==nextTarget.bounds[2]||target.bounds[3]!==nextTarget.bounds[3])changes.push('target-appearance');
    }else if(!after.truncated)changes.push('target-hidden');
    return changes;
}

// The source capture supplies a guard that denies all page-initiated network
// requests. This module never accepts URLs, selectors or code from an agent.
async function inspectTouch(page, input, sealNetwork) {
    if(!WIDTHS.includes(input.width) || page.viewportSize()?.width!==input.width)
        throw new Error('Touch viewport does not match the pinned source.');
    const actions=validateTouchActions(input.actions);
    if(typeof sealNetwork!=='function')throw new Error('Touch network guard is missing.');
    const denied=sealNetwork();
    const preferHorizontal=actions.some(action=>action.type==='scroll-x');
    const initial=await touchState(page,preferHorizontal);
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
        const scrollX=action.type==='scroll-x';
        const unsupported=action.target && (!target || (button && !target.permitsClick) ||
            (drag && !target.permitsDrag) || (scrollX && !target.permitsScrollX));
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
                else if(scrollX){
                    await locator.scrollIntoViewIfNeeded({timeout:2000});
                    const box=await locator.boundingBox({timeout:2000});
                    if(!box)throw new Error('Scroll target is unavailable.');
                    const viewport=page.viewportSize();
                    const x=(Math.max(0,box.x)+Math.min(viewport.width,box.x+box.width))/2;
                    const y=(Math.max(0,box.y)+Math.min(viewport.height,box.y+box.height))/2;
                    await page.mouse.move(x,y);
                    await page.mouse.wheel(action.dx,0);
                }
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
        await page.waitForTimeout(scrollX?500:350);
        const afterState=await touchState(page,preferHorizontal);
        const after=await snapshot();
        const afterTarget=afterState.targets.find(entry=>entry.id===action.target);
        // Playwright may scroll a target into view before acting. That alone
        // does not prove a widget changed state after a click, hover or drag.
        const changes=transitionFacts(action,current,afterState,target,afterTarget);
        const blockedRequests=denied.slice(attemptStart);
        const observed=!reason && changes.length>0;
        scenarios.push({ action,target,status:observed?'observed':'unknown',
            reason:reason??(observed?null:'state_unchanged'),before,after,blockedRequests,changes,
            remoteEffects:'unverified',
            from:{ signature:current.signature,width:current.width,scroll:current.scroll,copySha256:current.copySha256,target },
            to:{ signature:afterState.signature,width:afterState.width,scroll:afterState.scroll,
                copySha256:afterState.copySha256,target:afterTarget } });
        current=afterState;
    }
    return { version:1,status:'observed',initial,scenarios,final:current };
}

/** Identify one exact repeated disclosure from the archived runtime, with no arbitrary selectors or actions. */
async function inspectDisclosure(page, input, sealNetwork) {
    const { width,sourceRef,copySha256 }=input;
    if(!WIDTHS.includes(width)||page.viewportSize()?.width!==width||!disclosureRef(sourceRef)||typeof sealNetwork!=='function')
        throw new Error('Invalid disclosure evidence request.');
    const initialCopySha256=digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()));
    const denied=sealNetwork();
    const receipt={version:1,width,sourceRef,sourceCopySha256:initialCopySha256,status:'unknown'};
    if(initialCopySha256!==copySha256)return {...receipt,reason:'live_source_changed'};
    const root=page.locator(sourceRef).filter({visible:true});
    if(await root.count()!==1)return {...receipt,reason:'region_not_unique'};
    const controls=root.locator('button,[role="button"]');
    const total=await controls.count();
    if(total<2||total>8)return {...receipt,reason:'incorrect_control_count'};
    const shape=await root.evaluate(region=>{
        const buttons=[...region.querySelectorAll('button,[role="button"]')];
        const icons=[...region.querySelectorAll('svg')];
        if(icons.length!==buttons.length||
            region.querySelector('a[href],form,input,textarea,select,video,canvas,iframe,img,object,embed,[role="tab"],[role="combobox"],[draggable="true"]'))return false;
        return buttons.every(button=>{
            const box=button.getBoundingClientRect();
            const icon=button.querySelector('svg');
            return box.width>0&&box.height>0&&!button.closest('[inert],[aria-disabled="true"]')&&!button.disabled&&
                !button.hasAttribute('formaction')&&button.parentElement.querySelectorAll('button,[role="button"]').length===1&&
                icon&&button.querySelectorAll('svg').length===1&&icon.children.length===1&&icon.firstElementChild.localName==='path'&&
                icon.getAttribute('viewBox')==='0 0 24 24'&&icon.getAttribute('width')==='20'&&icon.getAttribute('height')==='20'&&
                icon.getAttribute('fill')==='none'&&icon.getAttribute('stroke')==='currentColor'&&
                icon.getAttribute('stroke-width')==='1.75'&&icon.getAttribute('stroke-linecap')==='square'&&
                icon.firstElementChild.getAttribute('d')==='M6 9l6 6 6-6';
        });
    });
    if(!shape)return {...receipt,reason:'unsupported_controls_or_artwork'};
    const read=()=>controls.evaluateAll(buttons=>buttons.map(button=>{
        const clean=value=>value.replace(/\s+/gu,' ').trim();
        const summary=clean(button.innerText);
        const parent=clean(button.parentElement.innerText);
        return {summary,answer:parent.startsWith(summary)?parent.slice(summary.length).trim():null,
            indicator:getComputedStyle(button.querySelector('svg')).transform};
    }));
    const initial=await read();
    const safeQuestion=value=>value.endsWith('?')&&value.length<=240&&
        !/\b(book|pay|buy|submit|sign[ -]?in|log[ -]?in|logout|checkout|reset password|delete|upload|download|purchase)\b/i.test(value);
    const initialOpen=initial.flatMap((row,index)=>row.answer?[index]:[]);
    if(initialOpen.length!==1||initialOpen[0]!==0||initial.some(row=>!safeQuestion(row.summary)||row.answer===null||
        (row.indicator!=='none')!==Boolean(row.answer)))return {...receipt,reason:'unexpected_initial_disclosure'};
    const panels=initial.map((row,index)=>({targetId:digest(JSON.stringify([sourceRef,index,row.summary])).slice(0,24),
        summary:row.summary,answer:row.answer||null}));
    const clicks=[];
    const sequence=[...panels.keys()].slice(1).concat([0,0]);
    let before=initial;
    const blockedBefore=denied.length;
    let prerequisite=null;
    try{
        if(width===1440){
            const dismiss=page.getByRole('button',{name:/^Continue to website(?: →)?$/});
            if(await dismiss.count()===1&&await dismiss.isVisible()&&await dismiss.evaluate(element=>{
                if(element.closest('a[href],form,[inert]')||element.hasAttribute('formaction'))return false;
                for(let parent=element.parentElement;parent;parent=parent.parentElement){
                    if(['fixed','absolute'].includes(getComputedStyle(parent).position))return true;
                }
                return false;
            })){
                await dismiss.click({timeout:2000});
                await page.waitForTimeout(300);
                if(await dismiss.isVisible()||JSON.stringify(initial)!==JSON.stringify(await read())||denied.length!==blockedBefore)
                    return {...receipt,reason:'blocking_overlay_not_dismissed'};
                prerequisite='dismissed-local-overlay';
            }
        }
        for(const [position,index] of sequence.entries()){
            await controls.nth(index).click({timeout:2000});
            await page.waitForTimeout(300);
            const after=await read();
            const opened=after.flatMap((row,key)=>row.answer?[key]:[]);
            const expected=position===sequence.length-1?[]:[index];
            if(denied.length!==blockedBefore||JSON.stringify(opened)!==JSON.stringify(expected)||
                after.some((row,key)=>row.summary!==panels[key].summary||row.answer===null||
                    (row.indicator!=='none')!==Boolean(row.answer)))
                return {...receipt,reason:'disclosure_replay_did_not_match'};
            if(opened.length)panels[index].answer=after[index].answer;
            clicks.push({targetId:panels[index].targetId,index,open:opened,
                fromSha256:digest(JSON.stringify(before)),toSha256:digest(JSON.stringify(after))});
            before=after;
        }
    }catch{return {...receipt,reason:'disclosure_action_did_not_complete'};}
    if(panels.some(panel=>!panel.answer||panel.answer.length>1600))return {...receipt,reason:'panel_copy_missing'};
    return {...receipt,status:'observed',initial:initialOpen,panels,clicks,indicator:'chevron-20',prerequisite,
        keyboard:'unverified',externalEffects:'unverified',blockedRequestCount:denied.length-blockedBefore};
}

/** Measure a single, non-linked image and its actual horizontal scroll range. */
async function inspectImageStrip(page, {width,sourceRef,copySha256}, sealNetwork) {
    if(!WIDTHS.includes(width)||page.viewportSize()?.width!==width||!disclosureRef(sourceRef))
        throw new Error('Invalid image strip evidence request.');
    const denied=sealNetwork();
    const receipt={version:1,width,sourceRef,status:'unknown'};
    const sourceCopySha256=digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()));
    if(sourceCopySha256!==copySha256)return {...receipt,reason:'live_source_changed'};
    const root=page.locator(sourceRef).filter({visible:true});
    if(await root.count()!==1)return {...receipt,reason:'region_not_unique'};
    const state=()=>root.evaluate(element=>{
        const image=element.firstElementChild;
        if(element.children.length!==1||image?.tagName!=='IMG'||element.innerText.trim()||
            element.closest('a,form,[inert]')||!image.complete||!image.naturalWidth||!image.alt.trim()||
            !image.currentSrc.startsWith('https://'))return null;
        const box=element.getBoundingClientRect(),paint=image.getBoundingClientRect();
        const style=getComputedStyle(element),imageStyle=getComputedStyle(image);
        return {url:image.currentSrc,alt:image.alt,pixels:[image.naturalWidth,image.naturalHeight],
            width:box.width,height:box.height,imageBox:[paint.x-box.x,paint.y-box.y,paint.width,paint.height],
            padding:[style.paddingTop,style.paddingRight,style.paddingBottom,style.paddingLeft],
            imagePaddingRight:imageStyle.paddingRight,background:style.backgroundColor,overflow:style.overflowX,
            maximum:element.scrollWidth-element.clientWidth,offset:element.scrollLeft};
    });
    const initial=await state();
    if(!initial||initial.width!==width||initial.offset!==0||initial.height<=0||initial.imageBox[2]<=0)
        return {...receipt,reason:'unsupported_image_strip'};
    let after=initial;
    if(initial.maximum>0){
        if(!['auto','scroll'].includes(initial.overflow)||initial.maximum>1900)
            return {...receipt,reason:'unsupported_scroll_range'};
        try{
            await root.scrollIntoViewIfNeeded({timeout:2000});
            const box=await root.boundingBox();
            const point={x:box.x+box.width/2,y:Math.min(895,Math.max(4,box.y+box.height/2))};
            if(!await root.evaluate((element,{x,y})=>element.contains(document.elementFromPoint(x,y)),point))
                return {...receipt,reason:'scroll_target_occluded'};
            await page.mouse.move(point.x,point.y);
            await page.mouse.wheel(initial.maximum+100,0);
            const deadline=Date.now()+2000;
            do{
                await page.waitForTimeout(70);
                after=await state();
            }while(after&&after.offset!==initial.maximum&&Date.now()<deadline);
            if(!after||after.offset!==initial.maximum)
                return {...receipt,reason:'scroll_endpoint_unverified'};
        }catch{return {...receipt,reason:'scroll_action_failed'};}
    }
    if(denied.length||after.url!==initial.url||after.alt!==initial.alt||
        digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()))!==sourceCopySha256)
        return {...receipt,reason:'source_changed_during_replay'};
    return {...receipt,status:'observed',sourceCopySha256,initial,finalOffset:after.offset,
        keyboard:'unverified',blockedRequestCount:denied.length};
}

/** Observe source markup and decoded image geometry without following its link. */
async function inspectLinkedImage(page, {width,sourceRef,copySha256}, sealNetwork) {
    if(!WIDTHS.includes(width)||page.viewportSize()?.width!==width||!disclosureRef(sourceRef))
        throw new Error('Invalid linked image evidence request.');
    const denied=sealNetwork();
    const receipt={version:1,width,sourceRef,status:'unknown'};
    const sourceCopySha256=digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()));
    if(sourceCopySha256!==copySha256)return {...receipt,reason:'live_source_changed'};
    const root=page.locator(sourceRef).filter({visible:true});
    if(await root.count()!==1)return {...receipt,reason:'region_not_unique'};
    const state=await root.evaluate(element=>{
        const visible=node=>node.getBoundingClientRect().width>0&&node.getBoundingClientRect().height>0&&
            node.checkVisibility({opacityProperty:true,visibilityProperty:true});
        const links=[...element.querySelectorAll('a[href]')].filter(visible);
        const images=[...element.querySelectorAll('img')].filter(visible);
        const interactive=[...element.querySelectorAll('button,input,select,textarea,summary,[role="button"],[role="link"],[contenteditable]')];
        if(links.length!==1||images.length>1||interactive.some(visible)||element.closest('a,form,[inert]'))return null;
        const link=links[0],image=images[0];
        let destination;
        try{destination=new URL(link.href);}catch{return null;}
        const text=link.innerText.replace(/\s+/gu,' ').trim();
        if(destination.origin!==location.origin||destination.protocol!=='https:'||destination.hash||
            destination.username||destination.password||destination.href===location.href||destination.href.length>2048||
            !text||text.length>240||link.tabIndex<0||link.hasAttribute('download')||link.hasAttribute('ping')||
            (link.target&&link.target!=='_self')||link.closest('[inert]'))return null;
        let artwork=null;
        if(image){
            const url=new URL(image.currentSrc||location.href);
            if(url.protocol!=='https:'||url.username||url.password||url.hash||url.href.length>2048||
                image.closest('a,[inert]')||!image.complete||!image.naturalWidth||!image.alt.trim()||image.alt.length>240)return null;
            const origin=element.getBoundingClientRect(),box=image.getBoundingClientRect(),style=getComputedStyle(image);
            artwork={url:url.href,alt:image.alt,pixels:[image.naturalWidth,image.naturalHeight],
                box:[box.x-origin.x,box.y-origin.y,box.width,box.height],filter:style.filter,blend:style.mixBlendMode};
        }
        return {link:{href:destination.href,text},image:artwork};
    });
    if(!state||denied.length)return {...receipt,reason:'unsupported_linked_image_markup'};
    return {...receipt,status:'observed',sourceCopySha256,...state,destinationStatus:'declared',blockedRequestCount:0};
}

/** Replay a bounded pointer reveal and confirm the apparent call to action is inert. */
async function inspectRevealCards(page,{width,sourceRef,copySha256},sealNetwork) {
    if(!WIDTHS.includes(width)||page.viewportSize()?.width!==width||!disclosureRef(sourceRef))
        throw new Error('Invalid reveal evidence request.');
    const denied=sealNetwork();
    const sourceCopySha256=digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()));
    const pending={version:1,width,sourceRef,sourceCopySha256,status:'unknown'};
    if(sourceCopySha256!==copySha256)return {...pending,reason:'live_source_changed'};
    const root=page.locator(sourceRef).filter({visible:true});
    if(await root.count()!==1)return {...pending,reason:'region_not_unique'};
    const shape=await root.evaluate(region=>{
        const supported=!region.querySelector('a[href],form,input,select,textarea,button,details,[role=button],'+
            '[role=tab],iframe,svg,video,object,img,canvas,[tabindex],[draggable=true]');
        const candidates=[...region.querySelectorAll('*')].filter(node=>node.checkVisibility()&&
            (typeof node.onclick==='function'||getComputedStyle(node).cursor==='pointer'&&
                getComputedStyle(node.parentElement).cursor!=='pointer'));
        const paths=candidates.map(node=>{
            const path=[];
            for(let part=node;part!==region;part=part.parentElement)
                path.unshift(`:nth-child(${[...part.parentElement.children].indexOf(part)+1})`);
            return path.join(' > ');
        });
        return {supported,paths};
    });
    if(!shape.supported)return {...pending,reason:'unsupported_reveal_controls'};
    if(width!==1440)return shape.paths.length||denied.length?{...pending,reason:'compact_actions_present'}:
        {...pending,status:'observed',modeAtWidth:'static',cards:[],blockedRequestCount:0};
    if(shape.paths.length<2||shape.paths.length>6)return {...pending,reason:'incorrect_control_count'};
    let prerequisite=null;
    // The recorded desktop overlay may occlude the cards. Only dispatch the inert local dismiss action.
    const dismiss=page.locator('[role=button]').filter({hasText:/^Continue to website(?: →)?$/}).filter({visible:true});
    if(await dismiss.count()===1&&await dismiss.evaluate(node=>{
        if(node.closest('a[href],form,[inert]')||node.hasAttribute('formaction'))return false;
        for(let parent=node.parentElement;parent;parent=parent.parentElement)
            if(['absolute','fixed'].includes(getComputedStyle(parent).position))return true;
        return false;
    })){
        try{await dismiss.click({timeout:2000});await page.waitForTimeout(300);}catch{return {...pending,reason:'overlay_not_dismissed'};}
        if(await dismiss.isVisible()||denied.length)return {...pending,reason:'overlay_not_dismissed'};
        prerequisite='dismissed-local-overlay';
    }
    const cards=[];
    for(const [index,path] of shape.paths.entries()){
        const card=root.locator(':scope > '+path);
        const read=()=>card.evaluate(element=>{
            const cover=element.firstElementChild,panel=cover?.lastElementChild;
            if(element.tagName!=='DIV'||!cover||!panel||element.children.length!==3||cover.children.length!==2||
                panel.tagName!=='DIV'||panel.children.length!==2||panel.firstElementChild.tagName!=='P'||
                panel.lastElementChild.tagName!=='SPAN'||panel.lastElementChild.children.length||
                getComputedStyle(panel).position!=='absolute')return null;
            const text=node=>node.textContent.replace(/\s+/gu,' ').trim();
            const style=getComputedStyle(panel),size=cover.getBoundingClientRect();
            const copy=[text(cover.firstElementChild),...Array.from(panel.children,text),...Array.from(element.children).slice(1).map(text)];
            if(copy.some(value=>!value||value.length>900)||size.width>650||size.height>650||size.width<120||size.height<120)return null;
            return {copy,opacity:Number(style.opacity),hovered:element.matches(':hover'),keyboardReachable:element.tabIndex>=0,
                paint:style.backgroundImage,color:style.backgroundColor,padding:style.padding,gap:style.gap,
                extent:[Math.round(size.width),Math.round(size.height)]};
        });
        try{
            if(await card.count()!==1)return {...pending,reason:'reveal_target_lost'};
            await card.scrollIntoViewIfNeeded({timeout:1800});
            await page.mouse.move(0,0);
            await page.waitForTimeout(400);
            const before=await read();
            if(!before||before.hovered||before.opacity!==0||before.keyboardReachable)return {...pending,reason:'initial_reveal_state_mismatch'};
            const screenshot=()=>card.screenshot({animations:'disabled',timeout:2400});
            const initialSha256=digest(await screenshot());
            await card.hover({timeout:1800});
            await page.waitForTimeout(400);
            const after=await read();
            if(!after||!after.hovered||after.opacity!==1||JSON.stringify(after.copy)!==JSON.stringify(before.copy))
                return {...pending,reason:'hover_reveal_not_observed',cardIndex:index,
                    hovered:after?.hovered??false,opacity:after?.opacity??null};
            const revealedSha256=digest(await screenshot());
            if(initialSha256===revealedSha256)return {...pending,reason:'hover_paint_unchanged'};
            const label=card.locator(':scope > div:first-child > div:last-child > span:last-child');
            const url=page.url();
            await label.click({timeout:1800});
            const clicked=await read();
            if(denied.length||page.url()!==url||JSON.stringify(clicked)!==JSON.stringify(after))
                return {...pending,reason:'reveal_label_has_unverified_effects'};
            await page.mouse.move(0,0);
            await page.waitForTimeout(400);
            if(JSON.stringify(await read())!==JSON.stringify(before))return {...pending,reason:'hover_reveal_not_reversible'};
            const copySha256=digest(JSON.stringify(before.copy));
            cards.push({id:digest(JSON.stringify([sourceRef,index,copySha256])).slice(0,24),index,copy:before.copy,copySha256,
                actions:['hover','click-label','leave'],opacity:[0,1,1,0],keyboard:'unavailable-in-source',destination:'absent',
                paint:after.paint,color:after.color,padding:after.padding,gap:after.gap,extent:after.extent,initialSha256,revealedSha256});
        }catch{return {...pending,reason:'reveal_replay_failed'};}
    }
    return {...pending,status:'observed',modeAtWidth:'hover-reveal',prerequisite,cards,blockedRequestCount:0};
}

module.exports={ validateTouchActions,inspectTouch,touchTargetId,disclosureRef,inspectDisclosure,inspectImageStrip,inspectLinkedImage,inspectRevealCards };
