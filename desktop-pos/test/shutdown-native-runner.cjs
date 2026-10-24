'use strict';
// Node waits for both real Windows GUI processes. A timeout is always failure;
// force termination is confined to cleanup of that failed disposable fixture.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawnSync,execFileSync}=require('node:child_process');
const crypto=require('node:crypto'),assert=require('node:assert/strict');
assert.equal(process.platform,'win32','Native shutdown acceptance requires actual Windows.');
assert.equal(process.argv.length,3,'One output receipt path is required.');
const output=path.resolve(process.argv[2]);
const executable=path.resolve(__dirname,'../node_modules/electron/dist/electron.exe');
assert.equal(fs.existsSync(executable),true,'The pinned Electron executable is required.');
const lockedElectron=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
assert.match(sourceRevision,/^[a-f0-9]{40}$/);
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const expectedHashes={shutdown:sha(path.join(__dirname,'../src/shutdown.cjs')),dashboard:sha(path.join(__dirname,'../src/dashboard.cjs')),
  fixture:sha(path.join(__dirname,'shutdown-native.cjs'))};
const root=fs.mkdtempSync(path.join(os.tmpdir(),'go-erp-native-shutdown-'));
const profile=path.join(root,'synthetic profile');fs.mkdirSync(profile);
fs.writeFileSync(path.join(profile,'shutdown-fixture-profile.marker'),'GO_ERP_SYNTHETIC_SHUTDOWN_ONLY\n',{flag:'wx'});
const receipts=[];
let proof;
try{
  for(const phase of ['close','reopen']){
    const phaseReceipt=path.join(root,phase+'.json'),env={...process.env};delete env.ELECTRON_RUN_AS_NODE;
    const child=spawnSync(executable,[path.join(__dirname,'shutdown-native.cjs'),phase,profile,phaseReceipt],
      {env,encoding:'utf8',shell:false,windowsHide:true,timeout:45000,maxBuffer:1024*1024});
    if(child.stdout)process.stdout.write(child.stdout);if(child.stderr)process.stderr.write(child.stderr);
    if(child.error||child.status!==0||child.signal!==null){
      if(child.pid)spawnSync('taskkill.exe',['/PID',String(child.pid),'/T','/F'],{stdio:'ignore',shell:false,windowsHide:true,timeout:10000});
      throw Error('Actual Windows native shutdown failed in '+phase+' phase: '+(child.error?.code||child.status||child.signal));
    }
    const receipt=JSON.parse(fs.readFileSync(phaseReceipt,'utf8'));
    assert.equal(receipt.pid,child.pid,'The receipt must belong to the real Electron process just observed exiting.');
    assert.equal(receipt.phase,phase);assert.equal(receipt.sourceRevision,sourceRevision);
    assert.equal(receipt.electronVersion,lockedElectron);assert.equal(receipt.platform,'win32');
    assert.deepEqual(receipt.sourceHashes,expectedHashes);assert.equal(receipt.hardware,false);assert.equal(receipt.productionProfile,false);
    for(const field of ['primaryInstance','actualDashboardWindow','actualBeforeUnloadVeto','acceptedWriteCommitted',
      'retainedBeforeCommit','drainedBeforeUnload','sqliteClosed','nativeWindowClosed','naturalQuit','completed'])assert.equal(receipt[field],true,phase+': '+field);
    assert.ok(Number.isInteger(receipt.pid)&&receipt.pid>0);
    receipts.push(receipt);
  }
  assert.notEqual(receipts[0].pid,receipts[1].pid,'Reopen must start a fresh native process.');
  assert.equal(receipts[1].savedRowVerified,true,'The second actual Electron process must read the accepted SQLite row.');
  proof={format:1,kind:'go-erp-native-shutdown',sourceRevision,sourceHashes:expectedHashes,
    runnerSha256:sha(__filename),electronVersion:lockedElectron,platform:'win32',hardware:false,productionProfile:false,
    actualElectron:true,actualDashboardWindow:true,actualBeforeUnloadVeto:true,acceptedWriteBeforeClose:true,
    retainedBeforeCommit:true,drainedBeforeUnload:true,naturalPrimaryExit:true,singleInstanceReleased:true,
    actualFreshReopen:true,sameProfile:true,savedRowVerified:true,forcedSuccessExit:false,processes:2,completed:true,
    phases:receipts.map(({phase,pid,naturalQuit,savedRowVerified})=>({phase,pid,naturalQuit,savedRowVerified}))};
}finally{fs.rmSync(root,{recursive:true,force:true,maxRetries:30,retryDelay:100});}
fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify(proof)+'\n');
process.stdout.write('NATIVE_SHUTDOWN_REOPEN_COMPLETE '+JSON.stringify({processes:2,sourceRevision,electronVersion:lockedElectron})+'\n');
