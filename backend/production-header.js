'use strict';
const { createHash } = require('node:crypto');
const { inspectTouch } = require('./production-touch');
const { recordedPng } = require('./production-reference');
const { captureResponsiveViewport, settleEntrancePaint } = require('./responsive-capture');

const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const clean = text => text.replace(/\s+/gu, ' ').trim();
const equal = (a,b) => JSON.stringify(a)===JSON.stringify(b);
const closedHeight = width => width===1440 ? 141 : 72;
const screenshot = async (page,clip) => (await page.screenshot({type:'jpeg',quality:75,timeout:3000,...(clip?{clip}:{})})).toString('base64');
const pathTo = id => '[data-dc-tpl="' + id + '"]';
const validId = id => typeof id==='string'&&/^\d{1,10}$/.test(id);
const link = ({label,href}) => ({label,...(href?{href}:{})});

function visibleAnchors(element) {
    return [...element.querySelectorAll('a[href]')]
        .filter(node=>node.checkVisibility({checkOpacity:true,checkVisibilityCSS:true})&&node.innerText.trim())
        .map(node=>{
            const {x,y,height}=node.getBoundingClientRect();
            return {id:node.getAttribute('data-dc-tpl'),label:node.innerText.trim().replace(/\s+/gu,' '),
                href:node.href,x,y:y+height/2};
        });
}

async function desktopHeader(page,root) {
    const rows=(await root.evaluate(visibleAnchors)).sort((a,b)=>a.y-b.y||a.x-b.x);
    if(rows.length<4||rows.length>24||!rows.every(row=>validId(row.id))||
        new Set(rows.map(row=>row.id)).size!==rows.length)return null;
    const lanes=[];
    for(const row of rows){
        let lane=lanes.find(list=>Math.abs(list[0].y-row.y)<8);
        if(!lane){lane=[];lanes.push(lane);}
        lane.push(row);
    }
    if(lanes.length!==2||lanes.some(lane=>lane.length<2))return null;
    const [utility,main]=lanes;
    const rate=main.pop();
    const ids=new Set(rows.map(row=>row.id));
    const panelStates=[];
    const menus=[];
    for(const lane of [utility,main]){
        const items=[];
        for(const row of lane){
            const target=root.locator(pathTo(row.id));
            await target.hover({timeout:2000});
            await settleEntrancePaint(page);
            const revealed=(await root.evaluate(visibleAnchors)).filter(anchor=>!ids.has(anchor.id));
            if(revealed.some(anchor=>!validId(anchor.id))||revealed.length>16)return null;
            const item=link(row);
            if(revealed.length){
                const panel=await root.locator(pathTo(revealed[0].id)).evaluate(el=>{
                    const {x,y,width,height}=el.parentElement.getBoundingClientRect();
                    return {x,y,width,height,children:[...el.parentElement.children].map(child=>({
                        id:child.getAttribute('data-dc-tpl'),tag:child.tagName,empty:!child.textContent.trim()
                    }))};
                });
                const anchors=panel.children.filter(child=>child.tag==='A');
                if(!equal(anchors.map(a=>a.id),revealed.map(a=>a.id))||!panel.children.every(child=>
                    child.tag==='A'||(child.tag==='DIV'&&child.empty)))return null;
                let separator=false;
                item.children=panel.children.flatMap(child=>{
                    if(child.tag==='DIV'){separator=true;return [];}
                    const value={...link(revealed.find(anchor=>anchor.id===child.id)),...(separator?{separatorBefore:true}:{})};
                    separator=false;
                    return [value];
                });
                item.panelWidth=Math.round(panel.width);
                const clip=innerClip(panel);
                if(!clip)return null;
                panelStates.push({label:item.label,clip,screenshot:await screenshot(page,clip)});
            }
            items.push(item);
            await page.mouse.move(8,650);
            await settleEntrancePaint(page);
            if((await root.evaluate(visibleAnchors)).some(anchor=>!ids.has(anchor.id)))return null;
        }
        menus.push(items);
    }
    const toggle=root.getByRole('button');
    if(await toggle.count()!==1)return null;
    const languageLabel=clean(await toggle.innerText());
    await toggle.hover({timeout:2000});
    await settleEntrancePaint(page);
    const options=(await root.evaluate(visibleAnchors)).filter(anchor=>!ids.has(anchor.id));
    if(options.length<2||options.length>8||options.some(option=>!validId(option.id)||!option.href.endsWith('#')||
        option.href.slice(0,-1)!==page.url()))return null;
    const languageBox=await root.locator(pathTo(options[0].id)).locator('..').boundingBox();
    const clip=innerClip(languageBox);
    if(!clip)return null;
    panelStates.push({label:languageLabel,language:true,clip,screenshot:await screenshot(page,clip)});
    await page.mouse.move(8,650);
    await settleEntrancePaint(page);
    if((await root.evaluate(visibleAnchors)).some(anchor=>!ids.has(anchor.id)))return null;
    return {navigation:{utility:menus[0],primary:menus[1],rateLabel:rate.label,rateUrl:rate.href,
        languageLabel,languages:options.map(option=>option.label)},panels:panelStates};
}

function innerClip(box) {
    if(!box)return null;
    const clip={x:Math.ceil(box.x)+1,y:Math.ceil(box.y)+1,width:Math.floor(box.width)-3,height:Math.floor(box.height)-3};
    return clip.x>=0&&clip.y>=0&&clip.width>0&&clip.height>0&&clip.x+clip.width<=1440&&clip.y+clip.height<=900?clip:null;
}

function drawerNavigation(close) {
    let drawer=close;
    while(drawer&&getComputedStyle(drawer).position!=='fixed')drawer=drawer.parentElement;
    const clean=value=>value.replace(/\s+/gu,' ').trim();
    const leaf=node=>{
        if(!node||node.childElementCount||!['SPAN','A'].includes(node.tagName)||!clean(node.innerText)||
            node.matches('[role],[tabindex],[onclick],[contenteditable]'))throw new Error('Unsupported drawer leaf.');
        const label=clean(node.innerText);
        return {label,...(node.tagName==='A'?{href:node.href}:{})};
    };
    const children=node=>[...node.children];
    if(!drawer||drawer.children.length!==3)return null;
    const [top,menu,footer]=children(drawer);
    const logo=top.querySelector('img');
    if(!logo||!logo.complete||!logo.naturalWidth||!top.contains(close))return null;
    const rows=children(menu);
    const utility=rows.pop();
    const compactPrimary=[];
    for(let i=0;i<rows.length;i++){
        const row=rows[i];
        const parts=children(row);
        if(parts.length!==1&&!(parts.length===2&&parts[1].tagName==='SPAN'&&clean(parts[1].innerText)==='+'))return null;
        const value=leaf(parts[0]);
        if(parts.length===2){
            value.children=children(rows[++i]).map(leaf);
            if(!value.children.length)return null;
        }
        if(parseFloat(getComputedStyle(row).borderBottomWidth)>1)value.emphasis=true;
        compactPrimary.push(value);
    }
    const compactUtility=children(utility).map(row=>{
        if(row.tagName!=='DIV')return leaf(row);
        const [label,group]=children(row);
        if(row.children.length!==2||group.tagName!=='DIV')throw new Error('Unsupported drawer grouping.');
        return {...leaf(label),children:children(group).map(leaf)};
    });
    const [language,rateNode]=children(footer);
    const [languageLabel,...languages]=children(language).map(leaf);
    if(footer.children.length!==2||rateNode.tagName!=='A'||languageLabel.href||
        languages.some(item=>item.href!==location.href+'#'))return null;
    const rate=leaf(rateNode);
    return {compactPrimary,compactUtility,rateLabel:rate.label,rateUrl:rate.href,languageLabel:languageLabel.label,
        languages:languages.map(item=>item.label),logoUrl:logo.currentSrc,homeUrl:logo.closest('a[href]')?.href};
}

async function compactWarmup(page,root,logoUrl) {
    const opener=root.getByRole('button',{name:'Open menu'});
    if(await opener.count()!==1||!await opener.evaluate(el=>el.closest('a[href], form')===null))return null;
    const close=page.getByRole('button',{name:'Close menu'});
    const requests=[];
    const log=req=>requests.push(req);
    page.on('request',log);
    try{
        await opener.click({timeout:2000});
        await close.waitFor({timeout:2000});
        const loaded=await close.evaluate(async el=>{
            let overlay=el;
            while(overlay&&getComputedStyle(overlay).position!=='fixed')overlay=overlay.parentElement;
            const img=overlay?.querySelector('img');
            if(!img)return null;
            await img.decode();
            return img.currentSrc;
        });
        if(loaded!==logoUrl)return null;
        await close.click({timeout:2000});
        await close.waitFor({state:'hidden',timeout:2000});
        const approved=requests.length<=1&&requests.every(req=>req.method()==='GET'&&!req.isNavigationRequest()&&
            req.resourceType()==='image'&&req.url()===logoUrl);
        return approved?{opener,close,warmupImageRequests:requests.length}:null;
    }finally{page.off('request',log);}
}

async function replayRecordedHeader(page,sections,diagnostics,{width,sourceRef,pinnedSha256,reference},seal) {
    const unknown=reason=>({version:1,width,sourceRef,status:'unknown',reason});
    const desktop=width===1440;
    const target=sections.find(section=>section.sourceRef===sourceRef);
    if(!target?.geometryAvailable||target.bounds.y!==0||target.bounds.height!==closedHeight(width)||
        (desktop&&target.kind!=='header'))return unknown('unsupported_header_geometry');
    const root=page.locator(sourceRef).filter({visible:true});
    if(await root.count()!==1)return unknown('header_not_unique');
    const copy=async()=>digest(await page.evaluate(()=>document.body.innerText.replace(/\s+/gu,' ').trim()));
    if(await copy()!==reference.copySha256)return unknown('source_copy_changed');
    const baseline=await captureResponsiveViewport(page,sections.filter(section=>section===target||section.kind==='overlay'),
        width,diagnostics);
    const closed=baseline.sections.find(section=>section.sourceRef===sourceRef)?.screenshot;
    if(!closed||digest(Buffer.from(closed,'base64'))!==pinnedSha256)return unknown('pinned_crop_changed');
    const images=root.locator('img:visible');
    if(await images.count()!==1)return unknown('logo_not_unique');
    const primaryLogo=await images.evaluate(el=>({url:el.currentSrc,alt:el.alt,home:el.closest('a[href]')?.href,
        size:[el.naturalWidth,el.naturalHeight]}));
    const archived=recordedPng(reference.runtime,primaryLogo.url);
    if(!archived||!equal(primaryLogo.size,archived.pixels)||!primaryLogo.alt||!primaryLogo.home)
        return unknown('archived_logo_unverified');

    const compact=desktop?null:await compactWarmup(page,root,primaryLogo.url);
    if(!desktop&&!compact)return unknown('drawer_warmup_unverified');
    const touch=await inspectTouch(page,{width,actions:[],copySha256:reference.copySha256},seal);
    if(touch.status!=='observed')return unknown('source_copy_changed');
    if(desktop&&sections.some(section=>section.kind==='overlay')){
        const actions=touch.initial.targets.filter(item=>item.permitsClick&&!item.destination&&/^Continue to website/.test(item.name));
        if(actions.length!==1)return unknown('header_occluded');
        await page.locator(actions[0].selector).click({timeout:2000});
        await settleEntrancePaint(page);
    }
    const scrollPositions=[0,500,1400,3000];
    for(const top of scrollPositions){
        await page.evaluate(top=>scrollTo({top,behavior:'instant'}),top);
        const box=await root.boundingBox();
        if(await page.evaluate(()=>scrollY)!==top||!box||box.y!==0||box.height!==closedHeight(width))
            return unknown('sticky_scroll_unverified');
    }
    await page.evaluate(()=>scrollTo({top:0,behavior:'instant'}));
    const markup=await root.innerHTML();
    const outcome=desktop?await desktopHeader(page,root):await (async()=>{
        const label=await root.evaluate((el,openerName)=>[...el.querySelectorAll('span')].filter(
            node=>node.checkVisibility()&&!node.children.length&&node.innerText.trim()&&
                node.getAttribute('aria-label')!==openerName).map(node=>node.innerText.trim()),'Open menu');
        if(label.length!==1)return null;
        await root.getByText(label[0],{exact:true}).click({timeout:2000});
        if(await root.innerHTML()!==markup||await compact.close.count()!==0||await copy()!==reference.copySha256)return null;
        await compact.opener.click({timeout:2000});
        await compact.close.waitFor({timeout:2000});
        const navigation=await compact.close.evaluate(drawerNavigation);
        if(!navigation||navigation.logoUrl!==primaryLogo.url||navigation.homeUrl!==primaryLogo.home)return null;
        const open=await screenshot(page);
        await page.keyboard.press('Escape');
        const escapeCloses=!(await compact.close.isVisible());
        if(escapeCloses)return null;
        await compact.close.click({timeout:2000});
        await compact.close.waitFor({state:'hidden',timeout:2000});
        if(await copy()!==reference.copySha256)return null;
        const {logoUrl,homeUrl,...menu}=navigation;
        return {navigation:{...menu,bookLabel:label[0]},open,warmupImageRequests:compact.warmupImageRequests,
            pointerCloses:true,escapeCloses};
    })();
    if(!outcome||seal().length||page.url()!==reference.sourceUrl)return unknown('header_actions_unverified');
    const {body,...logo}=archived;
    return {version:1,width,sourceRef,status:'observed',sourceCopySha256:reference.copySha256,pinnedSha256,scrollPositions,
        logo:{...logo,alt:primaryLogo.alt},homeUrl:primaryLogo.home,blockedRequestCount:0,...outcome,
        ...(desktop?{logoBase64:body.toString('base64')}:{})};
}

module.exports={replayRecordedHeader};
