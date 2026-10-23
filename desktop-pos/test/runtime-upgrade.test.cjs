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

async function addDeltaEntry(installed){
  const name='desktop/delta.php',bytes='<?php echo "{}";\n';
  await fs.mkdir(path.join(installed.bundle,'application/desktop'),{recursive:true});
  await fs.writeFile(path.join(installed.bundle,'application',name),bytes);
  installed.manifest.sourceHashes[name]=crypto.createHash('sha256').update(bytes).digest('hex');
  installed.manifest.sourceFingerprint=await source.fingerprint(path.join(installed.bundle,'application'));
  await fs.writeFile(path.join(installed.bundle,'manifest.json'),JSON.stringify(installed.manifest));return installed;
}

function deltaActive(f){
  const previous={database:'fasakhansta_dashboard_stage_'+'a'.repeat(16),sourceRevision:f.old.manifest.sourceRevision,
    sourceFingerprint:structuredClone(f.old.manifest.sourceFingerprint),deviceId:crypto.randomUUID(),actorId:10,snapshotId:crypto.randomUUID(),schemaHash:'1'.repeat(64)};
  f.records.set('prepared',previous);f.runtime.connection=async()=>f.records.get('prepared');return previous;
}

test('an unpublished delta-capable installer cannot allocate a retained runtime or background database',async t=>{
  const f=await fixture(t),previous=deltaActive(f);await addDeltaEntry(f.next);f.runtime.authorizeInstalled=async()=>false;
  await assert.rejects(f.runtime.deltaContext(previous));
  assert.equal(f.records.has('runtimes/'+f.next.manifest.sourceRevision),false);assert.equal(f.records.has('delta-background'),false);
  assert.deepEqual(f.records.get('prepared'),previous);assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  await assert.rejects(fs.access(path.join(f.runtime.profile,'generations')));
});

test('an authorized delta context uses verified new code while retaining the active runtime and business database',async t=>{
  const f=await fixture(t),previous=deltaActive(f);await addDeltaEntry(f.next);const authorities=[];
  f.runtime.authorizeInstalled=async manifest=>{authorities.push(manifest.sourceRevision);return true;};
  const context=await f.runtime.deltaContext(previous);
  assert.equal(context.manifest.sourceRevision,f.next.manifest.sourceRevision);assert.ok(source.same(context.sourceFingerprint,f.next.manifest.sourceFingerprint));
  assert.notEqual(context.bundle,f.next.bundle);assert.equal(await fs.readFile(path.join(context.application,'desktop/delta.php'),'utf8'),'<?php echo "{}";\n');
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);assert.deepEqual(f.records.get('prepared'),previous);
  assert.equal(f.records.has('delta-background'),false);assert.deepEqual(authorities,[f.next.manifest.sourceRevision,f.next.manifest.sourceRevision]);
  await assert.rejects(fs.access(path.join(f.runtime.profile,'generations')));
});

test('delta execution refuses incompatible database binaries even when the installed application is authorized',async t=>{
  const f=await fixture(t),previous=deltaActive(f),different=await f.make('c'.repeat(40),'new business rule','incompatible MariaDB bytes');
  await addDeltaEntry(different);f.runtime.installedBundle=different.bundle;f.runtime.authorizeInstalled=async()=>true;
  await assert.rejects(f.runtime.deltaContext(previous),/ترقية ملفات قاعدة البيانات/);
  assert.deepEqual(f.records.get('prepared'),previous);assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  assert.equal(f.records.has('delta-background'),false);await assert.rejects(fs.access(path.join(f.runtime.profile,'generations')));
});

test('damaged delta source cannot inherit publication authority or execute against the active database',async t=>{
  const f=await fixture(t),previous=deltaActive(f);await addDeltaEntry(f.next);f.runtime.authorizeInstalled=async()=>true;
  await fs.writeFile(path.join(f.next.bundle,'application/desktop/delta.php'),'damaged unreviewed source');
  await assert.rejects(f.runtime.deltaContext(previous));assert.deepEqual(f.records.get('prepared'),previous);
  assert.equal(f.records.has('delta-background'),false);assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
  await assert.rejects(fs.access(path.join(f.runtime.profile,'generations')));
});

async function deltaRecoveryFixture(t,{candidate=true}={}){
  const f=await fixture(t),previous=deltaActive(f);await addDeltaEntry(f.next);f.runtime.authorizeInstalled=async()=>true;
  const context=await f.runtime.deltaContext(previous);
  const record={format:1,kind:'dashboard-delta-background',token:'2'.repeat(64),sourceRevision:context.manifest.sourceRevision,
    sourceFingerprint:context.sourceFingerprint,source_database:previous.database,capture_generation:'3'.repeat(16),
    capture_database:'fasakhansta_dashboard_stage_'+'3'.repeat(16),candidate_generation:candidate?'4'.repeat(16):null,
    candidate_database:candidate?'fasakhansta_dashboard_stage_'+'4'.repeat(16):null,device_id:previous.deviceId,actor_id:previous.actorId};
  f.records.set('delta-background',structuredClone(record));
  f.runtime.settings=async()=>({deviceId:previous.deviceId});
  const calls=[];
  for(const id of [record.capture_generation,record.candidate_generation].filter(Boolean)){
    const folder=path.join(f.runtime.profile,'generations',id);await fs.mkdir(folder,{recursive:true});await fs.writeFile(path.join(folder,'private-fixture'),'owned inactive work');
  }
  f.runtime.deltaDatabaseExists=async stage=>{
    calls.push('exists:'+stage.value.database);assert.notEqual(stage.value.database,f.records.get('prepared').database);
    assert.ok([record.capture_database,record.candidate_database].includes(stage.value.database));
    assert.equal(stage.context.manifest.sourceRevision,record.sourceRevision);return false;
  };
  f.runtime.createDeltaDatabase=async()=>{throw Error('Restart recovery must not allocate a database.');};
  const releaseStage=f.runtime.releaseDeltaStage.bind(f.runtime),releaseCapture=f.runtime.releaseDeltaCapture.bind(f.runtime);
  f.runtime.releaseDeltaStage=async(candidate,token)=>{calls.push('stage:'+candidate.database);assert.equal(token,record.token);return releaseStage(candidate,token);};
  f.runtime.releaseDeltaCapture=async(capture,token)=>{calls.push('capture:'+capture.capture_database);assert.equal(token,record.token);return releaseCapture(capture,token);};
  // Recovery consumes the verified retained receipt without trusting a later publication or copying another installer.
  f.runtime.authorizeInstalled=async()=>{throw Error('Recovery cannot request a new installed-code authority.');};
  return {...f,previous,context,record,calls};
}

test('background restart recovery verifies retained code and removes only its absent owned stage before its capture',async t=>{
  const f=await deltaRecoveryFixture(t);await f.runtime.recoverDeltaBackground();
  const sequence=f.calls.filter(item=>item.startsWith('stage:')||item.startsWith('capture:'));
  assert.deepEqual(sequence,['stage:'+f.record.candidate_database,'capture:'+f.record.capture_database]);
  assert.equal(f.records.get('delta-background'),null);assert.deepEqual(f.records.get('prepared'),f.previous);
  await assert.rejects(fs.access(path.join(f.runtime.profile,'generations',f.record.candidate_generation)));
  await assert.rejects(fs.access(path.join(f.runtime.profile,'generations',f.record.capture_generation)));
  assert.equal(f.runtime.manifest.sourceRevision,f.old.manifest.sourceRevision);
});

test('a candidate that already became active is never discarded during background recovery',async t=>{
  const f=await deltaRecoveryFixture(t);
  const active={...f.previous,database:f.record.candidate_database,sourceRevision:f.context.manifest.sourceRevision,sourceFingerprint:f.context.sourceFingerprint};
  f.records.set('prepared',active);await f.runtime.recoverDeltaBackground();
  assert.equal(f.calls.some(item=>item.startsWith('stage:')),false);assert.equal(f.calls.includes('capture:'+f.record.capture_database),true);
  assert.deepEqual(f.records.get('prepared'),active);assert.equal(f.records.get('delta-background'),null);
  assert.equal(await fs.readFile(path.join(f.runtime.profile,'generations',f.record.candidate_generation,'private-fixture'),'utf8'),'owned inactive work');
});

test('a capture database referenced by the active pointer fails recovery without any discard',async t=>{
  const f=await deltaRecoveryFixture(t),active={...f.previous,database:f.record.capture_database};f.records.set('prepared',active);
  await assert.rejects(f.runtime.recoverDeltaBackground());assert.deepEqual(f.calls,[]);
  assert.deepEqual(f.records.get('delta-background'),f.record);assert.deepEqual(f.records.get('prepared'),active);
  assert.equal(await fs.readFile(path.join(f.runtime.profile,'generations',f.record.capture_generation,'private-fixture'),'utf8'),'owned inactive work');
});

test('an unconfirmed stage discard preserves its capability and does not proceed to capture deletion',async t=>{
  const f=await deltaRecoveryFixture(t);f.runtime.releaseDeltaStage=async()=>{f.calls.push('stage-discard-unconfirmed');return false;};
  await assert.rejects(f.runtime.recoverDeltaBackground());assert.deepEqual(f.calls,['stage-discard-unconfirmed']);
  assert.deepEqual(f.records.get('delta-background'),f.record);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('an unresolved generation fence blocks background cleanup before any database inspection or discard',async t=>{
  const f=await deltaRecoveryFixture(t);f.records.set('refresh',{id:crypto.randomUUID(),state:'unresolved'});
  await assert.rejects(f.runtime.recoverDeltaBackground());assert.deepEqual(f.calls,[]);
  assert.deepEqual(f.records.get('delta-background'),f.record);assert.deepEqual(f.records.get('prepared'),f.previous);
  assert.equal(await fs.readFile(path.join(f.runtime.profile,'generations',f.record.candidate_generation,'private-fixture'),'utf8'),'owned inactive work');
});

test('corrupt or foreign background capabilities cannot allocate, inspect or discard a database',async t=>{
  for(const alter of [
    record=>record.token='invalid',record=>record.capture_generation='../escape',
    record=>record.capture_database=record.source_database,record=>record.candidate_database=record.capture_database,
    record=>record.device_id=crypto.randomUUID(),record=>record.actor_id=11
  ]){
    const f=await deltaRecoveryFixture(t),changed=structuredClone(f.record);alter(changed);f.records.set('delta-background',changed);
    await assert.rejects(f.runtime.recoverDeltaBackground());assert.deepEqual(f.calls,[]);
    assert.deepEqual(f.records.get('delta-background'),changed);assert.deepEqual(f.records.get('prepared'),f.previous);
  }
});

test('a damaged retained cleanup runtime cannot use its recorded capability against any background database',async t=>{
  const f=await deltaRecoveryFixture(t);await fs.writeFile(path.join(f.context.application,'desktop/delta.php'),'damaged retained cleanup source');
  await assert.rejects(f.runtime.recoverDeltaBackground());assert.deepEqual(f.calls,[]);
  assert.deepEqual(f.records.get('delta-background'),f.record);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('the capture capability is flushed before the first background database allocation',async t=>{
  const f=await fixture(t),previous=deltaActive(f);await addDeltaEntry(f.next);f.runtime.authorizeInstalled=async()=>true;
  const context=await f.runtime.deltaContext(previous),id='5'.repeat(16),token='6'.repeat(64);let allocations=0;
  f.runtime.createDeltaDatabase=async(selected,generation)=>{
    allocations++;assert.equal(selected,context);assert.equal(generation,id);const record=f.records.get('delta-background');
    assert.equal(record.kind,'dashboard-delta-background');assert.equal(record.capture_database,'fasakhansta_dashboard_stage_'+id);
    assert.equal(record.source_database,previous.database);assert.equal(record.token,token);assert.equal(record.device_id,previous.deviceId);
    assert.equal(record.sourceRevision,context.manifest.sourceRevision);assert.equal(record.candidate_database,null);
    throw Error('stopped before SQL allocation');
  };
  await assert.rejects(f.runtime.captureDelta(context,previous,id,token),/stopped before SQL/);
  assert.equal(allocations,1);assert.equal(f.records.get('delta-background').capture_generation,id);assert.deepEqual(f.records.get('prepared'),previous);
});

test('a stale capture record cannot be overwritten by a second background attempt',async t=>{
  const f=await deltaRecoveryFixture(t,{candidate:false});let allocations=0;
  f.runtime.createDeltaDatabase=async()=>{allocations++;throw Error('must not allocate');};
  await assert.rejects(f.runtime.captureDelta(f.context,f.previous,'7'.repeat(16),'8'.repeat(64)));
  assert.equal(allocations,0);assert.deepEqual(f.records.get('delta-background'),f.record);assert.deepEqual(f.records.get('prepared'),f.previous);
});

test('the inactive final-stage identity is persisted before its database allocation',async t=>{
  const f=await deltaRecoveryFixture(t,{candidate:false}),id='9'.repeat(16),capture={capture_database:f.record.capture_database,source_database:f.previous.database};
  f.runtime.deltaCaptures=new Map([[capture.capture_database,{token:f.record.token,context:f.context,capture}]]);let allocations=0;
  f.runtime.createDeltaDatabase=async(selected,generation)=>{
    allocations++;assert.equal(selected,f.context);assert.equal(generation,id);const background=f.records.get('delta-background');
    assert.equal(background.candidate_generation,id);assert.equal(background.candidate_database,'fasakhansta_dashboard_stage_'+id);
    assert.equal(background.capture_database,f.record.capture_database);assert.equal(background.token,f.record.token);throw Error('stopped before final SQL');
  };
  await assert.rejects(f.runtime.stageDelta(f.context,capture,{media:[]},id,f.record.token),/stopped before final SQL/);
  assert.equal(allocations,1);assert.deepEqual(f.records.get('prepared'),f.previous);
});

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
