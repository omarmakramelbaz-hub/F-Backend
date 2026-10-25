'use strict';
// Synthetic main-process acceptance. This file and src are executed inside a real ASAR.
const {app,BrowserWindow}=require('electron');
const fs=require('node:fs/promises'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const RuntimeArchive=require('./src/runtime-archive.cjs');
const digest=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
let stopping=false,active,window;
app.whenReady().then(async()=>{
  assert.equal(process.platform,'win32');assert.equal(process.type,'browser');
  assert.equal(process.env.ELECTRON_RUN_AS_NODE,undefined);
  assert.ok(__dirname.includes('.asar'));
  const contract=JSON.parse(await fs.readFile(process.env.GO_ERP_INVENTORY_CONTRACT,'utf8'));
  const directory=process.env.GO_ERP_INVENTORY_FIXTURE;
  const archive=new RuntimeArchive(path.dirname(directory),{}, {stopped:()=>stopping});
  const receipt={format:1,revision:contract.revision,files:contract.files};
  for(const [name,expected]of Object.entries(contract.packagedHashes))
    assert.equal(digest(await fs.readFile(path.join(__dirname,name))),expected);
  window=new BrowserWindow({show:false,webPreferences:{nodeIntegration:false,contextIsolation:true,sandbox:true}});
  await window.loadURL('data:text/html,<title>Private inventory acceptance</title>');
  let ticks=0;const heartbeat=setInterval(()=>ticks++,10);
  try{active=archive.verify(directory,receipt);await active;}finally{active=null;clearInterval(heartbeat);}
  assert.ok(ticks>0,'The actual Electron main event loop progresses while its ASAR worker reads files.');
  const name='application/vendor/fixture-00000.php',file=path.join(directory,name);
  const original=await fs.readFile(file),stat=await fs.stat(file);
  await fs.writeFile(file,Buffer.alloc(original.length,0x79));await fs.utimes(file,stat.atime,stat.mtime);
  await assert.rejects(archive.verify(directory,receipt));
  await assert.rejects(archive.sourceFingerprint(directory));
  await fs.writeFile(file,original);
  await fs.writeFile(path.join(directory,'unexpected.php'),'<?php // unexpected');
  await assert.rejects(archive.verify(directory,receipt));await fs.unlink(path.join(directory,'unexpected.php'));
  const displaced=path.join(path.dirname(directory),'displaced-fixture.php');
  await fs.rename(file,displaced);
  try{await assert.rejects(archive.verify(directory,receipt));}finally{await fs.rename(displaced,file);}
  const cancelTimer=setTimeout(()=>{stopping=true;},25);
  try{active=archive.inventory(directory);await assert.rejects(active,/البرنامج يُغلق الآن/);}
  finally{active=null;clearTimeout(cancelTimer);stopping=false;}
  await archive.verify(directory,receipt);
  assert.deepEqual(await fs.readFile(file),original,'Tests never discard or retain altered application bytes.');
  const proof={format:1,kind:'go-erp-runtime-inventory-electron-asar',rootSha:contract.rootSha,
    platform:process.platform,electronVersion:process.versions.electron,actualElectronMain:true,packagedAsar:true,
    exactInventoryVerified:true,parentEventLoopProgressed:true,sameLengthAndMtimeTamperRejected:true,
    failedRecheckInvalidatesAuthority:true,extraFileRejected:true,missingFileRejected:true,cancellationRejected:true,
    workerSettledBeforeExit:true,originalBytesPreserved:true,files:Object.keys(contract.files).length,
    sourceHashes:contract.sourceHashes,completed:true};
  await fs.writeFile(process.env.GO_ERP_INVENTORY_RECEIPT,JSON.stringify(proof)+'\n',{flag:'wx'});
  console.log('RUNTIME_INVENTORY_ELECTRON_COMPLETE '+JSON.stringify(proof));
}).then(()=>{window?.destroy();app.exit(0);}).catch(async error=>{
  stopping=true;if(active)await active.catch(()=>{});
  console.error(error.stack);window?.destroy();app.exit(1);
});
