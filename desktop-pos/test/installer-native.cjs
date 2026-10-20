'use strict';
// Real NSIS installation in a disposable Windows directory; no production account or database.
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {execFileSync,spawnSync,spawn}=require('node:child_process'),{DatabaseSync}=require('node:sqlite');
const packaging=require('../installer-runtime.cjs');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
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
  const prerequisiteFailureBeforeExtraction=await require('./installer-prerequisite-native.cjs')(project);
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'fasakhansta-installer-')),installed=path.join(root,'تجربة البرنامج'),profile=path.join(root,'retained-user-data');
  const powershell=path.join(process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe');
  const registrationPath='HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\FasakhanstaDashboardPreview';
  const folders=JSON.parse(execFileSync(powershell,['-NoProfile','-NonInteractive','-Command',
    '[pscustomobject]@{desktop=[Environment]::GetFolderPath("DesktopDirectory");programs=[Environment]::GetFolderPath("Programs")} | ConvertTo-Json -Compress'],
    {windowsHide:true,encoding:'utf8',timeout:30000}));
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
    await fs.writeFile(path.join(project,'dist/installer-checks.json'),JSON.stringify({format:1,sourceRevision:receipt.sourceRevision,version:receipt.version,
      channel:receipt.channel,fullDashboard:false,installation:true,branding,
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
