'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..'),posts=[];
const attendanceSource=process.env.ATTENDANCE_JS_SOURCE?path.resolve(process.env.ATTENDANCE_JS_SOURCE):path.join(source,'public/dashboard/js/branch-operations.js');
const item={id:1,branch:'f:100',name:'موظف الساعة السادسة',job_title:'كاشير',shift:'مسائي',revision:1,
 active:true,hired_on:'2026-01-01',schedule_type:'branch',attendance:null,day_closed:false,
 statement:{salary:'3100.00',net:'1800.00',status:'draft',month:'2026-10'},
 daily:Object.fromEntries(['bonus','deduction','advance'].map(k=>[k,{count:0,amount:'0.00'}]))};
const data={success:true,items:[item],branches:[{value:'f:100',name:'المحلة'}],options:{shift:['مسائي'],job_title:['كاشير']},
 filters:{branch:'f:100',day:'2026-10-18',month:'2026-10'},pagination:{page:1,last_page:1,total:1},summary:{employees:1,present:0,absent:0,leave:0}};
const urls=Object.fromEntries(['data','save','attendance','recover','wallet','daily-notes'].map(k=>[k,'https://part-time.test/'+k]));
const boot={module:'employees',urls,initial:data,branches:data.branches,today:'2026-10-18'};
const filters=['branch','search','shift','job_title','day','month'].map(k=>`<input data-op-filter="${k}" value="${data.filters[k]||''}">`).join('');
const html=`<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="fixture"><style>${fs.readFileSync(path.join(source,'public/dashboard/css/branch-operations.css'),'utf8')}</style></head><body><main id="branch-operations" class="op-workspace op-employees"><button data-op-add>إضافة موظف</button><button data-op-refresh>تحديث</button><p data-op-message hidden></p><button data-op-retry hidden></button>${filters}<section data-op-cards></section><div data-op-table></div><span data-op-pages></span><button data-op-prev></button><button data-op-next></button><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>إغلاق</button></header><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 const page=await browser.newPage({viewport:{width:1089,height:1024}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://part-time.test/**',async route=>{
  const req=route.request(),endpoint=new URL(req.url()).pathname.slice(1);
  if(!endpoint)return route.fulfill({contentType:'text/html',body:html});
  if(req.method()==='GET')return route.fulfill({json:data});
  const v=req.postDataJSON();posts.push({endpoint,v});
  if(endpoint==='save'){Object.assign(item,v,{revision:item.revision+1});if(v.schedule_type==='branch')Object.assign(item,{attendance_start:null,attendance_end:null,attendance_shift:null});}
  if(endpoint==='attendance'){
   item.attendance={...(item.attendance||{}),status:v.status,revision:1,checked_in_at:'2026-10-18 15:00:00',check_in:'18:00:00',
    attendance_rule_snapshot:JSON.stringify({starts_at:'18:00',ends_at:'23:00'})};
   if(v.action==='check_out')Object.assign(item.attendance,{checked_out_at:'2026-10-18 20:00:00',check_out:'23:00:00'});
  }
  return route.fulfill({json:{success:true,employee:item,attendance:item.attendance}});
 });
 async function refresh(){await page.getByRole('button',{name:'تحديث',exact:true}).click();await page.waitForTimeout(50);}
 async function edit(){await page.locator('.op-row-more summary').click();await page.getByRole('button',{name:'تعديل الموظف'}).click();}
 const input=k=>page.locator(`[data-op-body] [name="${k}"]`);
 try{
  await page.goto('https://part-time.test/');await page.addScriptTag({path:attendanceSource});
  await page.locator('[data-op-add]').click();
  assert.equal(await input('schedule_type').inputValue(),'branch');assert.equal(await input('attendance_start').isVisible(),false);assert.equal(await input('attendance_start').isDisabled(),true);
  await input('schedule_type').selectOption('part_time');assert.equal(await input('attendance_start').isVisible(),true);assert.equal(await input('attendance_start').getAttribute('required'),'');
  await input('name').fill(item.name);await input('job_title').fill(item.job_title);await input('salary').fill('3100.00');
  await input('attendance_start').fill('18:00');await input('attendance_end').fill('23:00');await input('attendance_shift').selectOption('evening');
  if(process.env.ATTENDANCE_BROWSER_SCREENSHOT)await page.screenshot({path:process.env.ATTENDANCE_BROWSER_SCREENSHOT+'.part-time-editor.png'});
  await page.getByRole('button',{name:'حفظ',exact:true}).click();await page.locator('[data-op-dialog]').waitFor({state:'hidden'});
  assert.equal(posts[0].v.schedule_type,'part_time');assert.equal(posts[0].v.attendance_start,'18:00');assert.equal(posts[0].v.attendance_end,'23:00');assert.equal(posts[0].v.attendance_shift,'evening');
  await page.waitForFunction(()=>document.querySelector('[data-op-table]').textContent.includes('دوام جزئي · 18:00'));
  const arrival=()=>page.locator('[data-op-attendance]'),departure=()=>page.locator('[data-op-checkout]');
  assert.deepEqual(await arrival().locator('option').allTextContents(),['تسجيل الحضور','حضور دوام جزئي','إجازة مسبقة','غياب بدون إذن']);
  await arrival().selectOption('part_time');await page.waitForFunction(()=>document.querySelector('[data-op-attendance]').disabled);
  assert.equal(posts.at(-1).v.status,'part_time');assert.equal(posts.at(-1).v.action,'check_in');assert.ok(!('check_in' in posts.at(-1).v));
  assert.deepEqual(await departure().locator('option').allTextContents(),['تسجيل الانصراف','انصراف دوام جزئي']);
  await edit();assert.equal(await input('schedule_type').inputValue(),'part_time');assert.equal(await input('attendance_start').inputValue(),'18:00');
  await input('schedule_type').selectOption('branch');assert.equal(await input('attendance_start').isDisabled(),true);assert.equal(await input('attendance_start').isVisible(),false);
  await page.getByRole('button',{name:'حفظ',exact:true}).click();await page.locator('[data-op-dialog]').waitFor({state:'hidden'});
  assert.equal(posts.at(-1).v.schedule_type,'branch');assert.ok(!('attendance_start' in posts.at(-1).v));
  await refresh();assert.equal(await departure().locator('option[value=part_time]').count(),1,'recorded part-time departure survives an employee schedule change');
  await departure().selectOption('part_time');await page.waitForFunction(()=>document.querySelector('[data-op-checkout]').disabled);
  assert.equal(posts.at(-1).v.action,'check_out');assert.equal(posts.at(-1).v.status,'part_time');
  item.attendance=null;item.schedule_type='part_time';item.attendance_start='18:00';item.attendance_end='23:00';item.attendance_shift='evening';
  await page.setViewportSize({width:390,height:844});await refresh();await edit();
  const dialog=page.locator('[data-op-dialog]');assert.equal(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'part-time editor fits mobile width');
  assert.equal(await input('attendance_end').inputValue(),'23:00');assert.deepEqual(errors,[]);
  console.log('Part-time browser checks passed: create/edit, required custom hours, stored schedule, dedicated attendance, frozen-day checkout, branch reset, mobile layout.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
