'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..');
const script=fs.readFileSync(process.env.ATTENDANCE_ROLLOVER_SOURCE||path.join(source,'public/dashboard/js/branch-operations.js'),'utf8');
const clockScript=fs.readFileSync(path.join(source,'public/dashboard/js/dashboard-operating-day.js'),'utf8');
const before='2026-10-09T02:59:59Z',after='2026-10-09T03:00:00Z';
const branches=[{value:'f:100',name:'فرع المنصورة'}];
const urls=Object.fromEntries(['data','save','attendance','attendance-rules','entry','wallet','daily-notes','void-entry','statement','entries','close','pay','export','recover','orders'].map(k=>[k,'http://rollover.test/'+k]));
const oldAttendance={day:'2026-10-08',status:'morning',check_in:'10:00:00',check_out:'18:00:00',checked_in_at:'2026-10-08 07:00:00',checked_out_at:'2026-10-08 15:00:00',revision:2,notes:''};
function listing(day,month,operatingDay,attendance){return {success:true,operating_day:{date:operatingDay,start_hour:6,timezone:'Africa/Cairo'},can_manage_attendance:false,branches,options:{job_title:[],shift:[]},filters:{branch:'f:100',day,month},pagination:{page:1,last_page:1,total:1},summary:{employees:1,present:attendance?1:0,absent:0,leave:0,deduction:'0.00',bonus:'0.00',advance:'0.00'},items:[{id:1,branch:'f:100',name:'موظف الاختبار',job_title:'كاشير',shift:'صباحي',revision:1,active:true,attendance,day_closed:false,wallet_phone:'',daily:{deduction:{count:0,amount:'0.00'},bonus:{count:0,amount:'0.00'},advance:{count:0,amount:'0.00'}},statement:{net:'800.00',salary:'3100.00',status:'draft',month,eligible_days:8}}]};}
function html(initial,today,clockTime){const boot={module:'employees',branches,initial,today,urls};return `<!doctype html><html><head><meta name="csrf-token" content="test"></head><body><div data-dashboard-clock data-clock-server="${new Date(clockTime).getTime()}"><span data-dashboard-operating-date></span><span data-dashboard-local-time></span></div><main id="branch-operations"><button data-op-add>إضافة موظف</button><div data-op-message hidden></div><button data-op-retry hidden>تحقق</button><select data-op-filter="branch"><option value="f:100">المنصورة</option></select><input data-op-filter="day" type="date" value="${today}"><input data-op-filter="month" type="month" value="${today.slice(0,7)}"><select data-op-filter="job_title"></select><select data-op-filter="shift"></select><input data-op-filter="search"><button data-op-refresh>تحديث</button><button data-op-export>تصدير</button><section data-op-cards></section><div data-op-table></div><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button><dialog data-op-dialog><h2 data-op-title></h2><button data-op-close>إغلاق</button><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 const errors=[];let checks=0;
 async function fixture(options={}){
  const page=await browser.newPage({timezoneId:'America/Los_Angeles'});page.on('pageerror',e=>errors.push(e.message));
  await page.clock.install({time:new Date(options.time||before)});
  let operatingDay=options.operatingDay||'2026-10-08',reads=0,uncertain=false,recovered=null;
  const days={'2026-10-08':structuredClone(oldAttendance)},posts=[];
  const selected=options.selected||'2026-10-08',today=options.today||'2026-10-08';
  await page.route('http://rollover.test/**',async route=>{
   const req=route.request(),u=new URL(req.url()),endpoint=u.pathname.slice(1);
   if(!endpoint)return route.fulfill({contentType:'text/html',body:html(listing(selected,selected.slice(0,7),today,days[selected]||null),today,options.time||before)});
   if(req.method()==='GET'){
    if(endpoint==='recover')return route.fulfill({json:recovered||{success:true,found:false}});
    reads++;const day=u.searchParams.get('day')||operatingDay,month=u.searchParams.get('month')||day.slice(0,7);
    return route.fulfill({json:listing(day,month,operatingDay,days[day]||null)});
   }
   const v=req.postDataJSON();posts.push({endpoint,v});
   let result={success:true};
   if(endpoint==='attendance'){
    if(v.action==='check_in'&&v.day!==operatingDay)return route.fulfill({status:422,json:{success:false,message:'يوم سابق'}});
    const row=days[v.day]||(days[v.day]={day:v.day,revision:0});row.status=v.status;row.notes=v.notes;row.revision++;
    if(v.action==='check_in'){row.check_in='06:00:00';row.checked_in_at='2026-10-09 03:00:00';}
    if(v.action==='check_out'){row.check_out='18:00:00';row.checked_out_at='2026-10-09 15:00:00';}
    result.attendance=structuredClone(row);
    if(uncertain){uncertain=false;recovered={...result,found:true};return route.abort('failed');}
   }
   if(endpoint==='daily-notes'){days[v.day].notes=v.notes;days[v.day].revision++;result.attendance=days[v.day];}
   return route.fulfill({json:result});
  });
  await page.goto('http://rollover.test/');
  await page.evaluate(()=>{window.DashboardSPA={isCurrentPage:()=>true,onBeforeLeave:()=>{},onCleanup:fn=>window.rolloverCleanup=fn};});
  if(options.clock!==false)await page.addScriptTag({content:clockScript});
  await page.addScriptTag({content:script});
  const arrival=()=>page.locator('[data-op-attendance]'),departure=()=>page.locator('[data-op-checkout]');
  async function ready(day){await page.waitForFunction(day=>document.querySelector('[data-op-filter=day]').value===day&&!document.querySelector('[data-op-attendance]').disabled,day,{timeout:3000});}
  async function event(day){await page.evaluate(date=>window.dispatchEvent(new CustomEvent('dashboard:operating-day',{detail:{date}})),day);}
  return {page,posts,days,arrival,departure,ready,event,setDay:day=>operatingDay=day,reads:()=>reads,uncertain:()=>uncertain=true};
 }
 async function check(name,fn){await fn();checks++;process.stdout.write('PASS '+name+'\n');}
 try{
  await check('before six the previous day stays fixed; at six a new arrival and departure preserve the old row',async()=>{
   const f=await fixture();assert.ok(await f.arrival().isDisabled());assert.equal(await f.page.locator('[data-op-filter=day]').inputValue(),'2026-10-08');
   f.setDay('2026-10-09');await f.page.clock.fastForward(1000);await f.ready('2026-10-09');assert.ok(await f.departure().isDisabled());
   await f.arrival().selectOption('evening');await f.page.waitForFunction(()=>!document.querySelector('[data-op-checkout]').disabled);
   assert.equal(f.posts.at(-1).v.day,'2026-10-09');await f.departure().selectOption('evening');await f.page.waitForFunction(()=>document.querySelector('[data-op-checkout]').dataset.opRecorded==='');
   assert.deepEqual(f.days['2026-10-08'],oldAttendance);assert.equal(f.days['2026-10-09'].status,'evening');assert.equal(f.posts.length,2);await f.page.close();
  });
  await check('initialization catches an operating-day event emitted before the employee script loaded',async()=>{
   const f=await fixture({time:after,operatingDay:'2026-10-09'});await f.ready('2026-10-09');await f.page.close();
  });
  await check('server metadata catches a missed clock event and refetches the new day',async()=>{
   const f=await fixture({clock:false});f.setDay('2026-10-09');await f.page.locator('[data-op-refresh]').click();await f.ready('2026-10-09');assert.equal(f.reads(),2);await f.page.close();
  });
  await check('a tab left open without the shared clock advances during its periodic check',async()=>{
   const f=await fixture({clock:false});f.setDay('2026-10-09');await f.page.clock.fastForward(30000);await f.ready('2026-10-09');await f.page.close();
  });
  await check('returning to a sleeping tab refreshes the operating day',async()=>{
   const f=await fixture({clock:false});f.setDay('2026-10-09');await f.page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));await f.ready('2026-10-09');await f.page.close();
  });
  await check('closing a dialog completes the deferred rollover immediately',async()=>{
   const f=await fixture({clock:false});await f.page.locator('[data-op-add]').click();f.setDay('2026-10-09');await f.event('2026-10-09');assert.equal(await f.page.locator('[data-op-filter=day]').inputValue(),'2026-10-08');await f.page.locator('[data-op-close]').click();await f.ready('2026-10-09');await f.page.close();
  });
  await check('unsaved wallet text survives without blocking the new attendance day',async()=>{
   const f=await fixture({clock:false});await f.page.locator('[data-op-wallet]').evaluate(el=>{el.value='01064464499';el.dispatchEvent(new Event('input',{bubbles:true}));});f.setDay('2026-10-09');await f.event('2026-10-09');await f.ready('2026-10-09');assert.equal(await f.page.locator('[data-op-wallet]').inputValue(),'01064464499');assert.equal(f.posts.length,0);await f.page.close();
  });
  await check('daily notes save to their original day before the deferred rollover',async()=>{
   const f=await fixture({clock:false});await f.page.locator('[data-op-auto-notes]').fill('ملاحظات أمس');f.setDay('2026-10-09');await f.event('2026-10-09');await f.page.locator('[data-op-auto-notes]').dispatchEvent('change');await f.ready('2026-10-09');assert.equal(f.posts[0].endpoint,'daily-notes');assert.equal(f.posts[0].v.day,'2026-10-08');assert.equal(f.days['2026-10-08'].notes,'ملاحظات أمس');assert.equal(await f.page.locator('[data-op-auto-notes]').inputValue(),'');await f.page.close();
  });
  await check('an uncertain attendance save is recovered once before moving to the next day',async()=>{
   const f=await fixture({clock:false,selected:'2026-10-09',today:'2026-10-09',operatingDay:'2026-10-09'});f.uncertain();await f.arrival().selectOption('morning');await f.page.waitForFunction(()=>!document.querySelector('[data-op-retry]').hidden);f.setDay('2026-10-10');await f.event('2026-10-10');await f.page.locator('[data-op-retry]').click();await f.ready('2026-10-10');assert.equal(f.posts.length,1);assert.equal(f.days['2026-10-09'].revision,1);await f.page.close();
  });
  await check('a month boundary advances the day and current month together',async()=>{
   const f=await fixture({clock:false,selected:'2026-10-31',today:'2026-10-31',operatingDay:'2026-10-31'});f.setDay('2026-11-01');await f.event('2026-11-01');await f.ready('2026-11-01');assert.equal(await f.page.locator('[data-op-filter=month]').inputValue(),'2026-11');await f.page.close();
  });
  await check('an explicitly selected historical date keeps its matching attendance and month',async()=>{
   const f=await fixture({clock:false,today:'2026-10-09',operatingDay:'2026-10-09'});assert.equal(await f.page.locator('[data-op-filter=day]').inputValue(),'2026-10-08');f.setDay('2026-11-01');await f.event('2026-11-01');await f.page.locator('[data-op-refresh]').click();await f.page.waitForResponse(r=>new URL(r.url()).pathname==='/data');assert.equal(await f.page.locator('[data-op-filter=day]').inputValue(),'2026-10-08');assert.equal(await f.page.locator('[data-op-filter=month]').inputValue(),'2026-10');assert.ok(await f.arrival().isDisabled());await f.page.close();
  });
  await check('leaving a SPA page removes its clock polling and resume listeners',async()=>{
   const f=await fixture({clock:false});await f.page.evaluate(()=>window.rolloverCleanup());const reads=f.reads();await f.page.clock.fastForward(60000);await f.page.evaluate(()=>{window.dispatchEvent(new Event('focus'));document.dispatchEvent(new Event('visibilitychange'));});assert.equal(f.reads(),reads);await f.page.close();
  });
  assert.deepEqual(errors,[]);process.stdout.write(checks+' employee attendance rollover scenarios passed.\n');
 }finally{await browser.close();}
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exit(1);});
