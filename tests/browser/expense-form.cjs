const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const labels=Object.fromEntries([...read('resources/lang/ar/expenses.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));
function template(manage,approve){
 let html=read('resources/views/admin/expenses/index.blade.php').split("@section('content')")[1].split('@endsection')[0];
 html=html.replace(/@foreach\(\['today'=>[\s\S]*?@endforeach/,['today','month','average','top_category','count'].map(key=>`<article class="ex-metric"><span class="ex-metric-icon"><i></i></span><div><h2>${labels[key]}</h2><strong data-expense-metric="${key}">—</strong><small ${key==='top_category'?'data-expense-top-amount':''}>${labels.currency}</small></div></article>`).join(''));
 html=html.replace(/@foreach\(\$boot\['branches'\] as \$branch\)[\s\S]*?@endforeach/g,'<option value="f:100">فرع المحلة</option>');
 html=html.replace(/@foreach\(\$boot\['initial'\]\['(?:active_)?categories'\] as \$category=>\$categoryName\)[\s\S]*?@endforeach/g,'<option value="purchases">مشتريات</option>');
 html=html.replace(/@foreach\(\['pending',[\s\S]*?@endforeach/g,['pending','approved','rejected','voided'].map(s=>`<option value="${s}">${labels['status_'+s]}</option>`).join(''));
 html=html.replace(/@foreach\(\['number',[\s\S]*?@endforeach/g,['number','date','branch','category','description','amount','payment_method','actor','status','attachments','actions'].map(k=>`<th>${labels[k]}</th>`).join(''));
 html=html.replace(/@if\(\$boot\['allow_all'\]\)[\s\S]*?@endif/g,'');
 html=html.replace(/@if\(\$boot\['permissions'\]\['can_manage_categories'\]\)([\s\S]*?)@endif/g,(_,body)=>manage?body:'');
 html=html.replace(/@if\(\$boot\['permissions'\]\['can_approve'\]\)([\s\S]*?)@endif/g,(_,body)=>approve?body:'');
 html=html.replace(/@if\(count\(\$boot\['branches'\]\)===1\) disabled @endif/g,'disabled');
 html=html.replace(/{{\s*__\('expenses\.([^']+)'\)\s*}}/g,(_,k)=>labels[k]);
 html=html.replace(/{{\s*\$boot\['today'\]\s*}}/g,'2026-10-07').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['from'\]\s*}}/g,'2026-10-01').replace(/{{\s*\$boot\['initial'\]\['filters'\]\['to'\]\s*}}/g,'2026-10-07');
 assert(!/@if|@foreach|{{/.test(html));return html;
}
const branches=[{value:'f:100',name:'فرع المحلة'}];
function data(){return {success:true,items:[],categories:{purchases:'مشتريات'},active_categories:{purchases:'مشتريات'},category_items:[],actors:[],filters:{branch:'f:100',from:'2026-10-01',to:'2026-10-07'},pagination:{page:1,last_page:1,total:0},summary:{today:'0.00',month:'0.00',average:'0.00',count:0,top_category:null,top_amount:'0.00',period:'0.00',pending:0,cash_balance:'1000.00'}};}
const shell='<style>body{margin:0;font-family:Arial,sans-serif}.app-head{height:58px;background:#0b1928}.app-foot{height:51px;background:#0b1928}.content-wrapper{margin-inline-end:250px}aside.app-side{position:fixed;inset-inline-end:0;width:250px;top:58px;bottom:0;background:#0b1928}@media(max-width:999px){aside.app-side{display:none}.content-wrapper{margin-inline-end:0}}</style>';
(async()=>{
 const browser=await chromium.launch({headless:true});fs.mkdirSync('test-results/phone-delivery',{recursive:true});
 try{for(const [width,height,role] of [[1366,768,'branch'],[1024,650,'admin'],[1920,1080,'owner'],[390,844,'branch']]){
  const page=await browser.newPage({viewport:{width,height}}),errors=[],posted=[],approve=role!=='branch';page.on('pageerror',e=>errors.push(e.message));
  const boot={actor_id:1,today:'2026-10-07',initial:data(),branches,permissions:{can_create:true,can_approve:approve,can_manage_categories:approve},urls:{data:'/data',save:'/save',recover:'/recover',export:'/export',report:'/report',categories:'/categories'}};
  const html=`<html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture">${shell}<style>${read('public/dashboard/css/branch-expenses.css')}</style></head><body class="dashboard-theme"><div class="app-head"></div><aside class="app-side"></aside>${template(approve,approve)}<div class="app-foot"></div><script id="branch-expenses-bootstrap" type="application/json">${JSON.stringify(boot)}</script><script id="branch-expenses-labels" type="application/json">${JSON.stringify(labels)}</script></body></html>`;
  await page.route('http://localhost/**',route=>{
   const req=route.request(),p=new URL(req.url()).pathname;
   if(p==='/data')return route.fulfill({json:data()});
   if(p==='/save'){
    const values=Object.fromEntries([...req.postData().matchAll(/name="([^"\r\n]+)"\r\n\r\n([^\r\n]*)/g)].map(m=>[m[1],m[2]]));posted.push(values);
    return route.fulfill({json:{success:true,expense:{branch:'f:100',status:'pending'}}});
   }
   return route.fulfill({contentType:'text/html',body:html});
  });
  await page.goto('http://localhost/');await page.addScriptTag({content:read('public/dashboard/js/branch-expenses.js')});
  await page.locator('[data-expense-new]').click();const form=page.locator('[data-expense-form]');
  for(const name of ['payment_reference','supplier','cost_center','attachment','notes'])assert.equal(await form.locator(`[name="${name}"]`).count(),0);
  await form.locator('[name="category"]').selectOption('purchases');await form.locator('[name="description"]').fill('خامات للفرع');await form.locator('[name="amount"]').fill('125.50');
  const before=await page.evaluate(()=>{const form=document.querySelector('[data-expense-form]'),button=form.querySelector('[data-expense-save]').getBoundingClientRect();return {pageScroll:document.documentElement.scrollHeight>innerHeight+1,formScroll:form.scrollHeight>form.clientHeight+1,buttonBottom:button.bottom,wide:document.documentElement.scrollWidth>innerWidth+1};});
  assert(!before.wide);if(width>=1024){assert(!before.pageScroll,JSON.stringify(before));assert(!before.formScroll,JSON.stringify(before));assert(before.buttonBottom<=height-51);}
  await page.screenshot({path:`test-results/phone-delivery/expense-form-${width}-${role}.png`,fullPage:true});
  await form.locator('[data-expense-save]').click();
  try{await page.waitForFunction(message=>document.querySelector('[data-expense-message] span').textContent===message,labels.saved,{timeout:3000});}
  catch(error){console.error({posted,errors,notice:await page.locator('[data-expense-message]').textContent(),invalid:await form.locator(':invalid').evaluateAll(nodes=>nodes.map(n=>({name:n.name,value:n.value,message:n.validationMessage})))});throw error;}
  assert.equal(posted.length,1);assert.equal(posted[0].branch,'f:100');assert.equal(posted[0].payment_method,'cash');assert.equal(posted[0].amount,'125.50');assert.equal(posted[0].approve,'0');
  for(const name of ['payment_reference','supplier','cost_center','attachment','notes'])assert(!(name in posted[0]));
  assert.deepEqual(errors,[]);console.log({width,height,role,...before});await page.close();
 }}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
