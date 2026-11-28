'use strict';

const { createHash } = require('node:crypto');
const { compareScreenshots } = require('./compare');
const { MAX_SCREENSHOT } = require('./inspection-contract');

function validFooterFrames(frames, viewports) {
    const pinned=viewports.filter(width=>[1440,768,375].includes(width));
    return frames && typeof frames==='object' && !Array.isArray(frames) &&
        Object.keys(frames).length===pinned.length && pinned.every(width=>{
            const frame=frames[width];
            return frame && Number.isFinite(frame.top) && frame.top>=0 && frame.top<36000 &&
                Number.isInteger(frame.extent) && frame.extent>0 && frame.extent<=900 &&
                typeof frame.image==='string' && frame.image.length<=MAX_SCREENSHOT &&
                /^\/9j\/[A-Za-z0-9+/]+={0,2}$/.test(frame.image);
        });
}

async function checkFooter(page,width,frame,screenshot) {
    const footer=page.locator('footer.sm-recorded-footer');
    const compact=width<1024;
    const variant=footer.locator(compact?'.sm-footer-compact':'.sm-footer-desktop');
    const inactive=footer.locator(compact?'.sm-footer-desktop':'.sm-footer-compact');
    if(await footer.count()!==1||await variant.count()!==1||!await variant.isVisible()||await inactive.isVisible())
        return {responsive:false};
    const details=await variant.evaluate(element=>{
        const image=element.querySelector('img');
        const words=[];
        const walk=document.createTreeWalker(element,NodeFilter.SHOW_TEXT);
        while(walk.nextNode()){
            if(walk.currentNode.parentElement?.checkVisibility()&&walk.currentNode.textContent.trim())
                words.push(walk.currentNode.textContent.trim());
        }
        const rect=element.getBoundingClientRect();
        return {
            size:[rect.x,rect.y,rect.width,Math.round(rect.height)],
            documentWidth:document.documentElement.scrollWidth,
            copy:words.join(' ').replace(/\s+/gu,' '),
            links:[...element.querySelectorAll('a[href]')].map(link=>({href:link.getAttribute('href'),text:link.textContent.trim()})),
            asset:image?.complete&&image.naturalWidth>0?{url:image.currentSrc,alt:image.alt}:null,
            controls:element.querySelectorAll('button,summary,[role=button],form,input,select,[tabindex]').length,
        };
    });
    const expectedWidth=compact?width:Math.max(width,1280);
    const responsive=details.size[0]===0&&details.size[1]===0&&details.size[2]===expectedWidth&&
        details.documentWidth===expectedWidth&&details.size[3]>0&&(!frame||details.size[3]===frame.extent);
    const encoded=/^data:image\/png;base64,([A-Za-z0-9+/]+=*)$/.exec(details.asset?.url??'');
    const logoSha256=encoded?createHash('sha256').update(Buffer.from(encoded[1],'base64')).digest('hex'):null;
    let ratio=null;
    if(frame&&responsive){
        const native=await screenshot(page,width,frame.extent,frame.top);
        const sample=image=>({width,extent:frame.extent,screenshot:image});
        ratio=compareScreenshots([sample(frame.image)],[sample(native)],true,true)[0].pixelDifferenceRatio;
    }
    let functional=details.controls===0;
    if(compact){
        const rows=variant.locator('.sm-footer-compact-row');
        functional=functional&&details.links.length===0&&await rows.count()===4;
        const before=await variant.innerHTML();
        const url=page.url();
        for(const row of await rows.all()){
            const plus=row.getByText('+',{exact:true});
            if(await plus.count()!==1){functional=false;break;}
            await plus.click({timeout:2000});
        }
        functional=functional&&await variant.innerHTML()===before&&page.url()===url;
    }else{
        const locals=variant.locator('a[href="#"]');
        functional=functional&&await locals.count()===5;
        if(frame){
            await footer.evaluate(element=>{
                const spacer=document.createElement('div');
                spacer.style.height='1200px';
                element.before(spacer);
            });
            const initial=await variant.innerHTML();
            const url=page.url();
            for(const link of await locals.all()){
                await page.evaluate(()=>history.replaceState(null,'',location.pathname+location.search));
                await link.scrollIntoViewIfNeeded({timeout:2000});
                const y=await page.evaluate(()=>scrollY);
                await link.click({timeout:2000});
                await page.waitForFunction(()=>scrollY===0,null,{timeout:2500});
                functional=functional&&y>0&&page.url()===url+'#'&&await variant.innerHTML()===initial;
            }
            await page.evaluate(()=>history.replaceState(null,'',location.pathname+location.search));
        }
    }
    return {responsive,functional,copy:details.copy,links:details.links,alt:details.asset?.alt,logoSha256,ratio};
}

module.exports={validFooterFrames,checkFooter};
