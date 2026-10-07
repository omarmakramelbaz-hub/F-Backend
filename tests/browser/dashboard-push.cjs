// Production notification JavaScript with synthetic responses; never contacts Firebase.
const {chromium} = require('playwright');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const {execFileSync} = require('child_process');
const root = path.join(__dirname, '../..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const labels = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', path.join(root, 'resources/lang/ar/dashboard_push.php')], {encoding:'utf8'}));
const summary = 'المستخدمون المستهدفون: 11050 · لديهم رمز إرسال: 4000 · بلا رمز صالح الصيغة: 7050';
const markup = `<html dir="rtl"><head><meta charset="utf-8"></head><body><div id="dashboard-push" data-audience-url="/audience" data-status-url="/campaigns/0" data-step-url="/campaigns/0/step" data-resume-url="/campaigns/0/resume">
<div data-push-audience></div><form data-push-form method="post" action="/send"><input name="_token" value="fixture"><input name="request_key" value="09000000-0000-4000-8000-000000000001"><input name="durable" value="1"><input name="account_type" value="user"><input name="title" value="إشعار تجريبي"><textarea name="body">تجربة</textarea>
<input type="radio" name="send_by" value="1" checked><input type="radio" name="send_by" value="0"><input type="radio" name="choose_user" value="0" checked><input type="radio" name="choose_user" value="1"><select name="user_id[]" multiple><option value="20">مستخدم</option></select><select name="zone_id[]" multiple><option value="1">منطقة</option></select><button type="submit">إرسال</button></form>
<select data-push-history><option value=""></option><option value="8">حملة قديمة</option></select><div data-push-progress hidden></div><button data-push-resume hidden>استكمال</button><button data-push-new hidden>جديد</button></div>
<script id="dashboard-push-labels" type="application/json">${JSON.stringify(labels)}</script></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  for(const width of [1366,390]) {
   const page=await browser.newPage({viewport:{width,height:900}}), errors=[];
   page.on('pageerror',e=>errors.push(String(e)));
   let sends=0,steps=0,preview=0,releaseSlow;
   const state={id:9,status:'queued',message:'أجهزة في الانتظار',diagnostic:'',issues:[],audience_message:summary};
   await page.route('http://push.test/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:markup});
    if(url.pathname==='/audience'){
     preview++;
     if(url.searchParams.get('send_by')==='0') {await new Promise(resolve=>{releaseSlow=resolve;});return route.fulfill({json:{message:'STALE AREA COUNT'}});}
     return route.fulfill({json:{message:summary}});
    }
    if(url.pathname==='/send'){sends++;return route.fulfill({status:202,json:{campaign:state}});}
    if(url.pathname==='/campaigns/9/step'){steps++;return route.fulfill({json:{...state,status:'finished',message:'قبلت Firebase: 50'}});}
    if(url.pathname==='/campaigns/8')return route.fulfill({json:{...state,id:8,status:'finished',audience_message:labels.audience_legacy}});
    throw new Error('Unexpected request '+url);
   });
   await page.goto('http://push.test/');
   await page.addScriptTag({content:read('public/dashboard/plugins/jquery/jquery.min.js')});
   await page.addScriptTag({content:read('public/dashboard/plugins/select2/js/select2.min.js')});
   await page.evaluate(()=>{window.jQuery('select').select2();window.DashboardSPA={isCurrentPage:()=>true,onCleanup:fn=>window.cleanupPush=fn};});
   await page.addScriptTag({content:read('public/dashboard/js/dashboard-push.js')});
   await page.waitForFunction(()=>document.querySelector('[data-push-audience]').textContent.includes('11050'));
   assert.equal(sends,0);assert.equal(steps,0);
   // Select2 changes refresh preview and a stale response cannot replace campaign counts.
   await page.evaluate(()=>{document.querySelector('[name="send_by"][value="0"]').checked=true;window.jQuery('[name="zone_id[]"]').val(['1']).trigger('change');});
   await page.waitForTimeout(350);assert(releaseSlow);
   await page.evaluate(()=>{document.querySelector('[name="send_by"][value="1"]').checked=true;document.querySelector('[data-push-form]').requestSubmit();});
   await page.waitForFunction(()=>document.querySelector('[data-push-progress]').textContent.includes('50'));
   releaseSlow();await page.waitForTimeout(80);
   assert.equal(sends,1);assert.equal(steps,1);
   assert((await page.locator('[data-push-audience]').textContent()).includes('11050'));
   assert.equal(await page.locator('[type="submit"]').textContent(),labels.saved);
   await page.locator('[data-push-new]').click();
   assert.equal(await page.locator('[type="submit"]').textContent(),labels.send);
   assert.equal(await page.locator('[type="submit"]').isEnabled(),true);
   await page.evaluate(()=>window.jQuery('[data-push-history]').val('8').trigger('change'));
   await page.waitForFunction(text=>document.querySelector('[data-push-audience]').textContent===text,labels.audience_legacy);
   assert.equal(sends,1);assert.equal(steps,1);
   await page.evaluate(()=>window.cleanupPush());
   assert.deepEqual(errors,[]);
   assert(preview>=2);
   await page.close();
  }
  console.log('Notification browser checks passed: desktop/mobile, Select2, stale preview, saved button, campaign history and single submission.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
