'use strict';
// Render the real shift-close Blade and JavaScript with local-only responses; no business writes or printing.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES||process.env.NODE_PATH||'','playwright'));
const root=path.resolve(__dirname,'../..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'shift-pending-invoices-'));
const php=process.env.CRM_TEST_PHP||'php',autoload=process.env.CRM_TEST_AUTOLOAD;
assert(autoload&&fs.existsSync(autoload),'CRM_TEST_AUTOLOAD must point to isolated Laravel dependencies');
const branches=[{value:'f:100',name:'شبرا الخيمة'},{value:'f:101',name:'المنصورة'}],warning='برجاء إغلاق الفواتير أولًا.';
function invoice(source,id,extra={}){return{source,id,number:source.toUpperCase()+'-'+id,label:{dine:'صالة',phone:'دليفري',app:'تطبيق'}[source],status:source==='dine'?'awaiting_bill':'preparing',status_label:source==='dine'?'بانتظار الحساب':'قيد التجهيز',table_name:source==='dine'?'12':'',branch_name:branches[0].name,url:'/admin/'+source+'?invoice='+id,...extra};}
function pending(items){return{total:items.length,counts:{dine:items.filter(i=>i.source==='dine').length,phone:items.filter(i=>i.source==='phone').length,app:items.filter(i=>i.source==='app').length},items};}
function response(selected,byBranch){
    const all=selected==='all',one=b=>({branch:b,started_at:'2026-10-20 06:00',pending_expenses:0,unsettled_app_cash:0,pending_invoices:pending(byBranch[b.value]||[])});
    return{success:true,all_branches:all,report:all?{...one({value:'all',name:'جميع الفروع'}),pending_invoices:pending(branches.flatMap(b=>byBranch[b.value]||[]))}:one(branches.find(b=>b.value===selected)),branch_reports:all?branches.map(one):[],previous_closing_id:1,review_token:'a'.repeat(64),history:[]};
}
function html(boot){
    const file=path.join(tmp,'boot.json');fs.writeFileSync(file,JSON.stringify(boot));
    const render=String.raw`require getenv('CRM_TEST_AUTOLOAD');$boot=json_decode(file_get_contents(getenv('QA_BOOT')),true);$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/branch_shifts/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,getenv('QA_CACHE'));$__env=new class {use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));echo ob_get_clean();`;
    const content=cp.execFileSync(php,['-r',render],{encoding:'utf8',env:{...process.env,CRM_TEST_AUTOLOAD:autoload,QA_ROOT:root,QA_BOOT:file,QA_CACHE:tmp}});
    const blade=fs.readFileSync(path.join(root,'resources/views/admin/branch_shifts/index.blade.php'),'utf8'),style=(blade.match(/<style>([\s\S]*?)<\/style>/)||[])[1]||'';
    const css=fs.readFileSync(process.env.SHIFT_TEST_CSS||path.join(root,'public/dashboard/css/branch-shifts.css'),'utf8');
    return`<!doctype html><html dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>html,body{margin:0;font-family:Tahoma,Arial,sans-serif}.qa-header{height:42px;background:#102c46;color:white;padding:0 16px;display:flex;align-items:center;box-sizing:border-box}.main-footer{height:30px;background:#102c46;color:white;text-align:center;padding:5px;box-sizing:border-box}${css}${style}</style></head><body><header class="qa-header">فسخانستا · معاينة تقفيل الوردية</header>${content}<footer class="main-footer">بيانات اختبار</footer><script type="application/json" id="branch-shifts-bootstrap">${JSON.stringify(boot).replaceAll('<','\\u003c')}</script><script>${fs.readFileSync(path.join(root,'public/dashboard/js/branch-shifts.js'),'utf8')}</script></body></html>`;
}
async function setup(browser,{selected='f:100',byBranch={'f:100':[invoice('dine',7)]},newPendingOnClose=null}={}){
    const state=structuredClone(byBranch),boot={branches,can_all:true,selected_branch:selected,initial:response(selected,state),urls:{data:'/shift/data',close:'/shift/close',recover:'/shift/recover',reprint:'/shift/reprint'}};
    const page=await browser.newPage({viewport:{width:1280,height:800}}),calls=[],errors=[],outside=[];let conflict=newPendingOnClose;
    page.on('pageerror',error=>errors.push(error.message));await page.addInitScript(()=>{window.confirm=()=>true;window.DashboardInvoiceDetails={open:()=>{}};window.print=()=>{throw Error('No printing in QA');};});
    await page.route('**/*',async route=>{
        const req=route.request(),u=new URL(req.url());if(u.origin!=='https://shift.test'){outside.push(u.href);return route.abort();}
        if(u.pathname==='/shift')return route.fulfill({contentType:'text/html',body:html(boot)});
        const payload=req.method()==='POST'?req.postDataJSON():Object.fromEntries(u.searchParams);calls.push({path:u.pathname,method:req.method(),payload});
        if(u.pathname==='/shift/data')return route.fulfill({contentType:'application/json',body:JSON.stringify(response(payload.branch,state))});
        if(u.pathname==='/shift/close'){
            assert.equal(req.headers()['x-csrf-token'],'fixture-token');
            if(conflict){state[payload.branch]=structuredClone(conflict);conflict=null;return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({success:false,message:warning})});}
            return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,closing:{id:2}})});
        }
        outside.push(u.href);return route.abort();
    });
    await page.goto('https://shift.test/shift');await page.locator('[data-sh-pending-total]').waitFor();return{page,calls,errors,outside,set:(b,items)=>state[b]=structuredClone(items)};
}
const closes=ctx=>ctx.calls.filter(call=>call.method==='POST'&&call.path==='/shift/close');
async function submit(page){await page.locator('[data-sh-form]').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));}
async function total(page,value){await page.waitForFunction(expected=>document.querySelector('[data-sh-pending-total]').textContent===String(expected),value);}
async function clean(ctx){assert.deepEqual(ctx.errors,[]);assert.deepEqual(ctx.outside,[]);await ctx.page.close();}
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_TEST_CHROMIUM||undefined});
    try{
        const first=await setup(browser),page=first.page;await total(page,1);assert.match(await page.locator('[data-sh-pending-counts]').innerText(),/صالة: 1 · دليفري: 0 · تطبيق: 0/);
        assert.match(await page.locator('[data-sh-pending-invoices]').innerText(),/DINE-7.*صالة.*شبرا الخيمة.*12.*بانتظار الحساب/s);assert(await page.locator('[data-sh-pending-warning]').isVisible());assert(await page.locator('.sh-close').isDisabled());
        assert(!await page.locator('[data-sh-counted]').isDisabled(),'blind cash entry remains editable while pending invoices are shown');assert(!await page.locator('[data-sh-refresh]').isDisabled());assert(!await page.locator('[data-sh-branch]').isDisabled());
        await page.locator('[data-sh-counted]').fill('123.45');await submit(page);assert.equal(closes(first).length,0,'pending invoice prevents POST even through direct form submission');assert.equal(await page.locator('[data-sh-message]').innerText(),warning);
        assert.match(await page.locator('.sh-carry').innerText(),/رصيد الدرج مستمر/,'continuous cash policy stays visible');
        await page.screenshot({path:'/tmp/shift-pending-invoices-blocked.png',fullPage:true});
        first.set('f:100',[]);await page.locator('[data-sh-refresh]').click();await total(page,0);await page.waitForFunction(()=>!document.querySelector('.sh-close').disabled);assert(await page.locator('[data-sh-pending-warning]').isHidden());assert(await page.locator('[data-sh-pending-empty]').isVisible());assert(await page.locator('[data-sh-message]').isHidden(),'resolved pending warning is cleared after refresh');
        assert.equal(await page.locator('[data-sh-counted]').inputValue(),'123.45','refresh preserves the entered blind cash amount');await submit(page);await page.waitForFunction(()=>document.querySelector('[data-sh-message]').classList.contains('is-success'));
        assert.equal(closes(first).length,1);assert.equal(closes(first)[0].payload.counted_cash,'123.45');await clean(first);
        const race=await setup(browser,{byBranch:{'f:100':[]},newPendingOnClose:[invoice('phone',8)]});await race.page.locator('[data-sh-counted]').fill('50');await submit(race.page);await total(race.page,1);assert(await race.page.locator('.sh-close').isDisabled());assert.match(await race.page.locator('[data-sh-pending-invoices]').innerText(),/PHONE-8/);assert.equal(closes(race).length,1);await submit(race.page);assert.equal(closes(race).length,1,'server conflict refresh locks newly discovered pending invoices');await clean(race);
        const unsafe=invoice('dine',99,{number:'<img src=x onerror=alert(1)>',url:'https://outside.invalid/steal'}),all=await setup(browser,{selected:'all',byBranch:{'f:100':[unsafe,invoice('phone',8)],'f:101':[invoice('app',9,{branch_name:branches[1].name,url:'javascript:alert(1)'})]}});
        await total(all.page,3);assert.match(await all.page.locator('[data-sh-pending-counts]').innerText(),/صالة: 1 · دليفري: 1 · تطبيق: 1/);assert.equal(await all.page.locator('[data-sh-pending-invoices] tr').count(),3);assert.equal(await all.page.locator('[data-sh-pending-invoices] a').count(),1,'only same-origin HTTP invoice links become navigable');assert.equal(await all.page.locator('[data-sh-pending-invoices] img').count(),0,'invoice number is escaped as text');assert.match(await all.page.locator('[data-sh-pending-invoices]').innerText(),/<img src=x/);
        assert(await all.page.locator('.sh-layout').isHidden());assert(await all.page.locator('[data-sh-counted]').isDisabled());assert(await all.page.locator('.sh-close').isDisabled());await submit(all.page);assert.equal(closes(all).length,0);assert.deepEqual(await all.page.locator('[data-sh-branch-reports] tr').evaluateAll(rows=>rows.map(r=>r.children[2].textContent)),['2','1']);
        await all.page.locator('[data-sh-branch]').selectOption('f:101');await total(all.page,1);assert(!await all.page.locator('.sh-layout').isHidden());assert(!await all.page.locator('[data-sh-counted]').isDisabled());assert(await all.page.locator('.sh-close').isDisabled());
        await all.page.setViewportSize({width:390,height:844});assert(await all.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile pending area keeps horizontal scrolling inside its table');await all.page.screenshot({path:'/tmp/shift-pending-invoices-mobile.png',fullPage:true});await clean(all);
        console.log('SHIFT_PENDING_INVOICES_BROWSER_OK initial pending invoice blocks POST; refresh clears lock; 409 discovers new pending; all-branch counts; same-origin escaped links; cash continuity preserved.');
    }finally{await browser.close();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(error=>{console.error(error);process.exit(1);});
