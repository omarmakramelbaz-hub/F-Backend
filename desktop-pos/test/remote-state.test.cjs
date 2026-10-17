const test=require('node:test'),assert=require('node:assert/strict');
const RemoteState=require('../src/dashboard-remote-state.cjs');
function fixture(){
  const records=new Map();
  const metadata={read:async name=>records.get(name)||null,write:async(name,value)=>records.set(name,structuredClone(value))};
  return {records,metadata,state:new RemoteState(metadata)};
}
test('a lost remote response survives restart and blocks both stale local use and snapshot clearing',async()=>{
  const f=fixture();await f.state.begin(17);
  const restarted=new RemoteState(f.metadata);
  await assert.rejects(restarted.assertClean());
  let exported=false;await assert.rejects(restarted.snapshot(async()=>{exported=true;}));
  assert.equal(exported,false);assert.deepEqual((await restarted.read()).pending,['17']);
});
test('a completed remote write remains dirty until an entire coherent snapshot succeeds',async()=>{
  const f=fixture();await f.state.begin(1);await f.state.complete(1);await assert.rejects(f.state.assertClean());
  await assert.rejects(f.state.snapshot(async()=>{throw Error('staging failed');}));
  assert.equal((await f.state.read()).dirty,true);
  await f.state.snapshot(async()=>{});await f.state.assertClean();
});
test('the snapshot gate refuses concurrent remote writes and restores submission after failure',async()=>{
  const f=fixture();let release,started;const ready=new Promise(r=>started=r);
  const snapshot=f.state.snapshot(async()=>{started();await new Promise(r=>release=r);throw Error('lost download');});
  await ready;await assert.rejects(f.state.begin(2));assert.deepEqual((await f.state.read()).pending,[]);
  release();await assert.rejects(snapshot);await f.state.begin(3);assert.deepEqual((await f.state.read()).pending,['3']);
});
test('simultaneous server writes retain every durable uncertainty and a late completion cannot discard another request',async()=>{
  const f=fixture();await Promise.all([f.state.begin(4),f.state.begin(5)]);await f.state.complete(4);
  assert.deepEqual((await f.state.read()).pending,['5']);await assert.rejects(f.state.assertClean());
});
