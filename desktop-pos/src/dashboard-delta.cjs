'use strict';
const crypto=require('node:crypto');
const {isDeepStrictEqual}=require('node:util');
const {prepared}=require('./dashboard-generation.cjs');
const sourceCode=require('./dashboard-source.cjs');
const coverage=require('./dashboard-coverage.cjs');
const media=require('./dashboard-media.cjs');
const uuid=value=>/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value||'');
const hash=value=>/^[a-f0-9]{64}$/.test(value||'');
const same=(left,right)=>isDeepStrictEqual(left,right);
const identity=value=>coverage.digest({device:value.deviceId,actor:value.actorId,database:value.database,snapshot:value.snapshotId,
  schema:value.schemaHash,sourceRevision:value.sourceRevision,source:value.sourceFingerprint,origin:value.serverOrigin,token:value.token,
  branches:value.branches,fullCoverage:value.fullCoverage,previewOnly:value.previewOnly||false,coverage:value.coverage||null});
const SAFE_ERROR='تعذر تحديث بيانات السيرفر في الخلفية؛ العمل المحلي والعمليات المحفوظة مستمران. سيُعاد الفحص في دورة المزامنة التالية.';
const RESTART_CODE='DASHBOARD_HANDOFF_RESTART_REQUIRED';
const RESTART_ERROR='تعذر إكمال تحديث الشاشة بأمان. أغلق البرنامج من «البرنامج > إغلاق البرنامج» ثم افتحه من جديد لاستعادة العمل. بياناتك وعملياتك المسجلة محفوظة.';
const restartError=()=>Object.assign(Error(RESTART_ERROR),{code:RESTART_CODE});
function indexTableHash(index){
  if(!index?.hashes||typeof index.hashes!=='object'||Array.isArray(index.hashes)||!hash(index.schema_sha256)
    ||!Number.isSafeInteger(index.count)||index.count<0||index.count>500000)throw Error(SAFE_ERROR);
  let total=0;const digest=crypto.createHash('sha256').update('dashboard-delta-table-v1\0');
  for(const key of Object.keys(index.hashes).sort()){
    const count=index.hashes[key];
    if(!hash(key)||!Number.isSafeInteger(count)||count<1||(total+=count)>500000)throw Error(SAFE_ERROR);
    digest.update(key+':'+count+'\n');
  }
  if(total!==index.count)throw Error(SAFE_ERROR);return digest.digest('hex');
}

/** Prepare incoming changes without holding the renderer or the active business database. */
class DashboardDelta{
  constructor({runtime,metadata,download,view,handoff=work=>work(),recover=async()=>{},remoteState,allowPreview=false,onState=()=>{}}){
    Object.assign(this,{runtime,metadata,download,view,handoff,recover,remoteState,onState});this.allowPreview=allowPreview===true;
    this.running=null;this.stopped=false;this.state={phase:'idle',deferred:false,error:'',restartRequired:false};
  }
  stop(){this.stopped=true;this.runtime.cancelDelta?.();}
  report(value){Object.assign(this.state,value);this.onState({...this.state});}
  run(){
    if(this.running)return this.running;
    if(this.state.restartRequired)return Promise.reject(restartError());
    if(this.stopped)return Promise.resolve({deferred:true});
    this.running=this.refresh().finally(()=>{this.running=null;});return this.running;
  }
  captureValid(capture,previous,context){
    const value=capture?.identity,index=capture?.index;
    return capture?.format===1&&capture.kind==='dashboard-delta-capture'&&hash(capture.capture_id)
      &&/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(capture.capture_database||'')
      &&capture.capture_database!==previous.database&&capture.source_database===previous.database
      &&Number.isSafeInteger(capture.sequence)&&capture.sequence>=0&&value?.device_id===previous.deviceId
      &&value.actor_id===previous.actorId&&value.snapshot_id===previous.snapshotId&&value.schema_hash===previous.schemaHash
      &&sourceCode.same(value.source,previous.sourceFingerprint)&&same(value.branches,previous.branches)
      &&capture.replay?.format===1&&capture.replay.source_database===previous.database&&capture.replay.sequence===capture.sequence
      &&Number.isSafeInteger(capture.replay.rows)&&capture.replay.rows>=0&&hash(capture.replay.sha256)
      &&index?.format===1&&index.kind==='dashboard-delta-index'&&index.device_id===previous.deviceId&&index.actor_id===previous.actorId
      &&sourceCode.same(index.source,context.sourceFingerprint)&&index.tables&&typeof index.tables==='object'&&!Array.isArray(index.tables);
  }
  unchanged(capture,delta,previous,context){
    const baseline=capture.identity;
    return delta.delta_summary?.changed_tables===0&&delta.delta_summary.added_rows===0&&delta.delta_summary.removed_rows===0
      &&context.manifest.sourceRevision===previous.sourceRevision&&sourceCode.same(delta.source,previous.sourceFingerprint)
      &&delta.schema_hash===baseline.schema_hash
      &&same(delta.branches,baseline.branches)&&same(delta.coverage,baseline.coverage)
      &&Array.isArray(baseline.media)&&same(coverage.mediaReceipt(delta.media),coverage.mediaReceipt(baseline.media))
      &&baseline.enrollment&&same(delta.enrollment,baseline.enrollment)
      &&same(Object.keys(delta.tables).sort(),Object.keys(capture.index.tables).sort())
      &&Object.entries(delta.tables).every(([name,part])=>part?.delta&&Array.isArray(part.delta.add)&&part.delta.add.length===0
        &&part.delta.remove&&Object.keys(part.delta.remove).length===0&&part.delta.count===capture.index.tables[name].count
        &&part.delta.target_hash===indexTableHash(capture.index.tables[name]));
  }
  async refresh(){
    let capture,token,candidate,intent;
    try{
      await this.recover();
      if(this.stopped)throw Error(SAFE_ERROR);
      await this.runtime.recoverDeltaBackground?.();
      if(this.stopped)throw Error(SAFE_ERROR);
      if(!this.view.current().local){this.report({phase:'deferred',deferred:true,error:''});return {deferred:true};}
      await this.remoteState?.assertClean();
      if(this.stopped)throw Error(SAFE_ERROR);
      const previous=await this.runtime.connection(),binding=identity(previous);
      this.report({phase:'checking',deferred:false,error:''});
      // This verifies publication and the installed executable before creating any private capture.
      const context=await this.runtime.deltaContext(previous);
      if(this.stopped)throw Error(SAFE_ERROR);
      token=crypto.randomBytes(32).toString('hex');
      const captureGeneration=crypto.randomBytes(8).toString('hex');
      this.report({phase:'capturing'});
      capture=await this.runtime.captureDelta(context,previous,captureGeneration,token);
      if(this.stopped)throw Error(SAFE_ERROR);
      if(!this.captureValid(capture,previous,context))throw Error(SAFE_ERROR);
      this.report({phase:'downloading'});
      // A capture token and database receipt never cross the network boundary.
      const delta=await this.download(capture.index,previous);
      if(this.stopped)throw Error(SAFE_ERROR);
      if(delta?.format!==1||delta.kind!=='dashboard-data-delta'||delta.device_id!==previous.deviceId||delta.actor_id!==previous.actorId
        ||!uuid(delta.snapshot_id)||!hash(delta.schema_hash)||!sourceCode.same(delta.source,context.sourceFingerprint)
        ||!Array.isArray(delta.branches)||!delta.branches.length||!delta.tables||Array.isArray(delta.tables)||typeof delta.tables!=='object')throw Error(SAFE_ERROR);
      media.manifest(delta.media);
      const manifest=context.manifest;
      coverage.snapshotCoverage(delta,manifest,this.allowPreview);
      if(identity(await this.runtime.connection())!==binding)throw Error(SAFE_ERROR);
      if(this.unchanged(capture,delta,previous,context)){
        this.report({phase:'ready',deferred:false,error:''});return {unchanged:true};
      }
      const id=crypto.randomBytes(8).toString('hex');
      this.report({phase:'staging'});
      candidate=await this.runtime.stageDelta(context,capture,delta,id,token);
      if(this.stopped)throw Error(SAFE_ERROR);
      const receipt=candidate?.receipt;
      const handoff=candidate?.archiveHandoff;
      if(candidate.database!=='fasakhansta_dashboard_stage_'+id||candidate.mediaVerified!==true||!candidate.mediaSeal
        ||candidate.sourceRevision!==manifest.sourceRevision||!sourceCode.same(candidate.sourceFingerprint,delta.source)
        ||receipt?.verified!==true||receipt.device_id!==delta.device_id||receipt.actor_id!==delta.actor_id
        ||receipt.snapshot_id!==delta.snapshot_id||receipt.schema_hash!==delta.schema_hash||!sourceCode.same(receipt.source,delta.source)
        ||!same(receipt.branches,delta.branches)||!same(receipt.coverage,delta.coverage)||!same(receipt.delta_replay,capture.replay)
        ||handoff?.format!==1||handoff.kind!=='desktop-preverified-handoff'||!hash(handoff.signature)
        ||handoff.payload?.database!==candidate.database||handoff.payload.source_database!==previous.database||handoff.payload.sequence!==capture.sequence
        ||!same(handoff.payload.source_identity,{snapshot_id:previous.snapshotId,schema_hash:previous.schemaHash})
        ||!same(handoff.payload.replay,capture.replay))throw Error(SAFE_ERROR);
      const scope=coverage.preparedCoverage(delta,candidate,manifest,this.allowPreview);
      const refreshId=crypto.randomUUID();
      const next={...previous,format:2,generation:id,database:candidate.database,snapshotId:delta.snapshot_id,schemaHash:delta.schema_hash,
        branches:delta.branches,...scope,mediaVerified:true,refreshId,sourceRevision:candidate.sourceRevision,sourceFingerprint:candidate.sourceFingerprint};
      if(next.previewOnly)next.coverageBinding=coverage.binding(next);
      else{delete next.previewOnly;delete next.coverage;delete next.mediaManifestHash;delete next.coverageBinding;}
      if(!prepared(next,previous.deviceId,manifest))throw Error(SAFE_ERROR);
      // Publication revalidation is also outside the final view/database gate.
      await this.runtime.authorizeDeltaContext(context);
      if(this.stopped)throw Error(SAFE_ERROR);
      if(identity(await this.runtime.connection())!==binding)throw Error(SAFE_ERROR);
      this.report({phase:'waiting'});
      const result=await this.handoff(()=>this.view.refresh(async()=>{
        if(this.stopped)return {deferred:true};
        if(identity(await this.runtime.connection())!==binding)return {deferred:true};
        await this.remoteState?.assertClean();
        intent={format:1,id:refreshId,token:crypto.randomBytes(32).toString('hex'),generation:id,previous};
        // Only now save the crash-recovery capability and acquire the active database fence.
        await this.metadata.write('refresh',intent);
        let fence;
        try{fence=await this.runtime.control({action:'refresh-begin',refresh_id:intent.id,token:intent.token});}
        catch(error){if(error.status===409){await this.recover();intent=null;return {deferred:true};}throw error;}
        if(fence.held!==true||fence.device_id!==previous.deviceId||fence.actor_id!==previous.actorId||fence.snapshot_id!==previous.snapshotId
          ||fence.schema_hash!==previous.schemaHash||fence.refresh_id!==intent.id||fence.fenced_sequence!==capture.sequence){
          await this.recover();intent=null;return {deferred:true};
        }
        this.report({phase:'activating'});
        // All full media/row checks finished before this point. The archive consumes the private stat seal.
        await this.runtime.finalizeDelta(candidate,intent);
        if(this.stopped){await this.recover();intent=null;return {deferred:true};}
        await this.metadata.write('generations/'+intent.id,{format:1,previous,next,fence,receipt:candidate.receipt});
        await this.runtime.activate(next,()=>this.metadata.write('prepared',next));
        await this.metadata.clearIntent();intent=null;
        return next;
      }));
      if(result?.deferred){this.report({phase:'deferred',deferred:true,error:''});return {deferred:true};}
      this.report({phase:'ready',deferred:false,error:''});return result;
    }catch(error){
      const restartRequired=error?.code===RESTART_CODE;
      // The view can require a restart after pointer activation has already
      // cleared the intent. Resolve any durable fence before reporting it.
      if(intent||restartRequired){try{await this.recover();intent=null;}catch{
        this.report({phase:'failed',deferred:false,restartRequired,error:restartRequired?RESTART_ERROR:SAFE_ERROR});
        throw restartRequired?restartError():Error(SAFE_ERROR);
      }}
      if(restartRequired){this.report({phase:'failed',deferred:false,restartRequired:true,error:RESTART_ERROR});throw restartError();}
      this.report({phase:'failed',deferred:false,error:SAFE_ERROR});throw Error(SAFE_ERROR);
    }finally{
      // Stop leaves the durable inactive-clone capability for verified recovery
      // on the next launch. Accepted active-database fence recovery above still
      // completes before this finally block and the native shutdown barrier.
      if(!this.stopped){
        if(candidate&&token&&!intent)await this.runtime.releaseDeltaStage?.(candidate,token).catch(()=>{});
        if(capture&&token)await this.runtime.releaseDeltaCapture?.(capture,token).catch(()=>{});
      }
    }
  }
}
module.exports={DashboardDelta,SAFE_ERROR,RESTART_CODE,RESTART_ERROR,indexTableHash};
