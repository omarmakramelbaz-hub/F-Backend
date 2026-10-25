'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=path.join(__dirname,'../src/account-picker-renderer.js');
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};}
function fixture(profiles,choose=async()=>true){
  class Element{
    constructor(tag='div'){this.tagName=tag;this.children=[];this.attributes={};this.listeners={};this.disabled=false;this.textContent='';this.className='';}
    set innerHTML(_value){throw Error('Labels must never be parsed as HTML.');}
    insertAdjacentHTML(){throw Error('Labels must never be parsed as HTML.');}
    append(...values){this.children.push(...values);}
    setAttribute(name,value){this.attributes[name]=value;}
    addEventListener(name,listener){this.listeners[name]=listener;}
    click(){if(!this.disabled)this.listeners.click?.({});}
  }
  const ids=Object.fromEntries(['account-list','account-status','account-select','account-add','account-cancel'].map(id=>[id,new Element()]));
  ids['account-select'].disabled=ids['account-add'].disabled=true;
  const events={},calls=[],document={getElementById:id=>ids[id],createElement:tag=>new Element(tag),addEventListener:(name,listener)=>{events[name]=listener;}};
  const listing=profiles?.promise?profiles.promise:Promise.resolve({profiles});
  vm.runInNewContext(fs.readFileSync(source,'utf8'),{document,window:{goAccounts:{list:()=>listing,choose:input=>{calls.push(input);return choose(input);}}}},{filename:source});
  return {ids,calls,events,ready:()=>new Promise(resolve=>setImmediate(resolve))};
}
const profiles=[{id:'default',label:'الحساب الحالي',selected:true},{id:'12345678-1234-4234-9234-123456789abc',label:'الفرع الثاني',selected:false}];
test('all64 labels render as literal text with a current badge and explicit selected-account confirmation',async()=>{
  const list=Array.from({length:64},(_,index)=>({id:index?'id-'+index:'default',label:index?'<img src=x onerror=alert(1)> '+index:'اسم آمن',selected:index===0}));
  const f=fixture(list);await f.ready();const rows=f.ids['account-list'].children;
  assert.equal(rows.length,64);assert.equal(rows[0].attributes['aria-pressed'],'true');assert.equal(rows[63].attributes['aria-pressed'],'false');
  assert.equal(rows[63].children[1].textContent,list[63].label);assert.equal(rows[0].children[2].textContent,'الحالي');
  assert.equal(rows[1].children.length,2);assert.equal(f.ids['account-list'].attributes['aria-busy'],'false');
  rows[63].click();assert.equal(f.calls.length,0);assert.equal(rows[63].attributes['aria-pressed'],'true');assert.equal(rows[0].attributes['aria-pressed'],'false');
  f.ids['account-select'].click();await f.ready();assert.equal(JSON.stringify(f.calls),JSON.stringify([{action:'select',id:'id-63'}]));
});
test('a pending choice blocks duplicate clicks, account changes, add, and Escape',async()=>{
  const pending=deferred(),f=fixture(profiles,()=>pending.promise);await f.ready();
  f.ids['account-select'].click();f.ids['account-select'].click();f.ids['account-add'].click();f.ids['account-cancel'].click();
  f.ids['account-list'].children[1].click();f.events.keydown({key:'Escape',preventDefault(){}});
  assert.equal(f.calls.length,1);assert.equal(f.calls[0].id,'default');assert.equal(f.ids['account-select'].disabled,true);
  pending.resolve(false);await f.ready();assert.equal(f.ids['account-select'].disabled,false);assert.match(f.ids['account-status'].textContent,/تعذر تأكيد/);
});
test('add and cancel send narrow actions and an empty registry offers account creation',async()=>{
  const empty=fixture([]);await empty.ready();assert.equal(empty.ids['account-select'].disabled,true);assert.equal(empty.ids['account-add'].disabled,false);
  empty.ids['account-add'].click();await empty.ready();assert.equal(JSON.stringify(empty.calls),JSON.stringify([{action:'add'}]));
  const f=fixture(profiles);await f.ready();let prevented=false;f.events.keydown({key:'Escape',preventDefault(){prevented=true;}});
  await f.ready();assert.equal(prevented,true);assert.equal(JSON.stringify(f.calls),JSON.stringify([{action:'cancel'}]));
});
test('load and choice failures display fixed messages without native error details',async()=>{
  const failed=deferred(),f=fixture(failed);failed.reject(Error('PRIVATE_TOKEN_MUST_NOT_RENDER'));await f.ready();
  assert.equal(f.ids['account-select'].disabled,true);assert.equal(f.ids['account-add'].disabled,true);assert.doesNotMatch(f.ids['account-status'].textContent,/PRIVATE/);
  const choice=fixture(profiles,async()=>{throw Error('PRIVATE_PASSWORD_MUST_NOT_RENDER');});await choice.ready();choice.ids['account-select'].click();await choice.ready();
  assert.equal(choice.ids['account-select'].disabled,false);assert.doesNotMatch(choice.ids['account-status'].textContent,/PRIVATE/);
});
test('the fixed page permits only local scripts/styles and uses a scrolling list without credential fields',()=>{
  const html=fs.readFileSync(path.join(__dirname,'../src/account-picker.html'),'utf8'),css=fs.readFileSync(path.join(__dirname,'../src/account-picker.css'),'utf8');
  assert.match(html,/dir="rtl"/);assert.match(html,/default-src 'none'; script-src 'self'; style-src 'self'/);assert.doesNotMatch(html,/unsafe-inline|https?:|<input|<iframe/i);
  assert.match(html,/<script src="account-picker-renderer\.js" defer>/);assert.match(html,/<link rel="stylesheet" href="account-picker\.css">/);
  assert.match(css,/overflow-y:auto/);assert.match(css,/height:100vh/);assert.match(css,/flex-direction:column/);assert.match(css,/overflow:hidden/);assert.match(css,/focus-visible/);
});
test('the sandbox preload exposes only narrow account actions on its per-window nonce channel',async()=>{
  const preload=fs.readFileSync(path.join(__dirname,'../src/account-picker-preload.cjs'),'utf8'),calls=[];let api;
  const load=argv=>vm.runInNewContext(preload,{process:{argv},Promise,require:name=>{assert.equal(name,'electron');return {
    contextBridge:{exposeInMainWorld(name,value){assert.equal(name,'goAccounts');api=value;}},
    ipcRenderer:{invoke(channel,input){calls.push({channel,input});return Promise.resolve(true);}}
  };}});
  load(['--go-account-picker=invalid']);assert.equal(api,undefined);
  load(['--go-account-picker='+'a'.repeat(32)]);await api.list();await api.choose({action:'select',id:'default',password:'do not copy',token:'do not copy'});
  await api.choose({action:'add',secret:'do not copy'});await api.choose({action:'cancel'});assert.equal(await api.choose({action:'arbitrary',channel:'escape'}),false);
  assert.equal(calls.length,4);assert.ok(calls.every(value=>value.channel==='go-account-picker:'+'a'.repeat(32)));
  assert.equal(JSON.stringify(calls.map(value=>value.input)),JSON.stringify([{action:'list'},{action:'select',id:'default'},{action:'add'},{action:'cancel'}]));
});
