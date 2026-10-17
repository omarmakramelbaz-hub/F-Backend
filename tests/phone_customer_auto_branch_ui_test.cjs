'use strict';
// Render the production Blade and run the production JS against fixture-owned HTTP/map APIs.
// No production customers, orders, messages or printing are accessed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const cp = require('node:child_process');
const {chromium} = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'phone-customer-branch-ui-'));
const php = process.env.CRM_TEST_PHP || 'php';
assert(process.env.CRM_TEST_AUTOLOAD && fs.existsSync(process.env.CRM_TEST_AUTOLOAD), 'Set CRM_TEST_AUTOLOAD to the isolated Laravel autoload.php.');
const urls = Object.fromEntries(['catalog', 'board', 'delivery-settings', 'delivery-quote', 'customers'].map(name => [name.replaceAll('-', '_'), '/admin/phone-orders/' + name]));
Object.assign(urls, {companies:'/admin/delivery-companies/data', customer_save:'/admin/customers/save'});
const branches = [{id:100,value:'f:100',name:'المنصورة',kind:'f'}, {id:101,value:'f:101',name:'المحلة',kind:'f'}];
const savedCustomer = {customer_id:71,customer_revision:6,branch:'f:101',branch_name:'المحلة',phone:'01012345678',name:'أحمد عميل المحلة',address:'شارع البحر، المحلة الكبرى',area:'المحلة',delivery_notes:'الدور الثاني',latitude:30.969,longitude:31.166};
const field = (page, name) => page.locator('[data-phone-field="' + name + '"]');
const render = `require getenv('CRM_TEST_AUTOLOAD');
$c=new \\Illuminate\\Container\\Container; \\Illuminate\\Container\\Container::setInstance($c);
$l=new \\Illuminate\\Translation\\ArrayLoader; $labels=require getenv('QA_ROOT').'/resources/lang/ar/phone_orders.php';
$l->addMessages('ar','phone_orders',$labels); $c->instance('translator',new \\Illuminate\\Translation\\Translator($l,'ar'));
if(!function_exists('__')){function __($key,$replace=[],$locale=null){return \\Illuminate\\Container\\Container::getInstance()->make('translator')->get($key,$replace,$locale);}}
$phonePos=json_decode(file_get_contents(getenv('QA_BOOT')),true);
$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/phone_orders/index.blade.php');
$blade=explode("@section('content')",$blade,2)[1]; $blade=explode('@endsection',$blade,2)[0];
$compiler=new \\Illuminate\\View\\Compilers\\BladeCompiler(new \\Illuminate\\Filesystem\\Filesystem,getenv('QA_CACHE'));
$__env=new class { use \\Illuminate\\View\\Concerns\\ManagesLoops; };
ob_start(); eval('?>'.$compiler->compileString($blade)); $content=ob_get_clean();
echo json_encode(['content'=>$content,'labels'=>$labels],JSON_UNESCAPED_UNICODE);`;
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');

async function setup(browser, {selected = '', all = true, enhanced = false, matches = [savedCustomer]} = {}) {
    const boot = {branches,selected_branch:selected,permissions:{can_checkout:true,can_operate:true},can_view_all_branches:all,receiver_branch:null,company_filter:0,open_phone_maps:false,maps_key:'fixture',urls};
    fs.writeFileSync(path.join(tmp, 'boot.json'), JSON.stringify(boot));
    const rendered = JSON.parse(cp.execFileSync(php, ['-r', render], {encoding:'utf8',env:{...process.env,QA_ROOT:root,QA_BOOT:path.join(tmp,'boot.json'),QA_CACHE:tmp}}));
    const enhance = enhanced ? `<script>window.selectizeUpdates=[];document.querySelector('[data-phone-branch]').selectize={setValue(value,silent){window.selectizeUpdates.push({value,silent});},disable(){},enable(){}};</script>` : '';
    const html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>body{margin:0;font-family:Arial}*{box-sizing:border-box}</style><style>' + read('public/dashboard/css/phone-orders.css') + '</style></head><body class="dashboard-phone-orders-page">' + rendered.content + '<script type="application/json" id="phone-orders-bootstrap">' + safe(boot) + '</script><script type="application/json" id="phone-orders-translations">' + safe(rendered.labels) + '</script>' + enhance + '<script>' + read('public/dashboard/js/phone-address-search.js') + '</script><script>' + read('public/dashboard/js/phone-orders.js') + '</script></body></html>';
    const page = await browser.newPage({viewport:{width:1440,height:1000}});
    const calls = [], errors = [], unexpected = [];
    const state = {matches,hold:null};
    state.holdNextLookup = () => {
        let enter, release;
        const hold = {entered:new Promise(resolve => {enter=resolve;}),gate:new Promise(resolve => {release=resolve;})};
        Object.assign(hold,{enter,release});state.hold=hold;return hold;
    };
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => {
        // Deliberately allow stale customer responses through for the race regression test.
        const actualFetch=window.fetch;
        window.fetch=function(input,options){if(window.ignoreCustomerAbort && String(input).includes('/phone-orders/customers'))options={...options,signal:undefined};return actualFetch.call(this,input,options);};
        window.DashboardLocationPicker={valid:(lat,lng)=>lat!==''&&lng!==''&&Number.isFinite(Number(lat))&&Number.isFinite(Number(lng))&&Math.abs(Number(lat))<=90&&Math.abs(Number(lng))<=180,create:()=>({resize(){},destroy(){},clear(){},invalidate(){},set(){},route(){},geocode:async()=>null,suggest:async()=>[],resolve:async item=>item})};
    });
    await page.route('**/*', async route => {
        const request=route.request(), url=new URL(request.url());
        if(url.origin!=='https://phone.test'){unexpected.push(url.origin+url.pathname);return route.abort();}
        if(url.pathname==='/admin/phone-orders')return route.fulfill({contentType:'text/html',body:html});
        const payload=request.postDataJSON(), query=Object.fromEntries(url.searchParams);calls.push({path:url.pathname,query,payload});
        if(request.method()==='POST')assert.equal(request.headers()['x-csrf-token'],'fixture-token');
        let result;
        if(url.pathname===urls.customers){
            const responseMatches=state.matches.slice(), hold=state.hold;state.hold=null;
            if(hold){hold.enter();await hold.gate;}
            result={success:true,branch:{value:query.branch},matches:responseMatches};
        }
        else if(url.pathname===urls.catalog)result={success:true,ready:true,items:[{id:1,name:'رنجة هيرنج',unit_price:'200.00',price:'200.00',quantity_mode:'weight',unit:'kg',available:true,options:[]}],categories:[],policy:{can_discount:false,payment_methods:['cash']},permissions:{can_checkout:true,can_operate:true},pagination:{page:1,last_page:1,total:1}};
        else if(url.pathname===urls.board)result={success:true,branch:query.branch,columns:Object.fromEntries(['preparing','courier','finished'].map(stage=>[stage,{items:[],pagination:{page:1,last_page:1,total:0}}])),companies:[]};
        else if(url.pathname===urls.delivery_settings)result={success:true,settings:{branch:branches.find(branch=>branch.value===query.branch),ready:true,latitude:30.9,longitude:31.1,km_price:'20.00'}};
        else if(url.pathname===urls.companies)result={success:true,items:[]};
        else if(url.pathname===urls.delivery_quote)result={success:true,delivery:{branch:payload.branch,latitude:payload.latitude,longitude:payload.longitude,delivery_fee:'50.00',distance_km:'2.50',km_price:'20.00',delivery_quote_hash:'d'.repeat(64),method:'direct'}};
        else if(url.pathname===urls.customer_save)result={success:true,customer:{id:payload.customer_id||72,revision:(payload.expected_revision||0)+1,branch:payload.branch,name:payload.name,phone:payload.phone,address:payload.address}};
        else {unexpected.push(url.pathname);return route.abort();}
        try{return await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});}catch(error){if(!request.failure()&&!page.isClosed())throw error;}
    });
    await page.goto('https://phone.test/admin/phone-orders');
    await page.waitForFunction(()=>document.querySelector('[data-phone-branch]').options[0].textContent!=='select_branch');
    if(selected)await page.locator('[data-phone-product="1"]').waitFor({state:'attached'});
    return {page,calls,errors,unexpected,state};
}

async function assertClean(ctx) {
    assert.deepEqual(ctx.errors,[], 'production JS has no browser runtime errors');
    assert.deepEqual(ctx.unexpected,[], 'all requests remain within isolated fixture endpoints');
    await ctx.page.close();
}
async function selectAcrossBranches(browser, enhanced) {
    const ctx=await setup(browser,{enhanced}), {page,calls}=ctx;
    await field(page,'customer_phone').fill('010123');
    const suggestion=page.locator('[data-phone-suggestion]').first();await suggestion.waitFor({state:'visible'});
    assert.equal(calls.filter(call=>call.path===urls.customers).at(-1).query.branch,'all');
    const text=await suggestion.textContent();for(const value of [savedCustomer.name,savedCustomer.phone,'المحلة'])assert(text.includes(value));
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'','lookup alone preserves all branches');
    await suggestion.click();await page.locator('#phone-orders.ph-step-details').waitFor();
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'f:101','selection changes the actual branch control');
    if(enhanced)assert.deepEqual(await page.evaluate(()=>window.selectizeUpdates),[{value:'f:101',silent:true}],'enhanced selector is synchronized without a second change event');
    for(const [key,value] of Object.entries({customer_name:savedCustomer.name,customer_phone:savedCustomer.phone,address:savedCustomer.address,area:savedCustomer.area,delivery_notes:savedCustomer.delivery_notes}))assert.equal(await field(page,key).inputValue(),value);
    assert.equal(await page.locator('[data-phone-lat]').inputValue(),String(savedCustomer.latitude));
    assert.equal(await page.locator('[data-phone-lng]').inputValue(),String(savedCustomer.longitude));
    await page.locator('[data-phone-product="1"]').waitFor({state:'attached'});
    for(const endpoint of [urls.catalog,urls.delivery_settings,urls.companies,urls.board])assert(calls.some(call=>call.path===endpoint&&call.query.branch==='f:101'),'selected branch context reloads '+endpoint);
    assert((await page.locator('[data-phone-selected-branch]').textContent()).includes('المحلة'));
    await page.locator('[data-phone-confirm-location]').click();
    await page.waitForFunction(()=>document.querySelector('[data-phone-distance]').textContent.includes('تم تأكيد الدبوس'));
    await page.locator('[data-phone-next-details]').click();await page.locator('#phone-orders.ph-step-products').waitFor();
    const save=calls.find(call=>call.path===urls.customer_save).payload;
    assert.equal(save.branch,'f:101');assert.equal(save.customer_id,71);assert.equal(save.expected_revision,6,'selection preserves the existing branch customer revision');
    assert.equal(save.phone,savedCustomer.phone);assert.equal(Number(save.latitude),savedCustomer.latitude);assert.equal(Number(save.longitude),savedCustomer.longitude);
    assert.equal(calls.filter(call=>call.path===urls.customers).length,1,'preserved customer identity avoids a second lookup before saving');
    await assertClean(ctx);
}
async function explicitBranchAndExactEnter(browser) {
    const record={...savedCustomer,branch:'f:100',branch_name:'المنصورة'};
    const ctx=await setup(browser,{selected:'f:100',matches:[record]}), {page,calls}=ctx;
    await field(page,'customer_phone').fill(savedCustomer.phone);await field(page,'customer_phone').press('Enter');
    await page.waitForFunction(()=>document.querySelector('[data-phone-field="customer_name"]').value==='أحمد عميل المحلة');
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'f:100');
    const lookup=calls.filter(call=>call.path===urls.customers).at(-1);assert.equal(lookup.query.branch,'f:100');assert.equal(lookup.query.prefix,'0');
    await assertClean(ctx);
    const exact=await setup(browser), phone=field(exact.page,'customer_phone');await phone.fill(savedCustomer.phone);await phone.press('Enter');
    await exact.page.waitForFunction(()=>document.querySelector('[data-phone-branch]').value==='f:101');
    assert.equal(await field(exact.page,'customer_name').inputValue(),savedCustomer.name,'an exact Enter lookup also switches the branch');
    assert.equal(exact.calls.filter(call=>call.path===urls.customers).at(-1).query.prefix,'0');await assertClean(exact);
}
async function staleAndUnrecognizedBranches(browser) {
    const ctx=await setup(browser), {page,state,calls}=ctx;
    await page.evaluate(()=>{window.ignoreCustomerAbort=true;});const hold=state.holdNextLookup();
    await field(page,'customer_phone').fill('010111');await hold.entered;
    await page.locator('[data-phone-branch]').selectOption('f:100');await page.locator('[data-phone-product="1"]').waitFor({state:'attached'});
    const staleResponse=page.waitForResponse(response=>new URL(response.url()).pathname===urls.customers&&new URL(response.url()).searchParams.get('phone')==='010111');
    hold.release();await staleResponse;await page.evaluate(()=>new Promise(requestAnimationFrame));
    assert.equal(await page.locator('[data-phone-suggestion]').count(),0,'late all-branch response is ignored after a manual branch change');
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'f:100');assert.equal(await field(page,'customer_name').inputValue(),'');
    await page.locator('[data-phone-branch]').selectOption('');state.matches=[savedCustomer];
    await field(page,'customer_phone').fill('010123');const staleButton=await page.locator('[data-phone-suggestion]').first().elementHandle();
    await field(page,'customer_phone').fill('010999');await staleButton.evaluate(button=>button.dispatchEvent(new MouseEvent('click',{bubbles:true})));
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'','stale suggestion cannot select a branch after the number changes');
    await field(page,'customer_phone').fill('01');state.matches=[{...savedCustomer,branch:'f:999',branch_name:'فرع غير مصرح'}];
    await field(page,'customer_phone').fill('010000');const unavailable=page.locator('[data-phone-suggestion]').first();await unavailable.waitFor({state:'visible'});
    assert(await unavailable.isDisabled(),'unknown branch suggestions cannot be selected');
    await unavailable.evaluate(button=>button.dispatchEvent(new MouseEvent('click',{bubbles:true})));
    assert.equal(await page.locator('[data-phone-branch]').inputValue(),'');assert.equal(await field(page,'customer_name').inputValue(),'');
    assert(!calls.some(call=>call.query.branch==='f:999'),'unknown branch never loads a menu or delivery context');await assertClean(ctx);
}

(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});
    try{
        await selectAcrossBranches(browser,false);await selectAcrossBranches(browser,true);
        await explicitBranchAndExactEnter(browser);await staleAndUnrecognizedBranches(browser);
        console.log('PHONE_CUSTOMER_AUTO_BRANCH_UI_OK: all-branch suggestions; native/selectize auto-selection; branch catalog/context/board reload; existing customer revision/location reuse; exact Enter; explicit branch; stale and unknown branch guards.');
    }finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exitCode=1;});
