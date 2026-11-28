'use strict';

const { chromium } = require('playwright');
const { createHash } = require('node:crypto');
const axe = require('axe-core');
const { checkHeader } = require('./check-header');
const { checkOffer } = require('./check-offer');
const { HEIGHT, MAX_JPEG, MAX_METADATA, DEFAULT_VIEWPORTS, RendererError } = require('./inspection-contract');

async function waitForInstalledFonts(page) {
    await page.evaluate(() => {
        if(!document.querySelector('style[data-sm-installed-fonts]'))return true;
        document.body.getBoundingClientRect();
        return document.fonts.ready.then(() => true);
    });
}

async function checkImageStrip(page) {
    const strip=page.locator('.wp-block-group.sm-source-image-strip');
    if(await strip.count()!==1)return null;
    const image=strip.locator(':scope > figure.wp-block-image > img[data-sm-source-image]');
    if(await image.count()!==1)return null;
    await image.evaluate(element=>element.decode());
    const state=()=>strip.evaluate(element=>{
        const style=getComputedStyle(element),img=element.querySelector('img'),rect=element.getBoundingClientRect();
        return {range:element.scrollWidth-element.clientWidth,offset:element.scrollLeft,alt:img.alt,
            insidePage:rect.left>=-1&&rect.right<=innerWidth+1&&document.documentElement.scrollWidth<=innerWidth,
            compact:['auto','scroll'].includes(style.overflowX),name:element.getAttribute('aria-label'),
            focus:document.activeElement===element&&element.matches(':focus-visible')&&parseFloat(style.outlineWidth)>=2,
            focusable:element.tabIndex===0&&element.getAttribute('role')==='group',src:img.currentSrc};
    });
    const before=await state();
    const png=/^data:image\/png;base64,([A-Za-z0-9+/]+=*)$/.exec(before.src);
    if(!png||before.range>1900||before.offset!==0)return null;
    const proof={sha256:createHash('sha256').update(Buffer.from(png[1],'base64')).digest('hex'),
        alt:before.alt,range:before.range,initialOffset:before.offset,compact:before.compact,
        insidePage:before.insidePage,focusable:before.focusable&&Boolean(before.name)};
    if(!before.range)return proof;
    await strip.scrollIntoViewIfNeeded({timeout:2000});
    const box=await strip.boundingBox();
    await page.mouse.move(box.x+box.width/2,Math.min(895,Math.max(4,box.y+box.height/2)));
    const settle=async value=>{
        const deadline=Date.now()+1500;
        let current;
        do{await page.waitForTimeout(60);current=await state();}while(current.offset!==value&&Date.now()<deadline);
        return current.offset;
    };
    await page.mouse.wheel(before.range+100,0);
    proof.wheelOffset=await settle(before.range);
    await page.mouse.wheel(-before.range-100,0);
    proof.returnOffset=await settle(0);
    await strip.press('ArrowRight');
    await page.waitForTimeout(160);
    const keyboard=await state();
    proof.keyboardOffset=keyboard.offset;
    proof.focus=keyboard.focus;
    return proof;
}

async function checkRevealCards(page) {
    const layout=page.locator('.sm-reveal-layout');
    if(await layout.count()!==1)return null;
    const cards=layout.locator('[data-sm-reveal-card]:visible');
    const count=await cards.count();
    const insidePage=await layout.evaluate(()=>document.documentElement.scrollWidth<=innerWidth);
    if(count===0)return {insidePage,cards:[],keyboard:[]};
    if(count<2||count>6)return null;
    const read=card=>card.evaluate(node=>{
        const cover=node.querySelector(':scope > .sm-reveal-cover'),panel=cover?.querySelector(':scope > .sm-reveal-panel');
        if(!cover||!panel||node.querySelector('a,button,form,input'))return null;
        const copy=[cover.firstElementChild,...panel.children,...Array.from(node.children).slice(1)]
            .map(el=>el.textContent.replace(/\s+/gu,' ').trim());
        const style=getComputedStyle(panel),box=cover.getBoundingClientRect();
        return {id:node.getAttribute('data-sm-reveal-card'),copy,opacity:Number(style.opacity),paint:style.backgroundImage,
            color:style.backgroundColor,padding:style.padding,gap:style.gap,extent:[Math.round(box.width),Math.round(box.height)],
            focusable:node.tabIndex===0&&node.getAttribute('role')==='group'&&!!node.getAttribute('aria-label'),
            labelInert:panel.lastElementChild.tabIndex<0};
    });
    const results=[];
    for(const card of await cards.all()){
        await card.scrollIntoViewIfNeeded({timeout:2000});
        await page.mouse.move(0,0);
        const initial=await read(card);
        if(!initial||initial.opacity!==0||!initial.focusable||!initial.labelInert)return null;
        await card.hover({timeout:2000});
        const revealed=await read(card);
        const label=card.locator('.sm-reveal-label');
        const url=page.url();
        await label.click({timeout:2000});
        const clicked=await read(card);
        await page.mouse.move(0,0);
        const left=await read(card);
        if(!revealed||!clicked||!left||page.url()!==url||
            JSON.stringify(initial.copy)!==JSON.stringify(revealed.copy)||JSON.stringify(revealed)!==JSON.stringify(clicked)||
            JSON.stringify(initial)!==JSON.stringify(left))return null;
        results.push({...revealed,opacity:[initial.opacity,revealed.opacity,clicked.opacity,left.opacity]});
    }
    await page.evaluate(()=>{document.body.tabIndex=-1;document.body.focus();});
    const keyboard=[];
    for(const card of await cards.all()){
        await page.keyboard.press('Tab');
        const focused=await card.evaluate(el=>{
            const style=getComputedStyle(el);
            return el===document.activeElement&&el.matches(':focus-visible')&&style.outlineStyle!=='none'&&parseFloat(style.outlineWidth)>=2;
        });
        const open=await cards.evaluateAll(nodes=>nodes.filter(el=>Number(getComputedStyle(el.querySelector('.sm-reveal-panel')).opacity)===1)
            .map(el=>el.getAttribute('data-sm-reveal-card')));
        if(!focused||open.length!==1||open[0]!==await card.getAttribute('data-sm-reveal-card'))return null;
        keyboard.push(open[0]);
    }
    await page.keyboard.press('Tab');
    const closed=await cards.evaluateAll(nodes=>nodes.every(el=>getComputedStyle(el.querySelector('.sm-reveal-panel')).opacity==='0'));
    return {insidePage,cards:results,keyboard,closed};
}

async function checkRooms(page,width) {
    const root=page.locator('.sm-rooms-layout');
    if(await root.count()!==1||await root.locator('a[href],form').count())return null;
    if(!await root.evaluate(()=>document.documentElement.scrollWidth<=innerWidth))return null;
    if(width<1024){
        const strip=root.locator('.sm-rooms-strip');
        if(await root.locator('[data-sm-room-category]:visible,button:visible').count())return null;
        const active=await strip.evaluate(el=>el.scrollWidth-el.clientWidth);
        const offset=()=>strip.evaluate(el=>el.scrollLeft);
        await strip.scrollIntoViewIfNeeded();
        const box=await strip.boundingBox();
        if(!box)return null;
        await page.mouse.move(box.x+box.width/2,box.y+box.height/2);
        await page.mouse.wheel(500,0);
        await page.waitForTimeout(220);
        const forward=await offset();
        await page.mouse.wheel(-500,0);
        await page.waitForTimeout(220);
        const returned=await offset();
        let keyboard=null;
        if(active){
            await page.evaluate(()=>{document.body.tabIndex=-1;document.body.focus();});
            await page.keyboard.press('Tab');
            if(!await strip.evaluate(el=>el===document.activeElement&&el.matches(':focus-visible')&&
                parseFloat(getComputedStyle(el).outlineWidth)>=2))return null;
            await page.keyboard.press('ArrowRight');
            await page.waitForTimeout(240);
            keyboard=await offset();
        }
        return {copy:await strip.locator('.sm-rooms-chip').allTextContents(),wheel:[0,forward,returned],keyboard};
    }
    const carousel=root.locator('.sm-rooms-carousel');
    const previous=root.locator('[data-sm-room-previous]');
    const next=root.locator('[data-sm-room-next]');
    const pause=root.locator('[data-sm-room-pause]');
    const dots=root.locator('[data-sm-room-dot]');
    const categories=root.locator('[data-sm-room-category]');
    const state=()=>root.evaluate(el=>{
        const cards=[...el.querySelectorAll('.sm-rooms-category')];
        const expanded=cards.filter(card=>card.dataset.smRoomActive==='true'&&
            card.querySelector('[data-sm-room-category]').getAttribute('aria-expanded')==='true');
        const room=cards.indexOf(expanded[0]);
        const stage=el.querySelector('.sm-rooms-carousel');
        const photos=[...stage.querySelectorAll('.sm-rooms-slide')];
        const selected=photos.filter(slide=>slide.getAttribute('aria-hidden')==='false');
        const photo=photos.indexOf(selected[0]);
        if(expanded.length!==1||selected.length!==1||stage.dataset.smRoomPhoto!==String(photo)||
            cards.some((card,index)=>card.querySelector('.sm-rooms-detail').hidden===(room===index)))return null;
        return {photo,room,details:[...expanded[0].querySelectorAll('.sm-rooms-detail p')].map(p=>p.textContent.trim()),
            title:stage.querySelector('.sm-rooms-photo-title').textContent.trim(),
            offset:Math.round(selected[0].getBoundingClientRect().x-stage.getBoundingClientRect().x)};
    });
    const check=async(photo,room)=>{
        const s=await state();
        if(!s||s.photo!==photo||s.room!==room||s.offset!==0)throw Error('Room state mismatch');
        return s;
    };
    try{
        const startup=await check(0,0);
        const steps=[[startup.photo,startup.room]];
        if(width===1440){
            await page.waitForFunction(()=>document.querySelector('.sm-rooms-carousel')?.dataset.smRoomPhoto==='1',{},{timeout:5000});
            await carousel.scrollIntoViewIfNeeded();
            await pause.focus();
            await pause.press('Enter');
            if(await pause.getAttribute('aria-pressed')!=='true')return null;
        }else await page.emulateMedia({reducedMotion:'reduce'});
        // Reduced motion keeps the functional samples independent of transitions.
        await page.emulateMedia({reducedMotion:'reduce'});
        await dots.nth(0).press('Enter');
        for(const [control,key,photo,room] of [[previous,'Enter',2,0],[next,'Space',0,0],[dots.nth(2),'Enter',2,0],
            [categories.nth(1),'Enter',0,1],[categories.nth(2),'Space',0,2],[categories.nth(3),'Enter',0,3],
            [categories.nth(0),'Enter',0,0],[categories.nth(0),'Enter',0,0]]){
            await control.focus();
            await control.press(key);
            const s=await check(photo,room);
            const focus=await control.evaluate(el=>el===document.activeElement&&el.matches(':focus-visible')&&
                parseFloat(getComputedStyle(el).outlineWidth)>=2);
            if(!focus)return null;
            steps.push([s.photo,s.room,s.title,s.details]);
        }
        return {steps,autoplay:width===1440,insidePage:true};
    }catch{return null;}
}

async function boundedScreenshot(page, width, extent, sourceTop = null) {
    const session = extent ? await page.context().newCDPSession(page) : null;
    try {
    for (const quality of sourceTop === null ? [65, 50, 35, 20, 10] : [58, 40, 22]) {
        if (session) {
            const { data } = await session.send('Page.captureScreenshot', { format: 'jpeg', quality, captureBeyondViewport: true,
                clip: { x: 0, y: sourceTop === null ? 0 : Math.round(sourceTop),
                    width, height: extent, scale: Math.min(1, HEIGHT / extent) } });
            if (data.length <= 4 * Math.ceil(MAX_JPEG / 3)) return data;
            continue;
        }
        const bytes = await page.screenshot({ type: 'jpeg', quality, timeout: 5000 });
        if (bytes.length <= MAX_JPEG) return bytes.toString('base64');
    }
    throw new RendererError('screenshot_limit', 507);
    } finally {
        if (session) await session.detach();
    }
}

// Match the source paint origin after measuring the candidate's authored layout.
async function sourceFrameScreenshot(page, width, extent, top) {
    if(top===0)return boundedScreenshot(page,width,extent,top);
    const frame=await page.evaluateHandle(sourceTop=>{
        const body=document.body;
        const nodes=[...body.querySelectorAll('*')].filter(el=>el.getClientRects().length>0);
        const original=nodes.map(el=>el.getBoundingClientRect());
        const offset=parseFloat(getComputedStyle(body).top)||0;
        const sheets=[...document.adoptedStyleSheets];
        const translation=new CSSStyleSheet();
        translation.replaceSync(`html > body { position:relative!important; top:${offset+sourceTop}px!important; }`);
        document.adoptedStyleSheets=[...sheets,translation];
        const close=(a,b)=>Math.abs(a-b)<=0.035;
        const translated=nodes.every((el,index)=>{
            const before=original[index],after=el.getBoundingClientRect();
            return close(after.top-before.top,sourceTop)&&close(after.left,before.left)&&
                close(after.width,before.width)&&close(after.height,before.height);
        });
        return {translated,restore(){document.adoptedStyleSheets=sheets;}};
    },top);
    try {
        if(!await frame.evaluate(state=>state.translated))throw new RendererError('source_frame_unavailable',422);
        return await boundedScreenshot(page,width,extent,top);
    } finally {
        await frame.evaluate(state=>state.restore());
        await frame.dispose();
    }
}

async function checkCompactBooking(page, width) {
    const root = page.locator('.wp-block-group.is-style-sm-compact-booking');
    if (await root.count()!==1) throw new RendererError('missing_evidence',422);
    if (!await root.isVisible()) return {present:false};
    const state = await root.evaluate(element=>({
        copy:[...element.querySelectorAll('p')].map(node=>node.textContent.replace(/\s+/gu,' ').trim()),
        controls:element.querySelectorAll('a,button,input,select,textarea,form,[role],[tabindex],[contenteditable],[onclick]').length,
        fixed:getComputedStyle(element).position==='fixed',
        overflow:document.documentElement.scrollWidth>innerWidth||element.scrollWidth>element.clientWidth,
    }));
    const measure = async () => {
        const box=await root.boundingBox();
        return box ? [box.x,box.y,box.width,box.height] : null;
    };
    const bounds=await measure();
    const expected=[0,HEIGHT-68,width,68];
    if (await page.evaluate(()=>scrollY)!==0 || !bounds || bounds.some((value,index)=>value!==expected[index]))
        throw new RendererError('source_frame_unavailable',422);
    // Clip the fixed layer in place. Translating the document would change its viewport anchor.
    const screenshot=await boundedScreenshot(page,width,68,HEIGHT-68);
    const positions=[];
    for(const top of [0,500,1400,3000]){
        await page.evaluate(top=>window.scrollTo({top,behavior:'instant'}),top);
        positions.push({y:await page.evaluate(()=>scrollY),bounds:await measure()});
    }
    return {present:true,...state,bounds,positions,screenshot};
}

async function checkScrollStrips(page,html) {
    const strips=page.locator('.wp-block-group.sm-scroll-strip,[data-sm-scroll-reference]');
    const count=await strips.count();
    if(count>4)throw new RendererError('evidence_limit',507);
    const checks=[];
    for(let index=0;index<count;index++){
        const strip=strips.nth(index);
        const component=await strip.getAttribute('data-sm-node')??await strip.getAttribute('data-sm-scroll-reference');
        if(!await strip.isVisible()){
            checks.push({component,visible:false});
            continue;
        }
        const state=()=>strip.evaluate(element=>{
            const box=element.getBoundingClientRect(),style=getComputedStyle(element);
            const cards=[...element.children].filter(card=>card.matches('.wp-block-group,[data-sm-scroll-card]'));
            const fit=(rect)=>rect.left>=box.left-2&&rect.right<=box.right+2;
            return {scroll:element.scrollLeft,max:element.scrollWidth-element.clientWidth,
                cards:cards.map(card=>card.innerText.replace(/\s+/gu,' ').trim()),
                visible:cards.flatMap((card,i)=>fit(card.getBoundingClientRect())?[i]:[]),
                layout:element.tabIndex===0&&element.getAttribute('role')==='group'&&!!element.getAttribute('aria-label')?.trim()&&
                    style.direction==='ltr'&&style.overflowX==='auto'&&
                    style.scrollSnapType==='x mandatory'&&style.columnGap==='12px'&&style.paddingInlineEnd==='20px'&&
                    box.left>=-2&&box.right<=innerWidth+2&&document.documentElement.scrollWidth<=innerWidth&&
                    cards.length===element.children.length&&cards.every(card=>getComputedStyle(card).scrollSnapAlign==='start'&&
                        Math.abs(card.getBoundingClientRect().width-(element.clientWidth-20)*.8)<=2),
                focusVisible:document.activeElement===element&&element.matches(':focus-visible')&&
                    style.outlineStyle!=='none'&&parseFloat(style.outlineWidth)>=2};
        });
        const before=await state();
        const cards=before.cards.length;
        const initial=before.scroll===0&&before.visible.includes(0);
        const gesture=async action=>{
            const offset=()=>strip.evaluate(element=>element.scrollLeft);
            const start=await offset();
            await action();
            let last=start,stable=0;
            const deadline=Date.now()+1200;
            // Poll in the trusted worker so the document keeps script execution disabled.
            while(Date.now()<deadline&&stable<3){
                await page.waitForTimeout(60);
                const current=await offset();
                stable=current===last&&current!==start?stable+1:0;
                last=current;
            }
            return state();
        };
        let wheel=false,keyboard=false,end=false,focusVisible=false;
        const keyboardOffsets=[];
        if(before.layout&&cards>=2&&cards<=6&&before.max>4){
            try{
                await strip.scrollIntoViewIfNeeded({timeout:1500});
                const box=await strip.boundingBox();
                await page.mouse.move(box.x+box.width/2,Math.max(4,Math.min(HEIGHT-4,box.y+box.height/2)));
                const afterWheel=await gesture(()=>page.mouse.wheel(Math.ceil(box.width*.82),0));
                wheel=afterWheel.scroll>0&&afterWheel.visible.includes(1);
                // Keyboard reachability starts from a fresh initial state,
                // separate from Chromium's still-settling wheel scroll.
                await page.setContent(html,{waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                const atStart=await state();
                const reached=new Set(atStart.visible);
                for(let key=0;key<cards&&reached.size<cards;key++){
                    const after=await gesture(()=>strip.press('ArrowRight',{timeout:1500}));
                    keyboardOffsets.push(after.scroll);
                    after.visible.forEach(card=>reached.add(card));
                    focusVisible=after.focusVisible;
                    end=after.visible.includes(cards-1)&&Math.abs(after.scroll-after.max)<=2;
                }
                keyboard=atStart.scroll===0&&reached.size===cards&&end;
            }catch(_){keyboard=false;}
        }
        const copySha256=createHash('sha256').update(JSON.stringify(before.cards)).digest('hex');
        checks.push({component,visible:true,cards,copySha256,layout:before.layout,initial,wheel,keyboard,end,focusVisible,keyboardOffsets});
    }
    return checks;
}

async function checkDisclosures(page) {
    const groups=page.locator('.wp-block-group.sm-disclosure-group,[data-sm-disclosure-reference]');
    const count=await groups.count();
    if(count>4)throw new RendererError('evidence_limit',507);
    const checks=[];
    for(let index=0;index<count;index++){
        const group=groups.nth(index);
        const component=await group.evaluate(el=>el.getAttribute('data-sm-disclosure-reference')??
            el.getAttribute('data-sm-node')??[...el.classList].find(name=>name.startsWith('sm-disclosure-id-'))?.slice(17)??null);
        const state=()=>group.evaluate(element=>{
            const normalize=value=>value.replace(/\s+/gu,' ').trim();
            const rows=[...element.children];
            const name=rows[0]?.getAttribute('name');
            const summaries=rows.map(row=>row.querySelector(':scope > summary'));
            const answers=rows.map(row=>row.querySelector(':scope > p'));
            const indicator=summaries.map(summary=>summary&&getComputedStyle(summary,'::after'));
            const focused=summaries.indexOf(document.activeElement);
            const focusStyle=focused>=0?getComputedStyle(summaries[focused]):null;
            return { open:rows.flatMap((row,i)=>row.open?[i]:[]),
                visible:answers.flatMap((answer,i)=>answer?.checkVisibility({contentVisibilityAuto:true})&&
                    answer.getBoundingClientRect().height>0?[i]:[]),
                copy:summaries.map((summary,i)=>({summary:normalize(summary?.textContent??''),answer:normalize(answers[i]?.textContent??'')})),
                structure:!!name&&rows.length>=2&&rows.length<=8&&rows.every((row,i)=>row.matches('details')&&
                    row.getAttribute('name')===name&&row.firstElementChild===summaries[i]&&row.children.length===2&&
                    answers[i]&&summaries[i]?.tabIndex>=0)&&
                    [...document.querySelectorAll('details[name]')].filter(row=>row.getAttribute('name')===name).length===rows.length,
                indicator:indicator.every((style,i)=>style&&style.width==='20px'&&style.height==='20px'&&
                    style.clipPath.startsWith('polygon(')&&(style.transform!=='none')===rows[i].open),
                focusVisible:focused>=0&&summaries[focused].matches(':focus-visible')&&
                    focusStyle.outlineStyle==='solid'&&parseFloat(focusStyle.outlineWidth)>=2 };
        });
        const snapshotOthers=()=>groups.evaluateAll((items,current)=>items.filter((_,i)=>i!==current).map(item=>item.innerHTML),index);
        const original=await snapshotOthers();
        const before=await state();
        const matches=(state,index)=>JSON.stringify(state.open)===JSON.stringify(index===null?[]:[index])&&
            JSON.stringify(state.visible)===JSON.stringify(state.open)&&state.indicator;
        let clicks=false,keyboard=false;
        if(before.structure&&matches(before,0)){
            const controls=group.locator(':scope > details > summary');
            try{
                clicks=true;
                for(let panel=1;panel<before.copy.length;panel++){
                    await controls.nth(panel).click({timeout:1500});
                    clicks=matches(await state(),panel)&&clicks;
                }
                await controls.first().click({timeout:1500});
                clicks=matches(await state(),0)&&clicks;
                await controls.first().click({timeout:1500});
                clicks=matches(await state(),null)&&clicks;
                await controls.nth(1).press('Enter',{timeout:1500});
                const entered=await state();
                await controls.nth(1).press('Space',{timeout:1500});
                const closed=await state();
                keyboard=matches(entered,1)&&matches(closed,null)&&entered.focusVisible&&closed.focusVisible;
                await controls.first().press('Enter',{timeout:1500});
            }catch(_){clicks=false;keyboard=false;}
        }
        checks.push({component,copy:before.copy,structure:before.structure,initial:matches(before,0),indicator:before.indicator,
            clicks,keyboard,isolation:JSON.stringify(original)===JSON.stringify(await snapshotOthers())});
    }
    return checks;
}

const input = [];
process.stdin.on('data', (chunk) => input.push(chunk));
process.stdin.on('end', async () => {
    let browser;
    let crashed = false;
    const evidence = [];
    try {
        const { html, inspect, extent, sourceFrames, tabsRuntime, scrollStrips, disclosures, imageStrip, revealCards, roomsRuntime,
            compactBooking, header, offer, viewports = DEFAULT_VIEWPORTS } = JSON.parse(Buffer.concat(input).toString('utf8'));
        browser = await chromium.launch({
            headless: true, timeout: 10000,
            // Synthetic fixtures are fulfilled by Playwright. Other Chromium
            // traffic, including implicit loopback bypass and WebRTC UDP, stays blocked.
            args: [
                '--no-sandbox', '--disable-dev-shm-usage',
                '--proxy-server=http://127.0.0.1:1', '--proxy-bypass-list=<-loopback>',
                '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
            ],
        });
        const context = await browser.newContext({
            javaScriptEnabled: !inspect || Boolean(tabsRuntime || roomsRuntime || header || offer),
            serviceWorkers: 'block',
            acceptDownloads: false,
        });
        // Completely deny external scripts, stylesheets, images and API calls.
        // This preview only supports inline scripts and inline styles.
        await context.route('**/*', (route) => route.abort('blockedbyclient'));
        await context.routeWebSocket(/.*/,socket=>socket.close({code:1008,reason:'Offline verification'}));
        const page = await context.newPage();
        page.on('crash', () => { crashed = true; });
        page.on('popup', (popup) => popup.close().catch(() => {}));
        if(header||offer){
            const fixture='https://functional.invalid/';
            const name=header?'header':'offer';
            const document=fixture+name, script=document+'.js';
            await page.route(document,route=>route.fulfill({contentType:'text/html;charset=utf-8',
                headers:{'content-security-policy':"default-src 'none'; script-src "+script+"; style-src 'unsafe-inline'; img-src data:; font-src data:; base-uri 'none'; form-action 'none'"},
                body:'<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">'+html+
                    '<style>html,body,#syndamorph-page{margin:0;min-height:3900px}</style><script src="/'+name+'.js"></script>'}));
            await page.route(script,route=>route.fulfill({contentType:'application/javascript',body:(header??offer).runtime}));
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                const other=[];
                const log=request=>{if(![document,script].includes(request.url()))other.push(true);};
                page.on('request',log);
                try{
                    await page.goto(document,{waitUntil:'domcontentloaded',timeout:5000});
                    await waitForInstalledFonts(page);
                    const proof=header?await checkHeader(page,width,header.frames[width]):await checkOffer(page,width,offer.frame);
                    evidence.push({width,proof:{...proof,offline:other.length===0&&page.url()===document}});
                }finally{page.off('request',log);}
            }
            process.stdout.write(JSON.stringify({evidence}));
            return;
        }
        if(compactBooking){
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                await page.setContent('<!doctype html><style>html,body{margin:0;min-height:3900px}</style>'+html,
                    {waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                await page.evaluate(()=>window.scrollTo({top:0,behavior:'instant'}));
                evidence.push({width,proof:await checkCompactBooking(page,width)});
            }
            process.stdout.write(JSON.stringify({evidence}));
            return;
        }
        if(scrollStrips||disclosures||imageStrip||revealCards){
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                await page.setContent(html,{waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                if(imageStrip)evidence.push({width,proof:await checkImageStrip(page)});
                else if(revealCards)evidence.push({width,proof:await checkRevealCards(page)});
                else evidence.push({width,checks:disclosures?await checkDisclosures(page):await checkScrollStrips(page,html)});
            }
            process.stdout.write(JSON.stringify({evidence}));
            return;
        }
        if(roomsRuntime){
            const fixture='https://functional.invalid/';
            await page.route(fixture+'rooms',route=>route.fulfill({contentType:'text/html;charset=utf-8',
                headers:{'content-security-policy':"default-src 'none'; script-src https://functional.invalid/rooms.js; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"},
                body:'<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">'+html+'<script src="/rooms.js"></script>'}));
            await page.route(fixture+'rooms.js',route=>route.fulfill({contentType:'application/javascript',body:roomsRuntime}));
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                await page.emulateMedia({reducedMotion:'no-preference'});
                await page.goto(fixture+'rooms',{waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                evidence.push({width,proof:await checkRooms(page,width)});
            }
            process.stdout.write(JSON.stringify({evidence}));
            return;
        }
        if(tabsRuntime){
            const fixture = 'https://functional.invalid/';
            await page.route(fixture+'document',route=>route.fulfill({contentType:'text/html;charset=utf-8',
                headers:{'content-security-policy':"default-src 'none'; script-src https://functional.invalid/tabs-runtime.js; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'"},
                body:'<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">'+html+
                    '<script src="/tabs-runtime.js"></script>'}));
            await page.route(fixture+'tabs-runtime.js',route=>route.fulfill({
                contentType:'application/javascript',body:tabsRuntime}));
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                await page.goto(fixture+'document',{waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                const widgets=page.locator('[data-sm-tabs]');
                const count=await widgets.count();
                if(count>12)throw new RendererError('evidence_limit',507);
                const checks=[];
                for(let i=0;i<count;i++){
                    const widget=widgets.nth(i);
                    const component=await widget.getAttribute('data-sm-node');
                    const controls=widget.locator(':scope > [role="tablist"] > [role="tab"]');
                    const panels=widget.locator(':scope > [role="tabpanel"]');
                    const total=await controls.count();
                    const state=()=>widget.evaluate(el=>{
                        const tabs=[...el.querySelectorAll(':scope > [role="tablist"] > [role="tab"]')];
                        const listStyle=getComputedStyle(tabs[0].parentElement);
                        const selectedTab=tabs.find(tab=>tab.getAttribute('aria-selected')==='true');
                        const selectedStyle=selectedTab?getComputedStyle(selectedTab):null;
                        const focused=tabs.indexOf(document.activeElement);
                        const focusStyle=focused>=0?getComputedStyle(tabs[focused]):null;
                        const select=tabs.map((tab,index)=>tab.getAttribute('aria-selected')==='true'?index:-1).filter(index=>index>=0);
                        const targets=tabs.map(tab=>document.getElementById(tab.getAttribute('aria-controls')));
                        const visible=targets.map((panel,index)=>panel&&panel.parentElement===el&&!panel.hidden&&
                            panel.getClientRects().length?index:-1).filter(index=>index>=0);
                        return {select,visible,tabStops:tabs.map((tab,index)=>tab.tabIndex===0?index:-1).filter(index=>index>=0),
                            structure:new Set(targets).size===tabs.length&&tabs.every((tab,index)=>{
                                const panel=targets[index];
                                return tab.id&&tab.textContent.trim()&&panel?.parentElement===el&&
                                    panel.getAttribute('aria-labelledby')===tab.id&&panel.getAttribute('role')==='tabpanel';
                            }),
                            copy:tabs.map((tab,index)=>({label:tab.textContent,
                                heading:targets[index]?.querySelector('h3')?.textContent,
                                body:targets[index]?.querySelector('p')?.textContent})),
                            focused,
                            focusVisible:focused>=0&&tabs[focused].matches(':focus-visible')&&
                                focusStyle.outlineStyle==='solid'&&parseFloat(focusStyle.outlineWidth)>=2&&
                                focusStyle.outlineColor!=='rgba(0, 0, 0, 0)',
                            nativeStyle:listStyle.display==='flex'&&listStyle.flexWrap==='wrap'&&
                                tabs.every(tab=>tab.getBoundingClientRect().height>=44)&&
                                selectedStyle?.borderBottomStyle==='solid'&&selectedStyle.borderBottomWidth==='2px'&&
                                selectedStyle.borderBottomColor===selectedStyle.color};
                    });
                    const only=(indices,index)=>indices.length===1&&indices[0]===index;
                    const correct=(value,index)=>only(value.select,index)&&only(value.visible,index)&&only(value.tabStops,index);
                    if(total<2||total>6||await panels.count()!==total){
                        checks.push({component,total,initial:false,click:false,keyboard:false});
                        continue;
                    }
                    const untouched=await widgets.evaluateAll((items,current)=>items.filter((_,index)=>index!==current)
                        .map(item=>item.innerHTML),i);
                    const before=await state();
                    let click=true;
                    let keyboard=true;
                    let focusVisible=false;
                    try{
                        for(let index=1;index<total;index++){
                            await controls.nth(index).click({timeout:1500});
                            const after=await state();
                            click=click&&correct(after,index);
                        }
                        await controls.last().press('Home',{timeout:1500});
                        const home=await state();
                        const direction=await controls.first().evaluate(tab=>getComputedStyle(tab.parentElement).direction);
                        await controls.first().press(direction==='rtl'?'ArrowLeft':'ArrowRight',{timeout:1500});
                        const arrow=await state();
                        keyboard=correct(home,0)&&home.focused===0&&correct(arrow,1)&&arrow.focused===1;
                        focusVisible=home.focusVisible&&arrow.focusVisible;
                        await controls.nth(1).press('Home',{timeout:1500});
                    }catch(_){
                        click=false;
                        keyboard=false;
                    }
                    const others=await widgets.evaluateAll((items,current)=>items.filter((_,index)=>index!==current)
                        .map(item=>item.innerHTML),i);
                    checks.push({component,total,initial:correct(before,0),structure:before.structure,copy:before.copy,
                        click,keyboard,focusVisible,nativeStyle:before.nativeStyle,isolation:JSON.stringify(untouched)===JSON.stringify(others)});
                }
                evidence.push({width,checks});
            }
            process.stdout.write(JSON.stringify({evidence}));
            return;
        }
        await page.setContent(html, { waitUntil: 'domcontentloaded', timeout: 5000 });
        await page.waitForTimeout(600);
        if (inspect) {
            // Both inputs are embeddable fragments. Remove the UA's body
            // margin without overriding a more specific authored style.
            await page.evaluate(() => {
                const frameStyle = document.createElement('style');
                frameStyle.textContent = ':where(body){margin:0}';
                document.head.prepend(frameStyle);
            });
            // Render untrusted input with JS disabled. Run axe separately on a
            // sanitized DOM copy, never on the original script-bearing document.
            const safeMarkup = await page.evaluate(() => {
                const doc = document.documentElement.cloneNode(true);
                doc.querySelectorAll('script,iframe,object,embed,form,link,base,meta[http-equiv]').forEach(el=>el.remove());
                doc.querySelectorAll('*').forEach(el => {
                    for(const attr of [...el.attributes]) {
                        if(/^on/i.test(attr.name) || /^(?:srcdoc|formaction|action)$/i.test(attr.name) ||
                            (/^(?:href|src)$/i.test(attr.name) && /^(?:javascript:|data:text\/html)/i.test(attr.value)))
                            el.removeAttribute(attr.name);
                    }
                });
                return '<!doctype html>' + doc.outerHTML;
            });
            const a11yContext = await browser.newContext({
                javaScriptEnabled:true, serviceWorkers:'block', acceptDownloads:false
            });
            await a11yContext.route('**/*', route=>route.abort('blockedbyclient'));
            const a11yPage = await a11yContext.newPage();
            a11yPage.on('crash', () => { crashed = true; });
            await a11yPage.setContent(safeMarkup, {waitUntil:'domcontentloaded',timeout:5000});
            await a11yPage.evaluate(axe.source);
            for (const width of viewports) {
                await page.setViewportSize({ width, height: HEIGHT });
                await waitForInstalledFonts(page);
                const snapshot = await page.evaluate(async includeLiteral => {
                    const elements = [...document.body.querySelectorAll('*')].filter(el => !['STYLE','SCRIPT'].includes(el.tagName));
                    const positions = new Map(elements.map((el, index) => [el, index]));
                    const rect = r => [r.x, r.y, r.width, r.height].map(value => Math.round(value * 10) / 10);
                    // Compare literal authored copy, including inline formatting and
                    // long text, independently of geometry and text-transform.
                    const copyParts = [];
                    function gatherCopy(node,parts=copyParts) {
                        if (node.nodeType === Node.TEXT_NODE) {
                            parts.push(node.nodeValue);
                            return;
                        }
                        if (node.nodeType !== Node.ELEMENT_NODE ||
                            /^(?:STYLE|SCRIPT|NOSCRIPT|TEMPLATE|SVG)$/.test(node.tagName)) return;
                        const { display, visibility } = getComputedStyle(node);
                        if (display === 'none') return;
                        const boundary = node.tagName === 'BR' || !/^(?:inline|contents)/.test(display);
                        if (boundary) parts.push(' ');
                        if(node.localName==='details'&&!node.open){
                            const summary=node.querySelector(':scope > summary');
                            if(summary)gatherCopy(summary,parts);
                            if(boundary)parts.push(' ');
                            return;
                        }
                        for (const child of node.childNodes) {
                            if (child.nodeType !== Node.TEXT_NODE || visibility === 'visible') gatherCopy(child,parts);
                        }
                        if (boundary) parts.push(' ');
                    }
                    gatherCopy(document.body);
                    const targets=document.querySelectorAll('[data-sm-host-target]');
                    const hostAccessibility=targets.length===1?(()=>{
                        const target=targets[0];
                        const title=document.querySelector('[data-sm-host-title]');
                        const visible=el=>el.getClientRects().length>0&&el.checkVisibility({opacityProperty:true,visibilityProperty:true});
                        const depth=el=>Number(el.getAttribute('aria-level')||el.tagName.slice(1));
                        const levels=[...target.querySelectorAll('h1,h2,h3,h4,h5,h6,[role=heading]')].filter(visible).map(depth);
                        const candidates=[...target.querySelectorAll('*')].filter(el=>visible(el)&&
                            [...el.childNodes].some(node=>node.nodeType===Node.TEXT_NODE&&node.textContent.trim()));
                        const rgb=color=>color.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/)?.slice(1).map(Number);
                        const brightness=channels=>channels.map(value=>value/255).map(value=>value<=.04045?
                            value/12.92:((value+.055)/1.055)**2.4).reduce((sum,value,i)=>sum+value*[.2126,.7152,.0722][i],0);
                        let unresolved=0,minimum=null;
                        for(const el of candidates){
                            const foreground=rgb(getComputedStyle(el).color);
                            let background=null;
                            for(let ancestor=el;ancestor;ancestor=ancestor.parentElement){
                                const css=getComputedStyle(ancestor);
                                if(css.opacity!=='1'||css.mixBlendMode!=='normal'||css.filter!=='none'){
                                    background=null;
                                    break;
                                }
                                if(background)continue;
                                if(css.backgroundImage!=='none')break;
                                background=rgb(css.backgroundColor);
                                if(!background&&css.backgroundColor!=='rgba(0, 0, 0, 0)')break;
                            }
                            if(!foreground||!background){unresolved++;continue;}
                            const values=[brightness(foreground),brightness(background)].sort((a,b)=>b-a);
                            const ratio=(values[0]+.05)/(values[1]+.05);
                            minimum=minimum===null?ratio:Math.min(ratio,minimum);
                        }
                        return {titleLevel:title&&visible(title)?depth(title):null,levels,textRuns:candidates.length,
                            unresolved,minContrast:minimum};
                    })():null;
                    const scrollIdentity=el=>{
                        const id=el.getAttribute('data-sm-scroll-reference')??
                            (el.matches('.wp-block-group.sm-scroll-strip')?el.getAttribute('data-sm-node'):null);
                        return id&&id.length<=48?id:null;
                    };
                    const literal = includeLiteral ? await (async () => {
                        const normalize = value => value.replace(/\s+/gu, ' ').trim();
                        const visible = new Set([document.body, ...elements].filter(el => {
                            const rect = el.getBoundingClientRect(), style = getComputedStyle(el);
                            const closed=el.closest('details:not([open])');
                            return rect.width > 0 && rect.height > 0 && !el.closest('[hidden]') &&
                                (!closed||closed===el||closed.querySelector(':scope > summary')?.contains(el))&&
                                !['hidden', 'collapse'].includes(style.visibility) && style.display !== 'none';
                        }));
                        const text = [];
                        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                        while (walker.nextNode()) {
                            const value = normalize(walker.currentNode.nodeValue);
                            if (value && visible.has(walker.currentNode.parentElement)) text.push(value);
                        }
                        const links = Array.from(visible).filter(el => el.matches('a[href]')).map(el =>
                            ({ href: el.getAttribute('href'), text: normalize(el.innerText || el.getAttribute('aria-label') || '') }));
                        const assets = Array.from(visible).filter(el => el.matches('img,svg,video,audio,iframe,object,embed,canvas') ||
                            getComputedStyle(el).backgroundImage.includes('url(')).length;
                        const originals = [...visible].filter(el => el.matches('img[data-sm-source-image]') &&
                            el.checkVisibility({opacityProperty:true,visibilityProperty:true}));
                        let originalImage = null;
                        if (originals.length === 1) {
                            const [image] = originals;
                            const match = /^data:image\/png;base64,([A-Za-z0-9+/=]{1,699052})$/.exec(image.currentSrc);
                            if (match && await image.decode().then(() => true, () => false)) {
                                const style = getComputedStyle(image);
                                originalImage = {base64:match[1],alt:image.alt.slice(0,241),
                                    pixels:[image.naturalWidth,image.naturalHeight],filter:style.filter,blend:style.mixBlendMode};
                            }
                        }
                        const controls = Array.from(visible).filter(el => el.matches('form,input,select,textarea,button,details,dialog,[role=tab],[role=button]')).length;
                        const scrollAreas = [...visible].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowX)&&
                            el.clientWidth>0&&el.scrollWidth-el.clientWidth>4);
                        const scrollStrips = scrollAreas.slice(0,5).map(el=>{
                            const id=scrollIdentity(el);
                            const cards=[...el.children];
                            if(!id||cards.length<2||cards.length>6||
                                !cards.every(card=>visible.has(card)&&card.matches('.wp-block-group,[data-sm-scroll-card]')))return null;
                            const copy=cards.map(card=>normalize(card.innerText));
                            return copy.some(item=>item.length>4000)?null:{id,copy};
                        });
                        const disclosures=[...visible].filter(el=>el.matches('.sm-disclosure-group,[data-sm-disclosure-reference]'))
                            .slice(0,5).map(group=>{
                                const id=group.getAttribute('data-sm-disclosure-reference')??group.getAttribute('data-sm-node')??
                                    [...group.classList].find(name=>name.startsWith('sm-disclosure-id-'))?.slice(17);
                                const rows=[...group.children];
                                if(!id||id.length>48||rows.length<2||rows.length>8||
                                    rows.some(row=>!row.matches('details')||row.children.length!==2||
                                        !row.firstElementChild.matches('summary')||!row.lastElementChild.matches('p')))return null;
                                const copy=rows.map(row=>({summary:normalize(row.firstElementChild.textContent),
                                    answer:normalize(row.lastElementChild.textContent)}));
                                return copy.some(panel=>panel.summary.length>240||panel.answer.length>1600)?null:
                                    {id,initial:rows.flatMap((row,i)=>row.open?[i]:[]),panels:copy};
                            });
                        const visibleElements = elements.filter(el => visible.has(el));
                        const paint = [...new Set(visibleElements.map(el => {
                            const css = getComputedStyle(el);
                            return css.backgroundColor === 'rgba(0, 0, 0, 0)' && css.backgroundImage === 'none'
                                ? null : css.backgroundColor + '|' + css.backgroundImage;
                        }).filter(Boolean))].sort();
                        const textStyles = visibleElements.filter(el => el.matches('h1,h2,h3,h4,h5,h6,p')).map(el => {
                            const css = getComputedStyle(el);
                            return [el.localName, normalize(el.textContent).slice(0, 250), css.fontFamily, css.fontWeight, css.color];
                        });
                        const layoutIdentity = visibleElements.length <= 200 && textStyles.length <= 20 &&
                            paint.length <= 32 && paint.every(value => value.length <= 240) ? {paint,textStyles} : null;
                        return { text, links, assets, controls, layoutIdentity, scrollStrips,
                            ...(originalImage ? {originalImage} : {}),...(disclosures.length?{disclosures}:{}) };
                    })() : null;
                    return {
                        copy: copyParts.join('').replace(/\s+/gu, ' ').trim(),
                        overflow: document.documentElement.scrollWidth > innerWidth,
                        height: document.documentElement.scrollHeight,
                        elementCount: elements.length,
                        ...(literal ? { literal } : {}),
                        ...(hostAccessibility?{hostAccessibility}:{}),
                        elements: elements.slice(0, 200).map(el => {
                            const r = el.getBoundingClientRect(), s = getComputedStyle(el);
                            const text = el.children.length ? '' : el.textContent.replace(/\s+/gu, ' ').trim().slice(0, 250);
                            const textParts=[];
                            if((el.children.length||el.textContent.length>250)&&
                                el.matches('p,h1,h2,h3,h4,h5,h6,a,button,label,li,summary'))gatherCopy(el,textParts);
                            const checkText=textParts.length?textParts.join('').replace(/\s+/gu,' ').trim():null;
                            const checkCharacters=checkText!==null&&checkText!==text?Array.from(checkText):null;
                            const range = document.createRange();
                            if (text) range.selectNodeContents(el);
                            const lines = text ? [...range.getClientRects()].slice(0, 12).map(rect) : [];
                            const wrapStyle = s.textWrapStyle;
                            let wrapChangesGeometry = false;
                            if (lines.length > 1 && ['balance','pretty'].includes(wrapStyle)) {
                                const old = el.style.getPropertyValue('text-wrap-style');
                                const priority = el.style.getPropertyPriority('text-wrap-style');
                                try {
                                    el.style.setProperty('text-wrap-style', 'auto', 'important');
                                    const natural = [...range.getClientRects()].slice(0, 12).map(rect);
                                    wrapChangesGeometry = JSON.stringify(lines) !== JSON.stringify(natural);
                                } finally {
                                    if (old) el.style.setProperty('text-wrap-style', old, priority);
                                    else el.style.removeProperty('text-wrap-style');
                                }
                            }
                            const id = el.getAttribute('data-sm-node') || (el.tagName === 'A' ? el.parentElement?.getAttribute('data-sm-node') : null);
                            const href=el.tagName==='A'?el.getAttribute('href'):null;
                            let fragmentTarget=null;
                            if(href?.startsWith('#')&&href.length>1){
                                try { fragmentTarget=document.getElementById(decodeURIComponent(href.slice(1)))!==null; }
                                catch { fragmentTarget=false; }
                            }
                            const textBox=text?range.getBoundingClientRect():null;
                            const strip=textBox?el.closest('.wp-block-group.sm-scroll-strip,[data-sm-scroll-reference]'):null;
                            let scrollContained=false;
                            if(strip&&strip!==el&&strip.tabIndex===0&&getComputedStyle(strip).overflowX==='auto'&&
                                strip.scrollWidth>strip.clientWidth+4){
                                let card=el;
                                let innerClip=false;
                                for(let parent=el;parent!==strip;parent=parent.parentElement){
                                    card=parent;
                                    if(['clip','hidden'].includes(getComputedStyle(parent).overflowX)){
                                        const bounds=parent.getBoundingClientRect();
                                        if(textBox.left<bounds.left-2||textBox.right>bounds.right+2)innerClip=true;
                                    }
                                }
                                const cardBox=card.getBoundingClientRect(),stripBox=strip.getBoundingClientRect();
                                scrollContained=!innerClip&&card.matches('.wp-block-group,[data-sm-scroll-card]')&&
                                    getComputedStyle(card).scrollSnapAlign==='start'&&stripBox.left>=-2&&stripBox.right<=innerWidth+2&&
                                    cardBox.width<=strip.clientWidth+2&&textBox.left>=cardBox.left-2&&textBox.right<=cardBox.right+2;
                            }
                            if(textBox&&el.matches('.sm-rooms-slide-label,.sm-rooms-chip')){
                                const viewport=el.closest('.sm-rooms-carousel,.sm-rooms-strip');
                                const item=el.closest('.sm-rooms-slide')||el;
                                if(viewport&&viewport.closest('.sm-rooms-layout')){
                                    const area=viewport.getBoundingClientRect(),bounds=item.getBoundingClientRect();
                                    const overflow=getComputedStyle(viewport).overflowX;
                                    scrollContained=area.left>=-2&&area.right<=innerWidth+2&&bounds.width<=viewport.clientWidth+2&&
                                        textBox.left>=bounds.left-2&&textBox.right<=bounds.right+2&&
                                        (viewport.matches('.sm-rooms-carousel')?overflow==='hidden':
                                            overflow==='auto'&&viewport.tabIndex===0);
                                }
                            }
                            const backgroundImage=s.backgroundImage.replace(/url\([^)]*\)/g,'url([asset])');
                            const border=parseFloat(s.borderTopWidth)>0?{
                                borderTopWidth:s.borderTopWidth,borderTopStyle:s.borderTopStyle,borderTopColor:s.borderTopColor,
                            }:{};
                            const localHorizontalScroll=r.width>0&&r.height>0&&['auto','scroll'].includes(s.overflowX)&&
                                el.scrollWidth>el.clientWidth+4;
                            return { tag: el.tagName.slice(0, 32), id: el.id.slice(0, 128), text: el.children.length ? '' : el.textContent.slice(0, 250),
                                node: id?.slice(0, 128) ?? null,
                                classes:[...el.classList].slice(0,12).map(name=>name.slice(0,64)),
                                visible:r.width>0&&r.height>0&&el.checkVisibility({opacityProperty:true,visibilityProperty:true}),
                                role:el.getAttribute('role')?.slice(0,80)??null,
                                name:el.getAttribute('aria-label')?.slice(0,128)??null,
                                href:href?.slice(0,300)??null,fragmentTarget,
                                keyboardReachable:el.tabIndex>=0&&!el.matches(':disabled')&&!el.closest('[inert]'),
                                parent: positions.get(el.parentElement) ?? null,
                                normalizedText: text,
                                ...(checkCharacters?{
                                    checkText:checkCharacters.slice(0,1000).join(''),checkTextTruncated:checkCharacters.length>1000,
                                }:{}),
                                rect: rect(r),
                                textRect:textBox?rect(textBox):null,
                                ...(scrollContained?{scrollContained:true}:{}),
                                ...(localHorizontalScroll?{localHorizontalScroll:true,
                                    scrollReference:scrollIdentity(el)}:{}),
                                lineRects: lines,
                                wrapChangesGeometry,
                                styles: { ...Object.fromEntries(['color','backgroundColor','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','maxWidth','overflowWrap','padding','margin','gap','display','textAlign','textTransform','textWrapStyle','alignItems','justifyContent','borderRadius'].map(k => [k,s[k].slice(0, 128)])),
                                    backgroundImage:backgroundImage.slice(0,240),...border },
                                widthValue: el.computedStyleMap().get('width')?.toString().slice(0,128) ?? null,
                                clipped: /^(?:hidden|clip)$/.test(s.overflowX) && el.scrollWidth > el.clientWidth + 2,
                                missingAlt: el.tagName === 'IMG' && !el.hasAttribute('alt'),
                                unnamedControl: ['A','BUTTON'].includes(el.tagName) && !(el.textContent.trim() || el.getAttribute('aria-label') || el.querySelector('img[alt]:not([alt=""])'))
                            };
                        })
                    };
                }, extent === 'section');
                const fontFrame=await page.evaluate(()=>{
                    const style=document.querySelector('style[data-sm-installed-fonts]');
                    if(!style)return null;
                    const faces=[...document.fonts];
                    return {css:style.textContent,ready:!!style.sheet?.cssRules.length&&faces.length>0&&
                        faces.every(face=>face.status!=='error'),loaded:[...new Set(faces.filter(face=>face.status==='loaded')
                        .map(face=>face.family))].slice(0,12)};
                });
                if(fontFrame)snapshot.installedFonts={sha256:createHash('sha256').update(fontFrame.css).digest('hex'),
                    status:fontFrame.ready?'ready':'failed',loaded:fontFrame.loaded};
                await a11yPage.setViewportSize({width,height:HEIGHT});
                await waitForInstalledFonts(a11yPage);
                if(snapshot.literal) snapshot.literal.scrollStrips=snapshot.literal.scrollStrips.map(strip=>strip===null?null:{
                    id:strip.id,cards:strip.copy.length,copySha256:createHash('sha256').update(JSON.stringify(strip.copy)).digest('hex'),
                });
                if (snapshot.literal?.originalImage) {
                    const {base64, ...facts} = snapshot.literal.originalImage;
                    snapshot.literal.originalImage = {...facts,sha256:createHash('sha256').update(Buffer.from(base64,'base64')).digest('hex')};
                }
                const a11y = await a11yPage.evaluate(async () => {
                    const report=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});
                    return report.violations.map(v=>({id:v.id,impact:v.impact,description:v.description,
                        nodes:v.nodes.slice(0,8).map(n=>({
                            target:n.target.slice(0,8).map(selector=>(typeof selector==='string'?selector:JSON.stringify(selector)).slice(0,256)),
                            summary:n.failureSummary?.slice(0,600),
                        }))}));
                });
                if (Buffer.byteLength(JSON.stringify({ width, snapshot, a11y })) > MAX_METADATA)
                    throw new RendererError('evidence_limit', 507);
                const top=sourceFrames?.[width]??null;
                const height = top!==null ? await page.evaluate(()=>{
                    const first=[...document.body.children].find(el=>!['STYLE','SCRIPT','TEMPLATE'].includes(el.tagName));
                    return Math.max(1,Math.round(first?.getBoundingClientRect().bottom??0));
                }) : extent === 'section'
                    ? Math.max(1, Math.ceil((snapshot.elements[0]?.rect[1] || 0) + (snapshot.elements[0]?.rect[3] || 0)))
                    : extent === 'page' ? snapshot.height : null;
                if (height > 36000) throw new RendererError('screenshot_limit', 507);
                const screenshot = top===null ? await boundedScreenshot(page, width, height) :
                    await sourceFrameScreenshot(page,width,height,top);
                evidence.push({width,snapshot,screenshot,a11y,...(height === null ? {} : { extent: height }),
                    ...(top===null?{}:{sourceFrame:{top,screenshotTop:Math.round(top)}})});
            }
            process.stdout.write(JSON.stringify({ evidence }));
            return;
        }
        const snapshot = await page.evaluate(() => {
            document.querySelectorAll('script, iframe, object, embed').forEach((el) => el.remove());
            return '<!doctype html>\n' + document.documentElement.outerHTML;
        });
        process.stdout.write(JSON.stringify({ html: snapshot }));
    } catch (error) {
        const category = error instanceof RendererError ? error.code :
            error.name === 'TimeoutError' ? 'browser_timeout' :
                (crashed || !browser || !browser.isConnected()) ? 'browser_crash' : 'worker_failure';
        if (evidence.length && ['browser_timeout','browser_crash'].includes(category))
            process.stdout.write(JSON.stringify({ evidence }));
        else process.stdout.write(JSON.stringify({ error: category }));
    } finally {
        if (browser) { await browser.close().catch(() => {}); }
    }
});
