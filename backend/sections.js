'use strict';

/**
 * Browser-side extraction. Standalone on purpose: Playwright can serialize this
 * function to page.evaluate(), while tests exercise the same implementation.
 */
function extractSections({ maxSections = 60, maxHtml = 2 * 1024 * 1024 } = {}) {
    const ignored = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'TITLE', 'HEAD', 'HELMET', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'IFRAME', 'OBJECT', 'EMBED']);
    const structural = new Set(['SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'ASIDE']);
    const visible = (el) => {
        if (!el || el.nodeType !== 1 || ignored.has(el.tagName)) return false;
        const style = getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse') return false;
        const rect = el.getBoundingClientRect();
        return rect.width > 2 && rect.height > 2;
    };
    const rectFor = (el) => {
        const own = el.getBoundingClientRect();
        if (own.width > 2 && own.height > 2) return own;
        // display:contents and custom layout wrappers have zero-sized own boxes.
        const children = [...el.querySelectorAll('*')].filter(visible).slice(0, 200);
        if (!children.length) return own;
        const x = Math.min(...children.map((child) => child.getBoundingClientRect().left));
        const y = Math.min(...children.map((child) => child.getBoundingClientRect().top));
        const right = Math.max(...children.map((child) => child.getBoundingClientRect().right));
        const bottom = Math.max(...children.map((child) => child.getBoundingClientRect().bottom));
        return { x, y, width: right - x, height: bottom - y };
    };
    const substantial = (el) => {
        if (!el || ignored.has(el.tagName)) return false;
        const box = rectFor(el);
        return box.width > 20 && box.height > 16 &&
            ((el.innerText || el.textContent || '').trim().length >= 12 ||
             !!el.querySelector('img,picture,video,canvas'));
    };
    const contentRoot = document.querySelector('main') || document.querySelector('[role="main"]') || document.body;
    if (!contentRoot) return [];
    // Prefer semantic sections independent of how many custom/display:contents
    // wrappers lie between <body>, <main>, and the content.
    const explicit = [...document.querySelectorAll('section,article,header,footer,[role="region"]')]
        .filter((el) => substantial(el) && el.closest('body') && el !== contentRoot);
    const noOverlap = (nodes) => nodes.filter((el) => !nodes.some((other) => other !== el && other.contains(el)));
    let roots = noOverlap(explicit);
    // Ignore whole-page header/footer wrappers only when they swallow all content.
    roots = roots.filter((el) => el.tagName !== 'HEADER' && el.tagName !== 'FOOTER' ||
        el.closest('main') || rectFor(el).height < (document.documentElement.scrollHeight || Infinity) * 0.8);
    if (roots.length < 2) {
        // Descend through single-child and zero-sized wrappers. Stop once a
        // container has multiple meaningful children that form visual units.
        let holder = contentRoot;
        for (let depth = 0; depth < 12; depth++) {
            const children = [...holder.children].filter((child) => substantial(child));
            if (children.length > 1) {
                roots = children;
                break;
            }
            if (children.length !== 1) break;
            holder = children[0];
        }
    }
    // If only one coarse section remains, split on top-level headings or
    // their nearest substantial visual containers, avoiding nested duplication.
    if (roots.length < 2) {
        const headings = [...contentRoot.querySelectorAll('h1,h2')].filter(visible);
        const entries = [];
        for (const heading of headings) {
            let node = heading;
            while (node.parentElement && node.parentElement !== contentRoot &&
                node.parentElement !== document.body) {
                const parent = node.parentElement;
                const count = [...parent.children].filter(substantial).length;
                if (count > 1 && structural.has(parent.tagName)) break;
                if (rectFor(parent).height > 1000 && entries.length) break;
                node = parent;
                if (structural.has(node.tagName)) break;
            }
            if (substantial(node) && !entries.includes(node)) entries.push(node);
        }
        const distinct = noOverlap(entries);
        if (distinct.length >= 2) roots = distinct;
    }
    if (!roots.length && substantial(contentRoot)) roots = [contentRoot];
    const ordered = [...new Set(roots)].filter(substantial).sort((a, b) => {
        const ar = rectFor(a);
        const br = rectFor(b);
        return ar.y - br.y || ar.x - br.x;
    });

    const properties = [
        'display', 'color', 'background-color', 'background-image', 'background-size',
        'background-position', 'font-family', 'font-size', 'font-weight', 'line-height',
        'letter-spacing', 'text-align', 'padding', 'margin', 'gap', 'column-gap',
        'row-gap', 'grid-template-columns', 'flex-direction', 'align-items',
        'justify-content', 'border-radius', 'width', 'max-width',
    ];
    const computedStyles = (original, copy) => {
        const style = getComputedStyle(original);
        // Avoid flooding the converter with inherited/default declarations.
        // Preserve authored inline styles and the properties needed for layout.
        const important = new Set([
            'display', 'background-color', 'background-image', 'background-size',
            'background-position', 'padding', 'margin', 'gap', 'column-gap',
            'row-gap', 'grid-template-columns', 'flex-direction', 'align-items',
            'justify-content', 'border-radius', 'width', 'max-width',
        ]);
        for (const prop of properties) {
            if (!important.has(prop) && !['H1','H2','H3','H4','H5','H6','P','A','BUTTON','SPAN'].includes(original.tagName)) continue;
            const value = style.getPropertyValue(prop);
            if (!value || ['none', 'normal', 'auto', 'initial', '0px', 'rgba(0, 0, 0, 0)'].includes(value)) continue;
            if (prop === 'width' && (original.tagName === 'BODY' || original.tagName === 'MAIN')) continue;
            copy.style.setProperty(prop, value);
        }
    };
    const copyContent = (root) => {
        // Walk original and clone in parallel; removing nodes during the walk
        // must not misalign style mapping.
        const clone = root.cloneNode(true);
        const walk = (original, copied) => {
            if (ignored.has(original.tagName)) { copied.remove(); return; }
            for (const attr of [...copied.attributes]) {
                if (/^on/i.test(attr.name) || ['srcdoc', 'integrity', 'nonce'].includes(attr.name)) copied.removeAttribute(attr.name);
            }
            if (original.tagName === 'FORM') copied.removeAttribute('action');
            computedStyles(original, copied);
            if (original.tagName === 'IMG' && original.currentSrc) copied.setAttribute('src', original.currentSrc);
            for (const name of ['src', 'href']) {
                const value = copied.getAttribute(name);
                if (!value) continue;
                try {
                    const resolved = new URL(value, document.baseURI);
                    if (!['http:', 'https:'].includes(resolved.protocol)) copied.removeAttribute(name);
                    else copied.setAttribute(name, resolved.href);
                } catch (_) { copied.removeAttribute(name); }
            }
            const sourceElements = [...original.children];
            const copiedElements = [...copied.children];
            sourceElements.forEach((child, i) => walk(child, copiedElements[i]));
        };
        walk(root, clone);
        const html = clone.outerHTML;
        if (new TextEncoder().encode(html).length > maxHtml) return null;
        return html;
    };
    const sections = [];
    for (const el of ordered) {
        if (sections.length >= maxSections) break;
        const html = copyContent(el);
        const rect = rectFor(el);
        const heading = el.querySelector('h1,h2,h3,h4');
        const label = (heading?.innerText || heading?.textContent || el.getAttribute('aria-label') || el.id || el.tagName.toLowerCase()).trim();
        sections.push({
            id: 'section-' + (sections.length + 1), label: label.slice(0, 100),
            tag: el.tagName.toLowerCase(),
            bounds: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
            html,
            error: html === null ? 'Section HTML exceeds capture limit; select a smaller section.' : null,
        });
    }
    return sections;
}

module.exports = { extractSections };
