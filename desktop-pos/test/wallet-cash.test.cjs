'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
const origin='https://wallet.test';

test('native cash reservations admit only the two reviewed original POST forms',()=>{
  const attempts=new RemoteAttempts({});
  for(const endpoint of ['/admin/wallets/transfer','/admin/wallets/withdraw/store']){
    assert.equal(attempts.supported({url:origin+endpoint,method:'POST'}),true);
    for(const method of ['GET','HEAD','PUT','PATCH','DELETE'])assert.equal(attempts.supported({url:origin+endpoint,method}),false);
  }
  for(const endpoint of ['/admin/charging/wallet','/admin/wallets/withdraw','/admin/wallets/transfer/20','/admin/wallets/transfer/'])
    assert.equal(attempts.supported({url:origin+endpoint,method:'POST'}),false);
});

test('the original cash forms retain one immutable UUID through repeated submits and DOM rescans',async()=>{
  const listeners=new Map();let rescan;
  class Form {
    constructor(action){this.action=origin+action;this.method='post';this.elements=[];this.dataset={};}
    append(field){field.form=this;this.elements.push(field);}
    querySelector(selector){const name=/\[name="([^"]+)"\]/.exec(selector)?.[1];return this.elements.find(field=>field.name===name)||null;}
    hasAttribute(){return false;}
  }
  const forms=['/admin/wallets/transfer','/admin/wallets/withdraw/store','/admin/charging/wallet'].map(endpoint=>new Form(endpoint));
  for(const form of forms)for(const [name,value] of [['amount','50.01'],['type',form.action.endsWith('/transfer')?'transfer':'withdraw']])form.append({name,value,disabled:false});
  const document={body:{dataset:{dashboardLocal:'1',dashboardActor:'1'}},documentElement:{},
    querySelectorAll:selector=>selector==='form'?forms:[],createElement:()=>({}),
    addEventListener:(name,listener)=>listeners.set(name,listener)};
  await vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8'),{
    document,window:{},location:{origin,href:origin+'/admin/wallets'},HTMLFormElement:Form,
    MutationObserver:class{constructor(callback){rescan=callback;}observe(){}},URL,URLSearchParams,crypto,
  });
  const ids=forms.slice(0,2).map(form=>form.querySelector('[name="_desktop_command"]').value);
  assert.notEqual(ids[0],ids[1]);assert.equal(forms[2].querySelector('[name="_desktop_command"]'),null);
  for(const form of forms.slice(0,2))listeners.get('submit')({target:form,defaultPrevented:true});
  rescan();
  assert.deepEqual(forms.slice(0,2).map(form=>form.querySelector('[name="_desktop_command"]').value),ids);
  assert.equal(forms.slice(0,2).every(form=>form.elements.filter(field=>field.name==='_desktop_command').length===1),true);
});
