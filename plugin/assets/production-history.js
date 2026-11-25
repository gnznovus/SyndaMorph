/* History inspection and explicit conversion of completed archived artifacts. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphTeam;
    const $ = id => document.getElementById(id);
    const root = $('sm-team-panel-history');
    if (!root || !cfg?.historyUrl) return;
    const list = $('sm-history-list'), detail = $('sm-history-detail'), status = $('sm-history-status');
    const search = $('sm-history-search'), category = $('sm-history-category'), sort = $('sm-history-sort');
    const prev = $('sm-history-prev'), next = $('sm-history-next');
    let page = 1, generation = 0, timer, selected = null, renderers = [], exportControl = null;
    const node = (tag, value, cls) => {
        const element = document.createElement(tag);
        if (value !== undefined) element.textContent = value;
        if (cls) element.className = cls;
        return element;
    };
    const button = (label, fn) => {
        const b = node('button', label, 'sm-team-secondary');
        b.type = 'button'; b.addEventListener('click', fn); return b;
    };
    async function get(path = '', params = {}) {
        const url = new URL(cfg.historyUrl + path, window.location.href);
        Object.entries(params).forEach(([key,value]) => url.searchParams.set(key,value));
        const response = await fetch(url, {credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce}});
        if (!response.ok) throw new Error('History request failed (HTTP ' + response.status + ').');
        return response.json();
    }
    function clearDetail() {
        if (exportControl?.busy()) { status.textContent='Wait for the current conversion or save to finish.'; return false; }
        exportControl?.destroy(); exportControl=null;
        renderers.splice(0).forEach(r => r.destroy());
        detail.replaceChildren(); detail.hidden = true; selected = null;
        return true;
    }
    function download(record) {
        const url = URL.createObjectURL(new Blob([JSON.stringify(record,null,2)],{type:'application/json'}));
        const link = node('a'); link.href = url; link.download = record.jobId + '.json'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function section(title, value, kind='markdown') {
        const box = node('details',undefined,'sm-team-ai-agent-output');
        box.append(node('summary',title));
        const body = node('div');
        const renderer = window.SyndaMorphRichContent.mount(body,kind);
        renderer.update(value,true); renderers.push(renderer); box.append(body); detail.append(box);
        return box;
    }
    function preview(html) {
        if (typeof html !== 'string') return;
        const frame = node('iframe');
        frame.title = 'Archived output preview · isolated';
        frame.setAttribute('sandbox','');
        frame.setAttribute('referrerpolicy','no-referrer');
        frame.className = 'sm-history-preview';
        // Remove all raw resource-loading / navigation elements by parsing
        // into an inert template, then apply an independent restrictive CSP.
        const template = node('template');
        template.innerHTML = html;
        template.content.querySelectorAll('script,iframe,frame,object,embed,link,meta,base,form,video,audio,source,svg,math').forEach(e=>e.remove());
        template.content.querySelectorAll('*').forEach(e=>{
            for(const a of [...e.attributes]) if (/^on|^(?:src|srcset|href|action|formaction|ping|poster|data)$/i.test(a.name)) e.removeAttribute(a.name);
        });
        frame.srcdoc = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><meta name="viewport" content="width=device-width,initial-scale=1">' + template.innerHTML;
        detail.append(frame);
    }
    function inspect(record) {
        if (!clearDetail()) return; selected = record; detail.hidden = false;
        detail.append(node('h2',record.jobId + ' : ' + record.title));
        detail.append(node('p',record.status + ' · ' + record.createdAt + ' · Archived data; no AI inference.'));
        const actions = node('div',undefined,'sm-history-actions');
        actions.append(button('Export JSON',()=>download(record)),button('Close',clearDetail));
        if (record.category === 'GEN') {
            actions.prepend(button('Replay', async()=>{
                try {await window.SyndaMorphProductionReplay.replay(record,true);}
                catch(e){status.textContent=e.message;}
            }),button('Open Results', async()=>{
                try {await window.SyndaMorphProductionReplay.replay(record,false);}
                catch(e){status.textContent=e.message;}
            }));
        }
        detail.append(actions);
        const context=record.context||{};
        if(context.workflow==='source-scope-v1') {
            actions.prepend(button('Open scope checkpoint',()=>window.SyndaMorphScope.open(record.id)));
            const manifest=[...(record.events||[])].reverse().find(event=>event.type==='scope_manifest');
            if(manifest) section('Section manifest',JSON.stringify(manifest.data,null,2),'html');
            return;
        }
        if (context.brief) section('Original brief',context.brief).open=true;
        if (context.standards) section('Production standards snapshot',JSON.stringify(context.standards,null,2),'html');
        if (context.settings) section('Model configuration',JSON.stringify(context.settings,null,2),'html');
        if (context.reference) {
            const ref=context.reference;
            const meta={...ref}; delete meta.html; delete meta.capture;
            if(meta.package) meta.package={filename:meta.package.filename,sha256:meta.package.sha256};
            section('Reference metadata',JSON.stringify(meta,null,2),'html').open=true;
            if(ref.html) section('Original HTML',ref.html,'html');
            if(ref.capture) section('Captured source snapshot',JSON.stringify(ref.capture,null,2),'html');
            if(ref.package?.base64) {
                const b=button('Download original ZIP',()=>{
                    const bytes=Uint8Array.from(atob(ref.package.base64),c=>c.charCodeAt(0));
                    const url=URL.createObjectURL(new Blob([bytes],{type:'application/zip'}));
                    const a=node('a');a.href=url;a.download=ref.package.filename||'reference.zip';a.click();
                    setTimeout(()=>URL.revokeObjectURL(url),1000);
                });detail.append(b);
            }
        }
        const events=record.events||[], outputs=new Map();
        const inspectionRecord=[...events].reverse().find(event=>
            (event.type==='job_error'||event.type==='job_complete')&&event.data?.referenceCheckpoint);
        if(inspectionRecord) {
            const retry=button('Retry browser inspection',async()=>{
                retry.disabled=true;
                status.textContent='Inspecting the saved artifact…';
                try {
                    const response=await fetch(cfg.historyUrl+'/'+record.id+'/reinspect-reference',{
                        method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce},
                    });
                    const result=await response.json();
                    if(!response.ok)throw new Error(result.message || 'Browser inspection failed.');
                    status.textContent='Browser inspection passed at '+result.widths.join(', ')+' CSS px. Visual approval remains pending.';
                }catch(e){status.textContent=e.message;}
                finally{retry.disabled=false;}
            });
            actions.append(retry);
        }
        for(const event of events) {
            const d=event.data||{};
            if(event.type==='agent_delta') outputs.set(d.role,(outputs.get(d.role)||'')+d.text);
            if(event.type==='reference_page') {
                section(d.title+' · source',d.source||'','html');
                section(d.title+' · Gutenberg blocks',d.artifact?.content||'','html');
                preview(d.artifact?.preview);
            }
            if(event.type==='inspection_error') section('Recorded inspection error',JSON.stringify(d,null,2),'json').open=true;
            if(event.type==='job_error') {
                const { referenceCheckpoint, ...error } = d;
                section('Recorded error',JSON.stringify(error,null,2),'json').open=true;
            }
        }
        const final=[...events].reverse().find(e=>e.type==='job_complete')?.data;
        if(final?.outputs) Object.entries(final.outputs).forEach(([role,value])=>outputs.set(role,value));
        for(const [role,value] of outputs) section(role,value,role==='developer'?((final?.outputType||context.outputType)==='gutenberg'?'json':'html'):'markdown');
        if(final?.outputs?.developer) {
            if(final.outputType !== 'gutenberg') preview(final.outputs.developer);
            if(record.category==='GEN' && ['completed','needs_review','uncompleted'].includes(record.status)) {
                const receipts=events.filter(e=>e.type==='export_receipt').map(e=>e.data);
                exportControl=window.SyndaMorphAIExport.mount(detail,{html:final.outputType === 'gutenberg' ? null : final.outputs.developer,artifact:final.artifact,identity:record,receipts});
            }
        }
        if(final?.referenceHtml) section('HTML design reference',final.referenceHtml,'html');
        if(final?.sourceQuality) section('Source fidelity',JSON.stringify(final.sourceQuality,null,2),'json');
        if(final?.quality) section('QA findings and revision history',JSON.stringify(final.quality,null,2),'json').open=true;
        if (final?.quality?.evidence) {
            const gallery=node('details',undefined,'sm-team-ai-agent-output');
            gallery.append(node('summary','Recorded QA screenshots'));
            for (const kind of ['reference','native']) {
                for (const sample of final.quality.evidence[kind] || []) {
                    if (!sample.screenshot || !/^[A-Za-z0-9+/=]+$/.test(sample.screenshot)) continue;
                    const figure=node('figure');
                    const img=node('img');
                    img.alt=kind+' screenshot at '+sample.width+'px';
                    img.src='data:image/jpeg;base64,'+sample.screenshot;
                    img.loading='lazy';img.style.maxWidth='100%';
                    figure.append(img,node('figcaption',kind+' · '+sample.width+'px'));
                    gallery.append(figure);
                }
            }
            detail.append(gallery);
        }
        if(final?.usage) section('Metrics and validation',JSON.stringify({usage:final.usage,agentMetrics:final.agentMetrics,checks:final.checks,estimatedCostUsd:final.estimatedCostUsd,elapsedMs:final.elapsedMs},null,2),'html');
        section('Recorded event metadata',JSON.stringify(events.filter(e=>e.type!=='agent_delta').map(e=>({type:e.type,at:e.at,role:e.data?.role})),null,2),'html');
        detail.scrollIntoView({block:'nearest'});
    }
    async function open(id, action) {
        try {
            if (exportControl?.busy()) throw new Error('Wait for the current conversion or save to finish.');
            status.textContent='Loading archived job…';
            const record=await get('/'+id);
            if(action==='export') download(record);
            else if(action==='replay') await window.SyndaMorphProductionReplay.replay(record,true);
            else inspect(record);
            status.textContent='Archived job loaded.';
        }catch(e){status.textContent=e.message;}
    }
    async function load() {
        const ticket=++generation;
        status.textContent='Loading history…';
        try {
            const data=await get('',{q:search.value,category:category.value,sort:sort.value,page});
            if(ticket!==generation)return;
            list.replaceChildren();
            for(const job of data.jobs||[]) {
                const card=node('article',undefined,'sm-history-item');
                const header=node('div',undefined,'sm-history-item-head');
                header.append(node('strong',job.jobId+' : '+job.title),node('span',job.status,'sm-status-pill'));
                card.append(header,node('p',job.createdAt+' · '+(job.category==='GEN'?'Without reference':'With reference')));
                const actions=node('div',undefined,'sm-history-actions');
                actions.append(button('Inspect',()=>open(job.id,'inspect')));
                if(job.category==='GEN')actions.append(button('Replay',()=>open(job.id,'replay')));
                actions.append(button('Export',()=>open(job.id,'export')));card.append(actions);list.append(card);
            }
            status.textContent=(data.jobs||[]).length?'Page '+page:'No matching jobs.';
            prev.disabled=page<=1;next.disabled=!data.more;
        }catch(e){if(ticket===generation)status.textContent=e.message;}
    }
    function changed(){page=1;clearDetail();void load();}
    search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(changed,200);});
    category.addEventListener('change',changed);sort.addEventListener('change',changed);
    $('sm-history-refresh').addEventListener('click',()=>void load());
    prev.addEventListener('click',()=>{if(page>1){page--;void load();}});
    next.addEventListener('click',()=>{page++;void load();});
    // Handles mouse and keyboard tab activation through the shared tab controller.
    new MutationObserver(()=>{if(!root.hidden)void load();}).observe(root,{attributes:true,attributeFilter:['hidden']});
    if(!root.hidden)void load();
})();
