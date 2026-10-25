'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises');
const nativeFs=require('node:fs'),path=require('node:path'),os=require('node:os'),vm=require('node:vm'),crypto=require('node:crypto');
const {createRequire}=require('node:module');
const file=path.join(__dirname,'../src/local-runtime.cjs');

// Exercise the real supervisor and durable recovery logic; the worker boundary
// stands in for a SQL clone that has been allocated but has not been activated.
function loadRuntime(worker){
  const context=vm.createContext({module:{exports:{}},require:createRequire(file),Buffer,process,URL,Error,JSON,AbortSignal,AbortController,
    fetch,setTimeout,clearTimeout,structuredClone,worker});
  vm.runInContext(nativeFs.readFileSync(file,'utf8')+'\nrun=worker;',context,{filename:file});
  return context.module.exports;
}

async function fixture(t,phase){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'go-delta-exit-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const bundle=path.join(root,'bundle'),application=path.join(bundle,'application');await fs.mkdir(application,{recursive:true});
  const records=new Map(),databases=new Set(),calls=[];
  const metadata={read:async key=>records.has(key)?structuredClone(records.get(key)):null,
    write:async(key,value)=>{records.set(key,structuredClone(value));}};
  const fingerprint={format:1,files:20,sha256:'a'.repeat(64),framework:'8.83.29'};
  const manifest={format:1,platform:'win32-x64',sourceRevision:'b'.repeat(40),sourceFingerprint:fingerprint,
    sourceHashes:{'desktop/delta.php':'c'.repeat(64)}};
  const previous={deviceId:crypto.randomUUID(),actorId:10,database:'fasakhansta_dashboard_stage_'+'d'.repeat(16),
    snapshotId:crypto.randomUUID(),schemaHash:'e'.repeat(64),sourceRevision:manifest.sourceRevision,sourceFingerprint:fingerprint};
  records.set('prepared',previous);let started,discardStarted,holdDiscards=false;
  const waiting=new Promise(resolve=>{started=resolve;});
  const discardWaiting=new Promise(resolve=>{discardStarted=resolve;});
  const worker=async(_php,args,options,input)=>{
    const value=JSON.parse(input);const action=args.at(-1)==='stage-status'?'stage-status':value.action;
    calls.push(action);
    if(action==='stage-status')return JSON.stringify({format:1,database:options.env.DB_DATABASE,exists:databases.has(options.env.DB_DATABASE)});
    if(action==='discard-stage'||action==='discard-capture'){
      const database=value.stage_database||value.capture_database;
      assert.notEqual(database,records.get('prepared').database,'Restart cleanup must never discard the active business database.');
      if(holdDiscards){discardStarted();return new Promise((_resolve,reject)=>{
        options.signal.addEventListener('abort',()=>reject(Error('cancelled cleanup')),{once:true});
      });}
      assert.equal(databases.delete(database),true);return JSON.stringify({discarded:true,database});
    }
    if(action==='capture'&&phase==='stage')return JSON.stringify({capture_database:options.env.DB_DATABASE,source_database:previous.database});
    assert.equal(action,phase==='capture'?'capture':'reconstruct');
    started();return new Promise((_resolve,reject)=>{
      if(options.signal.aborted)reject(Error('cancelled'));
      else options.signal.addEventListener('abort',()=>reject(Error('cancelled')),{once:true});
    });
  };
  const Runtime=loadRuntime(worker);
  const make=()=>{
    const runtime=new Runtime({bundle,profile:root,safeStorage:{}});runtime.metadata=metadata;
    runtime.manifest=manifest;runtime.sourceFingerprint=fingerprint;runtime.bundle=bundle;
    runtime.php=path.join(bundle,'php/php.exe');runtime.application=application;runtime.phpIni=path.join(root,'php.ini');runtime.environment={};
    runtime.settings=async()=>({deviceId:previous.deviceId,rootPassword:'synthetic-root-password',appPassword:'synthetic-app-password'});
    runtime.connection=async()=>metadata.read('prepared');return runtime;
  };
  const runtime=make();await fs.mkdir(runtime.profile,{recursive:true});
  const context=runtime.context();
  runtime.createDeltaDatabase=async(selected,id)=>{
    assert.equal(selected,context);runtime.deltaController??=new AbortController();
    const value={generation:id,database:'fasakhansta_dashboard_stage_'+id},folder=path.join(runtime.profile,'generations',id);
    await fs.mkdir(folder,{recursive:true});await fs.writeFile(path.join(folder,'inactive-clone'),'retained clone data');databases.add(value.database);
    const env={DB_DATABASE:value.database};return {value,root:folder,env,options:{env,cwd:application,signal:runtime.deltaController.signal},
      args:Runtime.phpArguments(context),context};
  };
  return {runtime,make,context,previous,records,databases,calls,waiting,discardWaiting,holdDiscards:value=>{holdDiscards=value;}};
}

for(const phase of ['capture','stage'])test('cancelled '+phase+' retains clone ownership for a fresh supervisor instead of launching long exit cleanup',async t=>{
  const f=await fixture(t,phase),token='1'.repeat(64),captureId='2'.repeat(16),stageId='3'.repeat(16);
  let work;
  if(phase==='capture')work=f.runtime.captureDelta(f.context,f.previous,captureId,token);
  else{
    const capture=await f.runtime.captureDelta(f.context,f.previous,captureId,token);
    work=f.runtime.stageDelta(f.context,capture,{media:[]},stageId,token);
  }
  const rejected=assert.rejects(work,/cancelled/);await f.waiting;f.runtime.cancelDelta();await rejected;
  assert.equal(f.calls.some(action=>action.startsWith('discard-')),false,'Exit must not start a fresh unsignalled cleanup worker.');
  const record=structuredClone(f.records.get('delta-background'));
  assert.equal(record.token,token);assert.equal(f.databases.has(record.capture_database),true);
  if(phase==='stage')assert.equal(f.databases.has(record.candidate_database),true);
  assert.deepEqual(f.records.get('prepared'),f.previous);
  const restarted=f.make();await restarted.recoverDeltaBackground();
  assert.equal(f.records.get('delta-background'),null);assert.equal(f.databases.size,0);
  assert.deepEqual(f.records.get('prepared'),f.previous);
  await assert.rejects(fs.access(path.join(restarted.profile,'generations',captureId)));
  if(phase==='stage')await assert.rejects(fs.access(path.join(restarted.profile,'generations',stageId)));
  assert.deepEqual(f.calls.filter(action=>action.startsWith('discard-')),phase==='stage'?['discard-stage','discard-capture']:['discard-capture']);
});

test('cancelling before any delta controller exists cannot admit a late clone allocation or cleanup',async()=>{
  const Runtime=loadRuntime(async()=>{throw Error('No worker may start after cancellation.');});
  const runtime=new Runtime({bundle:'/unused-bundle',profile:'/unused-profile',safeStorage:{}});runtime.environment={};runtime.cancelDelta();
  await assert.rejects(runtime.createDeltaDatabase({},'a'.repeat(16)),/تعذر بدء تحديث/);
  assert.equal(await runtime.releaseDeltaCapture({},'b'.repeat(64)),false);
  assert.equal(await runtime.releaseDeltaStage({},'b'.repeat(64)),false);
});

test('a cleanup worker already in progress is abortable and retains its durable capability for the next supervisor',async t=>{
  const f=await fixture(t,'capture'),work=f.runtime.captureDelta(f.context,f.previous,'2'.repeat(16),'1'.repeat(64));
  const rejected=assert.rejects(work,/cancelled/);await f.waiting;f.runtime.cancelDelta();await rejected;
  const record=structuredClone(f.records.get('delta-background'));f.holdDiscards(true);
  const restarted=f.make(),recovery=restarted.recoverDeltaBackground(),cancelled=assert.rejects(recovery,/cancelled cleanup/);
  await f.discardWaiting;restarted.cancelDelta();await cancelled;
  assert.deepEqual(f.records.get('delta-background'),record);assert.equal(f.databases.has(record.capture_database),true);
  assert.deepEqual(f.records.get('prepared'),f.previous);
  f.holdDiscards(false);await f.make().recoverDeltaBackground();
  assert.equal(f.records.get('delta-background'),null);assert.equal(f.databases.size,0);
});

test('delta cancellation does not abort an already accepted active database finalization',async()=>{
  let finalized=false;
  const Runtime=loadRuntime(async(_php,args,options,input)=>{
    assert.equal(path.basename(args.at(-1)),'archive.php');assert.equal(options.signal,undefined);
    assert.equal(JSON.parse(input).refresh_id,'accepted-refresh');finalized=true;return '{}';
  });
  const runtime=new Runtime({bundle:'/retained-bundle',profile:'/retained-profile',safeStorage:{}});
  const candidate={generation:'1'.repeat(16),mediaSeal:{format:1},archiveHandoff:{format:1},receipt:{verified:true}};
  const context={application:'/retained-bundle/application',php:'/retained-bundle/php/php.exe'};
  runtime.deltaStages=new Map([[candidate.generation,{candidate,context,args:[],options:{env:{},cwd:context.application},token:'2'.repeat(64)}]]);
  runtime.cancelDelta();
  await runtime.finalizeDelta(candidate,{id:'accepted-refresh',token:'3'.repeat(64),previous:{database:'active-business-database',snapshotId:'snapshot',schemaHash:'4'.repeat(64)}});
  assert.equal(finalized,true);
});
