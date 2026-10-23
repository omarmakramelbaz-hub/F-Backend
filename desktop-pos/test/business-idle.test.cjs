'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const read=name=>fs.readFileSync(path.join(__dirname,'../../public/dashboard/js',name),'utf8');

function fixture(){
  class Target{
    constructor(){this.listeners=new Map();}
    addEventListener(name,callback){const items=this.listeners.get(name)||[];items.push(callback);this.listeners.set(name,items);}
    removeEventListener(name,callback){this.listeners.set(name,(this.listeners.get(name)||[]).filter(item=>item!==callback));}
    dispatch(name,event={}){for(const callback of [...this.listeners.get(name)||[]])callback.call(this,event);}
  }
  class Form extends Target{submit(){}}
  class Reader{}
  const region={setAttribute(){},inert:false},document=new Target(),window=new Target();
  let modals=[],dialogs=[],openDialog=null,fetches=0;
  document.readyState='complete';document.currentScript=null;
  document.documentElement={dir:'rtl',setAttribute(){}};document.head={childNodes:[]};
  document.body={appendChild(){},classList:{toggle(){}},getAttribute(){return null;}};
  document.createElement=()=>({setAttribute(){}});
  document.querySelector=selector=>selector==='[data-dashboard-page]'?region:selector==='dialog[open]'?openDialog:null;
  document.querySelectorAll=selector=>selector==='.modal'?modals:selector.startsWith('.modal-backdrop')?dialogs:[];
  const history={state:null,replaceState(value){this.state=value;}};
  const location={href:'https://fixture.invalid/admin/takeaway',origin:'https://fixture.invalid'};
  Object.assign(window,{setTimeout:()=>1,clearTimeout(){},setInterval:()=>2,clearInterval(){},requestAnimationFrame:()=>3,cancelAnimationFrame(){},
    FileReader:Reader,fetch:async()=>{fetches++;throw Error('Idle must not send a request');},location});
  class FixtureURL extends URL{}
  FixtureURL.createObjectURL=()=>'';FixtureURL.revokeObjectURL=()=>{};
  const source=read('dashboard-spa.js');
  // Expose private lifecycle/state transitions in this fixture only. Production's
  // complete SPA code and exported observation API execute unchanged.
  const instrumented=source.replace(/\}\(\)\);\s*$/,
    'window.testPage={replace:function(){page.dispose();page=new PageScope();},state:function(value){navigation=value.navigation||null;submitting=value.submitting||false;pendingHistory=value.pendingHistory||null;restoringHistory=value.restoringHistory||false;currentDialog=value.currentDialog||null;}}; }());');
  vm.runInNewContext(instrumented,{window,document,EventTarget:Target,HTMLFormElement:Form,URL:FixtureURL,location,history,
    scrollX:0,scrollY:0,console,Element:class{},Map,Set,WeakMap,Promise,AbortController,DOMException});
  return {api:window.DashboardSPA,window,document,start:()=>document.dispatch('DOMContentLoaded'),
    modals(value){modals=value;},dialogs(value){dialogs=value;},open(value){openDialog=value;},fetches:()=>fetches};
}

test('the actual SPA requires ready page-scoped literal true idle observations without invoking leave guards',()=>{
  const f=fixture();let leaveCalls=0,idleCalls=0;
  f.api.onBeforeLeave(()=>{leaveCalls++;throw Error('Must not ask to discard work');});
  const remove=f.api.onIdle(()=>{idleCalls++;return true;});
  assert.equal(f.api.isIdle(),false);f.start();assert.equal(f.api.isIdle(),true);
  assert.equal(leaveCalls,0);assert.equal(idleCalls,1);assert.equal(f.fetches(),0);
  remove();assert.equal(f.api.isIdle(),false);
  for(const value of [undefined,null,0,1,'true',{},Promise.resolve(true)]){
    const off=f.api.onIdle(()=>value);assert.equal(f.api.isIdle(),false);off();
  }
  const off=f.api.onIdle(()=>{throw Error('Probe failure');});assert.equal(f.api.isIdle(),false);off();
  f.api.onIdle(null);assert.equal(f.api.isIdle(),false);
});

test('an unregistered widget guard fails closed until its own observation is present',()=>{
  const f=fixture();f.start();f.api.onBeforeLeave(()=>true);f.api.onIdle(()=>true);assert.equal(f.api.isIdle(),true);
  const removeLeave=f.api.onBeforeLeave(()=>true);assert.equal(f.api.isIdle(),false);
  const removeIdle=f.api.onIdle(()=>true);assert.equal(f.api.isIdle(),true);
  removeIdle();assert.equal(f.api.isIdle(),false);removeLeave();assert.equal(f.api.isIdle(),true);
});

test('disposed page callbacks never authorize a later page and old removers cannot unregister new observations',()=>{
  const f=fixture();f.start();let oldCalls=0,newCalls=0;
  const remove=f.api.onIdle(()=>{oldCalls++;return true;});assert.equal(f.api.isIdle(),true);
  f.window.testPage.replace();assert.equal(f.api.isIdle(),false);
  f.api.onIdle(()=>{newCalls++;return true;});remove();assert.equal(f.api.isIdle(),true);
  assert.equal(oldCalls,1);assert.equal(newCalls,1);
  f.window.dispatch('pagehide');assert.equal(f.api.isIdle(),false);f.api.onIdle(()=>true);assert.equal(f.api.isIdle(),false);
});

test('navigation, saves, history recovery and a current dialog block observations without side effects',()=>{
  const f=fixture();f.start();let calls=0;f.api.onIdle(()=>{calls++;return true;});
  for(const state of [{navigation:{}},{submitting:true},{pendingHistory:{}},{restoringHistory:true},{currentDialog:{}}]){
    f.window.testPage.state(state);assert.equal(f.api.isIdle(),false);
  }
  assert.equal(calls,0);f.window.testPage.state({});assert.equal(f.api.isIdle(),true);assert.equal(calls,1);
  f.api.onIdle(()=>{f.window.testPage.state({navigation:{}});return true;});assert.equal(f.api.isIdle(),false);
});

test('pending modal transitions and open dialogs block while hidden template dialogs do not',()=>{
  const f=fixture();f.start();f.api.onIdle(()=>true);
  const modal={matches:selector=>selector==='.modal',classList:{contains:()=>false}};f.modals([modal]);assert.equal(f.api.isIdle(),true);
  f.document.dispatch('show.bs.modal',{target:modal,defaultPrevented:false});assert.equal(f.api.isIdle(),false);
  f.document.dispatch('shown.bs.modal',{target:modal});assert.equal(f.api.isIdle(),false);
  f.document.dispatch('hide.bs.modal',{target:modal,defaultPrevented:false});assert.equal(f.api.isIdle(),false);
  f.document.dispatch('hidden.bs.modal',{target:modal});assert.equal(f.api.isIdle(),true);
  f.document.dispatch('show.bs.modal',{target:modal,defaultPrevented:true});assert.equal(f.api.isIdle(),true);
  f.open({open:true});assert.equal(f.api.isIdle(),false);f.open(null);
  f.dialogs([{hidden:false,closest:()=>({hidden:true}),getClientRects:()=>[{}]}]);assert.equal(f.api.isIdle(),true);
  f.dialogs([{hidden:false,closest:()=>null,getClientRects:()=>[]}]);assert.equal(f.api.isIdle(),true);
  f.dialogs([{hidden:false,closest:()=>null,getClientRects:()=>[{}]}]);assert.equal(f.api.isIdle(),false);
});

function declaration(source,name){
  const start=source.indexOf('function '+name+'(');assert.ok(start>=0,'Execute the real '+name+' function');
  let depth=0,quote=null,escaped=false,body=source.indexOf('{',start);
  for(let index=body;index<source.length;index++){
    const c=source[index];
    if(quote){if(escaped)escaped=false;else if(c==='\\')escaped=true;else if(c===quote)quote=null;continue;}
    if(c==='"'||c==="'"){quote=c;continue;}
    if(c==='{')depth++;else if(c==='}'&&!--depth)return source.slice(start,index+1);
  }
  throw Error('Incomplete function '+name);
}
function observe(file,state,functions=[]){
  const source=read(file),context=vm.createContext(state);
  vm.runInContext(functions.concat('refreshIdle').map(name=>declaration(source,name)).join('\n'),context);
  return ()=>vm.runInContext('refreshIdle()',context);
}
const invoice=()=>({cart:[],customerPhone:'',customerName:'',notes:'',cash:'',reference:'',discount:'0.00',uncertain:false,sold:false});

test('takeaway observes all five invoices and live input without storing drafts, prompting or changing invoice metadata',()=>{
  const fields=Object.fromEntries(['discount-reason','notes','payment-reference','search'].map(name=>[name,{value:''}]));
  const stored=Array.from({length:5},invoice),phone={value:''},cash={value:''},modal={hidden:true};
  const state={disposed:false,registerLocked:false,selling:false,modal,activeInvoice:0,invoices:stored,
    branch:'f:100',branchSelect:{selectedOptions:[{textContent:'Fixture'}]},quarantined:false,cart:[],quote:null,saleKey:'fixture',frozenPayload:null,
    uncertain:false,sold:false,lastReceipt:null,lastQuotedTotal:null,customerPhone:phone,customerName:{value:''},serviceConsent:false,marketingConsent:false,
    discountInput:{value:'0.00'},root:{querySelector:selector=>fields[selector.slice(10,-1)]},cashInput:cash,confirmedInput:{checked:false},paymentMethod:'cash',
    quantityMode:'weight',quantityInput:'1',replaceQuantity:true,category:'',catalogPage:1,policy:{},permissions:{},products:new Map(),pagination:{},register:null,
    minor:value=>BigInt(Math.round(Number(value)*100)),storeInvoice:()=>{throw Error('Idle must not persist');},confirm:()=>{throw Error('Idle must not prompt');}};
  const idle=observe('takeaway-pos.js',state,['captureInvoice','invoiceIsDirty']),before=JSON.stringify(stored);
  assert.equal(idle(),true);phone.value='01000000000';assert.equal(idle(),false);phone.value='';cash.value='0';assert.equal(idle(),false);cash.value='';
  stored[4].cart=[{quantity:'1'}];assert.equal(idle(),false);stored[4].sold=true;assert.equal(idle(),true);stored[4].uncertain=true;assert.equal(idle(),false);
  stored[4]=invoice();for(const key of ['disposed','registerLocked','selling']){state[key]=true;assert.equal(idle(),false);state[key]=false;}
  modal.hidden=false;assert.equal(idle(),false);modal.hidden=true;assert.equal(idle(),true);
  assert.equal(JSON.stringify(stored),before,'The observation never persists or rewrites invoice metadata.');
});

test('expense observation blocks dirty, pending, busy and open category/detail dialogs without invoking mayLeave',()=>{
  let dialog=null;const state={disposed:false,busy:false,categoryBusy:false,frozen:null,dirty:false,root:{querySelector:()=>dialog}};
  const idle=observe('branch-expenses.js',state,['locked']);assert.equal(idle(),true);
  for(const [key,value]of [['disposed',true],['busy',true],['categoryBusy',true],['frozen',{}],['dirty',true]]){
    state[key]=value;assert.equal(idle(),false);state[key]=key==='frozen'?null:false;
  }
  dialog={open:true};assert.equal(idle(),false);dialog=null;assert.equal(idle(),true);
});

test('stock and recipe observations jointly protect receipt, minimum and unsaved recipe work',()=>{
  const stock={disposed:false,recipeBusy:false,busy:false,minimumBusy:false,loading:false,frozen:null,dirty:false,minimumEditor:null};
  const idleStock=observe('branch-stock.js',stock,['locked']);assert.equal(idleStock(),true);
  for(const [key,value]of [['disposed',true],['recipeBusy',true],['busy',true],['minimumBusy',true],['loading',true],['frozen',{}],['dirty',true],['minimumEditor',{}]]){
    stock[key]=value;assert.equal(idleStock(),false);stock[key]=['frozen','minimumEditor'].includes(key)?null:false;
  }
  const recipe={disposed:false,stockBusy:false,busy:false,loading:false,frozen:null,dirty:false,basicDialog:{open:false}};
  const idleRecipe=observe('branch-recipes.js',recipe,['locked']);assert.equal(idleRecipe(),true);
  for(const [key,value]of [['disposed',true],['stockBusy',true],['busy',true],['loading',true],['frozen',{}],['dirty',true]]){
    recipe[key]=value;assert.equal(idleRecipe(),false);recipe[key]=key==='frozen'?null:false;
  }
  recipe.basicDialog.open=true;assert.equal(idleRecipe(),false);recipe.basicDialog.open=false;
  assert.equal(idleStock()&&idleRecipe(),true);
});

test('shift observation protects a pending closing, loading report and live cash/notes even when amount is zero',()=>{
  const state={disposed:false,busy:false,pending:null,loading:false,counted:{value:''},notes:{value:''}};
  const idle=observe('branch-shifts.js',state);assert.equal(idle(),true);
  for(const [key,value]of [['disposed',true],['busy',true],['pending',{}],['loading',true]]){
    state[key]=value;assert.equal(idle(),false);state[key]=key==='pending'?null:false;
  }
  state.counted.value='0';assert.equal(idle(),false);state.counted.value='';state.notes.value=' ';assert.equal(idle(),false);state.notes.value='';assert.equal(idle(),true);
});
