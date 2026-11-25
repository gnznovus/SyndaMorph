const assert=require('node:assert/strict');
const {chromium}=require('../backend/node_modules/playwright');
(async()=>{
 const channel=process.env.SM_BROWSER_CHANNEL;
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],...(channel?{channel}:{})});
 try {
  const page=await browser.newPage();
  page.on('console',m=>{if(m.type()==='error'||m.type()==='warning')console.log(m.text());});
  page.on('pageerror',e=>console.error(e));
  const response=await page.goto('http://127.0.0.1:8085/sm-native-fixture.php');
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
   return {initial,count,registered,expected:a.blockCount,rejected,cases,missingFontClassRejected};
  });
  console.log(result);
  assert.equal(result.initial,0);
  assert.equal(result.count,result.expected);
  assert.ok(result.registered>=7);
  assert.ok(result.rejected,'Original inline gap defect must fail real Gutenberg validation');
  assert.ok(result.cases.length>=20);
  assert.deepEqual(result.cases.filter(c=>!c.passed),[]);
  assert.ok(result.missingFontClassRejected,'GEN9 missing font class must fail real Gutenberg validation');
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
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
