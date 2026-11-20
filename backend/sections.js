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

    const normalize = (text) => (text || '').replace(/\s+/g, ' ').trim();
    const textOf = (el) => normalize(el.innerText || el.textContent);
    const sourceRef = (el) => {
        const parts = [];
        const literal = (value) => '"' + value.replace(/[^\w-]/gu, (char) => '\\' + char.codePointAt(0).toString(16) + ' ') + '"';
        for (let node = el; node && node !== document.documentElement; node = node.parentElement) {
            const id = node.id && '[id=' + literal(node.id) + ']';
            const key = node.getAttribute('data-dc-tpl');
            const selector = key && node.localName + '[data-dc-tpl=' + literal(key) + ']';
            const unique = [id, selector].find((value) => value && document.querySelectorAll(value).length === 1);
            if (unique) {
                parts.unshift(unique);
                break;
            }
            const siblings = [...(node.parentElement?.children || [])].filter((other) => other.localName === node.localName);
            parts.unshift(node.localName + ':nth-of-type(' + (siblings.indexOf(node) + 1) + ')');
        }
        return parts.join(' > ');
    };
    const isPositioned = (el) => ['absolute', 'fixed'].includes(getComputedStyle(el).position);
    const overlap = (a, b) => Math.max(0, Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x)) *
        Math.max(0, Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y));
    const rootKind = (el, index, geometry) => {
        const text = textOf(el);
        if (el.matches('dialog,[role="dialog"],[role="alertdialog"],[aria-modal="true"]')) return 'overlay';
        if (geometry && isPositioned(el) && Number(getComputedStyle(el).zIndex) > 0 &&
            /\b(?:continue to (?:the )?(?:site|website)|close (?:dialog|popup|offer)|dismiss)\b/i.test(text)) {
            const bounds = rectFor(el);
            if (ordered.some((other) => other !== el && !isPositioned(other) && overlap(bounds, rectFor(other)) >= bounds.width * bounds.height * 0.5)) {
                return 'overlay';
            }
        }
        if (el.matches('header,[role="banner"]') && !el.closest('article,section')) return 'header';
        if (el.matches('footer,[role="contentinfo"]') && !el.closest('article,section')) return 'footer';
        if (index === ordered.length - 1 && el.querySelectorAll('a[href]').length >= 3 &&
            /\bprivacy policy\b/i.test(text) && /\b(?:all rights reserved|terms of use|cookie preferences)\b/i.test(text)) return 'footer';
        if (el.matches('form,[role="form"],[role="tablist"],[aria-roledescription="carousel"]') ||
            /\b(?:email address|e-mail address)\b/i.test(text) && /\bsubscribe\b/i.test(text) && text.length < 240) return 'interactive';
        return 'content';
    };
    const rootLabel = (el, kind, geometry = true) => {
        if (kind === 'header') return 'Site header';
        const image = el.querySelector('img[alt]');
        if (kind === 'footer') return !textOf(el) && image?.alt ? normalize(image.alt) : 'Site footer';
        if (kind === 'interactive' && /\bsubscribe\b/i.test(textOf(el))) return 'Newsletter signup';
        const labelled = normalize((el.getAttribute('aria-labelledby') || '').split(/\s+/)
            .map((id) => document.getElementById(id)?.textContent || '').join(' '));
        const heading = [...el.querySelectorAll('h1,h2,h3,h4,[role="heading"]')].find((node) => !geometry || visible(node));
        const headingLabel = heading && textOf(heading);
        const directText = [...el.querySelectorAll('div,p,span')].find((node) => {
            const ownText = normalize([...node.childNodes].filter((part) => part.nodeType === 3).map((part) => part.textContent).join(' '));
            return (!geometry || visible(node)) && ownText.length >= 3 && ownText.length <= 85 &&
                /[\p{L}]/u.test(ownText) && !node.closest('a,button,[role="button"]');
        });
        const idLabel = normalize(el.id.replace(/[-_]+/g, ' ')).replace(/^./, (char) => char.toUpperCase());
        const name = normalize(el.getAttribute('aria-label')) || labelled || headingLabel || idLabel ||
            (directText && textOf(directText)) || normalize(image?.alt) || textOf(el) || 'Untitled content';
        return (kind === 'overlay' ? 'Overlay · ' + name : name).slice(0, 100);
    };
    const overhangsFor = (el, root) => {
        const nodes = [...el.querySelectorAll('*')].filter((node) => {
            if (!visible(node) || !isPositioned(node)) return false;
            const b = node.getBoundingClientRect();
            return b.left < root.x - 2 || b.top < root.y - 2 ||
                b.right > root.x + root.width + 2 || b.bottom > root.y + root.height + 2;
        });
        return noOverlap(nodes).slice(0, 12).map((node) => {
            const b = node.getBoundingClientRect();
            return {
                sourceRef: sourceRef(node), label: textOf(node).slice(0, 100), position: getComputedStyle(node).position,
                bounds: { x: b.x, y: b.y, width: b.width, height: b.height },
                edges: {
                    top: Math.max(0, root.y - b.top), right: Math.max(0, b.right - root.x - root.width),
                    bottom: Math.max(0, b.bottom - root.y - root.height), left: Math.max(0, root.x - b.left),
                },
            };
        });
    };

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
    // Some prototypes use layoutless custom elements or reveal content only
    // after browser effects. DOM content may exist even when no node has a
    // measurable box. In that case avoid claiming visual geometry: partition
    // the document by its headings and preserve the relevant HTML fragments.
    if (!ordered.length) {
        const headings = [...contentRoot.querySelectorAll('h1,h2')]
            .filter((heading) => !heading.closest('script,style,template,head,helmet'));
        const cleanFragment = (fragment) => {
            for (const node of [...fragment.querySelectorAll('*')]) {
                if (ignored.has(node.tagName)) {
                    node.remove();
                    continue;
                }
                for (const attr of [...node.attributes]) {
                    if (/^on/i.test(attr.name) || ['srcdoc','integrity','nonce'].includes(attr.name)) node.removeAttribute(attr.name);
                }
                if (node.tagName === 'FORM') node.removeAttribute('action');
                for (const name of ['src','href']) {
                    const value = node.getAttribute(name);
                    if (!value) continue;
                    try {
                        const resolved = new URL(value, document.baseURI);
                        if (!['http:','https:'].includes(resolved.protocol)) node.removeAttribute(name);
                        else node.setAttribute(name, resolved.href);
                    } catch (_) { node.removeAttribute(name); }
                }
            }
        };
        const makeSection = (start, end, index, label) => {
            const range = document.createRange();
            range.setStartBefore(start);
            if (end) range.setEndBefore(end);
            else range.setEnd(contentRoot, contentRoot.childNodes.length);
            const fragment = range.cloneContents();
            cleanFragment(fragment);
            const wrapper = document.createElement('section');
            wrapper.appendChild(fragment);
            const html = wrapper.outerHTML;
            const withinLimit = new TextEncoder().encode(html).length <= maxHtml;
            return {
                id: 'section-' + (index + 1),
                label: label.slice(0, 100),
                sourceRef: sourceRef(start),
                kind: 'content', overhangs: [],
                tag: 'section',
                geometryAvailable: false,
                bounds: { x: null, y: null, width: null, height: null },
                html: withinLimit ? html : null,
                error: withinLimit ? null : 'Section HTML exceeds capture limit; select a smaller section.',
            };
        };
        if (headings.length >= 2) {
            return headings.slice(0, maxSections).map((heading, index) =>
                makeSection(heading, headings[index + 1] || null, index,
                    (heading.innerText || heading.textContent || 'Untitled section').trim()));
        }
        // No headings: retain bounded, meaningful child groups under the
        // single layoutless document wrapper. Never return entire <body>.
        let holder = contentRoot;
        for (let depth = 0; depth < 12; depth++) {
            const children = [...holder.children].filter((child) =>
                !ignored.has(child.tagName) && (child.textContent || '').trim().length > 10);
            if (children.length > 1) {
                return children.slice(0, maxSections).map((node, index) => {
                    const clone = node.cloneNode(true);
                    cleanFragment(clone);
                    const html = clone.outerHTML;
                    const withinLimit = new TextEncoder().encode(html).length <= maxHtml;
                    const kind = rootKind(node, index, false);
                    return {
                        id: 'section-' + (index + 1), label: rootLabel(node, kind, false),
                        sourceRef: sourceRef(node), kind, overhangs: [],
                        tag: node.tagName.toLowerCase(), geometryAvailable: false,
                        bounds: { x: null, y: null, width: null, height: null },
                        html: withinLimit ? html : null,
                        error: withinLimit ? null : 'Section HTML exceeds capture limit; select a smaller section.',
                    };
                });
            }
            if (children.length !== 1) break;
            holder = children[0];
        }
    }

    const sections = [];
    const kinds = ordered.map((el, index) => rootKind(el, index, true));
    // Some sites render a standalone brand strip immediately above the footer.
    const last = ordered.length - 1;
    const brands = ordered[last - 1];
    if (kinds[last] === 'footer' && kinds[last - 1] === 'content' && !textOf(brands) &&
        /\bbrands\b/i.test(brands.querySelector('img[alt]')?.alt || '')) kinds[last - 1] = 'footer';
    for (const [index, el] of ordered.slice(0, maxSections).entries()) {
        const html = copyContent(el);
        const rect = rectFor(el);
        const kind = kinds[index];
        sections.push({
            id: 'section-' + (sections.length + 1), label: rootLabel(el, kind),
            sourceRef: sourceRef(el), kind, overhangs: overhangsFor(el, rect),
            tag: el.tagName.toLowerCase(), geometryAvailable: true,
            bounds: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
            html,
            error: html === null ? 'Section HTML exceeds capture limit; select a smaller section.' : null,
        });
    }
    return sections;
}

module.exports = { extractSections };
