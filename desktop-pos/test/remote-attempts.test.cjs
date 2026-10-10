'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const RemoteState=require('../src/dashboard-remote-state.cjs'),RemoteAttempts=require('../src/dashboard-remote-attempts.cjs');
const credential={deviceId:crypto.randomUUID(),actorId:1,serverOrigin:'https://dashboard.test',token:'a'.repeat(64)};
function fixture(){
  const records=new Map(),decisions=new Map(),calls=[];
  const metadata={read:async key=>structuredClone(records.get(key)||null),write:async(key,value)=>records.set(key,structuredClone(value))};
  const state=new RemoteState(metadata);
  const request=async value=>{
    calls.push(value);const previous=decisions.get(value.id),status=value.action==='settle'&&previous!=='committed'?'cancelled':previous||'ready';decisions.set(value.id,status);
    return {format:1,id:value.id,device_id:credential.deviceId,actor_id:credential.actorId,method:value.method,path:value.path,status,capability:'b'.repeat(64)};
  };
  const options={state,metadata,credential:async()=>credential,request};return {records,decisions,calls,state,options,attempts:new RemoteAttempts(options)};
}
const details={url:credential.serverOrigin+'/admin/products',method:'POST'};
test('original category ordering reserves only its reviewed POST endpoint',()=>{
  const f=fixture(),url=credential.serverOrigin+'/admin/post-sortable';
  assert.equal(f.attempts.supported({url,method:'POST'}),true);
  for(const method of ['GET','PUT','DELETE'])assert.equal(f.attempts.supported({url,method}),false);
});
test('native menu outcomes reserve only scoped f/gs product availability POSTs',()=>{
  const f=fixture(),origin=credential.serverOrigin;
  for(const kind of ['f','gs']){
    const url=origin+'/admin/order-board/menu/'+kind+'/100/products/12/availability';
    assert.equal(f.attempts.supported({url,method:'POST'}),true);
    for(const method of ['GET','PUT','PATCH','DELETE'])assert.equal(f.attempts.supported({url,method}),false);
  }
  for(const path of ['/admin/order-board/menu/other/100/products/12/availability','/admin/order-board/menu/f/0/products/12/availability',
    '/admin/order-board/menu/f/100/products/0/availability','/admin/order-board/menu/f/100/products/12','/admin/order-board/legacy/12/action'])
    assert.equal(f.attempts.supported({url:origin+path,method:'POST'}),false);
});
test('original role writes reserve only their reviewed methods without claiming permission administration',()=>{
  const f=fixture(),origin=credential.serverOrigin;
  assert.equal(f.attempts.supported({url:origin+'/admin/roles',method:'POST'}),true);
  for(const method of ['POST','PUT','PATCH','DELETE'])assert.equal(f.attempts.supported({url:origin+'/admin/roles/12',method}),true);
  for(const method of ['GET','PUT','DELETE'])assert.equal(f.attempts.supported({url:origin+'/admin/roles',method}),false);
  assert.equal(f.attempts.supported({url:origin+'/admin/rolesDeleteAll',method:'DELETE'}),true);
  for(const method of ['GET','POST','PUT','PATCH'])assert.equal(f.attempts.supported({url:origin+'/admin/rolesDeleteAll',method}),false);
  for(const path of ['/admin/permissions','/admin/permissions/12','/admin/roles/12/edit','/admin/rolesDeleteAll/12','/admin/rolesDeleteAll/'])
    assert.equal(f.attempts.supported({url:origin+path,method:'DELETE'}),false);
});
test('original bulk role deletion binds its exact DELETE path to a durable reservation and terminal recovery',async()=>{
  const f=fixture(),id=crypto.randomUUID(),bulk={url:credential.serverOrigin+'/admin/rolesDeleteAll',method:'DELETE'};
  const proof=await f.attempts.begin(id,bulk);
  assert.equal(proof['X-Fasakhansta-Remote-Attempt'],id);assert.equal(proof['X-Fasakhansta-Remote-Capability'],'b'.repeat(64));
  assert.equal(f.records.get('remote-attempts/'+id).method,'DELETE');assert.equal(f.records.get('remote-attempts/'+id).path,'/admin/rolesDeleteAll');
  f.decisions.set(id,'committed');f.attempts.failed(id);
  await new RemoteAttempts(f.options).recover();
  assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
  assert.deepEqual(f.calls.map(value=>[value.action,value.method,value.path]),[['reserve','DELETE','/admin/rolesDeleteAll'],['settle','DELETE','/admin/rolesDeleteAll']]);
});
test('original bulk role AJAX retains one UUID after a lost or reordered reply and releases it only on acknowledgement',async()=>{
  const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),storage=new Map();
  let prefilter;
  const document={body:{dataset:{dashboardLocal:'1',dashboardActor:'1'}},documentElement:{},querySelectorAll:()=>[],addEventListener:()=>{}};
  await vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/desktop-dashboard.js'),'utf8'),{
    document,window:{jQuery:{ajaxPrefilter:callback=>{prefilter=callback;}}},location:{href:credential.serverOrigin+'/admin/roles',origin:credential.serverOrigin},
    HTMLFormElement:class {},MutationObserver:class {observe(){}},URL,URLSearchParams,crypto,
    sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)}
  });
  assert.equal(typeof prefilter,'function');
  const send=(data,{url=credential.serverOrigin+'/admin/rolesDeleteAll',type='DELETE'}={})=>{
    const headers={},callbacks=[];
    prefilter({url,type,data},{},{setRequestHeader:(name,value)=>{headers[name]=value;},done:callback=>callbacks.push(callback)});
    return {command:headers['X-Fasakhansta-Command'],acknowledge:value=>callbacks.forEach(callback=>callback(value))};
  };
  const first=send('ids=12,3'),reordered=send('ids=3,12');
  assert.match(first.command,/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i);
  assert.equal(reordered.command,first.command);assert.equal(storage.size,1);
  reordered.acknowledge({error:'No acknowledgement'});
  assert.equal(send('ids=12,3').command,first.command);
  for(const options of [{type:'POST'},{url:credential.serverOrigin+'/admin/permissions'},{url:'https://other.test/admin/rolesDeleteAll'}])
    assert.equal(send('ids=12,3',options).command,undefined);
  reordered.acknowledge({success:'Original JSON acknowledgement'});
  assert.equal(storage.size,0);const newer=send('ids=3,12');assert.notEqual(newer.command,first.command);
  first.acknowledge({success:'Delayed old acknowledgement'});
  assert.equal(storage.size,1);assert.equal(send('ids=12,3').command,newer.command,'A late old reply cannot erase the UUID of a newer lost-response retry.');
  newer.acknowledge({success:'Current operation acknowledgement'});assert.equal(storage.size,0);
});
test('reviewed own-notification menu and history reads reserve, while external sends keep their guard',()=>{
  const f=fixture(),url=credential.serverOrigin+'/admin/dashboard-inbox/notifications/read';
  assert.equal(f.attempts.supported({url,method:'POST'}),true);
  assert.equal(f.attempts.supported({url,method:'GET'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/read/all/notification',method:'POST'}),true);
  const history=credential.serverOrigin+'/admin/read/'+crypto.randomUUID();
  for(const method of ['POST','PUT'])assert.equal(f.attempts.supported({url:history,method}),true);
  for(const method of ['GET','DELETE'])assert.equal(f.attempts.supported({url:history,method}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/read/123',method:'PUT'}),false);
  for(const path of ['/admin/for-send-notify','/admin/dashboard-inbox/support/12/messages'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),false);
});
test('an original remote request cannot leave before its durable reservation and exact server capability',async()=>{
  const f=fixture(),id=crypto.randomUUID();
  const proof=await f.attempts.begin(id,details);
  assert.equal(proof['X-Fasakhansta-Remote-Attempt'],id);assert.equal(proof['X-Fasakhansta-Remote-Capability'],'b'.repeat(64));
  assert.equal(f.records.get('remote-attempts/'+id).path,'/admin/products');assert.deepEqual((await f.state.read()).pending,[id]);
  await f.attempts.recover();assert.equal(f.calls.length,1,'Recovery must not cancel an in-flight accepted original request.');
});
test('a lost reserve reply is recovered with a terminal decision after restart and cannot clear stale data',async()=>{
  const f=fixture(),id=crypto.randomUUID();f.attempts.request=async value=>{await f.options.request(value);throw Error('lost reserve reply');};
  await assert.rejects(f.attempts.begin(id,details));assert.deepEqual((await f.state.read()).pending,[id]);
  await new RemoteAttempts(f.options).recover();assert.equal(f.decisions.get(id),'cancelled');
});
test('lost original write responses are settled by authority, with dirty data retained until a coherent refresh',async()=>{
  const f=fixture(),id=crypto.randomUUID();await f.attempts.begin(id,details);f.decisions.set(id,'committed');f.attempts.failed(id);
  await new RemoteAttempts(f.options).recover();assert.deepEqual((await f.state.read()).pending,[]);assert.equal((await f.state.read()).dirty,true);
  await assert.rejects(f.state.assertClean());await f.state.snapshot(async()=>{});await f.state.assertClean();
});
test('a lost settlement reply retains uncertainty and retries the same server attempt',async()=>{
  const f=fixture(),id=crypto.randomUUID();await f.attempts.begin(id,details);f.attempts.failed(id);
  const restarted=new RemoteAttempts({...f.options,request:async value=>{await f.options.request(value);throw Error('lost settlement');}});
  await assert.rejects(restarted.recover());assert.deepEqual((await f.state.read()).pending,[id]);
  await new RemoteAttempts(f.options).recover();assert.deepEqual((await f.state.read()).pending,[]);
  assert.ok(f.calls.filter(value=>value.action==='settle').every(value=>value.id===id));
});
test('foreign receipts, changed account credentials and earlier unreserved operations cannot clear uncertainty',async()=>{
  const f=fixture(),id=crypto.randomUUID();await f.attempts.begin(id,details);f.attempts.failed(id);
  await assert.rejects(new RemoteAttempts({...f.options,credential:async()=>({...credential,actorId:2})}).recover());
  await assert.rejects(new RemoteAttempts({...f.options,request:async value=>({...await f.options.request(value),device_id:crypto.randomUUID()})}).recover());
  await f.state.begin('earlier-unreserved');await f.attempts.recover();assert.deepEqual((await f.state.read()).pending,['earlier-unreserved']);
});
test('an unprepared device retains the old guard, while a credential read failure cannot send an unreserved prepared request',async()=>{
  const f=fixture(),id=crypto.randomUUID();
  const unprepared=new RemoteAttempts({...f.options,credential:async()=>null});assert.deepEqual(await unprepared.begin(id,details),{});
  assert.deepEqual((await f.state.read()).pending,[id]);assert.equal(f.calls.length,0);
  const broken=new RemoteAttempts({...f.options,credential:async()=>{throw Error('encrypted credential failed');}});
  await assert.rejects(broken.begin(crypto.randomUUID(),details));assert.equal(f.calls.length,0);
});
test('reviewed cash, phone and expense writes reserve while calculations and print claims keep their existing guard',()=>{
  const f=fixture();
  for(const path of ['/admin/takeaway/checkout','/admin/phone-orders/tickets/12/settle','/admin/phone-orders/finish-batch','/admin/dining/tables','/admin/customers/save','/admin/branch-shifts/close','/admin/areas','/admin/areas/12','/admin/branch-expenses/save','/admin/question_answers','/admin/question_answers/12','/admin/features','/admin/features/12','/admin/contracts','/admin/contracts/12'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),true);
  for(const path of ['/admin/takeaway/quote','/admin/phone-orders/print-jobs/claim'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/areasDeleteAll',method:'DELETE'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/areasDeleteAll',method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/contractsDeleteAll',method:'DELETE'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/featuresDeleteAll',method:'DELETE'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/question_answersDeleteAll',method:'DELETE'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/contacts/12',method:'POST'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/contactsDeleteAll',method:'DELETE'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/contacts',method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/contacts/12',method:'PUT'}),false);
});
test('reviewed employee, inventory and shared expense-category POST paths reserve without accepting read reports or unreviewed writes',()=>{
  const f=fixture();
  for(const path of ['/admin/employees/save','/admin/employees/attendance','/admin/employees/void-entry','/admin/employees/close','/admin/employees/pay',
    '/admin/branch-stock/receive','/admin/branch-stock/recipes','/admin/branch-expenses/12/review','/admin/branch-expenses/categories'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),true);
  for(const path of ['/admin/employees/statement','/admin/employees/entries','/admin/branch-expenses/categories/save'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/employees/attendance',method:'GET'}),false);
});
