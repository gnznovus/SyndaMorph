/* Real AI workflow in the Production Team workspace; model output is never executed. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphRealTeam;
    const byId = id => document.getElementById(id);
    const root = byId('syndamorph-production-team');
    const button = byId('syndamorph-real-run');
    const brief = byId('syndamorph-team-brief');
    const simulation = byId('syndamorph-team-run');
    const reset = byId('syndamorph-team-reset');
    const notice = byId('syndamorph-team-validation');
    const timeline = byId('syndamorph-team-timeline');
    const review = byId('syndamorph-team-review');
    const results = byId('syndamorph-real-results');
    const feedState = byId('syndamorph-team-feed-state');
    const jobState = byId('syndamorph-team-job-state');
    const handoffs = byId('syndamorph-team-handoffs');
    const outputState = byId('syndamorph-team-output-state');
    const currentJob = byId('syndamorph-team-current-job');
    const resultTitle = byId('syndamorph-team-result-title');
    const viewResult = byId('syndamorph-team-view-result');
    if (!cfg || !root || !button || !brief || !timeline || !results) return;

    const roles = ['boss', 'designer', 'developer', 'validator'];
    const labels = { boss: 'Boss', designer: 'Designer', developer: 'Developer', validator: 'Validator' };
    let busy = false;
    const text = (node, value) => { if (node) node.textContent = value; };
    const tab = name => {
        const button = root.querySelector('.sm-team-tabs [data-panel="' + name + '"]');
        if (button) button.click();
    };
    const status = (state, message) => {
        text(feedState, message);
        if (feedState) feedState.dataset.state = state;
    };
    function setRoster(roleStates) {
        root.querySelectorAll('[data-agent]').forEach(node => {
            const pill = node.querySelector('.sm-status-pill');
            if (!pill) return;
            const state = roleStates[node.dataset.agent] || 'idle';
            pill.dataset.state = state;
            pill.textContent = state === 'success' ? 'Completed' : state === 'error' ? 'Failed' :
                state === 'running' ? 'Running' : 'Standing by';
        });
    }
    function appendActivity(label, body, metric) {
        const li = document.createElement('li');
        li.className = 'sm-team-step';
        const stamp = document.createElement('span');
        stamp.className = 'sm-team-step-count';
        stamp.textContent = 'REAL AI';
        const heading = document.createElement('strong');
        heading.textContent = label;
        const detail = document.createElement('p');
        detail.textContent = body;
        li.append(stamp, heading, detail);
        if (metric) {
            const small = document.createElement('p');
            small.textContent = metric;
            li.append(small);
        }
        timeline.append(li);
    }
    function showReview(title, message) {
        review.replaceChildren();
        const strong = document.createElement('strong');
        const p = document.createElement('p');
        strong.textContent = title;
        p.textContent = message;
        review.append(strong, p);
    }
    function formatMetric(metric) {
        return metric.inputTokens + ' input · ' + metric.outputTokens + ' output tokens · ' +
            (metric.elapsedMs / 1000).toFixed(1) + 's · estimated $' +
            Number(metric.estimatedCostUsd).toFixed(6);
    }
    function showResults(data) {
        results.replaceChildren();
        results.hidden = false;
        const summary = document.createElement('p');
        summary.className = 'sm-team-ai-summary';
        summary.textContent = data.model + ' · ' + (data.elapsedMs / 1000).toFixed(1) +
            's · ' + (data.usage.inputTokens + data.usage.outputTokens) +
            ' tokens · estimated $' + Number(data.estimatedCostUsd).toFixed(6) +
            ' (not a billing guarantee)';
        results.append(summary);
        roles.forEach(role => {
            const details = document.createElement('details');
            details.className = 'sm-team-ai-agent-output';
            const summary = document.createElement('summary');
            const metric = Array.isArray(data.agentMetrics) ? data.agentMetrics.find(item => item.role === role) : null;
            summary.textContent = labels[role] + (metric ? ' — ' + formatMetric(metric) : '');
            const pre = document.createElement('pre');
            pre.textContent = typeof data.outputs?.[role] === 'string' ? data.outputs[role] : 'No output provided';
            details.append(summary, pre);
            results.append(details);
        });
        const checks = document.createElement('p');
        checks.className = 'sm-hint';
        const passed = Object.entries(data.checks || {}).filter(([, value]) => value).length;
        const count = Object.keys(data.checks || {}).length;
        checks.textContent = 'Deterministic checks: ' + passed + '/' + count +
            ' · Generated HTML is review-only; no browser verification or publishing.';
        results.append(checks);
    }
    function resetRealDisplay() {
        results.replaceChildren();
        results.hidden = true;
    }
    root.addEventListener('syndamorph-team-reset', resetRealDisplay);
    root.addEventListener('syndamorph-team-demo-start', resetRealDisplay);

    button.addEventListener('click', async () => {
        if (busy) return;
        const prompt = brief.value.trim();
        if (prompt.length < 12 || prompt.length > 2000) {
            text(notice, 'Enter a production brief between 12 and 2000 characters.');
            return;
        }
        if (!window.confirm('Run Boss → Designer → Developer → Validator with the selected paid model? Only the production brief is sent. No content will be published.')) return;
        busy = true;
        [button, simulation, reset].forEach(node => { if (node) node.disabled = true; });
        root.dispatchEvent(new Event('syndamorph-team-real-start'));
        resetRealDisplay();
        const jobId = 'AI-' + Date.now().toString(36).toUpperCase();
        text(currentJob, jobId + ' · ' + prompt.slice(0, 75));
        text(resultTitle, jobId + ' · Real AI Production Team');
        text(jobState, 'Executing AI');
        text(handoffs, '0 / 4 completed');
        text(outputState, 'Generating');
        text(notice, 'Real AI job submitted. This POC reports individual agent results when the request completes.');
        status('running', 'AI job running');
        setRoster({boss: 'running'});
        timeline.replaceChildren();
        appendActivity('Boss → Designer → Developer → Validator', 'Executing real model calls. The current endpoint returns only after the entire job completes.');
        showReview('AI job running', 'Agent reports and token metrics will appear here when WordPress completes the request.');
        tab('production');

        try {
            const response = await fetch(cfg.url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({brief: prompt}),
            });
            const raw = await response.text();
            let data;
            try { data = JSON.parse(raw); }
            catch (_) { throw new Error('WordPress returned non-JSON (HTTP ' + response.status + '). Check the REST response before retrying; paid calls may already have occurred.'); }
            if (!response.ok) {
                const d = data.data && typeof data.data === 'object' ? data.data : {};
                const info = [
                    d.role ? 'Agent: ' + d.role : '',
                    Number.isInteger(d.completedCalls) ? 'Completed calls: ' + d.completedCalls : '',
                    d.stopReason ? 'Stop: ' + d.stopReason : '',
                    Number.isInteger(d.outputTokens) ? 'Output tokens: ' + d.outputTokens : '',
                ].filter(Boolean).join(' · ');
                const error = new Error((data.message || 'WordPress rejected this request.') +
                    ' [HTTP ' + response.status + ' / ' + (data.code || 'unknown') + ']' +
                    (info ? ' · ' + info : ''));
                error.details = d;
                throw error;
            }
            const metrics = Array.isArray(data.agentMetrics) ? data.agentMetrics : [];
            timeline.replaceChildren();
            const states = {};
            roles.forEach(role => {
                const metric = metrics.find(item => item.role === role);
                const completed = Array.isArray(data.events) && data.events.some(item => item.role === role && item.status === 'completed');
                states[role] = completed ? 'success' : 'idle';
                appendActivity(labels[role] + (completed ? ' · Completed' : ' · No completion'), completed ?
                    'Generation finished and handed off to the next stage.' : 'Not completed.',
                    metric ? formatMetric(metric) : '');
            });
            setRoster(states);
            text(handoffs, roles.filter(role => states[role] === 'success').length + ' / 4 completed');
            text(jobState, data.status === 'needs_review' ? 'Review needed' : 'AI generated');
            text(outputState, 'Review output');
            status('success', '4-agent run finished');
            text(notice, 'AI job finished. Review generated outputs and validation notes; no content was published.');
            showReview('Generation complete · human review required',
                'All four AI agents have responded. Deterministic checks do not verify responsive rendering or Gutenberg-native output.');
            showResults(data);
            if (viewResult) viewResult.hidden = false;
            tab('results');
        } catch (error) {
            const d = error.details || {};
            const completed = Number.isInteger(d.completedCalls) ? d.completedCalls : 0;
            const states = {};
            roles.forEach((role, i) => {
                states[role] = i < completed ? 'success' : role === d.role ? 'error' : 'idle';
            });
            setRoster(states);
            text(handoffs, completed + ' / 4 completed');
            text(jobState, 'Failed');
            text(outputState, 'Not generated');
            status('error', 'AI execution failed');
            timeline.replaceChildren();
            appendActivity('Execution failed', error.message);
            showReview('Generation failed', error.message +
                ' No content was published. Some API calls may have been billed; inspect the error before retrying.');
            text(notice, error.message);
            tab('results');
        } finally {
            busy = false;
            [button, simulation, reset].forEach(node => { if (node) node.disabled = false; });
        }
    });
})();
