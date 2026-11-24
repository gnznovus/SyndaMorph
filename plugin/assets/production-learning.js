/* Admin-only learning management; no inference, uploads, or background calls. */
(() => {
    'use strict';
    const cfg=window.SyndaMorphTeam, $=id=>document.getElementById(id);
    const list=$('sm-learning-list'), counts=$('sm-learning-counts'), status=$('sm-learning-status');
    if(!cfg?.historyUrl||!list||!counts||!status)return;
    const url=cfg.historyUrl.replace(/\/history\/?$/,'/learning');
    const el=(tag,text)=>{const x=document.createElement(tag);if(text!==undefined)x.textContent=text;return x;};
    const action=(label,callback)=>{const b=el('button',label);b.type='button';b.className='sm-team-secondary';b.addEventListener('click',callback);return b;};
    async function request(path='',body){
        const res=await fetch(url+path,{method:body?'POST':'GET',credentials:'same-origin',
            headers:{'X-WP-Nonce':cfg.nonce,...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
        const result=await res.json();
        if(!res.ok)throw Error(result.message||'Learning operation failed (HTTP '+res.status+').');
        return result;
    }
    function draw(data){
        counts.replaceChildren();list.replaceChildren();
        const c=data.counts||{};
        counts.append(el('p','Lessons: '+(data.lessons?.length||0)+' · Verified '+(c.verified||0)+' · Observed '+(c.observed||0)+' · Stale '+(c.stale||0)+' · Disabled '+(c.disabled||0)));
        for(const lesson of data.lessons||[]){
            const row=el('article');row.className='sm-history-item';
            row.append(el('strong',lesson.category+' / '+lesson.task+' / '+lesson.pattern));
            row.append(el('p',lesson.rule));
            row.append(el('p',lesson.status+' · '+lesson.occurrences+' occurrences · '+lesson.verifiedCount+' verified'));
            const controls=el('div');controls.className='sm-history-actions';
            if(lesson.status!=='stale')controls.append(action(lesson.status==='disabled'?'Enable':'Disable',()=>void manage(lesson.id,lesson.status==='disabled'?'enable':'disable')));
            controls.append(action('Delete',()=>{
                if(window.confirm('Permanently delete this learning record?'))void manage(lesson.id,'delete');
            }));
            row.append(controls);list.append(row);
        }
        status.textContent=(data.lessons?.length||0)+' learning records loaded.';
    }
    async function refresh(){try{draw(await request());}catch(e){status.textContent=e.message;}}
    async function manage(id,operation){try{draw(await request('/'+id,{operation}));}catch(e){status.textContent=e.message;}}
    $('sm-learning-refresh').addEventListener('click',()=>void refresh());
    $('sm-learning-bootstrap').addEventListener('click',async()=>{
        try{status.textContent='Importing only previously compiler-verified schema corrections…';
            const result=await request('/bootstrap',{});draw(result.snapshot);
            status.textContent='Scanned '+result.scannedJobs+' archived jobs; '+result.verifiedLessons+' verified corrections considered (duplicates ignored).';
        }catch(e){status.textContent=e.message;}
    });
    void refresh();
})();
