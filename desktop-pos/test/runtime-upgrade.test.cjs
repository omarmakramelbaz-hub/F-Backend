'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises');
const path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const LocalRuntime=require('../src/local-runtime.cjs'),RuntimeArchive=require('../src/runtime-archive.cjs'),source=require('../src/dashboard-source.cjs');
async function fixture(t){
  const temporary=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-upgrade-'));t.after(()=>fs.rm(temporary,{recursive:true,force:true}));
  const root=path.join(temporary,'بيانات المستخدم~1');await fs.mkdir(root);
  const records=new Map(),metadata={read:async key=>records.get(key)||null,write:async(key,value)=>records.set(key,structuredClone(value))};
  const make=async(revision,rule,database='same database bytes')=>{
    const bundle=path.join(root,revision),application=path.join(bundle,'application');
    for(const folder of ['application/app','php/ext','php/ssl','mariadb/bin'])await fs.mkdir(path.join(bundle,folder),{recursive:true});
    await fs.writeFile(path.join(application,'app/rule.php'),rule);
    await fs.writeFile(path.join(application,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
    await fs.writeFile(path.join(bundle,'php/php.exe'),'synthetic PHP executable');
    await fs.writeFile(path.join(bundle,'php/php.ini'),'extension=C:/build/php/ext/fixture.dll\nzend_extension=C:/build/php/ext/fixture-zend.dll\n');
    await fs.writeFile(path.join(bundle,'php/ext/fixture.dll'),'synthetic PHP extension');
    await fs.writeFile(path.join(bundle,'php/ext/fixture-zend.dll'),'synthetic Zend extension');
    await fs.copyFile(path.join(__dirname,'fixtures/synthetic-ca.pem'),path.join(bundle,'php/ssl/cacert.pem'));
    for(const file of ['mariadbd.exe','mariadb-install-db.exe','runtime.dll'])await fs.writeFile(path.join(bundle,'mariadb/bin',file),database);
    const manifest={format:1,platform:'win32-x64',sourceRevision:revision,sourceHashes:{'app/rule.php':crypto.createHash('sha256').update(rule).digest('hex')},sourceFingerprint:await source.fingerprint(application)};
    await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify(manifest));return {bundle,manifest};
  };
  const old=await make('a'.repeat(40),'old business rule'),next=await make('b'.repeat(40),'new business rule');
  const runtime=new LocalRuntime({bundle:next.bundle,profile:root,safeStorage:{}});runtime.metadata=metadata;
  const archive=new RuntimeArchive(runtime.profile,metadata);runtime.bundle=await archive.retain(old.bundle);
  runtime.manifest=old.manifest;runtime.sourceFingerprint=old.manifest.sourceFingerprint;
  runtime.application=path.join(runtime.bundle,'application');runtime.php=path.join(runtime.bundle,'php/php.exe');runtime.phpIni=path.join(root,'old.ini');runtime.environment={};
  records.set('prepared',{database:'retained business database',sourceRevision:old.manifest.sourceRevision});
  return {root,records,make,runtime,next,old};
}
test('a matching installed code upgrade is retained and verified without changing the active application or database',async t=>{
  const f=await fixture(t),context=await f.runtime.candidate({source:f.next.manifest.sourceFingerprint});
  assert.equal(context.manifest.sourceRevision,f.next.manifest.sourceRevision);assert.ok(source.same(context.sourceFingerprint,f.next.manifest.sourceFingerprint));
  assert.notEqual(context.bundle,f.next.bundle);assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.get('prepared').database,'retained business database');
  const ini=await fs.readFile(context.phpIni,'utf8');
  const settings=Object.fromEntries([...ini.matchAll(/^([a-z_.]+)\s*=\s*"?([^"\r\n]+)"?\s*$/gm)].map(match=>[match[1],match[2]]));
  for(const [name,file]of [['extension_dir','php/ext'],['extension','php/ext/fixture.dll'],['zend_extension','php/ext/fixture-zend.dll']]){
    assert.ok(!path.isAbsolute(settings[name]));assert.doesNotMatch(settings[name],/[^\x20-\x7e]/);
    assert.equal(await fs.realpath(path.resolve(context.application,settings[name])),await fs.realpath(path.join(context.bundle,file)));
  }
  for(const name of ['curl.cainfo','openssl.cafile']){
    assert.ok(path.isAbsolute(settings[name]));
    assert.equal(await fs.realpath(settings[name]),await fs.realpath(path.join(context.bundle,'php/ssl/cacert.pem')));
  }
  const args=LocalRuntime.phpArguments(context);assert.equal(args[0],'-c');
  assert.ok(!path.isAbsolute(args[1]));assert.doesNotMatch(args[1],/[^\x20-\x7e]/);
  assert.equal(await fs.readFile(path.resolve(context.application,args[1]),'utf8'),ini);
  assert.deepEqual(await fs.readFile(path.join(context.bundle,'php/ssl/cacert.pem')),await fs.readFile(path.join(__dirname,'fixtures/synthetic-ca.pem')));
  for(const setting of ['upload_max_filesize=5M','post_max_size=12M','memory_limit=256M'])assert.ok(ini.includes(setting));
});
test('changed MariaDB executables or DLLs cannot stage installed code against the retained database directory',async t=>{
  const f=await fixture(t),different=await f.make('c'.repeat(40),'new business rule','changed database binary');f.runtime.installedBundle=different.bundle;
  await assert.rejects(f.runtime.stage({source:different.manifest.sourceFingerprint,media:[]},'d'.repeat(16)),/ترقية ملفات قاعدة البيانات/);
  assert.equal(f.records.get('prepared').database,'retained business database');await assert.rejects(fs.access(path.join(f.runtime.profile,'generations')));
});
test('unknown server code and damaged installed source retain the old journal and active runtime',async t=>{
  const f=await fixture(t);await assert.rejects(f.runtime.candidate({source:{...f.next.manifest.sourceFingerprint,sha256:'f'.repeat(64)}}),/نسخة البرنامج/);
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  const damaged=await f.make('c'.repeat(40),'third rule');await fs.writeFile(path.join(damaged.bundle,'application/app/rule.php'),'damaged');f.runtime.installedBundle=damaged.bundle;
  await assert.rejects(f.runtime.candidate({source:damaged.manifest.sourceFingerprint}));assert.equal(f.records.get('prepared').database,'retained business database');
});
test('an absent database-binary receipt cannot authorize application upgrade compatibility',()=>{
  assert.throws(()=>RuntimeArchive.databaseCompatible(null,{files:{}}),/لم تتأكد/);
});
async function guiInstalled(f,revision='c'.repeat(40),database){
  const installed=await f.make(revision,'old business rule',database);
  const name='public/js/dashboard-fixture.js',bytes='new dashboard GUI();\n';
  await fs.mkdir(path.join(installed.bundle,'application/public/js'),{recursive:true});
  await fs.writeFile(path.join(installed.bundle,'application',name),bytes);
  installed.manifest.sourceHashes[name]=crypto.createHash('sha256').update(bytes).digest('hex');
  await fs.writeFile(path.join(installed.bundle,'manifest.json'),JSON.stringify(installed.manifest));
  assert.ok(source.same(installed.manifest.sourceFingerprint,f.old.manifest.sourceFingerprint));
  f.runtime.installedBundle=installed.bundle;return installed;
}
test('a GUI-only installed revision promotes through coverage and candidate only with fresh server authority',async t=>{
  const f=await fixture(t),installed=await guiInstalled(f),calls=[];
  f.runtime.authorizeInstalled=async manifest=>{calls.push(manifest.sourceRevision);return true;};
  const snapshot={source:f.old.manifest.sourceFingerprint};
  assert.equal((await f.runtime.coverageManifest(snapshot)).sourceRevision,installed.manifest.sourceRevision);
  const candidate=await f.runtime.candidate(snapshot);
  assert.equal(candidate.manifest.sourceRevision,installed.manifest.sourceRevision);
  assert.deepEqual(calls,[installed.manifest.sourceRevision,installed.manifest.sourceRevision,installed.manifest.sourceRevision]);
  assert.equal(await fs.readFile(path.join(candidate.application,'public/js/dashboard-fixture.js'),'utf8'),'new dashboard GUI();\n');
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.get('prepared').database,'retained business database');
});
test('manual older or unapproved same-core installers retain the active manifest and runtime',async t=>{
  for(const authority of [undefined,async()=>false,async()=>'approved']){
    const f=await fixture(t),installed=await guiInstalled(f,'0'.repeat(40));
    if(authority)f.runtime.authorizeInstalled=authority;
    const snapshot={source:f.old.manifest.sourceFingerprint};
    assert.equal(await f.runtime.coverageManifest(snapshot),f.old.manifest);
    assert.equal((await f.runtime.candidate(snapshot)).bundle,f.runtime.bundle);
    assert.equal(f.records.has('runtimes/'+installed.manifest.sourceRevision),false);
    assert.equal(f.records.get('prepared').sourceRevision,f.old.manifest.sourceRevision);
  }
});
test('offline authorization and unreadable installed metadata retain the active source without cached permission',async t=>{
  const f=await fixture(t),installed=await guiInstalled(f),snapshot={source:f.old.manifest.sourceFingerprint};
  let allowed=true,calls=0;
  f.runtime.authorizeInstalled=async()=>{calls++;if(!allowed)throw Error('offline');return true;};
  assert.equal((await f.runtime.coverageManifest(snapshot)).sourceRevision,installed.manifest.sourceRevision);
  allowed=false;
  assert.equal(await f.runtime.coverageManifest(snapshot),f.old.manifest);
  assert.equal((await f.runtime.candidate(snapshot)).bundle,f.runtime.bundle);
  assert.equal(calls,3);
  await fs.unlink(path.join(installed.bundle,'manifest.json'));
  assert.equal(await f.runtime.coverageManifest(snapshot),f.old.manifest);
  assert.equal((await f.runtime.candidate(snapshot)).bundle,f.runtime.bundle);
  assert.equal(calls,3);
  assert.equal(f.records.get('prepared').database,'retained business database');
});
test('revoking GUI publication after retention prevents candidate promotion',async t=>{
  const f=await fixture(t);await guiInstalled(f);let calls=0;
  f.runtime.authorizeInstalled=async()=>++calls===1;
  const context=await f.runtime.candidate({source:f.old.manifest.sourceFingerprint});
  assert.equal(calls,2);assert.equal(context.bundle,f.runtime.bundle);
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.get('prepared').database,'retained business database');
});
test('GUI promotion still verifies installed source and preserves the old journal on corruption',async t=>{
  const f=await fixture(t),installed=await guiInstalled(f);f.runtime.authorizeInstalled=async()=>true;
  await fs.writeFile(path.join(installed.bundle,'application/public/js/dashboard-fixture.js'),'damaged');
  await assert.rejects(f.runtime.candidate({source:f.old.manifest.sourceFingerprint}),/مصدر الداشبورد/);
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.get('prepared').database,'retained business database');
});
test('GUI-only promotion cannot change MariaDB binaries under an existing database',async t=>{
  const f=await fixture(t);await guiInstalled(f,'c'.repeat(40),'incompatible database binary');f.runtime.authorizeInstalled=async()=>true;
  await assert.rejects(f.runtime.candidate({source:f.old.manifest.sourceFingerprint}),/ترقية ملفات قاعدة البيانات/);
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.get('prepared').database,'retained business database');
});
test('unchanged revision and changed-core paths preserve their previous authorization behavior',async t=>{
  const f=await fixture(t);let calls=0;f.runtime.authorizeInstalled=async()=>{calls++;throw Error('must not authorize these paths');};
  f.runtime.installedBundle=f.old.bundle;
  const snapshot={source:f.old.manifest.sourceFingerprint};
  assert.equal(await f.runtime.coverageManifest(snapshot),f.old.manifest);
  assert.equal((await f.runtime.candidate(snapshot)).bundle,f.runtime.bundle);
  f.runtime.installedBundle=f.next.bundle;
  assert.equal((await f.runtime.coverageManifest({source:f.next.manifest.sourceFingerprint})).sourceRevision,f.next.manifest.sourceRevision);
  assert.equal((await f.runtime.candidate({source:f.next.manifest.sourceFingerprint})).manifest.sourceRevision,f.next.manifest.sourceRevision);
  assert.equal(calls,0);
});
