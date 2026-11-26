(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const cfg = window.SyndaMorphTeam;
    const scope = $('syndamorph-team-scope');
    const panel = $('syndamorph-team-scope-progress');
    if (!scope || !panel || !cfg?.scopeUrl) return;
    const source = $('syndamorph-team-source');
    const sourceType = $('syndamorph-team-source-type');
    const format = $('syndamorph-team-output-type');
    const renderMode = $('syndamorph-team-render-mode');
    const section = $('syndamorph-team-section');
    const run = $('syndamorph-real-run');
    const message = $('syndamorph-team-validation');
    let manifest = null;
    let running = false;
    let activeRegion = null;

    function node(tag, value) {
        const element = document.createElement(tag);
        if (value !== undefined) element.textContent = value;
        return element;
    }
    async function request(url, body, params = null) {
        const target = new URL(url, window.location.href);
        for (const [name, value] of Object.entries(params || {})) target.searchParams.set(name, value);
        const headers = {'X-WP-Nonce': cfg.nonce};
        if (body) headers['Content-Type'] = 'application/json';
        const response = await fetch(target, {method: body ? 'POST' : 'GET', credentials: 'same-origin', headers,
            body: body ? JSON.stringify(body) : undefined});
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) throw new Error(data?.message || 'Scope response unavailable (HTTP ' + response.status + '). Refresh before retrying.');
        return data;
    }
    const url = () => cfg.scopeUrl + '/' + manifest.id;
    const targets = () => Object.values(manifest.regions).filter(row => manifest.mode === 'page' || row.id === (manifest.selected || section.value));
    const budget = () => Number(manifest?.budgetUsd ?? cfg.scopeBudgetUsd);
    const canSpend = () => manifest.meteringComplete && manifest.spentUsd < budget() * 0.8;
    const budgetField = node('input');
    budgetField.type = 'number'; budgetField.min = '0.01'; budgetField.step = '0.01';
    budgetField.id = 'syndamorph-scope-budget'; budgetField.className = 'sm-control';
    const layoutOptions = node('details');
    const layoutWidths = [768,375].map(width => {
        const checkbox = node('input');
        checkbox.type = 'checkbox';
        checkbox.value = String(width);
        const label = node('label');
        label.append(checkbox, document.createTextNode(' ' + width + ' px'));
        return {checkbox,label};
    });
    const layoutReason = node('textarea');
    layoutReason.id = 'syndamorph-scope-layout-reason';
    layoutReason.maxLength = 240;
    layoutReason.rows = 2;
    layoutReason.className = 'sm-control';
    const layoutReasonLabel = node('label', 'Reason for changing the source layout');
    layoutReasonLabel.htmlFor = layoutReason.id;
    layoutOptions.append(node('summary','Allow a responsive source layout change'),
        node('p','Select the widths where layout may change. Source copy, links, assets, behavior and desktop fidelity still require verification.'),
        ...layoutWidths.map(({label}) => label), layoutReasonLabel, layoutReason);
    function clearLayoutOptions() {
        for (const {checkbox} of layoutWidths) checkbox.checked = false;
        layoutReason.value = '';
        layoutOptions.open = false;
    }
    function button(label, callback) {
        const control = node('button', label);
        control.type = 'button';
        control.className = 'sm-team-secondary';
        control.disabled = running;
        control.addEventListener('click', () => execute(callback));
        return control;
    }
    function link(label, href) {
        const anchor = node('a', label);
        anchor.href = href;
        return anchor;
    }
    function render() {
        panel.replaceChildren();
        panel.hidden = !manifest;
        if (!manifest) return;
        const saved = targets().filter(row => row.patternId).length;
        const label = manifest.mode === 'page' ? 'Full Page' : 'Section';
        panel.append(node('h3', label + ' · ' + manifest.jobId),
            node('p', (manifest.status === 'completed' ? 'Completed' : 'Acceptance pending') + ' · ' + saved + '/' + targets().length + ' patterns · $' + Number(manifest.spentUsd).toFixed(6) + ' estimated'));
        if (manifest.budgetUsd) panel.append(node('p', 'Scope budget $' + budget().toFixed(2) + ' · ' + (manifest.outputTokens || 0).toLocaleString() + ' output tokens used'));
        if (!manifest.budgetUsd) {
            for (const {checkbox} of layoutWidths) checkbox.disabled = running;
            layoutReason.disabled = running;
            panel.append(layoutOptions);
        } else if (manifest.layoutAuthorization) {
            panel.append(node('p','Source layout change authorized at ' + manifest.layoutAuthorization.widths.join(', ') +
                ' px. ' + manifest.layoutAuthorization.reason));
        }
        if (manifest.mode === 'page' || manifest.budgetUsd) {
            const budgetLabel = node('label', manifest.budgetUsd ? 'New total budget (USD)' : 'Full Page total budget (USD)');
            budgetLabel.htmlFor = budgetField.id;
            budgetField.disabled = running;
            if (!budgetField.value) budgetField.value = budget().toFixed(2);
            panel.append(budgetLabel,budgetField);
            if (manifest.budgetUsd) panel.append(button('Raise scope budget', async () => {
                manifest = await request(url(), {action:'increase_budget',limitUsd:Number(budgetField.value)});
            }));
        }
        if (!manifest.meteringComplete) panel.append(node('p', 'Metering is incomplete. New paid attempts are paused.'));
        const list = node('ol');
        for (const row of targets()) {
            const entry = node('li');
            const checkpoint = row.recoveryStage;
            const states = {pattern_saved:'Pattern saved',needs_review:'Editor verification pending',uncompleted:'QA pending',completed:'Pattern save pending'};
            entry.append(node('strong', row.label), node('span', ' · ' + (row.id === activeRegion ? 'Running' :
                row.designDecisionRequired ? 'Design decision pending' : states[row.status] || row.status)));
            for (const attempt of row.attempts) entry.append(node('span', ' · ' + attempt.jobId));
            if (row.patternEditor) entry.append(node('span',' · '),link('Edit pattern',row.patternEditor));
            if (row.blockers.length || row.issue) {
                const details = node('details');
                details.append(node('summary','Limitations and QA'));
                for (const issue of [...row.blockers, row.issue].filter(Boolean)) details.append(node('p',issue));
                entry.append(details);
            }
            if (!row.patternId && ['needs_review','completed'].includes(row.status)) entry.append(button('Validate and save pattern', () => finishRegion(row.id)));
            if (row.status === 'blocked' && row.imageReplayAvailable) {
                if (row.imageEvidence) entry.append(button('Verify native image strip', async () => {
                    manifest = await request(url(), {action:'stage_image_strip',region:row.id});
                    await finishRegion(row.id);
                }));
                else entry.append(button('Verify and import archived image', async () => {
                    manifest = await request(url(), {action:'verify_image',region:row.id});
                }));
            }
            if (row.status === 'blocked' && row.linkedImageReplayAvailable) {
                if (row.linkedImageEvidence) entry.append(node('p','Archived image and declared link verified. Native QA pending.'));
                else entry.append(button('Verify archived image and link', async () => {
                    manifest = await request(url(), {action:'verify_image',region:row.id});
                }));
            }
            if (row.checkpointAvailable) entry.append(button('Recheck browser', () => recheck(row)));
            if (checkpoint && manifest.costBounded &&
                Number(manifest.spentUsd) + Number(manifest.unmeteredReserveUsd) < budget() * 0.8) {
                const label = checkpoint === 'planning' ? 'Resume from saved work order' :
                    checkpoint === 'staged_planning' ? 'Retry saved interaction classification' :
                    checkpoint === 'staged_format' ? 'Resume formatted work order' :
                    checkpoint === 'staged_disclosure_qa' ? 'Resume disclosure implementation QA' :
                    checkpoint === 'designer_css' ? 'Continue retained Designer CSS' :
                    checkpoint === 'design_evidence' ? 'Review bounded browser evidence' :
                    checkpoint === 'developer' ? 'Resume Developer' :
                    checkpoint === 'native_review' ? 'Review recovered implementation' : 'Resume design review';
                entry.append(button(label, async () => {
                    if (window.confirm('Resume the saved job within its original budget?'))
                        await runRegion(row.id, 'resume_checkpoint');
                }));
            }
            if (manifest.mode === 'section' && row.status === 'failed' && !row.checkpointAvailable && !checkpoint &&
                !row.designDecisionRequired && !manifest.layoutAuthorization) entry.append(button('Recapture section evidence', async () => {
                manifest = await request(url(), {action:'recapture'});
            }));
            if (canSpend() && ['failed','uncompleted'].includes(row.status) && !row.checkpointAvailable && !checkpoint && !row.designDecisionRequired) {
                entry.append(button('Retry section', async () => {
                    if (window.confirm('Start one new paid attempt for this section within the remaining scope budget?')) await runRegion(row.id, 'retry');
                }));
            }
            list.append(entry);
        }
        panel.append(list);
        if (manifest.page?.editor) panel.append(link('Open WordPress draft', manifest.page.editor),node('span',' · '),link('Preview draft',manifest.page.preview),
            node('p', (manifest.page.allRegions ? 'All regions placed' : 'Partial draft') + ' · ' +
                (manifest.page.editorVerified ? 'Gutenberg verified' : 'Gutenberg verification pending') + ' · ' +
                (manifest.page.qa?.passed ? 'Page visual QA passed' : (manifest.page.qa?.reason || 'Page visual QA not accepted'))));
        panel.append(button('Refresh checkpoint', async () => { manifest = await request(url()); }));
        if (manifest.mode === 'page' && saved > 0) panel.append(button('Assemble and verify draft', finishPage));
        if (manifest.budgetUsd && canSpend() && targets().some(row => row.status === 'ready'))
            panel.append(button('Resume remaining sections', async () => {
                if (window.confirm('Run the remaining ready sections within this scope’s recorded budget?')) await runRemaining();
            }));
    }
    async function execute(operation) {
        if (running) return;
        running = true;
        run.disabled = true;
        render();
        try { await operation(); }
        catch (error) { message.textContent = error.message; }
        finally { running = false; run.disabled = false; activeRegion = null; render(); }
    }
    async function finishRegion(id) {
        const candidate = await request(url(), null, {region: id});
        if (!candidate.eligible) throw new Error('This section is still pending source or production QA.');
        if (candidate.imageStrip) {
            const count = window.SyndaMorphAIExport.validateNative(candidate.content);
            manifest = await request(url(), {action:'save_pattern',region:id,checksum:candidate.checksum,blockCount:count,valid:true});
            return;
        }
        const validationUrl = cfg.historyUrl + '/' + candidate.id + '/validate-native';
        let count;
        try { count = window.SyndaMorphAIExport.validateNative(candidate.content); }
        catch (error) {
            await request(validationUrl, {valid: false, contentSha256: candidate.checksum, failureKind:'serialization'});
            throw error;
        }
        await request(validationUrl, {valid:true, contentSha256:candidate.checksum, blockCount: count});
        manifest = await request(url(), {action:'save_pattern',region:id});
    }
    async function recheck(row) {
        const result = await request(cfg.historyUrl + '/' + row.attempts.at(-1).id + '/reinspect-reference', {});
        manifest = await request(url());
        message.textContent = result.responsive
            ? 'Responsive QA ' + result.responsive.status + '. The saved artifact was used.'
            : result.message || 'Browser inspection completed. Full visual approval remains pending.';
    }
    async function runRegion(id, action = 'run') {
        activeRegion = id;
        render();
        manifest = await request(url(), {action,region:id,confirmed:true});
        activeRegion = null;
        if (manifest.regions[id].status === 'needs_review') {
            try { await finishRegion(id); }
            catch (error) { message.textContent = manifest.regions[id].label + ': ' + error.message; }
        }
        render();
    }
    async function finishPage() {
        manifest = await request(url(), {action:'assemble'});
        render();
        const draft = await request(url(), null, {draft:'1'});
        const count = window.SyndaMorphAIExport.validateNative(draft.content,7200);
        manifest = await request(url(), {action:'verify_page',checksum:draft.checksum,blockCount:count,valid:true});
    }
    async function runRemaining() {
        for (const row of targets()) {
            if (!canSpend()) break;
            if (row.status === 'ready') await runRegion(row.id);
        }
        if (manifest.mode === 'page' && targets().some(row => row.patternId)) await finishPage();
    }
    function sync() {
        const fullPage = sourceType.value === 'url' && scope.value === 'page';
        if (fullPage && format.value !== 'gutenberg') {
            format.value = 'gutenberg';
            format.dispatchEvent(new Event('change'));
        }
        format.disabled = fullPage;
        renderMode.disabled = fullPage;
        renderMode.closest('.sm-team-capture-options').hidden = fullPage;
    }
    scope.addEventListener('change',sync);
    sourceType.addEventListener('change',sync);
    section.addEventListener('change',render);
    $('syndamorph-team-reset').addEventListener('click',() => { if (!running) { manifest = null; render(); } });
    window.SyndaMorphScope = {
        usesSource: () => sourceType.value === 'url' && (scope.value === 'page' ||
            (format.value === 'gutenberg' && !!source.value.trim() && !$('sm-ai-source')?.open)),
        async discover(raw) {
            if (scope.value !== 'page' && renderMode.value === 'static') throw new Error('Native AI source inspection requires browser capture.');
            return request(cfg.scopeUrl, {url:raw,scope:scope.value});
        },
        set(result) { manifest = result; budgetField.value = ''; clearLayoutOptions(); render(); },
        clear() { if (!running) { manifest = null; render(); } },
        preview: id => request(url(), null, {preview:'1',selected:id}),
        async open(id) {
            await execute(async () => {
                const saved = await request(cfg.scopeUrl + '/' + id);
                sourceType.value = 'url';
                sourceType.dispatchEvent(new Event('change'));
                scope.value = saved.mode;
                scope.dispatchEvent(new Event('change'));
                source.value = saved.sourceUrl;
                format.value = 'gutenberg';
                format.dispatchEvent(new Event('change'));
                section.replaceChildren(...Object.values(saved.regions).map(row => new Option(row.label,row.id)));
                if (saved.selected) section.value = saved.selected;
                section.hidden = saved.mode === 'page';
                section.labels[0].hidden = section.hidden;
                $('syndamorph-team-source-inspection').hidden = false;
                $('syndamorph-team-convert').disabled = saved.mode === 'page';
                $('syndamorph-team-source-summary').textContent = 'Pinned source from ' + saved.jobId;
                manifest = saved;
                budgetField.value = '';
                clearLayoutOptions();
                $('sm-team-tab-setup').click();
                message.textContent = 'Loaded the saved scope checkpoint.';
            });
        },
        start: brief => execute(async () => {
            if (!manifest || (manifest.sourceUrl !== source.value.trim() && manifest.requestedUrl !== source.value.trim()) || manifest.mode !== scope.value)
                throw new Error('Inspect the current page URL before starting production.');
            if (manifest.budgetUsd) throw new Error('Use the saved scope controls to resume or retry. Inspect the source again to start a new conversion.');
            const selected = manifest.mode === 'section' ? section.value : null;
            if (manifest.mode === 'section' && !manifest.regions[selected]) throw new Error('Select a captured section.');
            const ready = targets().filter(row => row.status === 'ready').length;
            const limitUsd = manifest.mode === 'page' ? Number(budgetField.value) : budget();
            if (!Number.isFinite(limitUsd) || limitUsd <= 0) throw new Error('Enter a positive total budget.');
            const widths = layoutWidths.filter(({checkbox}) => checkbox.checked).map(({checkbox}) => Number(checkbox.value));
            const reason = layoutReason.value.trim();
            if (widths.length && reason.length < 12) throw new Error('Describe the layout change in at least 12 characters.');
            if (ready && !window.confirm('Run ' + ready + ' section job(s) within a total $' + limitUsd.toFixed(2) + ' estimated AI budget?')) return;
            const sourceLayoutChange = widths.length ? {authorized:true,widths,reason} : undefined;
            manifest = await request(url(), {action:'configure',brief,region:selected,limitUsd,sourceLayoutChange});
            render();
            await runRemaining();
        }),
    };
    sync();
})();
