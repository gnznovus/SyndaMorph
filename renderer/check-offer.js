'use strict';
const { compareExactCrop } = require('./compare');

const clip={x:380,y:200,width:680,height:411};
function validOfferFrame(frame,viewports) {
    return viewports.includes(1440)?
        typeof frame==='string'&&frame.length<=524288&&frame.startsWith('/9j/')&&/^[a-zA-Z0-9+/]+={0,2}$/.test(frame):
        frame===null;
}

async function checkOffer(page,width,screenshot) {
    const offer=page.locator('#syndamorph-page > [data-sm-offer]');
    if(await offer.count()!==1)return {responsive:false};
    const card=offer.locator('.sm-offer-card');
    const shade=offer.locator('.sm-offer-shade');
    const lead=page.locator('.sm-offer-entrance > h2');
    if(width<1024)return {responsive:!await card.isVisible()&&!await shade.isVisible()&&await lead.isVisible()};
    const box=await card.boundingBox();
    const left=Math.max(300,(width-680)/2);
    const geometry=box&&box.x===left&&box.y===200&&box.width===680&&Math.ceil(box.height)===411;
    const shadeMatches=await shade.evaluate(el=>{
        const box=el.getBoundingClientRect();
        return box.x===0&&box.y===0&&box.width===innerWidth&&box.height===document.querySelector('#syndamorph-page').getBoundingClientRect().height&&
            getComputedStyle(el).backgroundColor==='rgba(17, 17, 17, 0.55)'&&getComputedStyle(el).position==='absolute';
    });
    const responsive=Boolean(geometry&&shadeMatches&&!await lead.isVisible());
    if(width!==1440||!responsive)return {responsive};
    const native=(await page.screenshot({type:'jpeg',quality:75,clip,timeout:3000})).toString('base64');
    const ratio=compareExactCrop(screenshot,native,clip.width,clip.height);
    const copy=await card.evaluate(el=>Object.fromEntries(Object.entries({
        placeholder:'.sm-offer-placeholder',eyebrow:'.sm-offer-eyebrow',title:'h2',description:'.sm-offer-description',
        offerLabel:'.sm-offer-label',dismissLabel:'.sm-offer-dismiss'
    }).map(([name,selector])=>[name,el.querySelector(selector)?.textContent.replace(/\s+/gu,' ').trim()])));
    const label=card.locator('.sm-offer-label');
    const dismiss=card.getByRole('button');
    const markup=await card.innerHTML();
    const open=async()=>await card.isVisible()&&await card.innerHTML()===markup&&!await lead.isVisible();
    let functional=await dismiss.count()===1&&await offer.locator('a,form,input,[role=link]').count()===0&&
        await label.evaluate(el=>!el.closest('a,button,[role],[tabindex],form'));
    await label.click({timeout:2000});
    functional=await open()&&functional;
    await page.mouse.click(5,5);
    functional=await open()&&functional;
    await page.keyboard.press('Escape');
    functional=await open()&&functional;
    await dismiss.click({timeout:2000});
    functional=!await card.isVisible()&&!await shade.isVisible()&&await lead.isVisible()&&functional;
    await page.setViewportSize({width:768,height:900});
    await page.setViewportSize({width:1440,height:900});
    functional=!await card.isVisible()&&functional;
    for(const key of ['Enter','Space']){
        await page.reload({waitUntil:'domcontentloaded',timeout:5000});
        await card.waitFor({timeout:2000});
        await page.keyboard.press('Tab');
        const visible=await dismiss.evaluate(el=>el.matches(':focus-visible')&&parseFloat(getComputedStyle(el).outlineWidth)>=2);
        await dismiss.press(key);
        functional=visible&&!await card.isVisible()&&await lead.isVisible()&&functional;
    }
    return {responsive,functional,copy,ratio};
}

module.exports={validOfferFrame,checkOffer};
