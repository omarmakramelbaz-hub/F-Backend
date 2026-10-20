'use strict';
// Real NSIS installation in a disposable Windows directory; no production account or database.
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {execFileSync,spawnSync,spawn}=require('node:child_process'),{DatabaseSync}=require('node:sqlite');
const packaging=require('../installer-runtime.cjs');
const {installer:installerFingerprint}=require('../../.github/scripts/desktop-update-receipt.cjs');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const {powerShellJson,registryString}=require('./helpers/windows-registry.cjs');
async function automaticUpgrade({setup,installed,profile,root,powershell,registrationPath,executable,saved}){
  const crypto=require('node:crypto'),journal=path.join(profile,'unsynced-orders.sqlite');
  const journalHash=crypto.createHash('sha256').update(await fs.readFile(journal)).digest('hex');
  const ready=path.join(root,'auto-wait-ready'),release=path.join(root,'auto-wait-release');
  const restarted=path.join(root,'auto-restart-ready.json'),probe=path.join(root,'auto-restart-probe.cjs');
  assert.match(probe,/^[\x20-\x21\x23-\x7e]+$/,'The native restart fixture requires an ASCII probe path without quotes.');
  // Native Electron's existing run-as-Node fixture keeps the actual restarted
  // executable isolated from the runner's default GUI account and device profile.
  await fs.writeFile(probe,"'use strict';const fs=require('node:fs');if(process.env.ELECTRON_RUN_AS_NODE!=='1')throw Error('Native Node fixture required');"
    +"fs.writeFileSync(process.env.GO_ERP_AUTO_RESTART_READY,JSON.stringify({pid:process.pid,execPath:process.execPath}));setInterval(()=>{},1000);\n");
  const stopOwned=pid=>{
    const script='$ErrorActionPreference="Stop"; $found=@(); foreach($p in Get-Process){try{$file=$p.Path}catch{continue}; '
      +'if([StringComparer]::OrdinalIgnoreCase.Equals($file,$env:GO_ERP_FIXTURE_EXECUTABLE) '
      +'-and (-not $env:GO_ERP_FIXTURE_PID -or $p.Id -eq [int]$env:GO_ERP_FIXTURE_PID)){'
      +'$found+= $p.Id; $p.Kill(); if(-not $p.WaitForExit(30000)){throw "Owned fixture process did not exit"}}}; '
      +'ConvertTo-Json -InputObject @($found) -Compress';
    return JSON.parse(execFileSync(powershell,['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(script,'utf16le').toString('base64')],
      {encoding:'utf8',windowsHide:true,timeout:60000,env:{...process.env,GO_ERP_FIXTURE_EXECUTABLE:executable,GO_ERP_FIXTURE_PID:pid?String(pid):''}}).trim());
  };
  const holderScript='$ErrorActionPreference="Stop"; [IO.File]::WriteAllText($env:GO_ERP_WAIT_READY,"ready"); '
    +'$clock=[Diagnostics.Stopwatch]::StartNew(); while(-not [IO.File]::Exists($env:GO_ERP_WAIT_RELEASE)) {'
    +'if($clock.ElapsedMilliseconds -gt 120000){throw "Wait fixture deadline exceeded"};[Threading.Thread]::Sleep(25)}';
  const holder=spawn(powershell,['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(holderScript,'utf16le').toString('base64')],
    {windowsHide:true,stdio:'ignore',env:{...process.env,GO_ERP_WAIT_READY:ready,GO_ERP_WAIT_RELEASE:release}});
  let holderError;holder.on('error',error=>{holderError=error;});
  const holderDone=new Promise(resolve=>holder.once('close',code=>resolve(code)));
  let installer,installerDone;
  try{
    const readinessDeadline=performance.now()+30000;
    for(;;){try{await fs.access(ready);break;}catch(error){if(error.code!=='ENOENT')throw error;}
      if(holderError)throw holderError;if(holder.exitCode!==null||performance.now()>readinessDeadline)throw Error('Native automatic-upgrade wait fixture did not become ready.');await pause(25);}
    assert.ok(Number.isSafeInteger(holder.pid)&&holder.pid>0);
    // A successful replacement writes the packaged timestamp. This unique prior
    // timestamp makes premature extraction visible even for the same setup bytes.
    const sentinelTime=new Date('2001-02-03T04:05:06.000Z');await fs.utimes(executable,sentinelTime,sentinelTime);
    const untouched=(await fs.stat(executable)).mtimeMs;
    installer=spawn(setup,['/S','/GOERPAUTOUPDATE','/GOERPWAITPID='+holder.pid,'/D='+installed],{
      windowsHide:true,windowsVerbatimArguments:true,stdio:'ignore',
      env:{...process.env,ELECTRON_RUN_AS_NODE:'1',NODE_OPTIONS:'--require="'+probe.replace(/\\/g,'/')+'"',GO_ERP_AUTO_RESTART_READY:restarted}});
    let installerError;installer.on('error',error=>{installerError=error;});
    installerDone=new Promise(resolve=>installer.once('close',(code,signal)=>resolve({code,signal})));
    const heldUntil=performance.now()+12000;
    while(performance.now()<heldUntil){
      if(installerError)throw installerError;
      assert.equal(holder.exitCode,null,'Native wait PID ended before the fixture released it.');
      assert.equal(installer.exitCode,null,'Automatic setup finished while its previous-process PID was alive.');
      assert.equal((await fs.stat(executable)).mtimeMs,untouched,'Automatic setup extracted the target before its previous-process PID exited.');
      await assert.rejects(fs.access(restarted),{code:'ENOENT'});await pause(100);
    }
    await fs.writeFile(release,'release');assert.equal(await holderDone,0);
    let timer;
    const result=await Promise.race([installerDone,new Promise((_resolve,reject)=>{timer=setTimeout(()=>reject(Error('Automatic setup did not finish within five minutes.')),300000);})]).finally(()=>clearTimeout(timer));
    assert.equal(result.signal,null);assert.equal(result.code,0,
      'Automatic-upgrade publication requires an actual restart; a 3010 reboot deferral is not a certified restart.');
    assert.notEqual((await fs.stat(executable)).mtimeMs,untouched,'Automatic setup did not replace its target after the wait PID exited.');
    const restartDeadline=performance.now()+120000;let restart;
    for(;;){try{restart=JSON.parse(await fs.readFile(restarted,'utf8'));break;}catch(error){if(error.code!=='ENOENT')throw error;}
      if(performance.now()>restartDeadline)throw Error('Automatic setup did not restart its installed executable with the isolated native fixture.');await pause(100);}
    assert.deepEqual(Object.keys(restart).sort(),['execPath','pid']);
    assert.ok(Number.isSafeInteger(restart.pid)&&restart.pid>0);assert.equal(path.resolve(restart.execPath).toLowerCase(),path.resolve(executable).toLowerCase());
    assert.deepEqual(stopOwned(restart.pid),[restart.pid],'The restarted PID did not belong to the exact owned fixture executable.');
    assert.equal(crypto.createHash('sha256').update(await fs.readFile(journal)).digest('hex'),journalHash);
    const db=new DatabaseSync(journal,{readOnly:true});try{
      const row=db.prepare('SELECT id,acked,payload FROM outbox').get();assert.equal(row.id,saved.event);assert.equal(row.acked,0);
      assert.equal(JSON.parse(row.payload).data.total_cents,saved.total);assert.equal(db.prepare('SELECT status FROM orders WHERE id=?').get(saved.order).status,'paid');
    }finally{db.close();}
    assert.equal(await fs.readFile(path.join(installed,'KEEP-MY-FILE.txt'),'utf8'),'unrelated existing file');
    assert.equal(await fs.readFile(path.join(profile,'existing-profile.txt'),'utf8'),'previous device data');
    for(const [field,expected]of [['DisplayName','GO ERP'],['UninstallString','"'+path.join(installed,'Uninstall.exe')+'"']]){
      assert.equal(registryString(powershell,registrationPath,field),expected);
    }
    process.stdout.write('PASS real automatic NSIS upgrade waits for the previous PID, restarts the exact installed executable, and preserves the paid unsynced journal and unrelated files\n');
    return {waitedForExit:true,preservedJournal:true,preservedUnrelatedFiles:true,registrationRetained:true,restartedInstalledExecutable:true};
  }finally{
    await fs.writeFile(release,'release');await holderDone;
    if(installer&&installer.exitCode===null){try{installer.kill();}catch{}await installerDone;}
    stopOwned();
  }
}
async function residualPaths(directory){
  const found=[],pending=[directory];let scanned=0;
  while(pending.length&&found.length<12&&scanned<256){
    const current=pending.shift();let entries;
    try{entries=await fs.readdir(current,{withFileTypes:true});}
    catch(error){if(error.code==='ENOENT')continue;return {paths:found,error:error.code||error.message};}
    for(const entry of entries){
      if(++scanned>256)break;
      const file=path.join(current,entry.name);
      if(entry.isDirectory())pending.push(file);else found.push(path.relative(directory,file));
      if(found.length===12)break;
    }
  }
  return {paths:found,directories:pending.slice(0,8).map(file=>path.relative(directory,file)),scanned};
}
(async()=>{
  assert.equal(process.platform,'win32','Actual installer checks require Windows.');
  const project=path.resolve(__dirname,'..'),setup=path.resolve(process.argv[2]||path.join(project,'dist/GO-ERP-Preview-Setup.exe'));
  const acceptedInstaller=await installerFingerprint(setup);
  const prerequisiteFailureBeforeExtraction=await require('./installer-prerequisite-native.cjs')(project);
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'fasakhansta-installer-')),installed=path.join(root,'تجربة البرنامج'),profile=path.join(root,'retained-user-data');
  const powershell=path.join(process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe');
  const registrationPath='HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\FasakhanstaDashboardPreview';
  const folders=powerShellJson(powershell,'$result=[pscustomobject]@{desktop=[Environment]::GetFolderPath("DesktopDirectory");programs=[Environment]::GetFolderPath("Programs")}');
  const legacyDesktop=path.join(folders.desktop,'Fasakhansta Dashboard Preview.lnk'),legacyMenu=path.join(folders.programs,'Fasakhansta Dashboard Preview');
  let ownsFixturePaths=false;
  try {
    assert.equal(spawnSync('reg.exe',['query',registrationPath],{windowsHide:true}).status,1,'The disposable runner already has a preview registration.');
    for(const item of [legacyDesktop,legacyMenu,path.join(folders.desktop,'GO ERP.lnk'),path.join(folders.programs,'GO ERP')])
      await assert.rejects(fs.access(item),{code:'ENOENT'});
    ownsFixturePaths=true;
    await fs.mkdir(installed);await fs.mkdir(profile);await fs.writeFile(path.join(installed,'KEEP-MY-FILE.txt'),'unrelated existing file');
    await fs.writeFile(path.join(profile,'existing-profile.txt'),'previous device data');
    const legacyExecutable=path.join(installed,'Fasakhansta Dashboard Preview.exe');
    await fs.writeFile(legacyExecutable,'previous named file');await fs.writeFile(legacyDesktop,'previous named desktop link');
    await fs.mkdir(legacyMenu);await fs.writeFile(path.join(legacyMenu,'Fasakhansta Dashboard Preview.lnk'),'previous named menu link');
    await fs.writeFile(path.join(legacyMenu,'KEEP.txt'),'unrelated menu file');
    // NSIS documents /D= as the last, unquoted argument; no command shell is involved.
    let setupRebootRequired=false;
    const install=()=>{try{execFileSync(setup,['/S','/D='+installed],{windowsHide:true,windowsVerbatimArguments:true,timeout:300000});}
      catch(error){if(error.status!==3010)throw error;setupRebootRequired=true;}};
    install();
    assert.equal(await fs.readFile(legacyExecutable,'utf8'),'previous named file');
    assert.equal(await fs.readFile(legacyDesktop,'utf8'),'previous named desktop link');
    assert.equal(await fs.readFile(path.join(legacyMenu,'Fasakhansta Dashboard Preview.lnk'),'utf8'),'previous named menu link');
    // Only this exact previous registration makes the old program names owned.
    for(const [name,value]of [['DisplayName','Fasakhansta Dashboard Preview'],['UninstallString','"'+path.join(installed,'Uninstall.exe')+'"'],['DisplayVersion','0.4.4-preview.4']])
      execFileSync('reg.exe',['add',registrationPath,'/v',name,'/t','REG_SZ','/d',value,'/f'],{windowsHide:true});
    const readiness=path.join(root,'legacy-lock-ready'),release=path.join(root,'legacy-lock-release');
    const lockScript='$ErrorActionPreference="Stop"; $s=[IO.File]::Open($env:LEGACY_LOCK_TARGET,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read); '
      +'try {[IO.File]::WriteAllText($env:LEGACY_LOCK_READY,"ready"); $clock=[Diagnostics.Stopwatch]::StartNew(); '
      +'while(-not [IO.File]::Exists($env:LEGACY_LOCK_RELEASE)){if($clock.ElapsedMilliseconds -gt 120000){throw "Lock deadline exceeded"};[Threading.Thread]::Sleep(25)}} finally {$s.Dispose()}';
    const holder=spawn(powershell,['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(lockScript,'utf16le').toString('base64')],
      {windowsHide:true,stdio:['ignore','ignore','ignore'],env:{...process.env,LEGACY_LOCK_TARGET:legacyExecutable,LEGACY_LOCK_READY:readiness,LEGACY_LOCK_RELEASE:release}});
    let holderError;holder.on('error',error=>{holderError=error;});
    const holderDone=new Promise(resolve=>holder.once('close',code=>resolve(code)));
    try{
      const deadline=performance.now()+30000;
      for(;;){try{await fs.access(readiness);break;}catch(error){if(error.code!=='ENOENT')throw error;}
        if(holderError)throw holderError;if(holder.exitCode!==null||performance.now()>deadline)throw Error('The legacy executable lock fixture did not become ready.');await pause(25);}
      const protectedExecutable=path.join(installed,'GO ERP.exe');await fs.writeFile(protectedExecutable,'existing application before locked upgrade');
      const blocked=spawnSync(setup,['/S','/D='+installed],{windowsHide:true,windowsVerbatimArguments:true,timeout:120000});
      if(blocked.error)throw blocked.error;assert.equal(blocked.status,1603,'An in-use previous preview was not refused.');
      assert.equal(await fs.readFile(protectedExecutable,'utf8'),'existing application before locked upgrade','A locked upgrade modified the application before refusing.');
      assert.equal(await fs.readFile(legacyExecutable,'utf8'),'previous named file');
    }finally{await fs.writeFile(release,'release');assert.equal(await holderDone,0);}
    install();
    for(const item of [legacyExecutable,legacyDesktop,path.join(legacyMenu,'Fasakhansta Dashboard Preview.lnk')])
      await assert.rejects(fs.access(item),{code:'ENOENT'});
    assert.equal(await fs.readFile(path.join(legacyMenu,'KEEP.txt'),'utf8'),'unrelated menu file');
    assert.equal(await fs.readFile(path.join(profile,'existing-profile.txt'),'utf8'),'previous device data');
    process.stdout.write('PASS GO ERP retains unowned old names and upgrades only the matching previous preview while preserving device and unrelated files\n');
    const vcPolicy=JSON.parse(await fs.readFile(path.join(project,'windows-vcredist.json'),'utf8'));
    const vcPayload=JSON.parse((await fs.readFile(path.join(project,'dist/prerequisites/vcredist-package.json'),'utf8')).replace(/^\uFEFF/,''));
    assert.equal(vcPayload.sha256,vcPolicy.sha256);assert.equal(vcPayload.signerSha256,vcPolicy.signerSha256);
    assert.equal(vcPayload.version,vcPolicy.version);assert.equal(vcPayload.signatureValid,true);
    const vcVersion=execFileSync(path.join(process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe'),
      ['-NoProfile','-NonInteractive','-Command',
        '. (Join-Path $env:FASAKHANSTA_VC_PROJECT "windows-vcredist.ps1"); $v = Get-DesktopVcInstalledVersion; '
        +'if ($null -eq $v -or $v -lt [version]$env:FASAKHANSTA_VC_MINIMUM) { exit 1 }; $v.ToString()'],
      {encoding:'utf8',windowsHide:true,timeout:60000,env:{...process.env,FASAKHANSTA_VC_PROJECT:project,FASAKHANSTA_VC_MINIMUM:vcPolicy.version}}).trim();
    process.stdout.write('PASS installed dashboard has the registered Microsoft Visual C++ x64 prerequisite, with its bundled signed payload independently verified\n');
    const receipt=JSON.parse(await fs.readFile(path.join(installed,'resources/desktop-build.json'),'utf8'));
    const runtime=await packaging.verifyBundle(path.join(installed,'resources/dashboard-runtime'));
    assert.equal(receipt.sourceRevision,runtime.manifest.sourceRevision);assert.equal(receipt.channel,'preview');assert.equal(receipt.fullDashboard,false);
    assert.deepEqual(Object.keys(receipt.files).sort(),Object.keys(runtime.files).sort());
    for(const [name,value]of Object.entries(receipt.files))assert.equal(runtime.files[name],value,name+' changed during actual installation');
    process.stdout.write('PASS real Windows NSIS installation retains every original application, PHP and MariaDB byte in an Arabic custom path\n');
    const native=path.join(installed,'resources/dashboard-runtime');
    const php=path.join(native,'php/php.exe');
    assert.match(execFileSync(php,['-n','-v'],{encoding:'utf8',windowsHide:true}),/PHP 8\.2\./);
    const extensions=['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'];
    // PHP loads DLLs through the Windows ANSI loader during startup. Keep the
    // directory argument ASCII while exercising the real Arabic installation.
    const phpArgs=['-n','-d','extension_dir=ext',...extensions.flatMap(name=>['-d','extension='+name]),'-r',
      'foreach('+JSON.stringify(extensions).replace('[','array(').replace(']',')')+' as $module) { if (!extension_loaded($module)) {fwrite(STDERR,$module); exit(1);} } echo "installed extensions ready";'];
    assert.equal(execFileSync(php,phpArgs,{encoding:'utf8',windowsHide:true,cwd:path.join(native,'php')}),'installed extensions ready');
    assert.match(execFileSync(path.join(native,'mariadb/bin/mariadbd.exe'),['--no-defaults','--version'],{encoding:'utf8',windowsHide:true}),/11\.4\.13/);
    process.stdout.write('PASS installed Windows PHP and MariaDB executables start from their packaged paths\n');
    const probe=path.join(root,'packaged-ledger-probe.cjs');
    await fs.writeFile(probe,`'use strict';
      const path=require('node:path'),assert=require('node:assert/strict'),{randomUUID}=require('node:crypto');
      const Store=require(path.join(process.argv[2],'resources/app.asar/src/store.cjs'));
      const file=path.join(process.argv[3],'unsynced-orders.sqlite');let store=new Store(file);
      store.saveSnapshot({id:randomUUID(),generated_at:new Date().toISOString(),branch:{value:'f:100',name:'فرع اختبار'},actor:{id:10,name:'كاشير'},tax_bps:1400,service_bps:1000,can_discount:false,categories:[],products:[{id:1,name:'رنجة',unit:'kg',variants:[{option_id:'',label:'أساسي',unit_price_cents:10000,quantity_mode:'weight',inventory:{}}]}]});
      const order=store.newOrder();store.update(order.id,{...order.data,items:[{product_id:1,option_id:'',quantity_mode:'weight',quantity:'0.250'}],cash_received:'100.00'});
      const sale=store.dispatch(order.id,'sale');store.close();store=new Store(file);
      assert.equal(store.pending().length,1);assert.equal(store.pending()[0].id,sale.id);assert.equal(store.order(order.id).status,'paid');store.close();
      process.stdout.write(JSON.stringify({event:sale.id,order:order.id,total:sale.data.total_cents})+'\\n');`);
    const executable=path.join(installed,'GO ERP.exe');
    const ResEdit=require('resedit'),crypto=require('node:crypto'),hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
    const iconBytes=await fs.readFile(path.join(project,'src/assets/app.ico'));
    assert.equal(hash(await fs.readFile(path.join(installed,'resources/app.ico'))),hash(iconBytes));
    const resources=ResEdit.NtExecutableResource.from(ResEdit.NtExecutable.from(await fs.readFile(executable)));
    const version=ResEdit.Resource.VersionInfo.fromEntries(resources.entries)[0];assert.ok(version);
    const strings=version.getStringValues(version.getAllLanguagesForStringValues()[0]);
    for(const name of ['ProductName','FileDescription','CompanyName','InternalName'])assert.equal(strings[name],'GO ERP');
    const group=ResEdit.Resource.IconGroupEntry.fromEntries(resources.entries).find(value=>value.id===1);assert.ok(group);
    const frames=iconBytes.readUInt16LE(4);assert.equal(frames,6);assert.equal(group.icons.length,frames);const iconImages=[];
    for(let i=0;i<frames;i++){
      const offset=6+i*16,width=iconBytes[offset]||256,height=iconBytes[offset+1]||256,length=iconBytes.readUInt32LE(offset+8),start=iconBytes.readUInt32LE(offset+12);
      const expected=iconBytes.subarray(start,start+length),item=group.icons.find(value=>(value.width||256)===width&&(value.height||256)===height);assert.ok(item);
      const resource=resources.entries.find(value=>value.type===3&&value.id===item.iconID&&value.lang===group.lang);assert.ok(resource);
      assert.deepEqual(Buffer.from(resource.bin),expected);iconImages.push({width,height,sha256:hash(expected)});
    }
    const branding={productName:strings.ProductName,fileDescription:strings.FileDescription,companyName:strings.CompanyName,internalName:strings.InternalName,
      executable:'GO ERP.exe',setup:'GO-ERP-Preview-Setup.exe',iconSha256:hash(iconBytes),iconImages,embeddedIconVerified:true,legacyPreviewUpgrade:true,unownedLegacyNamesRetained:true,lockedLegacyUpgradeBeforeExtraction:true};
    process.stdout.write('PASS the installed GO ERP executable embeds the official GO icon and Windows product metadata\n');
    const nativeOutput=execFileSync(executable,[path.join(__dirname,'generation-native.cjs'),native,
      path.join(project,'dist/dashboard-synthetic-snapshot.json'),path.join(installed,'resources/app.asar/src')],
      {encoding:'utf8',windowsHide:true,timeout:1200000,env:{...process.env,ELECTRON_RUN_AS_NODE:'1'}});
    assert.match(nativeOutput,/PASS real Windows supervisor prepares account data/);
    process.stdout.write(nativeOutput);
    process.stdout.write('PASS the actually installed ASAR supervisor runs the original local Laravel dashboard and preserves its data across restart and code upgrade\n');
    const saved=JSON.parse(execFileSync(executable,[probe,installed,profile],{encoding:'utf8',windowsHide:true,timeout:30000,
      env:{...process.env,ELECTRON_RUN_AS_NODE:'1'}}).trim());
    process.stdout.write('PASS installed Electron loads its actual ASAR ledger code and preserves one paid unsynced sale across SQLite reopen\n');
    const automaticUpgradeChecks=await automaticUpgrade({setup,installed,profile,root,powershell,registrationPath,executable,saved});
    // NSIS launches a self-copying worker. The launcher's exit and the early
    // executable deletion do not mean its ~52,000 exact removals have finished.
    const removalStarted=performance.now(),removalDeadline=removalStarted+600000;
    execFileSync(path.join(installed,'Uninstall.exe'),['/S'],{windowsHide:true,timeout:120000});
    let nextReport=removalStarted+30000;
    for(;;){
      const remaining=[];
      for(const file of [executable,path.join(installed,'resources')]){
        try{await fs.access(file);remaining.push(path.relative(installed,file));}
        catch(error){if(error.code!=='ENOENT')throw error;}
      }
      if(!remaining.length)break;
      const now=performance.now();
      if(now>=nextReport||now>=removalDeadline){
        const detail=await residualPaths(path.join(installed,'resources'));
        process.stdout.write('Windows uninstall remaining paths '+JSON.stringify({elapsedSeconds:Math.round((now-removalStarted)/1000),remaining,...detail})+'\n');
        nextReport=now+30000;
      }
      if(now>=removalDeadline)throw Error('The actual uninstaller did not remove its executable and packaged resources within ten minutes.');
      await pause(250);
    }
    assert.equal(await fs.readFile(path.join(installed,'KEEP-MY-FILE.txt'),'utf8'),'unrelated existing file');
    const db=new DatabaseSync(path.join(profile,'unsynced-orders.sqlite'),{readOnly:true});
    try{const row=db.prepare('SELECT id,acked,payload FROM outbox').get();assert.equal(row.id,saved.event);assert.equal(row.acked,0);assert.equal(JSON.parse(row.payload).data.total_cents,saved.total);
      assert.equal(db.prepare('SELECT status FROM orders WHERE id=?').get(saved.order).status,'paid');}finally{db.close();}
    process.stdout.write('PASS actual Windows uninstallation removes only packaged files and retains unrelated files plus the paid unsynced order\n');
    assert.deepEqual(await installerFingerprint(setup),acceptedInstaller,'The setup executable changed during actual Windows acceptance.');
    await fs.writeFile(path.join(project,'dist/installer-checks.json'),JSON.stringify({format:1,sourceRevision:receipt.sourceRevision,version:receipt.version,
      channel:receipt.channel,fullDashboard:false,installation:true,branding,installer:acceptedInstaller,automaticUpgrade:automaticUpgradeChecks,
      prerequisite:{registered:true,installedVersion:vcVersion,bundledVersion:vcPolicy.version,bundledSha256:vcPayload.sha256,signatureValid:true,
        rebootRequired:setupRebootRequired,failureBeforeExtraction:prerequisiteFailureBeforeExtraction},
      installedNativeExecutables:true,installedDashboardRecovery:true,packagedLedger:true,uninstallRetention:true},null,2)+'\n');
  } finally {
    if(ownsFixturePaths){
      spawnSync('reg.exe',['delete',registrationPath,'/f'],{windowsHide:true});
      for(const item of [legacyDesktop,legacyMenu,path.join(folders.desktop,'GO ERP.lnk'),path.join(folders.programs,'GO ERP')])
        await fs.rm(item,{recursive:true,force:true});
    }
    await fs.rm(root,{recursive:true,force:true});
  }
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
