(() => {
    for (const offer of document.querySelectorAll('[data-sm-offer]')) {
        const card = offer.querySelector('.sm-offer-card');
        if (!card) continue;
        const alignToDocument = () => {
            const parent = card.offsetParent;
            if (!parent) return;
            const top = parent === document.body && getComputedStyle(parent).position === 'static'
                ? 0 : parent.getBoundingClientRect().top + window.scrollY + parent.clientTop;
            card.style.setProperty('--sm-offer-origin-y', top + 'px');
        };
        alignToDocument();
        window.addEventListener('resize', alignToDocument);
        window.addEventListener('load', alignToDocument, { once: true });
        offer.querySelector('.sm-offer-dismiss')?.addEventListener('click', () => {
            offer.hidden = true;
            window.removeEventListener('resize', alignToDocument);
        }, { once: true });
    }
})();
