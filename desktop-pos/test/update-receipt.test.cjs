'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path'), crypto = require('node:crypto');
const {generate,validateParts,installer,guiSnapshot,CONNECTED,AUTOMATIC_UPGRADE} = require('../../.github/scripts/desktop-update-receipt.cjs');
const packaging = require('../installer-runtime.cjs'), source = require('../src/dashboard-source.cjs');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const revision = 'a'.repeat(40), sequence = 39395150298, version = '0.4.4-preview.6';
function inputs() {
  const sourceFingerprint = {format:1,files:2,sha256:'b'.repeat(64),framework:'8.83.29'};
  const files = {'manifest.json':'c'.repeat(64),'application/artisan':'d'.repeat(64)};
  return {revision,sequence,info:{version,build:{productName:'GO ERP'}},
    verified:{manifest:{sourceRevision:revision,sourceFingerprint},files,lockSha256:'e'.repeat(64)},
    runtimePackage:{format:1,version,sourceRevision:revision,sourceFingerprint,files,lockSha256:'e'.repeat(64)},
    installerChecks:{format:1,sourceRevision:revision,version,installation:true,installedNativeExecutables:true,
      installedDashboardRecovery:true,packagedLedger:true,uninstallRetention:true,installer:{sha256:sha(pe()),bytes:pe().length},
      automaticUpgrade:Object.fromEntries(AUTOMATIC_UPGRADE.map(key => [key,true])),
      prerequisite:{signatureValid:true,registered:true,failureBeforeExtraction:true}},
    connected:{sourceRevision:revision,platform:'win32',hardware:false,completed:true,printedSources:4,originalWrites:1,invocations:4,
      lostPrintRequests:1,reauthentications:6,reauthOriginalSignins:5,reauthSuccesses:3,localReauthentications:1,
      ...Object.fromEntries(CONNECTED.map(key => [key,true]))}};
}
test('the release receipt binds all accepted jobs to one source and version without expanding preview coverage', () => {
  const result = validateParts(inputs());
  assert.deepEqual(result,{format:1,kind:'go-erp-desktop-release',version,sequence,source_revision:revision,
    source:inputs().verified.manifest.sourceFingerprint,acceptance:{windows_installer:true,runtime:true,connected:true,source_revision:revision}});
  assert.equal(Object.hasOwn(result, 'full_dashboard'),false);
});
test('missing, stale and changed acceptance evidence cannot produce a release receipt', () => {
  const changes = [
    x => {x.revision = 'not-a-commit';}, x => {x.sequence = '39395150298';}, x => {x.sequence = Number.MAX_SAFE_INTEGER + 1;},
    x => {x.sequence = 0;}, x => {x.info.version = 'latest';}, x => {x.info.build.productName = 'Another App';},
    x => {x.verified.manifest.sourceRevision = 'f'.repeat(40);}, x => {x.verified.manifest.sourceFingerprint.sha256 = 'missing';},
    x => {x.runtimePackage.sourceRevision = 'f'.repeat(40);}, x => {x.runtimePackage.version = '0.4.4-preview.5';},
    x => {x.runtimePackage.sourceFingerprint = {...x.runtimePackage.sourceFingerprint,sha256:'f'.repeat(64)};},
    x => {x.runtimePackage.files = {...x.runtimePackage.files,'php/foreign.exe':'f'.repeat(64)};},
    x => {x.runtimePackage.lockSha256 = 'f'.repeat(64);}, x => {x.installerChecks.sourceRevision = 'f'.repeat(40);},
    x => {x.installerChecks.version = '0.4.4-preview.5';}, x => {x.installerChecks.installation = 'true';},
    x => {x.installerChecks.uninstallRetention = false;}, x => {x.installerChecks.prerequisite.signatureValid = false;},
    x => {x.installerChecks.prerequisite.failureBeforeExtraction = false;}, x => {x.connected.sourceRevision = 'f'.repeat(40);},
    x => {delete x.installerChecks.installer;}, x => {x.installerChecks.installer.sha256 = 'not-an-accepted-hash';},
    x => {x.connected.platform = 'linux';}, x => {x.connected.hardware = true;}, x => {x.connected.completed = false;},
    x => {x.connected.unknownNativeField = true;delete x.connected.actualDpapi;}, x => {x.connected.reauthSuccesses = 2;}
  ];
  for (const change of changes) {const value = structuredClone(inputs());change(value);assert.throws(() => validateParts(value));}
});
test('automatic publication requires exactly all five actual automatic upgrade proofs', () => {
  for (const invalid of [undefined,null,[],{},true,{...inputs().installerChecks.automaticUpgrade,extra:true}]) {
    const value = inputs();value.installerChecks.automaticUpgrade = invalid;
    assert.throws(() => validateParts(value),/automatic Windows upgrade acceptance is incomplete/);
  }
  for (const key of AUTOMATIC_UPGRADE) {
    for (const invalid of [undefined,false,'true',1]) {
      const value = inputs();
      if (invalid === undefined) delete value.installerChecks.automaticUpgrade[key];
      else value.installerChecks.automaticUpgrade[key] = invalid;
      assert.throws(() => validateParts(value),/automatic Windows upgrade acceptance is incomplete/);
    }
  }
});
function pe(machine = 0x14c) {
  // Synthetic headers validate structure only. Actual Windows execution is
  // independently required in installer-checks.json from the workflow.
  const bytes = Buffer.alloc(512);bytes.write('MZ');bytes.writeUInt32LE(64,60);bytes.write('PE\0\0',64);
  bytes.writeUInt16LE(machine,68);bytes.writeUInt16LE(1,70);bytes.writeUInt16LE(machine === 0x14c ? 96 : 112,84);
  bytes.writeUInt16LE(2,86);bytes.writeUInt16LE(machine === 0x14c ? 0x10b : 0x20b,88);return bytes;
}
async function temporary(t) {
  const root = await fs.mkdtemp(path.join(os.tmpdir(),'go-update-receipt-'));t.after(() => fs.rm(root,{recursive:true,force:true}));return root;
}
test('the exact installer hash is streamed only from a regular structurally valid Windows executable', async t => {
  const root = await temporary(t), file = path.join(root,'installer.exe'), bytes = pe();await fs.writeFile(file,bytes);
  assert.deepEqual(await installer(file),{sha256:sha(bytes),bytes:bytes.length});
  for (const mutate of [x=>x.write('ZZ'),x=>x.writeUInt32LE(510,60),x=>x.writeUInt16LE(0,70),x=>x.writeUInt16LE(0,86),x=>x.writeUInt16LE(0x20b,88)]) {
    const bad = Buffer.from(bytes);mutate(bad);await fs.writeFile(file,bad);await assert.rejects(installer(file));
  }
  await fs.writeFile(file,bytes);await fs.link(file,path.join(root,'alias.exe'));await assert.rejects(installer(file),/unsafe/);
});
async function fixture(t, gui = {}) {
  const project = path.join(await temporary(t),'desktop-pos'), bundle = path.join(project,'runtime-bundle');
  const write = async(name,value) => {const file = path.join(project,name);await fs.mkdir(path.dirname(file),{recursive:true});await fs.writeFile(file,typeof value === 'object' && !Buffer.isBuffer(value) ? JSON.stringify(value) : value);};
  const original = {'artisan':'<?php /* fixture */','desktop/router.php':'<?php','desktop/verify.php':'<?php','app/Business.php':'<?php /* checked business */',...gui};
  for (const [name,bytes] of Object.entries(original)) await write('runtime-bundle/application/'+name,bytes);
  const lock = {packages:[{name:'laravel/framework',version:'v8.83.29'}]};
  await write('package.json',{version,build:{productName:'GO ERP'}});
  await write('runtime-bundle/application/composer.json',{});await write('runtime-bundle/application/composer.lock',lock);
  await write('runtime-bundle/application/vendor/autoload.php','<?php');
  await write('runtime-bundle/application/vendor/composer/installed.json',lock);
  await write('runtime-bundle/php/php.ini','extension=php_pdo_mysql.dll\n');await write('runtime-bundle/php/ssl/cacert.pem','synthetic fixture certificate');
  for (const name of ['php/php.exe','mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe']) await write('runtime-bundle/'+name,pe(0x8664));
  for (const name of ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl']) await write('runtime-bundle/php/ext/php_'+name+'.dll','synthetic fixture DLL');
  const sourceFingerprint = await source.fingerprint(path.join(bundle,'application'));
  await write('runtime-bundle/manifest.json',{format:1,platform:'win32-x64',sourceRevision:revision,runtimeFramework:'8.83.29',sourceFingerprint,
    sourceHashes:Object.fromEntries(Object.entries(original).map(([name,bytes])=>[name,sha(bytes)]))});
  const verified = await packaging.verifyBundle(bundle), input = inputs();
  input.verified = verified;input.runtimePackage = {...input.runtimePackage,sourceFingerprint,files:verified.files,lockSha256:verified.lockSha256};
  await write('dist/runtime-package.json',input.runtimePackage);await write('dist/installer-checks.json',input.installerChecks);
  await write('dist/connected-request-native.json',input.connected);const exe = pe();await write('dist/GO-ERP-Preview-Setup.exe',exe);
  return {project,write,input,exe};
}
test('end-to-end CI receipt verifies current runtime bytes and writes one exclusive publication input', async t => {
  const value = await fixture(t), receipt = await generate(value.project,{revision,sequence:String(sequence)});
  assert.deepEqual(receipt.installer,{sha256:sha(value.exe),bytes:value.exe.length});
  assert.deepEqual(receipt.gui,{});
  assert.deepEqual(JSON.parse(await fs.readFile(path.join(value.project,'dist/go-erp-update-release.json'),'utf8')),receipt);
  await assert.rejects(generate(value.project,{revision,sequence}),{code:'EEXIST'});
  assert.equal((await fs.readdir(path.join(value.project,'dist'))).some(name=>name.endsWith('.tmp')),false);
});
test('modified runtime, stale installer acceptance and unsafe sequence leave no publication receipt', async t => {
  const value = await fixture(t), receipt = path.join(value.project,'dist/go-erp-update-release.json');
  await value.write('runtime-bundle/application/app/Business.php','<?php /* modified */');
  await assert.rejects(generate(value.project,{revision,sequence}),/original source changed/);await assert.rejects(fs.access(receipt));
  await value.write('runtime-bundle/application/app/Business.php','<?php /* checked business */');
  await value.write('dist/installer-checks.json',{...value.input.installerChecks,sourceRevision:'f'.repeat(40)});
  await assert.rejects(generate(value.project,{revision,sequence}),/acceptance is incomplete/);await assert.rejects(fs.access(receipt));
  await assert.rejects(generate(value.project,{revision,sequence:'1e9'}),/sequence is invalid/);await assert.rejects(fs.access(receipt));
});
test('a structurally valid replacement setup cannot inherit the earlier actual Windows acceptance', async t => {
  const value = await fixture(t), replacement = Buffer.from(value.exe);replacement[500] = 1;
  await value.write('dist/GO-ERP-Preview-Setup.exe',replacement);
  await assert.rejects(generate(value.project,{revision,sequence}),/differs from the executable accepted on Windows/);
  await assert.rejects(fs.access(path.join(value.project,'dist/go-erp-update-release.json')));
});
test('the receipt includes every immediate dashboard GUI asset with normalized hashes without changing the legacy source fingerprint', async t => {
  const gui = {'public/js/dashboard-new-feature.js':'const feature=1;\r\n','public/css/dashboard-new-feature.css':'body { color: orange; }\r\n',
    'public/js/dashboard-known.js':'const known=1;\n'};
  const value = await fixture(t,gui), empty = await fixture(t);
  assert.deepEqual(value.input.verified.manifest.sourceFingerprint,empty.input.verified.manifest.sourceFingerprint);
  const receipt = await generate(value.project,{revision,sequence});
  assert.deepEqual(receipt.gui,Object.fromEntries(Object.entries(gui).sort(([a],[b])=>a.localeCompare(b))
    .map(([name,bytes])=>[name,sha(bytes.replace(/\r\n/g,'\n'))])));
  await value.write('runtime-bundle/application/public/js/unrelated.js','not part of this GUI contract');
  await value.write('runtime-bundle/application/public/js/private/.env','private excluded content');
  assert.deepEqual(await guiSnapshot(path.join(value.project,'runtime-bundle/application'),value.input.verified),receipt.gui);
});
test('GUI-only stale, missing, extra or changed raw identities cannot be published', async t => {
  const relative = 'public/js/dashboard-live.js', bytes = 'const live=1;\r\n';
  const value = await fixture(t,{[relative]:bytes}), application = path.join(value.project,'runtime-bundle/application');
  const checked = value.input.verified;
  assert.deepEqual(await guiSnapshot(application,checked),{[relative]:sha('const live=1;\n')});
  await value.write('runtime-bundle/application/'+relative,'const live=2;\r\n');
  await assert.rejects(guiSnapshot(application,checked),/bytes differ/);
  await value.write('runtime-bundle/application/'+relative,bytes);
  const stale = structuredClone(checked);stale.manifest.sourceHashes[relative] = 'f'.repeat(64);
  await assert.rejects(guiSnapshot(application,stale),/bytes differ/);
  await fs.rm(path.join(application,relative));await assert.rejects(guiSnapshot(application,checked),/roster is incomplete/);
  await value.write('runtime-bundle/application/'+relative,bytes);
  await value.write('runtime-bundle/application/public/js/dashboard-unknown.js','const unknown=1;');
  await assert.rejects(guiSnapshot(application,checked),/bytes differ/);
  await fs.rm(path.join(application,'public/js/dashboard-unknown.js'));
  const missingIndex = structuredClone(checked);delete missingIndex.manifest.sourceHashes[relative];
  await assert.rejects(guiSnapshot(application,missingIndex),/roster differs/);
});
test('GUI inspection refuses links and oversized assets before accepting a complete roster', async t => {
  const relative = 'public/js/dashboard-live.js', value = await fixture(t,{[relative]:'const live=1;'});
  const application = path.join(value.project,'runtime-bundle/application'), file = path.join(application,relative);
  await fs.link(file,path.join(application,'public/js/other-link.js'));
  await assert.rejects(guiSnapshot(application,value.input.verified),/unsafe/);
  await fs.rm(path.join(application,'public/js/other-link.js'));
  await fs.truncate(file,8 * 1024 ** 2 + 1);
  await assert.rejects(guiSnapshot(application,value.input.verified),/exceeds its bound/);
  if (process.platform !== 'win32') {
    await fs.rm(file);await fs.symlink(path.join(application,'artisan'),file);
    await assert.rejects(guiSnapshot(application,value.input.verified),/unsafe/);
  }
});
