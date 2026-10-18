'use strict';
// Real POS Blade/JS exercised against local browser fixtures only.
const assert=require('assert'), fs=require('fs'), os=require('os'), path=require('path'), cp=require('child_process');
const {chromium}=require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES||process.env.NODE_PATH||'', 'playwright'));
const root=path.resolve(__dirname,'../..'), tmp=fs.mkdtempSync(path.join(os.tmpdir(),'pos-all-branches-'));
const php=process.env.CRM_TEST_PHP||'php';
assert(process.env.CRM_TEST_AUTOLOAD&&fs.existsSync(process.env.CRM_TEST_AUTOLOAD),'CRM_TEST_AUTOLOAD required');
// Fixture compiler supports only the directives used by these two templates.
const render = String.raw`require getenv('CRM_TEST_AUTOLOAD');function __($key){global $labels;return $labels[substr($key,strpos($key,'.')+1)]??$key;}function route($name){return 'https://pos.test/admin/takeaway';}$c=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($c);$loader=new \Illuminate\Translation\ArrayLoader;$kind=getenv('QA_KIND');$labels=require getenv('QA_ROOT').'/resources/lang/ar/'.$kind.'.php';$loader->addMessages('ar',$kind,$labels);$c->instance('translator',new \Illuminate\Translation\Translator($loader,'ar'));$c->instance('url',new class {public function route($name,$parameters=[],$absolute=true){return 'https://pos.test/admin/takeaway';}});$boot=json_decode(file_get_contents(getenv('QA_BOOT')),true);if($kind==='takeaway')$pos=$boot;else $dining=$boot;$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/'.$kind.'/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];foreach(['foreach','if','elseif'] as $directive)$blade=preg_replace_callback('/@'.$directive.'(\((?:[^()]++|(?1))*\))/s',fn($match)=>'<?php '.$directive.' '.$match[1].': ?>',$blade);$blade=str_replace(['@endforeach','@endif','@else'],['<?php endforeach; ?>','<?php endif; ?>','<?php else: ?>'],$blade);$blade=preg_replace('/{{\s*(.*?)\s*}}/s','<?= e($1) ?>',$blade);ob_start();eval('?>'.$blade);echo json_encode(['content'=>ob_get_clean(),'labels'=>$labels],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);`;
const safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');
function html(kind, boot) {
    const bootFile = path.join(tmp, `${kind}-boot.json`);
    fs.writeFileSync(bootFile, JSON.stringify(boot));
    const rendered = JSON.parse(cp.execFileSync(php, ['-r', render], { encoding: 'utf8', env: { ...process.env, QA_KIND: kind, QA_ROOT: root, QA_BOOT: bootFile, QA_CACHE: tmp } }));
    return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>*{box-sizing:border-box}html,body{margin:0;font-family:Arial,sans-serif}.content-wrapper{margin:0}</style><style>${fs.readFileSync(path.join(root, `public/dashboard/css/${kind}-pos.css`), 'utf8')}</style></head><body class="dashboard-theme dashboard-${kind}-page">${rendered.content}<script type="application/json" id="${kind}-bootstrap">${safe(boot)}</script><script type="application/json" id="${kind}-translations">${safe(rendered.labels)}</script><script>${fs.readFileSync(path.join(root, `public/dashboard/js/${kind}-pos.js`), 'utf8')}</script></body></html>`;
}

const branches=[{id:100,value:'f:100',name:'المحلة'},{id:101,value:'f:101',name:'المنصورة'}];
const permissions={can_checkout:true,can_operate:true,can_manage:true,can_manage_tables:true};
const policy={can_discount:true,tax_rate:'0.00',service_rate:'0.00',settings_revision:1,payment_methods:['cash']};
const product={id:1,name:'صنف اختبار',price:'100.00',unit_price:'100.00',quantity_mode:'piece',available:true,options:[]};
async function setup(browser,kind,stored){
 const boot={branches,selected_branch:'',can_view_all_branches:true,cashier:{id:1,name:'Owner'},permissions,policy,
 urls:{catalog:`/admin/${kind}/catalog`,quote:`/admin/${kind}/quote`,daily:'/admin/takeaway/receipts',tables:'/admin/dining/tables',show:'/admin/dining/tickets/__TICKET__'}};
 const rendered=html(kind,boot), page=await browser.newPage(), calls=[], errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(stored=>{window.confirm=()=>true;window.DashboardInvoiceDetails={open:()=>{}};if(stored)sessionStorage.setItem('takeaway-invoices:v2:1',JSON.stringify(stored));},stored);
 await page.route('**/*',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.origin!=='https://pos.test')throw Error('External browser request forbidden');
  if(url.pathname===`/admin/${kind}`)return route.fulfill({contentType:'text/html',body:rendered});
  const payload=request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams);
  calls.push({path:url.pathname,method:request.method(),payload});
  assert(request.method()==='GET'||url.pathname.endsWith('/quote'),'Fixture allows no operational writes');
  let result, branch=branches.find(item=>item.value===payload.branch);
  if(url.pathname.endsWith('/catalog'))result={success:true,ready:true,branch,items:[product],categories:[],policy,permissions,pagination:{page:1,last_page:1},today:{count:0}};
  else if(url.pathname.endsWith('/tables'))result={success:true,branch:branch||{value:''},policy,permissions,tables:branches.filter(item=>!branch||item.value===branch.value).map((item,index)=>({id:item.id,name:'1',branch:item.value,branch_name:item.name,capacity:4,active:true,status:'free',ticket_id:null}))};
  else if(url.pathname==='/admin/takeaway/receipts')result={success:true,items:branches.map((branch,index)=>({id:index+1,number:'TA-'+(index+1),branch,channel:'takeaway',payment_method:'cash',total:'100.00',can_reprint:false})),pagination:{page:1,last_page:1},today:{count:2}};
  else if(url.pathname.endsWith('/quote'))result={success:true,branch,items:payload.items.map(item=>({...item,name:product.name,total:'100.00',unit_price:'100.00'})),quote_hash:'a'.repeat(64),total:'100.00',subtotal:'100.00',discount:'0.00',tax:'0.00',service:'0.00'};
  else throw Error('Unexpected fixture route '+url.pathname);
  return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
 });
 await page.goto(`https://pos.test/admin/${kind}`);
 return {page,calls,errors};
}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});try{
 const takeaway=await setup(browser,'takeaway');
 assert.strictEqual(await takeaway.page.locator('[data-pos-branch]').inputValue(),'');
 assert.strictEqual(await takeaway.page.locator('[data-pos-branch] option:checked').textContent(),'جميع الفروع');
 assert(!takeaway.calls.some(call=>call.path.endsWith('/catalog')),'all selection never fetches an arbitrary branch catalog');
 await takeaway.page.locator('[data-pos-daily]').click();
 await takeaway.page.locator('[data-pos-modal-content] tbody tr').first().waitFor();
 assert.strictEqual(takeaway.calls.find(call=>call.path.endsWith('/receipts')).payload.branch,'all');
 assert((await takeaway.page.locator('[data-pos-modal-content]').innerText()).includes('المحلة'));
 assert((await takeaway.page.locator('[data-pos-modal-content]').innerText()).includes('المنصورة'));
 await takeaway.page.locator('[data-pos-modal-close]').click();
 await takeaway.page.locator('[data-pos-branch]').selectOption('f:100');
 await takeaway.page.locator('[data-pos-product="1"]').waitFor();
 const empty=await takeaway.page.evaluate(()=>JSON.parse(sessionStorage.getItem('takeaway-invoices:v2:1')));
 const reset=await setup(browser,'takeaway',empty);
 assert.strictEqual(await reset.page.locator('[data-pos-branch]').inputValue(),'','empty saved branch returns to all on reopening');
 const stale=structuredClone(empty);stale.updated=Date.now()-24*3600*1000;stale.invoices[0].cart=[{product,option:null,mode:'piece',quantity:'1'}];
 const draft=await setup(browser,'takeaway',stale);
 await draft.page.locator('[data-pos-lines] tr').first().waitFor();
 assert.strictEqual(await draft.page.locator('[data-pos-branch]').inputValue(),'f:100','unsaved cart retains its exact branch even after twelve hours');
 const completed=structuredClone(stale);completed.invoices[0].sold=true;
 const sold=await setup(browser,'takeaway',completed);
 assert.strictEqual(await sold.page.locator('[data-pos-branch]').inputValue(),'','completed stored sales reopen at all branches');
 const pending=structuredClone(stale),sale=pending.invoices[0];
 sale.uncertain=true;sale.frozenPayload={branch:sale.branch,idempotency_key:sale.saleKey,items:[{product_id:1,quantity:'1',quantity_mode:'piece'}],payment_method:'cash',quote_hash:'a'.repeat(64)};
 const uncertain=await setup(browser,'takeaway',pending);
 await uncertain.page.locator('[data-pos-lines] tr').first().waitFor();
 assert.strictEqual(await uncertain.page.locator('[data-pos-branch]').inputValue(),'f:100','pending sale retains its immutable branch');
 assert(await uncertain.page.locator('[data-pos-branch]').isDisabled());
 const dining=await setup(browser,'dining');
 await dining.page.locator('[data-dining-table="100"]').waitFor();
 await dining.page.locator('[data-dining-table="101"]').waitFor();
 assert.strictEqual(await dining.page.locator('[data-dining-branch]').inputValue(),'');
 assert((await dining.page.locator('[data-dining-tables]').innerText()).includes('المنصورة'));
 await dining.page.locator('[data-dining-table="101"]').click();
 await dining.page.locator('[data-dining-open-customer]').waitFor();
 assert.strictEqual(await dining.page.locator('[data-dining-branch]').inputValue(),'f:101','opening an aggregate table selects its real branch');
 assert(dining.calls.some(call=>call.path.endsWith('/catalog')&&call.payload.branch==='f:101'));
 assert(!dining.calls.some(call=>call.method==='POST'),'opening the table performs no operational write');
 await dining.page.locator('[data-dining-modal-close]').click();
 await dining.page.locator('[data-dining-branch]').selectOption('');
 await dining.page.locator('[data-dining-table="100"]').waitFor();
 assert.strictEqual(dining.calls.filter(call=>call.path.endsWith('/tables')).at(-1).payload.branch,'all');
 for(const context of [takeaway,reset,draft,sold,uncertain,dining])assert.deepStrictEqual(context.errors,[]);
 console.log('POS all branches browser checks passed: default selection, aggregate receipts and floor, concrete table routing, draft/pending preservation, completed reset.');
}finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}})().catch(error=>{console.error(error);process.exit(1);});
