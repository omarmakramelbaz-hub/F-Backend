const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const labels=Object.fromEntries([...read('resources/lang/ar/expenses.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
const branches=[{value:'f:100',name:'فرع المحلة'},{value:'f:101',name:'فرع المنصورة'}];
const categories={purchase_baladi_bread:'خبز بلدي',purchase_tomato:'طماطم',other:'أخرى'};
function data(items=[]){return {success:true,items,categories,active_categories:categories,stock_categories:{purchase_baladi_bread:'purchase_baladi_bread',purchase_tomato:'purchase_tomato'},stock_units:{purchase_baladi_bread:'piece',purchase_tomato:'kg'},category_scope:'f:100',category_items:[],actors:[],filters:{branch:'f:100',from:'2026-11-01',to:'2026-11-06'},pagination:{page:1,last_page:1,total:items.length},summary:{today:'0.00',month:'0.00',average:'0.00',count:items.length,top_category:null,top_amount:'0.00',period:'0.00',pending:'0.00',cash_balance:'0.00'}};}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.DESKTOP_TEST_CHROMIUM||undefined});
 try{for(const width of [1366,390]){
  const page=await browser.newPage({viewport:{width,height:width===390?844:768}}),posted=[],errors=[];let items=[];
  page.on('pageerror',e=>errors.push(e.message));
  const boot={actor_id:1,allow_all:false,selected_branch:'f:100',today:'2026-11-06',branches,initial:data(),permissions:{can_create:true,can_approve:false,can_manage_categories:false},urls:{data:'/data',save:'/save',show:'/expenses/__EXPENSE__',recover:'/recover',export:'/export',report:'/report'}};
  const template=require('./expense-blade-fixture.cjs')(boot);
  const html=`<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"><style>${read('public/dashboard/css/branch-expenses.css')}</style></head><body>${template}<script id="branch-expenses-bootstrap" type="application/json">${JSON.stringify(boot)}</script><script id="branch-expenses-labels" type="application/json">${JSON.stringify(labels)}</script></body></html>`;
  await page.route('http://localhost/**',route=>{
   const request=route.request(),pathname=new URL(request.url()).pathname;
   if(pathname==='/data')return route.fulfill({json:data(items)});
   if(pathname==='/expenses/1')return route.fulfill({json:{success:true,expense:items[0]}});
   if(pathname==='/save'){
    const values=Object.fromEntries([...request.postData().matchAll(/name="([^"\r\n]+)"\r\n\r\n([^\r\n]*)/g)].map(m=>[m[1],m[2]]));posted.push(values);
    const item={id:1,number:'EXP-000001',occurred_on:'2026-11-06',branch:'f:100',branch_name:'فرع المحلة',category:values.category,category_name:'خبز بلدي',description:'خبز للفرع',amount:values.amount,payment_method:'cash',actor_name:'مدير الفرع',status:'pending',revision:1,stock_quantity_kg:values.quantity_kg,stock_unit:values.category==='purchase_baladi_bread'?'piece':'kg',stock_item_name:'خبز بلدي',history:[]};
    items=[item];return route.fulfill({json:{success:true,expense:item}});
   }
   return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://localhost/');await page.addScriptTag({content:read('public/dashboard/js/branch-expenses.js')});
  await page.locator('[data-expense-new]').click();
  const form=page.locator('[data-expense-form]'),quantity=form.locator('[name="quantity_kg"]');
  await form.locator('[name="category"]').selectOption('purchase_baladi_bread');
  assert.equal(await page.locator('[data-expense-quantity-label]').textContent(),labels.quantity_piece);
  assert.equal(await quantity.getAttribute('inputmode'),'numeric');assert.equal(await quantity.getAttribute('placeholder'),'0');
  await form.locator('[name="amount"]').fill('75.00');
  await quantity.fill('2.5');await form.locator('[data-expense-save]').click();
  assert.equal(posted.length,0);assert((await page.locator('[data-expense-message]').textContent()).includes(labels.quantity_piece_invalid));
  await form.locator('[name="category"]').selectOption('purchase_tomato');
  assert.equal(await page.locator('[data-expense-quantity-label]').textContent(),labels.quantity_kg);assert.equal(await quantity.getAttribute('inputmode'),'decimal');
  await form.locator('[name="category"]').selectOption('other');assert(await quantity.isDisabled());
  await form.locator('[name="category"]').selectOption('purchase_baladi_bread');
  await quantity.fill('١٠٠');
  await page.evaluate(()=>{window.stockEvents=0;window.addEventListener('dashboard:stock-changed',()=>window.stockEvents++);});
  await form.locator('[data-expense-save]').click();
  await page.waitForFunction(message=>document.querySelector('[data-expense-message] span').textContent===message,labels.saved);
  assert.equal(posted.length,1);assert.equal(posted[0].quantity_kg,'100');assert.equal(posted[0].category,'purchase_baladi_bread');assert.equal(await page.evaluate(()=>stockEvents),1);
  await page.waitForSelector('.ex-stock-quantity');assert((await page.locator('.ex-stock-quantity').textContent()).includes('100 رغيف'));
  await page.locator('[data-expense-id="1"] button[aria-label="'+labels.details+'"]').click();
  await page.waitForSelector('.ex-stock-detail');assert((await page.locator('.ex-stock-detail').textContent()).includes('100 رغيف'));
  assert.deepEqual(errors,[]);console.log(`BALADI_BREAD_BROWSER_OK width=${width}`);await page.close();
 }}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
