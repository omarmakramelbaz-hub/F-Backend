'use strict';
// Run against the scoped live-clock candidate via ATTENDANCE_JS_SOURCE.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..');
const attendanceSource=process.env.ATTENDANCE_JS_SOURCE?path.resolve(process.env.ATTENDANCE_JS_SOURCE):path.join(source,'public/dashboard/js/branch-operations.js');
const oldDay='2026-10-17',newDay='2026-10-18',before=new Date('2026-10-18T02:59:59Z');
const item={id:1,branch:'f:100',name:'موظف الساعة السادسة',job_title:'كاشير',shift:'مسائي',revision:1,
 active:true,hired_on:'2026-01-01',schedule_type:'part_time',attendance_start:'18:00',attendance_end:'23:00',attendance_shift:'evening',attendance:null,day_closed:false,
 statement:{salary:'3100.00',net:'1800.00',status:'draft',month:'2026-10'},
 daily:Object.fromEntries(['bonus','deduction','advance'].map(k=>[k,{count:0,amount:'0.00'}]))};
const branches=[{value:'f:100',name:'المحلة'}],posts=[],requests=[];
let serverDay=oldDay;
function data(day){return {success:true,items:[item],branches,options:{shift:['مسائي'],job_title:['كاشير']},operating_day:{date:serverDay},
 filters:{branch:'f:100',day,month:'2026-10'},pagination:{page:1,last_page:1,total:1},summary:{employees:1,present:0,absent:0,leave:0}};}
const urls=Object.fromEntries(['data','save','attendance','recover','wallet','daily-notes'].map(k=>[k,'https://part-time-clock.test/'+k]));
const boot={module:'employees',urls,initial:data(oldDay),branches,today:oldDay};
const filters=['branch','search','shift','job_title','day','month'].map(k=>`<input data-op-filter="${k}" value="${boot.initial.filters[k]||''}">`).join('');
const html=`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"><style>${fs.readFileSync(path.join(source,'public/dashboard/css/branch-operations.css'),'utf8')}</style></head><body><div data-dashboard-clock data-clock-server="${before.getTime()}"><bdi data-dashboard-operating-date></bdi><bdi data-dashboard-local-time></bdi></div><main id="branch-operations" class="op-workspace op-employees"><button data-op-add>إضافة موظف</button><button data-op-refresh>تحديث</button><p data-op-message hidden></p><button data-op-retry hidden></button>${filters}<section data-op-cards></section><div data-op-table></div><span data-op-pages></span><button data-op-prev></button><button data-op-next></button><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>إغلاق</button></header><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 const page=await browser.newPage({timezoneId:'America/Los_Angeles',viewport:{width:1089,height:1024}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.clock.install({time:before});await page.clock.pauseAt(before);
 await page.route('https://part-time-clock.test/**',async route=>{
  const req=route.request(),url=new URL(req.url()),endpoint=url.pathname.slice(1);
  if(!endpoint)return route.fulfill({contentType:'text/html',body:html});
  if(req.method()==='GET'){requests.push({endpoint,day:url.searchParams.get('day')});return route.fulfill({json:data(url.searchParams.get('day')||serverDay)});}
  const payload=req.postDataJSON();posts.push({endpoint,payload});
  if(endpoint==='attendance')item.attendance={status:payload.status,revision:1,checked_in_at:'2026-10-18 15:00:00',check_in:'18:00:00'};
  return route.fulfill({json:{success:true,employee:item,attendance:item.attendance}});
 });
 try{
  await page.goto('https://part-time-clock.test/');
  await page.evaluate(()=>{
   window.fixtureCleanup=null;window.fixtureIntervals=new Set();
   const set=window.setInterval.bind(window),clear=window.clearInterval.bind(window);
   window.setInterval=(fn,delay,...args)=>{const id=set(fn,delay,...args);window.fixtureIntervals.add(id);return id;};
   window.clearInterval=id=>{window.fixtureIntervals.delete(id);return clear(id);};
   window.DashboardSPA={isCurrentPage:()=>true,onBeforeLeave:()=>{},onCleanup:fn=>{window.fixtureCleanup=fn;}};
  });
  await page.addScriptTag({path:path.join(source,'public/dashboard/js/dashboard-operating-day.js')});
  await page.addScriptTag({path:attendanceSource});
  assert.equal(await page.evaluate(()=>DashboardOperatingDay.current()),oldDay);
  assert.equal(await page.evaluate(()=>fixtureIntervals.size),2,'one persistent dashboard clock and one employee refresh timer');
  assert.ok((await page.locator('[data-op-table]').textContent()).includes('دوام جزئي · 18:00'));
  await page.locator('.op-row-more summary').click();await page.getByRole('button',{name:'تعديل الموظف'}).click();
  const name=page.locator('[data-op-body] [name="name"]');await name.fill('تعديل لم يحفظ بعد');
  assert.equal(await page.locator('[data-op-body] [name="attendance_start"]').inputValue(),'18:00');
  serverDay=newDay;await page.clock.fastForward(1000);
  assert.equal(await page.locator('[data-dashboard-local-time]').textContent(),'6:00 am');
  assert.equal(await page.locator('[data-dashboard-operating-date]').textContent(),newDay);
  assert.equal(await page.locator('[data-op-filter="day"]').inputValue(),oldDay,'rollover waits for the open employee editor');
  assert.equal(await name.inputValue(),'تعديل لم يحفظ بعد');
  assert.ok((await page.locator('[data-op-message]').textContent()).includes('6 صباحًا'));

  // Dispatch the existing attendance control while the editor is still open: stale-day writes must never leave the page.
  await Promise.all([
   page.waitForResponse(r=>new URL(r.url()).pathname==='/data'),
   page.locator('[data-op-attendance]').evaluate(el=>{el.value='part_time';el.dispatchEvent(new Event('change',{bubbles:true}));}),
  ]);
  assert.equal(posts.length,0,'the prior day cannot receive a part-time attendance post after 6AM');
  assert.equal(await name.inputValue(),'تعديل لم يحفظ بعد','refresh preserves the open editor draft');
  await Promise.all([
   page.waitForResponse(r=>new URL(r.url()).pathname==='/data'&&new URL(r.url()).searchParams.get('day')===newDay),
   page.locator('[data-op-close]').click(),
  ]);
  await page.waitForFunction(day=>document.querySelector('[data-op-table] tfoot').textContent.includes(day),newDay);
  assert.equal(await page.locator('[data-op-filter="day"]').inputValue(),newDay);
  assert.ok(requests.some(r=>r.day===newDay),'closing the editor refreshes the new operating day');
  await Promise.all([
   page.waitForResponse(r=>new URL(r.url()).pathname==='/attendance'),
   page.locator('[data-op-attendance]').selectOption('part_time'),
  ]);
  await page.waitForFunction(()=>document.querySelector('[data-op-attendance]').disabled);
  assert.equal(posts.length,1);assert.equal(posts[0].payload.day,newDay);assert.equal(posts[0].payload.status,'part_time');assert.equal(posts[0].payload.action,'check_in');
  await page.waitForFunction(()=>document.querySelector('[data-op-checkout] option[value="part_time"]'));
  const countBeforeCleanup=requests.length;
  await page.evaluate(()=>fixtureCleanup());
  assert.equal(await page.evaluate(()=>fixtureIntervals.size),1,'SPA cleanup removes only the employee refresh timer');
  await page.evaluate(()=>{window.dispatchEvent(new Event('focus'));document.dispatchEvent(new Event('visibilitychange'));window.dispatchEvent(new CustomEvent('dashboard:operating-day',{detail:{date:'2026-10-19',previous:'2026-10-18'}}));});
  await page.clock.fastForward(31000);
  assert.equal(requests.length,countBeforeCleanup,'disposed employee UI has no timer, focus or rollover requests');
  assert.deepEqual(errors,[]);
  console.log('Part-time live-clock checks passed: Cairo 6AM rollover, preserved editor draft, blocked stale-day attendance, rollover on dialog close, new-day part-time attendance and SPA timer/listener cleanup.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
