/* Production Team: authenticated, incremental SSE from real model calls. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphRealTeam;
    const $ = id => document.getElementById(id);
    const root = $('syndamorph-production-team');
    const run = $('syndamorph-real-run');
    const reset = $('syndamorph-team-reset');
    const brief = $('syndamorph-team-brief');
    const timeline = $('syndamorph-team-timeline');
    const results = $('syndamorph-real-results');
    const review = $('syndamorph-team-review');
    const notice = $('syndamorph-team-validation');
    const feed = $('syndamorph-team-feed-state');
    const job = $('syndamorph-team-job-state');
    const handoffs = $('syndamorph-team-handoffs');
    const output = $('syndamorph-team-output-state');
    const current = $('syndamorph-team-current-job');
    const title = $('syndamorph-team-result-title');
    if (!cfg || !root || !run || !brief || !timeline || !results) return;

    const roles = ['boss', 'designer', 'developer', 'validator'];
    const labels = {boss:'Boss', designer:'Designer', developer:'Developer', validator:'Validator'};
    let running = false;
    let completed = 0;
    let currentIdentity = null;
    let replaying = false;
    const messages = new Map();
    const reportRenderers = [];
    let exportControl = null;
    let exportReceipts = [];
    const clearReports = () => { if (exportControl) exportControl.destroy(); exportControl = null; reportRenderers.splice(0).forEach(renderer => renderer.destroy()); results.replaceChildren(); };
    const clearMessages = () => { messages.forEach(message => message.renderer.destroy()); messages.clear(); };
    const text = (node, value) => { if (node) node.textContent = value; };
    const tab = name => {
        const button = root.querySelector('.sm-team-tabs [data-panel="' + name + '"]');
        if (button) button.click();
    };
    function setFeed(message, state) {
        text(feed, message);
        if (feed) feed.dataset.state = state;
    }
    function setAgent(role, state) {
        const pill = root.querySelector('[data-agent="' + role + '"] .sm-status-pill');
        if (!pill) return;
        pill.dataset.state = state;
        pill.textContent = {running:'Generating', success:'Completed', error:'Failed'}[state] || 'Standing by';
    }
    function addMessage(role) {
        const item = document.createElement('li');
        item.className = 'sm-team-step';
        const stamp = document.createElement('span');
        stamp.className = 'sm-team-step-count';
        stamp.textContent = 'LIVE';
        const name = document.createElement('strong');
        name.textContent = labels[role] + ' · Generating';
        const body = document.createElement('div');
        body.className = 'sm-team-stream-text';
        const renderer = window.SyndaMorphRichContent.mount(body, role === 'developer' ? 'html' : 'markdown');
        const metric = document.createElement('small');
        item.append(stamp, name, body, metric);
        timeline.append(item);
        messages.set(role, {name, body, metric, renderer, value:''});
    }
    function setReview(heading, description) {
        if (!review) return;
        review.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = heading;
        const p = document.createElement('p');
        p.textContent = description;
        review.append(strong, p);
    }
    function formatMetric(metric) {
        return metric.inputTokens + ' input · ' + metric.outputTokens + ' output tokens · ' +
            (metric.elapsedMs / 1000).toFixed(1) + 's · est. $' +
            Number(metric.estimatedCostUsd).toFixed(6);
    }
    function appendReport(data) {
        clearReports();
        results.hidden = false;
        const summary = document.createElement('p');
        summary.className = 'sm-team-ai-summary';
        summary.textContent = data.model + ' · ' + (data.elapsedMs / 1000).toFixed(1) + 's · ' +
            (data.usage.inputTokens + data.usage.outputTokens) + ' tokens · est. $' +
            Number(data.estimatedCostUsd).toFixed(6) + ' (not guaranteed billing)';
        results.append(summary);
        roles.forEach(role => {
            const details = document.createElement('details');
            details.className = 'sm-team-ai-agent-output';
            details.open = role === 'developer';
            const heading = document.createElement('summary');
            const metric = (data.agentMetrics || []).find(item => item.role === role);
            heading.textContent = labels[role] + (metric ? ' — ' + formatMetric(metric) : '');
            const body = document.createElement('div');
            const renderer = window.SyndaMorphRichContent.mount(body, role === 'developer' ? 'html' : 'markdown');
            renderer.update(data.outputs?.[role] || 'No output', true);
            reportRenderers.push(renderer);
            details.append(heading, body);
            results.append(details);
        });
        if (typeof data.outputs?.developer === 'string') {
            const heading = document.createElement('h3');
            heading.textContent = 'HTML preview · isolated / review only';
            const frame = document.createElement('iframe');
            frame.title = 'Generated HTML preview, no scripts or navigation';
            frame.setAttribute('sandbox', '');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            frame.style.width = '100%';
            frame.style.minHeight = '480px';
            frame.style.border = '1px solid #ddd';
            frame.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:">' +
                '<meta name="viewport" content="width=device-width, initial-scale=1">' + data.outputs.developer;
            results.append(heading, frame);
        }
        const checks = document.createElement('p');
        checks.className = 'sm-hint';
        checks.textContent = 'Structural checks: ' +
            Object.values(data.checks || {}).filter(Boolean).length + '/' +
            Object.keys(data.checks || {}).length +
            '. No Gutenberg conversion, browser QA, or publishing has occurred.';
        results.append(checks);
        if (typeof data.outputs?.developer === 'string') {
            exportControl = window.SyndaMorphAIExport.mount(results, {html:data.outputs.developer, identity:currentIdentity, receipts:exportReceipts});
        }
    }
    function onEvent(type, data) {
        if (type === 'ready') {
            if (data.jobId) {
                currentIdentity = data;
                const label = data.jobId + ' : ' + data.title;
                text(current, label); text(title, label);
            }
            setFeed('Connected · waiting for Boss', 'running');
        } else if (type === 'job_title' && currentIdentity) {
            currentIdentity = {...currentIdentity, title:data.title};
            const label = currentIdentity.jobId + ' : ' + data.title;
            text(current, label); text(title, label);
        } else if (type === 'agent_start' && roles.includes(data.role)) {
            setAgent(data.role, 'running');
            addMessage(data.role);
            setFeed(labels[data.role] + ' generating', 'running');
        } else if (type === 'agent_delta' && messages.has(data.role) && typeof data.text === 'string') {
            const message = messages.get(data.role);
            message.value += data.text;
            // Bound DOM text independently from provider-side output limits.
            message.renderer.update(message.value);
            const nearBottom = timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight < 200;
            if (nearBottom) timeline.scrollTop = timeline.scrollHeight;
        } else if (type === 'agent_complete' && messages.has(data.role)) {
            const message = messages.get(data.role);
            message.renderer.update(message.value, true);
            message.name.textContent = labels[data.role] + ' · Completed';
            message.metric.textContent = data.metric ? formatMetric(data.metric) : '';
            setAgent(data.role, 'success');
            completed++;
            text(handoffs, completed + ' / 4 completed');
        } else if (type === 'job_error') {
            throw new Error(data.message || 'Production stream failed.');
        } else if (type === 'job_complete') {
            if (completed !== 4) throw new Error('Incomplete agent handoff: ' + completed + ' / 4.');
            text(job, data.status === 'needs_review' ? 'Review needed' : 'Generated');
            text(output, 'Review output');
            text(notice, 'Production finished. Review every agent output; nothing was published.');
            setFeed('4 / 4 agents completed', 'success');
            setReview('Generation complete · review required',
                'AI output is available below. Visual fidelity and Gutenberg block validity remain unverified.');
            appendReport(data);
            if (!replaying) tab('results');
        }
    }
    async function readEvents(response) {
        if (!response.ok) {
            let message = 'Production request failed (HTTP ' + response.status + ').';
            try { const data = await response.json(); message = data.message || message; } catch (_) {}
            throw new Error(message);
        }
        if (!response.body) throw new Error('This browser cannot read the streaming response.');
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let finished = false;
        while (true) {
            const next = await reader.read();
            if (next.done) break;
            buffer += decoder.decode(next.value, {stream:true}).replace(/\r\n/g, '\n');
            if (buffer.length > 300000) throw new Error('Stream frame exceeded the client limit.');
            let boundary;
            while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                const frame = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);
                let event = '';
                const parts = [];
                frame.split('\n').forEach(line => {
                    if (line.startsWith('event:')) event = line.slice(6).trim();
                    if (line.startsWith('data:')) parts.push(line.slice(5).trimStart());
                });
                if (!event || !parts.length) continue;
                let payload;
                try { payload = JSON.parse(parts.join('\n')); }
                catch (_) { throw new Error('Malformed production event.'); }
                onEvent(event, payload);
                if (event === 'job_complete') finished = true;
            }
        }
        if (!finished) throw new Error('Stream disconnected before completion; some provider calls may have been billed.');
    }
    root.addEventListener('syndamorph-team-reset', () => {
        if (running || replaying || exportControl?.busy()) return;
        clearReports();
        results.hidden = true;
        clearMessages();
        completed = 0;
    });
    // History uses the exact same event consumer and output renderer as live jobs.
    window.SyndaMorphProductionReplay = Object.freeze({
        busy: () => running || replaying,
        async replay(record, animated = false) {
            if (running || replaying || exportControl?.busy()) throw new Error('A production job is already active.');
            replaying = true;
            run.disabled = true;
            if (reset) reset.disabled = true;
            try {
                clearMessages(); clearReports(); timeline.replaceChildren();
                results.hidden = true; completed = 0;
                currentIdentity = record;
                exportReceipts = (record.events || []).filter(e => e.type === 'export_receipt').map(e => e.data);
                roles.forEach(role => setAgent(role, 'idle'));
                text(current, record.jobId + ' : ' + record.title);
                text(title, record.jobId + ' : ' + record.title);
                text(handoffs, '0 / 4 completed');
                text(job, 'Replay');
                text(output, 'Recorded output');
                setFeed('Historical replay · no AI calls', 'running');
                tab('production');
                const events = record.events || [];
                for (let i=0; i<events.length; i++) {
                    const event = events[i];
                    if (event.type === 'job_error') {
                        setReview('Recorded failure', event.data.message || 'Job failed.');
                        text(job, 'Failed');
                        setFeed('Recorded failure', 'error');
                        const failed = event.data.role || roles[completed];
                        if (failed) setAgent(failed, 'error');
                        break;
                    }
                    onEvent(event.type, event.data);
                    if (animated && event.type !== 'agent_delta') await new Promise(resolve => setTimeout(resolve, 300));
                    if (animated && i % 20 === 0) await new Promise(resolve => setTimeout(resolve, 16));
                }
                text(current, record.jobId + ' : ' + record.title);
                text(title, record.jobId + ' : ' + record.title);
                if (record.status === 'running' || record.status === 'interrupted') {
                    text(job, record.status); setFeed('Partial recorded history', 'idle');
                } else if (record.status !== 'failed') setFeed('Historical replay complete · no AI calls', 'success');
                if (!animated && !results.hidden) tab('results');
            } finally {
                replaying = false; run.disabled = false; if (reset) reset.disabled = false;
            }
        }
    });
    run.addEventListener('click', async () => {
        if (running || replaying || exportControl?.busy()) return;
        const prompt = brief.value.trim();
        if (prompt.length < 12 || prompt.length > 2000) {
            text(notice, 'Enter a production brief between 12 and 2000 characters.');
            return;
        }
        if (!window.confirm('Start four paid AI agent calls? The Production Brief only will be sent; no content will be published.')) return;
        running = true;
        run.disabled = true;
        if (reset) reset.disabled = true;
        clearMessages();
        completed = 0;
        clearReports();
        results.hidden = true;
        timeline.replaceChildren();
        roles.forEach(role => setAgent(role, 'idle'));
        currentIdentity = null;
        exportReceipts = [];
        text(current, 'Allocating production job…');
        text(title, 'Allocating production job…');
        text(job, 'Running');
        text(handoffs, '0 / 4 completed');
        text(output, 'Generating');
        text(notice, 'Connecting live production stream...');
        setFeed('Connecting', 'running');
        setReview('Production in progress', 'Real agent messages appear in the feed as model output arrives.');
        tab('production');
        try {
            const response = await fetch(cfg.url, {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json', 'X-WP-Nonce':cfg.nonce, 'Accept':'text/event-stream'},
                body:JSON.stringify({brief:prompt}),
            });
            await readEvents(response);
        } catch(error) {
            text(job, 'Failed');
            text(output, 'Not completed');
            setFeed('Production failed', 'error');
            text(notice, error.message);
            setReview('Production interrupted', error.message + ' Completed agent text remains in the live feed. Do not retry blindly.');
            const next = roles[completed];
            if (next) setAgent(next, 'error');
        } finally {
            running = false;
            run.disabled = false;
            if (reset) reset.disabled = false;
        }
    });
})();
