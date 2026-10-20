'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const packaging=require('../installer-runtime.cjs'),source=require('../src/dashboard-source.cjs');
const nativePdf=require('../src/native-pdf.cjs');
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
async function fixture(t){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-installer-'));
  t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const project=path.join(root,'desktop-pos'),bundle=path.join(project,'runtime-bundle'),out=path.join(root,'installed');
  const write=async(name,value)=>{const file=path.join(root,name);await fs.mkdir(path.dirname(file),{recursive:true});await fs.writeFile(file,value);};
  await write('desktop-pos/package.json',JSON.stringify({name:'fixture',version:'0.3.0-preview.1'}));
  await write('.gitignore','desktop-pos/runtime-bundle/\ndesktop-pos/dist/\ninstalled/\n');
  const prefix='desktop-pos/runtime-bundle/';
  const original={'artisan':'<?php /* original application */','desktop/router.php':'<?php /* private router */','desktop/verify.php':'<?php /* verify */','app/Business.php':'<?php /* business service */'};
  for(const [name,bytes]of Object.entries(original)){await write(name,bytes);await write(prefix+'application/'+name,bytes);}
  execFileSync('git',['init'],{cwd:root,stdio:'ignore'});
  const commit=()=>{
    execFileSync('git',['add','.'],{cwd:root});
    execFileSync('git',['-c','user.name=Fixture','-c','user.email=fixture@test.invalid','commit','-m','synthetic installer fixture'],{cwd:root,stdio:'ignore'});
    return execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  };
  const revision=commit();
  const lock=JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]});
  await write(prefix+'application/composer.lock',lock);await write(prefix+'application/composer.json','{}');await write(prefix+'application/vendor/autoload.php','<?php');
  await write(prefix+'application/vendor/composer/installed.json',JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
  await write(prefix+'php/php.ini','extension=php_pdo_mysql.dll\n');
  await write(prefix+'php/ssl/cacert.pem',await fs.readFile(path.join(__dirname,'fixtures/synthetic-ca.pem')));
  // Synthetic PE headers test the package validator, not executable process behavior.
  const exe=Buffer.alloc(128);exe.write('MZ');exe.writeUInt32LE(64,60);exe.write('PE\0\0',64);exe.writeUInt16LE(0x8664,68);exe.writeUInt16LE(0x20b,88);
  for(const name of ['php/php.exe','mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe'])await write(prefix+name,exe);
  for(const name of ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'])await write(prefix+'php/ext/php_'+name+'.dll','synthetic fixture DLL');
  const manifest={format:1,platform:'win32-x64',sourceRevision:revision,runtimeFramework:'8.83.29',sourceHashes:Object.fromEntries(Object.entries(original).map(([name,bytes])=>[name,sha(bytes)])),sourceFingerprint:await source.fingerprint(path.join(bundle,'application'))};
  await write(prefix+'manifest.json',JSON.stringify(manifest));
  const audit={format:1,lockSha256:sha(lock),result:{advisories:{'laravel/framework':[{advisoryId:'synthetic-test-advisory'}]}}};
  await write('desktop-pos/dist/runtime-audit.json',JSON.stringify(audit));
  return {root,project,bundle,out,manifest,audit,write,commit,context:{electronPlatformName:'win32',arch:1,packager:{projectDir:project,appInfo:{productName:'Fasakhansta Dashboard Preview'}},appOutDir:out}};
}
test('the preview installer retains every verified runtime file and records its incomplete release status',async t=>{
  const f=await fixture(t);await packaging(f.context);
  await fs.mkdir(path.join(f.out,'resources'),{recursive:true});await fs.cp(f.bundle,path.join(f.out,'resources/dashboard-runtime'),{recursive:true});
  await packaging.afterPack(f.context);
  const receipt=JSON.parse(await fs.readFile(path.join(f.out,'resources/desktop-build.json'),'utf8'));
  assert.equal(receipt.channel,'preview');assert.equal(receipt.fullDashboard,false);assert.equal(receipt.advisoryCount,1);assert.equal(receipt.sourceRevision,f.manifest.sourceRevision);
  assert.equal(receipt.runtimeFramework,'8.83.29');
  assert.ok(receipt.files['application/desktop/router.php']);assert.ok(receipt.files['php/php.exe']);assert.ok(receipt.files['mariadb/bin/mariadbd.exe']);
  assert.match(await fs.readFile(path.join(f.project,'dist/installer-version.nsh'),'utf8'),/0\.3\.0-preview\.1/);
});
test('the complete custom NSIS script owns only its version commands while builder callbacks and other targets retain their options',async t=>{
  const f=await fixture(t);await packaging(f.context);
  await fs.copyFile(path.resolve(__dirname,'../installer.nsi'),path.join(f.project,'installer.nsi'));
  const vm=require('node:vm'),{createRequire}=require('node:module');
  const hookFile=path.resolve(__dirname,'../../.github/scripts/desktop-runtime-copy-hook.cjs');
  const localRequire=createRequire(hookFile),module={exports:{}};
  const overrides={
    'node:fs/promises':{...fs,access:async file=>{
      if(file==='R:\\'){const error=Error('Unused test drive');error.code='ENOENT';throw error;}
      return fs.access(file);
    }},
    'node:child_process':{execFileSync(command,args){assert.equal(command,'subst.exe');assert.deepEqual(Array.from(args),['R:',f.out]);}},
    './installer-runtime.cjs':packaging,
    './build-uninstaller.cjs':async()=>fs.writeFile(path.join(f.project,'dist/uninstall-files.nsh'),'Delete "$INSTDIR\\app.exe"\n')
  };
  vm.runInNewContext('(function(require,module,exports){\n'+await fs.readFile(hookFile,'utf8')+'\n})',
    {process:{stdout:{write(){}}}},{filename:hookFile})(name=>Object.hasOwn(overrides,name)?overrides[name]:localRequire(name),module,module.exports);
  const hook=module.exports,packager=f.context.packager;
  packager.appInfo.id='com.example.reviewed-preview';
  const calls=[],skip={skip:true};let priorResult=false;
  const previous=async function(...args){assert.equal(this,packager.packagerOptions);calls.push(args);return priorResult;};
  packager.packagerOptions={effectiveOptionComputed:previous};
  // The normal builder script and a foreign full script are not intercepted.
  for(const script of [undefined,'another-installer.nsi']){
    packager.config={nsis:{script}};await hook(f.context);
    assert.equal(packager.packagerOptions.effectiveOptionComputed,previous);
  }
  packager.config.nsis.script=path.join(f.project,'installer-windows.nsi');await hook(f.context);
  const callback=packager.packagerOptions.effectiveOptionComputed;
  const packet=()=>[{
    PROJECT_DIR:f.project,PRODUCT_NAME:packager.appInfo.productName,APP_ID:packager.appInfo.id,
    APP_INSTALLER_STORE_FILE:'preview\\installer.exe',MUI_ICON:'official.ico',APP_64:'verified.7z',COMPRESS:'auto'
  },{OutFile:'"Setup.exe"',VIProductVersion:'0.3.0.0',VIAddVersionKey:['/LANG=1033 ProductName "builder default"'],
    Unicode:true,SetCompressor:'zlib',Icon:'"official.ico"',OtherCommand:'preserve'}];
  const original=packet(),expected=structuredClone(original);
  delete expected[1].VIProductVersion;delete expected[1].VIAddVersionKey;
  assert.equal(await callback.call(packager.packagerOptions,original,'extra'),false);
  assert.deepEqual(original,expected);assert.equal(calls.at(-1)[0],original);assert.equal(calls.at(-1)[1],'extra');
  // A prior callback's skip result and errors keep their semantics.
  priorResult=skip;const skipped=packet(),skippedBefore=structuredClone(skipped);
  assert.equal(await callback.call(packager.packagerOptions,skipped),skip);assert.deepEqual(skipped,skippedBefore);
  priorResult=false;
  for(const mutate of [
    p=>p[0].PROJECT_DIR=f.root,p=>p[0].APP_ID='another.app',p=>p[0].PRODUCT_NAME='Foreign product',
    p=>{delete p[0].APP_INSTALLER_STORE_FILE;},p=>p[0].APP_PACKAGE_STORE_FILE='web.7z',
    p=>p[0].REQUEST_EXECUTION_LEVEL='user',p=>p.push('unrelated packet'),p=>p[1]='unrelated command packet'
  ]){
    const foreign=packet();mutate(foreign);const before=structuredClone(foreign);
    assert.equal(await callback.call(packager.packagerOptions,foreign),false);assert.deepEqual(foreign,before);
  }
  const unrelated={options:'not NSIS'};assert.equal(await callback.call(packager.packagerOptions,unrelated),false);
  assert.deepEqual(unrelated,{options:'not NSIS'});
  packager.packagerOptions.effectiveOptionComputed=async()=>{throw Error('prior callback failure');};
  await hook(f.context);const refused=packet(),refusedBefore=structuredClone(refused);
  await assert.rejects(packager.packagerOptions.effectiveOptionComputed(refused),/prior callback failure/);
  assert.deepEqual(refused,refusedBefore);
});
test('the installed framework must agree with the exact lock and actual engine metadata',async t=>{
  const f=await fixture(t);
  await f.write('desktop-pos/runtime-bundle/application/vendor/composer/installed.json',JSON.stringify({packages:[{name:'laravel/framework',version:'v12.70.0'}]}));
  await assert.rejects(packaging(f.context),/installed and locked engine/);
  await f.write('desktop-pos/runtime-bundle/application/vendor/composer/installed.json',JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
  f.manifest.runtimeFramework='12.70.0';await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
  await assert.rejects(packaging(f.context),/installed and locked engine/);
});
test('runtime compatibility code is bound to its exact Git source independently of the application contract',async t=>{
  const f=await fixture(t),original='<?php /* reviewed runtime compatibility */';
  await f.write('desktop-pos/runtime/compatibility.php',original);
  await f.write('desktop-pos/runtime-bundle/application/compatibility.php',original);
  await f.write('desktop-pos/runtime-bundle/application/composer.json',JSON.stringify({autoload:{files:['compatibility.php']}}));
  f.manifest.runtimeCompatibilitySha256=sha(original);f.manifest.sourceRevision=f.commit();
  await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));await packaging(f.context);
  await f.write('desktop-pos/runtime-bundle/application/compatibility.php','<?php /* modified compatibility behavior */');
  await assert.rejects(packaging(f.context),/compatibility source is missing or changed/);
  f.manifest.runtimeCompatibilitySha256=sha('<?php /* modified compatibility behavior */');
  await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
  await assert.rejects(packaging(f.context),/compatibility source does not match the independently verified Git checkout/);
  delete f.manifest.runtimeCompatibilitySha256;await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
  await assert.rejects(packaging(f.context),/compatibility source is missing or changed/);
});
test('a lost native DLL during resource copying cannot produce an accepted installer',async t=>{
  const f=await fixture(t);await packaging(f.context);await fs.mkdir(path.join(f.out,'resources'),{recursive:true});await fs.cp(f.bundle,path.join(f.out,'resources/dashboard-runtime'),{recursive:true});
  await fs.rm(path.join(f.out,'resources/dashboard-runtime/php/ext/php_sodium.dll'));
  await assert.rejects(packaging.afterPack(f.context),/missing php\/ext\/php_sodium.dll/);
});
test('modified original code and an incompatible native executable are refused before packing',async t=>{
  const f=await fixture(t);await f.write('desktop-pos/runtime-bundle/app-placeholder','extra harmless file');
  await f.write('desktop-pos/runtime-bundle/application/app/Business.php','<?php /* changed business behavior */');
  await assert.rejects(packaging(f.context),/original source changed/);
  await f.write('desktop-pos/runtime-bundle/application/app/Business.php','<?php /* business service */');
  await f.write('desktop-pos/runtime-bundle/php/php.exe','not a Windows executable');
  await assert.rejects(packaging(f.context),/not Windows x64/);
});
test('a dependency audit for another lock cannot authorize packaging',async t=>{
  const f=await fixture(t);await f.write('desktop-pos/dist/runtime-audit.json',JSON.stringify({...f.audit,lockSha256:'0'.repeat(64)}));
  await assert.rejects(packaging(f.context),/different lock/);
});
test('an application with Snappy configuration cannot ship without its pinned Windows PDF files',async t=>{
  const f=await fixture(t);
  await f.write('desktop-pos/runtime-bundle/application/config/snappy.php','<?php return [];');
  await assert.rejects(packaging.verifyBundle(f.bundle),/missing its pinned Windows PDF component/);
});
test('the installer binds the actual official Windows PDF component and rejects a removed native file',
  {skip:!process.env.DESKTOP_TEST_WKHTMLTOX},async t=>{
    const f=await fixture(t),configuration='<?php return [];';
    await f.write('config/snappy.php',configuration);
    await f.write('desktop-pos/runtime-bundle/application/config/snappy.php',configuration);
    f.manifest.sourceHashes['config/snappy.php']=sha(configuration);
    f.manifest.sourceFingerprint=await source.fingerprint(path.join(f.bundle,'application'));
    f.manifest.pdfRuntime=await nativePdf.packageInto(process.env.DESKTOP_TEST_WKHTMLTOX,f.bundle,path.resolve(__dirname,'../..'));
    f.manifest.sourceRevision=f.commit();await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
    await packaging(f.context);
    const receipt=JSON.parse(await fs.readFile(path.join(f.project,'dist/runtime-package.json'),'utf8'));
    assert.equal(receipt.pdfRuntime.archiveSha256,nativePdf.policy.archive.sha256);
    assert.equal(receipt.files['wkhtmltox/bin/wkhtmltopdf.exe'],nativePdf.policy.files['bin/wkhtmltopdf.exe']);
    await fs.rm(path.join(f.bundle,'wkhtmltox/bin/wkhtmltox.dll'));
    await assert.rejects(packaging.verifyBundle(f.bundle),/pinned official files/);
  });
test('changing a preview version into a release cannot waive incomplete checks or known advisories',async t=>{
  const f=await fixture(t);await f.write('desktop-pos/package.json',JSON.stringify({version:'0.3.0'}));
  f.manifest.sourceRevision=f.commit();await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
  const review={sourceRevision:f.manifest.sourceRevision,sourceFingerprint:f.manifest.sourceFingerprint,lockSha256:f.audit.lockSha256,
    fullDashboard:true,dependencies:true,pdf:true,installation:true,updates:true,conflicts:true};
  await f.write('desktop-pos/dist/release-review.json',JSON.stringify(review));
  await assert.rejects(packaging(f.context),/not passed its release checks/);
  await f.write('desktop-pos/dist/runtime-audit.json',JSON.stringify({...f.audit,result:{advisories:{}}}));
  await f.write('desktop-pos/dist/release-review.json',JSON.stringify({...review,fullDashboard:false}));
  await assert.rejects(packaging(f.context),/not passed its release checks/);
});
test('rewriting the bundle and its manifest cannot impersonate the independently verified Git source',async t=>{
  const f=await fixture(t),changed='<?php /* altered behavior with a forged matching manifest */';
  await f.write('desktop-pos/runtime-bundle/application/app/Business.php',changed);
  f.manifest.sourceHashes['app/Business.php']=sha(changed);
  f.manifest.sourceFingerprint=await source.fingerprint(path.join(f.bundle,'application'));
  await f.write('desktop-pos/runtime-bundle/manifest.json',JSON.stringify(f.manifest));
  await assert.rejects(packaging(f.context),/independently verified Git checkout/);
});
