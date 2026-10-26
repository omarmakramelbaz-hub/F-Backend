const test=require('node:test'),assert=require('node:assert/strict');
const RemoteState=require('../src/dashboard-remote-state.cjs');
function fixture(){
  const records=new Map(),writes=[];
  const metadata={read:async name=>structuredClone(records.get(name)||null),write:async(name,value)=>{
    writes.push([name,structuredClone(value)]);records.set(name,structuredClone(value));
  }};
  return {records,writes,metadata,state:new RemoteState(metadata)};
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
test('concurrent central navigation and polling flush the dirty fence once and retain it across restart',async()=>{
  const f=fixture();
  await Promise.all(Array.from({length:64},()=>f.state.browse()));
  assert.equal(f.writes.length,1,'Repeated server GETs must not queue identical durable writes.');
  assert.deepEqual(await f.state.read(),{format:1,dirty:true,pending:[]});
  const restarted=new RemoteState(f.metadata);await restarted.browse();
  assert.equal(f.writes.length,1,'A persisted dirty fence needs no per-process or per-page rewrite.');
  await assert.rejects(restarted.assertClean());
});
test('an already dirty record preserves pending uncertainty and unknown metadata without a redundant write',async()=>{
  const f=fixture(),record={format:1,dirty:true,pending:['unconfirmed'],extension:{keep:['original']}};
  f.records.set('remote-writes',structuredClone(record));
  await Promise.all(Array.from({length:12},()=>f.state.browse()));
  assert.equal(f.writes.length,0);assert.deepEqual(await f.state.read(),record);
  let exported=false;
  await assert.rejects(f.state.snapshot(async()=>{exported=true;}));
  assert.equal(exported,false);assert.deepEqual(await f.state.read(),record);
});
test('a failed first browse flush cannot admit the read or suppress a later durable retry',async()=>{
  const f=fixture(),write=f.metadata.write;let fail=true,attempts=0;
  f.metadata.write=async(...args)=>{attempts++;if(fail){fail=false;throw Error('disk flush failed');}return write(...args);};
  await assert.rejects(f.state.browse(),/disk flush failed/);
  assert.equal(f.records.has('remote-writes'),false);
  await f.state.browse();await f.state.browse();
  assert.equal(attempts,2);assert.equal(f.writes.length,1);assert.equal((await f.state.read()).dirty,true);
});
test('snapshot holds block even dirty reads and a successful snapshot rearms exactly one browse flush',async()=>{
  const f=fixture();await f.state.browse();let release,started;
  const ready=new Promise(resolve=>started=resolve);
  const snapshot=f.state.snapshot(async()=>{started();await new Promise(resolve=>release=resolve);});
  await ready;await assert.rejects(f.state.browse(),/تحديث بيانات الجهاز/);
  assert.equal(f.writes.length,1);
  release();await snapshot;await f.state.assertClean();
  assert.equal(f.writes.length,2,'The coherent snapshot durably clears the old fence.');
  await Promise.all(Array.from({length:64},()=>f.state.browse()));
  assert.equal(f.writes.length,3,'Only the first subsequent central read rearms the dirty fence.');
  await assert.rejects(f.state.assertClean());
});
test('dirty-read optimization still rejects malformed durable state on every navigation',async()=>{
  const f=fixture();await f.state.browse();
  f.records.set('remote-writes',{format:1,dirty:true,pending:[42]});
  await assert.rejects(f.state.browse(),/تعذر التحقق/);
  assert.equal(f.writes.length,1);
});
