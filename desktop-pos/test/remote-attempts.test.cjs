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
  for(const path of ['/admin/takeaway/checkout','/admin/phone-orders/tickets/12/settle','/admin/phone-orders/finish-batch','/admin/dining/tables','/admin/customers/save','/admin/branch-shifts/close','/admin/areas','/admin/areas/12','/admin/branch-expenses/save','/admin/question_answers','/admin/question_answers/12'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),true);
  for(const path of ['/admin/takeaway/quote','/admin/phone-orders/print-jobs/claim'])
    assert.equal(f.attempts.supported({url:credential.serverOrigin+path,method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/areasDeleteAll',method:'DELETE'}),true);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/areasDeleteAll',method:'POST'}),false);
  assert.equal(f.attempts.supported({url:credential.serverOrigin+'/admin/question_answersDeleteAll',method:'DELETE'}),true);
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
