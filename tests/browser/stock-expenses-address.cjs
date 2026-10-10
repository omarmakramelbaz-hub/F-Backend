const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const read=p=>fs.readFileSync(path.join(__dirname,'../..',p),'utf8');

(async()=>{
 const browser=await chromium.launch({headless:true}),page=await browser.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 try{
  let count=2,requests=0;
  await page.route('http://localhost/**',route=>{
   if(new URL(route.request().url()).pathname==='/pending-count'){requests++;return route.fulfill({json:{success:true,count}});}
   return route.fulfill({contentType:'text/html',body:`<html dir="rtl"><head><style>${read('public/dashboard/branding/dashboard-brand.css')}</style></head><body><a>مصروفات الفروع <span class="badge dashboard-expense-badge" data-expense-pending-count data-count-url="/pending-count" data-count-label="مصروفات تنتظر الاعتماد" hidden>0</span></a></body></html>`});
  });
  await page.goto('http://localhost/');
  await page.addScriptTag({content:read('public/dashboard/js/dashboard-expense-badge.js')});
  const badge=page.locator('[data-expense-pending-count]');
  await page.waitForFunction(()=>document.querySelector('[data-expense-pending-count]').textContent==='2');assert(await badge.isVisible());
  assert.equal(await badge.evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(220, 53, 69)');
  count=1;await page.evaluate(()=>window.dispatchEvent(new Event('dashboard:expenses-changed')));
  await page.waitForFunction(()=>document.querySelector('[data-expense-pending-count]').textContent==='1');
  count=0;await page.evaluate(()=>document.dispatchEvent(new Event('dashboard:page-loaded')));await page.waitForFunction(()=>document.querySelector('[data-expense-pending-count]').hidden);
  const before=requests;await page.addScriptTag({content:read('public/dashboard/js/dashboard-expense-badge.js')});assert.equal(requests,before);
  await page.goto('http://localhost/');await badge.evaluate(n=>n.remove());const branchRequests=requests;await page.addScriptTag({content:read('public/dashboard/js/dashboard-expense-badge.js')});assert.equal(requests,branchRequests);
  const expense=read('public/dashboard/js/branch-expenses.js'),reviewStart=expense.indexOf('    function review('),reviewEnd=expense.indexOf('\n    async function details',reviewStart);
  assert(reviewStart>0&&reviewEnd>reviewStart);
  const approval=await page.evaluate(code=>{
   return (0,eval)(`(function(){var calls=[],confirms=0,boot={urls:{review:'/review'}};function locked(){return false;}function rowNotice(){}function url(v,p,id){return v+'/'+id;}function t(k){return k;}function uuid(){return 'operation-key';}function execute(op){calls.push(op);}function confirm(){confirms++;return true;}function prompt(){return 'رفض';}${code}
review({id:42,branch:'f:100',revision:2},'approve');var afterApprove=confirms;review({id:42,branch:'f:100',revision:2},'reject');return {calls,afterApprove,confirms};})()`);
  },expense.slice(reviewStart,reviewEnd));
  assert.equal(approval.afterApprove,0);assert.equal(approval.confirms,1);assert.equal(approval.calls[0].values.action,'approve');assert.equal(approval.calls[0].values.expected_revision,2);

  await page.setContent('<input id="address"><input id="area"><input id="lat"><input id="lng"><div id="options" hidden></div><p id="status"></p><div id="phone-orders"><div data-phone-map></div><p data-phone-address-status></p></div>');
  await page.evaluate(()=>{
   class Map {addListener(){} setCenter(){} setZoom(){}}
   class Marker {constructor(o){this.position=o.position;}addListener(){}setPosition(p){this.position=p;}getPosition(){return this.position;}setVisible(){}setMap(){}}
   class Circle {setCenter(){}setRadius(){}setMap(){}}
   window.components=[{types:['country'],longText:'مصر'},{types:['locality'],longText:'القاهرة الجديدة'},{types:['sublocality_level_1','political'],longText:'التجمع الجنوبي'}];
   window.google={maps:{Map,Marker,Circle,Geocoder:class{},event:{trigger(){},clearInstanceListeners(){}},importLibrary:async()=>({AutocompleteSessionToken:class{},AutocompleteSuggestion:{fetchAutocompleteSuggestions:async()=>({suggestions:[{placePrediction:{placeId:'one',text:{toString:()=> 'عنوان مختار'},toPlace:()=>({location:{lat:()=>30.01,lng:()=>31.02},formattedAddress:'العنوان المختار، التجمع الجنوبي',get addressComponents(){return window.components;},fetchFields:async({fields})=>{window.requestedFields=fields;}})}}]})}})}};
  });
  await page.addScriptTag({content:read('public/dashboard/js/dashboard-location-picker.js')});
  await page.addScriptTag({content:read('public/dashboard/js/phone-address-search.js')});
  const phone=read('public/dashboard/js/phone-orders.js');
  const desktopFlag=phone.match(/^    var desktopLocal = [^\n]+$/m);assert(desktopFlag);
  const start=phone.indexOf('    addressSearch=PhoneAddressSearch.create('),end=phone.indexOf('\n    async function confirmLocation',start);
  assert(start>0&&end>start);
  await page.evaluate(({code,flag})=>{
   (0,eval)(`${flag}\nvar root=document.getElementById('phone-orders'),fields={address:document.getElementById('address'),area:document.getElementById('area')},latInput=document.getElementById('lat'),lngInput=document.getElementById('lng'),addressSearch;
function locked(){return false;}function invalidateLocation(){}function previewLocation(){}
var locationPicker=DashboardLocationPicker.create(root.querySelector('[data-phone-map]'),{key:'fixture'});
${code.replace("root.querySelector('[data-phone-address-options]')","document.getElementById('options')")}
window.searchAddress=addressSearch;`);
  },{code:phone.slice(start,end),flag:desktopFlag[0]});
  assert.equal(await page.evaluate(()=>desktopLocal),false);
  await page.locator('#address').fill('عنوان');await page.evaluate(()=>searchAddress.search());await page.locator('#options button').click();
  await page.waitForFunction(()=>document.getElementById('area').value==='التجمع الجنوبي');
  assert.equal(await page.locator('#lat').inputValue(),'30.01');assert.equal(await page.locator('#lng').inputValue(),'31.02');assert((await page.evaluate(()=>requestedFields)).includes('addressComponents'));
  await page.evaluate(()=>{components=[{types:['locality'],longText:'المنصورة'}];searchAddress.search();});await page.locator('#options button').click();await page.waitForFunction(()=>document.getElementById('area').value==='المنصورة');
  await page.evaluate(()=>{components=[{types:['country'],longText:'مصر'}];searchAddress.search();});await page.locator('#options button').click();await page.waitForFunction(()=>document.getElementById('area').value==='');
  // Enter a vegetable plate weight through the actual recipe form; no quantity is prefilled.
  await page.unroute('http://localhost/**');
  let saved=[];
  const item={id:101,name:'بصل جوليان احمر',features:[{id:0,label:'الحجم الأساسي'}],recipe:null,stock_source:'ingredient_preview',recipe_hint:[{ingredient_id:16,measure:'g',quantity:''}]};
  const ingredients=[{id:16,name:'بصل',unit:'kg'}],recipeBoot={actor_id:1,initial:{ingredients,can_manage_recipes:true},urls:{recipes:'/recipes',recipe_save:'/recipe-save',recover:'/recipe-recover'}};
  const panel='<section data-inventory-panel="recipes"'+read('resources/views/admin/branch_stock/index.blade.php').split('<section data-inventory-panel="recipes"')[1].split('\n</section>')[0]+'\n</section>';
  await page.route('http://localhost/**',route=>{
   const request=route.request(),pathname=new URL(request.url()).pathname;
   if(pathname==='/recipes')return route.fulfill({json:{success:true,can_manage:true,items:[item],ingredients,pagination:{page:1,last_page:1,total:1}}});
   if(pathname==='/recipe-save'){
    const op=request.postDataJSON();saved.push(op);item.recipe={id:1,revision:1,unit:op.unit,variants:{'0':op.components}};item.stock_source='recipe';
    return route.fulfill({json:{success:true,recipe:item.recipe,operation:op}});
   }
   return route.fulfill({contentType:'text/html',body:`<meta name="csrf-token" content="fixture"><div id="branch-stock"><select data-stock-branch><option value="f:100">فرع المحلة</option></select><button data-inventory-tab="recipes">الوصفات</button>${panel}</div><script id="branch-stock-bootstrap" type="application/json">${JSON.stringify(recipeBoot)}</script>`});
  });
  await page.goto('http://localhost/recipe-test');await page.addScriptTag({content:read('public/dashboard/js/branch-recipes.js')});
  await page.locator('[data-inventory-tab="recipes"]').click();await page.locator('[data-recipe-product="101"]').click();
  assert.equal(await page.locator('[data-recipe-ingredient]').inputValue(),'16');assert.equal(await page.locator('[data-recipe-measure]').inputValue(),'g');
  assert.equal(await page.locator('[data-recipe-quantity]').inputValue(),'');assert.equal(await page.locator('[data-recipe-form] select[name="unit"]').inputValue(),'piece');
  assert.match(await page.locator('[data-recipe-revision]').innerText(),/وزن الخضار بالجرام لكل طبق/);
  await page.locator('[data-recipe-save]').click();assert.equal(saved.length,0);
  await page.locator('[data-recipe-quantity]').fill('50');await page.locator('[data-recipe-save]').click();
  await page.waitForFunction(()=>document.querySelector('[data-recipe-revision]').textContent==='نسخة الوصفة 1');
  assert.equal(saved.length,1);assert.deepEqual(saved[0].components,[{ingredient_id:16,quantity:'50',measure:'g'}]);assert.equal(saved[0].unit,'piece');
  assert.deepEqual(errors,[]);console.log('Expense badge, address area and vegetable plate recipe checks passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
