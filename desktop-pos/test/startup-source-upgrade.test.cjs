'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {upgradeStartupSource}=require('../src/startup-source-upgrade.cjs');
const {DashboardGeneration}=require('../src/dashboard-generation.cjs');
const {RESTART_CODE}=require('../src/dashboard-delta.cjs');
const coverage=require('../src/dashboard-coverage.cjs');
const source=sha=>({format:1,files:20,sha256:sha.repeat(64),framework:'8.83.29'});
const settle=async()=>{for(let n=0;n<15;n++)await Promise.resolve();await new Promise(setImmediate);};
const gate=()=>{let resolve;const promise=new Promise(done=>resolve=done);return {promise,resolve};};

function fixture(){
  const calls=[],records=new Map(),attempts=new Map(),hooks={};
  const previous={format:2,deviceId:crypto.randomUUID(),actorId:10,serverOrigin:'https://example.test',token:'a'.repeat(64),
    schemaHash:'b'.repeat(64),snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),generation:'a'.repeat(16),
    database:'fasakhansta_dashboard_stage_'+'a'.repeat(16),sourceRevision:'c'.repeat(40),sourceFingerprint:source('d'),
    branches:['f:100'],fullCoverage:true,mediaVerified:true};
  records.set('prepared',structuredClone(previous));
  const manifest={format:1,platform:'win32-x64',sourceRevision:'e'.repeat(40),sourceFingerprint:source('f')};
  const context={manifest,sourceFingerprint:source('f')};
  const wire={format:1,kind:'dashboard-data-delta',device_id:previous.deviceId,actor_id:previous.actorId,
    snapshot_id:crypto.randomUUID(),schema_hash:'1'.repeat(64),source:source('f'),branches:['f:100'],
    coverage:{full_dashboard:true,media:true},media:[],media_issues:[],enrollment:{enrollment_hash:'2'.repeat(64),enrolled_at:'2026-10-23T00:00:00Z'},
    created_scopes:[],delta_summary:{changed_tables:1,added_rows:1,removed_rows:0},
    tables:{users:{ddl:'CREATE TABLE `users` (`id` bigint NOT NULL)',delta:{remove:{},add:[{id:10}],target_hash:'3'.repeat(64),count:1}}}};
  const counts={pending:0,conflicts:0,printEffects:0,legacyPending:0,legacyConflicts:0,openOrders:0};
  const state={online:true,viewAbsent:true,dirty:false,authorized:true};
  const data={users:[{id:10,role:'branch',password:'hashed credential'}],sessions:['original-cookie-session'],journal:[{id:'settled-original-command'}]};
  const preserved=structuredClone(data),readController=new AbortController();let capture,candidate,held;
  const metadata={read:async name=>structuredClone(records.get(name)||null),
    write:async(name,value)=>{calls.push('write:'+name);records.set(name,structuredClone(value));},
    clearIntent:async()=>{calls.push('clear-intent');records.delete('refresh');}};
  const originalAuthority=async()=>{throw Error('startup authority callback was not scoped');};
  const runtime={authorizeInstalled:originalAuthority,connection:()=>metadata.read('prepared'),
    async authorizeDeltaContext(selected){calls.push('authorize-context');assert.equal(selected,context);
      if(await this.authorizeInstalled(selected.manifest)!==true)throw Error('not authorized');return true;},
    async deltaContext(active){calls.push('context');assert.deepEqual(active,previous);
      await this.authorizeDeltaContext(context);if(hooks.context)await hooks.context();await this.authorizeDeltaContext(context);return context;},
    async captureDelta(selected,active,id){
      calls.push('capture');if(hooks.capture)await hooks.capture(readController.signal);
      if(readController.signal.aborted)throw Error('read stopped');
      capture={format:1,kind:'dashboard-delta-capture',capture_database:'fasakhansta_dashboard_stage_'+id,
        source_database:active.database,capture_id:'4'.repeat(64),sequence:7,
        replay:{format:1,source_database:active.database,sequence:7,rows:1,sha256:'7'.repeat(64)},
        identity:{device_id:active.deviceId,actor_id:active.actorId,snapshot_id:active.snapshotId,schema_hash:active.schemaHash,
          branches:active.branches,coverage:{full_dashboard:true,media:true},source:active.sourceFingerprint,media:[],enrollment:wire.enrollment,created_scopes:[]},
        index:{format:1,kind:'dashboard-delta-index',device_id:active.deviceId,actor_id:active.actorId,source:manifest.sourceFingerprint,
          tables:{users:{schema_sha256:'5'.repeat(64),count:0,hashes:{}}}}};return capture;
    },
    async stageDelta(selected,captured,delta,id){
      calls.push('stage');if(hooks.stage)await hooks.stage(readController.signal);
      if(readController.signal.aborted)throw Error('stage stopped');
      const receipt={format:1,verified:true,device_id:delta.device_id,actor_id:delta.actor_id,snapshot_id:delta.snapshot_id,
        schema_hash:delta.schema_hash,branches:delta.branches,coverage:delta.coverage,media:[],source:delta.source,tables:1,rows:1,
        table_rows:{users:1},delta_replay:structuredClone(capture.replay)};
      candidate={generation:id,database:'fasakhansta_dashboard_stage_'+id,receipt,sourceRevision:manifest.sourceRevision,
        sourceFingerprint:manifest.sourceFingerprint,mediaVerified:true,mediaSeal:{format:1,manifest_sha256:'6'.repeat(64),files:[]},
        archiveHandoff:{format:1,kind:'desktop-preverified-handoff',signature:'8'.repeat(64),payload:{database:'fasakhansta_dashboard_stage_'+id,
          source_database:previous.database,source_identity:{snapshot_id:previous.snapshotId,schema_hash:previous.schemaHash},sequence:7,replay:structuredClone(capture.replay)}}};
      return hooks.candidate?hooks.candidate(candidate):candidate;
    },
    async finalizeDelta(selected){calls.push('finalize');assert.equal(selected,candidate);assert.ok(held);if(hooks.finalize)await hooks.finalize();},
    async activate(next,commit){calls.push('activate');if(hooks.activate)await hooks.activate(next,commit);else await commit();},
    async releaseDeltaCapture(){calls.push('release-capture');},async releaseDeltaStage(){calls.push('release-stage');},
    cancelDelta(){calls.push('cancel-reads');readController.abort();},
    async control(input){
      calls.push(input.action);const attempt=attempts.get(input.refresh_id);
      if(input.action==='refresh-begin'){
        const fence={held:true,device_id:previous.deviceId,actor_id:previous.actorId,snapshot_id:previous.snapshotId,
          schema_hash:previous.schemaHash,refresh_id:input.refresh_id,fenced_sequence:7};
        held=input.refresh_id;attempts.set(held,{token:input.token,fence});return fence;
      }
      if(input.action==='refresh-status'){if(hooks.recovery)await hooks.recovery();return attempt?{exists:true,...attempt.fence}:{exists:false,held:false};}
      if(input.action==='refresh-cancel'){assert.equal(attempt.token,input.token);attempt.fence.held=false;held=null;return attempt.fence;}
      throw Error('unexpected local control');
    }};
  const generations=new DashboardGeneration({runtime,metadata,download:async()=>{throw Error('full export is forbidden');}});
  const options={runtime,metadata,installedManifest:manifest,online:()=>state.online,viewAbsent:()=>state.viewAbsent,
    inspectIdle:async()=>{calls.push('inspect-idle');return {...counts};},remoteState:{assertClean:async()=>{calls.push('remote-clean');if(state.dirty)throw Error('remote state uncertain');}},
    authorizeInstalled:async(selected,{signal})=>{calls.push('authority');assert.equal(selected,manifest);assert.ok(signal instanceof AbortSignal);
      return hooks.authority?hooks.authority(signal):state.authorized;},
    download:async(index,active,{signal})=>{calls.push('download');assert.equal(index,capture.index);assert.deepEqual(active,previous);
      assert.equal(Object.hasOwn(index,'capture_token'),false);return hooks.download?hooks.download(signal):wire;},
    recover:()=>generations.recoverIntent()};
  return {options,run:extra=>upgradeStartupSource({...options,...extra}),calls,records,hooks,previous,manifest,wire,counts,state,data,preserved,
    runtime,metadata,originalAuthority,held:()=>held,readController};
}

test('first upgraded-source launch uses the original delta/fence recovery and keeps account data, sessions and journals',async()=>{
  const f=fixture(),result=await f.run();
  assert.equal(result.status,'activated');assert.equal(result.connection.sourceRevision,f.manifest.sourceRevision);
  assert.deepEqual(f.data,f.preserved);assert.equal(f.records.has('refresh'),false);
  assert.equal(f.calls.filter(x=>x==='authority').length,3);assert.equal(f.calls.filter(x=>x==='download').length,1);
  assert.ok(f.calls.indexOf('stage')<f.calls.indexOf('write:refresh'));assert.ok(f.calls.indexOf('finalize')<f.calls.indexOf('write:prepared'));
  assert.equal(f.runtime.authorizeInstalled,f.originalAuthority);
});

test('the same source on later launches does no HTTP, capture, journal scan or authority callback replacement',async()=>{
  const f=fixture();await f.run();f.calls.length=0;const result=await f.run();
  assert.equal(result.status,'unchanged');assert.deepEqual(f.calls,[]);assert.equal(f.runtime.authorizeInstalled,f.originalAuthority);
});

test('offline, an existing business view and unknown source cannot request or activate an upgrade',async()=>{
  for(const change of [f=>f.state.online=false,f=>f.state.viewAbsent=false,f=>f.manifest.sourceFingerprint=null]){
    const f=fixture();change(f);assert.equal((await f.run()).status,'deferred');
    assert.equal(f.calls.includes('authority'),false);assert.equal(f.calls.includes('capture'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('all six pending/conflict/print/legacy/open-order gates and unknown counts defer before any HTTP',async()=>{
  for(const key of Object.keys(fixture().counts)){
    const f=fixture();f.counts[key]=1;assert.equal((await f.run()).status,'deferred');
    assert.equal(f.calls.includes('authority'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
  for(const invalid of [undefined,-1,'0',NaN]){
    const f=fixture();f.counts.printEffects=invalid;assert.equal((await f.run()).status,'deferred');assert.equal(f.calls.includes('authority'),false);
  }
});

test('remote dirty or uncertain state cannot create captures or claim a fresh local snapshot',async()=>{
  const f=fixture();f.state.dirty=true;assert.equal((await f.run()).reason,'state-unverified');
  assert.equal(f.calls.includes('authority'),false);assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('revoked authority and a changed installed manifest fail closed without acquiring the active fence',async()=>{
  for(const change of [f=>f.state.authorized=false,f=>f.hooks.context=async()=>{f.state.authorized=false;}]){
    const f=fixture();change(f);assert.equal((await f.run()).status,'deferred');
    assert.equal(f.calls.includes('capture'),false);assert.equal(f.calls.includes('refresh-begin'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
  const f=fixture();f.options.installedManifest={...f.manifest,sourceRevision:'9'.repeat(40)};
  assert.equal((await f.run()).status,'deferred');assert.equal(f.calls.includes('authority'),false);
});

test('foreign actor/source and malformed staged proof never acquire an active database fence',async()=>{
  for(const change of [f=>f.wire.actor_id++,f=>f.wire.source=source('9'),f=>f.hooks.candidate=value=>({...value,mediaVerified:false})]){
    const f=fixture();change(f);assert.equal((await f.run()).status,'deferred');
    assert.equal(f.calls.includes('refresh-begin'),false);assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('partial source upgrade keeps the original exact preview scope and requires an explicit boolean policy',async()=>{
  const scoped=()=>{
    const f=fixture();Object.assign(f.manifest,{channel:'preview',fullDashboard:false,previewWriteRoutes:['customers.save','takeaway.checkout']});
    f.wire.coverage={full_dashboard:false,media:true,write_routes:['customers.save','takeaway.checkout'],role_memberships:[]};return f;
  };
  for(const extra of [{},{allowPreview:false}]){
    const f=scoped();assert.equal((await f.run(extra)).status,'deferred');assert.equal(f.calls.includes('stage'),false);
    assert.deepEqual(f.records.get('prepared'),f.previous);
  }
  const f=scoped(),result=await f.run({allowPreview:true});assert.equal(result.status,'activated');
  assert.equal(result.connection.fullCoverage,false);assert.equal(result.connection.previewOnly,true);
  assert.equal(coverage.previewPrepared(result.connection,f.manifest),true);assert.deepEqual(result.connection.coverage,f.wire.coverage);
  for(const mutate of [f=>f.wire.coverage.write_routes.pop(),f=>f.manifest.previewWriteRoutes.push('unreviewed.write'),f=>f.manifest.channel='stable']){
    const g=scoped();mutate(g);assert.equal((await g.run({allowPreview:true})).status,'deferred');assert.equal(g.calls.includes('activate'),false);
  }
  await assert.rejects(scoped().run({allowPreview:'true'}),/configuration/);
});

test('a newly opened view, pending print or remote uncertainty during staging defers the final handoff',async()=>{
  for(const change of [f=>f.state.viewAbsent=false,f=>f.counts.printEffects=1,f=>f.state.dirty=true]){
    const f=fixture();f.hooks.stage=async()=>change(f);assert.equal((await f.run()).status,'deferred');
    assert.equal(f.calls.includes('stage'),true);assert.equal(f.calls.includes('refresh-begin'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
    assert.equal(f.calls.includes('release-stage'),true);assert.equal(f.calls.includes('release-capture'),true);
  }
});

test('an optional authorization deadline drains aborted HTTP and leaves normal delta reads usable',async()=>{
  const f=fixture();let finished=false;
  f.hooks.authority=signal=>new Promise(resolve=>signal.addEventListener('abort',()=>{finished=true;resolve(false);},{once:true}));
  const result=await f.run({networkBudgetMs:15});assert.equal(result.reason,'network-budget');assert.equal(finished,true);
  assert.equal(f.calls.includes('cancel-reads'),false);assert.equal(f.readController.signal.aborted,false);assert.equal(f.runtime.authorizeInstalled,f.originalAuthority);
  f.hooks.authority=null;assert.equal((await f.run()).status,'activated');
});

test('an optional delta-download deadline drains its body and releases the inactive capture without a late pointer',async()=>{
  const f=fixture();let drained=false;
  f.hooks.download=signal=>new Promise(resolve=>signal.addEventListener('abort',()=>{drained=true;resolve(f.wire);},{once:true}));
  const result=await f.run({networkBudgetMs:15});assert.equal(result.reason,'network-budget');assert.equal(drained,true);
  assert.equal(f.calls.includes('release-capture'),true);assert.equal(f.calls.includes('stage'),false);assert.equal(f.readController.signal.aborted,false);
  await settle();assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('local staging does not consume the optional external-network budget',async()=>{
  const f=fixture();f.hooks.stage=()=>new Promise(resolve=>setTimeout(resolve,150));
  assert.equal((await f.run({networkBudgetMs:100})).status,'activated');
});

test('shutdown during HTTP drains the accepted request and cancels capture reads before returning',async()=>{
  const f=fixture(),controller=new AbortController();let aborted=false;
  f.hooks.download=signal=>new Promise(resolve=>signal.addEventListener('abort',()=>{aborted=true;resolve(f.wire);},{once:true}));
  const task=f.run({signal:controller.signal});await settle();assert.equal(f.calls.includes('download'),true);controller.abort();
  assert.equal((await task).reason,'stopped');assert.equal(aborted,true);assert.equal(f.readController.signal.aborted,true);
  await settle();assert.deepEqual(f.records.get('prepared'),f.previous);assert.equal(f.records.has('refresh'),false);
});

test('shutdown during capture or stage drains local reads and cannot fence or activate afterward',async()=>{
  for(const phase of ['capture','stage']){
    const f=fixture(),controller=new AbortController();f.hooks[phase]=signal=>new Promise(resolve=>signal.addEventListener('abort',resolve,{once:true}));
    const task=f.run({signal:controller.signal});await settle();assert.equal(f.calls.includes(phase),true);controller.abort();
    assert.equal((await task).reason,'stopped');assert.equal(f.calls.includes('refresh-begin'),false);
    await settle();assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('shutdown during durable finalization waits, recovers the original fence and preserves the old pointer',async()=>{
  const f=fixture(),controller=new AbortController(),wait=gate();let settled=false;
  f.hooks.finalize=()=>wait.promise;const task=f.run({signal:controller.signal}).finally(()=>settled=true);
  await settle();assert.equal(f.calls.includes('finalize'),true);controller.abort();await settle();assert.equal(settled,false);
  wait.resolve();assert.equal((await task).reason,'stopped');assert.equal(f.held(),null);assert.equal(f.records.has('refresh'),false);
  assert.equal(f.calls.includes('activate'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('shutdown during accepted activation drains its commit and reports the actual committed source without later writes',async()=>{
  const f=fixture(),controller=new AbortController(),wait=gate();let settled=false;
  f.hooks.activate=async(next,commit)=>{await wait.promise;await commit();};
  const task=f.run({signal:controller.signal}).finally(()=>settled=true);await settle();assert.equal(f.calls.includes('activate'),true);
  controller.abort();await settle();assert.equal(settled,false);wait.resolve();const result=await task;
  assert.equal(result.status,'activated');assert.equal(result.connection.sourceRevision,f.manifest.sourceRevision);
  const pointer=structuredClone(f.records.get('prepared'));await settle();assert.deepEqual(f.records.get('prepared'),pointer);assert.equal(f.records.has('refresh'),false);
});

test('a failed pointer write recovers the old fence; a lost reply after commit requires restart instead of claiming the old source',async()=>{
  const f=fixture();f.hooks.activate=async()=>{throw Error('before pointer');};
  assert.equal((await f.run()).status,'deferred');assert.equal(f.held(),null);assert.equal(f.records.has('refresh'),false);assert.deepEqual(f.records.get('prepared'),f.previous);
  const g=fixture();g.hooks.activate=async(next,commit)=>{await commit();throw Error('lost committed reply');};
  await assert.rejects(g.run(),error=>error.code===RESTART_CODE);assert.equal(g.records.get('prepared').sourceRevision,g.manifest.sourceRevision);
  assert.equal(g.calls.includes('refresh-cancel'),false);assert.equal(g.records.has('refresh'),false);
});

test('unresolved durable recovery retains its capability and requires restart rather than opening any business view',async()=>{
  const f=fixture();f.hooks.activate=async()=>{throw Error('before pointer');};f.hooks.recovery=async()=>{throw Error('unavailable');};
  await assert.rejects(f.run(),error=>error.code===RESTART_CODE);assert.ok(f.records.get('refresh'));assert.ok(f.held());
  assert.deepEqual(f.records.get('prepared'),f.previous);assert.equal(f.runtime.authorizeInstalled,f.originalAuthority);
});

test('concurrent startup requests share one capture, network delta and activation',async()=>{
  const f=fixture(),wait=gate();f.hooks.download=async()=>{await wait.promise;return f.wire;};
  const first=f.run(),second=f.run();assert.equal(first,second);await settle();wait.resolve();await first;
  assert.equal(f.calls.filter(x=>x==='download').length,1);assert.equal(f.calls.filter(x=>x==='activate').length,1);
});
