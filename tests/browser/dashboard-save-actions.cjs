const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const expenseLabels=Object.fromEntries([...read('resources/lang/ar/expenses.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
const branches=[{value:'f:100',name:'فرع المحلة'}];
function productPage(edit){
 let form=read('resources/views/admin/products/form.blade.php').split("@push('custom-js')")[0];
 form=form.replace(/@foreach\(\\App\\Models\\Category[\s\S]*?@endforeach/,'<option value="1" selected>معلبات وفاكيوم</option>');
 form=form.replace(/@if\(request\(\)->route\(\)->getName\(\) == 'products.edit'\)([\s\S]*?)@endif/,(_,body)=>edit?body:'');
 form=form.replace(/(<select name="product_features\[\]"[^>]*>)[\s\S]*?(<\/select>)/,'$1<option value="small" selected>صغير</option><option value="large">كبير</option>$2');
 form=form.replace(/@if\(\$product->has_clean == ([01])\) selected @endif/g,(_,v)=>v==='0'?'selected':'');
 form=form.replace(/@if\(\$product->status == '(show|hide)'\) selected @endif/g,(_,v)=>v==='show'?'selected':'');
 form=form.replace(/{{\s*Auth::guard\('admin'\)->user\(\)->id\s*}}/g,'1').replace(/{{\s*\$product->id\s*}}/g,'42').replace(/{{\s*old\('name_ar', \$product->name_ar\)\s*}}/g,edit?'علبة رنجة مخلية':'');
 form=form.replace(/@error\('name_ar'\) is-invalid @enderror/g,'');
 let html=read(`resources/views/admin/products/${edit?'edit':'create'}.blade.php`).split("@section('content')")[1].split('@endsection')[0];
 html=html.replace("@include('admin.products.form')",form).replace("@include('admin.layouts.alerts')",'');
 html=html.replace(/{{\s*route\('products.update',\s*\$product->id\)\s*}}/g,'/admin/products/42').replace(/{{\s*route\('products.store'\)\s*}}/g,'/admin/products').replace(/{{\s*url\('admin\/products'\)\s*}}/g,'/admin/products');
 html=html.replace(/@csrf/g,'<input type="hidden" name="_token" value="fixture-token">').replace(/@method\('PUT'\)/g,'<input type="hidden" name="_method" value="PUT">').replace(/@can\('[^']+'\)|@endcan/g,'');
 html=html.replace(/@lang\('main\.([^']+)'\)/g,(_,k)=>k==='save'?'حفظ':k);
 assert(!/@if|@foreach|{{|@include|@csrf|@method/.test(html));return html;
}
function expensePage(approve){
 let html=read('resources/views/admin/expenses/index.blade.php').split("@section('content')")[1].split('@endsection')[0];
 html=html.replace(/@foreach\(\['today'=>[\s\S]*?@endforeach/,['today','month','average','top_category','count'].map(k=>`<article><strong data-expense-metric="${k}"></strong><small ${k==='top_category'?'data-expense-top-amount':''}></small></article>`).join(''));
 html=html.replace(/@foreach\(\$boot\['branches'\] as \$branch\)[\s\S]*?@endforeach/g,'<option value="f:100">فرع المحلة</option>');
 html=html.replace(/@foreach\(\$boot\['initial'\]\['(?:active_)?categories'\] as \$category=>\$categoryName\)[\s\S]*?@endforeach/g,'<option value="purchases">مشتريات</option>');
 html=html.replace(/@foreach\(\['pending',[\s\S]*?@endforeach/g,['pending','approved','rejected','voided'].map(s=>`<option value="${s}">${s}</option>`).join(''));
 html=html.replace(/@foreach\(\['number',[\s\S]*?@endforeach/g,'<th>المصروف</th>');
 html=html.replace(/@if\(\$boot\['allow_all'\]\)[\s\S]*?@endif/g,'').replace(/@if\(\$boot\['permissions'\]\['can_manage_categories'\]\)[\s\S]*?@endif/g,'');
 html=html.replace(/@if\(\$boot\['permissions'\]\['can_approve'\]\)([\s\S]*?)@endif/g,(_,body)=>approve?body:'').replace(/@if\(count\(\$boot\['branches'\]\)===1\) disabled @endif/g,'disabled');
 html=html.replace(/{{\s*__\('expenses\.([^']+)'\)\s*}}/g,(_,k)=>expenseLabels[k]).replace(/{{\s*\$boot\['today'\]\s*}}/g,'2026-10-07').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['from'\]\s*}}/g,'2026-10-01').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['to'\]\s*}}/g,'2026-10-07');
 assert(!/@if|@foreach|{{/.test(html));return html;
}
function shell(content,scripts=''){
 return `<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture-token"><script src="/dashboard/js/dashboard-spa.js" defer></script></head><body data-dashboard-actor="1"><header class="main-header"></header><div data-dashboard-page>${content}</div><div data-dashboard-page-scripts>${scripts}</div></body></html>`;
}
const list=()=>shell('<h1>الأصناف</h1><a href="/admin/products/42/edit" id="edit-product">تعديل</a><a href="/admin/products/create" id="create-product">إضافة</a><a href="/admin/branch-expenses" id="open-expenses">مصروفات</a>');
const fields=req=>Object.fromEntries([...req.postData().matchAll(/name="([^"\r\n]+)"\r\n\r\n([^\r\n]*)/g)].map(m=>[m[1],m[2]]));
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage(),posted=[],errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('http://localhost/**',route=>{
   const req=route.request(),p=new URL(req.url()).pathname;
   if(p==='/dashboard/js/dashboard-spa.js')return route.fulfill({contentType:'application/javascript',body:read('public/dashboard/js/dashboard-spa.js')});
   if(req.method()==='POST'){posted.push({path:p,values:fields(req),csrf:req.headers()['x-csrf-token']});return route.fulfill({json:{success:true,redirect:'/admin/products'}});}
   if(p==='/admin/products/42/edit')return route.fulfill({contentType:'text/html',body:shell(productPage(true))});
   if(p==='/admin/products/create')return route.fulfill({contentType:'text/html',body:shell(productPage(false))});
   return route.fulfill({contentType:'text/html',body:list()});
  });
  await page.goto('http://localhost/admin/products');await page.waitForFunction(()=>window.DashboardSPA.ready());await page.evaluate(()=>window.fixtureShell=42);
  for(const edit of [true,false,true]){
   await page.locator(edit?'#edit-product':'#create-product').click();await page.waitForFunction(()=>!!document.querySelector('[name="name_ar"]')&&!document.querySelector('[data-dashboard-page]').inert);
   const owner=await page.locator('button[type="submit"]').evaluate(button=>({inForm:!!button.closest('form'),hasOwner:!!button.form}));
   assert.deepEqual(owner,{inForm:true,hasOwner:true},'Save must remain inside its form after SPA imports the product page');
   await page.locator('[name="name_ar"]').fill('علبة رنجة مخلية كبيرة');await page.locator('[name="status"]').selectOption('hide');await page.locator('[name="has_clean"]').selectOption('1');await page.locator('[name="product_features[]"]').selectOption('large');
   await page.locator('button[type="submit"]').click();await page.waitForSelector('#edit-product');
   const sent=posted.at(-1);assert.equal(sent.path,edit?'/admin/products/42':'/admin/products');assert.equal(sent.csrf,'fixture-token');assert.equal(sent.values._token,'fixture-token');assert.equal(sent.values.added_by,'1');assert.equal(sent.values.category_id,'1');assert.equal(sent.values.name_ar,'علبة رنجة مخلية كبيرة');assert.equal(sent.values.status,'hide');assert.equal(sent.values.has_clean,'1');assert.equal(sent.values['product_features[]'],'large');
   if(edit){assert.equal(sent.values._method,'PUT');assert.equal(sent.values.product_id,'42');}else{assert(!('_method' in sent.values));assert(!('product_id' in sent.values));}
   assert.equal(await page.evaluate(()=>window.fixtureShell),42);
  }
  assert.equal(posted.length,3);assert.deepEqual(errors,[]);await page.close();console.log('Product edit/create save after SPA navigation passed.');
  for(const [role,nativeUuid] of [['admin',true],['owner',true],['branch',true],['admin',false],['owner',false]]){
   const approve=role!=='branch',p=await browser.newPage(),reviews=[],saves=[],pageErrors=[];let status='pending',revision=1;
   const item=()=>({id:42,number:'EXP-000042',occurred_on:'2026-10-07',branch:'f:100',branch_name:'فرع المحلة',category:'purchases',description:'خامات',amount:'50.00',payment_method:'cash',actor_name:'مدير الفرع',status,revision,history:[]});
   const data=()=>({success:true,items:[item()],categories:{purchases:'مشتريات'},active_categories:{purchases:'مشتريات'},category_items:[],actors:[],filters:{branch:'f:100',from:'2026-10-01',to:'2026-10-07'},pagination:{page:1,last_page:1,total:1},summary:{today:'0.00',month:'0.00',average:'0.00',count:0,top_category:null,top_amount:'0.00',period:'0.00',pending:status==='pending'?1:0}});
   const boot=()=>({actor_id:1,today:'2026-10-07',branches,initial:data(),permissions:{can_create:true,can_approve:approve,can_manage_categories:false},urls:{data:'/admin/branch-expenses/data',review:'/admin/branch-expenses/__EXPENSE__/review',show:'/admin/branch-expenses/__EXPENSE__',recover:'/admin/branch-expenses/recover',save:'/admin/branch-expenses/save',export:'/admin/branch-expenses/export'}});
   if(!nativeUuid)await p.addInitScript(()=>Object.defineProperty(Crypto.prototype,'randomUUID',{value:undefined,configurable:true}));
   p.on('pageerror',e=>pageErrors.push(e.message));p.on('dialog',d=>{pageErrors.push('Unexpected approval confirmation');d.dismiss();});
   await p.route('http://localhost/**',route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname.startsWith('/dashboard/js/'))return route.fulfill({contentType:'application/javascript',body:read('public'+url.pathname)});
    if(url.pathname==='/admin/branch-expenses/42/review'){
     reviews.push(req.postDataJSON());assert.equal(req.method(),'POST');assert.equal(req.headers()['x-csrf-token'],'fixture-token');status='approved';revision++;return route.fulfill({json:{success:true,expense:item()}});
    }
    if(url.pathname==='/admin/branch-expenses/save'){
     saves.push(fields(req));return route.fulfill({json:{success:true,expense:item()}});
    }
    if(url.pathname==='/admin/branch-expenses/data')return route.fulfill({json:data()});
    if(url.pathname==='/admin/branch-expenses')return route.fulfill({contentType:'text/html',body:shell(expensePage(approve),`<script id="branch-expenses-bootstrap" type="application/json">${JSON.stringify(boot())}</script><script id="branch-expenses-labels" type="application/json">${JSON.stringify(expenseLabels)}</script><script src="/dashboard/js/branch-expenses.js"></script>`)});
    return route.fulfill({contentType:'text/html',body:list()});
   });
   await p.goto('http://localhost/admin/products');await p.waitForFunction(()=>window.DashboardSPA.ready());await p.locator('#open-expenses').click();await p.waitForSelector('[data-status="pending"]');
   assert.equal(await p.locator('[data-expense-approve]').count(),approve?1:0);
   if(approve){await p.locator('[data-expense-approve]').click();try{await p.waitForSelector('[data-status="approved"]',{timeout:3000});}catch(error){console.error({role,reviews,pageErrors,notice:await p.locator('[data-expense-message]').textContent()});throw error;}assert.equal(reviews.length,1);assert.equal(reviews[0].action,'approve');assert.equal(reviews[0].branch,'f:100');assert.equal(reviews[0].expected_revision,1);assert.match(reviews[0].idempotency_key,/^[0-9a-f-]{36}$/);assert.equal(await p.locator('[data-expense-approve]').count(),0);assert(await p.locator('[data-expense-message]').isHidden());}
   if(!nativeUuid){
    const form=p.locator('[data-expense-form]');
    async function fillExpense(){await form.locator('[name="category"]').selectOption('purchases');await form.locator('[name="description"]').fill('خامات جديدة');await form.locator('[name="amount"]').fill('25.00');}
    await fillExpense();await form.locator('[data-expense-save-approve]').click();await p.waitForFunction(()=>document.querySelector('[data-expense-form] [name="description"]').value==='');
    assert.equal(saves.length,1);assert.equal(saves[0].approve,'1');assert.equal(saves[0].payment_method,'cash');assert.match(saves[0].idempotency_key,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);assert.notEqual(saves[0].idempotency_key,reviews[0].idempotency_key);
    await fillExpense();await p.evaluate(()=>Object.defineProperty(Crypto.prototype,'getRandomValues',{value:undefined,configurable:true}));await form.locator('[data-expense-save-approve]').click();
    assert.equal(saves.length,1);assert.equal(await p.locator('[data-expense-message] span').textContent(),expenseLabels.error);assert(await p.locator('[data-expense-save-approve]').isEnabled());
   }
   assert.deepEqual(pageErrors,[]);await p.close();console.log(`${role}, native UUID ${nativeUuid}: expense approval after SPA navigation passed.`);
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
