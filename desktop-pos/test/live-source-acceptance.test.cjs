'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path'), crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const acceptance = require('../../.github/scripts/desktop-live-source-acceptance.cjs');
const receipts = require('../../.github/scripts/desktop-update-receipt.cjs');
const packaging = require('../installer-runtime.cjs'), source = require('../src/dashboard-source.cjs');
const sourcePolicy = require('../runtime-source-policy.cjs');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const revision = 'a'.repeat(40), version = '0.4.4-preview.11', runId = 39395150298;
const context = {repository:acceptance.REPOSITORY,branch:acceptance.LIVE_BRANCH,revision,runId,runAttempt:2,eventName:'push'};
function pe(machine = 0x14c) {
  // Test headers exercise structural hashing. The actual CI receipts, independent
  // of these fixtures, require execution of the installed Windows application.
  const bytes = Buffer.alloc(512);bytes.write('MZ');bytes.writeUInt32LE(64,60);bytes.write('PE\0\0',64);
  bytes.writeUInt16LE(machine,68);bytes.writeUInt16LE(1,70);bytes.writeUInt16LE(machine === 0x14c ? 96 : 112,84);
  bytes.writeUInt16LE(2,86);bytes.writeUInt16LE(machine === 0x14c ? 0x10b : 0x20b,88);return bytes;
}
function candidateFor(original) {
  const inventory = {}, live = {}, trusted = {}, packaged = {};
  for (const [name,bytes] of Object.entries(original)) {
    const authority = name.startsWith('desktop/') ? 'trusted-base' : 'live';
    const digest = sha(bytes);inventory[name] = {authority,present:true,sha256:digest,bytes:Buffer.byteLength(bytes),mode:'100644'};
    packaged[name] = digest;(authority === 'live' ? live : trusted)[name] = digest;
  }
  inventory['app/Deleted.php'] = {authority:'removed',present:false,sha256:null,bytes:0,mode:'100644'};
  return {format:1,kind:'go-erp-live-source-candidate',repository:acceptance.REPOSITORY,branch:acceptance.LIVE_BRANCH,
    baseRevision:'b'.repeat(40),version,versionSequence:11,inventorySha256:acceptance.mapHash(inventory),inventory,
    authoritativeLiveFiles:live,packagedFiles:packaged,retainedTrustedFiles:trusted,removedFiles:['app/Deleted.php']};
}
async function fixture(t, extraSource = {}) {
  const root = await fs.mkdtemp(path.join(os.tmpdir(),'go-live-acceptance-'));t.after(() => fs.rm(root,{recursive:true,force:true}));
  const project = path.join(root,'desktop-pos'), bundle = path.join(project,'runtime-bundle');
  const write = async (name,value) => {
    const file = path.join(root,name);await fs.mkdir(path.dirname(file),{recursive:true});
    await fs.writeFile(file,typeof value === 'object' && !Buffer.isBuffer(value) ? JSON.stringify(value) : value);
  };
  const original = {'artisan':'<?php /* checked */\n','desktop/router.php':'<?php\n','desktop/verify.php':'<?php\n',
    'app/Business.php':'<?php /* live business */\n','public/js/dashboard-live.js':'const live = 1;\r\n',
    'public/images/source.bin':Buffer.from([0,13,10,255]),...extraSource};
  for (const [name,bytes] of Object.entries(original)) await write('desktop-pos/runtime-bundle/application/'+name,bytes);
  const lock = {packages:[{name:'laravel/framework',version:'v8.83.29'}]};
  await write('desktop-pos/package.json',{version,build:{productName:'GO ERP'}});
  await write('desktop-pos/runtime-bundle/application/composer.json',{});await write('desktop-pos/runtime-bundle/application/composer.lock',lock);
  await write('desktop-pos/runtime-bundle/application/vendor/autoload.php','<?php');
  await write('desktop-pos/runtime-bundle/application/vendor/composer/installed.json',lock);
  await write('desktop-pos/runtime-bundle/php/php.ini','extension=php_pdo_mysql.dll\n');
  await write('desktop-pos/runtime-bundle/php/ssl/cacert.pem','synthetic fixture CA');
  for (const name of ['php/php.exe','mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe']) await write('desktop-pos/runtime-bundle/'+name,pe(0x8664));
  for (const name of ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'])
    await write('desktop-pos/runtime-bundle/php/ext/php_'+name+'.dll','synthetic fixture extension');
  const sourceFingerprint = await source.fingerprint(path.join(bundle,'application'));
  await write('desktop-pos/runtime-bundle/manifest.json',{format:1,platform:'win32-x64',sourceRevision:revision,runtimeFramework:'8.83.29',sourceFingerprint,
    sourceHashes:Object.fromEntries(Object.entries(original).map(([name,bytes]) => [name,sha(bytes)]))});
  const verified = await packaging.verifyBundle(bundle);
  await write('desktop-pos/dist/runtime-package.json',{format:1,version,sourceRevision:revision,sourceFingerprint,files:verified.files,lockSha256:verified.lockSha256});
  const native = {format:1,sourceRevision:revision,version,installation:true,installedNativeExecutables:true,installedDashboardRecovery:true,
    packagedLedger:true,uninstallRetention:true,installer:{sha256:sha(pe()),bytes:pe().length},
    prerequisite:{signatureValid:true,registered:true,failureBeforeExtraction:true},automaticUpgrade:Object.fromEntries(receipts.AUTOMATIC_UPGRADE.map(key => [key,true]))};
  const connected = {sourceRevision:revision,platform:'win32',hardware:false,completed:true,printedSources:4,originalWrites:1,invocations:4,
    lostPrintRequests:1,reauthentications:6,reauthOriginalSignins:5,reauthSuccesses:3,localReauthentications:1,
    ...Object.fromEntries(receipts.CONNECTED.map(key => [key,true]))};
  await write('desktop-pos/dist/installer-checks.json',native);await write('desktop-pos/dist/connected-request-native.json',connected);
  await write('desktop-pos/dist/GO-ERP-Preview-Setup.exe',pe());
  await receipts.generate(project,{revision,sequence:runId});
  const candidate = candidateFor(original);await write(acceptance.CANDIDATE,candidate);
  return {root,project,bundle,original,candidate,verified,native,connected,write,proof:path.join(project,'dist/go-erp-live-source-acceptance.json')};
}
test('live acceptance binds actual complete package, trusted runtime source, current CI attempt and exact accepted installer', async t => {
  const f = await fixture(t), proof = await acceptance.generate(f.project,context);
  assert.deepEqual(proof,{format:1,kind:'go-erp-live-source-acceptance',repository:context.repository,branch:context.branch,revision,
    run_id:runId,run_attempt:2,version,candidate_inventory_sha256:f.candidate.inventorySha256,
    full_package_sha256:acceptance.mapHash(f.verified.files),
    update_receipt_sha256:sha(await fs.readFile(path.join(f.project,'dist/go-erp-update-release.json'))),installer:f.native.installer});
  assert.deepEqual(JSON.parse(await fs.readFile(f.proof,'utf8')),proof);
  await assert.rejects(acceptance.generate(f.project,context),{code:'EEXIST'});
  assert.equal((await fs.readdir(path.dirname(f.proof))).some(name => name.endsWith('.tmp')),false);
});
test('normal minute builds remain independent of the new candidate file', async t => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(),'go-normal-acceptance-'));t.after(() => fs.rm(directory,{recursive:true,force:true}));
  assert.equal(await acceptance.generate(path.join(directory,'missing-project'),{branch:'codex/go-erp-minute-updates-20261020'}),null);
});
test('full inventory identity, authority maps and removals are checked rather than trusted as declarations', () => {
  const candidate = candidateFor({'artisan':'<?php','desktop/router.php':'<?php'});
  const validate = value => acceptance.validateCandidate(value,{repository:context.repository,branch:context.branch,version});
  assert.deepEqual(validate(candidate),candidate.packagedFiles);
  for (const mutate of [
    x => {x.repository = 'foreign/repository';},x => {x.branch = 'main';},x => {x.baseRevision = 'not-a-revision';},
    x => {x.version = '0.4.4-preview.10';},x => {x.versionSequence = 0;},x => {x.versionSequence = 10;},x => {x.extra = true;},
    x => {x.inventory.artisan.bytes++;},x => {delete x.retainedTrustedFiles['desktop/router.php'];},
    x => {x.packagedFiles['app/foreign.php'] = 'c'.repeat(64);},x => {x.removedFiles = [];},
    x => {x.authoritativeLiveFiles.artisan = 'c'.repeat(64);},x => {x.inventory['app/Deleted.php'].present = true;}
  ]) {const value = structuredClone(candidate);mutate(value);assert.throws(() => validate(value));}
  const unsafe = structuredClone(candidate);unsafe.inventory['app/../outside.php'] = unsafe.inventory.artisan;
  unsafe.inventorySha256 = acceptance.mapHash(unsafe.inventory);assert.throws(() => validate(unsafe));
});
test('canonical inventory binding matches portable UTF-8 sorted JSON and preserves exact source bytes', () => {
  assert.equal(acceptance.canonical({z:{present:true,bytes:0},a:'é'}),'{'+'"a":"é","z":{"bytes":0,"present":true}}');
  assert.notEqual(acceptance.mapHash({'file':'a\r\n'}),acceptance.mapHash({'file':'a\n'}));
  // Independent Python json.dumps(ensure_ascii=False,sort_keys=True,
  // separators=(',',':')) SHA256, including the real original BMP names.
  assert.equal(acceptance.mapHash({'public/site/images/Search – 2.png':{sha256:'a'.repeat(64),present:true},
    'public/site/images/Cart – 1.png':{present:true,sha256:'b'.repeat(64)},
    'public/site/images/Register – 2.png':{present:false,sha256:null}}),'7eba56574f5245187b48554be81bd6ec1f401a8cd97c78f24b9acf6924c6784d');
});
test('safe original BMP names are retained while Windows aliases and unsafe components fail closed', () => {
  for (const name of ['public/site/images/Cart – 1.png','public/site/images/Register – 2.png','public/site/images/Search – 2.png',
    'public/dashboard/dist/img=/logo image.png','public/site/images/صورة.png']) assert.equal(acceptance.sourcePath(name),true,name);
  for (const name of ['public/../secret.php','public//asset.png','public/.GIT/config','public/CON.png','public/aux/file.php',
    'public/LPT1.txt','public/COM9/config.php','public/trailing./file.png','public/trailing /file.png',
    'public/asset.png ','public/bad\\name.php','public/bad:name.php','public/bad<name.php','public/bad|name.php',
    'public/bad?name.php','public/bad*name.php','public/bad"name.php','public/control\u0085.png','public/emoji-😀.png',
    'public/lone-\ud800.png','public/control\u0001.png']) assert.equal(acceptance.sourcePath(name),false,name);
});
test('the actual current repository selected source inventory passes the complete package path contract', async t => {
  const checkout = path.resolve(__dirname,'../..'), roots = ['app','bootstrap','config','database','desktop','public','resources','routes','artisan'];
  const tracked = execFileSync('git',['ls-files','-z','--',...roots],{cwd:checkout,encoding:'utf8'}).split('\0').filter(Boolean);
  const original = {}, selected = {};
  const root = await fs.mkdtemp(path.join(os.tmpdir(),'go-live-actual-source-'));t.after(() => fs.rm(root,{recursive:true,force:true}));
  for (const name of tracked) {
    if (sourcePolicy.excludedPath(name)) continue;
    const from = path.join(checkout,name), stat = await fs.lstat(from);if (!stat.isFile()) continue;
    const bytes = await fs.readFile(from);if (sourcePolicy.credentialJson(name,bytes)) continue;
    original[name] = bytes;selected['application/'+name] = sha(bytes);
    const destination = path.join(root,'application',name);await fs.mkdir(path.dirname(destination),{recursive:true});await fs.writeFile(destination,bytes);
  }
  assert.ok(Object.keys(original).length > 2000,'Exercise the real full repository, not a small hand-picked path list.');
  for (const name of ['Cart – 1.png','Register – 2.png','Search – 2.png']) assert.ok(original['public/site/images/'+name]);
  const candidate = candidateFor(original);
  assert.deepEqual(acceptance.validateCandidate(candidate,{...context,version}),candidate.packagedFiles);
  assert.equal(await acceptance.verifySource(root,{manifest:{sourceHashes:candidate.packagedFiles},files:selected},candidate,{...context,version}),acceptance.mapHash(selected));
});
test('real original image bodies with spaces and en dashes survive runtime verification and final proof generation', async t => {
  const images = {};
  for (const name of ['Cart – 1.png','Register – 2.png','Search – 2.png']) {
    const relative = 'public/site/images/'+name;
    images[relative] = await fs.readFile(path.resolve(__dirname,'../..',relative));
  }
  const f = await fixture(t,images), proof = await acceptance.generate(f.project,context);
  assert.equal(proof.candidate_inventory_sha256,f.candidate.inventorySha256);
  assert.equal(proof.full_package_sha256,acceptance.mapHash(f.verified.files));
  for (const [name,bytes] of Object.entries(images)) assert.equal(f.verified.files['application/'+name],sha(bytes));
});
test('live Windows checkout settings preserve exact LF, CRLF and binary Git blobs under text=auto', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(),'go-live-checkout-'));t.after(() => fs.rm(root,{recursive:true,force:true}));
  const git = (args,input) => execFileSync('git',args,{cwd:root,input,encoding:'utf8',stdio:['pipe','pipe','pipe']}).trim();
  git(['init']);git(['config','core.autocrlf','false']);git(['config','core.eol','crlf']);
  const originals = {'.gitattributes':Buffer.from('* text=auto\n'),'source-lf.php':Buffer.from('<?php\necho 1;\n'),
    'source-crlf.php':Buffer.from('<?php\r\necho 2;\r\n'),'source.bin':Buffer.from([0,13,10,255])};
  for (const [name,bytes] of Object.entries(originals)) {
    const blob = git(['hash-object','-w','--no-filters','--stdin'],bytes);
    git(['update-index','--add','--cacheinfo','100644',blob,name]);
  }
  git(['checkout-index','--force','--all']);
  assert.notDeepEqual(await fs.readFile(path.join(root,'source-lf.php')),originals['source-lf.php'],'autocrlf=false alone does not override Windows native CRLF');
  git(['config','core.eol','lf']);git(['checkout-index','--force','--all']);
  for (const [name,bytes] of Object.entries(originals)) assert.deepEqual(await fs.readFile(path.join(root,name)),bytes);
});
test('a changed or missing live file, retained asset, new GUI file or deleted file cannot inherit acceptance', async t => {
  for (const name of ['app/Business.php','desktop/router.php','public/js/dashboard-live.js','public/images/source.bin']) {
    const f = await fixture(t);await fs.writeFile(path.join(f.bundle,'application',name),'changed');
    await assert.rejects(acceptance.generate(f.project,context));await assert.rejects(fs.access(f.proof));
  }
  const f = await fixture(t);await fs.rm(path.join(f.bundle,'application/app/Business.php'));
  await assert.rejects(acceptance.generate(f.project,context));await assert.rejects(fs.access(f.proof));
  await f.write('desktop-pos/runtime-bundle/application/app/Business.php',f.original['app/Business.php']);
  await f.write('desktop-pos/runtime-bundle/application/public/js/dashboard-added.js','const added=1;');
  const extra = await packaging.verifyBundle(f.bundle);
  await assert.rejects(acceptance.verifySource(f.bundle,extra,f.candidate,{...context,version}),/missing or additional/);
  await fs.rm(path.join(f.bundle,'application/public/js/dashboard-added.js'));
  await f.write('desktop-pos/runtime-bundle/application/app/Deleted.php','resurrected');
  await assert.rejects(async () => acceptance.verifySource(f.bundle,await packaging.verifyBundle(f.bundle),f.candidate,{...context,version}));
});
test('candidate full source cannot omit a trusted runtime-only asset or substitute only normalized text hashes', async t => {
  const f = await fixture(t), value = structuredClone(f.candidate);
  delete value.inventory['desktop/router.php'];delete value.packagedFiles['desktop/router.php'];delete value.retainedTrustedFiles['desktop/router.php'];
  value.inventorySha256 = acceptance.mapHash(value.inventory);
  await assert.rejects(acceptance.verifySource(f.bundle,f.verified,value,{...context,version}),/complete live candidate/);
  const normalized = structuredClone(f.candidate), key = 'public/js/dashboard-live.js', digest = sha('const live = 1;\n');
  normalized.inventory[key].sha256 = digest;normalized.authoritativeLiveFiles[key] = digest;normalized.packagedFiles[key] = digest;
  normalized.inventorySha256 = acceptance.mapHash(normalized.inventory);
  await assert.rejects(acceptance.verifySource(f.bundle,f.verified,normalized,{...context,version}),/complete live candidate/);
});
test('old or missing native acceptance, a replacement installer, or a changed update receipt leaves no proof', async t => {
  for (const mutate of [
    async f => f.write('desktop-pos/dist/installer-checks.json',{...f.native,sourceRevision:'f'.repeat(40)}),
    async f => f.write('desktop-pos/dist/connected-request-native.json',{...f.connected,actualDpapi:false}),
    async f => {const bytes = pe();bytes[500] = 1;await f.write('desktop-pos/dist/GO-ERP-Preview-Setup.exe',bytes);},
    async f => {const file = path.join(f.project,'dist/go-erp-update-release.json');const value = JSON.parse(await fs.readFile(file,'utf8'));value.source_revision = 'f'.repeat(40);await fs.writeFile(file,JSON.stringify(value));}
  ]) {const f = await fixture(t);await mutate(f);await assert.rejects(acceptance.generate(f.project,context));await assert.rejects(fs.access(f.proof));}
});
test('proof identity requires a push on this repository, exact SHA and positive safe run and attempt', async t => {
  const f = await fixture(t);
  for (const change of [{repository:'foreign/repo'},{eventName:'pull_request'},{revision:'latest'},{runId:0},{runAttempt:'2e0'},{runAttempt:Number.MAX_SAFE_INTEGER+1}])
    await assert.rejects(acceptance.generate(f.project,{...context,...change}));
  await assert.rejects(fs.access(f.proof));
});
test('candidate links and oversized JSON are refused without producing a proof', async t => {
  const f = await fixture(t), file = path.join(f.root,acceptance.CANDIDATE);
  await fs.link(file,file+'.alias');await assert.rejects(acceptance.generate(f.project,context),/unsafe/);await fs.rm(file+'.alias');
  if (process.platform !== 'win32') {
    await fs.rename(file,file+'.original');await fs.symlink(file+'.original',file);
    await assert.rejects(acceptance.generate(f.project,context),/unsafe/);await fs.rm(file);await fs.rename(file+'.original',file);
  }
  await fs.truncate(file,16 * 1024 ** 2 + 1);await assert.rejects(acceptance.generate(f.project,context),/bound/);
  await assert.rejects(fs.access(f.proof));
});
