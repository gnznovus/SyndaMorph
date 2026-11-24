/* Explicit conversion and save of generated HTML; never invokes AI. */
(() => {
    'use strict';
    const el = (tag, value, cls) => {
        const n = document.createElement(tag);
        if (value !== undefined) n.textContent = value;
        if (cls) n.className = cls;
        return n;
    };
    const button = (label, cls) => {
        const n = el('button', label, cls);
        n.type = 'button';
        return n;
    };
    async function post(url, data) {
        const response = await fetch(url, {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-WP-Nonce':window.SyndaMorphImport.nonce},
            body:JSON.stringify(data),
        });
        let result;
        try { result = await response.json(); }
        catch (_) { throw new Error('Unexpected server response (HTTP '+response.status+'). Check WordPress before retrying.'); }
        if (!response.ok) throw new Error(result.message || 'Save failed (HTTP '+response.status+').');
        return result;
    }
    function validateNative(content) {
        const api=window.wp?.blocks;
        if (!api || typeof api.parse!=='function' || typeof api.getBlockType!=='function' || typeof api.validateBlock!=='function')
            throw new Error('WordPress block validation API unavailable. Cannot verify native Gutenberg content.');
        const parsed=api.parse(content);
        if (!Array.isArray(parsed) || !parsed.length) throw new Error('Gutenberg parser returned no native blocks.');
        let count=0;
        function visit(blocks) {
            for(const block of blocks) {
                count++;
                if (count>200 || !block?.name?.startsWith('core/') || !api.getBlockType(block.name))
                    throw new Error('Unknown or unsupported Gutenberg block.');
                const check=api.validateBlock(block);
                if(!check || check.isValid!==true) throw new Error('Gutenberg serialization invalid for '+block.name+'. Check generated block attributes.');
                if (block.innerBlocks?.length) visit(block.innerBlocks);
            }
        }
        visit(parsed);
        return count;
    }
    function mount(parent, {html, artifact, identity, receipts=[]}) {
        const native = artifact?.schemaVersion === 1 && typeof artifact.content === 'string' && typeof artifact.preview === 'string';
        if (!native && (!window.SyndaMorphConverter || typeof html !== 'string' || !html.trim())) return;
        const panel = el('section',undefined,'sm-ai-export');
        const heading = el('h3','Save to WordPress');
        const help = el('p',native ? 'Review the compiled native Gutenberg blocks before saving. No conversion or additional AI calls.' : 'Convert the generated section into Gutenberg blocks and review the converted preview before saving. No additional AI calls.','sm-hint');
        const prepare = button(native ? 'Preview Gutenberg' : 'Convert & Preview','sm-primary');
        const status = el('p','','sm-hint');
        status.setAttribute('role','status');
        const preview = el('iframe');
        preview.title = 'Converted Gutenberg preview';
        preview.setAttribute('sandbox','');
        preview.setAttribute('referrerpolicy','no-referrer');
        preview.className = 'sm-history-preview';
        preview.hidden = true;
        const controls = el('div',undefined,'sm-team-export-actions');
        controls.hidden = true;
        const mode = el('select',undefined,'sm-control');
        mode.setAttribute('aria-label','Save as');
        for (const [value,label] of [['draft','Draft page'],['pattern','Unsynced pattern']]) {
            const option=el('option',label);option.value=value;mode.append(option);
        }
        const save=button('Save Draft','sm-team-secondary');
        const open=button('Save & Open Editor','sm-primary');
        controls.append(mode,save,open);
        panel.append(heading,help);
        if (!native) panel.append(prepare);
        panel.append(preview,controls,status);
        parent.append(panel);
        const saved=new Map();
        for(const item of receipts) if(item?.id && ['draft','pattern'].includes(item.mode)) saved.set(item.mode,item);
        let prepared=null,busy=false,disposed=false;
        const jobTitle=String(identity?.title || 'Generated section');
        const editor = id => window.SyndaMorphImport.editUrl+'?post='+encodeURIComponent(id)+'&action=edit';
        function refresh() {
            const receipt=saved.get(mode.value);
            prepare.disabled=busy;
            mode.disabled=busy;
            save.disabled=busy||!prepared||!!receipt;
            save.textContent=receipt?(mode.value==='draft'?'Draft saved':'Pattern saved'):(mode.value==='draft'?'Save Draft':'Save as Pattern');
            open.hidden=mode.value!=='draft';
            open.disabled=busy||!prepared;
            open.textContent=receipt?'Open Editor':'Save & Open Editor';
        }
        const preparePreview = async()=>{
            if(busy||disposed)return;
            prepared=null; preview.hidden=true; controls.hidden=true;
            busy=true;refresh();status.textContent=native ? 'Preparing native block preview…' : 'Converting HTML into Gutenberg blocks…';
            try {
                // Do not execute model-generated JavaScript during conversion.
                const result=native ? artifact : await window.SyndaMorphConverter.prepareImport(html,false);
                if(disposed)return;
                if(typeof result.content!=='string'||!result.content.includes('<!-- wp:')) throw new Error('No Gutenberg blocks were generated.');
                const validatedBlocks=native ? validateNative(result.content) : null;
                prepared=result;
                let archiveNote='';
                if(native && identity?.id && artifact?.contentSha256 && window.SyndaMorphTeam?.historyUrl) {
                    try {
                        const verified=await post(window.SyndaMorphTeam.historyUrl+'/'+identity.id+'/validate-native',
                            {contentSha256:artifact.contentSha256,valid:true,blockCount:validatedBlocks});
                        archiveNote=verified.status==='completed' ? ' Archived job marked Completed.' : ' Editor validation recorded.';
                        if (verified.status==='completed') panel.dispatchEvent(new CustomEvent('syndamorph-native-verified',{bubbles:true,detail:{id:identity.id}}));
                    } catch (_) { archiveNote=' Archive validation could not be recorded; inspect History.'; }
                }
                const template=el('template');template.innerHTML=result.preview;
                template.content.querySelectorAll('script,iframe,frame,object,embed,link,meta,base,form,video,audio,source,svg,math').forEach(n=>n.remove());
                template.content.querySelectorAll('*').forEach(n=>{
                    for(const a of [...n.attributes]) if(/^on|^(?:src|srcset|href|action|formaction|ping|poster|data)$/i.test(a.name))n.removeAttribute(a.name);
                });
                preview.srcdoc='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:"><meta name="viewport" content="width=device-width,initial-scale=1">'+template.innerHTML;
                preview.hidden=false;controls.hidden=false;prepare.textContent=native ? 'Refresh Preview' : 'Reconvert & Preview';
                const missing=Array.isArray(result.missing)?result.missing.length:0;
                const styles=Array.isArray(result.stylesheets)?result.stylesheets.length:(result.stylesheets?.missing?.length||0)+(result.stylesheets?.unverified?.length||0);
                status.textContent=(native ? 'WordPress registered-block validation passed ('+validatedBlocks+' blocks). '+archiveNote+' ' : 'Review the converted output before saving. ')+(missing?missing+' missing asset(s). ':'')+(styles?styles+' stylesheet notice(s). ':'')+(native ? 'Visual fidelity remains governed by recorded QA findings.' : 'Visual fidelity and editor validity are not automatically certified.');
            }catch(e){if(!disposed)status.textContent=e.message;}
            finally{busy=false;if(!disposed)refresh();}
        };
        if (native) { void preparePreview(); }
        else prepare.addEventListener('click',()=>void preparePreview());
        async function persist(openEditor) {
            if(busy||!prepared||disposed)return;
            const kind=mode.value;
            if(saved.has(kind)){
                if(openEditor&&kind==='draft')window.location.assign(editor(saved.get(kind).id));
                return;
            }
            busy=true;refresh();status.textContent='Saving '+(kind==='draft'?'draft page':'unsynced pattern')+'…';
            try {
                // Reuse the existing WordPress save endpoints, never publish a page.
                const result=await post(kind==='pattern'?window.SyndaMorphImport.patternUrl:window.SyndaMorphImport.restUrl,
                    {title:jobTitle,content:prepared.content,...(kind==='draft'?{status:'draft'}:{})});
                const id=Number(result.id);
                if(!Number.isSafeInteger(id)||id<=0)throw new Error('Save response has no valid ID. Check WordPress before retrying.');
                const receipt={mode:kind,id};
                saved.set(kind,receipt);
                let message=kind==='draft'?'Draft saved.':'Unsynced pattern saved to SyndaMorph.';
                if(identity?.id){
                    try{await post(window.SyndaMorphTeam.historyUrl+'/'+identity.id+'/export-receipt',receipt);}
                    catch(_){message+=' Archive receipt could not be recorded; check WordPress before saving again.';}
                }
                if(!disposed){status.textContent=message;if(openEditor&&kind==='draft')window.location.assign(editor(id));}
            }catch(e){if(!disposed)status.textContent=e.message;}
            finally{busy=false;if(!disposed)refresh();}
        }
        save.addEventListener('click',()=>void persist(false));
        open.addEventListener('click',()=>void persist(true));
        mode.addEventListener('change',refresh);
        refresh();
        return {destroy(){disposed=true;panel.remove();},busy:()=>busy};
    }
    window.SyndaMorphAIExport=Object.freeze({mount});
})();
