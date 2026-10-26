'use strict';
// Disposable Windows acceptance helper. Production source/authentication paths
// remain unchanged; only the test-owned server dataset/authority are synthetic.
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const crypto=require('node:crypto');

/** Build the response with original PHP codecs from canonical server-fixture bytes. */
function deltaFromCanonicalSnapshot({php,bootstrap,canonicalSnapshotWire,sourceFingerprint,snapshotId=crypto.randomUUID(),
  codeRoot=path.join(__dirname,'../src')}){
  assert.equal(typeof php,'function');assert.equal(typeof bootstrap,'string');assert.equal(typeof canonicalSnapshotWire,'string');
  assert.ok(Buffer.byteLength(canonicalSnapshotWire)<=256*1024*1024);
  const code=bootstrap+String.raw`
use App\Services\Dashboard\DesktopDashboardBootstrap as Json;
use App\Services\Dashboard\DesktopDashboardDeltaCodec as Codec;
$snapshot=json_decode($input['wire'],true,512,JSON_THROW_ON_ERROR);$index=$input['index'];
if($snapshot['kind']!=='initial-dashboard-data'||$snapshot['device_id']!==$index['device_id']||$snapshot['actor_id']!==$index['actor_id']
    ||$index['kind']!=='dashboard-delta-index'||$index['source']!==$input['source'])throw new RuntimeException('Synthetic source-upgrade dataset binding failed.');
$snapshot['snapshot_id']=$input['snapshot'];$snapshot['source']=$input['source'];
$retired=array_diff(array_keys($index['tables']),array_keys($snapshot['tables']));
$summary=['changed_tables'=>count($retired),'added_rows'=>0,'removed_rows'=>0];
foreach($snapshot['tables'] as $table=>&$part){
    if(!hash_equals($part['sha256'],hash('sha256',Json::json($part['rows']))))throw new RuntimeException('Canonical server-fixture rows changed.');
    $new=!array_key_exists($table,$index['tables']);
    $before=$index['tables'][$table]??['schema_sha256'=>Codec::schemaHash($part['ddl']),'count'=>0,'hashes'=>[]];
    $delta=Codec::delta($table,$part['rows'],$snapshot['actor_id'],$part['ddl'],$before,Codec::projection($table,$snapshot['coverage']));
    if($new||($delta['replace']??false)===true||$delta['add']!==[]||$delta['remove']!==[])$summary['changed_tables']++;
    $summary['added_rows']+=count($delta['add']);$summary['removed_rows']+=array_sum($delta['remove']);
    $part=['ddl'=>$part['ddl'],'delta'=>$delta];
}
unset($part);$snapshot['kind']='dashboard-data-delta';$snapshot['delta_summary']=$summary;
echo json_encode(['wire'=>Json::json($snapshot)],JSON_THROW_ON_ERROR);
`;
  return async(index,previous,{signal}={})=>{
    if(signal?.aborted)throw Error('Synthetic source-upgrade download stopped.');
    const result=await php(code,{wire:canonicalSnapshotWire,index,source:sourceFingerprint,snapshot:snapshotId});
    if(signal?.aborted)throw Error('Synthetic source-upgrade download stopped.');
    assert.equal(typeof result.wire,'string');
    // Use the original lossless transport reader. Do not JSON.parse/re-encode
    // canonical PHP tables in JavaScript, including exact 64-bit values.
    const readSnapshot=require(path.join(codeRoot,'dashboard-download.cjs'));
    return readSnapshot(new Response(result.wire,{headers:{'content-type':'application/json'}}));
  };
}

/**
 * Invoke after the existing generation-native B upgrade, with a distinct C
 * source-only installer and its restarted old prepared runtime. The caller owns
 * fixture creation/restart/cleanup; this module performs no setup or data reset.
 */
async function verifyStartupSourceUpgrade({runtime,installedManifest,download,allowPreview,php,bootstrap,
  codeRoot=path.join(__dirname,'../src'),sessionName='supervisor-fixture-session',expectedSession='synthetic preserved session',
  verifyBusiness=async()=>{},networkBudgetMs=30000}){
  assert.equal(process.platform,'win32','This acceptance helper requires actual Windows PHP/MariaDB supervisor processes.');
  const {upgradeStartupSource}=require(path.join(codeRoot,'startup-source-upgrade.cjs'));
  const {DashboardGeneration}=require(path.join(codeRoot,'dashboard-generation.cjs'));
  const source=require(path.join(codeRoot,'dashboard-source.cjs'));
  const before=await runtime.connection(),oldApplication=runtime.application,oldStorage=runtime.environment.DESKTOP_DASHBOARD_STORAGE;
  assert.equal(before.previewOnly,true);assert.equal(before.fullCoverage,false);assert.equal(allowPreview,true);
  assert.equal(source.same(before.sourceFingerprint,installedManifest.sourceFingerprint),false);
  assert.equal(source.same(runtime.sourceFingerprint,before.sourceFingerprint),true);
  assert.equal(await fs.readFile(path.join(oldStorage,'framework/sessions',sessionName),'utf8'),expectedSession);
  const businessCode=bootstrap+String.raw`
use Illuminate\Support\Facades\DB;
use App\Services\Dashboard\DesktopDashboardBootstrap as Json;
$tables=[];
foreach(['users','branch_customers','crm_customers','crm_customer_sources','branch_operation_commands'] as $name){
    $rows=DB::table($name)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
    $tables[$name]=['count'=>count($rows),'sha256'=>hash('sha256',Json::json($rows))];
}
$replay=[];
foreach(['desktop_dashboard_commands','desktop_dashboard_archived_commands'] as $name){
    foreach(DB::table($name)->where('device_id',$input['device'])->get(['device_id','command_id','actor_id','route_name','request_hash']) as $row)
        $replay[$row->device_id.':'.$row->command_id]=(array)$row;
}
ksort($replay);echo json_encode(['tables'=>$tables,'replayCount'=>count($replay),'replayHash'=>hash('sha256',Json::json(array_values($replay)))],JSON_THROW_ON_ERROR);
`;
  const readBusiness=()=>php(businessCode,{device:before.deviceId});
  const businessBefore=await readBusiness();assert.ok(businessBefore.replayCount>0,'The retained profile must contain historical replay protection.');
  const generations=new DashboardGeneration({runtime,metadata:runtime.metadata,allowPreview,
    download:async()=>{throw Error('Startup source acceptance must never fall back to a full snapshot.');}});
  let authorityCalls=0,downloadCalls=0;
  const options={runtime,metadata:runtime.metadata,installedManifest,allowPreview,
    online:()=>true,viewAbsent:()=>true,networkBudgetMs,
    // Synthetic publication authority, precisely bound to the separately
    // verified test installer. This is not a real HTTPS publication proof.
    authorizeInstalled:async(manifest,{signal})=>{
      authorityCalls++;assert.equal(signal.aborted,false);
      assert.equal(manifest.sourceRevision,installedManifest.sourceRevision);
      assert.ok(source.same(manifest.sourceFingerprint,installedManifest.sourceFingerprint));return true;
    },
    download:async(...args)=>{downloadCalls++;return download(...args);},recover:()=>generations.recoverIntent(),
    remoteState:{assertClean:async()=>{
      const value=await runtime.metadata.read('remote-writes');
      if(value)assert.deepEqual(value,{format:1,dirty:false,pending:[]});
    }},
    inspectIdle:async()=>{
      const pending=await runtime.control({action:'pending'});
      let printEffects=0;
      if(runtime.supportsPrintEffects()){
        const prints=await runtime.control({action:'print-outcomes'});
        assert.ok(Array.isArray(prints.outcomes));assert.equal(prints.outcomes.length,0);
        assert.equal(prints.pending,0);printEffects=prints.pending;
      }
      return {pending:pending.counts.pending,conflicts:pending.counts.conflicts,printEffects,
        legacyPending:0,legacyConflicts:0,openOrders:0};
    }};
  const offline=await upgradeStartupSource({...options,online:()=>false});
  assert.equal(offline.status,'deferred');assert.equal(authorityCalls,0);assert.equal(downloadCalls,0);
  assert.deepEqual(await runtime.connection(),before);
  // Default coverage remains fail-closed for the actual scoped preview source.
  const unapproved=await upgradeStartupSource({...options,allowPreview:false});
  assert.equal(unapproved.status,'deferred');assert.deepEqual(await runtime.connection(),before);
  assert.equal(await runtime.metadata.read('refresh'),null);
  authorityCalls=0;downloadCalls=0;
  const result=await upgradeStartupSource(options),next=await runtime.connection();
  assert.equal(result.status,'activated');assert.equal(next.sourceRevision,installedManifest.sourceRevision);
  assert.ok(source.same(next.sourceFingerprint,installedManifest.sourceFingerprint));
  assert.notEqual(next.database,before.database);assert.notEqual(runtime.application,oldApplication);
  assert.equal(next.previewOnly,true);assert.equal(next.fullCoverage,false);assert.deepEqual(next.coverage,before.coverage);
  assert.equal(await runtime.metadata.read('refresh'),null);assert.equal(await runtime.metadata.read('delta-background'),null);
  assert.deepEqual(await readBusiness(),businessBefore,'Original account rows and historical replay identities survive source activation.');
  assert.equal(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE,'framework/sessions',sessionName),'utf8'),expectedSession);
  assert.equal(await fs.readFile(path.join(oldStorage,'framework/sessions',sessionName),'utf8'),expectedSession);
  const oldState=await php(bootstrap+"echo json_encode(['state'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->where('device_id',$input['device'])->value('state')]);",
    {device:before.deviceId},{...runtime.environment,DB_DATABASE:before.database});
  assert.equal(oldState.state,'held');assert.equal((await runtime.control({action:'pending'})).counts.pending,0);
  await verifyBusiness({before,next,runtime});
  const completedAuthorityCalls=authorityCalls,completedDownloadCalls=downloadCalls;
  assert.ok(completedAuthorityCalls>=2);assert.equal(completedDownloadCalls,1);
  assert.equal((await upgradeStartupSource(options)).status,'unchanged');
  assert.equal(authorityCalls,completedAuthorityCalls);assert.equal(downloadCalls,completedDownloadCalls);
  return {format:1,kind:'go-erp-startup-source-native',actualWindows:true,actualPhp:true,actualMariaDB:true,
    actualDeltaCapture:true,actualDeltaStage:true,actualDeltaFinalize:true,actualDeltaActivate:true,
    installedAsarCode:codeRoot.includes('.asar'),sourceFingerprintChanged:true,preparedDataPreserved:true,
    originalUsersPreserved:true,originalSessionBytesPreserved:true,historicalReplayPreserved:true,
    originalPreviousDatabaseRetained:true,originalCoveragePreserved:true,offlineZeroHttp:true,sameSourceZeroHttp:true,
    unapprovedPreviewDenied:true,fullSnapshotFallback:false,authorityFixture:true,actualHttpsPublication:false,
    actualFullDashboard:false,previewOnly:true,completed:true};
}

module.exports={verifyStartupSourceUpgrade,deltaFromCanonicalSnapshot};
