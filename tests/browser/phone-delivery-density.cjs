// Exercises production queue rendering/CSS with synthetic tickets and no external requests.
const {chromium} = require('playwright');
const fs = require('fs');
const assert = require('node:assert/strict');
const path = require('path');
const read = p => fs.readFileSync(path.join(__dirname, '../..', p), 'utf8');
const source = read('public/dashboard/js/phone-orders.js');
function fn(name) {
  const start = source.indexOf('    function ' + name + '(');
  assert(start >= 0, name);
  const rest = source.slice(start), end = rest.slice(1).search(/\n    (?:async )?function /);
  return end < 0 ? rest : rest.slice(0, end + 1);
}
const functions = ['text','element','scaled','decimal','money','dateLabel','button','ticketActions','detailContent','dispatchControl','card','updateBatchTotal','renderBoard'].map(fn).join('\n');
function translations(locale) {
  return Object.fromEntries([...read(`resources/lang/${locale}/phone_orders.php`).matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
}
function ticket(id, stage) {
  return {id, number:'20261007-'+String(id).padStart(4,'0'), channel:'phone', revision:1, status:stage==='courier'?'out_for_delivery':stage==='finished'?'finished':'new',payment_status:stage==='finished'?'paid':'unpaid',
    customer_name:'أحمد محمود عبد الرحمن',customer_phone:'01012345678',address:'شارع الجمهورية بجوار المدرسة، الدور الثالث',area:'المحلة',created_at:'2026-10-07T10:30:00Z',
    branch:{value:'f:100',name:'فرع المحلة الكبرى'},courier_company:stage==='courier'?{id:1,name:'شركة النور'}:null,
    total:'1250.50',subtotal:'1200.00',discount:'0.00',delivery_fee:'50.50',tax:'0.00',service:'0.00',bill_print_url:'/receipt/'+id,
    items:[{name:'صنف تجريبي',quantity:'2',quantity_mode:'piece',total:'1200.00'}]};
}
const css=read('public/dashboard/css/phone-orders.css');
const shell=`<style>*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f5f7fa}.app-head{height:58px;background:#0b1928;color:#fff;padding:18px}.content-wrapper{margin-inline-end:250px}aside{position:fixed;inset-inline-end:0;top:58px;bottom:0;width:250px;background:#0b1928;color:#fff;padding:24px}.app-foot{height:51px;background:#0b1928;color:white;padding:15px}button,select{font:inherit}h1,h2,h3,p,dl,dd{margin:0}@media(max-width:999px){aside{display:none}.content-wrapper{margin-inline-end:0}}</style>`;
async function board(page,width,height,locale='ar',count=20) {
  const labels=translations(locale),columns=Object.fromEntries(['preparing','courier','finished'].map((stage,j)=>[stage,{items:Array.from({length:count},(_,i)=>ticket(j*100+i+1,stage)),pagination:{page:1,last_page:count>10?2:1,total:count>10?40:count}}]));
  await page.setViewportSize({width,height});
  await page.setContent(`<html lang="${locale}" dir="${locale==='ar'?'rtl':'ltr'}"><head>${shell}<style>${css}</style></head><body><div class="app-head">فسخانستا</div><aside>لوحة التحكم</aside><main class="content-wrapper phone-orders-wrapper"><section id="phone-orders" class="ph-pos ph-view-orders" dir="${locale==='ar'?'rtl':'ltr'}"><header class="ph-header"><div class="ph-heading"><h1>${labels.title||'طلبات الهاتف والدليفري'}</h1></div><label class="ph-branch"><select><option>${labels.all_branches}</option></select></label><nav class="ph-view-tabs"><button>طلب هاتف جديد</button><button>طلبات الدليفري</button></nav></header><div class="ph-flow">Steps</div><section class="ph-orders ph-panel"><header class="ph-panel-heading"><h2>Orders</h2><button>تحديث</button></header><div class="ph-batch-bar"><strong data-phone-batch-total></strong><button class="ph-batch-finish">إنهاء الطلبات المحددة</button></div><div class="ph-delivery-board" data-phone-order-list></div></section><div class="ph-modal-backdrop" hidden data-phone-modal><section class="ph-modal ph-panel" role="dialog"><header class="ph-panel-heading"><h2>تفاصيل الطلب</h2><button id="close">إغلاق</button></header><div class="ph-modal-content" data-phone-modal-content></div></section></div></section></main><div class="app-foot">فسخانستا</div></body></html>`);
  await page.evaluate(({labels,columns,functions})=>{
    window.calls=[]; window.alerts=[];
    const setup=`var labels=${JSON.stringify(labels)},boardData=${JSON.stringify({columns})};
var root=document.getElementById('phone-orders'),modal=root.querySelector('[data-phone-modal]'),selectedOrders=new Map(),boardCompanies=[{id:1,branch:'f:100',name:'شركة النور'},{id:2,branch:'f:101',name:'فرع آخر'}],boardPages={preparing:1,courier:1,finished:1},urls={'dispatch-company':'/dispatch'};
function canWrite(){return true;}function locked(){return false;}function lock(){root.querySelectorAll('[data-unavailable]').forEach(n=>n.disabled=true);}
function action(t,a){calls.push({id:t.id,action:a});}function print(url){calls.push({print:url});}function settlement(id){calls.push({settlement:id});}function editTicket(id){calls.push({edit:id});}function execute(op){calls.push(op);}function uuid(){return 'test-command';}function notify(message){alerts.push(message);}function loadOrders(){calls.push({page:true});}
function detailsModal(id){var t=Object.values(boardData.columns).flatMap(c=>c.items).find(t=>t.id===id);modal.hidden=false;detailContent(t,root.querySelector('[data-phone-modal-content]'));}
${functions}
renderBoard();document.getElementById('close').onclick=function(){modal.hidden=true;};window.testBoard={selectedOrders,boardData,renderBoard,card};`;
    (0,eval)(setup);
  },{labels,columns,functions});
  return labels;
}
(async()=>{
 fs.mkdirSync('test-results/phone-delivery',{recursive:true});
 const browser=await chromium.launch({headless:true}), page=await browser.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try {
  for(const [width,height,locale] of [[1920,1030,'ar'],[1366,768,'ar'],[1366,768,'en']]) {
   const labels=await board(page,width,height,locale);
   const metrics=await page.evaluate(()=>[...document.querySelectorAll('.ph-board-cards')].map(n=>{const b=n.getBoundingClientRect(),cards=[...n.querySelectorAll('.ph-ticket')].map(c=>c.getBoundingClientRect());return {top:b.top,bottom:b.bottom,visible:cards.filter(c=>c.top>=b.top&&c.bottom<=b.bottom+1).length,cardHeight:cards[0].height,overflow:n.scrollHeight>n.clientHeight,wide:n.scrollWidth>n.clientWidth+1};}));
   await page.screenshot({path:`test-results/phone-delivery/delivery-${width}-${locale}.png`,fullPage:true});
   console.log({width,height,locale,metrics});
   for(const m of metrics){assert(m.visible>=10,JSON.stringify(m));assert(m.overflow);assert(!m.wide);assert(m.top<210);assert(m.bottom<=height-40);}
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   await page.locator('[data-phone-column="preparing"] .ph-ticket').first().getByRole('button',{name:labels.details_actions+' #20261007-0001',exact:true}).click();
   const dialog=page.getByRole('dialog');
   await dialog.getByRole('button',{name:labels.edit,exact:true}).click();
   assert.equal(await page.evaluate(()=>calls.at(-1).edit),1);
   await dialog.locator('[data-phone-board-company]').selectOption('1');
   assert.equal(await page.evaluate(()=>calls.at(-1).payload.branch),'f:100');
   assert.equal(await dialog.locator('[data-phone-board-company] option').count(),2);
   await page.locator('#close').click();
   await page.locator('[data-phone-batch-select="101"]').check();
   await page.locator('[data-phone-batch-select="102"]').check();
   assert.equal(await page.evaluate(()=>testBoard.selectedOrders.size),2);
   await page.locator('[data-phone-column="preparing"] .ph-ticket').first().getByRole('button',{name:labels.receipt+' #20261007-0001',exact:true}).click();
   assert.equal(await page.evaluate(()=>calls.at(-1).action),'request_bill');
   await page.locator('[data-phone-column="preparing"] .ph-board-cards').evaluate(n=>n.scrollTop=n.scrollHeight);
   assert(await page.locator('[data-phone-column="preparing"] .ph-board-cards').evaluate(n=>n.scrollTop>0));
  }
  await board(page,1366,768,'ar',1);
  assert(await page.locator('.ph-ticket').first().evaluate(n=>n.getBoundingClientRect().height<65));
  await board(page,390,844,'ar');
  await page.screenshot({path:'test-results/phone-delivery/delivery-mobile.png',fullPage:true});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  // The branch home renderer uses only its two panels, with data from the scoped API.
  const labels=Object.fromEntries([...read('resources/lang/ar/home_overview.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
  const boot={locale:'ar',labels,url:'/overview',initial:{success:true,branch_home:true,filters:{branch:''},branches:[{value:'f:100',name:'فرع المحلة'}],modules:{inventory:true,expenses:true},inventory:{items:[{name:'رنجة',quantity:'25.5',quantity_units:25500000,unit:'kg',tracked_branches:1},{name:'صنف بدون رصيد افتتاحي',quantity:'0',quantity_units:0,unit:'piece',tracked_branches:0}]},today_expenses:{date:'2026-10-07',total_cents:12550,items:[{name:'مشتريات',amount_cents:12550}]}}};
  let template=read('resources/views/admin/home_branch.blade.php').split('@section(\'content\')')[1].split('@endsection')[0];
  template=template.replace(/@if\(count\([\s\S]*?@endif\s*<span data-bh-date>/,'<span>فرع المحلة</span><span data-bh-date>').replace(/{{\s*__\('home_overview\.([^']+)'\)\s*}}/g,(_,k)=>labels[k]).replace(/{{\s*route\('([^']+)'\)\s*}}/g,(_,k)=>'http://localhost/'+k).replace(/{{[^}]*}}/g,'');
  await page.setViewportSize({width:1366,height:768});
  await page.setContent(`<html lang="ar" dir="rtl"><head>${shell}<style>${read('public/dashboard/css/home-overview.css')}</style></head><body><div class="app-head">فسخانستا</div><aside>لوحة التحكم</aside>${template}<script id="branch-home-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`);
  await page.addScriptTag({content:read('public/dashboard/js/branch-home.js')});
  assert.equal(await page.locator('[data-bh-panel]').count(),2);
  assert.match(await page.locator('[data-bh-total]').innerText(),/125.50/);
  assert.match(await page.locator('[data-bh-stock]').innerText(),/25.5/);
  await page.screenshot({path:'test-results/phone-delivery/branch-home.png',fullPage:true});
  await page.locator('[data-bh-search]').fill('رنجة');assert.equal(await page.locator('[data-bh-stock] tr').count(),1);
  assert.deepEqual(errors,[]);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
