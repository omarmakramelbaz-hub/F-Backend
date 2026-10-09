'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const packaging=require('../installer-runtime.cjs'),source=require('../src/dashboard-source.cjs');
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
async function fixture(t){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-installer-'));
  t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const project=path.join(root,'desktop-pos'),bundle=path.join(project,'runtime-bundle'),out=path.join(root,'installed');
  const write=async(name,value)=>{const file=path.join(root,name);await fs.mkdir(path.dirname(file),{recursive:true});await fs.writeFile(file,value);};
  await write('desktop-pos/package.json',JSON.stringify({name:'fixture',version:'0.3.0-preview.1'}));
  execFileSync('git',['init'],{cwd:root,stdio:'ignore'});execFileSync('git',['add','.'],{cwd:root});
  execFileSync('git',['-c','user.name=Fixture','-c','user.email=fixture@test.invalid','commit','-m','synthetic installer fixture'],{cwd:root,stdio:'ignore'});
  const revision=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),prefix='desktop-pos/runtime-bundle/';
  const original={'artisan':'<?php /* original application */','desktop/router.php':'<?php /* private router */','desktop/verify.php':'<?php /* verify */','app/Business.php':'<?php /* business service */'};
  for(const [name,bytes]of Object.entries(original))await write(prefix+'application/'+name,bytes);
  const lock=JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]});
  await write(prefix+'application/composer.lock',lock);await write(prefix+'application/composer.json','{}');await write(prefix+'application/vendor/autoload.php','<?php');
  await write(prefix+'php/php.ini','extension=php_pdo_mysql.dll\n');
  // Synthetic PE headers test the package validator, not executable process behavior.
  const exe=Buffer.alloc(128);exe.write('MZ');exe.writeUInt32LE(64,60);exe.write('PE\0\0',64);exe.writeUInt16LE(0x8664,68);exe.writeUInt16LE(0x20b,88);
  for(const name of ['php/php.exe','mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe'])await write(prefix+name,exe);
  for(const name of ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'])await write(prefix+'php/ext/php_'+name+'.dll','synthetic fixture DLL');
  const manifest={format:1,platform:'win32-x64',sourceRevision:revision,sourceHashes:Object.fromEntries(Object.entries(original).map(([name,bytes])=>[name,sha(bytes)])),sourceFingerprint:await source.fingerprint(path.join(bundle,'application'))};
  await write(prefix+'manifest.json',JSON.stringify(manifest));
  const audit={format:1,lockSha256:sha(lock),result:{advisories:{'laravel/framework':[{advisoryId:'synthetic-test-advisory'}]}}};
  await write('desktop-pos/dist/runtime-audit.json',JSON.stringify(audit));
  return {root,project,bundle,out,manifest,audit,write,context:{electronPlatformName:'win32',arch:1,packager:{projectDir:project,appInfo:{productName:'Fasakhansta Dashboard Preview'}},appOutDir:out}};
}
test('the preview installer retains every verified runtime file and records its incomplete release status',async t=>{
  const f=await fixture(t);await packaging(f.context);
  await fs.mkdir(path.join(f.out,'resources'),{recursive:true});await fs.cp(f.bundle,path.join(f.out,'resources/dashboard-runtime'),{recursive:true});
  await packaging.afterPack(f.context);
  const receipt=JSON.parse(await fs.readFile(path.join(f.out,'resources/desktop-build.json'),'utf8'));
  assert.equal(receipt.channel,'preview');assert.equal(receipt.fullDashboard,false);assert.equal(receipt.advisoryCount,1);assert.equal(receipt.sourceRevision,f.manifest.sourceRevision);
  assert.ok(receipt.files['application/desktop/router.php']);assert.ok(receipt.files['php/php.exe']);assert.ok(receipt.files['mariadb/bin/mariadbd.exe']);
  assert.match(await fs.readFile(path.join(f.project,'dist/installer-version.nsh'),'utf8'),/0\.3\.0-preview\.1/);
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
test('changing a preview version into a release cannot waive incomplete checks or known advisories',async t=>{
  const f=await fixture(t);await f.write('desktop-pos/package.json',JSON.stringify({version:'0.3.0'}));
  const review={sourceRevision:f.manifest.sourceRevision,sourceFingerprint:f.manifest.sourceFingerprint,lockSha256:f.audit.lockSha256,
    fullDashboard:true,dependencies:true,pdf:true,installation:true,updates:true,conflicts:true};
  await f.write('desktop-pos/dist/release-review.json',JSON.stringify(review));
  await assert.rejects(packaging(f.context),/not passed its release checks/);
  await f.write('desktop-pos/dist/runtime-audit.json',JSON.stringify({...f.audit,result:{advisories:{}}}));
  await f.write('desktop-pos/dist/release-review.json',JSON.stringify({...review,fullDashboard:false}));
  await assert.rejects(packaging(f.context),/not passed its release checks/);
});
