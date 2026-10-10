'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const RemoteState=require('../src/dashboard-remote-state.cjs'),RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8');
const origin='https://dashboard.test',wishlistPath='/admin/userWishlistsDelete/12';
const uuid=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;

async function forms(specifications=[{}],{local=true,prepared=true}={}){
  class Form{
    constructor({action=origin+wishlistPath,method='post',spoof='DELETE',command}={}){
      this.action=action;this.method=method;this.dataset={};this.fields=[{name:'_token',value:'original-csrf'}];
      if(spoof!==null)this.fields.push({name:'_method',value:spoof});
      if(command)this.fields.push({type:'hidden',name:'_desktop_command',value:command});
    }
    hasAttribute(){return false;}
    querySelector(selector){return this.fields.find(field=>selector==='[name="'+field.name+'"]')||null;}
    append(field){this.fields.push(field);}
  }
  const list=specifications.map(value=>new Form(value)),events=new Map();let mutation,statusReads=0;
  const document={body:{dataset:{dashboardLocal:local?'1':'0',dashboardRemoteAttempts:'1',dashboardActor:'1'}},documentElement:{},
    querySelectorAll:selector=>{assert.equal(selector,'form');return list;},createElement:tag=>{assert.equal(tag,'input');return {};},
    addEventListener:(name,callback)=>events.set(name,callback)};
  await vm.runInNewContext(source,{document,window:{FasakhanstaDesktop:{status:async()=>{statusReads++;return {prepared};}}},
    location:{href:origin+'/admin/userWishlistsDelete',origin},URL,URLSearchParams,crypto,HTMLFormElement:Form,
    MutationObserver:class{constructor(callback){mutation=callback;}observe(){}}});
  return {list,get statusReads(){return statusReads;},command:form=>form.querySelector('[name="_desktop_command"]')?.value,
    dispatch(name,form=list[0]){events.get(name)?.({target:form});},mutate(){mutation?.();},add(specification){const form=new Form(specification);list.push(form);return form;}};
}

function remoteFixture(){
  const records=new Map(),decisions=new Map(),calls=[],trace=[];
  const credential={deviceId:crypto.randomUUID(),actorId:1,serverOrigin:origin,token:'a'.repeat(64)};
  const metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>{trace.push(['write',key]);records.set(key,structuredClone(value));}};
  const state=new RemoteState(metadata);
  const request=async value=>{
    trace.push(['request',value.action,value.id]);calls.push(value);
    const previous=decisions.get(value.id),status=value.action==='settle'&&previous!=='committed'?'cancelled':previous||'ready';
    decisions.set(value.id,status);
    return {format:1,id:value.id,device_id:credential.deviceId,actor_id:credential.actorId,method:value.method,path:value.path,status,capability:'b'.repeat(64)};
  };
  const options={metadata,state,credential:async()=>credential,request};
  return {records,decisions,calls,trace,state,options,attempts:new RemoteAttempts(options)};
}

test('the original spoofed wishlist DELETE form retains its UUID through submit, edits, mutation scans and a lost-response retry',async()=>{
  const f=await forms(),form=f.list[0],command=f.command(form);
  assert.match(command,uuid);assert.equal(form.method,'post');assert.equal(form.querySelector('[name="_method"]').value,'DELETE');
  assert.equal(form.querySelector('[name="_token"]').value,'original-csrf');
  f.dispatch('submit');
  form.querySelector('[name="_token"]').value='refreshed-csrf';
  form.append({name:'return_to',value:'/admin/userWishlistsDelete'});f.dispatch('change');f.mutate();
  // A lost navigation reply leaves this original form available for another submission.
  f.dispatch('submit');f.dispatch('DOMContentLoaded');f.mutate();
  assert.equal(f.command(form),command);assert.equal(form.fields.filter(field=>field.name==='_desktop_command').length,1);
  assert.equal(form.querySelector('[name="_token"]').value,'refreshed-csrf');
  const second=f.add({action:origin+'/admin/userWishlistsDelete/13'});f.mutate();
  assert.match(f.command(second),uuid);assert.notEqual(f.command(second),command,'Different original forms must not share an operation UUID.');
});

test('wishlist forms preserve an already issued command after their action or method changes',async()=>{
  const command=crypto.randomUUID(),f=await forms([{command}]),form=f.list[0];
  form.action=origin+'/admin/userWishlistsDelete/13';f.dispatch('change');f.mutate();f.dispatch('submit');
  assert.equal(f.command(form),command);
  form.querySelector('[name="_method"]').value='POST';f.dispatch('change');f.mutate();f.dispatch('submit');
  assert.equal(f.command(form),command);assert.equal(form.fields.filter(field=>field.name==='_desktop_command').length,1);
});

test('only exact same-origin positive wishlist DELETE form paths receive a command',async()=>{
  const unsupportedPaths=['/admin/userWishlistsDelete','/admin/userWishlistsDelete/0','/admin/userWishlistsDelete/01',
    '/admin/userWishlistsDelete/-1','/admin/userWishlistsDelete/10000000000000000000','/admin/userWishlistsDelete/12/',
    '/admin/userWishlistsDelete/12/edit','/admin/userWishlistsDeleteDeleteAll','/admin/userAddressesDelete/12'];
  const specifications=[...unsupportedPaths.map(value=>({action:origin+value})),
    ...['GET','POST','PUT','PATCH'].map(spoof=>({spoof})),{method:'get',spoof:null},{method:'post',spoof:null},
    {action:'https://foreign.test'+wishlistPath}];
  const f=await forms(specifications);
  f.dispatch('DOMContentLoaded');f.mutate();
  for(const form of f.list){f.dispatch('submit',form);assert.equal(f.command(form),undefined,form.action+' '+(form.querySelector('[name="_method"]')?.value||form.method));}
  const valid=f.add({action:origin+'/admin/userWishlistsDelete/9999999999999999999'});f.mutate();assert.match(f.command(valid),uuid);
});

test('a prepared remote original wishlist form uses the same UUID lifecycle and an unprepared page receives none',async()=>{
  const f=await forms([{}],{local:false}),form=f.list[0],command=f.command(form);
  assert.equal(f.statusReads,1);assert.match(command,uuid);f.dispatch('submit');f.mutate();assert.equal(f.command(form),command);
  const unprepared=await forms([{}],{local:false,prepared:false});
  assert.equal(unprepared.statusReads,1);unprepared.dispatch('submit');unprepared.mutate();assert.equal(unprepared.command(unprepared.list[0]),undefined);
});

test('native wishlist reservations support the original POST envelope and DELETE only at the exact reviewed path',()=>{
  const f=remoteFixture();
  for(const method of ['POST','DELETE'])assert.equal(f.attempts.supported({url:origin+wishlistPath,method}),true);
  for(const method of ['GET','PUT','PATCH','HEAD','OPTIONS'])assert.equal(f.attempts.supported({url:origin+wishlistPath,method}),false);
  for(const suffix of ['','/0','/01','/-1','/10000000000000000000','/12/','/12/edit','DeleteAll'])
    for(const method of ['POST','DELETE'])assert.equal(f.attempts.supported({url:origin+'/admin/userWishlistsDelete'+suffix,method}),false);
  assert.equal(f.attempts.supported({url:origin+'/admin/userAddressesDelete/12',method:'DELETE'}),false);
});

test('both wishlist transmission methods are durably bound before a proof and settle committed replies under their exact native operation',async()=>{
  for(const method of ['POST','DELETE']){
    const f=remoteFixture(),id=crypto.randomUUID(),details={url:origin+wishlistPath,method};
    const proof=await f.attempts.begin(id,details);
    assert.deepEqual(f.trace.slice(0,3),[['write','remote-attempts/'+id],['write','remote-writes'],['request','reserve',id]]);
    assert.equal(proof['X-Fasakhansta-Remote-Attempt'],id);assert.equal(proof['X-Fasakhansta-Remote-Capability'],'b'.repeat(64));
    assert.equal(f.records.get('remote-attempts/'+id).method,method);assert.equal(f.records.get('remote-attempts/'+id).path,wishlistPath);
    await f.attempts.recover();assert.equal(f.calls.length,1,'Recovery cannot cancel a transmitted request still awaiting its reply.');
    f.decisions.set(id,'committed');await f.attempts.complete(id);
    assert.deepEqual(f.calls.map(value=>[value.action,value.id,value.method,value.path]),[['reserve',id,method,wishlistPath],['settle',id,method,wishlistPath]]);
    assert.equal(f.records.get('remote-attempts/'+id).receipt.status,'committed');
    assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
    await assert.rejects(f.state.assertClean());await f.state.snapshot(async()=>{});await f.state.assertClean();
  }
});

test('a lost original wishlist response keeps the form command while native recovery settles that same transmitted operation',async()=>{
  const page=await forms(),form=page.list[0],command=page.command(form),f=remoteFixture(),id=crypto.randomUUID();
  const details={url:form.action,method:form.method.toUpperCase()};
  await f.attempts.begin(id,details);f.decisions.set(id,'committed');f.attempts.failed(id);
  page.dispatch('change');page.mutate();page.dispatch('submit');assert.equal(page.command(form),command);
  await new RemoteAttempts(f.options).recover();
  assert.deepEqual(f.calls.map(value=>[value.action,value.id,value.method,value.path]),[['reserve',id,'POST',wishlistPath],['settle',id,'POST',wishlistPath]]);
  assert.equal(f.records.get('remote-attempts/'+id).receipt.status,'committed');assert.deepEqual((await f.state.read()).pending,[]);
  assert.equal((await f.state.read()).dirty,true);assert.equal(page.command(form),command);
});

test('a lost wishlist reservation reply can only recover as cancelled and a lost settlement cannot clear its pending operation',async()=>{
  const f=remoteFixture(),id=crypto.randomUUID(),details={url:origin+wishlistPath,method:'POST'};
  f.attempts.request=async value=>{await f.options.request(value);throw Error('lost reservation reply');};
  await assert.rejects(f.attempts.begin(id,details),/lost reservation reply/);
  assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.records.get('remote-attempts/'+id).path,wishlistPath);
  const restarted=new RemoteAttempts({...f.options,request:async value=>{await f.options.request(value);throw Error('lost settlement reply');}});
  await assert.rejects(restarted.recover(),/lost settlement reply/);assert.deepEqual((await f.state.read()).pending,[id]);
  await new RemoteAttempts(f.options).recover();assert.deepEqual((await f.state.read()).pending,[]);
  assert.equal(f.records.get('remote-attempts/'+id).receipt.status,'cancelled');assert.equal((await f.state.read()).dirty,true);
  assert.ok(f.calls.every(value=>value.id===id&&value.method==='POST'&&value.path===wishlistPath));
});

test('a foreign wishlist URL cannot receive native proof and unsupported methods retain the existing unresolved-write guard',async()=>{
  const foreign=remoteFixture(),id=crypto.randomUUID();
  await assert.rejects(foreign.attempts.begin(id,{url:'https://foreign.test'+wishlistPath,method:'POST'}));
  assert.deepEqual(foreign.calls,[]);assert.equal(foreign.records.has('remote-attempts/'+id),false);assert.deepEqual((await foreign.state.read()).pending,[]);
  const unsupported=remoteFixture(),other=crypto.randomUUID();
  assert.deepEqual(await unsupported.attempts.begin(other,{url:origin+wishlistPath,method:'PUT'}),{});
  assert.deepEqual(unsupported.calls,[]);assert.equal(unsupported.records.has('remote-attempts/'+other),false);
  unsupported.attempts.failed(other);await new RemoteAttempts(unsupported.options).recover();
  assert.deepEqual((await unsupported.state.read()).pending,[other]);await assert.rejects(unsupported.state.assertClean());
});
