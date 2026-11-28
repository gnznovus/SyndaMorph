'use strict';

const {compareScreenshots,compareExactCrop}=require('./compare');
const {MAX_SCREENSHOT}=require('./inspection-contract');

const frameImage=(image,limit=MAX_SCREENSHOT)=>typeof image==='string'&&image.length<=limit&&
    /^\/9j\/[a-zA-Z0-9+/]+={0,2}$/.test(image);
function validNewsletterFrames(input,widths){
    const {frames,open,runtime}=input;
    const required=widths.filter(width=>[1440,768,375].includes(width));
    return typeof runtime==='string'&&runtime.length>0&&Buffer.byteLength(runtime)<=8192&&
        frames&&typeof frames==='object'&&!Array.isArray(frames)&&Object.keys(frames).length===required.length&&
        required.every(width=>{
            const v=frames[width];
            return v&&Number.isFinite(v.top)&&v.top>=104&&v.top<36000&&v.extent===(width===1440?451:411)&&frameImage(v.image);
        })&&(widths.includes(1440)?open?.x===620&&open?.width===280&&open?.height===320&&
            Number.isFinite(open.y)&&open.y===frames[1440].top-104&&frameImage(open.image,160000):open===null);
}

async function countryScreenshot(page,frame){
    const session=await page.context().newCDPSession(page);
    try{
        const {x,y,width,height}=frame;
        const {data}=await session.send('Page.captureScreenshot',{format:'jpeg',quality:75,captureBeyondViewport:true,
            clip:{x,y:Math.round(y),width,height,scale:1}});
        return compareExactCrop(frame.image,data,width,height);
    }finally{await session.detach();}
}

async function checkNewsletter(page,width,contract,screenshot){
    const region=page.locator('.wp-block-syndamorph-recorded-newsletter');
    if(await region.count()!==1)return {responsive:false};
    const desktop=width>=1024;
    const active=region.locator(desktop?'.sm-newsletter-desktop':'.sm-newsletter-compact');
    const inactive=region.locator(desktop?'.sm-newsletter-compact':'.sm-newsletter-desktop');
    if(!await active.isVisible()||await inactive.isVisible())return {responsive:false};
    const box=await active.boundingBox();
    const expected=desktop?Math.max(width,1280):width;
    const responsive=box.x===0&&box.y===0&&box.width===expected&&Math.round(box.height)===(desktop?451:411)&&
        await active.evaluate(()=>document.documentElement.scrollWidth)===expected;
    const frame=contract.frames[width];
    const copy=await active.innerText();
    let closedRatio=null;let openRatio=null;
    if(frame&&responsive){
        const image=await screenshot(page,width,frame.extent,frame.top);
        const sample=screenshot=>({width,extent:frame.extent,screenshot});
        closedRatio=compareScreenshots([sample(frame.image)],[sample(image)],true,true)[0].pixelDifferenceRatio;
    }
    const noInputs=await region.locator('a[href],input,form,select,textarea,summary').count()===0;
    const state=async()=>[await active.innerHTML(),page.url()];
    const original=await state();
    let functional=noInputs;
    for(const label of await active.locator('.sm-newsletter-field,.sm-newsletter-consent,.sm-newsletter-subscribe').all()){
        await label.click({timeout:2500});
        functional=functional&&JSON.stringify(await state())===JSON.stringify(original);
    }
    if(!desktop)return {responsive,functional:functional&&await active.locator('button,[role=button]').count()===0,
        copy,choices:[],closedRatio,openRatio};
    const trigger=active.locator('.sm-newsletter-trigger');
    const menu=active.locator('.sm-newsletter-menu');
    const option=menu.locator('.sm-newsletter-choice');
    if(await trigger.count()!==1||await menu.count()!==1||await option.count()!==10)
        return {responsive,functional:false};
    const choices=await option.evaluateAll(nodes=>nodes.map(el=>({name:el.children[0].textContent,
        dial:el.children[1].textContent,label:el.dataset.smCountry})));
    const defaultLabel=(await trigger.innerText()).trim();
    await region.evaluate((el,top)=>{
        const spacer=document.createElement('div');
        spacer.style.height=top+'px';
        el.before(spacer);
    },frame?.top??1200);
    if(contract.open){
        await trigger.click({timeout:2500});
        const natural=await menu.evaluate(el=>{
            const r=el.getBoundingClientRect();
            return [r.x+scrollX,r.y+scrollY,r.width,r.height];
        });
        const {x,y,width,height}=contract.open;
        if(JSON.stringify(natural)===JSON.stringify([x,y,width,height]))
            openRatio=await countryScreenshot(page,contract.open);
        await page.keyboard.press('Escape');
        functional=functional&&!await menu.isVisible()&&await trigger.evaluate(el=>document.activeElement===el);
    }
    await trigger.press('Enter',{timeout:2500});
    for(let i=0;i<choices.length;i++){
        if(i)await trigger.click({timeout:2500});
        if(await trigger.getAttribute('aria-expanded')!=='true')return {responsive,functional:false};
        await option.nth(i).click({timeout:2500});
        functional=functional&&(await trigger.innerText()).trim()===choices[i].label&&
            await option.nth(i).getAttribute('aria-pressed')==='true'&&!await menu.isVisible();
    }
    await trigger.click({timeout:2500});
    await option.first().press('Enter',{timeout:2500});
    functional=functional&&!await menu.isVisible()&&(await trigger.innerText()).trim()===defaultLabel;
    return {responsive,functional,copy,choices,closedRatio,openRatio};
}

module.exports={validNewsletterFrames,checkNewsletter};
