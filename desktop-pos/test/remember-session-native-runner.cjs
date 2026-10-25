'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {spawnSync,execFileSync}=require('node:child_process');
assert.equal(process.platform,'win32');assert.equal(process.argv.length,3);
const output=path.resolve(process.argv[2]),executable=path.resolve(__dirname,'../node_modules/electron/dist/electron.exe');
assert.equal(fs.existsSync(executable),true);
const sourceRevision=process.env.GITHUB_SHA||execFileSync('git',['rev-parse','HEAD'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}).trim();
const electronVersion=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../package-lock.json'),'utf8')).packages['node_modules/electron'].version;
const sha=file=>crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sourceHashes={dashboard:sha(path.join(__dirname,'../src/dashboard.cjs')),shutdown:sha(path.join(__dirname,'../src/shutdown.cjs')),fixture:sha(path.join(__dirname,'remember-session-native.cjs'))};
const root=fs.mkdtempSync(path.join(os.tmpdir(),'go-erp-native-remember-')),receipts=[];
try{
  for(const scenario of ['remember','plain','invalid']){
    const profile=path.join(root,scenario);fs.mkdirSync(profile);
    fs.writeFileSync(path.join(profile,'remember-session-fixture.marker'),'GO_ERP_SYNTHETIC_REMEMBER_ONLY\n',{flag:'wx'});
    const pids=[];
    for(const phase of ['close','reopen']){
      const receiptFile=path.join(root,scenario+'-'+phase+'.json'),env={...process.env};delete env.ELECTRON_RUN_AS_NODE;
      const child=spawnSync(executable,[path.join(__dirname,'remember-session-native.cjs'),scenario,phase,profile,receiptFile],
        {env,encoding:'utf8',shell:false,windowsHide:true,timeout:45000,maxBuffer:1024*1024});
      if(child.stdout)process.stdout.write(child.stdout);if(child.stderr)process.stderr.write(child.stderr);
      if(child.error||child.status!==0||child.signal!==null){
        if(child.pid)spawnSync('taskkill.exe',['/PID',String(child.pid),'/T','/F'],{stdio:'ignore',shell:false,windowsHide:true,timeout:10000});
        throw Error('Actual native remembered-session acceptance failed: '+scenario+'/'+phase+' '+(child.error?.code||child.status||child.signal));
      }
      const receipt=JSON.parse(fs.readFileSync(receiptFile,'utf8'));
      assert.equal(receipt.sourceRevision,sourceRevision);assert.deepEqual(receipt.sourceHashes,sourceHashes);
      assert.equal(receipt.electronVersion,electronVersion);assert.equal(receipt.platform,'win32');assert.equal(receipt.pid,child.pid);
      assert.equal(receipt.scenario,scenario);assert.equal(receipt.phase,phase);assert.equal(receipt.posts,0);
      for(const field of ['actualElectron','syntheticServer','primaryInstance','cookieFlagsChecked','scopedSessionEnded','naturalQuit','completed'])assert.equal(receipt[field],true,field);
      for(const field of ['hardware','productionProfile','forcedSuccessExit'])assert.equal(receipt[field],false,field);
      if(phase==='close')assert.equal(receipt.logoutRequests,scenario==='plain'?1:0);
      else if(scenario==='remember')assert.equal(receipt.automaticSameAccount,true);
      else{assert.equal(receipt.anonymousAfterReopen,true);if(scenario==='invalid')assert.equal(receipt.invalidRejected,true);}
      if(scenario!=='plain')assert.equal(receipt.recallerPreserved,true);
      pids.push(receipt.pid);receipts.push(receipt);
    }
    assert.notEqual(pids[0],pids[1],'Reopen must be a fresh native process.');
  }
  const proof={format:1,kind:'go-erp-native-remember-session',sourceRevision,sourceHashes,runnerSha256:sha(__filename),electronVersion,platform:'win32',
    actualElectron:true,syntheticServer:true,hardware:false,productionProfile:false,forcedSuccessExit:false,processes:6,
    sameProfileFreshReopen:true,httpOnlyCookieFlags:true,persistentRecallerPreserved:true,currentSessionEnded:true,
    normalLogoutWithoutRecaller:true,invalidRecallerRejected:true,noCredentialPost:true,naturalQuit:true,completed:true,
    phases:receipts.map(({scenario,phase,pid,logoutRequests,automaticSameAccount,anonymousAfterReopen,invalidRejected})=>({scenario,phase,pid,logoutRequests,automaticSameAccount,anonymousAfterReopen,invalidRejected}))};
  fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify(proof)+'\n');
  process.stdout.write('NATIVE_REMEMBER_SESSION_COMPLETE '+JSON.stringify({processes:6,sourceRevision,electronVersion})+'\n');
}finally{fs.rmSync(root,{recursive:true,force:true,maxRetries:30,retryDelay:100});}
