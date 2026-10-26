'use strict';
const {isDeepStrictEqual}=require('node:util');
const {performance}=require('node:perf_hooks');
const {DashboardDelta,RESTART_CODE,RESTART_ERROR}=require('./dashboard-delta.cjs');
const source=require('./dashboard-source.cjs');

const leases=new WeakMap();
const idleKeys=['pending','conflicts','printEffects','legacyPending','legacyConflicts','openOrders'];
const restart=()=>Object.assign(Error(RESTART_ERROR),{code:RESTART_CODE});
const stopped=()=>Object.assign(Error('Startup source verification stopped.'),{code:'STARTUP_SOURCE_STOPPED'});
const timeout=()=>Object.assign(Error('Startup source verification deferred.'),{code:'STARTUP_SOURCE_NETWORK_BUDGET'});
const manifestValid=value=>/^[a-f0-9]{40}$/.test(value?.sourceRevision||'')&&source.valid(value?.sourceFingerprint);
const installedActive=(connection,manifest)=>connection?.sourceRevision===manifest.sourceRevision
  &&source.same(connection.sourceFingerprint,manifest.sourceFingerprint);
const idle=value=>value&&typeof value==='object'&&!Array.isArray(value)
  &&idleKeys.every(key=>Number.isSafeInteger(value[key])&&value[key]===0);

/** A cumulative network budget. Aborted work is drained, never raced against activation. */
class NetworkBudget{
  constructor(milliseconds,signal){this.remaining=milliseconds;this.signal=signal;this.exhausted=false;}
  async run(work){
    if(this.signal?.aborted)throw stopped();
    if(this.exhausted||this.remaining<=0){this.exhausted=true;throw timeout();}
    const controller=new AbortController(),started=performance.now();
    const signal=this.signal?AbortSignal.any([controller.signal,this.signal]):controller.signal;
    const timer=setTimeout(()=>{this.exhausted=true;controller.abort();},Math.max(1,Math.ceil(this.remaining)));
    try{
      // Both supplied callbacks must pass this signal through to the original
      // request and stream reader. Awaiting it also drains an accepted response.
      const value=await work(signal);
      if(this.signal?.aborted)throw stopped();
      if(performance.now()-started>=this.remaining){this.exhausted=true;controller.abort();}
      if(this.exhausted||controller.signal.aborted)throw timeout();
      return value;
    }finally{clearTimeout(timer);this.remaining-=performance.now()-started;}
  }
}

/**
 * Upgrade a retained prepared source before any business view exists. The caller
 * owns the startup/shutdown barrier and keeps login submission serialized until
 * this promise settles. Only DashboardDelta may stage, fence or activate data.
 *
 * inspectIdle returns six exact zero counts: pending, conflicts, printEffects,
 * legacyPending, legacyConflicts and openOrders. Unknown state always defers.
 * authorizeInstalled(manifest,{signal}) performs the original updater's fresh
 * publication/high-water checks; download(index,connection,{signal}) performs
 * the original delta request. Both callbacks must honor abort through the body.
 */
function upgradeStartupSource(options){
  if(!options?.runtime||typeof options.runtime!=='object')return Promise.reject(Error('Invalid startup source runtime.'));
  const existing=leases.get(options.runtime);if(existing)return existing;
  const task=upgrade(options).finally(()=>{if(leases.get(options.runtime)===task)leases.delete(options.runtime);});
  leases.set(options.runtime,task);return task;
}

async function upgrade({runtime,metadata,installedManifest,online,viewAbsent,inspectIdle,remoteState,
  authorizeInstalled,download,recover,signal,networkBudgetMs=5000,allowPreview=false,onState=()=>{}}){
  if(!metadata?.read||typeof online!=='function'||typeof viewAbsent!=='function'||typeof inspectIdle!=='function'
    ||typeof remoteState?.assertClean!=='function'||typeof authorizeInstalled!=='function'||typeof download!=='function'
    ||typeof recover!=='function'||typeof allowPreview!=='boolean'
    ||!Number.isSafeInteger(networkBudgetMs)||networkBudgetMs<1||networkBudgetMs>30000)
    throw Error('Invalid startup source verification configuration.');
  const report=value=>{try{onState(value);}catch{}};
  const deferred=(reason,connection)=>({status:'deferred',reason,connection});
  const previous=await runtime.connection();
  if(!manifestValid(installedManifest)||!source.valid(previous?.sourceFingerprint))return deferred('source-unverified',previous);
  // An already activated installer does not request authority, download rows or
  // scan local journals again on subsequent launches.
  if(source.same(previous.sourceFingerprint,installedManifest.sourceFingerprint))
    return {status:'unchanged',connection:previous};
  if(signal?.aborted)return deferred('stopped',previous);
  const available=()=>{try{return !signal?.aborted&&viewAbsent()===true&&online()===true;}catch{return false;}};
  if(!available())return deferred('not-available',previous);
  const clean=async()=>{
    if(!available())return false;
    await remoteState.assertClean();
    if(!idle(await inspectIdle()))return false;
    return available();
  };
  try{if(!await clean())return deferred('not-idle',previous);}
  catch{return deferred('state-unverified',previous);}

  const budget=new NetworkBudget(networkBudgetMs,signal),originalAuthority=runtime.authorizeInstalled;
  let delta;
  const cancel=()=>delta?.stop();
  const authority=async manifest=>{
    if(!available()||!manifestValid(manifest)||manifest.sourceRevision!==installedManifest.sourceRevision
      ||!source.same(manifest.sourceFingerprint,installedManifest.sourceFingerprint))return false;
    return await budget.run(requestSignal=>authorizeInstalled(manifest,{signal:requestSignal}))===true;
  };
  // This is the constructor's original authority callback boundary. The
  // runtime still verifies binaries/source and obtains/rechecks fresh authority.
  runtime.authorizeInstalled=authority;
  signal?.addEventListener('abort',cancel,{once:true});
  let failure,result;
  try{
    delta=new DashboardDelta({runtime,metadata,remoteState,recover,allowPreview,
      download:(index,connection)=>budget.run(requestSignal=>download(index,connection,{signal:requestSignal})),
      view:{current:()=>({local:available()}),refresh:async work=>{
        try{if(!await clean())return {deferred:true};}
        catch{return {deferred:true};}
        return work();
      }},onState:value=>report({phase:value.phase})});
    if(signal?.aborted)delta.stop();
    try{result=await delta.run();}catch(error){failure=error;}
    // DashboardDelta drains an accepted fence/finalize/activation and resolves
    // original generation recovery before its promise settles. Never return a
    // stale prepared pointer after a lost commit reply or unresolved capability.
    let connection,intent;
    try{connection=await runtime.connection();intent=await metadata.read('refresh');}
    catch{throw restart();}
    if(intent||failure?.code===RESTART_CODE)throw restart();
    if(failure){
      if(!isDeepStrictEqual(connection,previous))throw restart();
      return deferred(signal?.aborted?'stopped':budget.exhausted?'network-budget':'verification-failed',connection);
    }
    if(installedActive(connection,installedManifest))return {status:'activated',connection};
    if(!isDeepStrictEqual(connection,previous))throw restart();
    return deferred(signal?.aborted?'stopped':result?.deferred?'not-idle':'verification-failed',connection);
  }finally{
    signal?.removeEventListener('abort',cancel);
    if(runtime.authorizeInstalled===authority)runtime.authorizeInstalled=originalAuthority;
  }
}

module.exports={upgradeStartupSource};
