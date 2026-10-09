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
    daily:{deduction:{count:0,amount:'0.00'},bonus:{count:0,amount:'0.00'},advance:{count:0,amount:'0.00'}},statement:{net:'800.00',earned_salary:'800.00',salary:'3100.00',status:'draft',month:'2026-10',eligible_days:8,month_days:31,accrued_from:'2026-10-01',accrued_through:'2026-10-08'}}]};
const urls=Object.fromEntries(['data','save','attendance','attendance-rules','entry','wallet','daily-notes','void-entry','statement','entries','close','pay','export','recover','orders'].map(k=>[k,'http://attendance.test/'+k]));
function html(owner=true){const boot={module:'employees',branches:data.branches,selected_branch:'f:100',initial:data,today:'2026-10-08',actor_id:owner?1:10,urls};return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test"><style>${fs.readFileSync(path.join(source,'public/dashboard/css/branch-operations.css'),'utf8')}</style></head><body><div class="content-wrapper"><main id="branch-operations" class="op-workspace op-employees"><header class="op-header"><div><small>إدارة الفروع</small><h1>متابعة الموظفين اليومية</h1><p>الحضور والخصومات والمكافآت والسلف لكل موظف، في اليوم المحدد.</p></div><div class="op-header-actions"><button data-op-add class="op-primary">إضافة موظف</button>${owner?'<button data-op-attendance-rules class="op-primary">مواعيد الحضور والانصراف والخصومات</button>':''}</div></header><div data-op-message class="op-message" hidden></div><button data-op-retry hidden></button><section class="op-filters"><label>الفرع<select data-op-filter="branch"><option value="f:100">فرع المنصورة</option></select></label><label>بحث<input data-op-filter="search"></label><label>الوردية<select data-op-filter="shift"></select></label><label>الوظيفة<select data-op-filter="job_title"></select></label><label>اليوم<input data-op-filter="day" type="date" value="2026-10-08"></label><button data-op-refresh>تحديث</button></section><section class="op-month-tools"><input data-op-filter="month" type="month" value="2026-10"><button data-op-month>تصفية حساب الشهر</button><button data-op-export>تصدير</button></section><section data-op-cards class="op-cards"></section><div data-op-table></div><nav class="op-pager"><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button></nav><dialog data-op-dialog><header><h2 data-op-title></h2><button data-op-close>×</button></header><div data-op-body></div></dialog></main></div><script id="branch-operations-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox'],executablePath:process.env.ATTENDANCE_BROWSER_EXECUTABLE||undefined});
 const page=await browser.newPage({viewport:{width:1440,height:950}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let rejectAttendance=false,uncertainAttendance=false,recovered=null;
 function updateTotals(amount){data.items[0].daily.deduction={count:amount==='0.00'?0:1,amount};data.summary.deduction=amount;data.items[0].statement.net=(800-Number(amount)).toFixed(2);}
 await page.route('http://attendance.test/**',async route=>{
  const request=route.request(),endpoint=new URL(request.url()).pathname.slice(1);
  if(endpoint==='')return route.fulfill({contentType:'text/html',body:html()});
  if(request.method()==='GET'){
   if(endpoint==='recover')return route.fulfill({json:recovered||{success:true,found:false}});
   if(endpoint==='statement')return route.fulfill({json:{success:true,statement:{...data.items[0].statement,employee:data.items[0],bonus:'0.00',deduction:data.items[0].daily.deduction.amount,advance:'0.00',entries:[],attendance:data.items[0].attendance?[data.items[0].attendance]:[]}}});
   return route.fulfill({json:data});
  }
  const v=request.postDataJSON();posts.push({endpoint,v});let result={success:true};
  if(endpoint==='attendance'){
   if(rejectAttendance){rejectAttendance=false;return route.fulfill({status:422,json:{success:false,message:'لم يتم تسجيل الحضور'}});}
   const previous=data.items[0].attendance||{},a={...previous,status:v.status,day:v.day,employee_id:1,branch:'f:100',notes:v.notes,revision:(previous.revision||0)+1};
   if(v.action==='check_in'){a.check_in=previous.check_in||'12:35:00';a.checked_in_at=previous.checked_in_at||'2026-10-08 09:35:00';updateTotals('50.00');}
   if(v.action==='check_out'){a.check_out='17:00:00';a.checked_out_at='2026-10-08 14:00:00';updateTotals('60.00');}
   if(v.action==='set_status'){a.check_in=null;a.checked_in_at=null;a.check_out=null;a.checked_out_at=null;updateTotals(v.status==='preapproved_leave'?'100.00':'300.00');}
   data.items[0].attendance=a;result.attendance=a;
   if(uncertainAttendance){uncertainAttendance=false;recovered={...result,found:true};return route.abort('failed');}
  }
  if(endpoint==='daily-notes'){const previous=data.items[0].attendance||{};data.items[0].attendance={...previous,notes:v.notes,revision:(previous.revision||0)+1};result.attendance=data.items[0].attendance;}
  return route.fulfill({json:result});
 });
 const arrival=()=>page.locator('[data-op-table] select[data-op-attendance]'),departure=()=>page.locator('[data-op-table] select[data-op-checkout]');
 const selected=control=>control.evaluate(el=>el.selectedOptions[0].textContent);
 async function waitRecorded(selector,text){await page.waitForFunction(({selector,text})=>document.querySelector(selector).selectedOptions[0].textContent===text,{selector,text});}
 async function refreshed(action,renders=2){const before=await page.evaluate(()=>window.attendanceTableRenders);await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname==='/data'),action()]);await page.waitForFunction(({before,renders})=>window.attendanceTableRenders>=before+renders,{before,renders});}
 async function refresh(){await refreshed(()=>page.locator('[data-op-refresh]').click(),1);}
 async function choose(control,value){
  if(await control.evaluate(el=>el.classList.contains('select2-hidden-accessible'))){
   const text=await control.locator('option[value="'+value+'"]').textContent();
   await control.locator('xpath=following-sibling::span[1]').locator('.select2-selection').click();
   await page.getByRole('option',{name:text,exact:true}).click();
  }else await control.selectOption(value);
 }
 async function enhanceAttendance(){await page.evaluate(()=>window.jQuery('[data-op-attendance],[data-op-checkout]').select2());}

 try{
  await page.goto('http://attendance.test/');
  await page.addStyleTag({path:path.join(source,'public/dashboard/dist/css/select2.min.css')});
  await page.addScriptTag({path:path.join(source,'public/dashboard/plugins/jquery/jquery.min.js')});
  await page.addScriptTag({path:path.join(source,'public/dashboard/dist/js/select2.min.js')});
  await page.addScriptTag({path:path.join(source,'public/dashboard/js/branch-operations.js')});
  await page.addScriptTag({path:path.join(source,'public/dashboard/dist/js/adminlte.js')});
  assert.ok(await arrival().evaluate(el=>el.classList.contains('select2-hidden-accessible')));

  await page.evaluate(()=>{window.attendanceTableRenders=0;new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1&&node.matches('.op-table-wrap'))window.attendanceTableRenders++;}).observe(document.querySelector('[data-op-table]'),{childList:true});window.attendanceDialogOpens=0;const dialog=document.querySelector('[data-op-dialog]'),show=dialog.showModal.bind(dialog);dialog.showModal=()=>{window.attendanceDialogOpens++;show();};});
  assert.equal(await arrival().count(),1);assert.equal(await departure().count(),1);
  assert.ok((await page.locator('[data-op-net]').textContent()).includes('8 يوم'));assert.ok((await page.locator('[data-op-net]').getAttribute('title')).includes('2026-10-01 حتى 2026-10-08'));
  assert.deepEqual(await arrival().locator('option').allTextContents(),['تسجيل الحضور','حضور صباحًا','حضور مساءً','إجازة مسبقة','غياب بدون إذن']);
  assert.deepEqual(await departure().locator('option').allTextContents(),['تسجيل الانصراف','انصراف صباحًا','انصراف مساءً']);
  assert.ok(await departure().isDisabled());assert.equal(await page.locator('[name=check_in],[name=check_out]').count(),0);
  await refreshed(()=>choose(arrival(),'morning'));await waitRecorded('[data-op-attendance]','12:35');
  assert.equal(posts.length,1);assert.equal(await page.locator('.select2-container--open').count(),0);assert.equal(posts[0].v.action,'check_in');assert.ok(!('check_in' in posts[0].v)&&!('checked_in_at' in posts[0].v));assert.ok(posts[0].v.idempotency_key);
  assert.equal(posts[0].v.expected_revision,null);assert.equal(await arrival().inputValue(),'');assert.ok(!(await departure().isDisabled()));
  assert.ok(await departure().locator('[value=evening]').evaluate(el=>el.disabled));assert.ok(!(await departure().locator('[value=morning]').evaluate(el=>el.disabled)));
  assert.ok(await page.locator('[data-op-total=deduction]').textContent().then(x=>x.includes('50.00')));
  assert.equal(await page.evaluate(()=>document.activeElement.matches('[data-op-attendance]')),false);
  assert.ok(await arrival().isDisabled());
  const recordedPosts=posts.length;await arrival().evaluate(el=>{el.value='evening';el.dispatchEvent(new Event('change',{bubbles:true}));});
  assert.equal(posts.length,recordedPosts);assert.equal(await selected(arrival()),'12:35');assert.ok((await page.locator('[data-op-total=deduction]').textContent()).includes('50.00'));
  await enhanceAttendance();await refreshed(()=>choose(departure(),'morning'));await waitRecorded('[data-op-checkout]','17:00');
  assert.equal(posts.filter(p=>p.endpoint==='attendance').length,2);assert.equal(await page.locator('.select2-container--open').count(),0);assert.equal(posts.at(-1).v.action,'check_out');assert.equal(posts.at(-1).v.expected_revision,1);assert.ok(!('check_out' in posts.at(-1).v));
  assert.ok(await departure().isDisabled());assert.equal(await selected(arrival()),'12:35');
  await page.waitForFunction(()=>document.querySelector('[data-op-net]').textContent.includes('740.00'));
  await page.locator('[data-op-auto-notes]').fill('ملاحظة محفوظة لليوم');await refreshed(()=>page.locator('[data-op-auto-notes]').press('Tab'),1);
  assert.ok(await arrival().isDisabled());assert.ok(await departure().isDisabled());
  // Independent fresh employee/day cases exercise the first choice, never rewriting a saved one.
  async function fresh(){data.items[0].attendance=null;data.items[0].day_closed=false;updateTotals('0.00');await refresh();await enhanceAttendance();assert.ok(!(await arrival().isDisabled()));}
  await fresh();
  await refreshed(()=>choose(arrival(),'unauthorized_absence'));await waitRecorded('[data-op-attendance]','غياب بدون إذن');
  assert.equal(posts.at(-1).v.action,'set_status');assert.equal(posts.at(-1).v.notes,'');assert.ok(await arrival().isDisabled());assert.equal(await selected(departure()),'تسجيل الانصراف');assert.ok(await departure().isDisabled());
  await page.waitForFunction(()=>document.querySelector('[data-op-total=deduction]').textContent.includes('300.00'));
  await fresh();
  await refreshed(()=>choose(arrival(),'preapproved_leave'));await waitRecorded('[data-op-attendance]','إجازة مسبقة');
  await page.waitForFunction(()=>document.querySelector('[data-op-total=deduction]').textContent.includes('100.00'));
  assert.ok(await arrival().isDisabled());await fresh();rejectAttendance=true;await choose(arrival(),'morning');await page.waitForFunction(()=>document.querySelector('[data-op-message]').textContent==='لم يتم تسجيل الحضور');
  assert.equal(await selected(arrival()),'تسجيل الحضور');assert.ok((await arrival().locator('xpath=following-sibling::span[1]').textContent()).includes('تسجيل الحضور'));assert.ok(!(await arrival().isDisabled()));assert.ok(await departure().isDisabled());
  uncertainAttendance=true;await choose(arrival(),'evening');await page.waitForFunction(()=>document.querySelector('[data-op-message]').textContent.includes('غير مؤكدة'));
  const count=posts.length;assert.ok(await arrival().isDisabled());await refreshed(()=>page.locator('[data-op-retry]').click());await waitRecorded('[data-op-attendance]','12:35');
  assert.equal(posts.length,count);assert.ok(await arrival().isDisabled());assert.ok(await departure().locator('[value=morning]').evaluate(el=>el.disabled));
  data.items[0].day_closed=true;await refresh();assert.ok(await arrival().isDisabled());assert.ok(await departure().isDisabled());
  data.items[0].day_closed=false;await refresh();assert.ok(await arrival().isDisabled());
  assert.equal(await page.evaluate(()=>window.attendanceDialogOpens),0);
  assert.equal(await page.locator('.op-header-actions [data-op-add]').count(),1);assert.equal(await page.locator('.op-header-actions [data-op-attendance-rules]').count(),1);
  await page.locator('[data-op-attendance-rules]').click();await page.waitForFunction(()=>document.querySelector('[name=morning_start]').value==='10:00');
  assert.equal(await page.locator('[name=evening_end]').inputValue(),'04:00');await page.fill('[name=absence]','450.00');await refreshed(()=>page.locator('[data-op-body] [type=submit]').click(),1);
  await page.waitForFunction(()=>!document.querySelector('[data-op-dialog]').open);assert.equal(posts.at(-1).endpoint,'attendance-rules');assert.equal(posts.at(-1).v.absence,'450.00');assert.equal(posts.at(-1).v.expected_revision,1);
  if(process.env.ATTENDANCE_BROWSER_SCREENSHOT)await page.screenshot({path:process.env.ATTENDANCE_BROWSER_SCREENSHOT+'.desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await fresh();await arrival().scrollIntoViewIfNeeded();await refreshed(()=>choose(arrival(),'evening'));assert.ok(await arrival().isDisabled());
  await departure().scrollIntoViewIfNeeded();await refreshed(()=>choose(departure(),'evening'));await waitRecorded('[data-op-checkout]','17:00');
  assert.equal(await selected(arrival()),'12:35');assert.equal(await page.evaluate(()=>window.attendanceDialogOpens),1);
  const bounds=await departure().boundingBox();assert.ok(bounds.width>50&&bounds.width<200&&bounds.x>=0&&bounds.x+bounds.width<=390,JSON.stringify(bounds));
  if(process.env.ATTENDANCE_BROWSER_SCREENSHOT)await page.screenshot({path:process.env.ATTENDANCE_BROWSER_SCREENSHOT,fullPage:true});
  // Dense ledger uses the existing dashboard CSS and sidebar width, with every money control populated.
  const template=JSON.parse(JSON.stringify(data.items[0]));data.items=Array.from({length:6},(_,i)=>({...JSON.parse(JSON.stringify(template)),id:i+1,name:'موظف الاختبار '+(i+1),daily:{deduction:{count:1,amount:'50.00'},bonus:{count:1,amount:'25.00'},advance:{count:1,amount:'50.00'}}}));data.summary.employees=6;data.pagination.total=6;
  await page.addStyleTag({path:path.join(source,'public/dashboard/dist/css/adminrtl.css')});
  await page.addStyleTag({path:path.join(source,'public/dashboard/branding/dashboard-brand.css')});
  await page.addStyleTag({path:path.join(source,'public/dashboard/css/branch-operations.css')});
  await page.addStyleTag({content:'body{margin:0}.content-wrapper{margin:56px 250px 0 0!important;min-height:0!important}'});
  await page.evaluate(()=>{document.body.classList.add('dashboard-theme');document.querySelector('[data-op-message]').hidden=true;});
  for(const size of [{width:1920,height:950},{width:1440,height:820},{width:1366,height:768}]){
   await page.setViewportSize(size);await refresh();await enhanceAttendance();await page.evaluate(()=>window.scrollTo(0,0));
   const rows=page.locator('[data-op-table] tbody tr');assert.equal(await rows.count(),6);
   const sixth=await rows.nth(5).boundingBox();assert.ok(sixth.y+sixth.height<=size.height-12,'Sixth employee is outside the initial viewport: '+JSON.stringify({size,sixth}));
   const clipped=await page.locator('.op-attendance-cell .select2-selection__rendered').first().evaluate(el=>el.scrollWidth>el.clientWidth);assert.equal(clipped,false,'Recorded attendance time must fit in the control');
   const add=await page.locator('[data-op-add]').boundingBox(),settings=await page.locator('[data-op-attendance-rules]').boundingBox();assert.ok(Math.abs(add.y-settings.y)<2,'Header actions must share a row');
   if(process.env.ATTENDANCE_BROWSER_SCREENSHOT)await page.screenshot({path:process.env.ATTENDANCE_BROWSER_SCREENSHOT+'.six-'+size.width+'.png',fullPage:true});
  }
  await page.locator('[data-op-net]').first().click();await page.waitForFunction(()=>document.querySelector('.op-statement'));
  assert.ok((await page.locator('.op-statement').textContent()).includes('800.00'));assert.ok((await page.locator('.op-statement').textContent()).includes('8 / 31 يوم'));assert.ok((await page.locator('.op-statement').textContent()).includes('من 2026-10-01 حتى 2026-10-08'));
  assert.deepEqual(errors,[]);console.log('Browser checks passed: real AdminLTE/Select2 dropdown clicks and native controls, Cairo clock requests, automatic totals, fixed attendance/departure choices, rejected saves, safe retry, closed months, owner configuration, mobile recording, accrued salary days/dates and six visible employee rows at three desktop sizes.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
