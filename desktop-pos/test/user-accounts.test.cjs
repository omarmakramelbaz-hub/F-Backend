'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const Attempts=require('../src/dashboard-remote-attempts.cjs'),State=require('../src/dashboard-remote-state.cjs');
const origin='https://accounts.test',uuid=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;

test('only exact original status links and address removal transmission methods can reserve native account writes',async()=>{
  const attempts=new Attempts({});
  for(const method of ['GET','HEAD','POST','PUT','PATCH','DELETE']){
    assert.equal(attempts.supported({url:origin+'/admin/change-status/20?status=accepted',method}),method==='GET');
    assert.equal(attempts.supported({url:origin+'/admin/userAddressDelete/30',method}),['POST','DELETE'].includes(method));
    assert.equal(attempts.supported({url:origin+'/admin/users/20',method}),['POST','PUT','PATCH'].includes(method));
  }
  for(const path of ['/admin/change-status/0','/admin/change-status/01','/admin/change-status/20/','/admin/change-status/20/edit','/admin/change-status/10000000000000000000','/admin/users/20/go_drive_activation','/admin/users/0','/admin/users/20/','/admin/users'])
    for(const method of ['GET','POST','DELETE'])assert.equal(attempts.supported({url:origin+path,method}),false,path+' '+method);
  await assert.rejects(attempts.begin(crypto.randomUUID(),{url:origin+'/admin/change-status/20',method:'HEAD'}),/فحص رابط التعديل لا يمكنه تنفيذ عملية/);
});

test('original status links and address forms retain immutable distinct UUIDs through repeated scans and lost navigation',async()=>{
  const listeners=new Map();let rescan;
  class Form{
    constructor(action,spoof='DELETE'){this.action=origin+action;this.method='post';this.elements=[];this.dataset={};this.append({name:'_method',value:spoof});this.append({name:'_token',value:'original-csrf'});}
    append(field){field.form=this;this.elements.push(field);}
    querySelector(selector){const name=/\[name="([^"]+)"\]/.exec(selector)?.[1];return this.elements.find(field=>field.name===name)||null;}
    hasAttribute(){return false;}
  }
  const links=['/admin/change-status/20?status=accepted','/admin/change-status/20?status=declined','/admin/change-status/20/','/admin/users/20/go_drive_activation'].map(href=>({href:origin+href,dataset:{}}));
  const forms=[new Form('/admin/userAddressDelete/30'),new Form('/admin/userAddressDelete/31'),new Form('/admin/users/20'),new Form('/admin/users/40','PUT')];
  const document={body:{dataset:{dashboardLocal:'1',dashboardActor:'1'}},documentElement:{},querySelectorAll:selector=>selector==='form'?forms:links,
    createElement:()=>({}),addEventListener:(name,fn)=>listeners.set(name,fn)};
  await vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8'),{document,window:{},location:{origin,href:origin+'/admin/users?account_type=delegate'},HTMLFormElement:Form,URL,URLSearchParams,crypto,MutationObserver:class{constructor(callback){rescan=callback;}observe(){}}});
  const commands=links.slice(0,2).map(link=>new URL(link.href).searchParams.get('_desktop_command'));
  const addressCommands=forms.slice(0,2).map(form=>form.querySelector('[name="_desktop_command"]').value);
  for(const command of [...commands,...addressCommands])assert.match(command,uuid);assert.equal(new Set([...commands,...addressCommands]).size,4);
  for(let i=0;i<3;i++){for(const form of forms)listeners.get('submit')({target:form});rescan();}
  assert.deepEqual(links.slice(0,2).map(link=>new URL(link.href).searchParams.get('_desktop_command')),commands);
  assert.deepEqual(forms.slice(0,2).map(form=>form.querySelector('[name="_desktop_command"]').value),addressCommands);
  assert.equal(forms[0].querySelector('[name="_method"]').value,'DELETE');assert.equal(forms[0].querySelector('[name="_token"]').value,'original-csrf');
  assert.match(forms[3].querySelector('[name="_desktop_command"]').value,uuid);assert.equal(forms[3].querySelector('[name="_method"]').value,'PUT');assert.equal(forms[2].querySelector('[name="_desktop_command"]'),null);assert.ok(links.slice(2).every(link=>!new URL(link.href).searchParams.has('_desktop_command')));
});

test('native account transmission identity survives a crash and settles its exact committed operation before refresh',async()=>{
  for(const [method,path] of [['GET','/admin/change-status/20'],['POST','/admin/userAddressDelete/30'],['DELETE','/admin/userAddressDelete/30'],['POST','/admin/users/40'],['PUT','/admin/users/40'],['PATCH','/admin/users/40']]){
    const records=new Map(),calls=[],trace=[];const credential={deviceId:crypto.randomUUID(),actorId:1,serverOrigin:origin};let committed=false;
    const metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>{trace.push(['write',key]);records.set(key,structuredClone(value));}};
    const state=new State(metadata),request=async value=>{calls.push(value);trace.push(['request',value.action]);return {format:1,id:value.id,device_id:credential.deviceId,actor_id:1,method,path,status:committed?'committed':'ready',capability:'a'.repeat(64)};};
    const options={metadata,state,credential:async()=>credential,request};const attempts=new Attempts(options),id=crypto.randomUUID();
    const proof=await attempts.begin(id,{method,url:origin+path+'?status=accepted&_desktop_command='+crypto.randomUUID()});
    assert.equal(proof['X-Fasakhansta-Remote-Attempt'],id);assert.deepEqual(trace.slice(0,3),[['write','remote-attempts/'+id],['write','remote-writes'],['request','reserve']]);
    committed=true;attempts.failed(id);await new Attempts(options).recover();
    assert.deepEqual(calls.map(value=>[value.action,value.id,value.method,value.path]),[['reserve',id,method,path],['settle',id,method,path]]);
    assert.deepEqual((await state.read()).pending,[]);assert.equal((await state.read()).dirty,true);await assert.rejects(state.assertClean());
  }
});
