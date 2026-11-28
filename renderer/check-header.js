'use strict';
const { compareHeaderImage } = require('./compare');

const scrollPositions=[0,500,1400,3000];
const pinWidths=[1440,768,375];
const jpeg = value => typeof value==='string'&&value.length<=524288&&/^[a-zA-Z0-9+/=]+$/.test(value);

function validHeaderFrames(frames,viewports) {
    if(!frames||typeof frames!=='object'||Array.isArray(frames)||
        Object.keys(frames).some(key=>!pinWidths.includes(Number(key))||!viewports.includes(Number(key))))return false;
    return viewports.every(width=>{
        const frame=frames[width];
        if(!pinWidths.includes(width))return frame===undefined;
        if(!frame||!jpeg(frame.closed))return false;
        if(width!==1440)return jpeg(frame.open);
        return Array.isArray(frame.panels)&&frame.panels.length>=2&&frame.panels.length<=12&&
            frame.panels.filter(panel=>panel?.language===true).length===1&&
            new Set(frame.panels.map(panel=>panel?.label)).size===frame.panels.length&&frame.panels.every(panel=>{
                const {x,y,width,height}=panel?.clip??{};
                return typeof panel?.label==='string'&&panel.label.trim()===panel.label&&panel.label.length>0&&panel.label.length<=120&&
                    [x,y,width,height].every(Number.isInteger)&&x>=0&&y>=0&&width>0&&height>0&&
                    x+width<=1440&&y+height<=900&&jpeg(panel.screenshot);
            });
    });
}

function desktopNavigation(header) {
    const label=node=>node.textContent.trim();
    const item=node=>({label:label(node),href:node.href});
    const list=element=>[...element.children].map(node=>{
        if(node.tagName==='A')return item(node);
        const [parent,flyout]=node.children;
        let separator=false;
        const children=[...flyout.children].flatMap(row=>{
            if(row.classList.contains('sm-header-separator')){separator=true;return [];}
            const child={...item(row),...(separator?{separatorBefore:true}:{})};
            separator=false;
            return [child];
        });
        return {...item(parent),children,panelWidth:parseInt(flyout.style.getPropertyValue('--sm-header-panel-width'),10)};
    });
    const rate=header.querySelector('.sm-header-main .sm-header-rate');
    const language=header.querySelector('.sm-header-language');
    return {homeUrl:header.querySelector('.sm-header-desktop .sm-header-logo').href,rateUrl:rate.href,rateLabel:label(rate),
        utility:list(header.querySelector('.sm-header-utility nav')),primary:list(header.querySelector('.sm-header-main nav')),
        languageLabel:label(language.querySelector('button')),languages:[...language.querySelectorAll('.sm-header-flyout span')].map(label)};
}

function compactNavigation(header) {
    const label=node=>node.textContent.trim();
    const item=node=>({label:label(node),...(node.tagName==='A'?{href:node.href}:{})});
    const list=nodes=>{
        const out=[];
        for(const node of nodes){
            if(node.classList.contains('sm-header-drawer-children'))out.at(-1).children=[...node.children].map(item);
            else out.push({...item(node.firstElementChild),...(node.classList.contains('sm-header-emphasis')?{emphasis:true}:{})});
        }
        return out;
    };
    const drawer=header.querySelector('.sm-header-drawer');
    const actions=drawer.querySelector('.sm-header-drawer-actions');
    const rate=actions.querySelector('a');
    const nav=drawer.querySelector('.sm-header-drawer-nav');
    const utility=nav.querySelector('.sm-header-drawer-utility');
    return {homeUrl:drawer.querySelector('.sm-header-logo').href,rateUrl:rate.href,rateLabel:label(rate),
        compactPrimary:list([...nav.children].filter(child=>child!==utility)),compactUtility:list(utility.children),
        languageLabel:label(actions.querySelector('strong')),languages:[...actions.querySelectorAll('span')].map(label),
        bookLabel:label(header.querySelector('.sm-header-compact-actions .sm-header-rate'))};
}

async function checkHeader(page,width,frame) {
    const desktop=width>=1024;
    const header=page.locator('[data-sm-header]');
    if(await header.count()!==1)return {geometry:false};
    const large=header.locator('.sm-header-desktop');
    const small=header.locator('.sm-header-compact');
    const visible=desktop?large:small;
    const logo=(desktop?large.locator('.sm-header-logo img'):small.locator(':scope > .sm-header-logo img'));
    const assetLoaded=await logo.evaluate(async img=>{
        await img.decode();
        return img.currentSrc.startsWith('data:image/png;base64,')&&img.naturalWidth>0;
    });
    const expected={x:0,y:0,width:desktop?Math.max(width,1280):width,height:desktop?141:72};
    const fixed=async()=>{
        const bounds=await header.boundingBox();
        return bounds&&Object.entries(expected).every(([key,value])=>bounds[key]===value);
    };
    let geometry=await large.isVisible()===desktop&&await small.isVisible()!==desktop;
    for(const top of scrollPositions){
        await page.evaluate(top=>scrollTo({top,behavior:'instant'}),top);
        geometry=(await page.evaluate(()=>scrollY)===top)&&await fixed()&&geometry;
    }
    await page.evaluate(()=>scrollTo({top:0,behavior:'instant'}));
    if(!frame)return {geometry,assetLoaded};
    const session=await page.context().newCDPSession(page);
    let data;
    try{
        ({data}=await session.send('Page.captureScreenshot',{format:'jpeg',quality:58,captureBeyondViewport:true,
            clip:{...expected,scale:1}}));
    }finally{await session.detach();}
    const visual={closed:compareHeaderImage(frame.closed,data,width,expected.height),panels:[]};
    const screen=async clip=>(await page.screenshot({type:'jpeg',quality:75,timeout:3000,...(clip?{clip}:{})})).toString('base64');
    if(desktop){
        let actions=true;
        for(const panel of frame.panels){
            const target=panel.language===true?visible.locator('.sm-header-language button'):
                visible.getByRole('link',{name:panel.label,exact:true}).filter({visible:true});
            if(await target.count()!==1)return {geometry,assetLoaded,actions:false};
            const flyout=target.locator('..').locator('.sm-header-flyout');
            await target.hover({timeout:2000});
            await flyout.waitFor({timeout:2000});
            const box=await flyout.boundingBox(),{clip}=panel;
            if(!box||clip.x<box.x-2||clip.y<box.y-2||
                clip.x+clip.width>box.x+box.width+2||clip.y+clip.height>box.y+box.height+2)return {geometry,assetLoaded,actions:false};
            visual.panels.push({label:panel.label,language:panel.language===true,ratio:
                compareHeaderImage(panel.screenshot,await screen(clip),clip.width,clip.height)});
            await page.mouse.move(8,650);
            actions=await flyout.isHidden()&&actions;
            if(panel.language===true){
                await target.press('Enter');
                actions=await flyout.isVisible()&&actions;
                await target.press('Escape');
                actions=await flyout.isHidden()&&actions;
                actions=await flyout.locator('a').count()===0&&actions;
            }
        }
        return {geometry,assetLoaded,actions,visual,navigation:await header.evaluate(desktopNavigation)};
    }
    const drawer=small.getByRole('dialog');
    const opener=small.getByRole('button',{name:'Open menu'});
    const book=small.locator('.sm-header-compact-actions .sm-header-rate');
    if(!await book.evaluate(el=>!el.closest('a,button,[role],[tabindex],form')))return {geometry,assetLoaded,actions:false};
    await book.click({timeout:2000});
    if(await drawer.isVisible())return {geometry,assetLoaded,actions:false};
    await opener.click({timeout:2000});
    await drawer.waitFor({timeout:2000});
    const navigation=await header.evaluate(compactNavigation);
    await drawer.locator('.sm-header-logo img').evaluate(img=>img.decode());
    visual.open=compareHeaderImage(frame.open,await screen(),width,900);
    await drawer.getByRole('button',{name:'Close menu'}).click({timeout:2000});
    await drawer.waitFor({state:'hidden',timeout:2000});
    await page.waitForFunction(()=>document.querySelector('.sm-header-open')?.getAttribute('aria-expanded')==='false');
    let actions=await opener.evaluate(el=>document.activeElement===el);
    await opener.press('Enter');
    await drawer.waitFor({timeout:2000});
    await page.keyboard.press('Escape');
    await drawer.waitFor({state:'hidden',timeout:2000});
    await page.waitForFunction(()=>document.querySelector('.sm-header-open')?.getAttribute('aria-expanded')==='false');
    actions=await opener.evaluate(el=>document.activeElement===el)&&actions;
    return {geometry,assetLoaded,actions,visual,navigation};
}

module.exports={validHeaderFrames,checkHeader};
