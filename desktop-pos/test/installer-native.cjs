'use strict';
// Real NSIS installation in a disposable Windows directory; no production account or database.
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process'),{DatabaseSync}=require('node:sqlite');
const packaging=require('../installer-runtime.cjs');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
  assert.equal(process.platform,'win32','Actual installer checks require Windows.');
  const project=path.resolve(__dirname,'..'),setup=path.resolve(process.argv[2]||path.join(project,'dist/Fasakhansta-Dashboard-Preview-Setup.exe'));
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'fasakhansta-installer-')),installed=path.join(root,'تجربة البرنامج'),profile=path.join(root,'retained-user-data');
  try {
    await fs.mkdir(installed);await fs.mkdir(profile);await fs.writeFile(path.join(installed,'KEEP-MY-FILE.txt'),'unrelated existing file');
    // NSIS documents /D= as the last, unquoted argument; no command shell is involved.
    execFileSync(setup,['/S','/D='+installed],{windowsHide:true,windowsVerbatimArguments:true,timeout:300000});
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
    const phpArgs=['-n','-d','extension_dir='+path.join(native,'php/ext'),...extensions.flatMap(name=>['-d','extension='+name]),'-r',
      'foreach('+JSON.stringify(extensions).replace('[','array(').replace(']',')')+' as $module) { if (!extension_loaded($module)) {fwrite(STDERR,$module); exit(1);} } echo "installed extensions ready";'];
    assert.equal(execFileSync(php,phpArgs,{encoding:'utf8',windowsHide:true}),'installed extensions ready');
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
    const executable=path.join(installed,'Fasakhansta Dashboard Preview.exe');
    const nativeOutput=execFileSync(executable,[path.join(__dirname,'generation-native.cjs'),native,
      path.join(project,'dist/dashboard-synthetic-snapshot.json'),path.join(installed,'resources/app.asar/src')],
      {encoding:'utf8',windowsHide:true,timeout:600000,env:{...process.env,ELECTRON_RUN_AS_NODE:'1'}});
    assert.match(nativeOutput,/PASS real Windows supervisor prepares account data/);
    process.stdout.write(nativeOutput);
    process.stdout.write('PASS the actually installed ASAR supervisor runs the original local Laravel dashboard and preserves its data across restart and code upgrade\n');
    const saved=JSON.parse(execFileSync(executable,[probe,installed,profile],{encoding:'utf8',windowsHide:true,timeout:30000,
      env:{...process.env,ELECTRON_RUN_AS_NODE:'1'}}).trim());
    process.stdout.write('PASS installed Electron loads its actual ASAR ledger code and preserves one paid unsynced sale across SQLite reopen\n');
    execFileSync(path.join(installed,'Uninstall.exe'),['/S'],{windowsHide:true,timeout:120000});
    for(let n=0;n<1200;n++) {
      try {await fs.access(executable);}
      catch(error){if(error.code==='ENOENT')break;throw error;}
      if(n===1199)throw Error('The actual uninstaller did not complete.');await pause(100);
    }
    for(let n=0;n<1200;n++) {
      try {await fs.access(path.join(installed,'resources'));}
      catch(error){if(error.code==='ENOENT')break;throw error;}
      if(n===1199)throw Error('The actual uninstaller left packaged resources.');await pause(100);
    }
    assert.equal(await fs.readFile(path.join(installed,'KEEP-MY-FILE.txt'),'utf8'),'unrelated existing file');
    const db=new DatabaseSync(path.join(profile,'unsynced-orders.sqlite'),{readOnly:true});
    try{const row=db.prepare('SELECT id,acked,payload FROM outbox').get();assert.equal(row.id,saved.event);assert.equal(row.acked,0);assert.equal(JSON.parse(row.payload).data.total_cents,saved.total);
      assert.equal(db.prepare('SELECT status FROM orders WHERE id=?').get(saved.order).status,'paid');}finally{db.close();}
    process.stdout.write('PASS actual Windows uninstallation removes only packaged files and retains unrelated files plus the paid unsynced order\n');
    await fs.writeFile(path.join(project,'dist/installer-checks.json'),JSON.stringify({format:1,sourceRevision:receipt.sourceRevision,version:receipt.version,
      channel:receipt.channel,fullDashboard:false,installation:true,installedNativeExecutables:true,installedDashboardRecovery:true,packagedLedger:true,uninstallRetention:true},null,2)+'\n');
  } finally {await fs.rm(root,{recursive:true,force:true});}
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
