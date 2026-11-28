/* SyndaMorph Overview: read-only, evidence-backed job summaries. */
(() => {
    'use strict';
    const root = document.getElementById('syndamorph-overview');
    const cfg = window.SyndaMorphOverview;
    if (!root || !cfg?.url) return;
    const $ = id => document.getElementById(id);
    const status = $('sm-overview-status');
    const period = $('sm-overview-period');
    let ticket = 0;

    function element(tag, text, className) {
        const el = document.createElement(tag);
        if (text !== undefined) el.textContent = text;
        if (className) el.className = className;
        return el;
    }

    function stat(name, value) {
        const target = root.querySelector('[data-overview-stat="' + name + '"]');
        if (target) target.textContent = value;
    }

    function breakdown(id, rows, total) {
        const container = $(id);
        container.replaceChildren();
        if (!total) {
            container.append(element('p', 'No archived jobs in this period.', 'sm-overview-empty'));
            return;
        }
        for (const [label, count] of rows) {
            if (!count) continue;
            const line = element('div', undefined, 'sm-overview-bar-row');
            const heading = element('div', undefined, 'sm-overview-bar-label');
            heading.append(element('span', label), element('strong', String(count)));
            const track = element('div', undefined, 'sm-overview-bar-track');
            const bar = element('span', undefined, 'sm-overview-bar-fill');
            bar.style.width = (count / total * 100).toFixed(1) + '%';
            track.append(bar);
            line.append(heading, track);
            container.append(line);
        }
    }

    function recent(jobs) {
        const container = $('sm-overview-jobs');
        container.replaceChildren();
        if (!jobs.length) {
            container.append(element('p', 'No recent jobs for this period.', 'sm-overview-empty'));
            return;
        }
        const list = element('ul', undefined, 'sm-overview-job-list');
        for (const job of jobs) {
            const item = element('li', undefined, 'sm-overview-job');
            const link = element('a', job.jobId + ' · ' + job.title);
            link.href = 'admin.php?page=syndamorph&tab=production&view=history';
            const sub = element('small', job.createdAt + ' · ' + job.category + ' · ' + job.status);
            item.append(link, sub);
            list.append(item);
        }
        container.append(list);
    }

    async function load() {
        const current = ++ticket;
        status.textContent = 'Loading recorded production activity…';
        $('sm-overview-refresh').disabled = true;
        try {
            const url = new URL(cfg.url, window.location.href);
            url.searchParams.set('period', period.value);
            const response = await fetch(url, { credentials: 'same-origin', headers: {'X-WP-Nonce': cfg.nonce} });
            if (!response.ok) throw new Error('Overview request failed (HTTP ' + response.status + ').');
            const data = await response.json();
            if (current !== ticket) return;
            stat('total', String(data.total));
            stat('completed', String(data.completed));
            stat('attention', String(data.attention));
            stat('rate', data.completionRate === null ? '—' : data.completionRate + '%');
            const statuses = Object.entries(data.statuses || {}).sort((a,b) => b[1] - a[1]);
            breakdown('sm-overview-pipeline', statuses, data.total);
            breakdown('sm-overview-modes', Object.entries(data.categories || {}), data.total);
            recent(data.recent || []);
            status.textContent = 'Showing saved jobs for ' + period.options[period.selectedIndex].text + '.';
        } catch (error) {
            if (current === ticket) status.textContent = error.message;
        } finally {
            if (current === ticket) $('sm-overview-refresh').disabled = false;
        }
    }
    period.addEventListener('change', load);
    $('sm-overview-refresh').addEventListener('click', load);
    void load();
})();
