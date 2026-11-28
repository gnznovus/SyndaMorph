/* Approved SyndaMorph Operations Overview: archive-backed metrics and prototype layout. */
(() => {
    'use strict';
    const root = document.getElementById('syndamorph-overview');
    const cfg = window.SyndaMorphOverview;
    if (!root || !cfg?.url) return;
    const $ = id => document.getElementById(id);
    const status = $('sm-overview-status');
    const rangeButtons = [...root.querySelectorAll('[data-range]')];
    let period = 'today', generation = 0;

    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    function stat(name, value) {
        const target = root.querySelector('[data-overview-stat="' + name + '"]');
        if (target) target.textContent = value;
    }

    function activity(jobs) {
        const container = $('sm-overview-jobs');
        container.replaceChildren();
        if (!jobs.length) {
            container.append(element('p', 'No archived jobs in this period.', 'sm-overview-empty'));
            return;
        }
        for (const job of jobs.slice(0, 5)) {
            const row = element('div', undefined, 'sm-overview-job');
            const information = element('div', undefined, 'sm-overview-job-info');
            const title = element('a', job.jobId + ' · ' + job.title, 'sm-overview-job-id');
            title.href = 'admin.php?page=syndamorph&tab=production&view=history&inspect=' + encodeURIComponent(job.id);
            const detail = element('div', job.category + ' · ' + job.createdAt, 'sm-overview-job-detail');
            information.append(title, detail);
            const raw = String(job.status || '').toLowerCase();
            const pill = element('span', raw.replaceAll('_', ' '), 'sm-overview-pill');
            pill.classList.add(raw === 'completed' ? 'completed' : raw === 'running' || raw === 'paused' || raw === 'uncompleted' || raw === 'needs_review' ? 'pending' : 'blocked');
            row.append(information, pill);
            container.append(row);
        }
    }

    async function load() {
        const ticket = ++generation;
        status.textContent = 'Loading recorded production activity…';
        try {
            const url = new URL(cfg.url, window.location.href);
            url.searchParams.set('period', period);
            const response = await fetch(url, {credentials: 'same-origin', headers: {'X-WP-Nonce': cfg.nonce}});
            if (!response.ok) throw new Error('Overview request failed (HTTP ' + response.status + ').');
            const data = await response.json();
            if (ticket !== generation) return;
            stat('total', String(data.total));
            stat('completed', String(data.completed));
            stat('attention', String(data.attention));
            stat('cost', '—');
            const rate = data.completionRate === null ? '—' : data.completionRate.toFixed(1) + '%';
            stat('rate', rate);
            stat('acceptance', rate);
            const fill = root.querySelector('[data-overview-acceptance-bar]');
            if (fill) fill.style.width = data.completionRate === null ? '0%' : Math.min(100, Math.max(0, data.completionRate)) + '%';
            activity(data.recent || []);
            status.textContent = 'Showing archived activity · ' + (rangeButtons.find(button => button.dataset.range === period)?.textContent || period);
        } catch (error) {
            if (ticket === generation) status.textContent = error.message;
        }
    }

    for (const button of rangeButtons) {
        button.addEventListener('click', () => {
            period = button.dataset.range;
            for (const other of rangeButtons) {
                const active = other === button;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-pressed', String(active));
            }
            void load();
        });
    }
    void load();
})();
