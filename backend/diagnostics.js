'use strict';

/**
 * Bounded capture diagnostics; run inside the same browser document as
 * section detection. No HTML dump, query strings, cookies or input values.
 */
function inspectDocument() {
    const ignored = new Set(['HEAD', 'SCRIPT', 'STYLE', 'LINK', 'META', 'TITLE', 'NOSCRIPT', 'TEMPLATE', 'SVG']);
    const rect = (element) => {
        const box = element.getBoundingClientRect();
        return { width: Math.round(box.width), height: Math.round(box.height),
            y: Math.round(box.y), display: getComputedStyle(element).display };
    };
    const pick = (element) => ({
        tag: element.tagName.toLowerCase(),
        className: typeof element.className === 'string' ? element.className.slice(0, 80) : '',
        id: (element.id || '').slice(0, 60),
        children: element.children.length,
        textLength: (element.innerText || element.textContent || '').trim().length,
        ...rect(element),
    });
    const body = document.body;
    const main = document.querySelector('main,[role="main"]');
    const nodes = [...document.querySelectorAll('*')].slice(0, 50000);
    const visible = nodes.filter((node) => {
        if (ignored.has(node.tagName)) return false;
        const style = getComputedStyle(node);
        return style.display !== 'none' && style.visibility !== 'hidden' &&
            node.getBoundingClientRect().width > 2 && node.getBoundingClientRect().height > 2;
    });
    const h = [...document.querySelectorAll('h1,h2,h3')].slice(0, 35).map((node) => ({
        tag: node.tagName.toLowerCase(),
        text: (node.innerText || node.textContent || '').trim().slice(0, 90),
        parent: node.parentElement?.tagName.toLowerCase() || '',
        bounds: rect(node),
    }));
    const css = [...document.styleSheets].slice(0, 30).map((sheet) => ({
        external: !!sheet.href,
        host: sheet.href ? (() => { try { return new URL(sheet.href).hostname; } catch (_) { return 'unknown'; } })() : null,
        rulesAccessible: (() => { try { return sheet.cssRules.length; } catch (_) { return null; } })(),
    }));
    return {
        readyState: document.readyState,
        title: document.title.slice(0, 120),
        documentElement: document.documentElement.tagName.toLowerCase(),
        body: body ? pick(body) : null,
        main: main ? pick(main) : null,
        totalElements: nodes.length,
        visibleElements: visible.length,
        semanticSections: document.querySelectorAll('section,article,[role="region"]').length,
        headings: h,
        stylesheets: css,
        bodyChildren: body ? [...body.children].filter((el) => !ignored.has(el.tagName)).slice(0, 18).map(pick) : [],
        mainChildren: main ? [...main.children].filter((el) => !ignored.has(el.tagName)).slice(0, 22).map(pick) : [],
        customElements: [...new Set(nodes.filter((el) => el.tagName.includes('-')).map((el) => el.tagName.toLowerCase()))].slice(0, 20),
        scrollHeight: document.documentElement.scrollHeight,
    };
}

module.exports = { inspectDocument };
