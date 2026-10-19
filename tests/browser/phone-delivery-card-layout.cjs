'use strict';
// Real Blade and full production scripts in the confirmed live dashboard shell.
// Read-only fixtures stress narrow delivery columns, Arabic labels and large timers.
const assert = require('node:assert/strict'), fs = require('node:fs'), os = require('node:os'), path = require('node:path'), cp = require('node:child_process');
const {chromium} = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '../..'), tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'phone-delivery-card-layout-'));
const php = process.env.CRM_TEST_PHP || 'php', now = Date.parse('2026-10-17T15:00:00Z');
const liveLayoutRevision = process.env.CRM_TEST_PHONE_LAYOUT_REVISION;
assert(process.env.CRM_TEST_AUTOLOAD && fs.existsSync(process.env.CRM_TEST_AUTOLOAD), 'isolated Laravel autoload is required');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const liveLayout = liveLayoutRevision ? cp.execFileSync('git', ['show', liveLayoutRevision + ':public/dashboard/css/phone-orders.css'], {cwd:root, encoding:'utf8'}) : read('public/dashboard/css/phone-orders.css');
const font = fs.readFileSync(path.join(root, 'public/dashboard/vendor/desktop-external/fonts.gstatic.com/s/almarai/v19/tsstApxBaigK_hnnQ1iFo0C3.woff2')).toString('base64');
const urls = {catalog:'/admin/phone-orders/catalog', board:'/admin/phone-orders/board', delivery_settings:'/admin/phone-orders/delivery-settings', companies:'/admin/delivery-companies/data', show:'/admin/phone-orders/tickets/__TICKET__'};
const branch = {id:100, value:'f:100', name:'فرع المحلة الكبرى', kind:'f'};
const boot = {branches:[branch], selected_branch:branch.value, permissions:{can_checkout:true, can_operate:true}, can_view_all_branches:true, receiver_branch:null, company_filter:0, open_phone_maps:false, urls};
fs.writeFileSync(path.join(tmp, 'boot.json'), JSON.stringify(boot));
const render = String.raw`function config($key,$default=null){return $default;}require getenv('CRM_TEST_AUTOLOAD');$c=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($c);$l=new \Illuminate\Translation\ArrayLoader;$labels=require getenv('QA_ROOT').'/resources/lang/ar/phone_orders.php';$l->addMessages('ar','phone_orders',$labels);$c->instance('translator',new \Illuminate\Translation\Translator($l,'ar'));if(!function_exists('__')){function __($key,$replace=[],$locale=null){return \Illuminate\Container\Container::getInstance()->make('translator')->get($key,$replace,$locale);}}$phonePos=json_decode(file_get_contents(getenv('QA_BOOT')),true);$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/phone_orders/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,getenv('QA_CACHE'));$__env=new class{use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));echo json_encode(['content'=>ob_get_clean(),'labels'=>$labels],JSON_UNESCAPED_UNICODE);`;
const rendered = JSON.parse(cp.execFileSync(php, ['-r', render], {encoding:'utf8', env:{...process.env, QA_ROOT:root, QA_BOOT:path.join(tmp,'boot.json'), QA_CACHE:tmp}}));
// Stress actual action controls through translations, without replacing the card DOM.
Object.assign(rendered.labels, {view_order:'عرض تفاصيل الطلب', edit_order:'تعديل أصناف الطلب', assign_company:'تسليم لشركة التوصيل', print_order:'طباعة فاتورة الطلب', finish_collect:'إنهاء وتحصيل الفاتورة'});
const safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');
const chrome = `@font-face{font-family:Almarai;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}*{box-sizing:border-box}html,body{margin:0;font-family:Almarai,Arial,sans-serif}label{font-weight:700}.qa-top{height:58px;background:#081727;color:white;display:flex;align-items:center;padding:0 270px 0 16px}.qa-sidebar{position:fixed;right:0;top:0;bottom:0;width:250px;background:#081727;color:white;padding:28px 18px}.content-wrapper{margin-right:250px}.qa-footer{height:51px;margin-right:250px;padding:15px;background:#081727;color:white;font-size:11px}i{display:inline-block;min-width:1em}@media(max-width:599px){.qa-sidebar{display:none}.content-wrapper,.qa-footer{margin-right:0}.qa-top{padding:0 15px}}`;
function html() {
    return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>'+chrome+'</style><style>'+liveLayout+read('public/dashboard/css/phone-delivery-refresh.css')+'</style></head><body class="dashboard-theme dashboard-phone-orders-page"><header class="qa-top">طلبات التوصيل</header><aside class="qa-sidebar">لوحة التحكم</aside>'+rendered.content+'<footer class="qa-footer">اختبار بيانات تجريبية</footer><script type="application/json" id="phone-orders-bootstrap">'+safe(boot)+'</script><script type="application/json" id="phone-orders-translations">'+safe(rendered.labels)+'</script><script>'+read('public/dashboard/js/phone-address-search.js')+'</script><script>'+read('public/dashboard/js/phone-orders.js')+'</script></body></html>';
}
const completion = '2026-10-17T14:45:20Z';
function ticket(id, stage, index) {
    return {id, number:'PH-'+id, channel:'phone', revision:1, status:stage==='courier'?'out_for_delivery':stage==='finished'?'finished':'preparing', payment_status:stage==='finished'?'paid':'unpaid', branch,
        customer_name:['عبد الرحمن محمد أحمد السيد مصطفى عبد العزيز الطويل', 'أميرة محمود عبد الفتاح محمد علي سليمان إبراهيم'][index % 2]+' '+index,
        customer_phone:'01012345678', address:'شارع الجمهورية بجوار المدرسة الابتدائية، الدور الثالث', area:'المحلة الكبرى', created_at:new Date(now-(3600+index*61)*1000).toISOString(),
        completed_at:stage==='finished'?completion:null,
        courier_company:stage==='preparing'?null:{id:1,name:'شركة التوصيل التجريبية'}, quote_hash:'a'.repeat(64), subtotal:'12295.75', total:'12345.75', delivery_fee:'50.00', discount:'0.00', tax:'0.00', service:'0.00', kitchen_sent:true, bill_locked:stage==='finished',
        bill_print_url:'/admin/phone-orders/tickets/'+id+'/print', items:[{product_id:1,name:'رنجة اختبار',quantity:'1',quantity_mode:'piece',unit_price:'12295.75',total:'12295.75'}]};
}
const columns = Object.fromEntries(['preparing','courier','finished'].map((stage, column) => {
    const items = Array.from({length:12}, (_, index) => ticket((column+1)*100+index+1, stage, index));
    if(stage==='courier')items[0].status='finished'; // Legacy unpaid deliveries still belong to the courier queue.
    return [stage,{items,pagination:{page:1,last_page:1,total:items.length}}];
}));
async function setup(browser, viewport) {
    const page = await browser.newPage({viewport}), errors = [], calls = [], unexpected = [];
    page.on('pageerror', error => errors.push(error.message)); page.setDefaultTimeout(12000);
    await page.clock.install({time:now-1000}); await page.clock.pauseAt(now);
    await page.addInitScript(() => {
        window.layoutCleanup=[];window.DashboardSPA={isCurrentPage:()=>true,onCleanup:callback=>window.layoutCleanup.push(callback),onBeforeLeave:()=>{}};
        window.DashboardPrint={print:()=>{throw Error('Layout QA must not print');}};
        window.DashboardLocationPicker={valid:()=>true,create:()=>({resize(){},destroy(){},clear(){},invalidate(){},set(){},route(){},geocode:async()=>null,suggest:async()=>[],resolve:async value=>value})};
    });
    await page.route('**/*', async route => {
        const request=route.request(), url=new URL(request.url());
        if(url.origin!=='https://layout.test'){unexpected.push(url.href);return route.abort();}
        if(url.pathname==='/admin/phone-orders')return route.fulfill({contentType:'text/html',body:html()});
        calls.push({method:request.method(),path:url.pathname});assert.equal(request.method(),'GET','layout checks cannot mutate an order');let result;
        if(url.pathname===urls.catalog)result={success:true,ready:true,items:[{id:1,name:'رنجة اختبار',price:'100.00',unit_price:'100.00',quantity_mode:'piece',unit:'piece',available:true,options:[]}],categories:[],policy:{can_discount:true,payment_methods:['cash']},permissions:{can_checkout:true},pagination:{page:1,last_page:1,total:1}};
        else if(url.pathname===urls.board)result={success:true,branch:branch.value,columns,companies:[]};
        else if(url.pathname===urls.delivery_settings)result={success:true,settings:{branch,ready:true,latitude:30.1,longitude:31.2,km_price:'20.00'}};
        else if(url.pathname===urls.companies)result={success:true,items:[{id:1,branch:branch.value,name:'شركة التوصيل التجريبية'}]};
        else{unexpected.push(url.pathname);return route.abort();}
        return route.fulfill({json:result});
    });
    await page.goto('https://layout.test/admin/phone-orders');
    await page.locator('[data-phone-ticket="101"]').waitFor();await page.evaluate(()=>document.fonts.ready);
    return {page,errors,calls,unexpected};
}
async function measure(page) {
    const expectedActions=Object.fromEntries(Object.values(columns).flatMap(column=>column.items.map(ticket=>[ticket.id,['finished','cancelled'].includes(ticket.status)?1:3])));
    return page.evaluate(expectedActions => {
        const failures=[], rect=node=>{const b=node.getBoundingClientRect();return {left:b.left,right:b.right,top:b.top,bottom:b.bottom,width:b.width,height:b.height};};
        const visible=node=>{const s=getComputedStyle(node),r=node.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;};
        const inside=(a,b,t=1.2)=>a.left>=b.left-t&&a.right<=b.right+t&&a.top>=b.top-t&&a.bottom<=b.bottom+t;
        const overlaps=(a,b)=>Math.min(a.right,b.right)-Math.max(a.left,b.left)>.75&&Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>.75;
        const cards=[...document.querySelectorAll('.ph-ticket-compact')], allParts=[];
        for(const card of cards){
            const id=card.dataset.phoneTicket, bounds=rect(card), parts=[];
            const list=card.closest('.ph-board-cards'),listBounds=rect(list);
            if(bounds.left<listBounds.left-1||bounds.right>listBounds.right+1)failures.push(id+' outside list horizontally');
            if(list.scrollWidth>list.clientWidth+1)failures.push(id+' horizontal list overflow');
            const buttons=[...card.querySelectorAll('.ph-ticket-quick-actions button')];
            if(buttons.length!==expectedActions[id])failures.push(id+' missing expected action controls');
            for(const node of card.querySelectorAll('.ph-ticket-number,.ph-ticket-name,.ph-ticket-amount,.ph-preparation-timer,.ph-ticket-finished-at,.ph-ticket-quick-actions button,.ph-board-select')){
                const label=node.className+' '+node.textContent.trim();if(!visible(node)){failures.push(id+' hidden mandatory card content: '+label);continue;}
                const box=rect(node),part={id,label,box};parts.push(part);
                // A scrolled-off card has layout boxes but cannot paint over the next mobile stage.
                const painted={left:Math.max(box.left,listBounds.left),right:Math.min(box.right,listBounds.right),top:Math.max(box.top,listBounds.top),bottom:Math.min(box.bottom,listBounds.bottom)};
                if(painted.right>painted.left&&painted.bottom>painted.top)allParts.push({...part,box:painted});
                if(!inside(box,bounds))failures.push(id+' escapes card: '+label+' '+JSON.stringify({box,bounds}));
                if(!node.matches('.ph-ticket-name')&&(node.scrollWidth>node.clientWidth+1||node.scrollHeight>node.clientHeight+1))failures.push(id+' clips label: '+label+' '+node.scrollWidth+'/'+node.clientWidth+' '+node.scrollHeight+'/'+node.clientHeight);
                if(node.matches('.ph-ticket-name')&&node.title!==node.textContent)failures.push(id+' truncated customer name lacks full title');
                if(node.matches('.ph-ticket-quick-actions button,.ph-preparation-timer,.ph-ticket-finished-at')){
                    const walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT);let text;
                    while(text=walker.nextNode()){if(!text.textContent.trim())continue;const range=document.createRange();range.selectNodeContents(text);for(const line of range.getClientRects())if(line.width&&line.height&&!inside(rect({getBoundingClientRect:()=>line}),box,2))failures.push(id+' text outside label: '+label);}
                }
            }
            for(let i=0;i<parts.length;i++)for(let j=i+1;j<parts.length;j++)if(overlaps(parts[i].box,parts[j].box))failures.push(id+' overlap: '+parts[i].label+' / '+parts[j].label);
            if(card.scrollWidth>card.clientWidth+1)failures.push(id+' horizontal card overflow');
        }
        for(let i=0;i<allParts.length;i++)for(let j=i+1;j<allParts.length;j++)if(allParts[i].id!==allParts[j].id&&overlaps(allParts[i].box,allParts[j].box))failures.push('cross-card overlap '+allParts[i].id+'/'+allParts[j].id);
        const density=[...document.querySelectorAll('.ph-board-column')].map(column=>{
            const list=column.querySelector('.ph-board-cards'), bounds=rect(list), children=[...list.querySelectorAll('.ph-ticket-compact')], tenth=rect(children[9]);
            return {stage:column.dataset.phoneColumn,width:bounds.width,height:bounds.height,tenCapacity:tenth.bottom-rect(children[0]).top,visible:children.filter(card=>inside(rect(card),bounds)).length};
        });
        return {failures,density,count:cards.length,overflow:Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)-innerWidth,
            preparingTimers:document.querySelectorAll('.ph-board-preparing .ph-preparation-timer').length,courierTimers:document.querySelectorAll('.ph-board-courier .ph-preparation-timer').length,
            finishedTimes:document.querySelectorAll('.ph-board-finished .ph-ticket-finished-at').length,timers:[...document.querySelectorAll('.ph-preparation-timer-value')].map(node=>node.textContent),
            finishedValues:[...document.querySelectorAll('.ph-ticket-finished-at time')].map(node=>({text:node.textContent.trim(),datetime:node.getAttribute('datetime'),visible:visible(node)})),viewport:{width:innerWidth,height:innerHeight}};
    },expectedActions);
}
const cases=[{width:1920,height:1200,dense:true},{width:1920,height:1080,dense:true},{width:1920,height:1006},{width:1536,height:864,zoom:'1920 at 125%'},{width:1366,height:900},{width:1366,height:768},{width:1280,height:720,zoom:'1920 at 150%'},{width:1093,height:720,zoom:'1366 at 125%'},{width:1024,height:768},{width:911,height:640,zoom:'1366 at 150%'},{width:768,height:1024},{width:390,height:844}];
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});let densityCases=0,narrowColumns=0;
    try{
        for(const viewport of cases){
            const {page,errors,calls,unexpected}=await setup(browser,{width:viewport.width,height:viewport.height});
            const result=await measure(page), label=viewport.width+'x'+viewport.height+(viewport.zoom?' ('+viewport.zoom+')':'');
            if(process.env.PHONE_LAYOUT_SCREENSHOT_DIR){fs.mkdirSync(process.env.PHONE_LAYOUT_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.PHONE_LAYOUT_SCREENSHOT_DIR,'phone-cards-'+viewport.width+'x'+viewport.height+'.png'),fullPage:true});}
            assert.equal(result.count,36,label+' renders all three real ticket columns');
            assert.equal(result.preparingTimers,12,label+' preparing timers exist');assert.equal(result.courierTimers,12,label+' courier timers continue from creation');assert.equal(result.finishedTimes,12,label+' finished cards show the completion timestamp');
            assert(result.timers.every(value=>/^\d{2}:\d{2}:\d{2}$/.test(value)),label+' exercises HH:MM:SS timer width');assert.equal(result.finishedValues.length,12,label+' contains actual completion time elements');assert(result.finishedValues.every(value=>value.text&&value.visible&&value.datetime===completion),label+' completion times visibly use the server completed_at');
            assert(result.overflow<=1,label+' has no page horizontal overflow: '+result.overflow);assert.deepEqual(result.failures,[],label+' layout collisions/clipping');
            for(const column of result.density){if(viewport.width>=1000&&column.width<300)narrowColumns++;if(viewport.width>=1000&&column.height>=column.tenCapacity+10){densityCases++;assert(column.visible>=10,label+' '+column.stage+' fits ten complete cards when capacity permits');}}
            if(viewport.dense)for(const column of result.density)assert(column.visible>=10,label+' '+column.stage+' preserves ten-card desktop density');
            assert.deepEqual(errors,[],label+' browser errors');assert.deepEqual(unexpected,[],label+' unexpected network');assert(calls.every(call=>call.method==='GET'));
            console.log(label+': '+result.density.map(column=>column.stage+' '+Math.round(column.width)+'px / '+column.visible+' visible').join(', '));
            await page.evaluate(()=>window.layoutCleanup.forEach(callback=>callback()));await page.close();
        }
        assert(narrowColumns>0,'the matrix must exercise desktop board columns narrower than 300px');assert(densityCases>0,'a desktop viewport must have and verify ten-card capacity');
        console.log('Phone delivery cards: real live layout, long Arabic names/actions, courier timers, finished times, containment, unclipped labels, non-overlap, responsive/zoom widths and ten-card density passed.');
    }finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exitCode=1;});
