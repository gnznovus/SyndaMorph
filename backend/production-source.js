'use strict';

const { createHash } = require('node:crypto');
const { capture } = require('./capture');

const WIDTHS = [1440, 768, 375];
const HEIGHT = 900;
const digest = value => createHash('sha256').update(value).digest('hex');
const identity = (url, ref) => digest(JSON.stringify([url, ref])).slice(0, 24);
function stableJSON(value) {
    if (Array.isArray(value)) return '[' + value.map(stableJSON).join(',') + ']';
    if (value && typeof value === 'object') {
        return '{' + Object.keys(value).sort().filter(key => value[key] !== undefined)
            .map(key => JSON.stringify(key) + ':' + stableJSON(value[key])).join(',') + '}';
    }
    return JSON.stringify(value);
}

/** Read facts directly from the live DOM. No importer markup, actions or control values. */
function describeSource(refs) {
    const normalize = value => (value || '').replace(/\s+/g, ' ').trim();
    const safeURL = value => {
        if (!value) return null;
        try {
            const url = new URL(value, document.baseURI);
            if (!/^https?:$/.test(url.protocol) || url.username || url.password) return null;
            return url.href.slice(0, 2048);
        } catch { return null; }
    };
    const visible = el => {
        const style = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        return style.display !== 'none' && style.visibility === 'visible' && rect.width > 1 && rect.height > 1;
    };
    const elements = (roots, selector) => roots.flatMap(root => [root, ...root.querySelectorAll(selector)])
        .filter(el => el.matches(selector) && visible(el));
    const box = el => {
        const rect = el.getBoundingClientRect();
        return [rect.x + scrollX, rect.y + scrollY, rect.width, rect.height].map(n => Math.round(n * 10) / 10);
    };
    return refs.map(ref => {
        const candidates = ref.split('\n').map(selector => document.querySelectorAll(selector));
        if (candidates.some(nodes => nodes.length !== 1 || !visible(nodes[0]))) return null;
        const roots = candidates.map(nodes => nodes[0]);
        const root = roots[0];
        const boxes = roots.map(box);
        const left = Math.min(...boxes.map(b => b[0]));
        const top = Math.min(...boxes.map(b => b[1]));
        const bounds = [left, top, Math.max(...boxes.map(b => b[0] + b[2])) - left,
            Math.max(...boxes.map(b => b[1] + b[3])) - top].map(n => Math.round(n * 10) / 10);
        const literal = [];
        function gatherCopy(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                literal.push(node.nodeValue);
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE ||
                /^(?:STYLE|SCRIPT|NOSCRIPT|TEMPLATE|SVG)$/.test(node.tagName)) return;
            const { display, visibility } = getComputedStyle(node);
            if (display === 'none') return;
            const boundary = node.tagName === 'BR' || !/^(?:inline|contents)/.test(display);
            if (boundary) literal.push(' ');
            for (const child of node.childNodes) {
                if (child.nodeType !== Node.TEXT_NODE || visibility === 'visible') gatherCopy(child);
            }
            if (boundary) literal.push(' ');
        }
        roots.forEach(gatherCopy);
        const typography = elements(roots, 'h1,h2,h3,h4,h5,h6,p').slice(0, 20).map(el => {
            const style = getComputedStyle(el);
            return { tag: el.localName, text: normalize(el.textContent).slice(0, 350), bounds: box(el),
                font: style.fontFamily, size: style.fontSize, weight: style.fontWeight,
                color: style.color, lineHeight: style.lineHeight, align: style.textAlign };
        });
        const controls = elements(roots, 'button,input,select,textarea,video,canvas,iframe,[role="button"],[role="tab"],[role="combobox"],[role="dialog"]')
            .slice(0, 40).map(el => ({ tag: el.localName, role: el.getAttribute('role') || '',
                label: normalize(el.getAttribute('aria-label') || el.textContent).slice(0, 90), bounds: box(el) }));
        const links = elements(roots, 'a[href]').map(el => ({ text: normalize(el.textContent), url: safeURL(el.href) }));
        const images = elements(roots, 'img').map(el => ({ url: safeURL(el.currentSrc || el.src), alt: el.alt.slice(0, 200), loaded: el.complete && el.naturalWidth > 0 }));
        const backgrounds = [...new Set(roots.flatMap(root => [root, ...root.querySelectorAll('*')]).slice(0, 1000)
             .filter(visible).map(el => getComputedStyle(el).backgroundImage).filter(value => value.includes('url('))
            .map(value => value.slice(0, 350)))].slice(0, 20);
        const fonts = [...new Set([getComputedStyle(root).fontFamily, ...typography.map(t => t.font)])];
        const style = getComputedStyle(root);
        const copy = normalize(literal.join(''));
        return { bounds, copy: copy.slice(0, 10000), copyTruncated: copy.length > 10000,
            typography, controls, links: links.slice(0, 30), linkCount: links.length, images: images.slice(0, 30),
            imageCount: images.length, backgrounds, fonts,
            flow: { display: style.display, position: style.position, direction: style.flexDirection,
                gap: style.gap, padding: style.padding, background: style.backgroundColor },
        };
    });
}

async function sectionScreenshot(page, ref, width) {
    // Keep the original geometry and paint. Hide other sections and overlays
    // with visibility so they neither pollute the crop nor reflow the source.
    const placement = await page.evaluate(({ ref, height }) => {
        const roots = ref.split('\n').map(selector => document.querySelector(selector));
        const keep = new Set(roots);
        const style = document.createElement('style');
        style.textContent = 'html{scroll-behavior:auto!important}';
        document.head.append(style);
        for (let node = roots[0]; node && node !== document.body; node = node.parentElement) {
            keep.add(node);
            for (const sibling of node.parentElement.children) if (!keep.has(sibling) && sibling instanceof HTMLElement) {
                sibling.style.setProperty('visibility', 'hidden', 'important');
            }
        }
        const before = Math.min(...roots.map(node => node.getBoundingClientRect().top));
        window.scrollTo(0, before + window.scrollY);
        const after = roots.map(node => node.getBoundingClientRect());
        const top = Math.min(...after.map(box => box.top));
        const bottom = Math.max(...after.map(box => box.bottom));
        return { y: Math.max(0, Math.round(top)), height: Math.min(height, Math.round(bottom - top)) };
    }, { ref, height: HEIGHT });
    const height = Math.min(placement.height, HEIGHT - placement.y);
    if (height < 1) throw new Error('Source section cannot be framed.');
    const bytes = await page.screenshot({ type: 'png', animations: 'disabled', timeout: 8000,
        clip: { x: 0, y: placement.y, width, height } });
    return page.evaluate(async ({ input, width, height }) => {
        const image = new Image();
        image.src = 'data:image/png;base64,' + input;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, width, height);
        ctx.drawImage(image, 0, 0);
        for (const quality of [0.65, 0.5, 0.35, 0.2, 0.1]) {
            const jpeg = canvas.toDataURL('image/jpeg', quality).split(',')[1];
            if (jpeg.length <= 400000) return jpeg;
        }
        throw new Error('Source section screenshot exceeds the evidence limit.');
    }, { input: bytes.toString('base64'), width, height: HEIGHT });
}

async function productionSource(url, sectionId = null, variants = null) {
    const requested = variants ?? Object.fromEntries(WIDTHS.map(width => [width, sectionId]));
    if ((variants !== null && (sectionId !== null || typeof variants !== 'object' || Array.isArray(variants) ||
        Object.keys(variants).length !== WIDTHS.length)) ||
        WIDTHS.some(width => (variants !== null || requested[width] !== null) &&
            (typeof requested[width] !== 'string' || !/^[a-f0-9]{24}$/.test(requested[width])))) {
        throw new Error('Invalid production source section selection.');
    }
    let manifestURL;
    let primary;
    let selected;
    const evidence = [];
    const pages = [];
    for (const width of WIDTHS) {
        const current = await capture(url, 'browser', { width, inspect: async (page, regions, diagnostics) => {
            const resolved = new URL(page.url());
            resolved.hash = '';
            const url = resolved.href;
            if (!diagnostics.contentReady || (manifestURL && manifestURL !== url))
                throw new Error('Responsive source did not render at the same URL.');
            const byRef = new Map(regions.map(({ sourceRef, label, kind, overhangs, parentRef }) =>
                [sourceRef, { id: identity(url, sourceRef), sourceRef, label, kind, overhangs, parentRef }]));
            const byPrimary = new Map((primary || []).map(row => [row.sourceRef, row]));
            const refs = [...new Set([...byPrimary.keys(), ...byRef.keys()])];
            const facts = await page.evaluate(describeSource, refs);
            const fontFaces = await page.evaluate(() => [...document.fonts].slice(0, 40).map(font => ({
                family: font.family, status: font.status, weight: font.weight, style: font.style,
            })));
            const rows = refs.map((ref, index) => ({ ...(byRef.get(ref) || byPrimary.get(ref)),
                view: facts[index], detected: byRef.has(ref) }));
            const entry = rows.find(item => item.id === requested[width]);
            if (requested[width] && !entry?.view) throw new Error('Selected section is absent at a required viewport.');
            const screenshot = entry ? await sectionScreenshot(page, entry.sourceRef, width) : null;
            return { url, rows, entry, screenshot, diagnostics, fontFaces };
        } });
        manifestURL = current.url;
        primary ||= current.rows;
        if (current.entry) {
            selected ||= current.entry;
            evidence.push({ width, sectionId: current.entry.id, sourceRef: current.entry.sourceRef, screenshot: current.screenshot, ...current.entry.view });
        }
        const d = current.diagnostics;
        pages.push({ width, height: HEIGHT, scrollHeight: d.scrollHeight,
            ready: d.contentReady, stylesheets: d.stylesheets, fontFaces: current.fontFaces, browserErrors: d.browserErrors,
            network: d.network, regions: current.rows.map(({ view, overhangs, ...region }) =>
                ({ ...region, bounds: view?.bounds ?? null, fonts: view?.fonts ?? [], images: view?.imageCount ?? 0,
                    backgrounds: view?.backgrounds?.length ?? 0, links: view?.linkCount ?? 0,
                    controls: view?.controls ?? [], overhangs: overhangs || [],
                    copySha256: view ? digest(view.copy) : null })) });
    }
    const manifest = { version: 1, sourceURL: manifestURL, viewportWidths: WIDTHS, pages };
    return { manifest: { ...manifest, sha256: digest(stableJSON(manifest)) },
        section: selected ? { id: variants === null ? selected.id : identity(manifestURL, WIDTHS.map(width => requested[width])),
            label: selected.label, sourceRef: selected.sourceRef,
            kind: selected.kind, evidence, evidenceSha256: digest(stableJSON(evidence)) } : null };
}

module.exports = { productionSource, describeSource, identity, stableJSON };
