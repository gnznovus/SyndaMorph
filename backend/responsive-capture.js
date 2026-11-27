'use strict';

const { createHash } = require('node:crypto');
const { touchTargetId } = require('./production-touch');
const VIEWPORTS = [1440, 768, 375];
const HEIGHT = 900;

// Read the original DOM. The existing manual extractor's static annotations
// are useful for display, but aren't a record of the source's behavior.
function sourceInventory({ selectors }) {
    const normalize = value => value.replace(/\s+/gu, ' ').trim();
    const visible = element => {
        const bounds = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return bounds.width > 0 && bounds.height > 0 && !element.closest('[hidden]') &&
            !['hidden', 'collapse'].includes(style.visibility) && style.display !== 'none';
    };
    const stripProfile = (element, style) => {
        const box = element.getBoundingClientRect();
        const cards = [...element.children];
        if (innerWidth >= 1024 || element === document.body || element === document.documentElement ||
            document.documentElement.scrollWidth > innerWidth || style.overflowX !== 'auto' || style.direction !== 'ltr' ||
            style.scrollSnapType !== 'x mandatory' || style.columnGap !== '12px' || style.paddingInlineEnd !== '20px' ||
            box.left < -2 || box.right > innerWidth + 2 || element.scrollLeft !== 0 || cards.length < 2 || cards.length > 6 ||
            element.closest('form,[inert],[aria-disabled="true"]') || !element.checkVisibility({opacityProperty:true,visibilityProperty:true}) ||
            !cards.every(card => visible(card) && getComputedStyle(card).scrollSnapAlign === 'start')) return null;
        const cardBounds = cards.map(card => card.getBoundingClientRect());
        if (!cardBounds.every((rect, index) => Math.abs(rect.width - (element.clientWidth - 20) * .8) <= 2 &&
            Math.abs(rect.left - (index ? cardBounds[index - 1].right + 12 : box.left)) <= 2)) return null;
        const cardCopy = cards.map(card => normalize(card.innerText));
        if (!cardCopy.some(Boolean) || cardCopy.some(copy => copy.length > 4000)) return null;
        const chain = [];
        for (let node = element; node !== document.body; node = node.parentElement) {
            const index = Array.prototype.indexOf.call(node.parentElement.children, node);
            chain.unshift(`:nth-child(${index + 1})`);
        }
        return { identity: { selector: ['body', ...chain].join(' > '), tag: element.localName,
            role: element.getAttribute('role') ?? '', name: normalize(element.getAttribute('aria-label') ||
                element.innerText || element.title || element.getAttribute('placeholder') || '').slice(0,120) }, cardCopy };
    };
    return selectors.map(selector => {
        const root = document.querySelector(selector);
        if (!root) return null;
        const elements = [root, ...root.querySelectorAll('*')].filter(element =>
            !element.closest('script,style,noscript,template') && visible(element));
        const text = [];
        const visibleNodes = new Set(elements);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const literal = normalize(walker.currentNode.nodeValue);
            if (literal && visibleNodes.has(walker.currentNode.parentElement)) text.push(literal);
        }
        const links = [];
        const assets = [];
        const controls = [];
        for (const element of elements) {
            if (element.matches('a[href]')) links.push({
                href: element.getAttribute('href').startsWith('#') ? element.getAttribute('href') : element.href,
                text: normalize(element.innerText || element.getAttribute('aria-label') || ''),
            });
            if (element.matches('img')) assets.push({
                type: 'image', url: element.currentSrc || element.src, alt: element.getAttribute('alt'),
                loaded: element.complete && element.naturalWidth > 0,
            });
            if (element.matches('svg,iframe,video,audio,canvas,object,embed')) assets.push({ type: element.localName });
            const style = getComputedStyle(element);
            const background = style.backgroundImage;
            for (const match of background.matchAll(/url\("?([^"\)]+)"?\)/g)) assets.push({ type: 'background', url: match[1] });
            if (['auto','scroll'].includes(style.overflowX) && element.clientWidth > 0 &&
                element.scrollWidth - element.clientWidth > 4) {
                const strip = stripProfile(element, style);
                controls.push({type:'horizontal-scroll',name:normalize(element.getAttribute('aria-label') || '').slice(0,180),
                    ...(strip ? { strip } : {})});
            }
            const customAction = !element.closest('a[href],button') && (typeof element.onclick === 'function' ||
                style.cursor === 'pointer' && (!element.parentElement || getComputedStyle(element.parentElement).cursor !== 'pointer'));
            if (customAction || element.matches('form,input,select,textarea,button,details,summary,dialog,[role=button],[role=tab],[role=dialog],[role=application],[aria-expanded],[aria-roledescription=carousel],a:not([href]),a[href="#"]')) {
                controls.push({ type: element.getAttribute('role') || element.localName,
                    name: normalize(element.getAttribute('aria-label') || element.innerText || element.getAttribute('placeholder') || '').slice(0, 180) });
            }
        }
        const bounds = root.getBoundingClientRect();
        return {
            text, links, assets, controls,
            anchors: [root.id, root.getAttribute('data-section-id'), root.getAttribute('aria-label')].filter(Boolean),
            position: getComputedStyle(root).position,
            compactNavigation: bounds.y <= 2 && bounds.height < 200 &&
                elements.some(element => /(menu|navigation)/i.test(element.getAttribute('aria-label') || '')),
        };
    });
}

async function captureImage(session, width, y, height) {
    // Bound pixels before Chromium encodes. Full-height views preserve the
    // document's composition, at a smaller scale suitable for comparison.
    const scale = Math.min(1, HEIGHT / height);
    for (const quality of [58, 40, 22]) {
        const { data } = await session.send('Page.captureScreenshot', {
            format: 'jpeg', quality, captureBeyondViewport: true,
            clip: { x: 0, y, width, height, scale },
        });
        if (data.length <= 400000) return data;
    }
    return null;
}

async function muteOverlayLayers(page, sections) {
    return page.evaluateHandle(({ overlaySelectors, contentSelectors }) => {
        const overlays = overlaySelectors.map(selector => document.querySelector(selector)).filter(Boolean);
        const content = contentSelectors.map(selector => document.querySelector(selector)).filter(Boolean);
        const muted = new Set(overlays);
        for (const overlay of overlays) {
            for (let node = overlay; node && node !== document.body; node = node.parentElement) {
                for (const sibling of node.parentElement.children) {
                    if (content.some(root => sibling.contains(root) || root.contains(sibling))) continue;
                    const style = getComputedStyle(sibling), box = sibling.getBoundingClientRect();
                    const isShade = ['fixed','absolute'].includes(style.position) && Number(style.zIndex) > 0 &&
                        box.left <= 2 && box.top <= 2 && box.right >= innerWidth - 2 && box.bottom >= innerHeight - 2 &&
                        !(sibling.innerText || '').trim() && !sibling.querySelector('img,video,canvas,iframe') &&
                        (style.backgroundColor !== 'rgba(0, 0, 0, 0)' || style.backdropFilter !== 'none' || style.backgroundImage !== 'none');
                    if (isShade) muted.add(sibling);
                }
            }
        }
        const prior = [...muted].map(element => [element,element.style.getPropertyValue('opacity'),element.style.getPropertyPriority('opacity')]);
        for (const [element] of prior) element.style.setProperty('opacity','0','important');
        return { count: prior.length, restore() {
            for (const [element,value,priority] of prior) {
                if (value) element.style.setProperty('opacity',value,priority);
                else element.style.removeProperty('opacity');
            }
        }};
    }, { overlaySelectors: sections.filter(s => s.kind === 'overlay').map(s => s.sourceRef),
        contentSelectors: sections.filter(s => s.kind !== 'overlay').map(s => s.sourceRef) });
}

/** Capture the region itself after verifying the original composition and the adjacent owner's overhang. */
async function captureOwnedRegion(page,sections,diagnostics,{width,sourceRef,destinationRef,overlapRef,compositedSha256}) {
    const target=sections.find(section=>section.kind==='content'&&section.sourceRef===sourceRef);
    const owner=sections.find(section=>section.kind==='content'&&section.sourceRef===destinationRef);
    const overhang=owner?.overhangs?.find(entry=>entry.sourceRef===overlapRef&&entry.position==='absolute'&&entry.edges?.bottom>0);
    const top=Math.round(target?.bounds?.y??-1);
    const height=Math.round(target?.bounds?.height??0);
    if(!diagnostics.contentReady||!target?.geometryAvailable||!overhang||top<0||height<1||height>12000||page.viewportSize()?.width!==width)
        return null;
    const measure=()=>page.evaluate(({sourceRef,destinationRef,overlapRef})=>{
        const unique=selector=>{
            const elements=document.querySelectorAll(selector);
            return elements.length===1?elements[0]:null;
        };
        const source=unique(sourceRef), owner=unique(destinationRef), overhang=unique(overlapRef);
        if(!source||!owner||!overhang||owner.contains(source)||source.contains(owner)||
            !owner.contains(overhang)||getComputedStyle(overhang).position!=='absolute')return null;
        const r=source.getBoundingClientRect(), a=owner.getBoundingClientRect(), b=overhang.getBoundingClientRect();
        const intersection=Math.min(r.bottom,b.bottom)-Math.max(r.top,b.top);
        if(a.bottom>r.top+1||b.top>=r.top||intersection<=0||Math.min(r.right,b.right)<=Math.max(r.left,b.left))return null;
        return {rect:[r.x+scrollX,r.y+scrollY,r.width,r.height],overlap:Math.round(intersection*100)/100,
            copy:source.textContent};
    },{sourceRef,destinationRef,overlapRef});
    const before=await measure();
    if(!before||Math.round(before.rect[1])!==top||Math.round(before.rect[3])!==height||
        Math.abs(before.overlap-overhang.edges.bottom)>1)return null;
    const captureFrame=async()=>{
        const rows=sections.filter(section=>section===target||section.kind==='overlay');
        const capture=await captureResponsiveViewport(page,rows,width,diagnostics);
        return capture.sections.find(section=>section.sourceRef===sourceRef)?.screenshot;
    };
    const original=await captureFrame();
    const sha=base64=>createHash('sha256').update(Buffer.from(base64,'base64')).digest('hex');
    if(!original||sha(original)!==compositedSha256)return null;
    const restore=await page.locator(overlapRef).evaluateHandle(element=>{
        const prior=element.style.getPropertyValue('visibility');
        const priority=element.style.getPropertyPriority('visibility');
        element.style.setProperty('visibility','hidden','important');
        return ()=>prior?element.style.setProperty('visibility',prior,priority):element.style.removeProperty('visibility');
    });
    try{
        const after=await measure();
        if(JSON.stringify(after)!==JSON.stringify(before))return null;
        const screenshot=await captureFrame();
        if(!screenshot||sha(screenshot)===compositedSha256)return null;
        return {width,top,height,overlap:before.overlap,sourceRef,destinationRef,overlapRef,
            compositedSha256,cropSha256:sha(screenshot),screenshot};
    }finally{
        await restore.evaluate(undo=>undo());
        await restore.dispose();
    }
}

async function captureResponsiveViewport(page, sections, width, diagnostics, describeSource = null) {
    const session = await page.context().newCDPSession(page);
    try {
        const height = await page.evaluate(() => Math.max(innerHeight, document.documentElement.scrollHeight));
        const whole = height <= 36000 ? await captureImage(session, width, 0, height) : null;
        const fold = await captureImage(session, width, 0, HEIGHT);
        const inventories = await page.evaluate(sourceInventory, { selectors: sections.map(section => section.sourceRef) });
        for (const inventory of inventories) for (const control of inventory?.controls ?? []) {
            if (!control.strip) continue;
            const { identity, cardCopy } = control.strip;
            control.strip = { version: 1, targetId: touchTargetId(identity), cards: cardCopy.length,
                copySha256: createHash('sha256').update(JSON.stringify(cardCopy)).digest('hex'), cardCopy };
        }
        const facts = describeSource ? await page.evaluate(describeSource, sections.map(section => section.sourceRef)) : [];
        const captureSection = async (section, index, hiddenLayers = 0) => {
            const top = Math.max(0, Math.round(section.bounds.y ?? 0));
            const extent = Math.round(section.bounds.height ?? 0);
            const screenshot = section.geometryAvailable && extent > 0 && extent <= 12000
                ? await captureImage(session, width, top, extent) : null;
            return { ...section, inventory: inventories[index], facts: facts[index] ?? null, screenshot, hiddenOverlayLayers: hiddenLayers };
        };
        const evidence = [];
        for (const [index, section] of sections.entries())
            if (section.kind === 'overlay') evidence[index] = await captureSection(section,index);
        const hidden = await muteOverlayLayers(page, sections);
        try {
            const count = await hidden.evaluate(state => state.count);
            for (const [index, section] of sections.entries())
                if (section.kind !== 'overlay') evidence[index] = await captureSection(section,index,count);
        } finally {
            await hidden.evaluate(state => state.restore());
            await hidden.dispose();
        }
        return { width, height, whole, fold, ready: diagnostics.contentReady, sections: evidence,
            network: diagnostics.network, browserErrors: diagnostics.browserErrors };
    } finally {
        await session.detach();
    }
}

function tokens(text) {
    return new Set((text.normalize('NFKC').toLowerCase().match(/[\p{L}\p{N}]{3,}/gu) || [])
        .filter(word => !['the', 'and', 'for', 'with', 'your', 'from', 'our', 'you', 'this'].includes(word)));
}

function correspondence(left, right, frequency) {
    if (left.sourceRef === right.sourceRef || (left.inventory?.anchors || []).some(anchor =>
        right.inventory?.anchors?.includes(anchor))) return 100;
    if (left.kind === 'overlay' || right.kind === 'overlay') return left.kind === right.kind && left.label === right.label ? 90 : 0;
    if (left.label.toLowerCase() === right.label.toLowerCase() && left.label !== 'Untitled content') return 90;
    if (left.kind === 'header' && right.inventory?.compactNavigation) return 85;
    const words = entry => tokens([entry.label, ...(entry.inventory?.text || [])].join(' '));
    const a = words(left), b = words(right);
    const common = [...a].filter(word => b.has(word) && frequency.get(word) <= 3);
    const labels = [...tokens(left.label)].filter(word => tokens(right.label).has(word));
    return common.length >= 3 ? Math.min(80, common.length * 3 + labels.length * 5) : labels.length >= 2 ? 10 : 0;
}

function reconcileResponsive(url, views) {
    const regions = [];
    const append = (section, width, index) => {
        const id = createHash('sha256').update(url + '\n' + section.sourceRef).digest('hex').slice(0, 24);
        const region = { id, label: section.label, kind: section.kind, sourceRef: section.sourceRef,
            variants: { [width]: section } };
        regions.splice(index, 0, region);
        return region;
    };
    for (const view of views) {
        const frequency = new Map();
        for (const section of view.sections) for (const word of tokens((section.inventory?.text || []).join(' ')))
            frequency.set(word, (frequency.get(word) || 0) + 1);
        const ranked = view.sections.map(section => regions.map(region => ({ region, score: Math.max(...Object.values(region.variants)
            .map(previous => correspondence(previous, section, frequency))) }))
            .filter(match => match.score >= 10).sort((a, b) => b.score - a.score));
        const matches = new Map();
        for (const [index, options] of ranked.entries()) {
            const match = options[0];
            if (!match || match.score - (options[1]?.score || 0) < 5 ||
                ranked.some((other, i) => i !== index && other[0]?.region === match.region && other[0].score >= match.score - 4)) continue;
            match.region.variants[view.width] = view.sections[index];
            matches.set(index, match.region);
        }
        for (const [index, section] of view.sections.entries()) {
            if (matches.has(index)) continue;
            const next = [...matches].find(([i]) => i > index)?.[1];
            append(section, view.width, next ? regions.indexOf(next) : regions.length);
        }
    }
    return regions;
}

module.exports = { VIEWPORTS, captureResponsiveViewport, captureOwnedRegion, reconcileResponsive };
