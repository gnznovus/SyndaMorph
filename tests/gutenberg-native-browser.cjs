const assert=require('node:assert/strict');
const {chromium}=require('../backend/node_modules/playwright');
const origin=process.env.SM_SCOPE_ORIGIN||'http://127.0.0.1:8085';

async function verifyEditableTabs(page,post) {
 const login=process.env.SM_SCOPE_USER||'ciadmin';
 const password=process.env.SM_SCOPE_PASSWORD||'ci-only-test-password';
 await page.goto(origin+'/wp-login.php');
 await page.locator('#user_login').fill(login);
 await page.locator('#user_pass').fill(password);
 await page.locator('#wp-submit').click();
 await page.waitForURL(/\/wp-admin\//);
 await page.goto(origin+'/wp-admin/post.php?post='+post+'&action=edit');
 await page.waitForFunction(id=>window.wp?.data?.select('core/editor')?.getCurrentPostId()===id,post);
 const canvas=page.frameLocator('iframe[name="editor-canvas"]');
 const sidebar=page.locator('body');
 const locate=label=>canvas.getByRole('textbox',{name:label,exact:true}).first()
  .or(sidebar.getByRole('textbox',{name:label,exact:true}).first()).first();
 const panel=locate('Panel text');
 await panel.fill('Updated from the Gutenberg editor.');
 await page.waitForFunction(()=>wp.data.select('core/editor').isEditedPostDirty());
 await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>!wp.data.select('core/editor').isSavingPost()&&
  wp.data.select('core/editor').didPostSaveRequestSucceed());
 await page.reload();
 await panel.waitFor();
 assert.equal(await panel.inputValue(),'Updated from the Gutenberg editor.');
 const frontend=await page.goto(origin+'/?page_id='+post+'&preview=true');
 assert.equal(frontend.status(),200);
 for(const width of [1440,768,375]){
  await page.setViewportSize({width,height:900});
  const widget=page.locator('[data-sm-tabs]').first();
  await widget.getByRole('tab',{name:'Rooms',exact:true}).click();
  assert.equal(await widget.getByRole('tabpanel').innerText(),'Room details\n\nKing & twin rooms — à la carte.');
  await widget.getByRole('tab',{name:'Rooms',exact:true}).press('Home');
  assert.match(await widget.getByRole('tabpanel').innerText(),/Updated from the Gutenberg editor/);
  assert.equal(await page.locator('[data-sm-tabs]').last().getByRole('tab',{name:'Overview'}).getAttribute('aria-selected'),'true');
 }
 console.log('PASS: real Gutenberg tab edit, save, reload, and WordPress frontend interaction at all three widths.');
}

async function verifyBooking(page, post) {
 assert.ok(post, 'Booking fixture did not save a draft');
 await page.goto(origin+'/wp-admin/post.php?post='+post+'&action=edit');
 const ready=()=>page.waitForFunction(()=>window.wp?.data?.select('core/block-editor')?.getBlocks().length===2);
 await ready();
 await page.evaluate(()=>{
  const block=wp.data.select('core/block-editor').getBlocks()[0];
  wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId,{copy:{availability:'Edited availability'}});
  return wp.data.dispatch('core/editor').savePost();
 });
 await page.waitForFunction(()=>!wp.data.select('core/editor').isSavingPost()&&wp.data.select('core/editor').didPostSaveRequestSucceed());
 await page.reload();
 await ready();
 assert.equal(await page.evaluate(()=>wp.data.select('core/block-editor').getBlocks().every(b=>
  b.isValid&&b.name==='syndamorph/booking-summary')),true);
 assert.equal((await page.goto(origin+'/?page_id='+post+'&preview=true')).status(),200);
 const bars=page.locator('[data-sm-booking]');
 assert.equal(await bars.count(),2);
 const first=bars.first(),second=bars.last();
 assert.equal(await first.getByText('Edited availability',{exact:true}).isVisible(),true);
 assert.equal(await first.locator('a[href],form,input').count(),0);
 const guests=first.getByRole('button',{name:/Rooms & guests/});
 await guests.press('Enter');
 const adult=first.locator('[data-sm-counter=adults]');
 for(let i=0;i<8;i++)await adult.getByRole('button',{name:'Increase Adults'}).press('Enter');
 assert.equal(await adult.locator('output').innerText(),'8');
 for(let i=0;i<9;i++)await adult.getByRole('button',{name:'Decrease Adults'}).press('Enter');
 assert.equal(await adult.locator('output').innerText(),'1');
 assert.equal(await first.locator('[data-sm-booking-guests]').innerText(),'1 room · 1 guest');
 assert.equal(await second.locator('[data-sm-booking-guests]').innerText(),'1 room · 2 guests');
 const rate=first.getByRole('button',{name:/Rate preference/});
 await rate.press('Space');
 assert.equal(await guests.getAttribute('aria-expanded'),'false');
 await first.getByRole('button',{name:'Reward Nights',exact:true}).press('Enter');
 assert.equal(await rate.getAttribute('aria-expanded'),'false');
 assert.equal(await first.locator('[data-sm-booking-rate]').innerText(),'Reward Nights');
 assert.equal(await second.locator('[data-sm-booking-rate]').innerText(),'Best Available');
 console.log('PASS: Gutenberg booking edit, save, reload, local actions, and independent instances.');
}

async function verifyDesktopOffer(page, post) {
 assert.ok(post, 'Desktop Offer fixture did not save a draft');
 await page.goto(origin+'/wp-admin/post.php?post='+post+'&action=edit');
 const title=page.frameLocator('iframe[name="editor-canvas"]').getByRole('textbox',{name:'Offer title',exact:true});
 await title.waitFor();
 await title.fill('Edited offer title');
 await page.waitForFunction(()=>wp.data.select('core/editor').isEditedPostDirty());
 await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>wp.data.select('core/editor').didPostSaveRequestSucceed());
 await page.reload();
 await title.waitFor();
 assert.equal(await title.inputValue(),'Edited offer title');
 const editor=await page.evaluate(()=>wp.data.select('core/block-editor').getBlocks()
  .map(({name,isValid})=>({name,isValid})));
 assert.deepEqual(editor,[{name:'syndamorph/desktop-offer',isValid:true},{name:'core/group',isValid:true}]);

 await page.setViewportSize({width:1440,height:900});
 await page.goto(origin+'/?page_id='+post+'&preview=true');
 const card=page.getByRole('region',{name:'Edited offer title'});
 const dismiss=card.getByRole('button',{name:'Continue to website'});
 const shade=page.locator('.sm-offer-shade');
 const lead=page.locator('.sm-offer-entrance h2');
 for(const width of [1440,1024,1023,768,375,1440]){
  await page.setViewportSize({width,height:900});
  assert.equal(await card.isVisible(),width>=1024);
  assert.equal(await shade.isVisible(),width>=1024);
  assert.equal(await lead.isVisible(),width<1024);
  if(width>=1024){
   await page.waitForFunction(()=>Math.abs(document.querySelector('.sm-offer-card').getBoundingClientRect().top-200)<.1);
   const box=await card.boundingBox();
   assert.equal(box.width,680);
  }
 }
 const requests=[];
 const observe=request=>{if(request.isNavigationRequest()||['xhr','fetch'].includes(request.resourceType()))requests.push(request.method());};
 page.on('request',observe);
 try {
  const label=card.getByText('View offer',{exact:true});
  assert.equal(await label.getAttribute('href'),null);
  await label.click();
  await shade.click({position:{x:30,y:250}});
  await page.keyboard.press('Escape');
  assert.equal(await card.isVisible(),true);
  await dismiss.click();
  assert.equal(await card.isVisible(),false);
  assert.equal(await lead.isVisible(),true);
  await page.setViewportSize({width:768,height:900});
  await page.setViewportSize({width:1440,height:900});
  assert.equal(await card.isVisible(),false);
  assert.deepEqual(requests,[]);
 } finally { page.off('request',observe); }
 await page.reload();
 await dismiss.focus();
 assert.equal(await dismiss.evaluate(button=>button.matches(':focus-visible')),true);
 await dismiss.press('Enter');
 assert.equal(await card.isVisible(),false);
 console.log('PASS: editable Desktop Offer retains its responsive lifecycle through Gutenberg save and reload.');
}

async function verifyRecordedHeader(page, post, canvasSelector = '.sm-production-canvas') {
 if(!post)return;
 await page.goto(origin+'/wp-admin/post.php?post='+post+'&action=edit');
 const field=page.frameLocator('iframe[name="editor-canvas"]').getByRole('textbox',{name:'Compact booking display label'});
 await field.waitFor();
 await field.fill('Reservation label');
 await page.waitForFunction(()=>wp.data.select('core/editor').isEditedPostDirty());
 await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>wp.data.select('core/editor').didPostSaveRequestSucceed());
 await page.reload();
 await field.waitFor();
 assert.equal(await field.inputValue(),'Reservation label');
 assert.equal(await page.evaluate(()=>wp.data.select('core/block-editor').getBlocks().every(block=>block.isValid)),true);
 await page.setViewportSize({width:1440,height:900});
 await page.goto(origin+'/?page_id='+post+'&preview=true');
 const canvas=page.locator(canvasSelector);
 const header=canvas.locator('[data-sm-header]');
 await header.waitFor();
 assert.equal(await canvas.locator('header').count(),1);
 assert.equal((await header.boundingBox()).height,141);
 assert.equal(await header.locator('.sm-header-desktop img').evaluate(img=>img.complete && img.naturalWidth>0),true);
 await page.evaluate(()=>window.scrollTo(0,250));
 assert.equal((await header.boundingBox()).y,0);
 await page.evaluate(()=>window.scrollTo(0,0));

 const lead=canvas.locator('.sm-offer-entrance h2');
 assert.equal(await lead.isVisible(),true);
 const stay=header.getByRole('link',{name:'Stay',exact:true});
 assert.equal(await stay.getAttribute('href'),'https://example.invalid/stay');
 await stay.hover();
 const flyout=header.locator('.sm-header-main .sm-header-flyout');
 assert.deepEqual(await flyout.locator('a').evaluateAll(links=>links.map(link=>link.getAttribute('href'))),
  ['https://example.invalid/stay#rooms','https://example.invalid/stay#suites']);
 assert.equal(await flyout.locator('.sm-header-separator').count(),1);
 assert.equal(await lead.isVisible(),false);
 await page.mouse.move(10,300);
 assert.equal(await flyout.isVisible(),false);
 assert.equal(await lead.isVisible(),true);
 const language=header.getByRole('button',{name:'EN'});
 await language.press('Enter');
 const choices=header.locator('.sm-header-language .sm-header-flyout');
 assert.equal(await choices.isVisible(),true);
 assert.equal(await choices.getByRole('link').count(),0);
 await language.press('Escape');
 assert.equal(await choices.isVisible(),false);

 await page.setViewportSize({width:375,height:900});
 assert.equal((await header.boundingBox()).height,72);
 const compact=header.locator('.sm-header-compact');
 await compact.getByText('Reservation label',{exact:true}).click();
 assert.equal(await compact.getByRole('dialog').count(),0);
 const opener=compact.getByRole('button',{name:'Open menu'});
 await opener.click();
 const drawer=compact.getByRole('dialog',{name:'Navigation'});
 await drawer.waitFor();
 assert.equal(await drawer.getByText('All Rooms',{exact:true}).isVisible(),true);
 assert.equal(await drawer.getByRole('link',{name:'All Rooms'}).count(),0);
 assert.equal(await drawer.getByRole('link',{name:'Check Rate'}).getAttribute('href'),'https://example.invalid/rates?a=1&b=2');
 await drawer.getByRole('button',{name:'Close menu'}).click();
 await drawer.waitFor({state:'hidden'});
 await page.waitForFunction(()=>document.querySelector('.sm-header-open')?.getAttribute('aria-expanded')==='false');
 assert.equal(await opener.evaluate(button=>document.activeElement===button),true);
 console.log('PASS: the generated page canvas preserves the editable header, real hover links, and local compact drawer.');
}

async function verifySectionWidths(page, sections) {
 await page.setViewportSize({width:1440,height:900});
 await page.goto(origin+'/wp-admin/post-new.php?post_type=page');
 await page.waitForFunction(()=>wp?.data?.select('core/editor')?.getCurrentPostId()>0);
 const id=await page.evaluate(sections=>{
  const editor=wp.data.select('core/editor');
  const blocks=Object.values(sections).flatMap(section=>wp.blocks.parse(section.content));
  wp.data.dispatch('core/editor').editPost({title:'Generated section alignment regression'});
  wp.data.dispatch('core/block-editor').resetBlocks(blocks);
  return editor.getCurrentPostId();
 },sections);
 const read=()=>page.evaluate(()=>{
  const blocks=wp.data.select('core/block-editor').getBlocks();
  return blocks.map(({isValid,attributes,innerBlocks})=>[
   isValid,attributes.align??null,innerBlocks[0]?.attributes.align??null,innerBlocks[0]?.attributes.layout?.contentSize
  ]);
 });
 const expected=[[true,'full',null,'20rem'],[true,'wide',null,'20rem'],[true,null,null,'20rem']];
 assert.deepEqual(await read(),expected);
 await page.evaluate(()=>wp.data.dispatch('core/editor').savePost());
 await page.waitForFunction(()=>!wp.data.select('core/editor').isSavingPost()&&
  wp.data.select('core/editor').didPostSaveRequestSucceed());
 await page.goto(origin+'/wp-admin/post.php?post='+id+'&action=edit');
 await page.waitForFunction(()=>wp?.data?.select('core/block-editor')?.getBlocks().length===3);
 assert.deepEqual(await read(),expected);
 const rootSelector=await page.evaluate(()=>wp.data.select('core/block-editor').getBlocks()
  .map(block=>'[data-block="'+block.clientId+'"]').join(','));
 const canvas=page.frameLocator('iframe[name="editor-canvas"]');
 const inspect=elements=>elements.map(element=>{
  const box=element.getBoundingClientRect();
  return {x:box.x,width:box.width,viewport:element.ownerDocument.documentElement.clientWidth,
   child:element.querySelector('h2').getBoundingClientRect().width};
 });
 const frontend=await page.context().newPage();
 const preview=await page.context().newPage();
 const report=[];
 try {
  for(const width of [1440,768,375]){
   await page.setViewportSize({width,height:900});
   const roots=canvas.locator(rootSelector);
   await roots.first().waitFor();
   const edit=await roots.evaluateAll(inspect);
   await frontend.setViewportSize({width,height:900});
   assert.equal((await frontend.goto(origin+'/?page_id='+id+'&preview=true')).status(),200);
   const live=await frontend.locator('.wp-block-post-content > .wp-block-group').evaluateAll(inspect);
   assert.equal(live.length,3);
   assert.equal(edit.length,3);
   await preview.setViewportSize({width,height:900});
   for(const [index,section] of Object.values(sections).entries()){
    await preview.setContent(section.preview);
    const [box]=await preview.locator('body > .wp-block-group').evaluateAll(inspect);
    assert.ok(Math.abs(box.x-live[index].x)<2&&Math.abs(box.width-live[index].width)<2,
     'The isolated section width must match the real Page template');
   }
   assert.ok(Math.abs(live[0].x)<2&&Math.abs(live[0].width-width)<2,'The frontend full section must reach both edges');
   assert.ok(edit[0].width>=edit[1].width&&edit[1].width>=edit[2].width,'The editor must honor the width hierarchy');
   if(width===1440){
    assert.ok(live[0].width>live[1].width&&live[1].width>live[2].width);
    assert.ok(edit[0].width>edit[2].width,'The editor full section must exceed the content cap');
   }
   assert.ok([...live,...edit].every(box=>box.child<=321&&box.child<=box.width));
   report.push({width,editor:edit.map(box=>box.width),frontend:live.map(box=>box.width)});
  }
 } finally { await Promise.all([frontend.close(),preview.close()]); }
 console.log('PASS: exact full, wide and content widths after Gutenberg save and reload.',report);
}

(async()=>{
 const channel=process.env.SM_BROWSER_CHANNEL;
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(channel?{channel}:{})});
 try {
  const context=await browser.newContext();
  const page=await context.newPage();
  page.on('console',m=>{if(m.type()==='error'||m.type()==='warning')console.log(m.text());});
  page.on('pageerror',e=>console.error(e));
  const response=await page.goto(origin+'/sm-native-fixture.php');
  assert.equal(response.status(),200);
  const result=await page.evaluate(()=>{
   const a=window.nativeFixture, validate=window.SyndaMorphAIExport.validateNative;
   const initial=wp.blocks.getBlockTypes().length;
   const count=validate(a.content);
   const registered=wp.blocks.getBlockTypes().length;
   let rejected=false;
   const broken=a.content.replace(/(<div class="wp-block-group is-layout-flex")>/,'$1 style="gap:clamp(1rem,0.8rem + 1vw,1.75rem)">');
   if(broken===a.content)throw Error('Regression mutation did not match');
   try{validate(broken);}catch(e){rejected=/serialization invalid/.test(e.message);}
   const cases=[];
   for(const [name,fixture] of Object.entries(window.nativeFixtures)){
    try{
     const actual=validate(fixture.content);
     if(actual!==fixture.blockCount)throw Error('Count mismatch '+actual+' != '+fixture.blockCount);
     cases.push({name,passed:true});
    }catch(e){cases.push({name,passed:false,error:e.message});}
   }
   const gen9=window.nativeFixtures['GEN9 custom CTA font'].content;
   let missingFontClassRejected=false;
   try{validate(gen9.replace(' has-custom-font-size',''));}catch(e){missingFontClassRejected=/serialization invalid/.test(e.message);}
   const tabBlocks=window.tabsPost?validate(window.tabsContent):0;
   const scopeHeader=window.nativeScopeHeader;
   const headerBlocks=scopeHeader?validate(scopeHeader,200,true):0;
   let headerRejected=false,duplicateHeaderRejected=false;
   if(scopeHeader){
    try{validate(scopeHeader);}catch(e){headerRejected=/unsupported Gutenberg block/.test(e.message);}
    try{validate(scopeHeader+'\n\n'+scopeHeader,200,true);}catch(e){duplicateHeaderRejected=/unsupported Gutenberg block/.test(e.message);}
   }
   return {initial,count,registered,expected:a.blockCount,rejected,cases,missingFontClassRejected,tabBlocks,
    tabsPost:window.tabsPost,bookingPost:window.bookingPost,offerPost:window.offerPost,headerPost:window.headerPost,
    scopeHeaderPost:window.scopeHeaderPost,headerBlocks,headerRejected,duplicateHeaderRejected,sections:window.nativeAlignments};
  });
  console.log({...result,sections:Object.keys(result.sections)});
  assert.ok(result.initial<=2,'Only the custom tabs and header should be registered before the validator sets up core.');
  if(result.scopeHeaderPost){
   assert.equal(result.headerBlocks,4);
   assert.equal(result.headerRejected,true);
   assert.equal(result.duplicateHeaderRejected,true);
  }
  assert.equal(result.count,result.expected);
  assert.ok(result.registered>=7);
  assert.ok(result.rejected,'Original inline gap defect must fail real Gutenberg validation');
  assert.ok(result.cases.length>=20);
  assert.deepEqual(result.cases.filter(c=>!c.passed),[]);
  assert.ok(result.missingFontClassRejected,'GEN9 missing font class must fail real Gutenberg validation');
  assert.equal(result.tabBlocks,4,'The custom tabs block must pass registered Gutenberg serialization.');
  for (const width of [1440,768,375]) {
   const geometry=await page.evaluate(async width=>{
    const measure=async (html,selector)=>{
     const frame=document.createElement('iframe');
     frame.width=width;
     const loaded=new Promise(resolve=>frame.addEventListener('load',resolve,{once:true}));
     frame.srcdoc=html;
     document.body.append(frame);
     await loaded;
     const doc=frame.contentDocument;
     const bounds=[...doc.querySelectorAll(selector)]
      .map(el=>{const r=el.getBoundingClientRect();return [r.x,r.width,r.y,r.bottom,doc.defaultView.getComputedStyle(el).textTransform];});
     frame.remove();
     return bounds;
    };
    const compare=async (value,selector)=>({
     preview:await measure(value.preview,selector),frontend:await measure(value.frontend,selector),
    });
    return {
     leftConstrained:await measure(window.nativeFixtures['left aligned constrained child'].preview,'h2'),
     nativeStack:await measure(window.nativeFixtures['constrained child in default stack'].preview,'[data-sm-node="stack-heading"]'),
     correctedStack:await measure(window.nativeFixtures['constrained child in stretched stack'].preview,'[data-sm-node="stack-heading"]'),
     constrained:await compare(window.nativeParity,'.wp-block-group > h2,.wp-block-group > p,.wp-block-group > .wp-block-buttons'),
     flex:await compare(window.nativeParity.flex,'.wp-block-group,.wp-block-group > h2,.wp-block-group > p'),
     columns:await compare(window.nativeParity.columns,'.wp-block-column'),
     hybrid:await compare(window.nativeParity.hybrid,'.wp-block-group, .wp-block-group > h2, .wp-block-group > p'),
     fullWidth:window.nativeParity.fullWidth && await compare(window.nativeParity.fullWidth,'.wp-block-buttons,.wp-block-button__link'),
     responsiveCoreWrapper:window.nativeParity.responsiveCoreWrapper && await measure(window.nativeParity.responsiveCoreWrapper,'.wp-block-button,.wp-block-button__link'),
    };
   },width);
   assert.ok(Math.abs(geometry.leftConstrained[0][0])<1);
   assert.ok(Math.abs(geometry.leftConstrained[0][1]-Math.min(400,width))<1);
   assert.equal(geometry.nativeStack.length,1);
   assert.equal(geometry.correctedStack.length,1);
   const [corrected]=geometry.correctedStack;
   assert.ok(Math.abs(corrected[0]+corrected[1]/2-width/2)<1,'A stretched native Stack must center the constrained child');
   if(width===1440) {
    assert.ok(geometry.nativeStack[0][0]<corrected[0]-100,'Default Stack should reproduce the left allocation defect');
    assert.ok(Math.abs(corrected[1]-704)<1,'The native constraint must remain effective after the parent correction');
   }
   const constrained=geometry.constrained;
   assert.equal(constrained.preview.length,3);
   assert.equal(constrained.frontend.length,3);
   for(const [i,[x,w]] of constrained.preview.entries()){
    assert.ok(Math.abs(x+w/2-width/2)<1,'Constrained child must share the group center');
    assert.ok(Math.abs(x-constrained.frontend[i][0])<1 && Math.abs(w-constrained.frontend[i][1])<1,'Preview width and position must match core');
   }
   const gaps=boxes=>[boxes[1][2]-boxes[0][3],boxes[2][2]-boxes[1][3]];
   for(const [i,gap] of gaps(constrained.preview).entries())
    assert.ok(Math.abs(gap-gaps(constrained.frontend)[i])<1,'Preview gap must match native inline and core spacing');
   for(const boxes of Object.values(geometry.flex)){
    assert.equal(boxes.length,3);
    assert.ok(Math.abs(boxes[1][0]+boxes[1][1]/2-width/2)<1,'Vertical group must center its content');
    assert.ok(Math.abs(boxes[0][3]-boxes[2][3]-16)<1,'Vertical group must bottom-align with padding');
   }
   for(const boxes of Object.values(geometry.hybrid)){
    const [outer,inner,heading,paragraph]=boxes;
    assert.equal(paragraph[4],'uppercase');
    assert.ok(Math.abs((outer[2]+outer[3])/2-(inner[2]+inner[3])/2)<1,'Nested content must center vertically');
    assert.ok(Math.abs(heading[1]-Math.min(width-32,576))<1,'Constrained child must honor its maximum width');
   }
   const [previewHeading,frontendHeading]=[geometry.hybrid.preview[2],geometry.hybrid.frontend[2]];
   assert.ok(Math.abs(previewHeading[0]-frontendHeading[0])<1,'Nested constrained layout must match core position');
   if(geometry.fullWidth)for(const [buttons,link] of Object.values(geometry.fullWidth)){
    assert.equal(link[4],'lowercase');
    assert.ok(Math.abs(buttons[1]-link[1])<1 && Math.abs(buttons[0]-link[0])<1,'Fixed width CTA must fill its container in preview and core');
   }
   if(width===375 && geometry.responsiveCoreWrapper) {
    const [wrapper,link]=geometry.responsiveCoreWrapper;
    assert.ok(Math.abs(wrapper[1]-width)<1,'WordPress must apply responsive width to the Button wrapper');
    assert.ok(link[1]<wrapper[1]-20,'A full-width wrapper must not be mistaken for a full-width CTA link');
   }
   for(const boxes of Object.values(geometry.columns)){
    assert.equal(boxes.length,2);
    if(width>781) assert.ok(boxes[1][0]>boxes[0][0] && Math.abs(boxes[1][2]-boxes[0][2])<1,'Desktop columns must share a row');
    else assert.ok(Math.abs(boxes[1][0]-boxes[0][0])<1 && boxes[1][2]>=boxes[0][3],'Narrow columns must stack');
   }
  }
  await verifyEditableTabs(page,result.tabsPost);
  await verifyBooking(page,result.bookingPost);
  await verifyDesktopOffer(page,result.offerPost);
  await verifyRecordedHeader(page,result.headerPost);
  await verifyRecordedHeader(page,result.scopeHeaderPost,'#syndamorph-page');
  await verifySectionWidths(page,result.sections);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
