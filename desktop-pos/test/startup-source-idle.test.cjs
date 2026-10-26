'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os');
const {GenerationStore}=require('../src/dashboard-generation.cjs');
const {inspectStartupIdle}=require('../src/startup-source-idle.cjs');
const effect='a'.repeat(64),other='b'.repeat(64);
const uuid=n=>'00000000-0000-4000-8000-'+String(n).padStart(12,'0');
const expected={pending:0,conflicts:0,printEffects:0,legacyPending:0,legacyConflicts:0,openOrders:0};
function savedRecord(){
  const identity={effect_id:effect,enrollment_id:'c'.repeat(64),origin_command_id:uuid(1),device_id:uuid(2),
    kind:'sale_receipt',actor_id:10,branch:'f:100',revision:1,snapshot_sha256:'d'.repeat(64),render_sha256:'e'.repeat(64)};
  const record={format:1,identity,invocation_id:uuid(3),native_session_id:uuid(4),claim_token:uuid(5),
    phase:'outcome',delivered:true,permit_hash:'f'.repeat(64)};
  record.receipt={effect_id:effect,actor_id:10,branch:'f:100',enrollment_id:identity.enrollment_id,
    origin_command_id:identity.origin_command_id,revision:1,invocation_id:record.invocation_id,
    native_session_id:record.native_session_id,claim_token:record.claim_token,permit_hash:record.permit_hash,
    state:'invoked',reason:'spooler_accepted'};
  return record;
}
async function fixture(t){
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'startup-source-idle-'));
  t.after(()=>fs.rm(profile,{recursive:true,force:true}));
  const safeStorage={isEncryptionAvailable:()=>true,encryptString:value=>Buffer.from('private:'+value),
    decryptString:bytes=>{if(!bytes.toString().startsWith('private:'))throw Error('Invalid encrypted metadata');return bytes.toString().slice(8);}};
  const metadata=new GenerationStore(profile,safeStorage),calls=[];
  const state={legacy:{pending:0,errors:null},orders:[],local:{commands:[],counts:{pending:0,conflicts:0},print_protocol:1},
    supported:true,outcomes:{outcomes:[],pending:0},listing:{effects:[],attention:0}};
  const store={counts:()=>structuredClone(state.legacy),openOrders:()=>structuredClone(state.orders)};
  const runtime={supportsPrintEffects:()=>state.supported,control:async value=>{
    calls.push(structuredClone(value));
    if(value.action==='pending')return structuredClone(state.local);
    if(value.action==='print-outcomes')return structuredClone(state.outcomes);
    if(value.action==='print-list')return structuredClone(state.listing);
    throw Error('A startup idle read must never resolve, repair, acknowledge or print');
  }};
  return {profile,metadata,store,runtime,state,calls,save:value=>metadata.write('print-effects/'+effect,value),
    run:()=>inspectStartupIdle({store,runtime,metadata})};
}

test('an empty actual encrypted metadata folder returns precisely six validated zero counts without a mutation',async t=>{
  const f=await fixture(t);
  f.metadata.write=async()=>{throw Error('An idle check must not write metadata');};
  assert.deepEqual(await f.run(),expected);
  assert.deepEqual(f.calls,[{action:'pending'},{action:'print-outcomes'},{action:'print-list'}]);
  assert.deepEqual(await fs.readdir(f.profile),[]);
});

test('pending commands, conflicts, legacy errors and open orders retain their independent counts',async t=>{
  const f=await fixture(t);
  f.state.local={commands:[{}],counts:{pending:2,conflicts:3},print_protocol:1};
  f.state.legacy={pending:4,errors:2};f.state.orders=[{},{}];
  assert.deepEqual(await f.run(),{...expected,pending:2,conflicts:3,legacyPending:4,legacyConflicts:2,openOrders:2});
  f.state.legacy.conflicts=3;assert.equal((await f.run()).legacyConflicts,3);
});

test('the actual legacy SQLite store empty SUM and persisted pending rows match the startup API',async t=>{
  const f=await fixture(t),Store=require('../src/store.cjs');
  const store=new Store(path.join(f.profile,'legacy.sqlite'));t.after(()=>store.close());
  const inspect=()=>inspectStartupIdle({store,runtime:f.runtime,metadata:f.metadata});
  assert.equal(store.counts().errors,null);assert.deepEqual(await inspect(),expected);
  store.db.prepare('INSERT INTO outbox(id,order_id,payload,last_error) VALUES(?,?,?,?)')
    .run(uuid(99),uuid(100),'{}','unconfirmed original operation');
  store.db.prepare('INSERT INTO orders VALUES(?,?,?,?,?,?)')
    .run(uuid(100),'original-snapshot','draft',0,'{}','2026-10-26T00:00:00Z');
  assert.deepEqual(await inspect(),{...expected,legacyPending:1,legacyConflicts:1,openOrders:1});
  assert.equal(store.pending()[0].id,uuid(99));assert.equal(store.openOrders()[0].id,uuid(100));
});

test('unknown, coerced, negative, fractional or contradictory command and legacy counts throw',async t=>{
  const f=await fixture(t),initial=structuredClone(f.state);
  const changes=[
    s=>s.legacy=null,s=>delete s.legacy.errors,s=>s.legacy={pending:1,errors:null},s=>s.legacy={pending:0,errors:1},
    s=>s.orders={},s=>s.local=null,s=>s.local.commands=null,s=>s.local.commands=[{}],
    s=>s.local.counts=null,s=>s.local.print_protocol=2,s=>s.supported=null,
    s=>s.legacy.conflicts='0',s=>s.legacy.conflicts=1
  ];
  for(const value of [undefined,null,'0',false,-1,0.1,NaN,Infinity,Number.MAX_SAFE_INTEGER+1]){
    changes.push(s=>s.legacy.pending=value,s=>s.local.counts.pending=value,s=>s.local.counts.conflicts=value);
  }
  for(const change of changes){Object.assign(f.state,structuredClone(initial));change(f.state);await assert.rejects(f.run());}
});

test('an explicitly unsupported old print source skips print controls but still scans encrypted native records',async t=>{
  const f=await fixture(t);f.state.supported=false;f.state.local.print_protocol=0;
  assert.deepEqual(await f.run(),expected);assert.deepEqual(f.calls,[{action:'pending'}]);
  delete f.state.local.print_protocol;assert.deepEqual(await f.run(),expected);
  const record=savedRecord();record.delivered=false;await f.save(record);
  assert.equal((await f.run()).printEffects,1);
  f.state.local.print_protocol=1;await assert.rejects(f.run());
});

test('valid delivered outcomes permit idle while planned, burnt or undelivered outcomes always defer',async t=>{
  const f=await fixture(t);
  const complete=savedRecord();await f.save(complete);
  const file=path.join(f.profile,'print-effects',effect+'.enc'),before=await fs.readFile(file);
  f.metadata.write=async()=>{throw Error('No write permitted during inspection');};
  assert.deepEqual(await f.run(),expected);assert.deepEqual(await fs.readFile(file),before);
  const persist=value=>GenerationStore.prototype.write.call(f.metadata,'print-effects/'+effect,value);
  for(const phase of ['planned','burnt','outcome']){
    const record=savedRecord();record.phase=phase;record.delivered=false;
    if(phase!=='outcome')delete record.receipt;if(phase==='planned')delete record.permit_hash;
    await persist(record);assert.equal((await f.run()).printEffects,1);
  }
  const review=savedRecord();review.receipt.state='review';review.receipt.reason='native_burn_reply_lost';delete review.permit_hash;
  await persist(review);assert.deepEqual(await f.run(),expected);
});

test('malformed encrypted print records or mismatched immutable receipt tuples fail closed',async t=>{
  const f=await fixture(t);
  const changes=[
    r=>r.format=2,r=>r.phase='unknown',r=>r.delivered=1,r=>r.identity.effect_id=other,
    r=>r.identity.device_id='invalid',r=>r.identity.device_id+='\n',r=>r.identity.kind='unknown',r=>r.identity.actor_id=0,
    r=>r.identity.branch='f:100\n',r=>r.identity.revision=0,r=>r.identity.render_sha256='invalid',
    r=>r.identity.snapshot_sha256='invalid',r=>r.identity.snapshot_sha256+='\n',r=>r.receipt=null,r=>r.receipt.effect_id=other,
    r=>r.receipt.actor_id=11,r=>r.receipt.origin_command_id=uuid(99),r=>r.receipt.branch='gs:1',
    r=>r.receipt.enrollment_id=other,r=>r.receipt.revision=2,r=>r.receipt.invocation_id=uuid(99),
    r=>r.receipt.native_session_id=uuid(99),r=>r.receipt.claim_token=uuid(99),r=>r.receipt.permit_hash=other,
    r=>r.receipt.reason='native_rejected',r=>r.receipt.state='planned',
    r=>{r.phase='planned';r.delivered=false;delete r.receipt;},
    r=>{r.phase='burnt';r.delivered=false;delete r.receipt;delete r.permit_hash;},
    r=>{r.phase='planned';delete r.receipt;delete r.permit_hash;}
  ];
  for(const change of changes){const record=savedRecord();change(record);await f.save(record);await assert.rejects(f.run());}
  await f.save(null);await assert.rejects(f.run());
  await fs.writeFile(path.join(f.profile,'print-effects',effect+'.enc'),'corrupt encrypted bytes');await assert.rejects(f.run());
});

test('unresolved DB outcomes, unattempted automatic intents and orphan claims all defer without printing or review',async t=>{
  const f=await fixture(t);
  f.state.outcomes={outcomes:[savedRecord().receipt],pending:2};
  f.state.listing={effects:[{effect_id:other,kind:'phone_kitchen',branch:'f:100',actor_id:10,
    origin_command_id:uuid(10),revision:1}],attention:3};
  assert.equal((await f.run()).printEffects,6);
  f.state.outcomes={outcomes:[],pending:0};f.state.listing={effects:[],attention:1};
  assert.equal((await f.run()).printEffects,1);
  assert.ok(f.calls.every(value=>['pending','print-outcomes','print-list'].includes(value.action)));
  assert.ok(f.calls.filter(value=>value.action==='print-list').every(value=>Object.keys(value).length===1));
  assert.deepEqual(await fs.readdir(f.profile),[]);
});

test('unknown print DB state, malformed receipts, listing contents or count overflows never become idle',async t=>{
  const f=await fixture(t),initial=structuredClone(f.state);
  const changes=[
    s=>s.outcomes=null,s=>s.outcomes.outcomes=null,s=>s.outcomes.outcomes=[savedRecord().receipt],
    s=>s.outcomes={outcomes:[{...savedRecord().receipt,actor_id:0}],pending:1},
    s=>s.listing=null,s=>s.listing.effects=null,
    s=>s.listing.effects=[{effect_id:effect,kind:'sale_receipt',actor_id:10,branch:'f:100',origin_command_id:uuid(1),revision:1}],
    s=>s.listing.effects=[{effect_id:effect,kind:'phone_kitchen'}],
    s=>{s.outcomes.pending=Number.MAX_SAFE_INTEGER;s.listing.attention=1;}
  ];
  for(const value of [undefined,null,'0',false,-1,0.1,NaN,Infinity])changes.push(s=>s.outcomes.pending=value,s=>s.listing.attention=value);
  for(const change of changes){Object.assign(f.state,structuredClone(initial));change(f.state);await assert.rejects(f.run());}
  for(const action of ['pending','print-outcomes','print-list']){
    Object.assign(f.state,structuredClone(initial));const original=f.runtime.control;
    f.runtime.control=async value=>{if(value.action===action)throw Error('Read unavailable');return original(value);};
    await assert.rejects(f.run(),/Read unavailable/);f.runtime.control=original;
  }
});

test('unknown filesystem entries, symlinks and changing encrypted files defer rather than bypassing their records',async t=>{
  const f=await fixture(t);await f.save(savedRecord());
  const folder=path.join(f.profile,'print-effects'),file=path.join(folder,effect+'.enc');
  await fs.writeFile(path.join(folder,'unfinished.tmp'),'private:planned');await assert.rejects(f.run());
  await fs.unlink(path.join(folder,'unfinished.tmp'));
  const read=f.metadata.read.bind(f.metadata);f.metadata.read=async name=>{const record=await read(name);await fs.appendFile(file,' ');return record;};
  await assert.rejects(f.run());f.metadata.read=read;await f.save(savedRecord());
  await fs.unlink(file);await fs.symlink(path.join(f.profile,'outside.enc'),file);await assert.rejects(f.run());
});

test('unavailable metadata location or API cannot claim the native print journal is empty',async t=>{
  const f=await fixture(t);
  for(const metadata of [null,{read:async()=>null},{profile:'relative',read:async()=>null},{profile:f.profile}]){
    await assert.rejects(inspectStartupIdle({store:f.store,runtime:f.runtime,metadata}));
  }
});
