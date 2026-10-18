// Real page scripts with fixture HTTP replies; all-branch views must remain read only.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const base=path.join(__dirname,'../..');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const branches=[{value:'f:100',name:'Main'},{value:'f:101',name:'Other'}];
function template(page,selector){return fs.readFileSync(path.join(base,'resources/views/admin/'+page+'/index.blade.php'),'utf8').split("@section('content')")[1].split('@endsection')[0].replace(new RegExp('<select '+selector+'[^>]*>[\\s\\S]*?</select>'),'<select '+selector+'><option value="all">جميع الفروع</option>'+branches.map(b=>'<option value="'+b.value+'">'+b.name+'</option>').join('')+'</select>');}
function dom(page,selector,id,boot){return new JSDOM('<meta name="csrf-token" content="test">'+template(page,selector)+'<script id="'+id+'" type="application/json">'+JSON.stringify(boot)+'</script>',{url:'https://branch-test.invalid',runScripts:'outside-only'});}
function script(w,name){w.eval(fs.readFileSync(path.join(base,'public/dashboard/js/'+name),'utf8'));}
function stockData(branch='all'){
 const all=branch==='all',items=(all?branches:branches.filter(b=>b.value===branch)).map(b=>({id:1,name:'Pepper',unit:'kg',stock:{quantity:b.value==='f:100'?'1.25':'4.5',unit:'kg',unit_label:'كجم'},...(all?{branch:b.value,branch_name:b.name}:{})}));
 return {success:true,all_branches:all,branch:all?{value:'all',name:'جميع الفروع'}:branches.find(b=>b.value===branch),items,ingredients:[{id:1,name:'Pepper',unit:'kg'}],history:[],legacy:[],unmapped_sales:[],can_manage_recipes:!all,pagination:{page:1,last_page:1,total:items.length}};
}
function shiftData(branch='all'){
 const report=b=>({branch:b,started_at:'2026-10-18 06:00',pending_expenses:0,unsettled_app_cash:0});
 return {success:true,all_branches:branch==='all',report:report(branch==='all'?{value:'all',name:'جميع الفروع'}:branches.find(b=>b.value===branch)),branch_reports:branch==='all'?branches.map(report):[],previous_closing_id:0,review_token:branch==='all'?undefined:'a'.repeat(64),history:[{id:1,number:'SHIFT-1',started_at:'06:00',closed_at:'12:00',branch:'f:100',branch_name:'Main',print_url:'/print/1'}]};
}
(async()=>{
 const boot={actor_id:1,can_all:true,branches,branch:'all',initial:stockData(),urls:{data:'/data',recipes:'/recipes',receive:'/receive',recipe_save:'/save',recover:'/recover'}};
 const d=dom('branch_stock','data-stock-branch','branch-stock-bootstrap',boot),w=d.window,doc=w.document,calls=[];
 w.confirm=()=>true;w.fetch=async(url,options={})=>{const u=new URL(url);calls.push({u,options});const selected=u.searchParams.get('branch');return {ok:true,status:200,json:async()=>u.pathname==='/recipes'?{success:true,branch:branches.find(b=>b.value===selected),items:[],ingredients:boot.initial.ingredients,can_manage:true,pagination:{page:1,last_page:1,total:0}}:stockData(selected)};};
 try{
  script(w,'branch-stock.js');script(w,'branch-recipes.js');
  const form=doc.querySelector('[data-stock-form]'),branch=doc.querySelector('[data-stock-branch]');
  assert.equal(branch.value,'all');assert(!branch.disabled);assert(form.elements.quantity.disabled);assert(doc.querySelector('[data-stock-save]').disabled);assert([...doc.querySelectorAll('.bs-item')].every(b=>b.disabled));
  assert.match(doc.querySelector('[data-stock-rows]').textContent,/Main.*Pepper.*Other.*Pepper/);
  form.dispatchEvent(new w.Event('submit',{cancelable:true}));assert.equal(calls.length,0);
  doc.querySelector('button[data-inventory-tab="recipes"]').click();await tick();assert.equal(calls.length,0);assert.match(doc.querySelector('[data-recipe-products]').textContent,/اختر فرعًا/);
  branch.value='f:100';branch.dispatchEvent(new w.Event('change'));await tick();await tick();assert(!form.elements.quantity.disabled);assert(calls.some(c=>c.u.pathname==='/recipes'&&c.u.searchParams.get('branch')==='f:100'));
  const recipeCalls=calls.filter(c=>c.u.pathname==='/recipes').length;
  branch.value='all';branch.dispatchEvent(new w.Event('change'));await tick();await tick();assert.equal(calls.filter(c=>c.u.pathname==='/recipes').length,recipeCalls);assert(form.elements.quantity.disabled);assert(doc.querySelector('[data-recipe-form]').hidden);assert(!calls.some(c=>c.options.method==='POST'));
 }finally{w.close();}
 const sh=dom('branch_shifts','data-sh-branch','branch-shifts-bootstrap',{branches,can_all:true,selected_branch:'all',initial:shiftData(),urls:{data:'/data',close:'/close',recover:'/recover'}}),sw=sh.window,sd=sw.document,requests=[];
 sw.confirm=()=>true;sw.fetch=async(url,options={})=>{requests.push({url,options});return {ok:true,status:200,json:async()=>shiftData(new URL(url).searchParams.get('branch'))};};
 try{
  script(sw,'branch-shifts.js');const form=sd.querySelector('[data-sh-form]'),branch=sd.querySelector('[data-sh-branch]'),counted=sd.querySelector('[data-sh-counted]');
  assert(sd.querySelector('.sh-layout').hidden);assert(!sd.querySelector('[data-sh-all]').hidden);assert.equal(sd.querySelector('[data-sh-branch-reports]').children.length,2);assert(counted.disabled);assert.match(sd.querySelector('[data-sh-history]').textContent,/Main/);
  form.dispatchEvent(new sw.Event('submit',{cancelable:true}));assert.equal(requests.length,0);
  branch.value='f:100';branch.dispatchEvent(new sw.Event('change'));await tick();await tick();assert(!sd.querySelector('.sh-layout').hidden);assert(!counted.disabled);
  branch.value='all';branch.dispatchEvent(new sw.Event('change'));await tick();await tick();assert(counted.disabled);assert(sd.querySelector('.sh-layout').hidden);assert(!requests.some(r=>r.options.method==='POST'));
 }finally{sw.close();}
 console.log('STOCK_SHIFT_ALL_BRANCHES_DOM_OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
