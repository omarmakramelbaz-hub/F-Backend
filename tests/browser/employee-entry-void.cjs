'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const script=process.env.EMPLOYEE_VOID_SOURCE?fs.readFileSync(process.env.EMPLOYEE_VOID_SOURCE,'utf8'):read('public/dashboard/js/branch-operations.js');
const branch='f:100',month='2026-11',day='2026-11-06';
const urls=Object.fromEntries(['data','save','attendance','attendance-rules','entry','wallet','daily-notes','void-entry','statement','entries','close','pay','export','recover','orders'].map(k=>[k,'http://employee.test/'+k]));
const branches=[{value:branch,name:'فرع المنصورة'}];
async function fixture(browser,{owner=true,status='draft',hold=false,failBefore=false,failAfter=false,deny=null,mobile=false,spa=false}={}){
 const page=await browser.newPage({viewport:mobile?{width:390,height:844}:{width:1440,height:950}}),posts=[],recoveries=[],errors=[];
 page.setDefaultTimeout(7000);await page.clock.install({time:new Date('2026-11-06T10:00:00Z')});
 const employee={id:7,branch,name:'موظف الاختبار',job_title:'كاشير',shift:'صباحي',revision:1,active:true,attendance:null,day_closed:status!=='draft',wallet_phone:'01000000000'};
 const entries=[{id:42,day,kind:'deduction',amount:'50.00',reason:'خصم تأخير مسجل',revision:3},{id:43,day,kind:'advance',amount:'25.00',reason:'سلفة مسجلة',revision:2},{id:44,day,kind:'bonus',amount:'10.00',reason:'مكافأة مسجلة',revision:1},{id:45,day,kind:'deduction',amount:'20.00',reason:'حركة ملغاة قديمة',revision:2,voided_at:'2026-11-06 09:00:00',void_reason:'مكرر'}];
 function totals(){const result={bonus:0,deduction:0,advance:0};entries.forEach(x=>{if(!x.voided_at)result[x.kind]+=Number(x.amount);});return Object.fromEntries(Object.entries(result).map(([k,v])=>[k,v.toFixed(2)]));}
 function statement(){const t=totals();return {employee,month,status,salary:'1000.00',earned_salary:'1000.00',eligible_days:6,month_days:30,accrued_from:'2026-11-01',accrued_through:day,...t,net:(1000+Number(t.bonus)-Number(t.deduction)-Number(t.advance)).toFixed(2),can_void_entries:owner,entries,attendance:[],preview_hash:'preview',payroll_id:5,revision:1};}
 function listing(){const s=statement();return {success:true,operating_day:{date:day,start_hour:6,timezone:'Africa/Cairo'},can_manage_employees:owner,can_manage_attendance:owner,can_award_bonuses:owner,branches,options:{job_title:[],shift:[]},filters:{branch,day,month},pagination:{page:1,last_page:1,total:1},summary:{employees:1,present:0,absent:0,leave:0,net:s.net,deduction:s.deduction,bonus:s.bonus,advance:s.advance},items:[{...employee,daily:Object.fromEntries(['deduction','bonus','advance'].map(k=>[k,{count:1,amount:s[k]}])),statement:s}]};}
 const boot={module:'employees',actor_id:owner?1:10,branches,initial:listing(),today:day,urls};
 const html=`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test"><style>${read('public/dashboard/css/branch-operations.css')}</style></head><body><main id="branch-operations" class="op-workspace op-employees"><button data-op-add>إضافة موظف</button><p data-op-message hidden></p><button data-op-retry hidden>تحقق</button><select data-op-filter="branch"><option value="${branch}">فرع المنصورة</option></select><input data-op-filter="search"><select data-op-filter="shift"></select><select data-op-filter="job_title"></select><input type="date" data-op-filter="day" value="${day}"><input type="month" data-op-filter="month" value="${month}"><button data-op-refresh>تحديث</button><button data-op-month>الشهر</button><button data-op-export>تصدير</button><section data-op-cards></section><div data-op-table></div><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>إغلاق</button></header><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;
 let release,mutations=0;const gate=hold?new Promise(resolve=>{release=resolve;}):Promise.resolve(),applied=new Map();
 page.on('pageerror',e=>{errors.push(e.message);console.error('Employee page error: '+e.message);});
 page.on('dialog',dialog=>{errors.push('Unexpected native dialog');dialog.dismiss();});
 await page.route('http://employee.test/**',async route=>{
  const request=route.request(),u=new URL(request.url()),endpoint=u.pathname.slice(1);
  if(!endpoint)return route.fulfill({contentType:'text/html',body:html});
  if(request.method()==='GET'){
   if(endpoint==='statement'){assert.equal(u.searchParams.get('branch'),branch);assert.equal(u.searchParams.get('employee_id'),'7');assert.equal(u.searchParams.get('month'),month);return route.fulfill({json:{success:true,statement:statement()}});}
   if(endpoint==='recover'){recoveries.push(Object.fromEntries(u.searchParams));const saved=applied.get(u.searchParams.get('idempotency_key'));return route.fulfill({json:saved?{...saved,found:true}:{success:true,found:false}});}
   if(endpoint==='data')return route.fulfill({json:listing()});
  }
  assert.equal(endpoint,'void-entry');assert.equal(request.method(),'POST');assert.equal(request.headers()['x-csrf-token'],'test');
  const payload=request.postDataJSON();posts.push(payload);
  if(deny&&posts.length===1)return route.fulfill({status:deny,json:{success:false,message:deny===403?'إلغاء الحركة متاح للأونر فقط.':deny===409?'تم إقفال هذا الشهر. لا يمكن تغيير حركاته.':'اكتب سبب إلغاء الحركة.'}});
  if(failBefore&&posts.length===1)return route.abort('failed');
  await gate;
  let result=applied.get(payload.idempotency_key);
  if(!result){
   const entry=entries.find(x=>x.id===payload.entry_id);assert(entry&&!entry.voided_at);assert.equal(payload.branch,branch);assert.equal(payload.expected_revision,entry.revision);assert(payload.reason.trim());
   assert.match(payload.idempotency_key,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
   Object.assign(entry,{voided_at:'2026-11-06 10:00:00',voided_by:1,void_reason:payload.reason,revision:entry.revision+1});mutations++;result={success:true,entry_id:entry.id};applied.set(payload.idempotency_key,result);
  }
  if(failAfter&&posts.length===1)return route.abort('failed');
  return route.fulfill({json:result});
 });
 await page.goto('http://employee.test/');
 await page.evaluate(spa=>{
  window.prompt=()=>{throw Error('prompt() is not supported in Electron');};window.confirm=()=>{throw Error('Unexpected native confirmation');};
  if(spa)window.DashboardSPA={isCurrentPage:()=>true,onBeforeLeave:fn=>{window.employeeMayLeave=fn;},onCleanup:fn=>{window.employeeCleanup=fn;}};
 },spa);
 await page.addScriptTag({content:script});
 await page.locator('[data-op-net]').first().click();await page.locator('.op-statement').waitFor();
 return {page,posts,recoveries,errors,entries,release,mutations:()=>mutations,net:()=>statement().net};
}
async function openForm(ctx,id=42){
 const row=ctx.page.locator('.op-statement tbody tr').filter({hasText:ctx.entries.find(x=>x.id===id).reason});
 await row.getByRole('button',{name:'إلغاء الحركة',exact:true}).click();await ctx.page.locator('[data-op-void-reason]').waitFor({state:'visible'});
}
async function submit(ctx,reason='  تصحيح حركة  '){await ctx.page.locator('[data-op-void-reason]').fill(reason);await ctx.page.locator('[data-op-void-submit]').click();}
async function completed(ctx,id=42){await ctx.page.locator('.op-statement').waitFor();assert((await ctx.page.locator('.op-statement').textContent()).includes('ملغاة: تصحيح حركة'));assert.equal(await ctx.page.locator(`[data-op-void-entry="${id}"]`).count(),0);assert.equal(await ctx.page.locator('.op-net dd').textContent(),ctx.net()+' جنيه');assert.deepEqual(ctx.errors,[]);}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 try{
  const owner=await fixture(browser,{hold:true});assert.equal(await owner.page.getByRole('button',{name:'إلغاء الحركة',exact:true}).count(),3);
  await openForm(owner);assert(await owner.page.locator('[data-op-void-reason]').evaluate(x=>x===document.activeElement));
  assert.equal(await owner.page.locator('[data-op-void-reason]').getAttribute('maxlength'),'500');
  await submit(owner,'   ');assert.equal(owner.posts.length,0);assert((await owner.page.locator('[data-op-error]').textContent()).includes('اكتب سبب'));
  await owner.page.locator('[data-op-void-cancel]').click();await owner.page.locator('.op-statement').waitFor();assert.equal(owner.posts.length,0);
  await openForm(owner);await owner.page.keyboard.press('Escape');assert(await owner.page.locator('[data-op-dialog]').isHidden());assert.equal(owner.posts.length,0);
  await owner.page.locator('[data-op-net]').first().click();await owner.page.locator('.op-statement').waitFor();await openForm(owner);
  await owner.page.locator('[data-op-void-reason]').fill('  تصحيح حركة  ');fs.mkdirSync('test-results',{recursive:true});await owner.page.screenshot({path:'test-results/employee-entry-void-desktop.png'});
  const sent=owner.page.waitForRequest(r=>new URL(r.url()).pathname==='/void-entry');await owner.page.locator('[data-op-void-submit]').click();await sent;
  assert(await owner.page.locator('[data-op-void-submit]').isDisabled());await owner.page.locator('[data-op-void-form]').dispatchEvent('submit');assert.equal(owner.posts.length,1);assert.equal(owner.posts[0].reason,'تصحيح حركة');
  owner.release();await completed(owner);assert.equal(owner.mutations(),1);assert.equal(owner.entries.length,4);await owner.page.close();
  for(const id of [43,44]){const ctx=await fixture(browser);await openForm(ctx,id);await submit(ctx);await completed(ctx,id);assert.equal(ctx.posts[0].entry_id,id);assert.equal(ctx.mutations(),1);await ctx.page.close();}
  for(const options of [{owner:false},{status:'closed'},{status:'paid'}]){const ctx=await fixture(browser,options);assert.equal(await ctx.page.getByRole('button',{name:'إلغاء الحركة',exact:true}).count(),0);assert.deepEqual(ctx.errors,[]);await ctx.page.close();}
  for(const options of [{failBefore:true},{failAfter:true}]){
   const ctx=await fixture(browser,options);await openForm(ctx);await submit(ctx);const retry=ctx.page.locator('[data-op-body] [data-op-retry]');await retry.waitFor({state:'visible'});
   assert(await ctx.page.locator('[data-op-void-reason]').isDisabled());await retry.click();await completed(ctx);
   assert.equal(ctx.mutations(),1);assert.equal(ctx.posts.length,options.failBefore?2:1);if(ctx.posts.length===2)assert.deepEqual(ctx.posts[1],ctx.posts[0]);
   assert.equal(ctx.recoveries[0].idempotency_key,ctx.posts[0].idempotency_key);assert.equal(ctx.recoveries[0].branch,branch);await ctx.page.close();
  }
  for(const status of [422,403]){const ctx=await fixture(browser,{deny:status});await openForm(ctx);await submit(ctx);await ctx.page.waitForFunction(()=>document.querySelector('[data-op-error]').textContent.length>0&&!document.querySelector('[data-op-void-submit]').disabled);assert.equal(ctx.mutations(),0);assert.equal(await ctx.page.locator('[data-op-void-reason]').inputValue(),'  تصحيح حركة  ');assert.equal(ctx.net(),'935.00');assert.deepEqual(ctx.errors,[]);await ctx.page.close();}
  const stale=await fixture(browser,{deny:409});await openForm(stale);await submit(stale);await stale.page.locator('[data-op-dialog]').waitFor({state:'hidden'});assert.equal(stale.mutations(),0);assert.deepEqual(stale.errors,[]);await stale.page.close();
  const mobile=await fixture(browser,{mobile:true,spa:true});await openForm(mobile);const bounds=await mobile.page.locator('[data-op-dialog]').boundingBox();assert(bounds.x>=0&&bounds.x+bounds.width<=390);assert(await mobile.page.locator('[data-op-void-submit]').isVisible());await mobile.page.screenshot({path:'test-results/employee-entry-void-mobile.png'});await mobile.page.evaluate(()=>employeeCleanup());assert(await mobile.page.locator('[data-op-dialog]').isHidden());assert.equal(mobile.posts.length,0);assert.deepEqual(mobile.errors,[]);await mobile.page.close();
  console.log('Employee entry cancellation: owner visibility, desktop-safe reason form, validation, cancel/Escape, all entry kinds, net refresh, audit preservation, duplicate guard, recovery, server denials, mobile and SPA cleanup passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
