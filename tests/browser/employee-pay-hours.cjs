'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..');
const attendanceSource=process.env.ATTENDANCE_JS_SOURCE?path.resolve(process.env.ATTENDANCE_JS_SOURCE):path.join(source,'public/dashboard/js/branch-operations.js');
const posts=[],requests=[],branches=[{value:'f:100',name:'المنصورة'},{value:'f:200',name:'المحلة'}];
const configured={
 'f:100@2026-10':{full_time_minutes:600,work_hours:'10',revision:3},
 'f:100@2026-11':{full_time_minutes:720,work_hours:'12',revision:9},
 'f:200@2026-10':{full_time_minutes:480,work_hours:'8',revision:4},
 'f:200@2026-11':{full_time_minutes:360,work_hours:'6',revision:5}
};
const item={id:1,branch:'f:100',name:'موظف دوام جزئي',job_title:'كاشير',shift:'دوام جزئي',revision:1,active:true,
 hired_on:'2026-10-01',schedule_type:'part_time',attendance_start:'19:10:00',attendance_end:'01:00:00',attendance_shift:'evening',attendance:null,day_closed:false,
 statement:{salary:'7100.00',net:'2404.84',status:'draft',month:'2026-10'},
 daily:Object.fromEntries(['bonus','deduction','advance'].map(k=>[k,{count:0,amount:'0.00'}]))};
const rule={starts_at:'09:00',ends_at:'19:00',late_half_hour:'10.00',early_half_hour:'10.00',absence:'100.00',revision:1};
function listing(branch='f:100',month='2026-10',owner=true){return {success:true,can_manage_attendance:owner,items:[item],branches,options:{shift:['دوام جزئي'],job_title:['كاشير']},
 filters:{branch,day:'2026-10-18',month},pagination:{page:1,last_page:1,total:1},summary:{employees:1,present:0,absent:0,leave:0},
 attendance_rules:Object.fromEntries(branches.map(b=>[b.value,{morning:rule,evening:{...rule,starts_at:'15:00',ends_at:'01:00'}}])),
 work_hours:Object.fromEntries(branches.map(b=>[b.value,{...(configured[b.value+'@'+month]||{full_time_minutes:600,work_hours:'10',revision:0}),effective_month:month}]))};}
const urls=Object.fromEntries(['data','save','attendance','recover','wallet','daily-notes','attendance-rules','statement'].map(k=>[k,'https://pay-hours.test/'+k]));
function html(owner=true){const data=listing('f:100','2026-10',owner),boot={module:'employees',urls,initial:data,branches,today:'2026-10-18'};
 const filters=['branch','search','shift','job_title','day','month'].map(k=>`<input data-op-filter="${k}" value="${data.filters[k]||''}">`).join('');
 return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="fixture"><style>${fs.readFileSync(path.join(source,'public/dashboard/css/branch-operations.css'),'utf8')}</style></head><body><main id="branch-operations" class="op-workspace op-employees"><button data-op-add>إضافة موظف</button><button data-op-refresh>تحديث</button>${owner?'<button data-op-attendance-rules>مواعيد الفرع</button>':''}<p data-op-message hidden></p><button data-op-retry hidden></button>${filters}<section data-op-cards></section><div data-op-table></div><span data-op-pages></span><button data-op-prev></button><button data-op-next></button><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>إغلاق</button></header><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 const page=await browser.newPage({viewport:{width:1089,height:1024}}),errors=[];let oldClosed=false;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://pay-hours.test/**',async route=>{
  const req=route.request(),u=new URL(req.url()),endpoint=u.pathname.slice(1);
  if(!endpoint)return route.fulfill({contentType:'text/html',body:html(u.searchParams.get('owner')!=='0')});
  if(req.method()==='GET'){
   requests.push({endpoint,branch:u.searchParams.get('branch'),month:u.searchParams.get('month')});
   if(endpoint==='statement'){
    const statement={employee:item,month:'2026-10',status:oldClosed?'closed':'draft',salary:'7100.00',earned_salary:'4122.58',eligible_days:18,month_days:31,
     bonus:'0.00',deduction:'0.00',advance:'0.00',net:oldClosed?'4122.58':'2404.84',entries:[],attendance:[]};
    if(!oldClosed)Object.assign(statement,{hours_deduction:'1717.74',effective_monthly_salary:'4141.67'});
    return route.fulfill({json:{success:true,statement}});
   }
   return route.fulfill({json:listing(u.searchParams.get('branch')||'f:100',u.searchParams.get('month')||'2026-10')});
  }
  const v=req.postDataJSON();posts.push({endpoint,v});
  if(endpoint==='attendance-rules'&&v.setting==='work_hours')configured[v.branch+'@'+v.effective_month]={full_time_minutes:Number(v.work_hours)*60,work_hours:v.work_hours,revision:(v.expected_revision||0)+1};
  return route.fulfill({json:{success:true}});
 });
 const input=k=>page.locator(`[data-op-body] [name="${k}"]`),preview=()=>page.locator('[data-op-salary-preview]');
 async function hasPreview(text){await page.waitForFunction(t=>document.querySelector('[data-op-salary-preview]').textContent.includes(t),text);}
 async function close(){await page.locator('[data-op-close]').click();}
 try{
  await page.goto('https://pay-hours.test/');await page.addScriptTag({path:attendanceSource});
  assert.equal(await page.locator('[data-op-work-hours]').count(),0,'hours button belongs inside branch schedules');
  await page.locator('[data-op-add]').click();await input('name').fill(item.name);await input('job_title').fill(item.job_title);await input('salary').fill('7100');await input('schedule_type').selectOption('part_time');
  await input('attendance_start').fill('19:10');await input('attendance_end').fill('01:00');
  await hasPreview('4141.67 جنيه');assert.match(await preview().textContent(),/2958\.33 جنيه/);assert.match(await preview().textContent(),/5 ساعة و50 دقيقة/);
  if(process.env.PAY_HOURS_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.PAY_HOURS_SCREENSHOT_DIR,'pay-hours-editor.png')});
  await input('salary').fill('100.01');await input('attendance_start').fill('18:00');await input('attendance_end').fill('23:00');await hasPreview('50.00 جنيه');assert.match(await preview().textContent(),/خصم نقص الساعات للشهر الكامل: 50\.01 جنيه/,'half-cent ties match salary minus rounded deduction');
  await input('salary').fill('7100');await input('attendance_start').fill('19:10');await input('attendance_end').fill('01:00');
  await input('branch').selectOption('f:200');await hasPreview('5177.08 جنيه');assert.match(await preview().textContent(),/ساعات الفرع اليومية: 8 ساعة/);
  await input('effective_month').fill('2026-11');await input('effective_month').dispatchEvent('change');await hasPreview('6902.78 جنيه');
  assert.ok(requests.some(r=>r.endpoint==='data'&&r.branch==='f:200'&&r.month==='2026-11'),'preview loads baseline for employee effective month');
  await input('branch').selectOption('f:100');await hasPreview('3451.39 جنيه');
  await input('attendance_start').fill('12:00');await input('attendance_end').fill('01:00');await hasPreview('7100.00 جنيه');assert.match(await preview().textContent(),/خصم نقص الساعات للشهر الكامل: 0\.00 جنيه/,'long schedules cap pay at full salary');
  await input('attendance_end').fill('12:00');assert.match(await preview().textContent(),/يجب أن يختلف/);
  await input('schedule_type').selectOption('branch');await hasPreview('7100.00 جنيه');assert.match(await preview().textContent(),/ساعات الموظف: 12 ساعة/);
  await close();await page.locator('[data-op-attendance-rules]').click();await page.locator('[data-op-work-hours]').click();
  await page.waitForFunction(()=>document.querySelector('[data-op-body] [name=work_hours]').value==='10');
  assert.equal(await input('work_hours').getAttribute('min'),'1');assert.equal(await input('work_hours').getAttribute('max'),'24');
  if(process.env.PAY_HOURS_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.PAY_HOURS_SCREENSHOT_DIR,'pay-hours-settings.png')});
  await input('effective_month').fill('2026-11');await input('effective_month').dispatchEvent('change');await page.waitForFunction(()=>document.querySelector('[data-op-body] [name=work_hours]').value==='12');
  await input('work_hours').fill('7.5');await page.getByRole('button',{name:'حفظ عدد ساعات العمل',exact:true}).click();await page.locator('[data-op-dialog]').waitFor({state:'hidden'});
  const saved=posts.at(-1);assert.equal(saved.endpoint,'attendance-rules');assert.deepEqual(Object.fromEntries(['setting','branch','work_hours','effective_month','expected_revision'].map(k=>[k,saved.v[k]])),{setting:'work_hours',branch:'f:100',work_hours:'7.5',effective_month:'2026-11',expected_revision:9});
  assert.match(saved.v.idempotency_key,/^[0-9a-f-]{36}$/i);
  await page.locator('[data-op-net]').click();await page.getByText('خصم نقص ساعات الدوام',{exact:true}).waitFor();
  const statement=page.locator('.op-statement');assert.match(await statement.textContent(),/1717\.74 جنيه/);assert.match(await statement.textContent(),/4141\.67 جنيه/);assert.match(await statement.textContent(),/خصومات الحركات المالية/);
  await close();oldClosed=true;await page.locator('[data-op-net]').click();await page.locator('.op-statement').waitFor();assert.doesNotMatch(await page.locator('.op-statement').textContent(),/undefined|NaN/);assert.equal(await page.getByText('خصم نقص ساعات الدوام',{exact:true}).count(),0,'legacy closed statements retain their saved totals');
  await close();await page.setViewportSize({width:390,height:844});await page.locator('[data-op-attendance-rules]').click();await page.locator('[data-op-work-hours]').click();
  await page.waitForFunction(()=>document.querySelector('[data-op-body] [name=work_hours]').value==='10');
  assert.equal(await page.locator('[data-op-dialog]').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'branch-hours dialog fits mobile width');
  await input('work_hours').fill('9.5');const mobileSave=page.getByRole('button',{name:'حفظ عدد ساعات العمل',exact:true});await mobileSave.scrollIntoViewIfNeeded();
  assert.equal(await mobileSave.isVisible(),true);assert.equal(await mobileSave.isEnabled(),true);const saveBounds=await mobileSave.boundingBox();assert.ok(saveBounds.x>=0&&saveBounds.x+saveBounds.width<=390,'mobile save button stays in viewport');
  await mobileSave.click();await page.locator('[data-op-dialog]').waitFor({state:'hidden'});assert.equal(posts.at(-1).v.work_hours,'9.5');assert.equal(posts.at(-1).v.effective_month,'2026-10');assert.equal(posts.at(-1).v.expected_revision,3);
  await page.goto('https://pay-hours.test/?owner=0');await page.addScriptTag({path:attendanceSource});assert.equal(await page.locator('[data-op-attendance-rules]').count(),0);assert.equal(await page.locator('[data-op-work-hours]').count(),0);
  assert.deepEqual(errors,[]);console.log('Pay-hours browser checks passed: branch hours button, revision/month save, overnight proportional preview, per-branch/month rates, full-pay cap, branch schedule, statement deductions, legacy snapshots, mobile hours save, owner visibility.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
