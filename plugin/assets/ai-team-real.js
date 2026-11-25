/* Production Team: authenticated, incremental SSE from real model calls. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphRealTeam;
    const $ = id => document.getElementById(id);
    const root = $('syndamorph-production-team');
    const run = $('syndamorph-real-run');
    const reset = $('syndamorph-team-reset');
    const brief = $('syndamorph-team-brief');
    const format = $('syndamorph-team-output-type');
    const timeline = $('syndamorph-team-timeline');
    const results = $('syndamorph-real-results');
    const review = $('syndamorph-team-review');
    const notice = $('syndamorph-team-validation');
    const feed = $('syndamorph-team-feed-state');
    const job = $('syndamorph-team-job-state');
    const handoffs = $('syndamorph-team-handoffs');
    const output = $('syndamorph-team-output-state');
    const current = $('syndamorph-team-current-job');
    const operation = $('syndamorph-team-operation');
    const activeAgent = $('syndamorph-team-active-agent');
    const liveCostLabel = $('syndamorph-team-live-cost');
    const pauseControl = $('syndamorph-team-pause');
    const resumeControl = $('syndamorph-team-resume');
    const stopControl = $('syndamorph-team-stop');
    const abortControl = $('syndamorph-team-abort');
    const title = $('syndamorph-team-result-title');
    if (!cfg || !root || !run || !brief || !timeline || !results) return;

    const roles = ['boss', 'designer', 'developer'];
    const taskRoles = {reference:'developer',validator:'boss',validation:'boss',revision_validation:'boss'};
    const taskLabels = {reference:'HTML reference',validation:'QA validation',validator:'QA validation',revision_validation:'Scoped QA validation',repair:'Technical repair',schema_repair:'Schema repair',final_approval:'Final approval'};
    const initialRoles = () => roles;
    let legacyNativeReplay = false;
    const requiredTasks = () => activeFormat === 'gutenberg' ?
        ['boss','designer',legacyNativeReplay?'reference':'final_approval','developer','validation'] :
        ['boss','designer','developer','validator'];
    const completedRoles = new Set();
    const labels = {boss:'Boss', designer:'Designer', reference:'HTML Reference', developer:'Developer', validator:'Validator'};
    const syncRoster = () => {};
    format?.addEventListener('change', () => {
        if (!running && !replaying) { activeFormat = format.value; syncRoster(); }
    });
    let running = false;
    let liveCost = 0;
    let paused = false;
    const showCost = () => text(liveCostLabel, String.fromCharCode(36) + liveCost.toFixed(6));
    const setControls = enabled => {
        if (stopControl) stopControl.disabled = !enabled;
        if (abortControl) abortControl.disabled = !enabled;
        if (pauseControl) pauseControl.disabled = !enabled;
        if (resumeControl) { resumeControl.disabled = !paused; resumeControl.hidden = !paused; }
        if (pauseControl) pauseControl.hidden = paused;
    };
    let completed = 0;
    let currentIdentity = null;
    let activeFormat = 'html';
    let replaying = false;
    const messages = new Map();
    const messageRenderers = [];
    let followFeed = true;
    let followFrame = 0;
    const nearBottom = () => timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight < 80;
    const followLatest = () => { if (!followFeed || followFrame) return; followFrame = requestAnimationFrame(() => { followFrame = 0; if (followFeed) timeline.scrollTop = timeline.scrollHeight; }); };
    timeline.addEventListener('scroll', () => { followFeed = nearBottom(); }, {passive:true});
    const feedObserver = new MutationObserver(followLatest);
    feedObserver.observe(timeline,{childList:true,subtree:true,characterData:true});
    if (typeof ResizeObserver !== 'undefined') { const observer = new ResizeObserver(followLatest); observer.observe(timeline); }
    const reportRenderers = [];
    let exportControl = null;
    let exportReceipts = [];
    const clearReports = () => { if (exportControl) exportControl.destroy(); exportControl = null; reportRenderers.splice(0).forEach(renderer => renderer.destroy()); results.replaceChildren(); };
    const clearMessages = () => { messageRenderers.splice(0).forEach(renderer => renderer.destroy()); messages.clear(); followFeed=true; };
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
    function addMessage(role, task=role) {
        const item = document.createElement('li');
        item.className = 'sm-team-step';
        const stamp = document.createElement('span');
        stamp.className = 'sm-team-step-count';
        stamp.textContent = 'LIVE';
        const name = document.createElement('strong');
        name.textContent = labels[role] + (taskLabels[task]?' · '+taskLabels[task]:'') + ' · Generating';
        const body = document.createElement('div');
        body.className = 'sm-team-stream-text';
        const renderer = window.SyndaMorphRichContent.mount(body, task === 'reference' ? 'html' : role === 'developer' ? (activeFormat === 'gutenberg' ? 'json' : 'html') : 'markdown');
        const metric = document.createElement('small');
        item.append(stamp, name, body, metric);
        timeline.append(item);
        messageRenderers.push(renderer);
        messages.set(role, {name, body, metric, renderer, value:'',task});
        followLatest();
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
        summary.textContent = (data.outputType === 'gutenberg' ? 'Native Gutenberg' : 'HTML / CSS') + ' · ' + data.model + ' · ' + (data.elapsedMs / 1000).toFixed(1) + 's · ' +
            (data.usage.inputTokens + (data.usage.cacheReadTokens||0) + (data.usage.cacheWriteTokens||0) + data.usage.outputTokens) + ' tokens · est. $' +
            Number(data.estimatedCostUsd).toFixed(6) + ' (not guaranteed billing)';
        results.append(summary);
        ['boss','designer','developer','validator'].forEach(role => {
            const details = document.createElement('details');
            details.className = 'sm-team-ai-agent-output';
            details.open = role === 'developer';
            const heading = document.createElement('summary');
            const metric = (data.agentMetrics || []).find(item => (item.task || item.role) === role || (role==='validator' && item.task==='validation'));
            heading.textContent = (role==='validator'?'Boss · QA validation':labels[role]) + (metric ? ' — ' + formatMetric(metric) : '');
            const body = document.createElement('div');
            const renderer = window.SyndaMorphRichContent.mount(body, role === 'reference' ? 'html' : role === 'developer' ? (data.outputType === 'gutenberg' ? 'json' : 'html') : 'markdown');
            renderer.update(data.outputs?.[role] || 'No output', true);
            reportRenderers.push(renderer);
            details.append(heading, body);
            results.append(details);
        });
        if (data.referenceHtml) {
            const ref=document.createElement('details');
            ref.className='sm-team-ai-agent-output';
            const h=document.createElement('summary');h.textContent='HTML design reference';
            const pre=document.createElement('pre');pre.textContent=data.referenceHtml;
            ref.append(h,pre);results.append(ref);
        }
        if (data.quality) {
            const qa=document.createElement('details');qa.className='sm-team-ai-agent-output';qa.open=true;
            const h=document.createElement('summary');h.textContent='Browser QA, issues and targeted revisions';
            const pre=document.createElement('pre');pre.textContent=JSON.stringify({terminationReason:data.quality.terminationReason,score:data.quality.score,issueRegistry:data.quality.issueRegistry,issues:data.quality.issues,revisions:data.quality.revisions,pixelComparison:data.quality.pixelComparison,browserInspected:data.quality.browserInspected,visionInspected:data.quality.visionInspected,nativeEditorVerified:data.quality.nativeEditorVerified},null,2);
            qa.append(h,pre);results.append(qa);
        }
        if (data.quality?.evidence) {
            const gallery=document.createElement('details');
            gallery.className='sm-team-ai-agent-output';
            const heading=document.createElement('summary');heading.textContent='QA browser screenshots (desktop and mobile)';
            gallery.append(heading);
            for(const kind of ['reference','native']) {
                for(const sample of data.quality.evidence[kind] || []) {
                    if(!sample.screenshot || !/^[A-Za-z0-9+/=]+$/.test(sample.screenshot)) continue;
                    const figure=document.createElement('figure');
                    const img=document.createElement('img');
                    img.alt=kind+' screenshot at '+sample.width+'px';
                    img.src='data:image/jpeg;base64,'+sample.screenshot;
                    img.loading='lazy';img.style.maxWidth='100%';img.style.height='auto';
                    const caption=document.createElement('figcaption');
                    caption.textContent=kind+' · '+sample.width+'px';
                    figure.append(img,caption);gallery.append(figure);
                }
            }
            results.append(gallery);
        }
        if (data.outputType !== 'gutenberg' && typeof data.outputs?.developer === 'string') {
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
        const qaPending = data.stageStatus?.browserQa === 'pending';
        checks.className = 'sm-hint';
        checks.textContent = 'Structural checks: ' +
            Object.values(data.checks || {}).filter(Boolean).length + '/' +
            Object.keys(data.checks || {}).length +
            (qaPending ? '. Required visual QA is pending.' :
                data.outputType === 'gutenberg' ? '. Browser and vision QA collected; native editor validation is not certified.' : '. No Gutenberg conversion, browser QA, or publishing has occurred.');
        results.append(checks);
        if (typeof data.outputs?.developer === 'string') {
            exportControl = window.SyndaMorphAIExport.mount(results, {html:data.outputType === 'gutenberg' ? null : data.outputs.developer, artifact:data.artifact, identity:currentIdentity, receipts:exportReceipts});
        }
    }
    function showFailure(data) {
        const stage = data.stage || 'unknown';
        const role = roles.includes(data.role) ? data.role : '';
        const generated = data.generationCompleted === true;
        const message = data.message || 'Production failed without a recorded explanation.';
        const label = ({compilation:'Native block compilation',generation:'Model generation',budget:'Budget guard',context:'Context preparation',output_limit:'Output size validation',output_safety:'Output safety validation',preflight:'Production preflight',system:'Production system',connection:'Connection / stream'})[stage] || 'Unclassified failure';
        const item = document.createElement('li');
        item.className = 'sm-team-step sm-team-failure';
        item.setAttribute('role','alert');
        const heading = document.createElement('strong');
        heading.textContent = label + ' · Failed';
        const reason = document.createElement('p');
        reason.textContent = message;
        const diagnostics = document.createElement('p');
        diagnostics.textContent = [data.code && 'Code: '+data.code, role && 'Agent: '+labels[role], data.task && 'Task: '+(taskLabels[data.task]||data.task), data.field && 'Field: '+data.field, data.stopReason && 'Stop reason: '+data.stopReason, Number.isInteger(data.outputTokens) && 'Output tokens: '+data.outputTokens].filter(Boolean).join(' · ');
        const recovery = document.createElement('p');
        recovery.textContent = generated ? 'Model generation completed; its output was rejected by the system. Previous handoffs and generated output remain in the recorded feed. Remaining agents were not started. Inspect History before retrying.' : 'Production stopped. Inspect the recorded output and History before retrying; do not assume failed calls were free.';
        item.append(heading,reason,diagnostics,recovery);
        timeline.append(item);
        if (role) {
            const entry = messages.get(role);
            if (entry) {
                entry.renderer.update(entry.value,true);
                entry.name.textContent = labels[role] + (generated ? ' · Generated / rejected' : ' · Interrupted');
                if (data.metric) entry.metric.textContent = formatMetric(data.metric);
            }
            if (generated) {
                setAgent(role,'success');
                const pill = root.querySelector('[data-agent="'+role+'"] .sm-status-pill');
                if (pill) pill.textContent = 'Generated';
            } else if (stage === 'generation' || (!data.stage && entry)) setAgent(role,'error');
        }
        // Never leave an active model labelled "Generating" after a terminal event.
        for (const agent of roles) {
            const pill = root.querySelector('[data-agent="'+agent+'"] .sm-status-pill');
            if (pill?.dataset.state === 'running') {
                setAgent(agent,'idle');
                pill.textContent = 'Interrupted';
                const entry = messages.get(agent);
                if (entry) { entry.renderer.update(entry.value,true); entry.name.textContent = labels[agent]+' · Interrupted'; }
            }
        }
        setControls(false);
        text(operation,'Production failed');
        text(job,'Failed'); text(output,'Not completed');
        if (Number.isInteger(data.completedCalls) && generated) text(handoffs,completed+' / 4 handoffs · '+data.completedCalls+' model calls completed');
        setFeed(label+' failed','error');
        text(notice,message);
        setReview(label+' failed',message + (data.field ? ' Field: '+data.field+'.' : '')+' Inspect History before retrying.');
        timeline.scrollTop = timeline.scrollHeight;
    }
    function onEvent(type, data) {
        if(type==='agent_complete' || type==='agent_failure'){
            liveCost += Math.max(0,Number(data.metric?.estimatedCostUsd)||0);
            showCost();
        }
        const task=data.task || data.role;
        if (activeFormat==='gutenberg' && task==='reference' && type==='agent_start') legacyNativeReplay=true;
        if (taskRoles[data.role]) data={...data,role:taskRoles[data.role],task};
        if (task==='validator' && activeFormat==='gutenberg') data={...data,task:'validation'};
        if (type === 'ready') {
            legacyNativeReplay = false;
            activeFormat = data.outputType || 'html';
            syncRoster();
            if (data.jobId) {
                currentIdentity = data;
                const label = data.jobId + ' : ' + data.title;
                text(current, label); text(title, label);
            }
            setFeed('Connected · waiting for Boss', 'running');
            text(operation,'Waiting for Boss');
            text(activeAgent,'Boss · classification');
            setControls(running && !replaying);
        } else if (type === 'job_title' && currentIdentity) {
            currentIdentity = {...currentIdentity, title:data.title};
            const label = currentIdentity.jobId + ' : ' + data.title;
            text(current, label); text(title, label);
        } else if (type === 'job_resumed') {
            paused=false;setControls(true);
            text(job,'Running');text(operation,'Restoring saved checkpoint without repeated paid calls');
            setFeed('Resumed · replaying completed operations','running');
        } else if (type === 'agent_start' && roles.includes(data.role)) {
            setAgent(data.role, 'running');
            text(activeAgent,(labels[data.role]||data.role)+' · '+(taskLabels[data.task]||data.task||'generation'));
            text(operation,taskLabels[data.task]||data.task||'Generating');
            addMessage(data.role,data.task || data.role);
            setFeed(labels[data.role] + ' generating', 'running');
        } else if (type === 'agent_delta' && messages.has(data.role) && typeof data.text === 'string') {
            const message = messages.get(data.role);
            message.value += data.text;
            // Bound DOM text independently from provider-side output limits.
            message.renderer.update(message.value);
            followLatest();
        } else if (type === 'agent_complete' && messages.has(data.role)) {
            const message = messages.get(data.role);
            message.renderer.update(message.value, true);
            message.name.textContent = labels[data.role] + (taskLabels[message.task]?' · '+taskLabels[message.task]:'') + ' · Completed';
            message.metric.textContent = data.metric ? formatMetric(data.metric) : '';
            setAgent(data.role, 'success');
            const finishedTask=data.task || data.role;
            if (requiredTasks().includes(finishedTask) && !completedRoles.has(finishedTask)) { completedRoles.add(finishedTask); completed++; }
            text(handoffs, completed + ' / ' + requiredTasks().length + ' initial tasks completed');
        } else if (type === 'design_surgery') {
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Designer surgery · '+data.status+' · review round '+data.round;
            timeline.append(item);followLatest();
        } else if (type === 'control_requested') {
            text(operation,(data.action||'Control')+' requested · finishing current operation');
        } else if (type === 'repair_route') {
            text(operation,'Repair '+data.round+' · '+(data.mode||data.route||'surgery'));
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Repair '+data.round+' · '+data.id+' · '+(data.route==='engine'?'Deterministic engine':'Developer');timeline.append(item);followLatest();
        } else if (type === 'handoff') {
            const item=document.createElement('li');item.className='sm-team-step';
            const label=data.type==='assignment'?'Work order assigned':
                data.type==='design_review'?'Design '+(data.decision==='approved'?'approved':'revision requested'):
                data.type==='approved_design'?'Approved design handed to Developer':
                data.type==='qa_review'?'Boss QA '+(data.decision==='approved'?'approved':'changes requested'):'Team handoff';
            item.textContent=label+' · '+(data.from||'agent')+' → '+(data.to||'team')+
                (Array.isArray(data.issues)&&data.issues.length?' · '+data.issues.length+' issues':'');
            timeline.append(item);followLatest();
        } else if (type === 'working_plan') {
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Developer working plan persisted · revision '+(data.revision||1);
            timeline.append(item);followLatest();
        } else if (type === 'repair_escalation') {
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Boss escalation · '+(data.decision || data.state || 'blocked')+
                ' · '+(Array.isArray(data.issueIds)?data.issueIds.slice(0,3).join(', '):'')+
                (data.nextAction?' · '+data.nextAction:'');
            timeline.append(item);followLatest();
        } else if (type === 'schema_repair') {
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Schema repair '+data.attempt+' · '+data.status+' · '+(data.field||'')+(data.message?' · '+data.message:'');timeline.append(item);
        } else if (type === 'revision') {
            const item=document.createElement('li');item.className='sm-team-step';
            item.textContent='Revision '+data.round+' · '+data.id+' · '+(data.accepted?'Accepted':data.status==='duplicate'?'Duplicate skipped':'Not accepted')+(Number.isFinite(data.before)&&Number.isFinite(data.after)?' · findings '+data.before+' → '+data.after:'')+(data.detail?' · '+data.detail:'');
            timeline.append(item);
        } else if (type === 'job_error') {
            showFailure(data);
            const error = new Error(data.message || 'Production stream failed.');
            error.reported = true;
            throw error;
        } else if (type === 'job_complete') {
            setControls(false);
            if(data.controlAction){
                const action=data.controlAction;
                const label=action==='paused'?'Paused':action==='stopped'?'Stopped':'Aborted';
                paused=action==='paused';
                text(job,label);text(output,paused?'Checkpoint saved':'Production '+label.toLowerCase());
                text(operation,paused?'Suspended at a safe boundary':'Terminated at a safe operation boundary');
                setFeed(label+' by operator',paused?'idle':'error');
                setControls(false);
                text(notice,data.message||label+' by operator. Recorded work is preserved in History.');
                setReview(label+' by operator',paused?
                    'Resume from the saved job checkpoint. Completed agent calls will be replayed from History without new provider charges.':
                    'Already completed calls may have been billed. Existing events remain in History; no automatic restart.');
                for(const agent of roles)if(root.querySelector('[data-agent="'+agent+'"] .sm-status-pill')?.dataset.state==='running')setAgent(agent,'idle');
                return;
            }
            const qaPending=data.status==='uncompleted' && data.stageStatus?.browserQa==='pending' &&
                !!data.quality?.inspection && data.quality?.bossApproved===false;
            if (!qaPending && requiredTasks().some(role => !completedRoles.has(role))) throw new Error('Incomplete initial agent handoffs: ' + completed + ' / ' + requiredTasks().length + '.');
            text(job, data.status === 'uncompleted' ? 'Uncompleted' : data.status === 'needs_review' ? 'Review needed' : 'Generated');
            text(output, 'Review output');
            text(notice, 'Production finished. Review every agent output; nothing was published.');
            setFeed(requiredTasks().length+' initial tasks finished · '+(data.status || 'review'), data.status === 'uncompleted' ? 'error' : 'success');
            const stages=data.stageStatus;
            const qaBlocked=stages?.browserQa === 'blocked';
            const blockers=Array.isArray(stages?.blockerIds)?stages.blockerIds.slice(0,5).join(', '):'';
            const reason=data.quality?.terminationReason || 'quality review pending';
            const detail=qaPending
                ? (stages.compilation==='passed' ? 'Native draft compiled. ' : 'Design checkpoint saved. ') +
                    'Required visual QA is pending. Review the inspection diagnostics in History.'
                : qaBlocked
                ? 'Design approved · Native compilation passed · Visual QA blocked ('+reason+
                    (blockers?' · '+blockers:'')+'). Last accepted artifact preserved. WordPress editor review remains pending.'
                : stages ? 'Design approved · Native compilation passed · Browser QA passed · WordPress editor review pending.'
                    : data.quality?'Browser and vision evidence recorded; WordPress editor validation pending.':'AI output available; visual fidelity unverified.';
            setReview(data.status === 'uncompleted' ? 'Production uncompleted · visual QA needs work' : 'Generation complete · review required',detail);
            text(notice,(qaPending||qaBlocked)?detail:'Production finished. Review every agent output; nothing was published.');
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
            if (buffer.length > 2000000) throw new Error('Stream frame exceeded the client limit.');
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
    root.addEventListener('syndamorph-native-verified', event => {
        if (currentIdentity?.id === event.detail?.id) {
            text(job,'Completed');text(output,'Native Gutenberg validated');
            setFeed('WordPress editor compatibility confirmed','success');
        }
    });
    root.addEventListener('syndamorph-team-reset', () => {
        if (running || replaying || exportControl?.busy()) return;
        clearReports();
        results.hidden = true;
        clearMessages();
        completed = 0; completedRoles.clear();
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
                results.hidden = true; completed = 0; completedRoles.clear();
                activeFormat = record.context?.outputType || 'html';
                syncRoster();
                currentIdentity = record;
                exportReceipts = (record.events || []).filter(e => e.type === 'export_receipt').map(e => e.data);
                roles.forEach(role => setAgent(role, 'idle'));
                text(current, record.jobId + ' : ' + record.title);
                text(title, record.jobId + ' : ' + record.title);
                text(handoffs, '0 / ' + requiredTasks().length + ' completed');
                text(job, 'Replay');
                text(output, 'Recorded output');
                setFeed('Historical replay · no AI calls', 'running');
                tab('production');
                const events = record.events || [];
                for (let i=0; i<events.length; i++) {
                    const event = events[i];
                    if (event.type === 'job_error') {
                        showFailure(event.data || {});
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
                } else if (record.status !== 'failed') {
                    if (record.status === 'completed') { text(job,'Completed'); text(output,'Validated or archived output'); }
                    else if (record.status === 'uncompleted') { text(job,'Uncompleted'); text(output,'Best artifact available'); }
                    setFeed('Historical replay complete · no AI calls', 'success');
                }
                if (!animated && !results.hidden) tab('results');
            } finally {
                replaying = false; run.disabled = false; if (reset) reset.disabled = false;
            }
        }
    });
    const requestControl = async action => {
        if(!running||replaying||!currentIdentity?.id)return;
        const verb=action==='pause'?'Pause':action==='stop'?'Stop':'Abort';
        const explanation=action==='abort'?
            'Abort at the next safe boundary? An active provider request may still finish and incur charges.':
            action==='pause'?
            'Pause at the next safe operation boundary? Completed responses will be retained for resume.':
            'Stop gracefully at the next operation boundary? Recorded work will remain in History.';
        if(!window.confirm(explanation))return;
        stopControl.disabled=true;abortControl.disabled=true;
        if(pauseControl)pauseControl.disabled=true;
        try {
            const base=cfg.url.split('/ai/team-stream')[0];
            const response=await fetch(base+'/history/'+encodeURIComponent(currentIdentity.id)+'/control',{
                method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},
                body:JSON.stringify({action}),
            });
            const result=await response.json();
            if(!response.ok)throw new Error(result.message||'Control request failed.');
            text(operation,verb+' requested · current paid call may finish');
            text(notice,verb+' requested. Waiting for the next safe operation boundary.');
        }catch(error){
            text(notice,error.message);
            setControls(running&&!replaying);
        }
    };
    pauseControl?.addEventListener('click',()=>requestControl('pause'));
    resumeControl?.addEventListener('click',async()=>{
        if(!paused||!currentIdentity?.id||running||replaying)return;
        running=true;paused=false;setControls(false);
        text(operation,'Connecting checkpoint continuation');
        try{
            const base=cfg.url.split('/ai/team-stream')[0];
            const response=await fetch(base+'/history/'+encodeURIComponent(currentIdentity.id)+'/resume',{
                method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce,'Accept':'text/event-stream'},
                body:'{}',
            });
            await readEvents(response);
        }catch(error){
            paused=true;
            text(notice,error.message);
            setFeed('Resume blocked · checkpoint remains paused','error');
        }finally{running=false;setControls(false);}
    });
    stopControl?.addEventListener('click',()=>requestControl('stop'));
    abortControl?.addEventListener('click',()=>requestControl('abort'));
    syncRoster();
    run.addEventListener('click', async () => {
        if (running || replaying || exportControl?.busy()) return;
        const prompt = brief.value.trim();
        activeFormat = format?.value || 'html';
        syncRoster();
        if (prompt.length < 12 || prompt.length > 2000) {
            text(notice, 'Enter a production brief between 12 and 2000 characters.');
            return;
        }
        if (!window.confirm(activeFormat === 'gutenberg' ? 'Start three agents with five initial paid tasks and optional budget-limited revisions? Nothing will be published.' : 'Start three agents with four paid tasks? Nothing will be published.')) return;
        running = true;
        paused = false;
        liveCost = 0;showCost();
        setControls(false);
        run.disabled = true;
        if (reset) reset.disabled = true;
        clearMessages();
        completed = 0; completedRoles.clear();
        clearReports();
        results.hidden = true;
        timeline.replaceChildren();
        roles.forEach(role => setAgent(role, 'idle'));
        currentIdentity = null;
        exportReceipts = [];
        text(current, 'Allocating production job…');
        text(title, 'Allocating production job…');
        text(job, 'Running');
        text(operation,'Connecting production stream');
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
                body:JSON.stringify({brief:prompt,outputType:activeFormat}),
            });
            await readEvents(response);
        } catch(error) {
            if (!error.reported) showFailure({stage:'connection',message:error.message});
        } finally {
            running = false;
            setControls(false);
            run.disabled = false;
            if (reset) reset.disabled = false;
        }
    });
})();
