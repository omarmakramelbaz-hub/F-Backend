'use strict';
// Browser behavior against production modal/date/table functions and a controlled read-only API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const repo = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(repo, 'public/dashboard/js/takeaway-pos.js'), 'utf8');
function section(start, end) { const from=source.indexOf(start), to=source.indexOf(end, from); assert(from>=0&&to>from, 'Production function markers must exist'); return source.slice(from,to); }
const production = [section('    function label(', '    function listen('),
    section('    function decimalInput(', '    function quantity('),
    section('    function endpoint(', '    function post('),
    section('    function updateRegister(', '    function unitText('),
    section('    function openModal(', '    function productChoices('),
    section('    function receiptTable(', '    function till(')].join('\n');
const php = process.env.TEST_PHP || 'php';
const labels = JSON.parse(execFileSync(php, ['-r', 'echo json_encode(require $argv[1]);', path.join(repo,'resources/lang/ar/takeaway.php')], {encoding:'utf8'}));
const fixture = `
var root=document.querySelector('#takeaway-pos'),modal=root.querySelector('[data-pos-modal]');
var labels=${JSON.stringify(labels)},urls={daily:'/receipts'};
var boot={today:{date:'2026-11-06',count:70,total:'41011.10'},can_view_all_branches:false};
var invoices=[{today:{date:'2026-11-06',count:70,total:'41011.10'},cart:[{id:55}]}],activeInvoice=0;
var register={balance:'41011.10'},branch='f:1',quarantined=false,registerLocked=false,disposed=false;
var modalGeneration=0,modalController,modalFocus,consentEnterAffirmed=false;
var test={calls:[],pending:[],mode:'ok',day:'2026-11-05',details:[],prints:[],notices:[]};
function notify(value){test.notices.push(value);}
function canReprint(){return true;}
function printReceipt(value){test.prints.push(value);}
window.DashboardInvoiceDetails={open:function(value){test.details.push(value);}};
function payload(url){var u=new URL(url);var date=u.searchParams.get('date')||test.day;var page=Number(u.searchParams.get('page'));return {success:true,today:{date:date,count:23,total:'1234.50'},items:test.mode==='empty'?[]:[{number:'TA-'+date+'-'+page,branch:{name:'فرع المنصورة'},channel:'takeaway',created_label:date+' 10:30 PM',payment_method:'cash',total:'1234.50',receipt_url:'/receipt/42/print',details_url:'/receipt/42/details'}],pagination:{page:page,last_page:3,total:23}};}
window.fetch=function(url,options){test.calls.push({url:url,options:options});if(test.mode==='defer')return new Promise(function(resolve){test.pending.push({resolve:resolve,url:url});});return Promise.resolve({ok:test.mode!=='error',status:test.mode==='error'?500:200,json:async function(){return payload(url);}});};
${production}
test.resolve=function(index){var pending=test.pending[index];pending.resolve({ok:true,status:200,json:async function(){return payload(pending.url);}});};
test.snapshot=function(){return {calls:test.calls.map(function(call){return {url:call.url,cache:call.options.cache,credentials:call.options.credentials,aborted:call.options.signal.aborted};}),today:invoices[0].today,register:register,cart:invoices[0].cart,details:test.details,prints:test.prints,notices:test.notices};};
window.test=test;window.daily=daily;window.closeModal=closeModal;
`;
const html = `<!doctype html><html dir="rtl" lang="ar"><meta charset="utf-8"><style>body{margin:0;background:#071829;font-family:Arial,sans-serif}.tp-pos{min-height:100vh}.tp-panel{box-shadow:0 8px 45px #0003}.dashboard-details-button{padding:8px;border:1px solid #c4d6e6;border-radius:7px;background:#f3f7fb;color:#1e4568}.tp-header-actions{padding:20px;color:#fff}.tp-modal-content table{overflow-wrap:anywhere}.tp-modal-content td{vertical-align:middle}</style><style>${fs.readFileSync(path.join(repo,'public/dashboard/css/takeaway-pos.css'),'utf8')}</style><main class="tp-pos" id="takeaway-pos"><div class="tp-header-actions"><button id="launcher">فواتير اليوم <strong data-pos-day-count>70</strong></button><button>درج الكاشير <bdi data-pos-register-balance>41,011.10</bdi></button></div><div class="tp-modal-backdrop" data-pos-modal hidden><section class="tp-modal tp-panel" role="dialog" aria-modal="true" tabindex="-1"><header class="tp-panel-heading"><h2></h2><button type="button" onclick="closeModal()">×</button></header><div class="tp-modal-content" data-pos-modal-content></div></section></div></main></html>`;
async function domTests() {
    const {JSDOM}=require('jsdom');
    const dom=new JSDOM(html,{url:'http://fixture.test/',runScripts:'outside-only',pretendToBeVisual:true});
    const w=dom.window;w.eval(fixture);
    const settle=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate);};
    const input=()=>w.document.querySelector('[data-pos-history-date]');
    const click=label=>{const button=[...w.document.querySelectorAll('button,a')].find(button=>button.textContent===label);assert(button,'Missing action '+label);button.click();};
    const submit=()=>w.document.querySelector('form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
    const lastQuery=()=>new URL(w.test.snapshot().calls.at(-1).url).searchParams;
    await w.daily();assert.equal(input().value,'2026-11-05');assert(!lastQuery().has('date'));
    input().value='2026-10-15';submit();await settle();
    assert.equal(lastQuery().get('date'),'2026-10-15');assert.equal(lastQuery().get('page'),'1');assert.equal(lastQuery().get('branch'),'f:1');
    input().value='2026-10-20';click(labels.next);await settle();
    assert.equal(lastQuery().get('date'),'2026-10-15');assert.equal(lastQuery().get('page'),'2');
    assert(w.document.querySelector('tbody').textContent.includes('2026-10-15-2'));
    click(labels.details);click(labels.print_invoice);
    assert.equal(w.test.details[0],'http://fixture.test/receipt/42/details');assert.equal(w.test.prints[0],'http://fixture.test/receipt/42/print');
    const original=w.test.snapshot();assert.equal(JSON.stringify(original.today),JSON.stringify({date:'2026-11-06',count:70,total:'41011.10'}));
    assert.equal(JSON.stringify(original.register),JSON.stringify({balance:'41011.10'}));assert.equal(JSON.stringify(original.cart),JSON.stringify([{id:55}]));
    assert.equal(w.document.querySelector('[data-pos-day-count]').textContent,'70');
    click(labels.history_today);await settle();assert(!lastQuery().has('date'));assert.equal(input().value,'2026-11-05');
    const count=w.test.calls.length;input().value='';submit();await settle();assert.equal(w.test.calls.length,count);
    w.test.mode='empty';await w.daily(1,'2026-10-01');assert.equal(input().value,'2026-10-01');assert(w.document.querySelector('[data-pos-modal-content]').textContent.includes(labels.daily_empty));
    w.test.mode='error';await w.daily(1,'2026-10-02');assert.equal(input().value,'2026-10-02');assert(w.document.querySelector('[data-pos-modal-content]').textContent.includes(labels.invoices_error));
    w.test.mode='ok';submit();await settle();assert(w.document.querySelector('tbody').textContent.includes('2026-10-02'));
    w.test.mode='defer';const first=w.daily(1,'2026-10-03');w.test.mode='ok';await w.daily(1,'2026-10-04');w.test.resolve(0);await first;
    assert(w.document.querySelector('tbody').textContent.includes('2026-10-04'));assert(w.test.snapshot().calls.at(-2).aborted);
    w.test.mode='defer';const second=w.daily(1,'2026-10-05');w.closeModal();assert(w.test.snapshot().calls.at(-1).aborted);w.test.resolve(1);await second;assert(w.document.querySelector('[data-pos-modal]').hidden);
    w.test.mode='ok';w.eval("branch='';boot.can_view_all_branches=true;");await w.daily(1,'2026-10-15');assert.equal(lastQuery().get('branch'),'all');
    w.closeModal();w.eval('boot.can_view_all_branches=false;');await w.daily();assert(w.document.querySelector('[data-pos-modal]').hidden);assert.equal(w.test.notices.at(-1),labels.choose_branch_first);
    w.eval("branch='f:1';");w.test.mode='defer';w.test.day='2026-11-07';
    const rollover=w.daily();w.eval("invoices[0].today.date='2026-11-07';");w.test.resolve(2);await rollover;
    assert.equal(input().value,'2026-11-07','Background today refresh must not prevent the response day from filling the input');
    w.test.day='2026-11-08';const edited=w.daily();input().value='2026-10-31';w.test.resolve(3);await edited;
    assert.equal(input().value,'2026-10-31','Unsent date edit is preserved during a default request');
    assert(w.document.querySelector('.tp-history-summary').textContent.includes('2026-11-08'));
    assert(w.test.snapshot().calls.every(call=>call.cache==='no-store'&&call.credentials==='same-origin'));
    dom.window.close();
    console.log('PASS (DOM): historical date, paging, today reset, validation, empty/error retry, stale responses, close abort, details/print, branch scope, unchanged today/till/draft, rollover and unsent edits.');
}
(async function(){
    if(process.env.TEST_DOM==='1'){await domTests();return;}
    const {chromium}=require('playwright');
    const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
    try {
        const page=await browser.newPage({viewport:{width:1440,height:1000},timezoneId:'America/Los_Angeles'});
        const browserErrors=[];page.on('pageerror',error=>browserErrors.push(error.message));
        await page.route('http://fixture.test/**',route=>route.fulfill({status:200,contentType:'text/html',body:html}));
        await page.goto('http://fixture.test/');await page.addScriptTag({content:fixture});
        const date=page.locator('[data-pos-history-date]');
        await page.evaluate(()=>daily());
        assert.equal(await date.inputValue(),'2026-11-05','Server operating day wins over stale boot/browser day');
        let state=await page.evaluate(()=>test.snapshot());
        assert(!new URL(state.calls.at(-1).url).searchParams.has('date'));
        assert.equal(state.calls.at(-1).cache,'no-store');assert.equal(state.calls.at(-1).credentials,'same-origin');
        await date.fill('2026-10-15');await page.getByRole('button',{name:labels.show_invoices,exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('.tp-history-summary').textContent.includes('2026-10-15'));
        state=await page.evaluate(()=>test.snapshot());let query=new URL(state.calls.at(-1).url).searchParams;
        assert.equal(query.get('date'),'2026-10-15');assert.equal(query.get('page'),'1');assert.equal(query.get('branch'),'f:1');
        await date.fill('2026-10-20');await page.getByRole('button',{name:labels.next,exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('tbody').textContent.includes('2026-10-15-2'));
        state=await page.evaluate(()=>test.snapshot());query=new URL(state.calls.at(-1).url).searchParams;
        assert.equal(query.get('date'),'2026-10-15','Pagination uses loaded date, not unsent edit');assert.equal(query.get('page'),'2');
        await page.getByRole('button',{name:labels.details,exact:true}).click();
        await page.getByRole('link',{name:labels.print_invoice,exact:true}).click();
        state=await page.evaluate(()=>test.snapshot());assert.equal(state.details[0],'http://fixture.test/receipt/42/details');assert.equal(state.prints[0],'http://fixture.test/receipt/42/print');
        assert.deepEqual(state.today,{date:'2026-11-06',count:70,total:'41011.10'});assert.deepEqual(state.register,{balance:'41011.10'});assert.deepEqual(state.cart,[{id:55}]);
        assert.equal(await page.locator('[data-pos-day-count]').textContent(),'70');
        await page.getByRole('button',{name:labels.history_today,exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('.tp-history-summary').textContent.includes('2026-11-05'));
        state=await page.evaluate(()=>test.snapshot());assert(!new URL(state.calls.at(-1).url).searchParams.has('date'));
        await date.fill('');const before=state.calls.length;await page.getByRole('button',{name:labels.show_invoices,exact:true}).click();
        assert.equal((await page.evaluate(()=>test.snapshot())).calls.length,before,'Blank date is rejected');
        await page.evaluate(()=>{test.mode='empty';return daily(1,'2026-10-01');});
        assert.equal(await date.inputValue(),'2026-10-01');assert(await page.getByText(labels.daily_empty,{exact:true}).isVisible());assert(await page.locator('form.tp-history-filter').isVisible());
        await page.evaluate(()=>{test.mode='error';return daily(1,'2026-10-02');});
        assert.equal(await date.inputValue(),'2026-10-02');assert(await page.getByText(labels.invoices_error,{exact:true}).isVisible());
        await page.evaluate(()=>{test.mode='ok';});await page.getByRole('button',{name:labels.show_invoices,exact:true}).click();
        await page.waitForFunction(()=>!!document.querySelector('tbody'));
        assert((await page.locator('tbody').textContent()).includes('2026-10-02'));
        await page.evaluate(()=>{test.mode='defer';void daily(1,'2026-10-03');});
        await page.evaluate(()=>{test.mode='ok';return daily(1,'2026-10-04');});
        await page.evaluate(()=>test.resolve(0));
        await page.waitForFunction(()=>document.querySelector('tbody').textContent.includes('2026-10-04'));
        assert((await page.locator('tbody').textContent()).includes('2026-10-04'),'Old response cannot replace new day');
        state=await page.evaluate(()=>test.snapshot());assert(state.calls.at(-2).aborted);
        await page.evaluate(()=>{test.mode='defer';void daily(1,'2026-10-05');closeModal();});
        state=await page.evaluate(()=>test.snapshot());assert(state.calls.at(-1).aborted);await page.evaluate(()=>test.resolve(1));assert(await page.locator('[data-pos-modal]').isHidden());
        await page.evaluate(()=>{test.mode='ok';branch='';boot.can_view_all_branches=true;return daily(1,'2026-10-15');});
        state=await page.evaluate(()=>test.snapshot());assert.equal(new URL(state.calls.at(-1).url).searchParams.get('branch'),'all');
        await page.evaluate(()=>{closeModal();boot.can_view_all_branches=false;return daily();});
        assert(await page.locator('[data-pos-modal]').isHidden());assert.equal((await page.evaluate(()=>test.snapshot())).notices.at(-1),labels.choose_branch_first);
        await page.evaluate(()=>{branch='f:1';return daily(1,'2026-10-15');});
        const preview=path.resolve(repo,'../preview');fs.mkdirSync(preview,{recursive:true});
        await page.screenshot({path:path.join(preview,'invoice-date-review-desktop.png')});
        await page.setViewportSize({width:390,height:844});
        const fits=await page.locator('.tp-history-filter').evaluate(form=>{const modal=form.closest('.tp-modal').getBoundingClientRect();return Array.from(form.querySelectorAll('input,button')).every(control=>{const r=control.getBoundingClientRect();return r.left>=modal.left&&r.right<=modal.right;});});assert(fits,'Date controls fit a 390px viewport');
        await page.screenshot({path:path.join(preview,'invoice-date-review-mobile.png')});
        assert.deepEqual(browserErrors,[]);
        console.log('PASS: historical date, paging, today reset, validation, empty/error retry, stale responses, close abort, details/print, branch scope, unchanged today/till/draft, mobile fit.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
