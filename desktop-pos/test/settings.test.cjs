'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
const origin='https://dashboard.test';
test('settings native reservations are limited to two original paths and their physical write methods',async()=>{
  const metadata=new Map(),calls=[];const credential={deviceId:crypto.randomUUID(),actorId:10,serverOrigin:origin};
  const attempts=new RemoteAttempts({state:{begin:async()=>{},complete:async()=>{}},metadata:{write:async(k,v)=>metadata.set(k,v)},credential:async()=>credential,
    request:async v=>{calls.push(v);return {format:1,id:v.id,device_id:credential.deviceId,actor_id:10,method:v.method,path:v.path,status:'ready',capability:'b'.repeat(64)};}});
  for(const pathname of ['/admin/settings/update','/admin/advertising/update']){
    for(const method of ['POST','PUT']){const id=crypto.randomUUID();assert.equal(attempts.supported({url:origin+pathname,method}),true);const headers=await attempts.begin(id,{url:origin+pathname,method});assert.equal(headers['X-Fasakhansta-Remote-Attempt'],id);assert.equal(metadata.get('remote-attempts/'+id).method,method);}
    for(const method of ['GET','PATCH','DELETE','HEAD'])assert.equal(attempts.supported({url:origin+pathname,method}),false);
  }
  for(const pathname of ['/admin/settings/updateEnv','/admin/settings/updatePaymentActivation','/admin/settings/update/','/admin/advertising/update/'])
    for(const method of ['POST','PUT'])assert.equal(attempts.supported({url:origin+pathname,method}),false);
  await assert.rejects(attempts.begin(crypto.randomUUID(),{url:'https://foreign.test/admin/settings/update',method:'POST'}),/ربط الجهاز/);assert.equal(calls.length,4);
});
async function forms(local){
  const listeners={};let observer;
  class Form{
    constructor(action,override='PUT'){this.action=action;this.method='post';this.fields=[];this.attributes=new Set();this.dataset={};if(override)this.append({name:'_method',value:override});}
    get elements(){return this.fields;}
    append(field){field.form=this;this.fields.push(field);}
    querySelector(selector){return this.fields.find(f=>selector==='[name="'+f.name+'"]')||null;}
    hasAttribute(name){return this.attributes.has(name);}
  }
  const settings=new Form(origin+'/admin/settings/update'),advertising=new Form(origin+'/admin/advertising/update'),foreign=new Form('https://foreign.test/admin/settings/update'),provider=new Form(origin+'/admin/settings/updateEnv'),activation=new Form(origin+'/admin/settings/updatePaymentActivation'),wrongMethod=new Form(origin+'/admin/settings/update','DELETE');
  const list=[settings,advertising,foreign,provider,activation,wrongMethod];
  const document={body:{dataset:{dashboardLocal:local?'1':'0',dashboardRemoteAttempts:'1'}},documentElement:{},querySelectorAll:s=>s==='form'?list:[],createElement:()=>({}),addEventListener:(name,callback)=>listeners[name]=callback};
  await vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8'),{document,window:{FasakhanstaDesktop:{status:async()=>({prepared:true})}},location:{href:origin+'/admin/settings',origin},HTMLFormElement:Form,MutationObserver:class{constructor(callback){observer=callback;}observe(){}},URL,URLSearchParams,crypto});
  return {settings,advertising,foreign,provider,activation,wrongMethod,listeners,observer};
}
for(const local of [true,false])test('original '+(local?'local':'connected')+' settings forms retain one immutable UUID across scans and lost-response resubmits',async()=>{
  const f=await forms(local);
  for(const form of [f.settings,f.advertising]){const command=form.fields.find(f=>f.name==='_desktop_command');assert.match(command.value,/^[a-f0-9-]{36}$/i);f.observer();f.listeners.submit({target:form,defaultPrevented:true});assert.equal(form.fields.filter(f=>f.name==='_desktop_command').length,1);assert.equal(form.fields.find(f=>f.name==='_desktop_command').value,command.value);assert.equal(form.method,'post');assert.equal(form.fields.find(f=>f.name==='_method').value,'PUT');}
  assert.notEqual(f.settings.fields.at(-1).value,f.advertising.fields.at(-1).value);
  for(const form of [f.foreign,f.provider,f.activation,f.wrongMethod])assert.equal(form.fields.some(f=>f.name==='_desktop_command'),false);
});
