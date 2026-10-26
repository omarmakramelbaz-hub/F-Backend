'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises');
const path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const LocalRuntime=require('../src/local-runtime.cjs'),RuntimeArchive=require('../src/runtime-archive.cjs');
const source=require('../src/dashboard-source.cjs');
const {upgradeStartupSource}=require('../src/startup-source-upgrade.cjs');
const {DashboardGeneration}=require('../src/dashboard-generation.cjs');

async function fixture(t,{database='same database bytes'}={}){
  const temporary=await fs.mkdtemp(path.join(os.tmpdir(),'startup-source-runtime-'));
  t.after(()=>fs.rm(temporary,{recursive:true,force:true}));
  const profile=path.join(temporary,'بيانات المستخدم~1');await fs.mkdir(profile);
  const records=new Map(),calls=[];
  const metadata={read:async key=>structuredClone(records.get(key)||null),
    write:async(key,value)=>records.set(key,structuredClone(value)),
    clearIntent:async()=>records.delete('refresh')};
  const make=async(revision,rule,dbBytes)=>{
    const bundle=path.join(temporary,revision),application=path.join(bundle,'application');
    for(const folder of ['application/app','application/desktop','php/ext','php/ssl','mariadb/bin'])
      await fs.mkdir(path.join(bundle,folder),{recursive:true});
    const entries={'app/rule.php':rule,'desktop/delta.php':'<?php echo "{}";\n'};
    for(const [name,bytes] of Object.entries(entries))await fs.writeFile(path.join(application,name),bytes);
    await fs.writeFile(path.join(application,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
    await fs.writeFile(path.join(bundle,'php/php.exe'),'synthetic executable is never invoked');
    await fs.writeFile(path.join(bundle,'php/php.ini'),'extension=C:/build/php/ext/fixture.dll\nzend_extension=C:/build/php/ext/fixture-zend.dll\n');
    await fs.writeFile(path.join(bundle,'php/ext/fixture.dll'),'synthetic extension');
    await fs.writeFile(path.join(bundle,'php/ext/fixture-zend.dll'),'synthetic Zend extension');
    await fs.copyFile(path.join(__dirname,'fixtures/synthetic-ca.pem'),path.join(bundle,'php/ssl/cacert.pem'));
    for(const name of ['mariadbd.exe','mariadb-install-db.exe','runtime.dll'])
      await fs.writeFile(path.join(bundle,'mariadb/bin',name),dbBytes);
    const manifest={format:1,platform:'win32-x64',sourceRevision:revision,
      sourceHashes:Object.fromEntries(Object.entries(entries).map(([name,bytes])=>[name,crypto.createHash('sha256').update(bytes).digest('hex')])),
      sourceFingerprint:await source.fingerprint(application)};
    await fs.writeFile(path.join(bundle,'manifest.json'),JSON.stringify(manifest));return {bundle,manifest};
  };
  const old=await make('a'.repeat(40),'old original rule','same database bytes');
  const next=await make('b'.repeat(40),'new original rule',database);
  const runtime=new LocalRuntime({bundle:next.bundle,profile,safeStorage:{}});runtime.metadata=metadata;
  runtime.bundle=await new RuntimeArchive(profile,metadata).retain(old.bundle);
  Object.assign(runtime,{manifest:old.manifest,sourceFingerprint:old.manifest.sourceFingerprint,
    application:path.join(runtime.bundle,'application'),php:path.join(runtime.bundle,'php/php.exe'),phpIni:path.join(profile,'old.ini'),environment:{}});
  const previous={format:2,deviceId:crypto.randomUUID(),actorId:10,serverOrigin:'https://example.test',token:'a'.repeat(64),
    schemaHash:'b'.repeat(64),snapshotId:crypto.randomUUID(),refreshId:crypto.randomUUID(),generation:'a'.repeat(16),
    database:'fasakhansta_dashboard_stage_'+'a'.repeat(16),sourceRevision:old.manifest.sourceRevision,
    sourceFingerprint:structuredClone(old.manifest.sourceFingerprint),branches:['f:100'],fullCoverage:true,mediaVerified:true};
  records.set('prepared',structuredClone(previous));runtime.settings=async()=>({deviceId:previous.deviceId});
  const sessionFile=path.join(profile,'session-preservation-marker'),journalFile=path.join(profile,'journal-preservation-marker');
  await fs.writeFile(sessionFile,'original authenticated session');await fs.writeFile(journalFile,'original settled and historical operations');
  runtime.captureDelta=async(context,active)=>{
    calls.push({kind:'capture',context,active});
    // This test stops at the PHP process boundary. It tests real source/binary
    // verification without claiming to execute synthetic PHP or MariaDB bytes.
    throw Error('synthetic capture boundary');
  };
  const generation=new DashboardGeneration({runtime,metadata,download:async()=>{throw Error('full export is forbidden');}});
  const originalAuthority=runtime.authorizeInstalled;
  const options={runtime,metadata,installedManifest:next.manifest,online:()=>true,viewAbsent:()=>true,
    inspectIdle:async()=>({pending:0,conflicts:0,printEffects:0,legacyPending:0,legacyConflicts:0,openOrders:0}),
    remoteState:{assertClean:async()=>{}},authorizeInstalled:async(manifest,{signal})=>{
      assert.equal(signal.aborted,false);calls.push({kind:'authority',revision:manifest.sourceRevision});return true;
    },download:async()=>{throw Error('unverified synthetic capture must never download');},recover:()=>generation.recoverIntent()};
  const assertRetained=async()=>{
    assert.deepEqual(records.get('prepared'),previous);
    assert.equal(runtime.manifest.sourceRevision,old.manifest.sourceRevision);
    assert.ok(source.same(runtime.sourceFingerprint,old.manifest.sourceFingerprint));
    assert.equal(records.has('delta-background'),false);assert.equal(records.has('refresh'),false);
    await assert.rejects(fs.access(path.join(profile,'generations')));
    assert.equal(await fs.readFile(sessionFile,'utf8'),'original authenticated session');
    assert.equal(await fs.readFile(journalFile,'utf8'),'original settled and historical operations');
    assert.equal(runtime.authorizeInstalled,originalAuthority);
  };
  return {runtime,metadata,records,calls,old,next,previous,options,assertRetained,
    run:extra=>upgradeStartupSource({...options,...extra})};
}

test('startup helper executes real retained-source and MariaDB compatibility checks before the PHP capture boundary',async t=>{
  const f=await fixture(t),result=await f.run();assert.equal(result.status,'deferred');
  const capture=f.calls.find(call=>call.kind==='capture');assert.ok(capture);
  assert.notEqual(capture.context.bundle,f.next.bundle);
  assert.equal(capture.context.manifest.sourceRevision,f.next.manifest.sourceRevision);
  assert.ok(source.same(capture.context.sourceFingerprint,f.next.manifest.sourceFingerprint));
  assert.deepEqual(capture.active,f.previous);
  assert.equal(await fs.readFile(path.join(capture.context.application,'app/rule.php'),'utf8'),'new original rule');
  assert.equal(f.calls.filter(call=>call.kind==='authority').length,2);
  assert.ok(f.records.get('runtimes/'+f.next.manifest.sourceRevision));await f.assertRetained();
});

test('startup source upgrade rejects real incompatible MariaDB bytes before capture or generation allocation',async t=>{
  const f=await fixture(t,{database:'incompatible database bytes'});assert.equal((await f.run()).status,'deferred');
  assert.equal(f.calls.some(call=>call.kind==='capture'),false);
  assert.equal(f.calls.filter(call=>call.kind==='authority').length,1);await f.assertRetained();
});

test('damaged installed delta PHP cannot use publication authority to cross the verified runtime boundary',async t=>{
  const f=await fixture(t);await fs.writeFile(path.join(f.next.bundle,'application/desktop/delta.php'),'<?php /* unreviewed changed source */');
  assert.equal((await f.run()).status,'deferred');assert.equal(f.calls.some(call=>call.kind==='capture'),false);
  assert.equal(f.records.has('runtimes/'+f.next.manifest.sourceRevision),false);await f.assertRetained();
});

test('a publication denial cannot retain new executable source or allocate a capture',async t=>{
  const f=await fixture(t);assert.equal((await f.run({authorizeInstalled:async()=>false})).status,'deferred');
  assert.equal(f.calls.some(call=>call.kind==='capture'),false);
  assert.equal(f.records.has('runtimes/'+f.next.manifest.sourceRevision),false);await f.assertRetained();
});

test('authority revoked after source retention still cannot start capture or alter the active account journal',async t=>{
  const f=await fixture(t);let approvals=0;
  assert.equal((await f.run({authorizeInstalled:async()=>++approvals===1})).status,'deferred');
  assert.equal(approvals,2);assert.equal(f.calls.some(call=>call.kind==='capture'),false);
  assert.ok(f.records.get('runtimes/'+f.next.manifest.sourceRevision));await f.assertRetained();
});

test('a foreign claimed installer source cannot authorize the real installed source or begin capture',async t=>{
  const f=await fixture(t),foreign={...f.next.manifest,sourceFingerprint:{...f.next.manifest.sourceFingerprint,sha256:'9'.repeat(64)}};
  assert.equal((await f.run({installedManifest:foreign})).status,'deferred');
  assert.equal(f.calls.some(call=>call.kind==='authority'),false);assert.equal(f.calls.some(call=>call.kind==='capture'),false);
  assert.equal(f.records.has('runtimes/'+f.next.manifest.sourceRevision),false);await f.assertRetained();
});

test('native fixture delta adapter keeps canonical PHP numeric bytes and drains an aborted PHP response',async()=>{
  const {deltaFromCanonicalSnapshot}=require('./startup-source-upgrade-native.cjs');
  const readSnapshot=require('../src/dashboard-download.cjs');
  const wire='{"format":1,"tables":{"numbers":{"rows":[{"id":9007199254740993,"rate":0.0}]}}}';
  const inputWire='{"format":1,"kind":"initial-dashboard-data","tables":{}}';
  let captured;
  const download=deltaFromCanonicalSnapshot({bootstrap:'',canonicalSnapshotWire:inputWire,sourceFingerprint:{},
    php:async(code,input)=>{captured=input;return {wire};}});
  const value=await download({tables:{}},{},{});
  assert.equal(captured.wire,inputWire);assert.equal(readSnapshot.input(value),wire);
  assert.ok(Object.isFrozen(value.tables.numbers.rows[0]));
  const controller=new AbortController();let drained=false;
  const aborting=deltaFromCanonicalSnapshot({bootstrap:'',canonicalSnapshotWire:inputWire,sourceFingerprint:{},
    php:async()=>{controller.abort();drained=true;return {wire};}});
  await assert.rejects(aborting({tables:{}},{},{signal:controller.signal}),/stopped/);assert.equal(drained,true);
});
