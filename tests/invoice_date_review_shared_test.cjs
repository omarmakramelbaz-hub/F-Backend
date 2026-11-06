'use strict';
// DOM checks for the shared history view and the two production adapters; no live requests.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const repo=path.resolve(__dirname,'..');
const shared=fs.readFileSync(path.join(repo,'public/dashboard/js/dashboard-invoice-details.js'),'utf8').split('// invoice-date-review-all-20261106')[1];
assert(shared,'Shared history marker exists');
const labels={invoice_history:'Invoices',invoice_date:'Date',show_invoices:'Show',history_today:'Today',invoices_loading:'Loading',invoice_day_hint:'6 AM to 6 AM',history_count:'Count',total:'Total',currency:'EGP',daily_empty:'Empty',invoices_error:'Load failed',previous:'Previous',next:'Next',invoice_number:'Number',branch:'Branch',channel:'Channel',date:'Date',payment_method:'Payment',print_invoice:'Print',details:'Details',print_error:'Print failed',takeaway:'Takeaway',phone:'Phone',dine:'Dine',cash:'Cash'};
const settle=async()=>{for(let i=0;i<8;i++)await new Promise(setImmediate);};
function slice(source,start,end){const first=source.indexOf(start),last=source.indexOf(end,first);assert(first>=0&&last>first,start);return source.slice(first,last);}
async function sharedTests(){
 const dom=new JSDOM('<main><div id="content"></div><b id="badge">70</b></main>',{url:'https://fixture.test/admin',runScripts:'outside-only'}),w=dom.window;
 w.eval(shared);const content=w.document.querySelector('#content');let generation=0,controller,mode='ok',serverDay='2026-11-05';const calls=[],pending=[],printed=[],details=[];
 w.DashboardInvoiceDetails={open:url=>details.push(url)};w.DashboardPrint={print:async url=>printed.push(url)};
 function result(url){const q=new URL(url).searchParams,day=q.get('date')||serverDay;return {success:true,today:{date:day,count:23,total:'1200.00'},pagination:{page:Number(q.get('page')),last_page:3},items:mode==='empty'?[]:['phone','dine','takeaway'].map((channel,index)=>({id:index+1,number:index===0?'<img onerror=evil()>':'N'+index,channel,branch:{name:'Mansoura'},created_label:day,payment_method:'cash',total:'400.00',can_reprint:index!==2,receipt_url:'/admin/takeaway/receipts/'+(index+1)+'/print',details_url:index===1?'https://evil.test/receipts/2/details':'/admin/takeaway/receipts/'+(index+1)+'/details'}))};}
 w.fetch=(url,options)=>{calls.push({url,options});if(mode==='defer')return new Promise(resolve=>pending.push(()=>resolve({ok:true,json:async()=>result(url)})));return Promise.resolve({ok:mode!=='error',json:async()=>result(url)});};
 const endpoint=(url,params)=>{const target=new URL(url,w.location.href);Object.entries(params).forEach(([key,value])=>{if(value!==undefined)target.searchParams.set(key,value);});return target.href;};
 function load(page,date){if(controller)controller.abort();controller=new w.AbortController();const active=++generation;return w.DashboardInvoiceHistory.show({content,labels,url:'/receipts',branch:'f:1',page,date,initialDate:'2026-11-06',signal:controller.signal,isCurrent:()=>generation===active,reload:load,endpoint,read:async response=>{if(!response.ok)throw new Error(labels.invoices_error);return response.json();},money:value=>value,canReprint:item=>item.id!==2});}
 const input=()=>content.querySelector('input'),action=name=>{const button=[...content.querySelectorAll('button,a')].find(el=>el.textContent===name);assert(button,name);button.click();};
 await load();assert.equal(input().value,serverDay);assert(!new URL(calls.at(-1).url).searchParams.has('date'));assert.equal(content.querySelectorAll('tbody tr').length,3);
 input().value='2026-10-15';action('Show');await settle();assert.equal(new URL(calls.at(-1).url).searchParams.get('date'),'2026-10-15');assert.equal(new URL(calls.at(-1).url).searchParams.get('page'),'1');
 input().value='2026-10-30';action('Next');await settle();assert.equal(new URL(calls.at(-1).url).searchParams.get('date'),'2026-10-15');assert.equal(new URL(calls.at(-1).url).searchParams.get('page'),'2');
 assert.equal(content.querySelectorAll('tbody a').length,1,'Only server-allowed and host-allowed printing');assert.equal(content.querySelectorAll('tbody button').length,2,'Cross-origin details are excluded');assert.equal(content.querySelectorAll('img').length,0,'Receipt values are text');action('Print');action('Details');await settle();assert.equal(printed.length,1);assert.equal(details[0],'https://fixture.test/admin/takeaway/receipts/1/details');
 const before=calls.length;input().value='';action('Show');await settle();assert.equal(calls.length,before,'Blank date does not request');action('Today');await settle();assert(!new URL(calls.at(-1).url).searchParams.has('date'));
 mode='empty';await load(1,'2026-10-01');assert(content.textContent.includes('Empty'));assert(content.querySelector('form'));
 mode='error';await load(1,'2026-10-02');assert(content.textContent.includes('Load failed'));assert.equal(input().value,'2026-10-02');mode='ok';action('Show');await settle();assert(content.querySelector('table'));
 mode='defer';const old=load(1,'2026-10-03');mode='ok';await load(1,'2026-10-04');pending[0]();await old;assert(content.querySelector('.ih-summary').textContent.includes('2026-10-04'));assert(calls.at(-2).options.signal.aborted);
 mode='defer';const close=load(1,'2026-10-05');controller.abort();generation++;content.replaceChildren();pending[1]();await close;assert.equal(content.children.length,0,'Closed view ignores late response');
 mode='defer';serverDay='2026-11-07';const edit=load();input().value='2026-10-31';pending[2]();await edit;assert.equal(input().value,'2026-10-31');assert(content.querySelector('.ih-summary').textContent.includes('2026-11-07'));
 assert.equal(w.document.querySelector('#badge').textContent,'70');assert(calls.every(call=>call.options.credentials==='same-origin'&&call.options.cache==='no-store'));dom.window.close();
}
function adapterTests(kind){
 const source=fs.readFileSync(path.join(repo,'public/dashboard/js/'+(kind==='dining'?'dining-pos.js':'phone-orders.js')),'utf8');
 const modalAttribute=kind==='dining'?'data-dining-modal-content':'data-phone-modal-content';
 const dom=new JSDOM('<script id="'+kind+'-history-translations" type="application/json">'+JSON.stringify(labels)+'</script><main><div id="modal" hidden><section role="dialog" tabindex="-1"><h2></h2><div '+modalAttribute+'></div></section></div></main>',{url:'https://fixture.test/admin',runScripts:'outside-only'}),w=dom.window;
 const opener=kind==='dining'?slice(source,'    function openModal(','    function productChoices('):slice(source,'    function openModal(','    function invoiceHistory(');
 const adapter=slice(source,'    function invoiceHistory(',kind==='dining'?'    function updateRegister(':'    function closeModal(');
 const closer=kind==='phone'?slice(source,'    function closeModal(','    async function print('):'';
 const controllers=kind==='phone'?slice(source,'    function abort(','    function get('):'';
 // The exact production open/close and adapter functions run with a recorder replacing only the shared renderer.
 w.eval(`var root=document.querySelector('main'),modal=document.querySelector('#modal'),modalGeneration=0,modalController,modalFocus,disposed=false,branch='f:1',busy=false,uncertain=false,consentEnterAffirmed=false,controllers={},lockedValue=false;
 var boot={today:{date:'2026-11-06'},can_view_all_branches:false,permissions:{can_reprint:true}},permissions={can_reprint:true},urls={receipts:'/receipts'},captured=[],notices=[];
 function label(key){return key;}function text(key){return key;}function locked(){return lockedValue;}function notify(key){notices.push(key);}function node(tag,cls,text){var el=document.createElement(tag);if(text)el.textContent=text;return el;}var element=node;
 function endpoint(url){return url;}function read(value){return value;}function money(value){return value;}function print(){}
 window.DashboardInvoiceHistory={show:function(options){captured.push(options);return Promise.resolve();}};
 ${controllers}${opener}${adapter}${closer}
 window.adapterTest={run:invoiceHistory,close:closeModal,captured:captured};`);
 const api=w.adapterTest;api.run(2,'2026-10-15');let options=api.captured.at(-1);assert.equal(options.branch,'f:1');assert.equal(options.date,'2026-10-15');assert.equal(options.page,2);assert(options.isCurrent());
 api.run();assert(options.signal.aborted,'Opening a newer modal aborts the old request');assert(!options.isCurrent());options=api.captured.at(-1);w.eval("branch='f:2';");assert(!options.isCurrent(),'Branch changes invalidate old response');
 w.eval("branch='';boot.can_view_all_branches=true;");api.run();assert.equal(api.captured.at(-1).branch,'all');
 const before=api.captured.length;w.eval('lockedValue=true;');api.run();assert.equal(api.captured.length,before,'Locked sales cannot open history');w.eval('lockedValue=false;');api.close();assert(api.captured.at(-1).signal.aborted);assert(!api.captured.at(-1).isCurrent());
 w.eval('boot.can_view_all_branches=false;');api.run();assert.equal(api.captured.length,before,'Unscoped account cannot query all branches');
 w.eval("branch='f:1';permissions.can_reprint=false;");api.run();assert(!api.captured.at(-1).canReprint({can_reprint:true}));dom.window.close();
}
(async()=>{await sharedTests();adapterTests('dining');adapterTests('phone');console.log('PASS (DOM): shared invoice history, last-month date, pagination, today, error retry, stale/closed requests, safe details, print permissions, and dining/phone adapter branch/lock/abort guards.');})().catch(error=>{console.error(error);process.exitCode=1;});
