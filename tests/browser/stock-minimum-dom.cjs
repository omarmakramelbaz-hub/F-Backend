const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const base=path.join(__dirname,'../..'),tick=()=>new Promise(resolve=>setImmediate(resolve));
const branches=[{value:'f:100',name:'Main'},{value:'f:101',name:'Other'}];
const template=fs.readFileSync(path.join(base,'resources/views/admin/branch_stock/index.blade.php'),'utf8').split("@section('content')")[1].split('@endsection')[0].replace(/<select data-stock-branch[^>]*>[\s\S]*?<\/select>/,'<select data-stock-branch><option value="all">جميع الفروع</option>'+branches.map(b=>'<option value="'+b.value+'">'+b.name+'</option>').join('')+'</select>');
function fixture(canManage=true){
 const quantities=new Map([['f:100',{minimum_quantity:'2.000',low_stock:true,minimum_revision:3}],['f:101',{minimum_quantity:null,low_stock:false,minimum_revision:0}]]),calls=[];
 function data(){return {success:true,all_branches:true,branch:{value:'all',name:'جميع الفروع'},items:branches.map(b=>({id:7,name:'Pepper',unit:'kg',branch:b.value,branch_name:b.name,stock:{quantity:'2.000',unit:'kg',unit_label:'كجم',stock_type:'ingredient',...quantities.get(b.value)}})),history:[],legacy:[],unmapped_sales:[],can_manage_minimum:canManage,minimum_alerts_available:true,pagination:{page:1,last_page:1,total:2}};}
 const boot={actor_id:1,can_all:true,branches,branch:'all',initial:data(),urls:{data:'/data',receive:'/receive',recover:'/recover',minimum_save:'/minimum'}},d=new JSDOM('<meta name="csrf-token" content="test">'+template+'<script id="branch-stock-bootstrap" type="application/json">'+JSON.stringify(boot)+'</script>',{url:'https://branch-test.invalid',runScripts:'outside-only',pretendToBeVisual:true}),w=d.window,timers=[],cleared=[];
 let cleanup;
 w.setInterval=(fn,ms)=>{timers.push({fn,ms});return timers.length;};w.clearInterval=id=>cleared.push(id);
 w.DashboardSPA={isCurrentPage:()=>true,onBeforeLeave:()=>{},onCleanup:fn=>{cleanup=fn;}};
 w.confirm=()=>true;
 w.fetch=async(url,options={})=>{calls.push({url,options});if(options.method==='POST'){const op=JSON.parse(options.body);quantities.set(op.branch,{minimum_quantity:op.minimum_quantity,low_stock:op.minimum_quantity!==null&&2<=Number(op.minimum_quantity),minimum_revision:op.expected_revision+1});return {ok:true,status:200,json:async()=>({success:true})};}return {ok:true,status:200,json:async()=>data()};};
 w.eval(fs.readFileSync(path.join(base,'public/dashboard/js/branch-stock.js'),'utf8'));
 return {d,w,doc:w.document,calls,timers,cleared,cleanup:()=>cleanup()};
}
(async()=>{
 const {w,doc,calls,timers,cleared,cleanup}=fixture();
 try{
  const rows=()=>[...doc.querySelector('[data-stock-rows]').children];
  assert(rows()[0].classList.contains('bs-low-stock'),'equal-to-minimum row is red');assert.match(rows()[0].textContent,/مخزون منخفض/);assert(!rows()[1].classList.contains('bs-low-stock'));
  assert(doc.querySelector('[data-stock-save]').disabled,'receiving stays disabled for all branches');assert([...doc.querySelectorAll('.bs-item')].every(b=>b.disabled));
  assert.equal(timers[0].ms,30000);timers[0].fn();await tick();await tick();assert.equal(calls.length,1,'visible idle screen refreshes balances');
  const toggle=rows()[1].querySelector('.bs-minimum-toggle');assert(!toggle.disabled,'minimum can be edited for the explicit row branch');toggle.click();
  const editor=()=>doc.querySelector('.bs-minimum-editor');assert(editor());assert(doc.querySelector('[data-stock-branch]').disabled);assert(doc.querySelector('[data-stock-search]').disabled);assert.equal(editor().querySelector('input').value,'');
  timers[0].fn();await tick();assert.equal(calls.length,1,'refresh does not discard an open minimum editor');
  editor().querySelector('input').value='٠';editor().dispatchEvent(new w.Event('submit',{cancelable:true}));await tick();await tick();
  let posts=calls.filter(c=>c.options.method==='POST'),op=JSON.parse(posts[0].options.body);assert.equal(op.branch,'f:101');assert.equal(op.stock_item_id,7);assert.equal(op.stock_type,'ingredient');assert.equal(op.minimum_quantity,'0');assert.equal(op.expected_revision,0);assert.match(op.idempotency_key,/^[a-f0-9-]{36}$/);assert.equal(posts[0].options.headers['X-CSRF-TOKEN'],'test');assert(!editor());assert.equal(rows()[1].querySelector('.bs-minimum-toggle').textContent,'0');assert(!rows()[1].classList.contains('bs-low-stock'));
  rows()[0].querySelector('.bs-minimum-toggle').click();editor().querySelector('input').value='-1';editor().dispatchEvent(new w.Event('submit',{cancelable:true}));await tick();assert.equal(calls.filter(c=>c.options.method==='POST').length,1,'negative threshold rejected');assert.match(doc.querySelector('[data-stock-message]').textContent,/صفرًا أو أكثر/);
  editor().querySelector('input').value='';editor().dispatchEvent(new w.Event('submit',{cancelable:true}));await tick();await tick();posts=calls.filter(c=>c.options.method==='POST');op=JSON.parse(posts[1].options.body);assert.equal(op.branch,'f:100');assert.equal(op.minimum_quantity,null,'empty disables instead of becoming zero');assert.equal(op.expected_revision,3);assert(!rows()[0].classList.contains('bs-low-stock'));
  rows()[1].querySelector('.bs-minimum-toggle').click();editor().querySelector('input').value='5';editor().dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));assert(!editor());assert(!doc.querySelector('[data-stock-branch]').disabled);assert.equal(calls.filter(c=>c.options.method==='POST').length,2,'cancel never saves');
  const previousCalls=calls.length;Object.defineProperty(doc,'hidden',{value:true,configurable:true});timers[0].fn();await tick();assert.equal(calls.length,previousCalls,'background page stays idle');Object.defineProperty(doc,'hidden',{value:false,configurable:true});
  doc.querySelector('[data-stock-form] input[name="quantity"]').dispatchEvent(new w.Event('input',{bubbles:true}));timers[0].fn();await tick();assert.equal(calls.length,previousCalls,'refresh does not discard a receipt draft');
  cleanup();assert.deepEqual(cleared,[1],'SPA cleanup removes background timer');
 }finally{w.close();}
 const readOnly=fixture(false);try{assert.equal(readOnly.doc.querySelectorAll('.bs-minimum-toggle').length,0,'branch-only actor cannot manage thresholds');assert(readOnly.doc.querySelector('.bs-low-stock'));assert.match(readOnly.doc.querySelector('.bs-minimum-cell').textContent,/2\.000/);}finally{readOnly.w.close();}
 console.log('STOCK_MINIMUM_DOM_OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
