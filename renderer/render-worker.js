'use strict';

const { chromium } = require('playwright');
const { createHash } = require('node:crypto');
const axe = require('axe-core');
const { HEIGHT, MAX_JPEG, MAX_METADATA, DEFAULT_VIEWPORTS, RendererError } = require('./inspection-contract');

async function waitForInstalledFonts(page) {
    await page.evaluate(() => {
        if(!document.querySelector('style[data-sm-installed-fonts]'))return true;
        document.body.getBoundingClientRect();
        return document.fonts.ready.then(() => true);
    });
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

const input = [];
process.stdin.on('data', (chunk) => input.push(chunk));
process.stdin.on('end', async () => {
    let browser;
    let crashed = false;
    const evidence = [];
    try {
        const { html, inspect, extent, sourceFrames, tabsRuntime, scrollStrips, viewports = DEFAULT_VIEWPORTS } = JSON.parse(Buffer.concat(input).toString('utf8'));
        browser = await chromium.launch({
            headless: true, timeout: 10000,
            args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
        });
        const context = await browser.newContext({
            javaScriptEnabled: !inspect || Boolean(tabsRuntime),
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
        if(scrollStrips){
            for(const width of viewports){
                await page.setViewportSize({width,height:HEIGHT});
                await page.setContent(html,{waitUntil:'domcontentloaded',timeout:5000});
                await waitForInstalledFonts(page);
                evidence.push({width,checks:await checkScrollStrips(page,html)});
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
                const snapshot = await page.evaluate(includeLiteral => {
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
                    const literal = includeLiteral ? (() => {
                        const normalize = value => value.replace(/\s+/gu, ' ').trim();
                        const visible = new Set([document.body, ...elements].filter(el => {
                            const rect = el.getBoundingClientRect(), style = getComputedStyle(el);
                            return rect.width > 0 && rect.height > 0 && !el.closest('[hidden]') &&
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
                        const controls = Array.from(visible).filter(el => el.matches('form,input,select,textarea,button,details,dialog,[role=tab],[role=button]')).length;
                        const scrollAreas = [...visible].filter(el=>['auto','scroll'].includes(getComputedStyle(el).overflowX)&&
                            el.clientWidth>0&&el.scrollWidth-el.clientWidth>4);
                        const scrollStrips = scrollAreas.slice(0,5).map(el=>{
                            const id=el.getAttribute('data-sm-scroll-reference')??
                                (el.classList.contains('sm-scroll-strip')?el.getAttribute('data-sm-node'):null);
                            const cards=[...el.children];
                            if(!id||id.length>48||cards.length<2||cards.length>6||
                                !cards.every(card=>visible.has(card)&&card.matches('.wp-block-group,[data-sm-scroll-card]')))return null;
                            const copy=cards.map(card=>normalize(card.innerText));
                            return copy.some(item=>item.length>4000)?null:{id,copy};
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
                        return { text, links, assets, controls, layoutIdentity, scrollStrips };
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
                            const backgroundImage=s.backgroundImage.replace(/url\([^)]*\)/g,'url([asset])');
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
                                    scrollReference:el.getAttribute('data-sm-scroll-reference')?.slice(0,48)??null}:{}),
                                lineRects: lines,
                                wrapChangesGeometry,
                                styles: { ...Object.fromEntries(['color','backgroundColor','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','maxWidth','overflowWrap','padding','margin','gap','display','textAlign','textTransform','textWrapStyle','alignItems','justifyContent','borderRadius'].map(k => [k,s[k].slice(0, 128)])),
                                    backgroundImage:backgroundImage.slice(0,240) },
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
