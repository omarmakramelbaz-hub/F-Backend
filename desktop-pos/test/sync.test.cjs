const test=require('node:test'),assert=require('node:assert/strict');
const Sync=require('../src/sync.cjs');

function fixture(){
  const row={id:'saved-sale',event:{id:'saved-sale',kind:'sale'}},pending=[row],calls=[];
  const store={pending:()=>pending.slice(),acknowledge:id=>pending.splice(pending.findIndex(value=>value.id===id),1),
    fail(){},set(){},snapshot:()=>null,saveSnapshot(){}};
  return {row,pending,calls,store};
}

test('pairing bootstrap reads health and menu without submitting an existing paid sale',async()=>{
  const f=fixture(),sync=new Sync(f.store,async(method,endpoint,body)=>{f.calls.push({method,endpoint,body});return {};});
  await sync.run({bootstrapOnly:true});
  assert.deepEqual(f.calls.map(value=>value.endpoint),['health','snapshot']);assert.equal(f.pending[0].id,f.row.id);
  await sync.run();assert.equal(f.calls.filter(value=>value.endpoint==='sync').length,1);assert.equal(f.pending.length,0);
});

test('concurrent callers join the active cycle instead of returning early or sending a second sale',async()=>{
  const f=fixture();let release;
  const sync=new Sync(f.store,async(method,endpoint,body)=>{f.calls.push({method,endpoint,body});if(endpoint==='sync')await new Promise(resolve=>{release=resolve;});return {};});
  const first=sync.run(),second=sync.run();assert.equal(first,second);assert.equal(sync.busy,true);
  assert.equal(f.calls.length,1);assert.equal(f.pending.length,1);release();await second;
  assert.equal(f.calls.filter(value=>value.endpoint==='sync').length,1);assert.equal(f.pending.length,0);assert.equal(sync.busy,false);
});
