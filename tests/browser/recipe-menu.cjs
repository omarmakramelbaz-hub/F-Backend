// A meal on a later API page must be selectable and editable in the complete recipe menu.
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');
const panel='<section data-inventory-panel="recipes"'+read('resources/views/admin/branch_stock/index.blade.php').split('<section data-inventory-panel="recipes"')[1].split('\n</section>')[0]+'\n</section>';
const ingredients=[{id:1,name:'فسيخ',unit:'kg'}];
function menu(){
 const items=Array.from({length:50},(_,i)=>({id:i+1,name:'صنف مباشر '+String(i+1).padStart(2,'0'),features:[{id:0,label:'الصنف الأساسي'}],recipe:null,stock_source:'direct'}));
 ['وجبة رنجة','وجبة رنجة بلس','وجبة سردين','وجبة سردين بلس','وجبة فسخانستا ميكس توب','وجبة فسيخ توب'].forEach((name,i)=>items.push({id:51+i,name,features:[{id:0,label:'الصنف الأساسي'}],recipe:null,stock_source:'unconfigured'}));
 // A partially configured meal still needs its missing size.
 items[50].features.push({id:7,label:'نصف'});
 items[50].recipe={id:51,revision:1,unit:'piece',variants:{0:[{ingredient_id:1,measure:'g',quantity:'250'}]}};items[50].stock_source='recipe';
 return items;
}
function html(canManage){
 const boot={actor_id:canManage?1:10,initial:{ingredients,can_manage_recipes:canManage},urls:{recipes:'/recipes',recipe_save:'/save',recover:'/recover'}};
 return `<html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"><style>body{font-family:Arial;margin:0}*{box-sizing:border-box}${read('public/dashboard/css/branch-stock.css')}</style></head><body><main id="branch-stock"><select data-stock-branch><option value="f:100">فرع المحلة</option><option value="f:101">فرع آخر</option></select><button type="button" data-inventory-tab="recipes">وصفات المينيو</button>${panel}</main><script id="branch-stock-bootstrap" type="application/json">${JSON.stringify(boot)}</script></body></html>`;
}
(async()=>{
 const browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1366,height:768}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
 let items=menu(),canManage=true,failure=false,paginationChanged=false,hold=false,release,requests=[],saves=[];
 try{
  await page.route('http://localhost/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.pathname==='/recipes'){
    const branch=u.searchParams.get('branch'),number=Number(u.searchParams.get('page'));requests.push({branch,page:number,search:u.searchParams.get('search')});
    if(branch==='f:101')return route.fulfill({json:{success:true,items:[{id:201,name:'وجبة الفرع الآخر',features:[{id:0,label:'الصنف الأساسي'}],recipe:null,stock_source:'unconfigured'}],ingredients,can_manage:canManage,pagination:{page:1,last_page:1,total:1}}});
    if(number===2&&hold){await new Promise(resolve=>release=resolve);}
    if(number===2&&failure)return route.fulfill({status:500,json:{success:false,message:'تعذر تحميل بقية أصناف البيع.'}});
    return route.fulfill({json:{success:true,items:items.slice((number-1)*50,number*50),ingredients,can_manage:canManage,pagination:{page:number,last_page:2,total:paginationChanged&&number===2?57:56}}});
   }
   if(u.pathname==='/save'){
    const op=req.postDataJSON();saves.push(op);assert.equal(req.headers()['x-csrf-token'],'fixture');
    const item=items.find(item=>item.id===op.product_id);assert(item);assert.equal(op.branch,'f:100');
    item.recipe={id:item.id,revision:1,unit:op.unit,variants:{[op.feature_id]:op.components}};item.stock_source='recipe';
    return route.fulfill({json:{success:true,recipe:item.recipe,operation:op}});
   }
   return route.fulfill({contentType:'text/html',body:html(canManage)});
  });
  async function open(){await page.goto('http://localhost/recipe-test');await page.addScriptTag({content:read('public/dashboard/js/branch-recipes.js')});await page.locator('[data-inventory-tab="recipes"]').click();}
  const list=page.locator('[data-recipe-products] button'),filter=page.locator('[data-recipe-filter]'),search=page.locator('[data-recipe-search]'),summary=page.locator('[data-recipe-page]');
  await open();await page.waitForFunction(()=>document.querySelectorAll('[data-recipe-product]').length===56);
  assert.deepEqual(requests,[{branch:'f:100',page:1,search:null},{branch:'f:100',page:2,search:null}]);
  assert.match(await summary.innerText(),/56.*56.*6 يحتاج وصفة/);
  assert((await list.first().getAttribute('data-recipe-product'))>=51);
  assert.equal(await page.locator('[data-recipe-prev],[data-recipe-next]').count(),0);
  await filter.selectOption('missing');assert.equal(await list.count(),6);assert.equal(await page.locator('[data-recipe-product="51"]').count(),1);
  await filter.selectOption('ready');assert.equal(await list.count(),50);
  await filter.selectOption('all');await search.fill('وجبة فسيخ توب');assert.equal(await list.count(),1);
  assert.equal(requests.length,2);await page.locator('[data-recipe-product="56"]').click();
  await page.locator('[data-recipe-ingredient]').selectOption('1');await page.locator('[data-recipe-quantity]').fill('250');
  await search.fill('وجبة سردين');assert.equal(await list.count(),2);
  assert.equal(await page.locator('[data-recipe-title]').innerText(),'وجبة فسيخ توب');assert.equal(await page.locator('[data-recipe-quantity]').inputValue(),'250');
  await page.locator('[data-recipe-save]').click();await page.waitForFunction(()=>document.querySelector('[data-recipe-revision]').textContent==='نسخة الوصفة 1'&&!document.querySelector('[data-recipe-refresh]').disabled);
  assert.equal(saves.length,1);assert.equal(saves[0].product_id,56);assert.deepEqual(saves[0].components,[{ingredient_id:1,quantity:'250',measure:'g'}]);
  await search.fill('');await filter.selectOption('missing');assert.equal(await list.count(),5);await filter.selectOption('ready');assert.equal(await list.count(),51);
  assert.match(await summary.innerText(),/5 يحتاج وصفة/);assert.equal(await page.locator('[data-recipe-title]').innerText(),'وجبة فسيخ توب');
  await filter.selectOption('all');fs.mkdirSync('test-results/phone-delivery',{recursive:true});await page.screenshot({path:'test-results/phone-delivery/recipe-menu-complete.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await page.screenshot({path:'test-results/phone-delivery/recipe-menu-mobile.png',fullPage:true});

  canManage=false;await open();await page.waitForFunction(()=>document.querySelectorAll('[data-recipe-product]').length===56);
  await page.locator('[data-recipe-product="56"]').click();assert.equal(await page.locator('[data-recipe-save]').isVisible(),false);
  assert.equal(await page.locator('[data-recipe-quantity]').isDisabled(),true);assert.equal(await page.locator('[data-recipe-feature]').isDisabled(),false);

  // A failed later page must not quietly present the first 50 as the complete menu.
  canManage=true;failure=true;await open();await page.waitForFunction(()=>document.querySelector('[data-recipe-message]').textContent==='تعذر تحميل بقية أصناف البيع.');
  assert.equal(await list.count(),0);assert.equal(await page.locator('[data-recipe-refresh]').isDisabled(),false);
  failure=false;await page.locator('[data-recipe-refresh]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-recipe-product]').length===56);
  paginationChanged=true;await open();await page.waitForFunction(()=>document.querySelector('[data-recipe-message]').textContent.includes('تغيرت أثناء التحميل'));
  assert.equal(await list.count(),0);paginationChanged=false;

  // A branch change cancels the old multi-page load and never mixes branch products.
  hold=true;requests=[];await open();await page.waitForFunction(()=>document.querySelector('[data-recipe-refresh]').disabled);
  const deadline=Date.now()+5000;while(!release&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,10));assert(release,'The later page was requested.');
  await page.evaluate(()=>{const root=document.getElementById('branch-stock');root.querySelector('[data-stock-branch]').value='f:101';root.dispatchEvent(new Event('inventory:branch'));});
  await page.waitForFunction(()=>document.querySelector('[data-recipe-product="201"]'));
  release();await page.waitForFunction(()=>!document.querySelector('[data-recipe-refresh]').disabled);
  assert.equal(await list.count(),1);assert.equal(await list.first().innerText(),'وجبة الفرع الآخر\nيحتاج وصفة');assert.equal(await page.locator('[data-recipe-message]').isVisible(),false);
  assert.deepEqual(errors,[]);console.log('Complete recipe menu, missing-size filter, later-page save, permissions, failed loads and branch cancellation passed.');
 }finally{if(release)release();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
