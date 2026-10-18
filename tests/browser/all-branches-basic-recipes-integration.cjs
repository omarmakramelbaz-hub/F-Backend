// Run against an installer-generated merge of the newer basic-recipes live files.
// Only HTTP data is synthetic; both production scripts and the production view run.
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const runtime=process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES||process.env.NODE_PATH;
const {chromium}=require(runtime?path.join(runtime,'playwright'):'playwright');
const sourceRoot=process.env.ALL_BRANCHES_MERGED_ROOT;
assert(sourceRoot,'Set ALL_BRANCHES_MERGED_ROOT to the installer-generated fixture.');
const read=relative=>fs.readFileSync(path.join(sourceRoot,relative),'utf8');
const branches=[{value:'f:100',name:'المنصورة'},{value:'f:101',name:'نبروه'}];
const ingredient={id:16,name:'بصل',unit:'kg',stock:null};
const product={id:101,name:'طبق بصل',features:[{id:0,label:'الحجم الأساسي'}],recipe:null,stock_source:'ingredient_preview',recipe_hint:[{ingredient_id:16,measure:'g',quantity:''}]};
function stockData(branch){
 const all=branch==='all',selected=branches.find(row=>row.value===branch);
 return {success:true,all_branches:all,branch:{value:branch,name:all?'جميع الفروع':selected.name},ingredients:[ingredient],items:all?branches.map(row=>({...ingredient,branch:row.value,branch_name:row.name})):[ingredient],can_manage_recipes:!all,history:[],legacy:[],unmapped_sales:[],unconfigured_count:1,pagination:{page:1,last_page:1,total:all?2:1}};
}
const boot={actor_id:7,branch:'all',can_all:true,branches,initial:stockData('all'),urls:{data:'/stock-data',receive:'/stock-receive',recover:'/recipe-recover',recipes:'/recipes',recipe_save:'/recipe-save',basic_status:'/basic-status',basic_apply:'/basic-apply'}};
function template(){
 let html=read('resources/views/admin/branch_stock/index.blade.php').split("@section('content')")[1].split('@endsection')[0];
 assert(html.includes('data-basic-preview')&&html.includes('data-basic-dialog'),'newer basic recipe markup retained');
 assert(/<option value="all"/.test(html),'aggregate branch option retained');
 html=html.replace(/<select data-stock-branch[\s\S]*?<\/select>/,'<select data-stock-branch><option value="all" selected>جميع الفروع</option>'+branches.map(row=>`<option value="${row.value}">${row.name}</option>`).join('')+'</select>');
 assert(!/@if|@foreach|{{/.test(html),'fixture resolved every Blade directive');
 return '<meta name="csrf-token" content="fixture">'+html+'<script id="branch-stock-bootstrap" type="application/json">'+JSON.stringify(boot)+'</script>';
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined}),page=await browser.newPage(),calls=[],errors=[];
 let releaseStock;
 page.on('pageerror',error=>errors.push(error.message));
 try{
  await page.route('https://branch-merge-test.invalid/**',async route=>{
   const request=route.request(),url=new URL(request.url()),branch=url.searchParams.get('branch'),body=request.postData()?request.postDataJSON():null;
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:template()});
   calls.push({path:url.pathname,branch,body});
   if(['/recipes','/basic-status','/basic-apply'].includes(url.pathname))assert((body&&body.branch||branch)!=='all','no recipe/basic request may use aggregate branch');
   if(url.pathname==='/stock-data'){
    if(releaseStock)await new Promise(resolve=>{releaseStock=resolve;});
    return route.fulfill({json:stockData(branch)});
   }
   if(url.pathname==='/recipes')return route.fulfill({json:{success:true,can_manage:true,items:[product],ingredients:[ingredient],pagination:{page:1,last_page:1,total:1}}});
   if(url.pathname==='/basic-status')return route.fulfill({json:{success:true,available:true,can_apply:true,branch:branches.find(row=>row.value===branch),source:{name:'المحلة',frozen_at:'2026-10-18 15:30',branch:'f:94'},preview_hash:'a'.repeat(64),summary:{matched:1,changed:1,unchanged:0,skipped:0},skipped:[]}});
   if(url.pathname==='/basic-apply')return route.fulfill({json:{success:true,basic_recipes:{summary:{matched:1,changed:1,unchanged:0,skipped:0},skipped:[]},operation:body}});
   if(url.pathname==='/recipe-recover')return route.fulfill({json:{success:true,found:false}});
   throw Error('Unexpected endpoint '+url.pathname);
  });
  await page.goto('https://branch-merge-test.invalid/');
  await page.addScriptTag({content:read('public/dashboard/js/branch-stock.js')});
  await page.addScriptTag({content:read('public/dashboard/js/branch-recipes.js')});
  const count=endpoint=>calls.filter(call=>call.path===endpoint).length;
  const forcedClick=selector=>page.locator(selector).evaluate(node=>node.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true})));
  assert.equal(await page.locator('[data-stock-branch]').inputValue(),'all');
  assert(await page.locator('[data-stock-branch]').isEnabled());
  assert(await page.locator('[data-stock-save]').isDisabled());
  await page.locator('[data-inventory-tab="recipes"]').click();
  assert(await page.locator('[data-basic-preview]').isHidden());
  await forcedClick('[data-basic-preview]');await forcedClick('[data-basic-confirm]');
  assert.equal(count('/recipes'),0);assert.equal(count('/basic-status'),0);assert.equal(count('/basic-apply'),0);
  // Hold stock data during all->specific: basic recipe actions must stay locked.
  releaseStock=true;
  await page.selectOption('[data-stock-branch]','f:100');
  await page.waitForFunction(()=>document.getElementById('branch-stock').dataset.stockBusy==='true');
  assert(await page.locator('[data-basic-preview]').isDisabled());
  await forcedClick('[data-basic-preview]');assert.equal(count('/basic-status'),0);
  await page.waitForFunction(()=>!!document.querySelector('[data-recipe-product="101"]'));
  const resolveStock=releaseStock;releaseStock=null;resolveStock();
  await page.waitForFunction(()=>!document.querySelector('[data-basic-preview]').disabled&&!document.querySelector('[data-basic-preview]').hidden);
  await page.locator('[data-basic-preview]').click();await page.waitForFunction(()=>document.querySelector('[data-basic-dialog]').open);
  assert.equal(count('/basic-apply'),0,'preview alone remains read-only');
  assert.equal(calls.filter(call=>call.path==='/basic-status').at(-1).branch,'f:100');
  await page.locator('[data-basic-confirm]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-basic-result]').hidden);
  assert.equal(count('/basic-apply'),1);assert.equal(calls.find(call=>call.path==='/basic-apply').body.branch,'f:100');
  await page.waitForFunction(()=>!document.querySelector('[data-stock-branch]').disabled);
  const recipeReads=count('/recipes'),previewReads=count('/basic-status');
  await page.selectOption('[data-stock-branch]','all');
  await page.waitForFunction(()=>!document.querySelector('[data-stock-branch]').disabled);
  assert(await page.locator('[data-basic-preview]').isHidden());assert(await page.locator('[data-stock-save]').isDisabled());
  await forcedClick('[data-basic-preview]');await forcedClick('[data-basic-confirm]');
  assert.equal(count('/recipes'),recipeReads);assert.equal(count('/basic-status'),previewReads);assert.equal(count('/basic-apply'),1);
  await page.selectOption('[data-stock-branch]','f:101');
  await page.waitForFunction(()=>!document.querySelector('[data-basic-preview]').disabled&&!document.querySelector('[data-basic-preview]').hidden);
  await page.locator('[data-basic-preview]').click();await page.waitForFunction(()=>document.querySelector('[data-basic-dialog]').open);
  assert.equal(calls.filter(call=>call.path==='/basic-status').at(-1).branch,'f:101');
  assert.equal(count('/basic-apply'),1,'changing branch never applies a previous preview');
  assert.deepEqual(errors,[]);
  console.log('ALL_BRANCHES_NEWER_BASIC_RECIPES_BROWSER_OK aggregate guard, branch switch, stock locking, preview, apply and retained source UI');
 }finally{if(typeof releaseStock==='function')releaseStock();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
