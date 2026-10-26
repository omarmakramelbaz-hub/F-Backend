'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../src/dashboard-preload.cjs'),'utf8');
function fixture(response={ok:true,value:true}){
  const calls=[];let api;
  const rejectDOM=new Proxy({},{get(){throw Error('The isolated bridge must not inspect or modify the original login form.');}});
  vm.runInNewContext(source,{window:rejectDOM,document:rejectDOM,require:name=>{assert.equal(name,'electron');return {
    contextBridge:{exposeInMainWorld(name,value){assert.equal(name,'FasakhanstaDesktop');api=value;}},
    ipcRenderer:{invoke:async(...args)=>{calls.push(args);return response;},on(){},removeListener(){}}
  };}});
  return {api,calls};
}
test('ordinary dashboard login remains untouched and receives no second account button',()=>{
  const f=fixture();assert.equal(f.calls.length,0);assert.equal(typeof f.api.printReceipt,'function');assert.equal(typeof f.api.prepare,'function');
  assert.doesNotMatch(source,/createElement|querySelector|DOMContentLoaded|تبديل الحساب/);
});
test('the optional native account menu bridge forwards no renderer password or identity',async()=>{
  const f=fixture();await f.api.switchAccount({password:'must-not-forward',actorId:1,profile:'foreign'});
  assert.deepEqual(f.calls,[['dashboard:switch-account',undefined]]);
  const refused=fixture({ok:false,error:'fixed refusal'});await assert.rejects(refused.api.switchAccount(),/fixed refusal/);
});
