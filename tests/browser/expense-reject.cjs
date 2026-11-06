const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const labels=Object.fromEntries([...read('resources/lang/ar/expenses.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
const branches=[{value:'f:100',name:'فرع المحلة'}];
function template(canApprove){
 let html=read('resources/views/admin/expenses/index.blade.php').split("@section('content')")[1].split('@endsection')[0];
 html=html.replace(/@foreach\(\['today'=>[\s\S]*?@endforeach/,['today','month','average','top_category','count'].map(k=>`<article class="ex-metric"><div><h2>${labels[k]}</h2><strong data-expense-metric="${k}"></strong><small ${k==='top_category'?'data-expense-top-amount':''}>${labels.currency}</small></div></article>`).join(''));
 html=html.replace(/@foreach\(\$boot\['branches'\] as \$branch\)[\s\S]*?@endforeach/g,'<option value="f:100">فرع المحلة</option>');
 html=html.replace(/@foreach\(\$boot\['initial'\]\['(?:active_)?categories'\] as \$category=>\$categoryName\)[\s\S]*?@endforeach/g,'<option value="purchases">مشتريات</option>');
 html=html.replace(/@foreach\(\['pending',[\s\S]*?@endforeach/g,['pending','approved','rejected','voided'].map(s=>`<option value="${s}">${labels['status_'+s]}</option>`).join(''));
 html=html.replace(/@foreach\(\['number',[\s\S]*?@endforeach/g,['number','date','branch','category','description','amount','payment_method','actor','status','attachments','actions'].map(k=>`<th>${labels[k]}</th>`).join(''));
 html=html.replace(/@if\(\$boot\['selected_branch'\]==='all'\) selected @endif/g,'');
 html=html.replace(/@if\(\$boot\['allow_all'\]\)[\s\S]*?@endif/g,'').replace(/@if\(\$boot\['permissions'\]\['can_manage_categories'\]\)[\s\S]*?@endif/g,'');
 html=html.replace(/@if\(\$boot\['permissions'\]\['can_approve'\]\)([\s\S]*?)@endif/g,(_,body)=>canApprove?body:'');
 html=html.replace(/@if\(count\(\$boot\['branches'\]\)===1\) disabled @endif/g,'disabled');
 html=html.replace(/@if\(!\$boot\['allow_all'\]&&count\(\$boot\['branches'\]\)===1\) disabled @endif/g,'disabled');
 html=html.replace(/{{\s*__\('expenses\.([^']+)'\)\s*}}/g,(_,k)=>labels[k]);
 html=html.replace(/{{\s*\$boot\['today'\]\s*}}/g,'2026-10-19').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['from'\]\s*}}/g,'2026-10-01').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['to'\]\s*}}/g,'2026-10-19');
 assert(!/@if|@foreach|{{/.test(html));return html;
}
function expense(id,status='pending',payment='cash'){
 return {id,number:'EXP-'+String(id).padStart(6,'0'),occurred_on:'2026-10-19',branch:'f:100',branch_name:'فرع المحلة',category:'purchases',description:'خامات للفرع',amount:'50.00',payment_method:payment,actor_name:'مدير الفرع',status,revision:id===42?3:1,history:[]};
}
async function setup(browser,role,{hold=false,failFirst=false,denyFirst=false,viewport={width:1366,height:768},spa=false}={}){
 const page=await browser.newPage({viewport}),errors=[],reviews=[],recoveries=[];
 page.setDefaultTimeout(7000);
 const items=[expense(42),expense(43,'approved'),expense(44,'rejected'),expense(45,'voided'),expense(46,'pending','bank')];
 const canApprove=role!=='branch';let release;
 const gate=hold?new Promise(resolve=>{release=resolve;}):Promise.resolve();
 const data=()=>({success:true,items,categories:{purchases:'مشتريات'},active_categories:{purchases:'مشتريات'},category_items:[],actors:[],filters:{branch:'f:100',from:'2026-10-01',to:'2026-10-19'},pagination:{page:1,last_page:1,total:items.length},summary:{today:'50.00',month:'50.00',average:'2.63',count:1,top_category:'purchases',top_amount:'50.00',period:'50.00',pending:items.filter(item=>item.status==='pending').length}});
 const boot={actor_id:1,today:'2026-10-19',branches,initial:data(),permissions:{can_create:true,can_approve:canApprove,can_manage_categories:false},urls:{data:'/data',review:'/expenses/__EXPENSE__/review',show:'/expenses/__EXPENSE__',recover:'/recover',save:'/save',export:'/export',report:'/report'}};
 const html=`<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture-token"><style>${read('public/dashboard/css/branch-expenses.css')}</style></head><body>${template(canApprove)}<script id="branch-expenses-bootstrap" type="application/json">${JSON.stringify(boot)}</script><script id="branch-expenses-labels" type="application/json">${JSON.stringify(labels)}</script></body></html>`;
 page.on('pageerror',error=>{errors.push(error.message);console.error('Expense page error: '+error.message);});
 page.on('dialog',dialog=>{errors.push('Unexpected browser dialog');dialog.dismiss();});
 await page.route('http://localhost/**',async route=>{
  const request=route.request(),url=new URL(request.url());
  const reviewMatch=url.pathname.match(/^\/expenses\/(\d+)\/review$/);
  if(reviewMatch){
   assert.equal(request.method(),'POST');assert.equal(request.headers()['x-csrf-token'],'fixture-token');
   const payload=request.postDataJSON();reviews.push(payload);
   if(failFirst&&reviews.length===1)return route.abort('failed');
   if(denyFirst&&reviews.length===1)return route.fulfill({status:422,json:{message:'Invalid review',errors:{reason:['تعذر رفض المصروف الحالي.']}}});
   await gate;const item=items.find(item=>item.id===Number(reviewMatch[1]));assert(item);
   assert.equal(payload.expected_revision,item.revision);
   Object.assign(item,{status:{reject:'rejected',approve:'approved',void:'voided'}[payload.action],revision:item.revision+1,review_reason:payload.reason,reviewer_name:'المالك'});
   return route.fulfill({json:{success:true,expense:item}});
  }
  const showMatch=url.pathname.match(/^\/expenses\/(\d+)$/);
  if(showMatch)return route.fulfill({json:{success:true,expense:items.find(item=>item.id===Number(showMatch[1]))}});
  if(url.pathname==='/recover'){
   recoveries.push(Object.fromEntries(url.searchParams));return route.fulfill({json:{success:true,found:false}});
  }
  if(url.pathname==='/data')return route.fulfill({json:data()});
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
  errors.push('Unexpected request '+request.method()+' '+url.pathname);return route.abort();
 });
 await page.goto('http://localhost/');
 await page.evaluate(spa=>{
  window.expenseChanged=0;
  window.addEventListener('dashboard:expenses-changed',()=>window.expenseChanged++);
  window.prompt=()=>{throw new Error('prompt() is not supported in Electron');};
  window.confirm=()=>{throw new Error('Expense review must use its own confirmation');};
  if(spa)window.DashboardSPA={isCurrentPage:()=>true,onBeforeLeave:work=>{window.expenseMayLeave=work;},onIdle:work=>{window.expenseIdle=work;},onCleanup:work=>{window.expenseCleanup=work;}};
 },spa);
 await page.addScriptTag({content:process.env.EXPENSE_REVIEW_SCRIPT?fs.readFileSync(process.env.EXPENSE_REVIEW_SCRIPT,'utf8'):read('public/dashboard/js/branch-expenses.js')});
 return {page,errors,reviews,recoveries,release};
}
async function openReview(page,reason){
 await page.locator('[data-expense-reject="42"]').click();
 await page.locator('[data-expense-review-dialog]').waitFor({state:'visible'});
 if(reason!==undefined)await page.locator('[data-expense-review-reason]').fill(reason);
}
async function submitReview(page){await page.locator('[data-expense-review-submit]').click();}
async function visibleActions(page,authorized){
 assert.equal(await page.locator('[data-expense-approve]').count(),authorized?1:0);
 assert.equal(await page.locator('[data-expense-reject]').count(),authorized?2:0);
 for(const id of [43,44,45])assert.equal(await page.locator(`[data-expense-id="${id}"] .ex-status-actions button`).count(),0);
 if(!authorized)return;
 const row=page.locator('[data-expense-id="42"]');
 assert.deepEqual(await row.locator('.ex-status-actions button').allTextContents(),[labels.approve,labels.reject]);
 assert(await row.locator('[data-expense-reject="42"]').evaluate(button=>button.previousElementSibling.hasAttribute('data-expense-approve')),'Reject is next to approve and pay');
 assert.equal(await row.locator('.ex-reject.ex-status-reject').count(),1);
 assert.equal(await page.locator('[data-expense-id="46"] [data-expense-approve]').count(),0);
 assert.equal(await page.locator('[data-expense-id="46"] [data-expense-reject]').count(),1,'Legacy pending noncash expenses can still be rejected');
}
function checkPayload(payload){
 assert.equal(payload.action,'reject');assert.equal(payload.branch,'f:100');assert.equal(payload.expected_revision,3);assert.equal(payload.reason,'مصروف مكرر');
 assert.match(payload.idempotency_key,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
}
async function completed(ctx){
 const {page,reviews,errors}=ctx;
 await page.waitForSelector('[data-expense-id="42"] [data-status="rejected"]');
 await page.waitForFunction(()=>document.querySelector('[data-expense-period]').textContent.endsWith(': 1'));
 assert.equal(await page.locator('[data-expense-id="42"] .ex-status-actions button').count(),0);
 assert.equal(await page.evaluate(()=>window.expenseChanged),1);
 assert.equal(await page.locator('[data-expense-metric="today"]').textContent(),'50.00','Rejected expenses do not change approved totals');
 reviews.forEach(checkPayload);assert.deepEqual(errors,[]);
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const branch=await setup(browser,'branch');await visibleActions(branch.page,false);assert.deepEqual(branch.errors,[]);await branch.page.close();
  const admin=await setup(browser,'admin',{hold:true}),button=admin.page.locator('[data-expense-reject="42"]');
  await visibleActions(admin.page,true);
  await admin.page.locator('[data-expense-cancel]').click();
  fs.mkdirSync('test-results',{recursive:true});await admin.page.screenshot({path:'test-results/expense-reject.png'});
  for(const cancel of ['[data-expense-review-cancel]','[data-expense-review-close]','Escape']){
   await openReview(admin.page,'سبب ملغى');
   if(cancel==='Escape')await admin.page.keyboard.press('Escape');else await admin.page.locator(cancel).click();
   assert.equal(admin.reviews.length,0);assert(await button.isEnabled());assert(await admin.page.locator('[data-expense-review-dialog]').isHidden());
  }
  await openReview(admin.page,'   ');await submitReview(admin.page);
  assert.equal(admin.reviews.length,0);assert(await admin.page.locator('[data-expense-review-error]').isVisible());
  assert(await admin.page.locator('[data-expense-review-reason]').evaluate(node=>node===document.activeElement));
  assert.equal(await admin.page.locator('[data-expense-review-reason]').getAttribute('maxlength'),'500');
  await admin.page.locator('[data-expense-review-reason]').fill('  مصروف مكرر  ');
  await admin.page.screenshot({path:'test-results/expense-review-dialog.png'});
  const sent=admin.page.waitForRequest(request=>request.url().endsWith('/expenses/42/review'));
  await submitReview(admin.page);await sent;
  assert(await button.isDisabled());assert(await admin.page.locator('[data-expense-approve="42"]').isDisabled());
  await button.evaluate(button=>{button.dispatchEvent(new MouseEvent('click',{bubbles:true}));button.dispatchEvent(new MouseEvent('click',{bubbles:true}));});
  assert.equal(admin.reviews.length,1,'Busy guard prevents duplicate submissions');
  admin.release();await completed(admin);assert.equal(admin.reviews.length,1);await admin.page.close();
  const owner=await setup(browser,'owner',{failFirst:true});await visibleActions(owner.page,true);
  await openReview(owner.page,'مصروف مكرر');await submitReview(owner.page);
  await owner.page.locator('[data-expense-retry]').waitFor({state:'visible'});
  assert(await owner.page.locator('[data-expense-reject="42"]').isDisabled(),'Unconfirmed rejection freezes other commands');
  assert.equal(owner.reviews.length,1);await owner.page.locator('[data-expense-retry]').click();await completed(owner);
  assert.equal(owner.reviews.length,2);assert.deepEqual(owner.reviews[1],owner.reviews[0],'Retry keeps the original UUID, revision and reason');
  assert.equal(owner.recoveries.length,1);assert.equal(owner.recoveries[0].idempotency_key,owner.reviews[0].idempotency_key);
  assert.equal(owner.recoveries[0].branch,'f:100');await owner.page.close();
  const denied=await setup(browser,'owner',{denyFirst:true});await openReview(denied.page,'مصروف مكرر');await submitReview(denied.page);
  await denied.page.locator('[data-expense-row-message]').waitFor({state:'visible'});
  assert.equal(await denied.page.locator('[data-expense-row-message]').textContent(),'تعذر رفض المصروف الحالي.');
  assert.equal(await denied.page.evaluate(()=>sessionStorage.length),0);assert(await denied.page.locator('[data-expense-reject="42"]').isEnabled());
  await openReview(denied.page,'مصروف مكرر');await submitReview(denied.page);await completed(denied);await denied.page.close();
  const detail=await setup(browser,'owner');
  await detail.page.locator('[data-expense-id="42"] .ex-row-actions button').click();
  const detailDialog=detail.page.locator('[data-expense-dialog]');await detailDialog.waitFor({state:'visible'});
  await detailDialog.getByRole('button',{name:labels.reject,exact:true}).click();
  await detail.page.locator('[data-expense-review-cancel]').click();assert(await detailDialog.isVisible());assert.equal(detail.reviews.length,0);
  await detailDialog.getByRole('button',{name:labels.reject,exact:true}).click();await detail.page.locator('[data-expense-review-reason]').fill('مصروف مكرر');await submitReview(detail.page);
  await completed(detail);assert(await detailDialog.isHidden());await detail.page.close();
  const approval=await setup(browser,'admin');await approval.page.locator('[data-expense-approve="42"]').click();
  await approval.page.waitForSelector('[data-expense-id="42"] [data-status="approved"]');assert.equal(approval.reviews.length,1);assert.equal(approval.reviews[0].action,'approve');assert.equal(approval.reviews[0].reason,'');assert.deepEqual(approval.errors,[]);await approval.page.close();
  const reversal=await setup(browser,'owner');await reversal.page.locator('[data-expense-id="43"] .ex-row-actions button').click();
  await reversal.page.locator('[data-expense-dialog]').getByRole('button',{name:labels.void,exact:true}).click();
  assert((await reversal.page.locator('[data-expense-review-dialog]').textContent()).includes(labels.confirm_void));
  await reversal.page.locator('[data-expense-review-reason]').fill('عملية مكررة');await submitReview(reversal.page);
  await reversal.page.waitForSelector('[data-expense-id="43"] [data-status="voided"]');assert.equal(reversal.reviews[0].action,'void');assert.deepEqual(reversal.errors,[]);await reversal.page.close();
  const mobile=await setup(browser,'owner',{viewport:{width:390,height:844},spa:true});await openReview(mobile.page,'مصروف مكرر');
  assert.equal(await mobile.page.evaluate(()=>expenseIdle()),false);
  const bounds=await mobile.page.locator('[data-expense-review-dialog]').boundingBox();assert(bounds.x>=0&&bounds.x+bounds.width<=390);
  assert(await mobile.page.locator('[data-expense-review-submit]').isVisible());
  await mobile.page.evaluate(()=>expenseCleanup());assert.equal(await mobile.page.locator('[data-expense-review-dialog]').count(),0);assert.deepEqual(mobile.errors,[]);await mobile.page.close();
  console.log('Expense review: desktop-safe dialogs, roles, blank reason, cancel/Escape, duplicate prevention, rejection/reversal, validation errors, retry, mobile and SPA cleanup passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
