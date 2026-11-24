const assert=require('node:assert/strict');
const {chromium}=require('../backend/node_modules/playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
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
   return {initial,count,registered,expected:a.blockCount,rejected};
  });
  console.log(result);
  assert.equal(result.initial,0);
  assert.equal(result.count,result.expected);
  assert.ok(result.registered>=7);
  assert.ok(result.rejected,'Original inline gap defect must fail real Gutenberg validation');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
