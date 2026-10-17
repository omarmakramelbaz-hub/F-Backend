const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const DashboardMode=require('../src/dashboard-mode.cjs');
function fixture(){
  const records=new Map(),calls=[],connection={deviceId:crypto.randomUUID(),actorId:10,database:'fasakhansta_dashboard_stage_'+'a'.repeat(16),
    snapshotId:crypto.randomUUID(),schemaHash:'b'.repeat(64),serverOrigin:'https://fixture.test'};
  let held=null,local=true,pending=0,probeActor=10,loseCancel=false,loseBegin=false;
  const metadata={read:async name=>records.get(name)||null,write:async(name,value)=>records.set(name,structuredClone(value))};
  const runtime={metadata,origin:'http://127.0.0.1:43123',token:'native-browser',isPrepared:async()=>true,connection:async()=>connection,start:async()=>{},
    control:async value=>{
      calls.push(value.action);
      if(value.action==='refresh-begin'){
        if(pending)throw Error('pending writes');
        held=value.refresh_id;
        if(loseBegin){loseBegin=false;throw Error('lost begin');}
        return {held:true,device_id:connection.deviceId,actor_id:connection.actorId,schema_hash:connection.schemaHash};
      }
      if(value.action==='refresh-status')return {exists:held===value.refresh_id,held:held===value.refresh_id};
      if(value.action==='refresh-cancel'){if(loseCancel)throw Error('lost cancel');held=null;return {held:false};}
    }};
  const view={current:()=>({local}),switchTo:async(target,work=async()=>{})=>{calls.push('switch');await work();local=Boolean(target.localToken);}};
  const generations={run:async()=>{calls.push('generation');connection.snapshotId=crypto.randomUUID();}};
  const probe=async()=>({device_id:connection.deviceId,actor_id:probeActor});
  const mode=new DashboardMode({runtime,view,generations,probe});
  return {mode,runtime,view,metadata,connection,records,calls,held:()=>held,setPending:value=>pending=value,setActor:value=>probeActor=value,
    loseCancel:()=>loseCancel=true,loseBegin:()=>loseBegin=true,recoverCancel:()=>loseCancel=false,generations,probe};
}
test('return to server follows coherent refresh, a common write fence and the same signed-in account',async()=>{
  const f=fixture();await f.mode.refresh();assert.equal(f.view.current().local,false);assert.ok(f.held());
  assert.ok(f.calls.indexOf('generation')<f.calls.indexOf('refresh-begin'));assert.ok(f.calls.indexOf('refresh-begin')<f.calls.indexOf('switch'));
  assert.equal(f.records.get('return').previous.snapshotId,f.connection.snapshotId);
  await f.mode.local({method:'GET'});assert.equal(f.view.current().local,true);assert.equal(f.held(),null);assert.equal(f.records.get('return'),null);
});
test('a pending operation racing the refresh cannot switch to server',async()=>{
  const f=fixture();f.setPending(1);await assert.rejects(f.mode.refresh());
  assert.equal(f.view.current().local,true);assert.equal(f.calls.includes('switch'),false);
});
test('another server account or a lost session preserves local mode and reopens local writing',async()=>{
  const f=fixture();f.setActor(11);await assert.rejects(f.mode.refresh());
  assert.equal(f.view.current().local,true);assert.equal(f.held(),null);assert.equal(f.records.get('return'),null);
});
test('a lost begin reply is recovered using its durable capability without acquiring another fence',async()=>{
  const f=fixture();await f.metadata.write('return',{format:1,id:crypto.randomUUID(),token:'d'.repeat(64),previous:f.connection});
  f.loseBegin();await assert.rejects(f.mode.hold());assert.ok(f.held());
  const restarted=new DashboardMode({runtime:f.runtime,view:f.view,generations:f.generations,probe:f.probe});
  await restarted.recover();assert.equal(f.held(),null);assert.equal(f.calls.filter(x=>x==='refresh-begin').length,1);
});
test('lost cancellation retains the inactive-local fence and recovery intent and cannot activate stale forms',async()=>{
  const f=fixture();await f.mode.refresh();f.loseCancel();await assert.rejects(f.mode.local({method:'GET'}));
  assert.equal(f.view.current().local,false);assert.ok(f.held());assert.ok(f.records.get('return'));
  f.recoverCancel();await f.mode.local({method:'GET'});assert.equal(f.view.current().local,true);assert.equal(f.held(),null);
});
test('an unknown remote POST result cannot silently switch to another database',async()=>{
  const f=fixture();await f.mode.refresh();await assert.rejects(f.mode.local({method:'POST'}));
  assert.equal(f.view.current().local,false);assert.ok(f.held());
});
test('remote background refresh first releases its own inactive fence, then holds the new coherent generation',async()=>{
  const f=fixture();await f.mode.refresh();const old=f.records.get('return').id;await f.mode.refresh();
  assert.equal(f.view.current().local,false);assert.notEqual(f.records.get('return').id,old);
  assert.equal(f.calls.filter(x=>x==='switch').length,1);assert.equal(f.calls.filter(x=>x==='generation').length,2);
});
