'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
const Attempts=require('../src/dashboard-remote-attempts.cjs');
const RemoteState=require('../src/dashboard-remote-state.cjs');
async function formFixture(url='https://dashboard.test/admin/go-stores/60',method='POST',dataset={dashboardLocal:'1'}){
  class Form{
    constructor(){this.action=url;this.method=method;this.fields=[];}
    hasAttribute(){return false;}
    querySelector(selector){return this.fields.find(field=>selector==='[name="'+field.name+'"]')||null;}
    append(field){this.fields.push(field);}
  }
  const form=new Form(),events={},document={body:{dataset},documentElement:{},querySelectorAll:()=>[form],createElement:()=>({}),addEventListener:(name,callback)=>events[name]=callback};
  await vm.runInNewContext(source,{document,window:{FasakhanstaDesktop:{status:async()=>({prepared:true})}},location:{href:'https://dashboard.test/admin/go-stores/60',origin:'https://dashboard.test'},URL,crypto,HTMLFormElement:Form,MutationObserver:class{observe(){}}});
  return {form,submit:()=>events.submit({target:form}),command:()=>form.fields.find(field=>field.name==='_desktop_command')?.value};
}
test('the original existing GO profile form retains its UUID when a response is lost, locally and on the prepared native server',async()=>{
  for(const dataset of [{dashboardLocal:'1'},{dashboardLocal:'0',dashboardRemoteAttempts:'1'}]){
    const f=await formFixture(undefined,undefined,dataset),command=f.command();assert.match(command,/^[a-f0-9-]{36}$/i);
    f.submit();f.submit();assert.equal(f.command(),command);assert.equal(f.form.fields.length,1);
  }
});
test('GO owner creation, products, foreign forms and other methods remain outside UUID and reservation coverage',async()=>{
  const attempts=new Attempts({});
  assert.equal(attempts.supported({url:'https://dashboard.test/admin/go-stores/60',method:'POST'}),true);
  for(const [url,method] of [['https://dashboard.test/admin/go-stores','POST'],['https://dashboard.test/admin/go-stores/60/products','POST'],['https://dashboard.test/admin/go-stores/60/products/1','POST'],...['GET','PUT','PATCH','DELETE'].map(method=>['https://dashboard.test/admin/go-stores/60',method])]){
    assert.equal((await formFixture(url,method)).command(),undefined);assert.equal(attempts.supported({url,method}),false);
  }
  assert.equal((await formFixture('https://foreign.test/admin/go-stores/60')).command(),undefined);
  assert.equal((await formFixture(undefined,undefined,{dashboardLocal:'0'})).command(),undefined);
});
test('a committed GO status rejected for current grants or enrollment preserves the actual native pending attempt until authority is restored',async()=>{
  for(const reason of ['revoked grant','unenrolled gs owner']){
    const records=new Map(),credential={deviceId:crypto.randomUUID(),actorId:67,serverOrigin:'https://dashboard.test',token:'a'.repeat(64)};
    const metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>records.set(key,structuredClone(value))};
    const state=new RemoteState(metadata),calls=[];let denied=false;
    const options={state,metadata,credential:async()=>credential,request:async value=>{
      calls.push({...value});
      if(value.action==='settle'&&denied)throw Error('HTTP 403: '+reason);
      return {format:1,id:value.id,device_id:credential.deviceId,actor_id:67,method:value.method,path:value.path,
        status:value.action==='reserve'?'ready':'committed',capability:'b'.repeat(64)};
    }};
    const id=crypto.randomUUID(),attempts=new Attempts(options);
    await attempts.begin(id,{url:credential.serverOrigin+'/admin/go-stores/60',method:'POST'});attempts.failed(id);denied=true;
    await assert.rejects(new Attempts(options).recover(),/HTTP 403/);
    assert.deepEqual((await state.read()).pending,[id]);assert.equal((await state.read()).dirty,true);
    assert.equal(records.get('remote-attempts/'+id).receipt,undefined);await assert.rejects(state.assertClean());
    denied=false;await new Attempts(options).recover();
    assert.deepEqual((await state.read()).pending,[]);assert.equal((await state.read()).dirty,true);
    assert.equal(records.get('remote-attempts/'+id).receipt.status,'committed');
    assert.deepEqual(calls.map(value=>[value.action,value.id,value.method,value.path]),
      [['reserve',id,'POST','/admin/go-stores/60'],['settle',id,'POST','/admin/go-stores/60'],['settle',id,'POST','/admin/go-stores/60']]);
  }
});
