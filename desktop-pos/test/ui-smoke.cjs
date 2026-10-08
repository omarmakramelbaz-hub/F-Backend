// npm install --no-save playwright; npx playwright install chromium; then node test/ui-smoke.cjs.
// Exercises real local storage through the renderer; native Windows printing remains a pilot check.
const fs=require('node:fs'),http=require('node:http'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');const Store=require('../src/store.cjs');const {randomUUID}=require('node:crypto');
(async()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'pos-ui-')),s=new Store(path.join(dir,'pos.sqlite'));
 const snap={id:randomUUID(),generated_at:new Date().toISOString(),branch:{value:'f:100',name:'فسخانستا — فرع اختبار'},actor:{id:10,name:'كاشير الفرع'},tax_bps:1400,service_bps:0,can_discount:false,categories:[{id:1,name:'رنجة'}],products:[{id:1,name:'رنجة هيرنج',category_id:1,unit:'kg',variants:[{option_id:'',label:'الأساسي',unit_price_cents:10000,quantity_mode:'weight',inventory:{}}]}]};s.saveSnapshot(snap);
 const server=http.createServer((req,res)=>{const name=req.url==='/'?'index.html':req.url.slice(1);if(!['index.html','renderer.js','style.css','domain.js'].includes(name)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html');res.end(fs.readFileSync(path.join(__dirname,'../src',name)));});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
 try {
   browser=await chromium.launch({headless:true,executablePath:process.env.POS_BROWSER_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
   const page=await browser.newPage({viewport:{width:1380,height:920}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
   const boot=()=>({paired:true,snapshot:s.snapshot(),orders:s.openOrders(),history:s.history(),counts:s.counts(),online:false,error:'',printer:'',last_synced:null});
   await page.exposeBinding('_rpc',async(_source,method,...args)=>{
      if(method==='state'||method==='sync')return boot();if(method==='new')return s.newOrder();if(method==='order')return s.order(args[0]);if(method==='update')return s.update(...args);if(method==='discard')return s.discard(args[0]);if(method==='action')return s.dispatch(...args);
      if(method==='print')throw Error('طابعة غير متصلة — محاكاة اختبار');if(method==='printers')return [];if(method==='printer')return true;
      throw Error('Unsupported test method '+method);
   });
   await page.addInitScript(()=>{const api={onState:()=>()=>{}};for(const n of ['state','sync','new','order','update','discard','action','print','printers','printer'])api[n]=(...a)=>window._rpc(n,...a);window.POS=api;});
   await page.goto('http://127.0.0.1:'+server.address().port+'/');await page.getByRole('button',{name:'+ إضافة',exact:true}).waitFor();
   await page.getByRole('button',{name:'+ إضافة',exact:true}).click();await page.waitForFunction(()=>document.getElementById('total').textContent==='28.50');
   await page.locator('#cash_received').fill('50.00');await page.locator('#sale').click();await page.waitForFunction(()=>document.getElementById('pending').textContent.includes('1 عملية'));
   assert.equal(s.pending().length,1);assert.equal(s.history()[0].event.kind,'sale');assert.equal(s.history()[0].event.data.cash_received,'50.00');
   assert.match(await page.locator('#message').innerText(),/محاكاة/);
   await page.getByRole('button',{name:'+ إضافة',exact:true}).click();await page.waitForFunction(()=>document.getElementById('total').textContent==='28.50');
   await page.locator('#kitchen').click();await page.waitForFunction(()=>document.getElementById('pending').textContent.includes('2 عملية'));
   const kitchen=s.openOrders().find(o=>o.status==='kitchen');assert.ok(kitchen);
   await page.locator('#history-button').click();await page.getByRole('button',{name:new RegExp('المطبخ.*'+kitchen.id.slice(0,8))}).click();
   await page.locator('#bill').click();await page.waitForFunction(()=>document.getElementById('notes').disabled);assert.ok(await page.locator('#notes').isDisabled());
   await page.locator('#cash_received').fill('40.00');await page.locator('#sale').click();await page.waitForFunction(()=>document.getElementById('pending').textContent.includes('4 عملية'));
   assert.equal(s.history().filter(r=>r.event.kind==='sale').length,2);assert.equal(s.pending().length,4);
   await page.reload();await page.getByRole('button',{name:'+ إضافة',exact:true}).waitFor();assert.equal(s.pending().length,4);assert.equal(errors.length,0,errors.join('; '));
   if(process.env.POS_PREVIEW_PATH){await page.getByRole('button',{name:'+ إضافة',exact:true}).click();await page.waitForFunction(()=>document.getElementById('total').textContent==='28.50');await page.screenshot({path:process.env.POS_PREVIEW_PATH,fullPage:true});}
   console.log('PASS renderer: offline sale, kitchen, locked bill, failed printer, pending queue and restart');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));s.close();fs.rmSync(dir,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exitCode=1;});
