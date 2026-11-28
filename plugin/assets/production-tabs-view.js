(() => {
    function activate(widget, target, focus) {
        for (const tab of widget.querySelectorAll(':scope > [role="tablist"] > [role="tab"]')) {
            const active = tab === target;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            const panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel && panel.parentElement === widget) panel.hidden = !active;
        }
        if (focus) target.focus();
    }
    document.addEventListener('click', event => {
        const tab = event.target.closest?.('[data-sm-tabs] > [role="tablist"] > [role="tab"]');
        if (tab) activate(tab.closest('[data-sm-tabs]'), tab, false);
    });
    document.addEventListener('keydown', event => {
        const tab = event.target.closest?.('[data-sm-tabs] > [role="tablist"] > [role="tab"]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const tabs = [...tab.parentElement.querySelectorAll(':scope > [role="tab"]')];
        const index = tabs.indexOf(tab);
        const rtl = getComputedStyle(tab.parentElement).direction === 'rtl';
        const direction = (event.key === 'ArrowLeft' ? -1 : 1) * (rtl ? -1 : 1);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + direction + tabs.length) % tabs.length;
        event.preventDefault();
        activate(tab.closest('[data-sm-tabs]'), tabs[next], true);
    });
})();
