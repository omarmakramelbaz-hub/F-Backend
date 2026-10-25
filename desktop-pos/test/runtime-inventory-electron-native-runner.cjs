'use strict';
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {spawnSync,execFileSync}=require('node:child_process');
const asar=require('../node_modules/@electron/asar');
const root=path.resolve(__dirname,'../..'),digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
async function main(){
  if(process.platform!=='win32')throw Error('Actual Windows Electron acceptance is required.');
  const output=path.resolve(process.argv[2]||path.join(root,'desktop-pos/dist/runtime-inventory-electron-native.json'));
  const temporary=await fs.mkdtemp(path.join(os.tmpdir(),'go-erp-inventory-asar-'));
  const application=path.join(temporary,'package'),fixture=path.join(temporary,'fixture'),archive=path.join(temporary,'app.asar');
  try{
    await fs.mkdir(application);await fs.mkdir(path.join(fixture,'application/vendor'),{recursive:true});
    await fs.cp(path.join(root,'desktop-pos/src'),path.join(application,'src'),{recursive:true});
    await fs.copyFile(path.join(__dirname,'runtime-inventory-electron-native.cjs'),path.join(application,'probe.cjs'));
    await fs.writeFile(path.join(application,'package.json'),JSON.stringify({name:'private-inventory-acceptance',version:'1.0.0',main:'probe.cjs'}));
    const revision='a'.repeat(40),files={};
    const fixtureBytes=Buffer.alloc(1024,0x78);
    const fileHash=digest(fixtureBytes);
    for(let i=0;i<8000;i++){
      const name='application/vendor/fixture-'+String(i).padStart(5,'0')+'.php';
      await fs.writeFile(path.join(fixture,name),fixtureBytes);files[name]=fileHash;
    }
    const manifest=Buffer.from(JSON.stringify({format:1,platform:'win32-x64',sourceRevision:revision,sourceHashes:{}}));
    await fs.writeFile(path.join(fixture,'manifest.json'),manifest);files['manifest.json']=digest(manifest);
    const sourcePaths=['desktop-pos/src/runtime-archive.cjs','desktop-pos/src/runtime-inventory-worker.cjs',
      'desktop-pos/src/dashboard-source.cjs','desktop-pos/src/native-pdf.cjs',
      'desktop-pos/test/runtime-inventory-electron-native.cjs','desktop-pos/test/runtime-inventory-electron-native-runner.cjs'];
    const sourceHashes={};for(const name of sourcePaths)sourceHashes[name]=digest(await fs.readFile(path.join(root,name)));
    const packagedHashes={};for(const name of sourcePaths.slice(0,4))packagedHashes['src/'+path.basename(name)]=sourceHashes[name];
    packagedHashes['probe.cjs']=sourceHashes[sourcePaths[4]];
    const rootSha=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8',windowsHide:true}).trim();
    const contract=path.join(temporary,'contract.json'),receipt=path.join(temporary,'receipt.json');
    await fs.writeFile(contract,JSON.stringify({revision,rootSha,files,sourceHashes,packagedHashes}));
    await asar.createPackage(application,archive);
    const executable=path.resolve(__dirname,'../node_modules/electron/dist/electron.exe');
    const env={...process.env,GO_ERP_INVENTORY_FIXTURE:fixture,GO_ERP_INVENTORY_CONTRACT:contract,GO_ERP_INVENTORY_RECEIPT:receipt};
    delete env.ELECTRON_RUN_AS_NODE;delete env.NODE_OPTIONS;
    const result=spawnSync(executable,[archive],{cwd:temporary,env,encoding:'utf8',windowsHide:true,shell:false,timeout:180000,maxBuffer:2*1024*1024});
    if(result.stdout)process.stdout.write(result.stdout);if(result.stderr)process.stderr.write(result.stderr);
    if(result.error)throw result.error;assert.equal(result.signal,null);assert.equal(result.status,0);
    const proof=JSON.parse(await fs.readFile(receipt,'utf8'));
    assert.equal(proof.rootSha,rootSha);assert.equal(proof.platform,'win32');assert.equal(proof.files,8001);
    assert.equal(proof.electronVersion,JSON.parse(await fs.readFile(path.join(root,'desktop-pos/package-lock.json'),'utf8')).packages['node_modules/electron'].version);
    for(const field of ['actualElectronMain','packagedAsar','exactInventoryVerified','parentEventLoopProgressed',
      'sameLengthAndMtimeTamperRejected','failedRecheckInvalidatesAuthority','extraFileRejected','missingFileRejected',
      'cancellationRejected','workerSettledBeforeExit','originalBytesPreserved','completed'])assert.equal(proof[field],true,field);
    assert.deepEqual(proof.sourceHashes,sourceHashes);
    for(const name of sourcePaths)assert.equal(digest(await fs.readFile(path.join(root,name))),sourceHashes[name]);
    await fs.mkdir(path.dirname(output),{recursive:true});await fs.writeFile(output,JSON.stringify(proof)+'\n');
  }finally{await fs.rm(temporary,{recursive:true,force:true});}
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});
