'use strict';
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.DESKTOP_TEST_BROWSER_MODULE||'playwright');
const source=path.resolve(__dirname,'../..');
const posts=[];
const rule={starts_at:'10:00',ends_at:'18:00',late_half_hour:'10.00',early_half_hour:'5.00',absence:'300.00',revision:1};
const data={success:true,can_manage_attendance:true,attendance_rules:{'f:100':{morning:rule,evening:{...rule,starts_at:'20:00',ends_at:'04:00'}}},
  branches:[{value:'f:100',name:'فرع المنصورة'}],options:{job_title:['كاشير'],shift:['صباحي']},filters:{branch:'f:100',day:'2026-10-08',month:'2026-10'},pagination:{page:1,last_page:1,total:1},
  summary:{employees:1,present:0,absent:0,leave:0,deduction:'0.00',bonus:'0.00',advance:'0.00'},
  items:[{id:1,branch:'f:100',name:'موظف الاختبار',job_title:'كاشير',shift:'صباحي',revision:1,active:true,attendance:null,day_closed:false,
    daily:{deduction:{count:0,amount:'0.00'},bonus:{count:0,amount:'0.00'},advance:{count:0,amount:'0.00'}},statement:{net:'3100.00',salary:'3100.00',status:'draft',month:'2026-10'}}]};
const urls=Object.fromEntries(['data','save','attendance','attendance-rules','entry','wallet','daily-notes','void-entry','statement','entries','close','pay','export','recover','orders'].map(k=>[k,'http://attendance.test/'+k]));
function html(owner=true){const boot={module:'employees',branches:data.branches,selected_branch:'f:100',initial:data,today:'2026-10-08',actor_id:owner?1:10,urls};return `<!doctype html><html lang="ar" dir="rtl"><head><meta name="csrf-token" content="test"><style>${fs.readFileSync(path.join(source,'public/dashboard/css/branch-operations.css'),'utf8')}</style></head><body><main id="branch-operations" class="op-workspace op-employees"><header class="op-header"><h1>متابعة الموظفين اليومية</h1><button data-op-add>إضافة موظف</button></header><div data-op-message hidden></div><button data-op-retry hidden></button><section class="op-filters"><label>الفرع<select data-op-filter="branch"><option value="f:100">فرع المنصورة</option></select></label><label>بحث<input data-op-filter="search"></label><label>الوردية<select data-op-filter="shift"></select></label><label>الوظيفة<select data-op-filter="job_title"></select></label><label>اليوم<input data-op-filter="day" type="date" value="2026-10-08"></label><button data-op-refresh>تحديث</button></section><section class="op-month-tools"><input data-op-filter="month" type="month" value="2026-10"><button data-op-month>تصفية حساب الشهر</button><button data-op-export>تصدير</button>${owner?'<button data-op-attendance-rules>مواعيد الحضور والانصراف والخصومات</button>':''}</section><section data-op-cards class="op-cards"></section><div data-op-table></div><nav><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button></nav><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>×</button></header><div data-op-body></div></dialog></main><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});const page=await browser.newPage({viewport:{width:1440,height:950}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://attendance.test/**',async route=>{
  const request=route.request(),endpoint=new URL(request.url()).pathname.slice(1);
  if(endpoint===''){return route.fulfill({contentType:'text/html',body:html()});}
  if(request.method()==='GET'){if(endpoint==='entries')return route.fulfill({json:{success:true,total:data.items[0].daily.deduction.amount,items:[{id:1,time:'12:35',reason:'خصم تأخر عن العمل',amount:'50.00',notes:'نصف ساعة مكتملة'}]}});return route.fulfill({json:data});}
  const v=request.postDataJSON();posts.push({endpoint,v});let result={success:true};
  if(endpoint==='attendance'){
    const previous=data.items[0].attendance||{};const a={...previous,status:v.status,day:v.day,employee_id:1,branch:'f:100',revision:(previous.revision||0)+1};
    if(v.action==='check_in'){a.check_in='12:35:00';a.checked_in_at='2026-10-08 09:35:00';data.items[0].daily.deduction={count:1,amount:'50.00'};}
    if(v.action==='check_out'){a.check_out='17:00:00';a.checked_out_at='2026-10-08 14:00:00';}
    if(v.action==='set_status'){a.check_in=null;a.checked_in_at=null;a.check_out=null;a.checked_out_at=null;data.items[0].daily.deduction={count:1,amount:v.status==='preapproved_leave'?'100.00':'300.00'};}
    data.items[0].attendance=a;result.attendance=a;
  }
  return route.fulfill({json:result});
 });
 try{
  await page.goto('http://attendance.test/');await page.addScriptTag({path:path.join(source,'public/dashboard/js/branch-operations.js')});
  await page.locator('[data-op-attendance]').click();
  assert.deepEqual(await page.locator('[name=status] option').allTextContents(),['اختر الحالة','حضور صباحًا','حضور مساءً','إجازة مسبقة','غياب بدون إذن']);
  assert.equal(await page.locator('[name=check_in],[name=check_out]').count(),0);
  await page.selectOption('[name=status]','morning');await page.waitForFunction(()=>document.querySelector('[data-op-body]').textContent.includes('12:35'));
  assert.equal(posts[0].v.action,'check_in');assert.ok(!('check_in' in posts[0].v));assert.ok(posts[0].v.idempotency_key);
  await page.locator('[data-op-close]').click();await page.locator('[data-op-table] [data-op-checkout]').click();
  await page.waitForFunction(()=>document.querySelector('[data-op-attendance]').textContent.includes('17:00'));
  assert.equal(posts[1].v.action,'check_out');assert.equal(await page.locator('[data-op-table] [data-op-checkout]').count(),0);
  await page.locator('[data-op-attendance]').click();await page.selectOption('[name=status]','unauthorized_absence');
  await page.waitForFunction(()=>document.querySelector('[data-op-attendance]').textContent.includes('غياب بدون إذن'));await page.locator('[data-op-close]').click();
  assert.equal(posts[2].v.action,'set_status');assert.ok(await page.locator('[data-op-table]').textContent().then(x=>x.includes('300.00')));
  await page.locator('[data-op-attendance]').click();await page.selectOption('[name=status]','preapproved_leave');
  await page.waitForFunction(()=>document.querySelector('[data-op-attendance]').textContent.includes('إجازة مسبقة'));await page.locator('[data-op-close]').click();assert.ok((await page.locator('[data-op-table]').textContent()).includes('100.00'));
  await page.locator('[data-op-attendance-rules]').click();await page.waitForFunction(()=>document.querySelector('[name=morning_start]').value==='10:00');
  assert.equal(await page.locator('[name=evening_end]').inputValue(),'04:00');await page.fill('[name=absence]','450.00');await page.locator('[data-op-body] [type=submit]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-op-dialog]').open);assert.equal(posts.at(-1).endpoint,'attendance-rules');assert.equal(posts.at(-1).v.absence,'450.00');assert.equal(posts.at(-1).v.expected_revision,1);
  await page.setViewportSize({width:390,height:844});await page.locator('[data-op-attendance]').click();assert.equal(await page.locator('[name=status]').inputValue(),'preapproved_leave');
  const bounds=await page.locator('[data-op-dialog]').boundingBox();assert.ok(bounds.width<=390&&bounds.x>=0);await page.locator('[data-op-close]').click();
  assert.deepEqual(errors,[]);console.log('12 browser checks passed: status choices, server clock request, checkout, automatic totals, owner configuration and mobile dialog.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
