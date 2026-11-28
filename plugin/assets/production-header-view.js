(() => {
    const desktop = window.matchMedia('(min-width: 1024px)');
    for (const header of document.querySelectorAll('[data-sm-header]')) {
        const opener = header.querySelector('.sm-header-open');
        const drawer = header.querySelector('.sm-header-drawer');
        const closer = header.querySelector('.sm-header-close');
        const language = header.querySelector('.sm-header-language');
        const toggle = language.querySelector('button');
        const panel = language.querySelector('.sm-header-flyout');
        const showLanguages = show => {
            panel.hidden = !show;
            toggle.setAttribute('aria-expanded', String(show));
        };
        language.addEventListener('pointerenter', () => showLanguages(true));
        language.addEventListener('pointerleave', () => showLanguages(false));
        toggle.addEventListener('click', event => {
            if (event.detail === 0) showLanguages(panel.hidden);
            else showLanguages(true);
        });
        toggle.addEventListener('keydown', event => {
            if (event.key === 'Escape') showLanguages(false);
        });
        toggle.addEventListener('blur', () => {
            if (!language.matches(':hover')) showLanguages(false);
        });

        opener.addEventListener('click', event => {
            drawer.toggleAttribute('data-sm-pointer-open', event.detail > 0);
            drawer.showModal();
            opener.setAttribute('aria-expanded', 'true');
            closer.focus();
        });
        closer.addEventListener('click', () => drawer.close());
        drawer.addEventListener('keydown', () => drawer.removeAttribute('data-sm-pointer-open'));
        drawer.addEventListener('close', () => {
            opener.setAttribute('aria-expanded', 'false');
            if (!desktop.matches) opener.focus();
        });
        desktop.addEventListener('change', event => {
            if (event.matches && drawer.open) drawer.close();
            if (!event.matches) showLanguages(false);
        });
    }
})();
