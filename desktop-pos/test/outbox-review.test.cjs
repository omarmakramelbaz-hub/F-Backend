'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const Review=require('../src/dashboard-outbox-review.cjs');
const id='11111111-1111-4111-8111-111111111111',device='22222222-2222-4222-8222-222222222222',snapshot='33333333-3333-4333-8333-333333333333';
function fixture(){
  const calls=[],audit=[],dialogs=[],source={deviceId:device,actorId:10,snapshotId:snapshot,sourceRevision:'a'.repeat(40),database:'fasakhansta_dashboard_stage_1234567890abcdef'};
  const value={format:1,device_id:device,actor_id:10,snapshot_id:snapshot,held:false,counts:{pending:2,conflicts:1,acknowledged:5},
    first:{command_id:id,sequence:6,actor_id:10,route_name:'takeaway.checkout',status:'conflict',attempts:2,occurred_at:'2026-10-20 00:00:00',last_error:'تغيّر المخزون على السيرفر',review_token:'f'.repeat(64)}};
  const answers=[0,0],runtime={manifest:{sourceHashes:{'app/Services/Dashboard/DesktopDashboardOutboxReview.php':'a'.repeat(64)}},connection:async()=>structuredClone(source),
    metadata:{write:async(name,data)=>{audit.push({name,data:structuredClone(data)});calls.push('audit:'+data.state);}},
    control:async request=>{calls.push(request.action);if(request.action==='outbox-review')return structuredClone(value);return {format:1,command_id:id,queued:true,counts:{pending:3,conflicts:0,acknowledged:5}};}};
  const review=new Review({runtime,dialog:{showMessageBox:async(_window,options)=>{dialogs.push(options);return {response:answers.shift()??1};}},synchronize:async()=>calls.push('sync'),randomUUID:()=> '44444444-4444-4444-8444-444444444444',now:()=> '2026-10-20T01:00:00Z'});
  return {review,runtime,value,source,answers,calls,audit,dialogs};
}
test('explicit retry records durable intent before exact requeue and result before scheduling synchronization',async()=>{
  const f=fixture();assert.equal((await f.review.open({})).retried,true);
  assert.deepEqual(f.calls,['outbox-review','audit:requested','outbox-retry','audit:queued','sync']);
  assert.equal(f.dialogs.length,2);for(const dialog of f.dialogs){assert.equal(dialog.defaultId,1);assert.equal(dialog.cancelId,1);}
  assert.match(f.dialogs[0].detail,new RegExp(id));assert.match(f.dialogs[0].detail,/تغيّر المخزون/);
  assert.equal(f.audit[0].data.commandId,id);assert.equal(f.audit[0].data.reviewToken,'f'.repeat(64));assert.equal(f.audit[1].data.state,'queued');
  assert.equal(JSON.stringify(f.audit).includes('token_cipher'),false);
});
test('either cancellation retains the conflict and performs no audit, queue mutation or sync',async()=>{
  for(const answers of [[1],[0,1]]){const f=fixture();f.answers.splice(0,2,...answers);assert.equal((await f.review.open({})).retried,false);
    assert.deepEqual(f.calls,['outbox-review']);assert.equal(f.audit.length,0);}
});
test('pending, refreshed fence and empty outbox have a read-only review with no retry control',async()=>{
  for(const state of ['pending','held','empty']){const f=fixture();if(state==='pending')f.value.first.status='pending';if(state==='held')f.value.held=true;if(state==='empty'){f.value.first=null;f.value.counts.pending=0;f.value.counts.conflicts=0;}
    assert.equal((await f.review.open({})).retried,false);assert.deepEqual(f.dialogs[0].buttons,['إغلاق']);assert.deepEqual(f.calls,['outbox-review']);}
});
test('audit storage failure prevents private retry and uncertain control preserves the requested audit',async()=>{
  const before=fixture();before.runtime.metadata.write=async()=>{throw Error('disk full');};await assert.rejects(before.review.open({}),/disk full/);assert.deepEqual(before.calls,['outbox-review']);
  const lost=fixture(),control=lost.runtime.control;lost.runtime.control=async request=>{if(request.action==='outbox-retry')throw Error('lost control reply');return control(request);};
  await assert.rejects(lost.review.open({}),/lost control reply/);assert.deepEqual(lost.audit.map(v=>v.data.state),['requested','unconfirmed']);assert.equal(lost.calls.includes('sync'),false);
});
test('generation drift after confirmation cannot requeue into another retained database',async()=>{
  const f=fixture();let read=0;f.runtime.connection=async()=>({...f.source,snapshotId:++read===1?snapshot:'55555555-5555-4555-8555-555555555555'});
  await assert.rejects(f.review.open({}),/تغيّرت/);assert.deepEqual(f.calls,['outbox-review','audit:requested']);
});
test('unsupported retained sources and mismatched metadata stop before any confirmation or mutation',async()=>{
  const old=fixture();old.runtime.manifest.sourceHashes={};await assert.rejects(old.review.open({}),/تحديث/);assert.deepEqual(old.calls,[]);
  for(const field of ['device_id','actor_id','snapshot_id']){const f=fixture();f.value[field]='foreign';await assert.rejects(f.review.open({}),/التحقق/);assert.equal(f.dialogs.length,0);assert.deepEqual(f.calls,['outbox-review']);}
});
test('concurrent review clicks share one explicit retry and a later review never auto-replays an old intent',async()=>{
  const f=fixture();const one=f.review.open({}),two=f.review.open({});assert.equal(one,two);await one;
  assert.equal(f.calls.filter(v=>v==='outbox-retry').length,1);f.answers.push(1);await f.review.open({});assert.equal(f.calls.filter(v=>v==='outbox-retry').length,1);
});
