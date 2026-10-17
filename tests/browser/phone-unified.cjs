'use strict';
// Unified production Blade/CSS/JS against isolated HTTP and map fixtures.
// No real customers, orders, messages, navigation or printing.
const assert = require('node:assert/strict'), fs = require('node:fs'), os = require('node:os'), path = require('node:path'), cp = require('node:child_process');
const {chromium} = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '../..'), tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'phone-unified-'));
const php = process.env.CRM_TEST_PHP || 'php';
assert(process.env.CRM_TEST_AUTOLOAD && fs.existsSync(process.env.CRM_TEST_AUTOLOAD), 'isolated Laravel autoload is required');
const urls = Object.fromEntries(['catalog', 'quote', 'tickets', 'save', 'recover', 'board', 'delivery-settings', 'delivery-quote', 'address-suggestions', 'dispatch-company', 'finish-batch'].map(name => [name.replaceAll('-', '_'), '/admin/phone-orders/' + name]));
Object.assign(urls, {show:'/admin/phone-orders/tickets/__TICKET__', action:'/admin/phone-orders/tickets/__TICKET__/action', settle:'/admin/phone-orders/tickets/__TICKET__/settle', customer_save:'/admin/customers/save', customers:'/admin/phone-orders/customers', companies:'/admin/delivery-companies/data', operations_recover:'/admin/branch-operations/recover', 'finish-batch':'/admin/phone-orders/finish-batch', 'dispatch-company':'/admin/phone-orders/dispatch-company'});
const baseBoot = {branches:[{id:100, value:'f:100', name:'المحلة', kind:'f'}], selected_branch:'f:100', permissions:{can_checkout:true, can_operate:true}, can_view_all_branches:true, receiver_branch:null, company_filter:0, open_phone_maps:false, maps_key:'isolated-map-fixture', urls};
fs.writeFileSync(path.join(tmp, 'boot.json'), JSON.stringify(baseBoot));
const render = `require getenv('CRM_TEST_AUTOLOAD');
$c=new \\Illuminate\\Container\\Container; \\Illuminate\\Container\\Container::setInstance($c);
$l=new \\Illuminate\\Translation\\ArrayLoader; $locale=getenv('QA_LOCALE')?:'ar';
$labels=require getenv('QA_ROOT').'/resources/lang/'.$locale.'/phone_orders.php';
$l->addMessages($locale,'phone_orders',$labels); $c->instance('translator',new \\Illuminate\\Translation\\Translator($l,$locale));
if(!function_exists('__')){function __($key,$replace=[],$locale=null){return \\Illuminate\\Container\\Container::getInstance()->make('translator')->get($key,$replace,$locale);}}
$phonePos=json_decode(file_get_contents(getenv('QA_BOOT')),true);
$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/phone_orders/index.blade.php');
$blade=explode("@section('content')",$blade,2)[1]; $blade=explode('@endsection',$blade,2)[0];
$compiler=new \\Illuminate\\View\\Compilers\\BladeCompiler(new \\Illuminate\\Filesystem\\Filesystem,getenv('QA_CACHE'));
$__env=new class { use \\Illuminate\\View\\Concerns\\ManagesLoops; };
ob_start(); eval('?>'.$compiler->compileString($blade)); $content=ob_get_clean();
echo json_encode(['content'=>$content,'labels'=>$labels],JSON_UNESCAPED_UNICODE);`;

function renderPage(locale,boot) {
  fs.writeFileSync(path.join(tmp, 'boot.json'),JSON.stringify(boot));
  return JSON.parse(cp.execFileSync(php,['-r',render],{encoding:'utf8',env:{...process.env,QA_ROOT:root,QA_BOOT:path.join(tmp,'boot.json'),QA_CACHE:tmp,QA_LOCALE:locale}}));
}
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const font = fs.readFileSync(path.join(root, 'public/dashboard/vendor/desktop-external/fonts.gstatic.com/s/almarai/v19/tsstApxBaigK_hnnQ1iFo0C3.woff2')).toString('base64');
const chrome = `@font-face{font-family:Almarai;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}*{box-sizing:border-box}html,body{margin:0;font-family:Almarai,Arial,sans-serif}label{font-weight:700}header.qa-top{height:58px;background:#081727;color:white;display:flex;align-items:center;padding:0 280px 0 20px}aside.qa-sidebar{position:fixed;right:0;top:0;bottom:0;width:250px;background:#081727;color:#fff;padding:35px 20px;box-sizing:border-box}aside.qa-sidebar h2{color:#ff7900}aside.qa-sidebar p{padding:14px 8px;border-bottom:1px solid #ffffff15;font-size:12px}.content-wrapper{margin-right:250px}footer.qa-footer{height:51px;background:#081727;color:white;margin-right:250px;padding:15px;box-sizing:border-box;font-size:11px}i{display:inline-block;min-width:1em}@media(max-width:599px){aside.qa-sidebar{display:none}.content-wrapper,footer.qa-footer{margin-right:0}header.qa-top{padding:0 15px}}`;
const safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');

const products = Array.from({length:18}, (_, i) => ({id:i+1, name:['رنجة هيرنج','سندوتش فسيخ مظبوط','وجبة سردين'][i] || 'صنف تجريبي طويل الاسم ' + (i+1), unit_price:i===0?'200.00':'100.00', price:i===0?'200.00':'100.00', quantity_mode:i===0?'weight':'piece', unit:i===0?'kg':'piece', available:true, options:[]}));
function fixtureTicket(id, stage='courier') {
    return {id, number:'TEST-'+id, channel:'phone', revision:1, status:stage==='courier'?'out_for_delivery':stage==='finished'?'finished':'new', payment_status:stage==='finished'?'paid':'unpaid', branch:{value:'f:100', name:'المحلة'}, customer_name:'عميل محفوظ '+id, customer_phone:'01012345678', address:'شارع الجمهورية بجوار المدرسة، الدور الثالث', area:'المحلة', created_at:'2026-10-17T15:00:00Z', courier_company:{id:1, name:'شركة الاختبار'}, quote_hash:'b'.repeat(64), total:'1250.50', subtotal:'1250.50', discount:'0.00', delivery_fee:'0.00', tax:'0.00', service:'0.00', bill_print_url:'/admin/phone-orders/tickets/'+id+'/print', items:[{product_id:2, name:'سندوتش فسيخ مظبوط', quantity:'2', quantity_mode:'piece', unit_price:'625.25', total:'1250.50'}]};
}
function fixtureQuote(payload, tickets, catalogProducts=products) {
    if (payload.ticket_id && !payload.items) return {...tickets.get(Number(payload.ticket_id)), success:true};
    let subtotal = 0;
    const items = payload.items.map(item => {
        const product=catalogProducts.find(p=>p.id===item.product_id),choices=product&&(product.preparation_options||[{id:'',label:'',price:product.price}].concat(product.options||[])),choice=choices&&choices.find(option=>String(option.id||'')===String(item.option_id||''));
        const saved=(payload.ticket_id?[tickets.get(Number(payload.ticket_id))]:[...tickets.values()]).filter(Boolean).flatMap(ticket=>ticket.items).find(line=>line.product_id===item.product_id&&String(line.option_id||'')===String(item.option_id||''));
        assert(choice||saved,'quoted choice must be configured or belong to the saved ticket');
        const price=choice?choice.price:saved.unit_price,label=choice?(item.option_id||product.preparation_options&&product.preparation_options.length>1?choice.label||'':''):saved.option_label||'',total=Number(price)*Number(item.quantity);
        subtotal+=total;return {...item,option_id:item.option_id||'',option_label:label,name:product?.name||saved.name,unit_price:price,total:total.toFixed(2)};
    });
    return {success:true, items, quote_hash:'a'.repeat(64), subtotal:subtotal.toFixed(2), total:(subtotal+Number(payload.delivery_fee||0)-Number(payload.discount||0)).toFixed(2), delivery_fee:payload.delivery_fee||'0.00', discount:payload.discount||'0.00', discount_reason:payload.discount_reason||'', tax:'0.00', service:'0.00', tax_rate:'0'};
}
async function setup(browser, {failFirstSave=false, failFirstSettle=false, failFirstBatch=false, catalogProducts=products, viewport={width:1920,height:1006},locale='ar',query='',multiBranch=false,deliveryPricing=null}={}) {
    const boot={...baseBoot,branches:multiBranch?[...baseBoot.branches,{id:101,value:'f:101',name:'نبروه',kind:'f'}]:baseBoot.branches},rendered=renderPage(locale,boot);
    const branchRecord=value=>boot.branches.find(branch=>branch.value===value)||boot.branches[0];
    const network={board:null,catalog:null};
    function holdResponse(kind,branch){let enter,release;const entered=new Promise(resolve=>{enter=resolve;}),gate=new Promise(resolve=>{release=resolve;});const hold={branch,entered,gate,enter,release};network[kind]=hold;return hold;}
    network.holdBoard=()=>holdResponse('board');network.holdCatalog=branch=>holdResponse('catalog',branch);
    const html = '<!doctype html><html lang="' + locale + '" dir="' + (locale==='ar'?'rtl':'ltr') + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>' + chrome + '</style><style>' + read('public/dashboard/css/phone-orders.css') + '</style></head><body class="dashboard-theme dashboard-phone-orders-page"><header class="qa-top">معاينة شاشة الدليفري</header><aside class="qa-sidebar"><h2>Fasakhansta</h2><p>الرئيسية</p><p>طلبات التطبيق</p><p>طلبات التيك أواي</p><p>طلبات الدليفري</p><p>طلبات الصالة</p><p>قائمة العملاء</p></aside>' + rendered.content + '<footer class="qa-footer">معاينة اختبار ببيانات تجريبية</footer><script type="application/json" id="phone-orders-bootstrap">' + safe(boot) + '</script><script type="application/json" id="phone-orders-translations">' + safe(rendered.labels) + '</script><script>' + read('public/dashboard/js/phone-address-search.js') + '</script><script>' + read('public/dashboard/js/phone-orders.js') + '</script></body></html>';
    const page = await browser.newPage({viewport}), calls = [], errors = [], unexpected = [];
    const tickets=new Map();
    for(const [stage,offset] of [['preparing',500],['courier',100],['finished',300]])for(let n=1;n<=20;n++)tickets.set(offset+n,fixtureTicket(offset+n,stage));
    if(multiBranch)for(const [stage,offset] of [['preparing',1500],['courier',1100],['finished',1300]])for(let n=1;n<=20;n++)tickets.set(offset+n,{...fixtureTicket(offset+n,stage),branch:{value:'f:101',name:'نبروه'},courier_company:stage==='courier'?{id:2,name:'شركة نبروه'}:null});
    page.setDefaultTimeout(12000);
    let saveFailures=failFirstSave?1:0, settleFailures=failFirstSettle?1:0, batchFailures=failFirstBatch?1:0;
    page.on('pageerror', error=>errors.push(error.message)); page.on('dialog', dialog=>dialog.accept());
    await page.addInitScript(() => {
        window.phoneBoardRequests = [];window.phoneBoardAborts=0;
        document.addEventListener('DOMContentLoaded',()=>{window.phoneBoardRequestsAtReady=window.phoneBoardRequests.length;},{once:true});
        const realFetch=window.fetch; window.fetch=function(input,...args){if(String(input).includes('/phone-orders/board')){const entry={at:performance.now(),aborted:false};window.phoneBoardRequests.push(entry);if(args[0]?.signal)args[0].signal.addEventListener('abort',()=>{entry.aborted=true;window.phoneBoardAborts++;},{once:true});}return realFetch.call(this,input,...args);};
        window.phonePrintCalls = []; window.DashboardPrint = {print:async url=>window.phonePrintCalls.push(url)};
        window.DashboardLocationPicker = {valid:(lat,lng)=>lat!==''&&lng!==''&&Number.isFinite(Number(lat))&&Number.isFinite(Number(lng))&&Math.abs(Number(lat))<=90&&Math.abs(Number(lng))<=180, create:(_node,options)=>({resize(){},destroy(){},clear(){},invalidate(){},set(){},route(){},geocode:async()=>null,suggest:async query=>[{label:query,area:'المحلة',latitude:30.12,longitude:31.25}],resolve:async item=>item})};
    });
    await page.route('**/*', async route => {
        const request=route.request(), url=new URL(request.url());
        if(url.origin!=='https://phone.test'){unexpected.push(url.origin+url.pathname);return route.abort();}
        if(url.pathname==='/admin/phone-orders')return route.fulfill({contentType:'text/html',body:html});
        const payload=request.postDataJSON(); calls.push({path:url.pathname,method:request.method(),payload});
        if(request.method()==='POST')assert.equal(request.headers()['x-csrf-token'],'fixture-token');
        const kind=url.pathname===urls.board?'board':url.pathname===urls.catalog?'catalog':null,hold=kind&&network[kind];
        if(hold&&(!hold.branch||hold.branch===url.searchParams.get('branch'))){network[kind]=null;hold.enter();await hold.gate;}
        let result;
        if(url.pathname===urls.catalog)result={success:true,ready:true,items:catalogProducts,categories:[{id:1,name:'الأطباق'},{id:2,name:'السندوتشات'}],policy:{can_discount:true,payment_methods:['cash']},permissions:{can_checkout:true},pagination:{page:1,last_page:1,total:catalogProducts.length}};
        else if(url.pathname===urls.delivery_settings)result={success:true,settings:{branch:branchRecord(url.searchParams.get('branch')),ready:true,latitude:30.1,longitude:31.2,km_price:'20.00',...(deliveryPricing?{pricing_rates:[{delivery:deliveryPricing.delivery_fee}]}:{})}};
        else if(url.pathname===urls.companies){const branch=url.searchParams.get('branch');result={success:true,items:[{id:branch==='f:101'?2:1,branch,name:branch==='f:101'?'شركة نبروه':'شركة الاختبار'}]};}
        else if(url.pathname===urls.customers)result={success:true,matches:[]};
        else if(url.pathname===urls.delivery_quote)result={success:true,delivery:{branch:payload.branch,latitude:30.12,longitude:31.25,delivery_fee:'50.00',distance_km:'2.50',km_price:'20.00',delivery_quote_hash:'d'.repeat(64),method:'direct',...(deliveryPricing||{})}};
        else if(url.pathname===urls.customer_save)result={success:true,customer:{id:7,revision:1,branch:payload.branch,name:payload.name,phone:payload.phone,address:payload.address}};
        else if(url.pathname===urls.quote)result=fixtureQuote(payload,tickets,catalogProducts);
        else if(url.pathname===urls.save){
            if(saveFailures-->0)return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false})});
            const quote=fixtureQuote(payload,tickets,catalogProducts),id=payload.ticket_id||801,ticket={...fixtureTicket(id,'preparing'),...quote,quote_hash:payload.quote_hash,id,revision:payload.expected_revision?payload.expected_revision+1:1,channel:'phone',status:'new',payment_status:'unpaid',branch:{value:payload.branch,name:'المحلة'},customer_name:payload.customer_name,customer_phone:payload.customer_phone,address:payload.address,notes:payload.notes,kitchen_sent:true,service_consent:payload.service_consent,marketing_consent:payload.marketing_consent,delivery_location:{branch:payload.branch,latitude:payload.latitude,longitude:payload.longitude,delivery_fee:payload.delivery_fee,distance_km:'2.50',km_price:'20.00',delivery_quote_hash:payload.delivery_quote_hash,method:'direct'}};
            tickets.set(ticket.id,ticket);result={success:true,ticket,operation:{idempotency_key:payload.idempotency_key,ticket_id:ticket.id},print_queued:true};
        }
        else if(url.pathname===urls.board){const columns={};for(const stage of ['preparing','courier','finished']){const scope=url.searchParams.get('branch'),items=[...tickets.values()].filter(t=>(scope==='all'||t.branch.value===scope)&&(t.payment_status==='paid'?'finished':t.status==='out_for_delivery'?'courier':'preparing')===stage);columns[stage]={items,pagination:{page:1,last_page:1,total:items.length}};}result={success:true,branch:url.searchParams.get('branch'),columns,companies:boot.branches.filter(branch=>url.searchParams.get('branch')==='all'||branch.value===url.searchParams.get('branch')).map(branch=>({id:branch.value==='f:101'?2:1,branch:branch.value,name:branch.value==='f:101'?'شركة نبروه':'شركة الاختبار'}))};}
        else if(url.pathname===urls['dispatch-company']){const ticket=tickets.get(payload.ticket_id);Object.assign(ticket,{status:'out_for_delivery',revision:ticket.revision+1,courier_company:{id:payload.company_id,name:'شركة الاختبار'}});result={success:true,branch:payload.branch,ticket_id:ticket.id,revision:ticket.revision,company:ticket.courier_company,operation:{kind:'phone.dispatch',idempotency_key:payload.idempotency_key}};}
        else if(url.pathname===urls.recover||url.pathname===urls.operations_recover)result={success:true,found:false};
        else if(/\/tickets\/[0-9]+$/.test(url.pathname))result={success:true,ticket:tickets.get(Number(url.pathname.match(/[0-9]+$/)[0]))};
        else if(/\/tickets\/[0-9]+\/settle$/.test(url.pathname)){
            if(settleFailures-->0)return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false})});
            const id=Number(url.pathname.match(/\/([0-9]+)\/settle$/)[1]),ticket={...tickets.get(id),payment_status:'paid',status:'finished',revision:2};tickets.set(id,ticket);
            result={success:true,ticket,operation:{idempotency_key:payload.idempotency_key,ticket_id:id},receipt:{id:2001,branch:{value:payload.branch},idempotency_key:payload.idempotency_key,total:ticket.total},receipt_url:'/admin/takeaway/receipts/2001/print'};
        }
        else if(url.pathname===urls['finish-batch']){
            if(batchFailures-->0)return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false})});
            payload.items.forEach(item=>tickets.set(item.id,{...tickets.get(item.id),payment_status:'paid',status:'finished',revision:2}));
            result={success:true,operation:{idempotency_key:payload.idempotency_key,kind:'phone.batch'},batch:{id:3001,branch:{value:payload.branch},idempotency_key:payload.idempotency_key,total:payload.total,items:payload.items.map(item=>({ticket_id:item.id})),print_url:'/admin/phone-orders/batches/3001/print'}};
        }
        else {unexpected.push(url.pathname);return route.abort();}
        try{return await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});}catch(error){if(!request.failure()&&!page.isClosed())throw error;}
    });
    await page.goto('https://phone.test/admin/phone-orders'+query);await page.locator('[data-phone-product="1"]').waitFor({state:'attached'});
    return {page,calls,errors,unexpected,tickets,labels:rendered.labels,network};
}
const field=(page,name)=>page.locator('[data-phone-field="'+name+'"]');
const rows=page=>page.locator('[data-phone-lines] tr');
async function focused(control,message){assert(await control.evaluate(el=>document.activeElement===el),message);}
async function amount(page,value){await page.locator('[data-phone-key="clear"]').click();for(const key of value)await page.locator('[data-phone-key="'+key+'"]').click();}
async function quoteReady(page){await page.waitForFunction(()=>document.querySelector('[data-phone-total="total"]').textContent!=='—');}
async function serviceApprovalRequired(ctx,saveCount=0){
    const {page,calls}=ctx,consent=page.locator('[data-phone-customer-consent]'),save=page.locator('[data-phone-save]');await quoteReady(page);assert(!await consent.isChecked());assert(await save.isDisabled(),'unchecked required service approval disables saving');await page.keyboard.press('F9');await focused(consent,'F9 focuses required service approval instead of sending');assert.equal(calls.filter(call=>call.path===urls.save).length,saveCount,'F9 cannot bypass required service approval');await save.evaluate(button=>button.click());assert.equal(calls.filter(call=>call.path===urls.save).length,saveCount,'the save button cannot send an unchecked draft');
}
async function prepareCustomer(page,beforeNextDetails){
    const phone=field(page,'customer_phone');assert.equal(await phone.count(),1,'phone identity has one input throughout the workflow');await phone.fill('01012345678');await phone.press('Enter');await page.locator('#phone-orders.ph-step-details').waitFor();
    await focused(field(page,'customer_name'),'phone Enter preserves the customer-details step');await unifiedVisible(page,'details');
    assert.equal(await phone.count(),1,'details move the original phone input without cloning it');
    if(page.viewportSize().width>=900){const nameBox=await field(page,'customer_name').boundingBox(),phoneBox=await phone.boundingBox();assert(Math.abs(nameBox.y-phoneBox.y)<2,'details show customer name and phone on the same row');}
    await field(page,'customer_name').fill('عميل اختبار الدليفري');await field(page,'address').fill('شارع الجمهورية، المحلة الكبرى');await field(page,'area').fill('المحلة');
    await page.locator('[data-phone-lat]').fill('30.12');await page.locator('[data-phone-lng]').fill('31.25');await page.locator('[data-phone-confirm-location]').click();
    await page.waitForFunction(()=>document.querySelector('[data-phone-distance]').textContent.includes('تم تأكيد الدبوس'));
    if(beforeNextDetails)await beforeNextDetails();
    await page.locator('[data-phone-next-details]').click();await page.locator('#phone-orders.ph-step-products').waitFor();await page.waitForFunction(()=>!document.querySelector('[data-phone-product="1"]').disabled);await unifiedVisible(page,'products');
}
async function draftSnapshot(page){return page.evaluate(()=>({lines:[...document.querySelectorAll('[data-phone-lines] tr')].map(row=>({text:row.textContent,quantity:row.querySelector('[data-phone-line-quantity]').value,option:row.querySelector('[data-phone-line-option]')?.value||''})),fields:[...document.querySelectorAll('[data-phone-field]')].map(input=>[input.dataset.phoneField,input.value]),summary:document.querySelector('[data-phone-customer-summary]').textContent,totals:[...document.querySelectorAll('[data-phone-total]')].map(total=>[total.dataset.phoneTotal,total.textContent]),consent:document.querySelector('[data-phone-customer-consent]').checked,quantity:document.querySelector('[data-phone-quantity]').value}));}
async function blockedEscapes(control, assertBlocked){for(const options of [{repeat:true},{isComposing:true},{keyCode:229},{ctrlKey:true},{altKey:true},{metaKey:true},{shiftKey:true}]){await control.dispatchEvent('keydown',{key:'Escape',code:'Escape',bubbles:true,...options});await assertBlocked();}}
async function screenshot(page,name){if(process.env.PHONE_QA_SCREENSHOTS){fs.mkdirSync(process.env.PHONE_QA_SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.PHONE_QA_SCREENSHOTS,name+'.png'),fullPage:true});}}

async function unifiedVisible(page,step){
    assert(await page.locator('#phone-orders.ph-unified.ph-step-'+step).isVisible(),'unified root retains the current composer step');
    assert(await page.locator('[data-phone-compose]').isVisible(),'composer stays visible in '+step);
    assert.equal(await page.locator('[data-phone-orders]').isVisible(),step!=='products','delivery tracking shows only in the phone and customer steps');
    assert.equal(await page.locator('[data-phone-view]').count(),0,'view tabs are removed');
    assert.equal(await page.locator('[data-phone-column]').count(),3,'all delivery stages render together');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'unified layout has no horizontal page overflow');
    const layout=await page.evaluate(()=>{const rect=selector=>{const r=document.querySelector(selector).getBoundingClientRect();return{top:r.top,bottom:r.bottom,width:r.width,height:r.height};};return{compose:rect('[data-phone-compose]'),board:rect('[data-phone-orders]'),viewport:{width:innerWidth,height:innerHeight}};});
    console.log('phone-unified-layout',JSON.stringify({step,...layout}));
    if(page.viewportSize().width>=1000&&step==='products'){
        assert(layout.compose.height>=layout.viewport.height-280,'product composer expands into the tracking area');
        assert.equal(layout.board.height,0,'hidden tracking consumes no product workspace');
    }else if(page.viewportSize().width>=1000){
        assert(layout.board.top<layout.viewport.height-80,'delivery tracking remains in the desktop viewport');
        assert(layout.board.height>=150,'desktop board retains usable tracking height');
        assert(layout.board.bottom<=layout.viewport.height,'desktop board is not clipped below the viewport');
    }
}
async function clean(ctx){assert.deepEqual(ctx.errors,[],'no browser runtime errors');assert.deepEqual(ctx.unexpected,[],'all network requests stay within isolated fixtures');}
async function liveTracking(browser){
    const ctx=await setup(browser),{page,calls,tickets,labels}=ctx;
    await page.locator('[data-phone-ticket="501"]').waitFor();await unifiedVisible(page,'number');
    assert(await page.evaluate(()=>window.phoneBoardRequestsAtReady>0),'initial board fetch starts during page initialization before polling');
    assert.equal(await page.locator('[data-phone-new-order]').count(),1,'there is one explicit new-order action');
    await prepareCustomer(page);await amount(page,'.375');await page.locator('[data-phone-product="1"]').click();await quoteReady(page);
    await field(page,'notes').fill('طلب جديد يظل محفوظًا أثناء المتابعة');await amount(page,'6.25');
    const before=await draftSnapshot(page);
    const held=ctx.network.holdBoard();await page.evaluate(()=>document.dispatchEvent(new CustomEvent('dashboard:branch-print',{detail:{status:'ready',latest:'late-products'}})));await held.entered;
    tickets.get(101).customer_name='تحديث متأخر أثناء اختيار الأصناف';tickets.get(101).revision++;held.release();
    await page.waitForFunction(()=>document.querySelector('[data-phone-ticket=\"101\"]')?.textContent.includes('تحديث متأخر أثناء اختيار الأصناف'));
    assert(await page.locator('[data-phone-orders]').isHidden(),'late tracking responses never reveal the board in products');
    assert.deepEqual(await draftSnapshot(page),before,'background tracking preserves the product draft');
    await page.locator('[data-phone-edit-customer]').click();await unifiedVisible(page,'details');
    const view=page.locator('[data-phone-ticket="101"] .ph-ticket-quick-actions button').first();
    await view.focus();await view.press('F9');await focused(view,'F9 on a delivery-board control cannot submit or leave the board');assert.deepEqual(await draftSnapshot(page),before,'board F9 preserves the composer draft');assert.equal(calls.filter(call=>call.path===urls.save).length,0,'board F9 cannot send a pending composer invoice');
    await view.press('Enter');await page.locator('[data-phone-modal]').waitFor({state:'visible'});
    await page.locator('[data-phone-modal-content]').getByText('01012345678',{exact:true}).waitFor();
    assert(await page.locator('[data-phone-modal-content]').getByText('01012345678',{exact:true}).isVisible(),'Enter activates the board details button while the existing product draft remains in the customer step');
    await page.keyboard.press('Escape');await page.locator('[data-phone-modal]').waitFor({state:'hidden'});
    assert.deepEqual(await draftSnapshot(page),before,'opening/closing another order preserves draft and pending quantity');

    const preparing=page.locator('[data-phone-ticket="501"]');
    assert.deepEqual(await preparing.locator('.ph-ticket-quick-actions button').allTextContents(),[labels.view_order,labels.edit_order,labels.assign_company],'preparing has only view, edit and delivery assignment');
    await preparing.getByRole('button',{name:labels.assign_company,exact:true}).click();
    await page.locator('[data-phone-board-company="501"]').selectOption('1');
    await page.locator('[data-phone-column="courier"] [data-phone-ticket="501"]').waitFor();
    assert(calls.some(call=>call.path===urls['dispatch-company']),'assignment writes through the existing endpoint');
    assert.deepEqual(await draftSnapshot(page),before,'dispatching another order preserves the composer draft');

    // Quiet updates may change saved tickets but must preserve editing focus and queue scroll.
    await field(page,'customer_name').focus();
    const scroll=await page.locator('[data-phone-column="courier"] .ph-board-cards').evaluate(el=>{el.scrollTop=Math.min(120,el.scrollHeight-el.clientHeight);return el.scrollTop;});
    assert(scroll>0,'fixture queue is long enough to exercise scroll restoration');
    const updated=tickets.get(102);updated.customer_name='اسم محدث أثناء متابعة الطلبات';updated.revision++;
    const fetches=calls.filter(call=>call.path===urls.board).length;
    await page.waitForFunction(count=>window.phoneBoardRequests.length>count,fetches,{timeout:7000});
    await page.waitForFunction(()=>document.querySelector('[data-phone-ticket="102"]')?.textContent.includes('اسم محدث أثناء متابعة الطلبات'),null,{timeout:7000});
    await focused(field(page,'customer_name'),'quiet refresh preserves focused composer input');
    assert.equal(await page.locator('[data-phone-column="courier"] .ph-board-cards').evaluate(el=>el.scrollTop),scroll,'quiet refresh restores the same column scroll offset');
    assert.deepEqual(await draftSnapshot(page),before,'live tracking never alters the current draft or pending quantity');

    // A focused tracking control must remain connected during quiet updates.
    const focusedBoard=page.locator('[data-phone-ticket="101"] .ph-ticket-quick-actions button').first();
    await focusedBoard.focus();await focusedBoard.evaluate(el=>{window.phoneFocusedBoard=el;});
    await page.waitForTimeout(4300);
    assert(await page.evaluate(()=>window.phoneFocusedBoard.isConnected&&document.activeElement===window.phoneFocusedBoard),'quiet polling retains focused tracking controls');
    assert.deepEqual(await draftSnapshot(page),before);

    await page.locator('[data-phone-next-details]').click();await page.locator('#phone-orders.ph-step-products').waitFor();await page.waitForFunction(()=>!document.querySelector('[data-phone-product=\"1\"]').disabled);
    await page.locator('[data-phone-customer-consent]').check();await page.waitForFunction(()=>!document.querySelector('[data-phone-save]').disabled);
    const previous=calls.filter(call=>call.path===urls.board).length;
    await page.locator('[data-phone-save]').click();await page.locator('#phone-orders.ph-step-number').waitFor();
    assert(calls.filter(call=>call.path===urls.board).length>previous,'saved phone order refreshes the persistent board in the confirmed-write callback');
    await page.locator('[data-phone-column="preparing"] [data-phone-ticket="801"]').waitFor();
    assert.equal(await field(page,'customer_phone').inputValue(),'','successful send resets the composer for the next call');
    assert.equal(await rows(page).count(),0);assert(!await page.locator('[data-phone-customer-consent]').isChecked());
    assert.equal(calls.filter(call=>call.path===urls.save).length,1,'one save creates one order');
    assert.deepEqual(await page.evaluate(()=>window.phonePrintCalls),[],'queued branch printing is not duplicated in the browser');
    await unifiedVisible(page,'number');await screenshot(page,'phone-unified-live-tracking');await clean(ctx);await page.close();
}
async function defaultProductQuantities(browser){
    const ctx=await setup(browser),{page,calls}=ctx;await prepareCustomer(page);
    const weight=page.locator('[data-phone-product="1"]'),piece=page.locator('[data-phone-product="2"]'),pending=page.locator('[data-phone-quantity]');
    await piece.click();await piece.click();await weight.click();await weight.click();await quoteReady(page);
    assert.equal(await rows(page).nth(0).locator('[data-phone-line-quantity]').inputValue(),'2','repeated plain product clicks add one piece per click');
    assert.equal(await rows(page).nth(1).locator('[data-phone-line-quantity]').inputValue(),'2.000','weight products default to one kilogram per click');
    assert.equal(await pending.inputValue(),'1','successful adds leave the next default unit ready');
    await amount(page,'.375');await weight.click();await quoteReady(page);
    assert.equal(await rows(page).nth(1).locator('[data-phone-line-quantity]').inputValue(),'2.375','explicit fractional kilograms remain authoritative');
    await piece.focus();await page.keyboard.press('3');assert.equal(await pending.inputValue(),'3','typing after an add replaces the default one');await piece.click();await quoteReady(page);
    assert.equal(await rows(page).nth(0).locator('[data-phone-line-quantity]').inputValue(),'5','explicit piece quantity is added without replacement');
    await amount(page,'6.25');const snapshot=await draftSnapshot(page);await blockedEscapes(pending,async()=>assert.equal(await pending.inputValue(),'6.25'));
    await pending.press('Escape');assert.equal(await pending.inputValue(),'0','plain Escape still clears only the pending quantity');
    assert.deepEqual(await draftSnapshot(page),{...snapshot,quantity:'0'},'Escape preserves products, customer and prices');
    await piece.click();await quoteReady(page);assert.equal(await rows(page).nth(0).locator('[data-phone-line-quantity]').inputValue(),'6','a zero pending quantity falls back to one piece');
    await pending.fill('');await weight.click();await quoteReady(page);assert.equal(await rows(page).nth(1).locator('[data-phone-line-quantity]').inputValue(),'3.375','an empty pending quantity falls back to one kilogram');
    await amount(page,'.5');const lines=await rows(page).allTextContents();await piece.click();assert.deepEqual(await rows(page).allTextContents(),lines,'fractional piece quantities remain invalid');
    assert.equal(await pending.inputValue(),'0.5');assert(!calls.some(call=>call.path===urls.save),'quantity shortcuts never send an order');
    await clean(ctx);await page.close();

    const catalogProducts=products.map(product=>product.id===1?{...product,preparation_options:[{id:'',label:'كامل',price:'200.00'},{id:'f:0:extra_clean',label:'تنظيف',price:'220.00'}]}:product);
    const options=await setup(browser,{catalogProducts}),p=options.page;await prepareCustomer(p);
    const fish=p.locator('[data-phone-product="1"]'),choices=p.locator('.ph-option-list button');
    await fish.click();await choices.last().click();await fish.click();await choices.last().click();await quoteReady(p);
    assert.equal(await rows(p).first().locator('[data-phone-line-quantity]').inputValue(),'2.000','repeated preparation selections also add one configured unit');
    await amount(p,'.25');await fish.click();await p.keyboard.press('Escape');assert.equal(await p.locator('[data-phone-quantity]').inputValue(),'0.25','canceling the preparation picker preserves explicit quantity');
    await fish.click();await choices.first().click();await quoteReady(p);assert.equal(await rows(p).last().locator('[data-phone-line-quantity]').inputValue(),'0.250');
    assert.equal(await p.locator('[data-phone-quantity]').inputValue(),'1');await clean(options);await p.close();
}
async function failedProductSend(browser){
    const ctx=await setup(browser,{failFirstSave:true}),{page,calls}=ctx;await prepareCustomer(page);await page.locator('[data-phone-product="2"]').click();await quoteReady(page);
    await page.keyboard.press('F9');assert(!calls.some(call=>call.path===urls.save),'F9 cannot bypass required customer consent');
    await page.locator('[data-phone-customer-consent]').check();await page.waitForFunction(()=>!document.querySelector('[data-phone-save]').disabled);
    const before=await draftSnapshot(page);await page.locator('[data-phone-save]').click();await page.locator('[data-phone-message] [data-phone-retry]').waitFor({state:'visible'});
    await unifiedVisible(page,'products');assert.deepEqual(await draftSnapshot(page),before,'unconfirmed save keeps the complete product draft');
    await page.locator('[data-phone-message] [data-phone-retry]').click();await page.locator('#phone-orders.ph-step-number').waitFor();
    await page.locator('[data-phone-column="preparing"] [data-phone-ticket="801"]').waitFor();await unifiedVisible(page,'number');
    assert.equal(await rows(page).count(),0);assert.equal(await field(page,'customer_phone').inputValue(),'');
    const attempts=calls.filter(call=>call.path===urls.save);assert.equal(attempts.length,2);assert.deepEqual(attempts[0].payload,attempts[1].payload,'retry reuses the frozen payload and request key');
    await clean(ctx);await page.close();
}
async function branchPricingPresentation(browser){
    for(const [pricing_rule,delivery_fee,labelKey] of [['app_branch_tier','75.00','branch_tariff_fixed'],['app_zero_rate','0.00','branch_tariff_free'],['app_per_km','50.00','branch_tariff_per_km']]){
        const ctx=await setup(browser,{deliveryPricing:{method:'app_direct',pricing_rule,delivery_fee,road_distance_km:'8.11'}}),{page,labels}=ctx;
        await page.waitForFunction(hint=>document.querySelector('[data-phone-location-status]').textContent.includes(hint),labels.branch_tariff_hint);
        await prepareCustomer(page,async()=>{
            const text=await page.locator('[data-phone-distance]').innerText();assert(text.includes(labels.app_delivery_distance),'pricing names the customer-app distance');assert(text.includes(labels[labelKey]),'pricing describes the branch tariff rule');assert(text.includes(labels.route_display_distance)&&text.includes('8.11'),'road distance remains visibly separate for map display');assert.equal(await field(page,'delivery_fee').inputValue(),delivery_fee,'configured tariff fee is authoritative');
        });
        await page.locator('[data-phone-product="2"]').click();await quoteReady(page);assert.equal(ctx.calls.filter(call=>call.path===urls.quote).at(-1).payload.delivery_fee,delivery_fee,'product invoice uses the confirmed branch tariff');await clean(ctx);await page.close();
    }
    console.log('Phone branch pricing display: fixed tier, zero rate and per-km rules; customer-app fee versus road geometry passed.');
}
async function branchPermissionRace(browser){
    const ctx=await setup(browser,{multiBranch:true}),{page,network,labels}=ctx;
    await page.locator('[data-phone-ticket="501"]').waitFor();
    const held=network.holdCatalog('f:101');
    await page.locator('[data-phone-branch]').selectOption('f:101');await held.entered;
    const card=page.locator('[data-phone-ticket="1501"]');await card.waitFor();
    assert.equal(await card.locator('.ph-ticket-quick-actions button').count(),1,'branch board can render before its write permissions arrive');
    assert.equal(await page.locator('[data-phone-ticket="501"]').count(),0,'previous-branch tickets leave the board');
    held.release();await page.waitForFunction(()=>document.querySelector('[data-phone-ticket="1501"] .ph-ticket-quick-actions')?.querySelectorAll('button').length===3);
    assert.deepEqual(await card.locator('.ph-ticket-quick-actions button').allTextContents(),[labels.view_order,labels.edit_order,labels.assign_company],'catalog permissions restore all preparing actions without waiting for a changed board response');
    await clean(ctx);await page.close();
}
async function legacyBoardFocusPolling(browser){
    const ctx=await setup(browser,{query:'?view=orders'}),{page,tickets}=ctx;
    await page.locator('[data-phone-ticket="501"]').waitFor();await focused(page.locator('[data-phone-orders]'),'legacy orders URL focuses the persistent board container');
    const count=await page.evaluate(()=>window.phoneBoardRequests.length),ticket=tickets.get(501);ticket.customer_name='طلب محدث مع تركيز لوحة الدليفري';ticket.revision++;
    await page.waitForFunction(before=>window.phoneBoardRequests.length>before,count,{timeout:7000});
    await page.waitForFunction(()=>document.querySelector('[data-phone-ticket="501"]')?.textContent.includes('طلب محدث مع تركيز لوحة الدليفري'),null,{timeout:7000});
    await focused(page.locator('[data-phone-orders]'),'container focus does not prevent polling or move focus');
    await clean(ctx);await page.close();
}
async function abortedBoardPolling(browser){
    const ctx=await setup(browser),{page,tickets,network,labels}=ctx;
    await page.locator('[data-phone-ticket="501"]').waitFor();
    let held,index;
    await prepareCustomer(page,async()=>{
        held=network.holdBoard();await page.locator('[data-phone-refresh]').click();await held.entered;
        index=await page.evaluate(()=>window.phoneBoardRequests.length-1);
    });
    assert(await page.evaluate(i=>window.phoneBoardRequests[i].aborted,index),'customer-save aborts the in-flight board GET');held.release();
    await page.locator('[data-phone-edit-customer]').click();
    async function nextQuietUpdate(label){
        await field(page,'customer_name').focus();const ticket=tickets.get(102);ticket.customer_name=label;ticket.revision++;
        const count=await page.evaluate(()=>window.phoneBoardRequests.length);
        await page.waitForFunction(before=>window.phoneBoardRequests.length>before,count,{timeout:7000});
        await page.waitForFunction(name=>document.querySelector('[data-phone-ticket="102"]')?.textContent.includes(name),label,{timeout:7000});
        await page.waitForFunction(()=>document.querySelector('[data-phone-order-list]').getAttribute('aria-busy')==='false');
    }
    await nextQuietUpdate('تحديث بعد حفظ بيانات العميل');

    held=network.holdBoard();await page.locator('[data-phone-refresh]').click();await held.entered;
    index=await page.evaluate(()=>window.phoneBoardRequests.length-1);
    await page.locator('[data-phone-ticket="501"]').getByRole('button',{name:labels.assign_company,exact:true}).click();
    await page.locator('[data-phone-board-company="501"]').selectOption('1');
    await page.locator('[data-phone-column="courier"] [data-phone-ticket="501"]').waitFor();
    assert(await page.evaluate(i=>window.phoneBoardRequests[i].aborted,index),'dispatch aborts the in-flight board GET');held.release();
    await nextQuietUpdate('تحديث بعد تسليم الطلب لشركة التوصيل');
    await clean(ctx);await page.close();
}
async function concurrencyCases(browser){
    await branchPermissionRace(browser);await legacyBoardFocusPolling(browser);await abortedBoardPolling(browser);
    console.log('Phone unified concurrency: board-before-catalog branch permissions, legacy board-container focus polling, customer-save and dispatch abort recovery passed.');
}
async function responsive(browser){
    for(const [viewport,locale] of [[{width:1366,height:768},'ar'],[{width:1089,height:1024},'ar'],[{width:1920,height:1006},'en'],[{width:390,height:844},'ar'],[{width:390,height:844},'en']]){
        const ctx=await setup(browser,{viewport,locale,query:'?view=orders'}),{page}=ctx;
        await page.locator('[data-phone-ticket="501"]').waitFor();await unifiedVisible(page,'number');
        assert.equal(await page.locator('html').getAttribute('dir'),locale==='ar'?'rtl':'ltr');
        await screenshot(page,'phone-unified-'+viewport.width+'-'+locale+'-number');
        await prepareCustomer(page);await amount(page,'1');await page.locator('[data-phone-product="2"]').click();await quoteReady(page);
        await unifiedVisible(page,'products');await screenshot(page,'phone-unified-'+viewport.width+'-'+locale+'-products');
        if(viewport.width>=1000){const bottom=await page.locator('[data-phone-save]').evaluate(el=>el.getBoundingClientRect().bottom);assert(bottom<=viewport.height,'desktop send action remains reachable without page scrolling');}
        await clean(ctx);await page.close();
    }
}
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});
    try{if(process.env.PHONE_UNIFIED_PRICING_ONLY==='1'){await branchPricingPresentation(browser);return;}await concurrencyCases(browser);if(process.env.PHONE_UNIFIED_TARGETED!=='1'){await branchPricingPresentation(browser);await defaultProductQuantities(browser);await failedProductSend(browser);await liveTracking(browser);await responsive(browser);console.log('Phone unified: tracking in phone/customer steps; full product workspace; one-unit product defaults; explicit quantities and guarded shortcuts; late polling and failed sends preserve product mode; successful sends reset phone/board; concurrency, printing and Arabic/English responsive layouts passed.');}}
    catch(error){for(const context of browser.contexts())for(const page of context.pages()){console.error('phone-unified-failure',JSON.stringify(await page.evaluate(()=>({viewport:{width:innerWidth,height:innerHeight},key:document.querySelector('[data-phone-key=\"1\"]')?.getBoundingClientRect().toJSON(),hint:document.querySelector('[data-phone-quantity-hint]')?.getBoundingClientRect().toJSON(),step:document.querySelector('#phone-orders')?.className,message:document.querySelector('[data-phone-message]')?.textContent}))));await screenshot(page,'phone-unified-failure');}throw error;}
    finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}
})();
