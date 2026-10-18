// Render the production home scripts with scoped inventory; no network or email is used.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const base=path.join(__dirname,'../..'),read=p=>fs.readFileSync(path.join(base,p),'utf8'),tick=()=>new Promise(resolve=>setImmediate(resolve));
const branches=[{value:'f:100',name:'Main'},{value:'f:101',name:'Other'}];
function labels(locale){return Object.fromEntries([...read('resources/lang/'+locale+'/home_overview.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m=>[m[1],m[2]]));}
function inventory(){return {tracked:3,items:[
 {id:1,name:'Renga',unit:'kg',quantity:'105',quantity_units:105000000,tracked_branches:2,negative_branches:0,is_low:true,balances:{'f:100':{quantity:'5',quantity_units:5000000,is_low:true},'f:101':{quantity:'100',quantity_units:100000000,is_low:false}}},
 {id:2,name:'Zero, minimum disabled',unit:'piece',quantity:'0',quantity_units:0,tracked_branches:2,negative_branches:0,is_low:false,balances:{'f:100':{quantity:'0',quantity_units:0,is_low:false},'f:101':{quantity:'0',quantity_units:0,is_low:false}}},
 {id:3,name:'Negative',unit:'piece',quantity:'-1',quantity_units:-1000,tracked_branches:1,negative_branches:1,is_low:false,balances:{'f:100':{quantity:'-1',quantity_units:-1000,is_low:false}}},
 {id:4,name:'Untracked',unit:'kg',quantity:'0',quantity_units:0,tracked_branches:0,negative_branches:0,is_low:false,balances:{}}
]};}
function data(owner){return {success:true,branch_home:true,updated_at:'2026-10-18T22:00:00Z',filters:{branch:'',period:'today',from:'2026-10-18',to:'2026-10-18'},branches,selected_branches:2,
 modules:{inventory:true,expenses:true,pos:true,app:true},inventory:inventory(),can_view_financials:false,
 ...(owner?{owner_platform:{go_partners:0,go_stores:0,go_users:0,fasakhansta_users:0,fasakhansta_stores:0,pending_total:0,pending_stores:0,pending_partners:0}}:{}),
 branch_cards:branches.map(b=>({...b,order_counts:{takeaway:0,dine:0,phone:0,app:0},completed:0,expenses_cents:0,active:{new:0,preparing:0,courier:0}})),
 today_expenses:{date:'2026-10-18',total_cents:0,items:[]},expenses_cents:0,sales:{gross_cents:0},previous:{gross_cents:0},active:{new:0,preparing:0,courier:0,awaiting_payment:0},
 completed:0,customers:{global:false,total:0,period:0},trend:[],channels:['takeaway','dine','phone','app'].map(key=>({key,count:0,amount_cents:0})),alerts:[],recent:[],ranking:[]};}
function fixture(mode,locale='ar'){
 const current=data(mode==='owner'),prefix=mode==='branch'?'bh':'ho',id=mode==='branch'?'branch-home':'home-overview';
 const attrs=mode==='branch'?['date','total','expenses','notice']:['updated','range','empty','content','drawer-note','legacy','platform','expenses-total','branch-expenses','branch-sales','sales-footer','trend-title','trend-caption','trend-total','chart','trend-data','ranking','notice','dates','kpis','live','alerts','alert-count','recent','app','branches','branch-count','branch-prev','branch-next','summary','stock-count'];
 let html='<div class="ho-wrapper"><main id="'+id+'">'+attrs.map(k=>'<div data-'+prefix+'-'+k+'></div>').join('')+'<button data-'+prefix+'-refresh></button><a data-'+prefix+'-stock-link href="/stock"></a>';
 if(mode==='branch')html+='<input data-bh-search><a data-bh-expenses-link href="/expenses"></a><table><tbody data-bh-stock></tbody></table>';
 else html+='<form data-ho-filters><select name="branch"><option value=""></option></select><input name="from"><input name="to"></form><input data-ho-stock-search><select data-ho-stock-branch></select><a data-ho-orders-link href="/orders"></a>'+(mode==='owner'?'<div data-ho-stock></div>':'<table><tbody data-ho-stock></tbody></table>');
 const boot={locale,labels:labels(locale),url:'/overview',initial:current};
 html+='</main></div><script type="application/json" id="'+id+'-bootstrap">'+JSON.stringify(boot)+'</script>';
 const dom=new JSDOM(html,{url:'https://home-test.invalid',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window;
 w.setInterval=()=>1;w.setTimeout=()=>1;w.clearTimeout=()=>{};w.matchMedia=()=>({matches:false});w.requestAnimationFrame=()=>1;w.ResizeObserver=class{observe(){}disconnect(){}};
 w.fetch=async()=>({ok:true,json:async()=>current});
 w.eval(read('public/dashboard/js/'+(mode==='branch'?'branch-home':'home-overview')+'.js'));
 return {w,doc:w.document,current,close:()=>w.close(),refresh:async()=>{w.document.querySelector('[data-'+prefix+'-refresh]').click();await tick();await tick();}};
}
(async()=>{
 for(const locale of ['ar','en']){
  const f=fixture('owner',locale),rows=()=>[...f.doc.querySelector('[data-ho-stock]').children],select=f.doc.querySelector('[data-ho-stock-branch]');
  try{
   assert(rows()[0].querySelector('strong').classList.contains('ho-stock-warning'),'a low branch colors a positive aggregate balance');
   assert.equal(rows()[0].querySelector('strong').title,labels(locale).stock_minimum_note);
   assert.equal(rows()[0].querySelector('.sr-only').textContent,' · '+labels(locale).stock_minimum_note,'screen readers get a text reason');
   assert(!rows()[1].querySelector('.ho-stock-warning'),'zero without an enabled minimum is unchanged');
   assert(rows()[2].querySelector('.ho-stock-warning'),'existing negative warning remains');
   assert(!rows()[3].querySelector('.ho-stock-warning'),'untracked stays neutral');
   select.value='f:101';select.dispatchEvent(new f.w.Event('change'));
   assert(!rows()[0].querySelector('.ho-stock-warning'),'a safe selected branch does not inherit another branch warning');
   assert.equal(rows()[0].querySelector('strong').title,'');assert(!rows()[0].querySelector('.sr-only'));
   select.value='f:100';select.dispatchEvent(new f.w.Event('change'));
   assert(rows()[0].querySelector('.ho-stock-warning'),'the equal-to-minimum selected branch is red');
   f.current.inventory.items[0].is_low=false;Object.assign(f.current.inventory.items[0].balances['f:100'],{is_low:false,quantity:'6',quantity_units:6000000});
   await f.refresh();assert.equal(select.value,'f:100');assert(!rows()[0].querySelector('.ho-stock-warning'),'replenishment removes the warning on refresh');assert(!rows()[0].querySelector('.sr-only'));
  }finally{f.close();}
 }
 for(const mode of ['generic','branch']){
  const f=fixture(mode),prefix=mode==='branch'?'bh':'ho',rows=()=>[...f.doc.querySelector('[data-'+prefix+'-stock]').children];
  try{
   assert(mode==='branch'?rows()[0].classList.contains('bh-low-stock'):rows()[0].querySelector('.ho-stock-warning'));
   assert(rows()[0].querySelector('.sr-only'));
   assert(!rows()[1].querySelector('.ho-stock-warning')&&!rows()[1].classList.contains('bh-low-stock'));
   assert(mode==='branch'?rows()[2].classList.contains('bh-negative'):rows()[2].querySelector('.ho-stock-warning'));
   f.current.inventory.items[0].is_low=false;await f.refresh();assert(!rows()[0].querySelector('.ho-stock-warning')&&!rows()[0].classList.contains('bh-low-stock'));assert(!rows()[0].querySelector('.sr-only'));
  }finally{f.close();}
 }
 console.log('HOME_STOCK_MINIMUM_DOM_OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
