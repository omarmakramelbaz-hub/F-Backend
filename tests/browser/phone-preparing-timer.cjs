'use strict';
// Production Blade, CSS and full phone JS with an isolated read-only board and controlled browser clock.
const assert = require('node:assert/strict'), fs = require('node:fs'), os = require('node:os'), path = require('node:path'), cp = require('node:child_process');
const {chromium} = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '../..'), tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'phone-preparing-timer-'));
const php = process.env.CRM_TEST_PHP || 'php', now = Date.parse('2026-10-17T15:00:00Z');
assert(process.env.CRM_TEST_AUTOLOAD && fs.existsSync(process.env.CRM_TEST_AUTOLOAD), 'isolated Laravel autoload is required');
const urls = {catalog:'/admin/phone-orders/catalog', board:'/admin/phone-orders/board', delivery_settings:'/admin/phone-orders/delivery-settings', companies:'/admin/delivery-companies/data', show:'/admin/phone-orders/tickets/__TICKET__'};
const branch = {id:100,value:'f:100',name:'المحلة',kind:'f'}, boot = {branches:[branch],selected_branch:branch.value,permissions:{can_checkout:true,can_operate:true},can_view_all_branches:true,receiver_branch:null,company_filter:0,open_phone_maps:false,urls};
fs.writeFileSync(path.join(tmp, 'boot.json'), JSON.stringify(boot));
const render = String.raw`function config($key,$default=null){return $default;}require getenv('CRM_TEST_AUTOLOAD');$c=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($c);$l=new \Illuminate\Translation\ArrayLoader;$locale=getenv('QA_LOCALE');$labels=require getenv('QA_ROOT').'/resources/lang/'.$locale.'/phone_orders.php';$l->addMessages($locale,'phone_orders',$labels);$c->instance('translator',new \Illuminate\Translation\Translator($l,$locale));if(!function_exists('__')){function __($key,$replace=[],$locale=null){return \Illuminate\Container\Container::getInstance()->make('translator')->get($key,$replace,$locale);}}$phonePos=json_decode(file_get_contents(getenv('QA_BOOT')),true);$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/phone_orders/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,getenv('QA_CACHE'));$__env=new class{use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));echo json_encode(['content'=>ob_get_clean(),'labels'=>$labels],JSON_UNESCAPED_UNICODE);`;
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8'), safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');
function html(locale){
    const rendered=JSON.parse(cp.execFileSync(php,['-r',render],{encoding:'utf8',env:{...process.env,QA_ROOT:root,QA_BOOT:path.join(tmp,'boot.json'),QA_CACHE:tmp,QA_LOCALE:locale}}));
    return '<!doctype html><html lang="'+locale+'" dir="'+(locale==='ar'?'rtl':'ltr')+'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>*{box-sizing:border-box}html,body{margin:0;font-family:Arial,sans-serif}</style><style>'+read('public/dashboard/css/phone-orders.css')+read('public/dashboard/css/phone-delivery-refresh.css')+'</style></head><body class="dashboard-theme dashboard-phone-orders-page">'+rendered.content+'<script type="application/json" id="phone-orders-bootstrap">'+safe(boot)+'</script><script type="application/json" id="phone-orders-translations">'+safe(rendered.labels)+'</script><script>'+read('public/dashboard/js/phone-address-search.js')+'</script><script>'+read('public/dashboard/js/phone-orders.js')+'</script></body></html>';
}
function ticket(id,stage,createdAt){return {id,number:'TIMER-'+id,channel:'phone',revision:1,status:stage==='courier'?'out_for_delivery':stage==='finished'?'finished':id===502?'preparing':'new',payment_status:stage==='finished'?'paid':'unpaid',branch,customer_name:'عميل تجريبي '+id,customer_phone:'01012345678',address:'عنوان اختبار',area:'المحلة',created_at:createdAt,courier_company:stage==='courier'?{id:1,name:'شركة تجريبية'}:null,quote_hash:'a'.repeat(64),subtotal:'100.00',total:'150.00',delivery_fee:'50.00',discount:'0.00',tax:'0.00',service:'0.00',kitchen_sent:true,bill_locked:false,items:[{product_id:1,name:'رنجة اختبار',quantity:'1',quantity_mode:'piece',unit_price:'100.00',total:'100.00'}],delivery_location:{branch:branch.value,latitude:30.12,longitude:31.25,delivery_fee:'50.00',distance_km:'2.50',km_price:'20.00',delivery_quote_hash:'d'.repeat(64),method:'direct'}};}
async function setup(browser,locale='ar'){
    const page=await browser.newPage({viewport:{width:1366,height:900},timezoneId:'America/New_York'}),calls=[],errors=[],unexpected=[],tickets=new Map();
    const age=seconds=>new Date(now-seconds*1000).toISOString();
    for(const [id,stage,created] of [[501,'preparing',age(899)],[502,'preparing',age(61)],[503,'preparing',age(-300)],[504,'preparing',undefined],[505,'preparing','not-a-date'],[506,'preparing',age(3599)],[507,'preparing',new Date(now-899000+10800000).toISOString().replace('Z','+03:00')],[508,'preparing','2026-02-30T15:00:00Z'],[509,'preparing','2026-10-17T14:00:00'],[601,'courier',age(1200)],[701,'finished',age(1500)]])tickets.set(id,ticket(id,stage,created));
    tickets.set(510,{...ticket(510,'preparing',age(120)),payment_status:'paid'});tickets.set(511,{...ticket(511,'preparing',age(120)),status:'cancelled'});
    tickets.set(602,{...ticket(602,'courier',age(1250)),status:'finished',_column:'courier'});
    tickets.set(701,{...tickets.get(701),completed_at:age(60)});
    tickets.set(702,{...ticket(702,'finished',age(1500)),completed_at:null});
    tickets.set(703,{...ticket(703,'finished',age(1500)),completed_at:'2026-02-30T15:00:00Z'});
    tickets.set(704,{...ticket(704,'finished',age(1500)),status:'cancelled',payment_status:'unpaid',completed_at:age(120),_column:'finished'});
    page.setDefaultTimeout(12000);page.on('pageerror',error=>errors.push(error.message));
    await page.clock.install({time:now-2000});await page.clock.pauseAt(now);
    await page.addInitScript(()=>{
        window.timerCleanup=[];window.timerIntervals=new Map();window.timerRegistrations=[];window.timerTicks=0;
        const interval=window.setInterval.bind(window),clear=window.clearInterval.bind(window);
        window.setInterval=function(callback,delay,...args){const id=interval(()=>{window.timerTicks++;callback(...args);},delay);window.timerIntervals.set(id,delay);window.timerRegistrations.push(delay);return id;};
        window.clearInterval=function(id){window.timerIntervals.delete(id);return clear(id);};
        window.DashboardSPA={isCurrentPage:()=>true,onCleanup:callback=>window.timerCleanup.push(callback),onBeforeLeave:()=>{}};
        window.DashboardPrint={print:()=>{throw Error('Timer QA must not print');}};
        window.DashboardLocationPicker={valid:()=>true,create:()=>({resize(){},destroy(){},clear(){},invalidate(){},set(){},route(){},geocode:async()=>null,suggest:async()=>[],resolve:async value=>value})};
    });
    await page.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.origin!=='https://timer.test'){unexpected.push(url.href);return route.abort();}
        if(url.pathname==='/admin/phone-orders')return route.fulfill({contentType:'text/html',body:html(locale)});
        calls.push({method:request.method(),path:url.pathname});assert.equal(request.method(),'GET','timer activity cannot create a write request');let result;
        if(url.pathname===urls.catalog)result={success:true,ready:true,items:[{id:1,name:'رنجة اختبار',price:'100.00',unit_price:'100.00',quantity_mode:'piece',unit:'piece',available:true,options:[]}],categories:[],policy:{can_discount:true,payment_methods:['cash']},permissions:{can_checkout:true},pagination:{page:1,last_page:1,total:1}};
        else if(url.pathname===urls.delivery_settings)result={success:true,settings:{branch,ready:true,latitude:30.1,longitude:31.2,km_price:'20.00'}};
        else if(url.pathname===urls.companies)result={success:true,items:[{id:1,branch:branch.value,name:'شركة تجريبية'}]};
        else if(url.pathname===urls.board){const columns={};for(const stage of ['preparing','courier','finished']){const items=[...tickets.values()].filter(ticket=>(ticket._column||([510,511].includes(ticket.id)?'preparing':ticket.payment_status==='paid'?'finished':ticket.status==='out_for_delivery'?'courier':'preparing'))===stage);columns[stage]={items,pagination:{page:1,last_page:1,total:items.length}};};result={success:true,branch:branch.value,columns,companies:[]};}
        else if(/\/tickets\/\d+$/.test(url.pathname))result={success:true,ticket:tickets.get(Number(url.pathname.match(/\d+$/)[0]))};
        else{unexpected.push(url.pathname);return route.abort();}
        try{return await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});}catch(error){if(!request.failure()&&!page.isClosed())throw error;}
    });
    await page.goto('https://timer.test/admin/phone-orders');await page.locator('[data-phone-ticket="501"]').waitFor();return {page,calls,errors,unexpected,tickets};
}
const timer=(page,id)=>page.locator('[data-phone-ticket="'+id+'"] .ph-preparation-timer');
const value=(page,id)=>timer(page,id).locator('.ph-preparation-timer-value');
async function oneTicker(page){assert.deepEqual(await page.evaluate(()=>({active:[...window.timerIntervals.values()],registered:window.timerRegistrations})),{active:[1000],registered:[1000]},'one shared one-second interval serves all preparing cards and every board refresh');}
async function color(page,id,overdue){assert.equal(await timer(page,id).evaluate(node=>node.classList.contains('is-overdue')),overdue);const color=await value(page,id).evaluate(node=>getComputedStyle(node).color),rgb=color.match(/[\d.]+/g).map(Number);assert(overdue?rgb[0]>rgb[1]&&rgb[0]>rgb[2]:rgb[1]>rgb[0]&&rgb[1]>rgb[2],overdue?'overdue elapsed time is red':'elapsed time through fifteen minutes is green');}
async function refresh(page){const response=page.waitForResponse(response=>new URL(response.url()).pathname===urls.board);await page.locator('[data-phone-refresh]').click();await response;await page.waitForFunction(()=>document.querySelector('[data-phone-order-list]').getAttribute('aria-busy')==='false');}
async function checks(browser){
    const {page,calls,errors,unexpected,tickets}=await setup(browser);
    assert.equal(await timer(page,501).getAttribute('data-phone-preparing-created-at'),String(now-899000),'badge anchors to the server creation timestamp in epoch milliseconds');assert.equal(await value(page,501).textContent(),'14:59');assert.equal(await value(page,502).textContent(),'01:01');assert.equal(await value(page,503).textContent(),'00:00','future creation times clamp to zero');assert.equal(await value(page,506).textContent(),'59:59');assert.equal(await value(page,507).textContent(),'14:59','equivalent UTC and Egypt-offset timestamps show the same elapsed time');assert.equal(await timer(page,507).getAttribute('data-phone-preparing-created-at'),String(now-899000));const label=(await timer(page,501).locator('.ph-preparation-timer-label').textContent()).trim();assert(label);assert.equal(await timer(page,501).getAttribute('role'),'timer');assert.equal(await timer(page,501).getAttribute('aria-label'),label);assert.equal(await timer(page,501).locator('i').getAttribute('aria-hidden'),'true');
    for(const id of [504,505,508,509,510,511,701,702,703,704])assert.equal(await timer(page,id).count(),0,'missing/invalid timestamps and terminal paid/cancelled tickets have no running badge');
    assert.equal(await value(page,601).textContent(),'20:00','courier cards keep elapsed time from the original order creation');
    assert.equal(await value(page,602).textContent(),'20:50','legacy finished-but-unpaid courier cards keep running');
    assert.equal((await timer(page,601).locator('.ph-preparation-timer-label').textContent()).trim(),'وقت الطلب');
    await color(page,601,true);
    const finishedTime=(id)=>page.locator('[data-phone-ticket="'+id+'"] .ph-ticket-finished-at time');
    const cairoLabel=(iso,locale='ar')=>new Intl.DateTimeFormat(locale==='ar'?'ar-EG':'en-GB',{timeZone:'Africa/Cairo',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(new Date(iso)).replace('،','');
    assert.equal(await finishedTime(701).getAttribute('datetime'),tickets.get(701).completed_at);
    assert.equal(await finishedTime(701).textContent(),cairoLabel(tickets.get(701).completed_at),'completion uses Cairo time even when the browser is in New York');
    assert.equal(await finishedTime(702).count(),0,'missing legacy completion evidence is not replaced by updated_at or the browser clock');
    assert.equal(await finishedTime(703).count(),0,'invalid completion timestamps have no fabricated badge');
    assert((await page.locator('[data-phone-ticket="704"] .ph-ticket-finished-at').textContent()).includes('وقت الإلغاء'));
    for(const id of [501,601,602])assert.equal(await finishedTime(id).count(),0,'active stages never show a terminal timestamp');
    await color(page,501,false);await oneTicker(page);
    const beforeTick=calls.length,poll=page.waitForResponse(response=>new URL(response.url()).pathname===urls.board);await page.clock.runFor(1000);await poll;assert.equal(calls.length,beforeTick+1,'the first tick permits only the existing receiver board poll');assert.equal(await value(page,501).textContent(),'15:00');assert.equal(await value(page,506).textContent(),'01:00:00','one-hour elapsed time switches to HH:MM:SS');await color(page,501,false);const afterPoll=calls.length;await page.clock.runFor(1000);assert.equal(calls.length,afterPoll,'card timer ticks do not add per-card network requests');assert.equal(await value(page,501).textContent(),'15:01');await color(page,501,true);assert.equal(await value(page,503).textContent(),'00:00');
    for(let n=0;n<3;n++){await refresh(page);assert.equal(await value(page,501).textContent(),'15:01','refresh keeps elapsed time instead of starting over');await oneTicker(page);}
    tickets.set(501,{...tickets.get(501),revision:2,notes:'تعديل بيانات الطلب',updated_at:new Date(now+2000).toISOString()});await refresh(page);assert.equal(await value(page,501).textContent(),'15:01','a revised updated_at does not reset elapsed time from created_at');
    await page.locator('[data-phone-ticket="501"] .ph-ticket-quick-actions button').nth(1).click();await page.locator('#phone-orders.ph-step-products').waitFor();assert.equal(await value(page,501).textContent(),'15:01','opening an editor does not reset the original creation time');await page.clock.runFor(1000);assert.equal(await value(page,501).textContent(),'15:02','hidden board cards continue to age from the same server timestamp');await page.locator('[data-phone-edit-customer]').click();await page.locator('#phone-orders.ph-step-details').waitFor();assert.equal(await value(page,501).textContent(),'15:02');assert.equal(tickets.get(501).created_at,new Date(now-899000).toISOString());await oneTicker(page);
    tickets.set(501,{...tickets.get(501),status:'out_for_delivery',revision:3,courier_company:{id:1,name:'شركة تجريبية'},updated_at:new Date(now+2000).toISOString()});
    tickets.set(503,{...tickets.get(503),status:'out_for_delivery',revision:2});await refresh(page);
    assert.equal(await page.locator('[data-phone-column="courier"] [data-phone-ticket="501"]').count(),1);
    assert.equal(await value(page,501).textContent(),'15:02','the same elapsed count travels from preparation into the courier column without reset');
    assert.equal(await value(page,503).textContent(),'00:00','dispatch retains the original future-time clamp');
    await page.clock.runFor(1000);assert.equal(await value(page,501).textContent(),'15:03','courier timers continue ticking');
    await page.reload();await page.locator('[data-phone-ticket="501"]').waitFor();assert.equal(await value(page,501).textContent(),'15:03','courier reload derives elapsed time from the same stored creation time');await oneTicker(page);await color(page,501,true);
    const completedAt=new Date(now+3000).toISOString();
    tickets.set(501,{...tickets.get(501),status:'finished',payment_status:'paid',revision:4,completed_at:completedAt});await refresh(page);
    assert.equal(await page.locator('[data-phone-column="finished"] [data-phone-ticket="501"]').count(),1);
    assert.equal(await timer(page,501).count(),0,'a paid completion stops the elapsed display');
    assert.equal(await finishedTime(501).textContent(),cairoLabel(completedAt));
    const completedLabel=await finishedTime(501).textContent();
    tickets.set(501,{...tickets.get(501),updated_at:new Date(now+9999999).toISOString()});await refresh(page);
    await page.clock.runFor(1000);assert.equal(await finishedTime(501).textContent(),completedLabel,'later updates and clock ticks never alter the recorded completion time');
    await page.reload();await finishedTime(501).waitFor();assert.equal(await finishedTime(501).textContent(),completedLabel,'completion timestamp survives a full reload');await oneTicker(page);
    const before=await value(page,601).textContent();await page.evaluate(()=>window.timerCleanup.forEach(callback=>callback()));const ticks=await page.evaluate(()=>window.timerTicks),requests=calls.length;assert.equal(await page.evaluate(()=>window.timerIntervals.size),0,'SPA cleanup clears the timer interval');await page.clock.runFor(5000);assert.equal(await page.evaluate(()=>window.timerTicks),ticks,'SPA cleanup prevents further timer callbacks');assert.equal(await value(page,601).textContent(),before);assert.equal(await finishedTime(501).textContent(),completedLabel);assert.equal(calls.length,requests,'cleaned-up timers and receiver polling make no more requests');assert(calls.every(call=>call.method==='GET'));assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);await page.close();
    const english=await setup(browser,'en');assert(/[A-Za-z]/.test(await timer(english.page,501).locator('.ph-preparation-timer-label').textContent()),'English elapsed-time label is localized');assert.equal(await timer(english.page,601).locator('.ph-preparation-timer-label').textContent(),'Order time');assert((await english.page.locator('[data-phone-ticket="701"] .ph-ticket-finished-at').textContent()).includes('Completed at'));await oneTicker(english.page);assert.deepEqual(english.errors,[]);assert.deepEqual(english.unexpected,[]);await english.page.close();
    console.log('Phone elapsed lifecycle: server-anchored preparing and courier timers, exact fifteen-minute colors, dispatch/edit/reload continuity, legacy unpaid courier, immutable localized completion/cancellation timestamps, one ticker, no writes and SPA cleanup passed.');
}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});try{await checks(browser);}finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}})().catch(error=>{console.error(error);process.exitCode=1;});
