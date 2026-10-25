'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {DashboardDelta,indexTableHash,SAFE_ERROR,RESTART_CODE,RESTART_ERROR}=require('../src/dashboard-delta.cjs');
const {DashboardGeneration,prepared}=require('../src/dashboard-generation.cjs');
const LocalRuntime=require('../src/local-runtime.cjs'),readSnapshot=require('../src/dashboard-download.cjs');
const settle=async()=>{for(let count=0;count<15;count++)await Promise.resolve();await new Promise(setImmediate);};
const source=sha=>({format:1,files:20,sha256:sha.repeat(64),framework:'8.83.29'});

function fixture(){
  const records=new Map(),calls=[],downloads=[],releases=[],contexts=[],stages=[];
  const previous={format:2,deviceId:crypto.randomUUID(),actorId:10,serverOrigin:'https://example.test',token:'a'.repeat(64),
    schemaHash:'b'.repeat(64),snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),generation:'a'.repeat(16),
    database:'fasakhansta_dashboard_stage_'+'a'.repeat(16),sourceRevision:'c'.repeat(40),sourceFingerprint:source('d'),
    branches:['f:100'],fullCoverage:true,mediaVerified:true};
  records.set('prepared',structuredClone(previous));
  let sequence=7,pending=0,idle=true,local=true,held=null,beforeView=null,downloadOverride=null,stageOverride=null;
  const attempts=new Map(),context={manifest:{format:1,platform:'win32-x64',sourceRevision:'e'.repeat(40),sourceFingerprint:source('f')},sourceFingerprint:source('f')};
  const wire={format:1,kind:'dashboard-data-delta',device_id:previous.deviceId,actor_id:previous.actorId,
    snapshot_id:crypto.randomUUID(),schema_hash:'1'.repeat(64),source:source('f'),branches:['f:100'],
    coverage:{full_dashboard:true,media:true},media:[],media_issues:[],enrollment:{enrollment_hash:'2'.repeat(64),enrolled_at:'2026-10-23T00:00:00Z'},
    created_scopes:[],delta_summary:{changed_tables:1,added_rows:1,removed_rows:0},
    tables:{users:{ddl:'CREATE TABLE `users` (`id` bigint NOT NULL)',delta:{remove:{},add:[{id:10}],target_hash:'3'.repeat(64),count:1}}}};
  const baseline={media:[],enrollment:structuredClone(wire.enrollment),created_scopes:[]};
  const metadata={read:async key=>records.has(key)?structuredClone(records.get(key)):null,
    write:async(key,value)=>{calls.push('write:'+key);records.set(key,structuredClone(value));},
    clearIntent:async()=>{calls.push('clear:refresh');records.delete('refresh');}};
  let lastCapture;
  const runtime={connection:async()=>metadata.read('prepared'),
    deltaContext:async value=>{calls.push('context');assert.equal(value.deviceId,previous.deviceId);contexts.push(context);return context;},
    authorizeDeltaContext:async value=>{calls.push('authorize-context');assert.equal(value,context);return true;},
    async captureDelta(selected,active,id,token){
      calls.push('capture');assert.equal(selected,context);assert.equal(active.database,previous.database);
      assert.match(id,/^[a-f0-9]{16}$/);assert.match(token,/^[a-f0-9]{64}$/);
      const capture={format:1,kind:'dashboard-delta-capture',capture_database:'fasakhansta_dashboard_stage_'+id,
        source_database:active.database,capture_id:'4'.repeat(64),sequence,
        replay:{format:1,source_database:active.database,sequence,rows:7,sha256:'7'.repeat(64)},
        identity:{device_id:active.deviceId,actor_id:active.actorId,snapshot_id:active.snapshotId,schema_hash:active.schemaHash,
          branches:active.branches,coverage:{full_dashboard:true,media:true},source:active.sourceFingerprint,
          media:baseline.media,enrollment:baseline.enrollment,created_scopes:baseline.created_scopes},
        index:{format:1,kind:'dashboard-delta-index',device_id:active.deviceId,actor_id:active.actorId,source:context.manifest.sourceFingerprint,
          tables:{users:{schema_sha256:'5'.repeat(64),count:0,hashes:{}}}}};
      lastCapture=capture;return capture;
    },
    async stageDelta(selected,capture,delta,id,token){
      calls.push('stage');assert.equal(selected,context);assert.equal(capture,lastCapture);assert.equal(delta,wire);
      assert.match(id,/^[a-f0-9]{16}$/);assert.match(token,/^[a-f0-9]{64}$/);
      const receipt={format:1,verified:true,device_id:delta.device_id,actor_id:delta.actor_id,snapshot_id:delta.snapshot_id,
        schema_hash:delta.schema_hash,branches:delta.branches,coverage:delta.coverage,
        media:delta.media.map(item=>{const {ticket,...receipt}=item;return receipt;}),source:delta.source,tables:1,rows:1,table_rows:{users:1},delta_replay:structuredClone(capture.replay)};
      const candidate={generation:id,database:'fasakhansta_dashboard_stage_'+id,receipt,sourceRevision:context.manifest.sourceRevision,
        sourceFingerprint:context.manifest.sourceFingerprint,mediaVerified:true,mediaSeal:{format:1,manifest_sha256:'6'.repeat(64),files:[]}};
      candidate.archiveHandoff={format:1,kind:'desktop-preverified-handoff',signature:'8'.repeat(64),payload:{database:candidate.database,
        source_database:previous.database,source_identity:{snapshot_id:previous.snapshotId,schema_hash:previous.schemaHash},sequence:capture.sequence,replay:structuredClone(capture.replay)}};
      stages.push(candidate);return stageOverride?stageOverride(candidate):candidate;
    },
    async finalizeDelta(candidate,intent){calls.push('finalize');assert.ok(held);assert.equal(held,intent.id);assert.equal(candidate,stages.at(-1));return {archived_commands:7};},
    async activate(next,commit){calls.push('activate');assert.ok(held);await commit();},
    async releaseDeltaCapture(capture,token){calls.push('release-capture');releases.push({capture,token});return {discarded:true};},
    async control(input){
      calls.push(input.action);const attempt=attempts.get(input.refresh_id);
      if(attempt&&attempt.token!==input.token)throw Error('wrong owner');
      if(input.action==='pending')return {counts:{pending,conflicts:0}};
      if(input.action==='refresh-begin'){
        if(pending)throw Object.assign(Error('new local work'),{status:409});
        const fence={format:1,held:true,device_id:previous.deviceId,actor_id:previous.actorId,snapshot_id:previous.snapshotId,
          schema_hash:previous.schemaHash,branches:previous.branches,coverage:{full_dashboard:true,media:true},refresh_id:input.refresh_id,fenced_sequence:sequence};
        held=input.refresh_id;attempts.set(held,{token:input.token,fence});return fence;
      }
      if(input.action==='refresh-status')return attempt?{exists:true,...attempt.fence}:{exists:false,held:false};
      if(input.action==='refresh-cancel'){assert.ok(attempt);held=null;attempt.fence.held=false;return attempt.fence;}
      throw Error('unexpected control action');
    }
  };
  const view={current:()=>({local}),canRefresh:async()=>idle,
    refresh:async work=>{calls.push('view');if(beforeView)await beforeView();if(!idle)return {deferred:true};calls.push('view-held');try{return await work();}finally{calls.push('view-released');}}};
  const remoteState={assertClean:async()=>calls.push('remote-clean'),snapshot:async work=>{calls.push('remote-snapshot');return work();}};
  const recover=new DashboardGeneration({runtime,metadata,download:async()=>{throw Error('full snapshot fallback forbidden');}});
  const download=async(index,active)=>{calls.push('download');downloads.push({index,active});assert.equal(index,lastCapture.index);
    assert.equal(Object.hasOwn(index,'capture_token'),false);assert.equal(Object.hasOwn(index,'capture_database'),false);
    assert.equal(Object.hasOwn(index,'identity'),false);assert.equal(Object.hasOwn(index,'capture_id'),false);
    return downloadOverride?downloadOverride(index,active):wire;};
  const create=options=>new DashboardDelta({runtime,metadata,download,view,recover:()=>recover.recoverIntent(),remoteState,...options});
  return {records,calls,downloads,releases,contexts,stages,context,previous,wire,baseline,runtime,metadata,view,remoteState,create,
    capture:()=>lastCapture,held:()=>held,setSequence:value=>sequence=value,setPending:value=>pending=value,setIdle:value=>idle=value,
    setLocal:value=>local=value,setBeforeView:fn=>beforeView=fn,setDownload:fn=>downloadOverride=fn,setStage:fn=>stageOverride=fn};
}

test('delta capture, network and complete stage verification finish before the short native view and database fence',async()=>{
  const f=fixture(),next=await f.create().run();
  assert.ok(prepared(next,f.previous.deviceId));assert.equal(next.sourceRevision,f.context.manifest.sourceRevision);
  assert.ok(f.calls.indexOf('capture')<f.calls.indexOf('download'));assert.ok(f.calls.indexOf('download')<f.calls.indexOf('stage'));
  assert.ok(f.calls.indexOf('stage')<f.calls.indexOf('view-held'));assert.ok(f.calls.indexOf('view-held')<f.calls.indexOf('write:refresh'));
  assert.ok(f.calls.indexOf('write:refresh')<f.calls.indexOf('refresh-begin'));assert.ok(f.calls.indexOf('refresh-begin')<f.calls.indexOf('finalize'));
  assert.ok(f.calls.indexOf('finalize')<f.calls.indexOf('activate'));assert.ok(f.calls.indexOf('activate')<f.calls.indexOf('write:prepared'));
  assert.equal(f.downloads.length,1);assert.equal(f.records.get('prepared').database,next.database);assert.equal(f.records.has('refresh'),false);
  assert.equal(f.releases.length,1);assert.equal(f.releases[0].capture.source_database,f.previous.database);
});

test('a slow delta download leaves sales and the active pointer unfenced until final cutover',async()=>{
  const f=fixture();let release;
  f.setDownload(async()=>{await new Promise(resolve=>release=resolve);return f.wire;});
  const accepted=f.create().run();await settle();assert.equal(f.calls.includes('download'),true);assert.equal(f.held(),null);
  assert.equal(f.calls.includes('view-held'),false);assert.deepEqual(f.records.get('prepared'),f.previous);assert.equal(f.records.has('refresh'),false);
  release();await accepted;
});

test('editing at the final idle gate defers activation after background preparation without acquiring a fence',async()=>{
  const f=fixture();f.setBeforeView(()=>f.setIdle(false));const result=await f.create().run();
  assert.equal(result.deferred,true);assert.ok(f.calls.includes('stage'));assert.equal(f.calls.includes('refresh-begin'),false);
  assert.equal(f.calls.includes('activate'),false);assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('an in-flight outbox acknowledgement defers only the final delta handoff after all background work',async()=>{
  const f=fixture();let handed=false;const result=await f.create({handoff:async()=>{handed=true;assert.ok(f.calls.includes('stage'));
    assert.equal(f.calls.includes('view'),false);assert.equal(f.calls.includes('write:refresh'),false);return {deferred:true};}}).run();
  assert.equal(handed,true);assert.equal(result.deferred,true);assert.equal(f.calls.includes('refresh-begin'),false);
  assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('the outbox handoff spans the view fence, pointer activation and view release',async()=>{
  const f=fixture();let held=false;const refresh=f.view.refresh;
  f.view.refresh=async work=>{assert.equal(held,true);const next=await refresh(work);assert.equal(held,true);return next;};
  const result=await f.create({handoff:async work=>{assert.ok(f.calls.includes('stage'));assert.equal(f.calls.includes('view'),false);
    held=true;try{return await work();}finally{held=false;}}}).run();
  assert.notEqual(result.database,f.previous.database);assert.equal(held,false);assert.equal(f.records.has('refresh'),false);
});

test('a new local command accepted while the delta downloads cancels the final fence and retains the current generation',async()=>{
  const f=fixture();f.setDownload(async()=>{f.setSequence(8);return f.wire;});const result=await f.create().run();
  assert.equal(result.deferred,true);assert.ok(f.calls.includes('refresh-cancel'));assert.equal(f.held(),null);
  assert.equal(f.calls.includes('finalize'),false);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('new unacknowledged local work refuses the final fence and is treated as a deferred refresh',async()=>{
  const f=fixture();f.setBeforeView(()=>f.setPending(1));const result=await f.create().run();
  assert.equal(result.deferred,true);assert.equal(f.calls.includes('activate'),false);assert.equal(f.calls.includes('finalize'),false);
  assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('a lost delta response does not write an intent, enter the view guard or retry with a full snapshot',async()=>{
  const f=fixture();f.setDownload(async()=>{throw Error('network unavailable');});await assert.rejects(f.create().run());
  assert.equal(f.downloads.length,1);assert.equal(f.calls.includes('stage'),false);assert.equal(f.calls.includes('view'),false);
  assert.equal(f.records.has('refresh'),false);assert.equal(f.held(),null);assert.deepEqual(f.records.get('prepared'),f.previous);
  assert.equal(f.releases.length,1);
});

test('foreign server account, source or malformed coverage cannot activate a delta stage',async()=>{
  for(const mutate of [wire=>wire.actor_id++,wire=>wire.device_id=crypto.randomUUID(),wire=>wire.source={...wire.source,sha256:'9'.repeat(64)},
    wire=>wire.coverage={full_dashboard:true,media:false},wire=>wire.schema_hash='invalid']){
    const f=fixture();mutate(f.wire);await assert.rejects(f.create().run());
    assert.equal(f.calls.includes('activate'),false);assert.equal(f.calls.includes('view-held'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('stage media, source and receipt failures retain the current pointer without taking a database fence',async()=>{
  for(const change of [{mediaVerified:false},{sourceFingerprint:source('9')},{receipt:{verified:false}}]){
    const f=fixture();f.setStage(candidate=>({...candidate,...change}));await assert.rejects(f.create().run());
    assert.equal(f.calls.includes('refresh-begin'),false);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('parallel delta refresh requests share capture, download and the single activation',async()=>{
  const f=fixture();let release;f.setDownload(async()=>{await new Promise(resolve=>release=resolve);return f.wire;});
  const coordinator=f.create(),first=coordinator.run(),second=coordinator.run();assert.equal(first,second);await settle();release();await first;
  assert.equal(f.calls.filter(value=>value==='capture').length,1);assert.equal(f.downloads.length,1);assert.equal(f.calls.filter(value=>value==='activate').length,1);
});

test('a changed active enrollment while data downloads cannot be replaced by an earlier delta',async()=>{
  const f=fixture();f.setDownload(async()=>{f.records.set('prepared',{...f.previous,token:'8'.repeat(64)});return f.wire;});
  await assert.rejects(f.create().run());assert.equal(f.calls.includes('activate'),false);assert.equal(f.records.get('prepared').token,'8'.repeat(64));
});

test('failed pointer persistence restores the old write fence through the existing generation recovery',async()=>{
  const f=fixture(),write=f.metadata.write;
  f.metadata.write=async(key,value)=>{if(key==='prepared')throw Error('disk unavailable');return write(key,value);};
  await assert.rejects(f.create().run());assert.equal(f.held(),null);assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('a lost reply after the pointer commits never reopens the archived previous database',async()=>{
  const f=fixture();f.runtime.activate=async(next,commit)=>{f.calls.push('activate');await commit();throw Error('reply lost after pointer');};
  await assert.rejects(f.create().run());const active=f.records.get('prepared');assert.notEqual(active.database,f.previous.database);
  assert.equal(f.calls.includes('refresh-cancel'),false);assert.equal(f.records.has('refresh'),false);assert.equal(f.held(),active.refreshId);
});

test('a controlled failed document retirement reports a fixed restart message after durable recovery and prevents another refresh',async()=>{
  const f=fixture(),refresh=f.view.refresh;let recoveries=0;
  f.view.refresh=async work=>{await refresh(work);throw Object.assign(Error('PRIVATE account and URL details'),{code:RESTART_CODE});};
  const coordinator=f.create(),recover=coordinator.recover;
  coordinator.recover=async()=>{recoveries++;return recover();};
  await assert.rejects(coordinator.run(),error=>error.code===RESTART_CODE&&error.message===RESTART_ERROR);
  assert.equal(recoveries,2);assert.equal(coordinator.state.restartRequired,true);assert.equal(coordinator.state.error,RESTART_ERROR);
  assert.notEqual(f.records.get('prepared').database,f.previous.database);assert.equal(f.records.has('refresh'),false);
  const captures=f.calls.filter(value=>value==='capture').length;
  await assert.rejects(coordinator.run(),error=>error.code===RESTART_CODE&&error.message===RESTART_ERROR);
  assert.equal(f.calls.filter(value=>value==='capture').length,captures);assert.doesNotMatch(JSON.stringify(coordinator.state),/PRIVATE|https:/);
});

test('a restart-required view failure still preserves a recovery capability when fence recovery is unavailable',async()=>{
  const f=fixture(),control=f.runtime.control;
  f.runtime.control=async input=>{const answer=await control(input);
    if(input.action==='refresh-begin')throw Object.assign(Error('PRIVATE failure'),{code:RESTART_CODE});
    if(input.action==='refresh-status')throw Error('PRIVATE recovery failure');return answer;};
  const coordinator=f.create();await assert.rejects(coordinator.run(),error=>error.code===RESTART_CODE&&error.message===RESTART_ERROR);
  assert.equal(coordinator.state.restartRequired,true);assert.equal(coordinator.state.error,RESTART_ERROR);
  assert.ok(f.records.get('refresh'));assert.equal(f.held(),f.records.get('refresh').id);
  assert.deepEqual(f.records.get('prepared'),f.previous);assert.equal(f.calls.includes('activate'),false);
});

test('unrecognized renderer error codes remain opaque and never claim a restart is required',async()=>{
  const f=fixture();f.view.refresh=async()=>{throw Object.assign(Error('PRIVATE customer details'),{code:'PRIVATE_UNREVIEWED_CODE'});};
  const coordinator=f.create();await assert.rejects(coordinator.run(),error=>error.message===SAFE_ERROR&&!error.code);
  assert.equal(coordinator.state.restartRequired,false);assert.equal(coordinator.state.error,SAFE_ERROR);
});

test('a lost fence reply with unavailable recovery retains the encrypted capability and never guesses the outcome',async()=>{
  const f=fixture(),control=f.runtime.control;
  f.runtime.control=async input=>{
    const answer=await control(input);
    if(input.action==='refresh-begin')throw Error('reply lost');
    if(input.action==='refresh-status')throw Error('service unavailable');return answer;
  };
  await assert.rejects(f.create().run());const intent=f.records.get('refresh');assert.ok(intent);
  assert.equal(f.held(),intent.id);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('a refresh cannot adopt a differently bound capture or an unrelated final fence',async()=>{
  for(const alter of [
    f=>{const capture=f.runtime.captureDelta;f.runtime.captureDelta=async(...args)=>({...await capture(...args),source_database:'fasakhansta_dashboard_stage_'+'9'.repeat(16)});},
    f=>{const capture=f.runtime.captureDelta;f.runtime.captureDelta=async(...args)=>{const value=await capture(...args);value.identity.actor_id=11;return value;};}
  ]){
    const f=fixture();alter(f);await assert.rejects(f.create().run());assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
  const f=fixture(),control=f.runtime.control;f.runtime.control=async input=>{const value=await control(input);return input.action==='refresh-begin'?{...value,actor_id:11}:value;};
  assert.equal((await f.create().run()).deferred,true);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

function unchangedFixture(){
  const f=fixture();f.context.manifest.sourceRevision=f.previous.sourceRevision;
  f.context.manifest.sourceFingerprint=structuredClone(f.previous.sourceFingerprint);f.context.sourceFingerprint=f.context.manifest.sourceFingerprint;
  f.wire.source=structuredClone(f.previous.sourceFingerprint);f.wire.schema_hash=f.previous.schemaHash;
  f.wire.delta_summary={changed_tables:0,added_rows:0,removed_rows:0};f.wire.tables.users.delta.add=[];f.wire.tables.users.delta.remove={};
  f.wire.tables.users.delta.count=0;f.wire.tables.users.delta.target_hash='f278068a614ae249b4095ac5f245fcce012ae39d67b8b1e4915e2768db840fca';
  return f;
}

test('an unchanged data, media, scope and executing source response skips the final database and media generation',async()=>{
  const f=unchangedFixture(),result=await f.create().run();
  assert.equal(result.unchanged,true);assert.equal(f.calls.includes('stage'),false);assert.equal(f.calls.includes('view'),false);
  assert.equal(f.calls.includes('refresh-begin'),false);assert.equal(f.calls.includes('activate'),false);assert.equal(f.releases.length,1);
  assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('zero row additions alone cannot hide changed DDL, media, enrollment, coverage or effective branches',async()=>{
  for(const change of [
    f=>f.wire.schema_hash='9'.repeat(64),
    f=>f.wire.media=[{path:'logo.png',sha256:'8'.repeat(64),bytes:10,mime:'image/png',ticket:'one-use-media-ticket'}],
    f=>f.wire.enrollment={...f.wire.enrollment,enrollment_hash:'7'.repeat(64)},
    f=>f.wire.coverage={...f.wire.coverage,print_protocol:1},
    f=>f.wire.branches=['f:100','f:200']
  ]){
    const f=unchangedFixture();change(f);const result=await f.create().run();assert.notEqual(result.unchanged,true);
    assert.equal(f.calls.includes('stage'),true);
  }
});

test('summary and media ticket rotation cannot bypass real row changes or force unnecessary activation',async()=>{
  const changed=unchangedFixture();changed.wire.tables.users.delta.add=[{id:10}];
  await changed.create().run();assert.equal(changed.calls.includes('stage'),true);
  const f=unchangedFixture(),media={path:'logo.png',sha256:'8'.repeat(64),bytes:10,mime:'image/png'};
  f.baseline.media=[media];f.wire.media=[{...media,ticket:'fresh-ticket-not-a-media-change'}];
  const result=await f.create().run();assert.equal(result.unchanged,true);assert.equal(f.calls.includes('stage'),false);
});

test('the unchanged-table proof matches actual PHP codec vectors including sorted duplicate multiplicity',()=>{
  // Produced by DesktopDashboardDeltaCodec::tableHash() under actual PHP 8.3.
  assert.equal(indexTableHash({schema_sha256:'5'.repeat(64),count:0,hashes:{}}),'f278068a614ae249b4095ac5f245fcce012ae39d67b8b1e4915e2768db840fca');
  assert.equal(indexTableHash({schema_sha256:'5'.repeat(64),count:3,hashes:{['b'.repeat(64)]:1,['a'.repeat(64)]:2}}),'edc2325ebb7e4f89dcef35057bc46c0202a85fecc84220167e81409b0e1e83c3');
  for(const index of [{count:2,hashes:{['a'.repeat(64)]:1}},{count:0,hashes:{['a'.repeat(64)]:0}},{count:1,hashes:{foreign:1}}])
    assert.throws(()=>indexTableHash({schema_sha256:'5'.repeat(64),...index}));
});

test('empty delta claims cannot skip validation for a missing table, wrong multiplicity or unrelated target hash',async()=>{
  for(const change of [f=>f.wire.tables={},f=>f.wire.tables.users.delta.count=1,f=>f.wire.tables.users.delta.target_hash='9'.repeat(64)]){
    const f=unchangedFixture();change(f);const result=await f.create().run();assert.notEqual(result.unchanged,true);assert.equal(f.calls.includes('stage'),true);
  }
});

test('reconstruction sends the private CLI the exact original delta bytes without rounding added numeric values',async()=>{
  const raw='{"format":1,"kind":"dashboard-data-delta","tables":{"wallets":{"delta":{"add":[{"id":9007199254740993,"amount":1.0,"tiny":1e-6,"value":1.2345678901234567}]}}}}';
  const delta=await readSnapshot(new Response(raw,{headers:{'content-type':'application/json'}}));
  const capture={capture_database:'fasakhansta_dashboard_stage_'+'1'.repeat(16),index:{tables:{}}},token='2'.repeat(64);
  const input=LocalRuntime.deltaInput(capture,delta,token);
  assert.equal(input.slice(input.indexOf(',"delta":')+9,-1),raw);
  assert.ok(input.includes('9007199254740993'));assert.ok(input.includes('"amount":1.0'));assert.ok(input.includes('"tiny":1e-6'));
  assert.equal(JSON.parse(input).capture_token,token);assert.equal(JSON.parse(input).capture_database,capture.capture_database);
});

test('a missing or differently bound signed archive handoff cannot enter the final view or database fence',async()=>{
  for(const change of [candidate=>{delete candidate.archiveHandoff;},candidate=>candidate.archiveHandoff.payload.sequence++,
    candidate=>candidate.archiveHandoff.payload.source_database='fasakhansta_dashboard_stage_'+'9'.repeat(16),
    candidate=>candidate.receipt.delta_replay.sha256='0'.repeat(64)]){
    const f=fixture();f.setStage(candidate=>{change(candidate);return candidate;});await assert.rejects(f.create().run());
    assert.equal(f.calls.includes('view-held'),false);assert.equal(f.calls.includes('refresh-begin'),false);assert.equal(f.calls.includes('activate'),false);
  }
});

test('background diagnostics do not expose raw account or transport errors',async()=>{
  const f=fixture(),privateValue='PRIVATE_ACCOUNT_TOKEN_AND_ORDER_DATA';let caught;
  f.setDownload(async()=>{throw Error(privateValue);});try{await f.create().run();}catch(error){caught=error;}
  assert.ok(caught);assert.equal(caught.message.includes(privateValue),false);assert.equal(caught.message.includes(f.previous.token),false);
});

test('an unpublished executing runtime fails before creating a database or taking a view fence',async()=>{
  const f=fixture();f.runtime.deltaContext=async()=>{f.calls.push('context');throw Error('publication unavailable');};
  await assert.rejects(f.create().run());assert.equal(f.calls.includes('capture'),false);assert.equal(f.calls.includes('download'),false);
  assert.equal(f.calls.includes('view'),false);assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('publication revocation after staging preserves the active database before the final cutover',async()=>{
  const f=fixture();f.runtime.authorizeDeltaContext=async()=>{f.calls.push('authorize-context');throw Error('publication revoked');};
  await assert.rejects(f.create().run());assert.ok(f.calls.includes('stage'));assert.equal(f.calls.includes('view'),false);
  assert.equal(f.calls.includes('refresh-begin'),false);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('selected central work stays outside automatic local delta capture and cannot be silently switched',async()=>{
  const f=fixture();f.setLocal(false);const result=await f.create().run();
  assert.equal(result.deferred,true);assert.equal(f.calls.includes('context'),false);assert.equal(f.calls.includes('capture'),false);
  assert.equal(f.calls.includes('view'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('closing during context verification cannot start a new capture after cancellation',async()=>{
  const f=fixture();let ready;
  f.runtime.deltaContext=async()=>{f.calls.push('context');await new Promise(resolve=>{ready=resolve;});return f.context;};
  const coordinator=f.create(),work=coordinator.run();await settle();coordinator.stop();ready();
  await assert.rejects(work);assert.equal(f.calls.includes('capture'),false);assert.equal(f.calls.includes('download'),false);
  assert.equal(f.calls.includes('view'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('closing during capture retains its durable background ownership without launching cleanup or network work',async()=>{
  const f=fixture(),capture=f.runtime.captureDelta;let ready;
  f.runtime.captureDelta=async(...args)=>{const value=await capture(...args);f.records.set('delta-background',{capture_database:value.capture_database});
    await new Promise(resolve=>{ready=resolve;});return value;};
  const coordinator=f.create(),work=coordinator.run();await settle();coordinator.stop();ready();
  await assert.rejects(work);assert.equal(f.calls.includes('download'),false);assert.equal(f.calls.includes('release-capture'),false);
  assert.ok(f.records.get('delta-background'));assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('closing during staging retains clone cleanup for restart without taking an active database fence',async()=>{
  const f=fixture(),stage=f.runtime.stageDelta;let ready;
  f.runtime.stageDelta=async(...args)=>{const value=await stage(...args);f.records.set('delta-background',{candidate_database:value.database});
    await new Promise(resolve=>{ready=resolve;});return value;};
  f.runtime.releaseDeltaStage=async()=>{f.calls.push('release-stage');throw Error('Closing must not launch another cleanup worker.');};
  const coordinator=f.create(),work=coordinator.run();await settle();coordinator.stop();ready();
  await assert.rejects(work);assert.equal(f.calls.includes('release-stage'),false);assert.equal(f.calls.includes('release-capture'),false);
  assert.equal(f.calls.includes('refresh-begin'),false);assert.ok(f.records.get('delta-background'));assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('closing during accepted finalization still drains its active fence recovery before settling',async()=>{
  const f=fixture(),finalize=f.runtime.finalizeDelta;let ready;
  f.runtime.finalizeDelta=async(...args)=>{await finalize(...args);await new Promise(resolve=>{ready=resolve;});};
  const coordinator=f.create(),work=coordinator.run();await settle();assert.ok(f.held());assert.ok(f.records.get('refresh'));
  coordinator.stop();assert.ok(f.held());ready();const result=await work;
  assert.equal(result.deferred,true);assert.equal(f.held(),null);assert.equal(f.records.has('refresh'),false);
  assert.equal(f.calls.includes('refresh-cancel'),true);assert.equal(f.calls.includes('activate'),false);
  assert.equal(f.calls.includes('release-capture'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});
