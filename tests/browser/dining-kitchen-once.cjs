'use strict';
// Real dining Blade/CSS/JS; local HTTP fixture, no provider or printer access.
const assert=require('assert'),fs=require('fs'),os=require('os'),path=require('path'),cp=require('child_process');
const {chromium}=require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES||process.env.NODE_PATH||'','playwright'));
const root=path.resolve(__dirname,'../..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'dining-kitchen-once-'));
const php=process.env.CRM_TEST_PHP||'php',vendor=process.env.CRM_TEST_AUTOLOAD;assert(vendor&&fs.existsSync(vendor),'CRM_TEST_AUTOLOAD must point to isolated Laravel');
const qa=process.env.TAKEAWAY_QA_SCREENSHOTS||path.resolve(root,'../qa');fs.mkdirSync(qa,{recursive:true});
const urls={catalog:'/admin/dining/catalog',tables:'/admin/dining/tables',quote:'/admin/dining/quote',save:'/admin/dining/tickets',show:'/admin/dining/tickets/__TICKET__',action:'/admin/dining/tickets/__TICKET__/action',settle:'/admin/dining/tickets/__TICKET__/settle',recover:'/admin/dining/recover',tickets:'/admin/dining/tickets',till:'/admin/dining/till',takeaway:'/admin/takeaway',settings:'/admin/dining/settings','table-save':'/admin/dining/tables/save'};
const branch={id:100,value:'f:100',name:'المحلة'},permissions={can_checkout:true,can_operate:true,can_manage:true,can_manage_tables:true};
const policy={can_discount:true,tax_rate:'0.00',service_rate:'5.00',settings_revision:1,payment_methods:['cash']};
const boot={branches:[branch],selected_branch:branch.value,cashier:{id:1,name:'كاشير الاختبار'},permissions,policy,urls};fs.writeFileSync(path.join(tmp,'boot.json'),JSON.stringify(boot));
const render=String.raw`require getenv('CRM_TEST_AUTOLOAD');$c=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($c);$loader=new \Illuminate\Translation\ArrayLoader;$labels=require getenv('QA_ROOT').'/resources/lang/'.getenv('QA_LANG').'/dining.php';$loader->addMessages(getenv('QA_LANG'),'dining',$labels);$c->instance('translator',new \Illuminate\Translation\Translator($loader,getenv('QA_LANG')));$c->instance('url',new class {public function route($name,$parameters=[],$absolute=true){return 'https://dining.test/admin/takeaway';}});$dining=json_decode(file_get_contents(getenv('QA_BOOT')),true);$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/dining/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,getenv('QA_CACHE'));$__env=new class {use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));$content=ob_get_clean();echo json_encode(['content'=>$content,'labels'=>$labels],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);`;
const font=fs.readFileSync(path.join(root,'public/dashboard/vendor/desktop-external/fonts.gstatic.com/s/almarai/v19/tsstApxBaigK_hnnQ1iFo0C3.woff2')).toString('base64');
const shell=`@font-face{font-family:Almarai;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}*{box-sizing:border-box}html,body{margin:0;font-family:Almarai,Arial,sans-serif}label{font-weight:700}header.qa-top{height:58px;background:#081727;color:white;display:flex;align-items:center;padding:0 280px 0 20px}aside.qa-sidebar{position:fixed;right:0;top:0;bottom:0;width:250px;background:#081727;color:#fff;padding:35px 20px;box-sizing:border-box}aside.qa-sidebar h2{color:#ff7900}aside.qa-sidebar p{padding:14px 8px;border-bottom:1px solid #ffffff15;font-size:12px}.content-wrapper{margin-right:250px}footer.qa-footer{height:51px;background:#081727;color:white;margin-right:250px;padding:15px;box-sizing:border-box;font-size:11px}i{display:inline-block;min-width:1em}@media(max-width:599px){aside.qa-sidebar{display:none}.content-wrapper,footer.qa-footer{margin-right:0}header.qa-top{padding:0 15px}}`;
const safe=value=>JSON.stringify(value).replaceAll('<','\\u003c');
function html(locale='ar'){const rendered=JSON.parse(cp.execFileSync(php,['-r',render],{encoding:'utf8',env:{...process.env,QA_ROOT:root,QA_LANG:locale,QA_BOOT:path.join(tmp,'boot.json'),QA_CACHE:tmp}}));return{labels:rendered.labels,html:`<!doctype html><html lang="${locale}" dir="${locale==='ar'?'rtl':'ltr'}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>${shell}</style><style>${fs.readFileSync(path.join(root,'public/dashboard/css/dining-pos.css'),'utf8')}</style></head><body class="dashboard-theme dashboard-dining-page"><header class="qa-top">معاينة شاشة الصالة</header><aside class="qa-sidebar"><h2>Fasakhansta</h2><p>الرئيسية</p><p>طلبات التطبيق</p><p>طلبات التيك أواي</p><p>طلبات الدليفري</p><p>طلبات الصالة</p><p>قائمة العملاء</p></aside>${rendered.content}<footer class="qa-footer">معاينة اختبار ببيانات تجريبية</footer><script type="application/json" id="dining-bootstrap">${safe(boot)}</script><script type="application/json" id="dining-translations">${safe(rendered.labels)}</script><script>${fs.readFileSync(path.join(root,'public/dashboard/js/dining-pos.js'),'utf8')}</script></body></html>`};}
const products=[{id:1,name:'رنجة هيرنج',unit_price:'200.00',price:'200.00',quantity_mode:'weight',unit:'kg',available:true,options:[]},{id:2,name:'سندوتش فسيخ مظبوط',unit_price:'100.00',price:'100.00',quantity_mode:'piece',unit:'piece',available:true,options:[]}];
function quote(payload){let subtotal=0;const items=payload.items.map(item=>{const product=products.find(p=>p.id===item.product_id),option=[...(product.preparation_options||[]),...(product.options||[])].find(option=>String(option.id||'')===String(item.option_id||'')),price=option?option.price:product.price,total=Math.round(Number(price)*Number(item.quantity)*100);subtotal+=total;return{...item,name:product.name,unit_price:price,total:(total/100).toFixed(2),option_label:option?option.label:''};});const discount=Math.round(Number(payload.discount||0)*100),service=Math.round((subtotal-discount)*.05);return{success:true,branch,items,quote_hash:'a'.repeat(64),subtotal:(subtotal/100).toFixed(2),discount:(discount/100).toFixed(2),discount_reason:payload.discount_reason||'',tax:'0.00',tax_rate:'0.00',service:(service/100).toFixed(2),service_rate:'5.00',total:((subtotal-discount+service)/100).toFixed(2)};}
function ticketOf(payload,revision=1){return{...quote(payload),id:101,number:'DINE-TEST-101',revision,channel:'dine',table:{id:1,name:'1',capacity:6,active:true},customer_name:payload.customer_name||'عميل الصالة',customer_phone:payload.customer_phone||'01012345678',waiter_name:payload.waiter_name||'كاشير الاختبار',guest_count:payload.guest_count||1,notes:payload.notes||'',status:'open',payment_status:'unpaid',bill_locked:false,kitchen_sent:false,opened_at:'2026-10-17T09:00:00Z',created_at:'2026-10-17T09:00:00Z',details_url:'/admin/dining/tickets/101/details',kitchen_print_url:'/admin/dining/tickets/101/kitchen',bill_print_url:'/admin/dining/tickets/101/print'};}
async function setup(browser,{initialTicket=null,loseAcknowledgement=null,failBeforeCommit=null}={}){
    const rendered=html(),page=await browser.newPage({viewport:{width:1366,height:900}}),calls=[],errors=[],outside=[],prints=[];
    let current=initialTicket?structuredClone(initialTicket):null, lost=false,failed=false;
    const operations=new Map();
    page.on('pageerror',error=>errors.push(error.message));
    await page.exposeFunction('captureDiningPrint',url=>prints.push({url,ticket:structuredClone(current)}));
    await page.addInitScript(()=>{window.DashboardPrint={print:async url=>window.captureDiningPrint(url)};window.DashboardInvoiceDetails={open:()=>{}};window.confirm=()=>true;window.print=()=>{throw Error('Native print is forbidden in QA');};});
    await page.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.origin!=='https://dining.test'){outside.push(url.href);return route.abort();}
        if(url.pathname==='/admin/dining')return route.fulfill({contentType:'text/html',body:rendered.html});
        const payload=request.method()==='POST'?request.postDataJSON():Object.fromEntries(url.searchParams);
        calls.push({path:url.pathname,method:request.method(),payload});let result;
        const ok=body=>route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
        const error=()=>route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false})});
        if(request.method()==='POST')assert.strictEqual(request.headers()['x-csrf-token'],'fixture-token');
        if(url.pathname==='/admin/dining/catalog')result={success:true,ready:true,branch,items:products,categories:[],policy,permissions,pagination:{page:1,last_page:1,total:products.length},today:{count:0}};
        else if(url.pathname==='/admin/dining/tables')result={success:true,branch,policy,permissions,tables:[{id:1,name:'1',capacity:6,active:true,status:current&&current.payment_status!=='paid'?current.status:'free',ticket_id:current&&current.payment_status!=='paid'?current.id:null,ticket:current&&current.payment_status!=='paid'?structuredClone(current):null}]};
        else if(url.pathname==='/admin/dining/quote')result=quote(payload);
        else if(url.pathname==='/admin/dining/recover')result=operations.has(payload.idempotency_key)?{...structuredClone(operations.get(payload.idempotency_key)),found:true,replayed:true}:{success:true,found:false};
        else if(request.method()==='POST'){
            const kind=url.pathname.endsWith('/settle')?'settle':url.pathname.endsWith('/action')?payload.action:'save';
            if(operations.has(payload.idempotency_key))return ok({...structuredClone(operations.get(payload.idempotency_key)),replayed:true});
            if(failBeforeCommit===kind&&!failed){failed=true;return error();}
            if(kind==='save'){
                if(current)assert.strictEqual(payload.expected_revision,current.revision);
                const before=current;current=ticketOf(payload,payload.expected_revision?payload.expected_revision+1:1);
                if(before){current.kitchen_sent=before.kitchen_sent;current.status=before.status;current.bill_locked=before.bill_locked;}
            }else{
                assert(current);assert.strictEqual(payload.expected_revision,current.revision);current.revision++;
                if(kind==='send_kitchen'){assert(!current.kitchen_sent,'the kitchen command must never be posted twice for one ticket');current.kitchen_sent=true;current.status='preparing';}
                else if(kind==='request_bill'){assert(!current.bill_locked,'the bill command must never be posted twice');current.bill_locked=true;current.status='awaiting_bill';current.bill_print_url='/admin/dining/tickets/101/print?revision='+current.revision;}
                else if(kind==='settle'){assert(current.bill_locked);current.payment_status='paid';current.status='paid';}
                else throw Error('Unexpected POST '+url.pathname+' '+kind);
            }
            result={success:true,replayed:false,ticket:structuredClone(current),operation:{kind:kind==='save'||kind==='settle'?kind:'action',ticket_id:101,revision:current.revision,idempotency_key:payload.idempotency_key},policy,permissions};
            if(kind==='send_kitchen')result.kitchen_print_url=current.kitchen_print_url;
            if(kind==='settle')Object.assign(result,{receipt:{id:501,ticket_id:101,channel:'dine',branch,idempotency_key:payload.idempotency_key,payment_method:'cash',total:current.total,cash_received:payload.cash_received,change:(Number(payload.cash_received)-Number(current.total)).toFixed(2)},receipt_url:'/admin/takeaway/receipts/501/print'});
            operations.set(payload.idempotency_key,structuredClone(result));
            if(loseAcknowledgement===kind&&!lost){lost=true;return error();}
        }else if(url.pathname==='/admin/dining/tickets/101')result={success:true,ticket:structuredClone(current),policy,permissions};
        else{outside.push(url.href);return route.abort();}
        return ok(result);
    });
    await page.goto('https://dining.test/admin/dining');await page.locator('[data-dining-product="1"]').waitFor();await page.locator('[data-dining-table="1"]').waitFor();
    return{page,calls,errors,outside,prints,state:()=>structuredClone(current)};
}
const writes=ctx=>ctx.calls.filter(call=>call.method==='POST'&&!call.path.endsWith('/quote'));
const actions=(ctx,action)=>writes(ctx).filter(call=>call.payload.action===action);
const saves=ctx=>writes(ctx).filter(call=>call.path==='/admin/dining/tickets');
async function fresh(page){await page.waitForFunction(()=>document.querySelector('[data-dining-total="total"]').textContent!=='—');}
async function start(ctx){
    const {page}=ctx;await page.locator('[data-dining-table="1"]').click();await page.locator('[data-dining-open-customer]').fill('عميل اختبار الصالة');await page.locator('[data-dining-open-customer]').press('Enter');
    await page.locator('[data-dining-product="1"]').click();await page.locator('[data-dining-product="2"]').click();await fresh(page);
    await page.locator('[data-dining-customer-phone]').fill('01012345678');await page.locator('[data-dining-customer-consent]').check();
}
async function onceSent(ctx){
    const {page}=ctx;await page.waitForFunction(()=>document.querySelector('[data-dining-save]').hidden&&document.querySelector('[data-dining-save]').disabled);
    assert(await page.locator('[data-dining-cancel]').isHidden());assert(await page.locator('[data-dining-clear]').isHidden());
    assert(!await page.locator('[data-dining-line-quantity]').first().isDisabled(),'kitchen sending keeps item edits available until bill');
    const before=writes(ctx).length;await page.keyboard.press('F9');await page.locator('[data-dining-save]').dispatchEvent('click');assert.strictEqual(writes(ctx).length,before,'F9 and programmatic save click cannot send a second kitchen order');
}
async function edit(ctx){
    const line=ctx.page.locator('[data-dining-line-quantity]').first();await line.fill('2');await line.dispatchEvent('change');await fresh(ctx.page);
    assert(await ctx.page.locator('[data-dining-save]').isHidden());assert(await ctx.page.locator('[data-dining-save]').isDisabled(),'editing never re-enables kitchen send');
    assert(!await ctx.page.locator('[data-dining-request-bill]').isDisabled(),'a fresh edited quote enables bill print');
}
async function billLockedOnlyCollection(ctx){
    const {page}=ctx;await page.waitForFunction(()=>document.querySelector('[data-dining-request-bill]').hidden);
    assert.deepStrictEqual(await page.locator('.dp-actions button:visible').evaluateAll(buttons=>buttons.map(b=>b.hasAttribute('data-dining-settle'))),[true],'only finish/collect remains in bottom invoice actions after bill');
    assert(await page.locator('[data-dining-line-quantity]').first().isDisabled());assert(await page.locator('[data-dining-remove]').first().isHidden());
    assert(await page.locator('[data-dining-product="1"]').isDisabled());assert(await page.locator('[data-dining-customer]').isDisabled());
    assert(!await page.locator('[data-dining-details]').isDisabled(),'readonly invoice details remain available');
    const count=ctx.prints.length,commands=writes(ctx).length;
    await page.locator('[data-dining-request-bill]').dispatchEvent('click');await page.keyboard.press('F9');await page.keyboard.press('F10');
    await page.evaluate(()=>{const b=document.createElement('button');b.dataset.diningPrintBill='';document.querySelector('#dining-pos').appendChild(b);b.click();b.remove();const k=document.createElement('button');k.dataset.diningPrintKitchen='';document.querySelector('#dining-pos').appendChild(k);k.click();k.remove();});
    assert.strictEqual(ctx.prints.length,count,'hidden bill/direct print handlers cannot print after the bill is locked');assert.strictEqual(writes(ctx).length,commands);
}
async function clean(ctx){assert.deepStrictEqual(ctx.errors,[]);assert.deepStrictEqual(ctx.outside,[]);await ctx.page.close();}
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});
    try{
        const main=await setup(browser);await start(main);
        await main.page.locator('[data-dining-save]').evaluate(button=>{button.click();button.click();});
        await main.page.waitForFunction(()=>document.querySelector('[data-dining-save]').hidden);await onceSent(main);
        await main.page.screenshot({path:'/tmp/dining-kitchen-once-after-send.png',fullPage:true});
        assert.strictEqual(main.prints.length,1);assert.strictEqual(actions(main,'send_kitchen').length,1);assert.strictEqual(saves(main).length,1);
        await edit(main);await onceSent(main);
        // Removing one of two lines is an allowed edit, but the final line cannot clear a sent invoice.
        await main.page.locator('[data-dining-remove]').nth(1).click();await fresh(main.page);assert(await main.page.locator('[data-dining-remove]').first().isDisabled());
        const edited=main.page.locator('[data-dining-line-quantity]').first();assert.strictEqual(await edited.inputValue(),'2.000');
        await main.page.locator('[data-dining-request-bill]').click();await billLockedOnlyCollection(main);
        await main.page.screenshot({path:'/tmp/dining-kitchen-once-after-bill.png',fullPage:true});
        assert.strictEqual(saves(main).length,2);assert.strictEqual(saves(main)[1].payload.items.length,1);assert.strictEqual(saves(main)[1].payload.items[0].quantity,'2.000');assert.strictEqual(saves(main)[1].payload.reprice,true);
        assert.strictEqual(actions(main,'send_kitchen').length,1,'save before bill must not send to kitchen again');assert.strictEqual(actions(main,'request_bill').length,1);assert.strictEqual(main.prints.length,2);
        assert.strictEqual(main.prints[1].ticket.total,'420.00');assert.strictEqual(main.prints[1].ticket.items[0].quantity,'2.000');assert(main.prints[1].url.includes('revision=4'),'bill prints the saved edited revision');
        await main.page.locator('[data-dining-settle]').click();assert(await main.page.locator('[data-dining-cash-wrap]').isVisible());await main.page.locator('[data-dining-customer-consent]').check();await main.page.locator('[data-dining-cash-received]').fill('500.00');
        await main.page.locator('[data-dining-cash-received]').press('Enter');await main.page.waitForFunction(()=>!document.querySelector('[data-dining-lines] tr'));assert.strictEqual(main.prints.length,2,'cash collection cannot repeat a kitchen or bill print');assert.strictEqual(main.state().payment_status,'paid');await clean(main);
        // Lost initial kitchen acknowledgement recovers the same operation and prints once.
        const lostKitchen=await setup(browser,{loseAcknowledgement:'send_kitchen'});await start(lostKitchen);await lostKitchen.page.locator('[data-dining-save]').click();await lostKitchen.page.locator('[data-dining-retry]').waitFor({state:'visible'});assert.strictEqual(lostKitchen.prints.length,0);
        await lostKitchen.page.locator('[data-dining-retry]').click();await onceSent(lostKitchen);assert.strictEqual(actions(lostKitchen,'send_kitchen').length,1);assert.strictEqual(lostKitchen.prints.length,1);await clean(lostKitchen);
        const sent=ticketOf({branch:branch.value,items:[{product_id:1,quantity:'1.000',quantity_mode:'weight'}]},2);Object.assign(sent,{kitchen_sent:true,status:'preparing'});
        // A committed edited save with a lost response continues only to request_bill after recovery.
        for(const fault of [{loseAcknowledgement:'save'},{loseAcknowledgement:'request_bill'},{failBeforeCommit:'request_bill'}]){
            const retry=await setup(browser,{initialTicket:sent,...fault});await retry.page.locator('[data-dining-table="1"]').click();await fresh(retry.page);await edit(retry);await retry.page.locator('[data-dining-request-bill]').click();await retry.page.locator('[data-dining-retry]').waitFor({state:'visible'});assert.strictEqual(retry.prints.length,0);
            await retry.page.locator('[data-dining-retry]').click();await billLockedOnlyCollection(retry);assert.strictEqual(saves(retry).length,1);assert.strictEqual(actions(retry,'send_kitchen').length,0);assert.strictEqual(retry.prints.length,1,'recovered bill path prints the final bill once');assert.strictEqual(retry.prints[0].ticket.total,'420.00');
            if(fault.failBeforeCommit){const attempts=actions(retry,'request_bill');assert.strictEqual(attempts.length,2);assert.deepStrictEqual(attempts[0].payload,attempts[1].payload,'retry reuses the identical immutable bill command');}else assert.strictEqual(actions(retry,'request_bill').length,1,'committed lost acknowledgement is recovered without a second POST');
            await clean(retry);
        }
        console.log('DINING_KITCHEN_ONCE_BROWSER_OK initial print once; edits never re-enable kitchen; dirty bill save→request_bill→updated print; only collect after bill; cash and lost-ack immutable retries pass.');
    }finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exit(1);});
