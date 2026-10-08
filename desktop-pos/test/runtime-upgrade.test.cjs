'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises');
const path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const LocalRuntime=require('../src/local-runtime.cjs'),RuntimeArchive=require('../src/runtime-archive.cjs'),source=require('../src/dashboard-source.cjs');
async function fixture(t){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-upgrade-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const records=new Map(),metadata={read:async key=>records.get(key)||null,write:async(key,value)=>records.set(key,structuredClone(value))};
  const make=async(revision,rule,database='same database bytes')=>{
    const bundle=path.join(root,revision),application=path.join(bundle,'application');
    for(const folder of ['application/app','php/ext','mariadb/bin'])await fs.mkdir(path.join(bundle,folder),{recursive:true});
    await fs.writeFile(path.join(application,'app/rule.php'),rule);
    await fs.writeFile(path.join(application,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
    await fs.writeFile(path.join(bundle,'php/php.exe'),'synthetic PHP executable');await fs.writeFile(path.join(bundle,'php/php.ini'),'extension=fixture.dll\n');
    await fs.writeFile(path.join(bundle,'php/ext/fixture.dll'),'synthetic PHP extension');
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
  assert.ok((await fs.readFile(context.phpIni,'utf8')).includes(path.join(context.bundle,'php/ext').replaceAll('\\','/')));
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
