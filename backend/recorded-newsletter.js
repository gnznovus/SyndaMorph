'use strict';

const {createHash}=require('node:crypto');
const {captureResponsiveViewport}=require('./responsive-capture');
const {inspectTouch}=require('./production-touch');

async function replayRecordedNewsletter(page,sections,diagnostics,input,reference,seal){
    const {width,sourceRef,pinnedSha256}=input;
    const receipt={version:1,width,sourceRef,sourceCopySha256:reference.copySha256};
    const pending=reason=>({...receipt,status:'pending',reason});
    const root=page.locator(sourceRef);
    const target=sections.find(row=>row.sourceRef===sourceRef&&row.geometryAvailable);
    if(!target||await root.count()!==1)return pending('newsletter_region_missing');
    const touch=await inspectTouch(page,{width,actions:[],copySha256:reference.copySha256},seal);
    if(touch.status!=='observed')return pending('newsletter_source_unverified');
    const source=await captureResponsiveViewport(page,sections.filter(row=>row===target||row.kind==='overlay'),width,diagnostics);
    const original=source.sections.find(row=>row.sourceRef===sourceRef);
    if(!original?.screenshot||createHash('sha256').update(Buffer.from(original.screenshot,'base64')).digest('hex')!==pinnedSha256)
        return pending('newsletter_crop_changed');
    const copy=original.inventory?.text;
    if(!Array.isArray(copy)||copy.length!==7||original.inventory.links.length)return pending('newsletter_content_changed');
    const desktop=width===1440;
    if(desktop){
        const dismiss=touch.initial.targets.find(item=>item.permitsClick&&item.destination===null&&/^Continue to website/.test(item.name));
        if(!dismiss)return pending('newsletter_overlay_not_dismissible');
        await page.locator(dismiss.selector).click({timeout:2500});
    }
    if(await root.locator('form,input,select,textarea,a,button,summary').count())return pending('newsletter_form_behavior_unverified');
    const state=()=>root.evaluate(el=>({markup:el.innerHTML,url:location.href}));
    const same=(a,b)=>a.markup===b.markup&&a.url===b.url;
    const inert=copy.filter((_label,i)=>i!==0&&(!desktop||i!==3));
    for(const label of inert){
        const item=root.getByText(label,{exact:true});
        if(await item.count()!==1)return pending('newsletter_label_changed');
        const before=await state();
        await item.click({timeout:2500});
        if(!same(before,await state()))return pending('newsletter_unexpected_action');
    }
    const arrow=await root.locator('svg').evaluateAll(nodes=>nodes.map(el=>({
        viewBox:el.getAttribute('viewBox'),path:el.querySelector('path')?.getAttribute('d'),
        width:el.getAttribute('width'),height:el.getAttribute('height'),strokeWidth:el.getAttribute('stroke-width'),
        strokeLinecap:el.getAttribute('stroke-linecap'),paths:el.children.length,
    })));
    if(arrow.length!==1||arrow[0].viewBox!=='0 0 24 24'||arrow[0].path!=='M6 9l6 6 6-6'||
        arrow[0].width!=='16'||arrow[0].height!=='16'||arrow[0].strokeWidth!=='2'||
        arrow[0].strokeLinecap!=='square'||arrow[0].paths!==1)return pending('newsletter_arrow_changed');
    const choices=[];
    let open=null;
    if(desktop){
        const trigger=root.getByRole('button',{name:copy[3],exact:true});
        if(await trigger.count()!==1)return pending('newsletter_country_trigger_missing');
        const ref=await trigger.getAttribute('data-dc-tpl');
        if(typeof ref!=='string'||!/^\d{1,8}$/.test(ref))return pending('newsletter_country_trigger_missing');
        const control=root.locator('[data-dc-tpl="'+ref+'"]');
        const picker=control.locator('..');
        await control.click({timeout:2500});
        const refs=await picker.evaluate(el=>[...el.children].map(child=>child.getAttribute('data-dc-tpl')));
        if(refs.length!==2||refs[0]!==ref||typeof refs[1]!=='string'||!/^\d{1,8}$/.test(refs[1]))
            return pending('newsletter_country_structure_changed');
        const menu=picker.locator('[data-dc-tpl="'+refs[1]+'"]');
        await menu.waitFor({timeout:2500});
        const options=await menu.evaluate(el=>[...el.children].map(row=>[...row.children].map(col=>col.innerText.trim())));
        if(options.length!==10||options.some(row=>row.length!==2||!row[0]||!/^\+[1-9][0-9]{0,3}$/.test(row[1])))
            return pending('newsletter_country_choices_changed');
        const clip=await menu.evaluate(el=>{
            const r=el.getBoundingClientRect();
            return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height};
        });
        if(!Number.isFinite(clip.y)||clip.y<0||clip.y>36000||clip.x!==620||clip.width!==280||clip.height!==320)
            return pending('newsletter_country_frame_changed');
        const session=await page.context().newCDPSession(page);
        try{
            const shot=async()=> (await session.send('Page.captureScreenshot',{format:'jpeg',quality:75,captureBeyondViewport:true,
                clip:{...clip,y:Math.round(clip.y),scale:1}})).data;
            const image=await shot();
            if(!image||image!==await shot()||image.length>160000)return pending('newsletter_country_frame_unstable');
            open={...clip,image,sha256:createHash('sha256').update(Buffer.from(image,'base64')).digest('hex')};
        }finally{await session.detach();}
        const url=page.url();
        for(const [name,dial] of options){
            await menu.getByText(name,{exact:true}).click({timeout:2500});
            await menu.waitFor({state:'hidden',timeout:2500});
            const label=(await control.innerText()).trim();
            if(!/^[A-Z]{2} \+\d{1,4}$/.test(label)||!label.endsWith(' '+dial)||page.url()!==url)
                return pending('newsletter_country_selection_unverified');
            choices.push({name,dial,label});
            await control.click({timeout:2500});
            await menu.waitFor({timeout:2500});
        }
        await menu.getByText(options[0][0],{exact:true}).click({timeout:2500});
        if((await control.innerText()).trim()!==copy[3]||await menu.isVisible())return pending('newsletter_country_reset_failed');
    }else if(await root.locator('[role=button],[tabindex]').count()){
        return pending('newsletter_compact_country_became_interactive');
    }
    if(seal().length)return pending('newsletter_outbound_action_observed');
    return {...receipt,status:'observed',pinnedSha256,blockedRequestCount:0,copy,inert,arrow:arrow[0],choices,open};
}

module.exports={replayRecordedNewsletter};
