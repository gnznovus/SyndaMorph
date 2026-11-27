(() => {
    for (const root of document.querySelectorAll('[data-sm-booking]')) {
        const toggles = [...root.querySelectorAll('.sm-booking-toggle')];
        const rates = [...root.querySelectorAll('[data-sm-rate]')];
        const counters = Object.fromEntries([...root.querySelectorAll('[data-sm-counter]')].map(row =>
            [row.dataset.smCounter, row.querySelector('output')]));
        let open = null;

        function close() {
            if (!open) return;
            document.getElementById(open.getAttribute('aria-controls')).hidden = true;
            open.setAttribute('aria-expanded', 'false');
            open = null;
        }

        function updateCounter(button) {
            const row = button.closest('[data-sm-counter]');
            const output = counters[row.dataset.smCounter];
            const next = Number(output.value) + Number(button.dataset.smStep);
            output.value = Math.min(Number(row.dataset.max), Math.max(Number(row.dataset.min), next));
            const rooms = Number(counters.rooms.value);
            const guests = Number(counters.adults.value) + Number(counters.children.value);
            root.querySelector('[data-sm-booking-guests]').textContent =
                rooms + (rooms === 1 ? ' room' : ' rooms') + ' · ' + guests + (guests === 1 ? ' guest' : ' guests');
        }

        root.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button || !root.contains(button)) return;
            if (toggles.includes(button)) {
                const wasOpen = button === open;
                close();
                if (!wasOpen) {
                    open = button;
                    document.getElementById(open.getAttribute('aria-controls')).hidden = false;
                    open.setAttribute('aria-expanded', 'true');
                }
            } else if (button.hasAttribute('data-sm-step')) {
                updateCounter(button);
            } else if (rates.includes(button)) {
                for (const rate of rates) rate.setAttribute('aria-pressed', String(rate === button));
                root.querySelector('[data-sm-booking-rate]').textContent = button.textContent;
                const trigger = open;
                close();
                if (trigger) trigger.focus();
            }
        });
        root.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || !open) return;
            const trigger = open;
            close();
            trigger.focus();
        });
        document.addEventListener('click', event => {
            if (!root.contains(event.target)) close();
        });
    }
})();
