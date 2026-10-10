'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const [mode,repository,root,php]=process.argv.slice(2),env=process.env;
assert.ok(['prepare','dependencies','fixtures','verify'].includes(mode)&&repository&&root);
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const fileHash=file=>fs.existsSync(file)?hash(fs.readFileSync(file)):null;
const json=file=>JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,''));
const git=(args,options={})=>execFileSync('git',args,{cwd:repository,windowsHide:true,maxBuffer:128*1024**2,...options});
const commit=git(['rev-parse','HEAD']).toString().trim(),tree=git(['rev-parse','HEAD^{tree}']).toString().trim();
assert.equal(commit,env.GITHUB_SHA);assert.equal(env.GITHUB_REF,'refs/heads/codex/laravel12-windows-full-suite-20261010');
assert.equal(git(['status','--porcelain']).toString(),'','The diagnostic checkout must remain clean.');
const inputRoot='.github/diagnostics/laravel12';
const expected={['composer.json']:'c7f7f6ac9b766b9282be10dacfbc25c367600ad550488802b24e75b4dfc122c1',['composer.lock']:'f0fe7a09c3736dac4cddf120f14f9ca3a5c6553a1ff7ecc2e37f4be0e5cbee75'};
for(const [name,digest]of Object.entries(expected)){
  assert.equal(fileHash(path.join(repository,inputRoot,name)),digest);
  assert.equal(hash(git(['show',commit+':'+inputRoot+'/'+name])),digest,'Diagnostic input Git bytes changed.');
}
const protectedInputs=['composer.json','composer.lock','desktop-pos/runtime/composer.json','desktop-pos/runtime/composer.lock'];
const inputHashes=Object.fromEntries(protectedInputs.map(name=>[name,fileHash(path.join(repository,name))]));
assert.deepEqual(inputHashes,{'composer.json':'26b487cf7267c2cf9d98818766e8078ebc776a8e8062fcff8571932f44c50306','composer.lock':null,'desktop-pos/runtime/composer.json':'06f9654c0ffe566d8b04fb23bc207504f07192780155adc7fae7d7239c6fcbd6','desktop-pos/runtime/composer.lock':'319ea5f627a46681c45e57c4e780f2d1c19a80f0272711ccf770feb3716d18a7'});
for(const vendor of ['vendor','desktop-pos/runtime/vendor'])assert.equal(fs.existsSync(path.join(repository,vendor)),false);
const overrides=Object.keys(env).filter(key=>/^COMPOSER(?:_|$)/i.test(key)&&!['COMPOSER_HOME','COMPOSER_CACHE_DIR'].includes(key));
assert.deepEqual(overrides,[],'Inherited Composer policy/platform/audit overrides remain.');
for(const key of ['COMPOSER_HOME','COMPOSER_CACHE_DIR'])assert.ok(env[key]&&path.resolve(env[key]).startsWith(path.resolve(root)+path.sep));
const app=path.join(root,'runtime/application'),artifact=path.join(root,'artifact');
const fingerprint=require(path.join(repository,'desktop-pos/src/dashboard-source.cjs'));
const receiptPath=path.join(artifact,'windows-full-suite-receipt.json');
const statuses={vendorInstalled:false,applicationMigrated:false,applicationValidated:false,acceptedRelease:false,fullDashboard:false};
const sourceEvidence=()=>{
  const manifest=json(path.join(root,'runtime/manifest.json'));
  assert.equal(manifest.sourceRevision,commit);
  assert.equal(Object.keys(manifest.sourceHashes).length,2245);
  const names=Object.keys(manifest.sourceHashes);
  const blobs=git(['cat-file','--batch'],{input:names.map(name=>commit+':'+name+'\n').join('')});
  let position=0;
  for(const [name,digest]of Object.entries(manifest.sourceHashes)){
    assert.equal(fileHash(path.join(app,name)),digest,'Packaged source changed: '+name);
    const end=blobs.indexOf(10,position),header=blobs.subarray(position,end).toString().split(' ');
    assert.equal(header[1],'blob');const size=Number(header[2]);assert.ok(Number.isSafeInteger(size)&&size>=0);
    position=end+1;assert.equal(hash(blobs.subarray(position,position+size)),digest,'Packaged source does not match Git: '+name);position+=size+1;
  }
  assert.equal(position,blobs.length);
  const contentHash=hash(Object.keys(manifest.sourceHashes).sort().map(name=>name+'\0'+manifest.sourceHashes[name]+'\n').join(''));
  assert.equal(contentHash,'946d76f57a8798fd5ff2c5b4fa98cf8c29c804bbab6abf6f51898d9e8f3cf2ce','Application source differs from frozen55e81f9 compatibility pilot.');
  const helper='.github/scripts/laravel12-full-suite-fixtures.cjs';
  const originalHelper='tests/desktop_dashboard_runtime/full-schema.php',report='tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json';
  assert.equal(fileHash(path.join(repository,originalHelper)),'ddb64a18b4a40404daaedaac3af3508403bbc357be7f00fc079cf8a95be64ae0');
  assert.equal(fileHash(path.join(repository,report)),'20b933f7ec95b25d32ab65f1225262f867bef2b66457c0a8c152d4f5cea9b6a5');
  return {commit,tree,ref:env.GITHUB_REF,applicationBase:'55e81f9de09e5a9aea9ae2a7f1d5956429a17041',sourceFiles:2245,sourceContentSha256:contentHash,sourceFingerprint:manifest.sourceFingerprint,files:Object.fromEntries([helper,originalHelper,report,'.github/scripts/laravel12-windows-full-suite.cjs','.github/workflows/laravel12-windows-full-suite.yml','app/Http/Kernel.php','app/Http/Middleware/TrustProxies.php','database/migrations/2022_08_05_174522_create_permission_tables.php','app/Services/Dashboard/DesktopDashboardReconciliation.php','app/Http/Controllers/Dashboard/RolesController.php','resources/lang/ar/validation.php','tests/desktop_dashboard_runtime/run.php','tests/desktop_dashboard_runtime/legacy.php','tests/desktop_dashboard_runtime/remote-attempts.php','tests/desktop_dashboard_runtime/native-delete-form-contract.php'].map(name=>[name,{sha256:fileHash(path.join(repository,name)),blob:git(['rev-parse',commit+':'+name]).toString().trim()}]))};
};
async function main(){
  if(mode==='prepare'){
    assert.equal(fs.existsSync(path.join(root,'runtime')),false);
    assert.equal(fs.existsSync(artifact),false);
    fs.mkdirSync(artifact,{recursive:true});
    const seed=path.join(root,'dependency-seed');fs.mkdirSync(path.join(seed,'vendor'),{recursive:true});
    for(const name of Object.keys(expected))fs.copyFileSync(path.join(repository,inputRoot,name),path.join(seed,name));
    await require(path.join(repository,'desktop-pos/build-runtime.cjs'))({source:repository,target:path.join(root,'runtime'),dependencyRoot:seed,revision:commit});
    for(const directory of ['storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'])fs.mkdirSync(path.join(app,directory),{recursive:true});
    const receipt={format:1,kind:'isolated-windows-laravel12-original-full-suite-diagnostic',source:sourceEvidence(),inputs:{candidate:expected,original:inputHashes},run:{repository:env.GITHUB_REPOSITORY,id:env.GITHUB_RUN_ID,attempt:env.GITHUB_RUN_ATTEMPT},status:statuses,windowsProof:'notRun',linuxProof:{commit:'55e81f9de09e5a9aea9ae2a7f1d5956429a17041',platform:'Linux PHP8.3.6/Maria10.11.14',coreChecks:158,legacyChecks:722,logSha256:'267ba1a3fff27cf30eec568a4c6da421eed2b656b2e922513d3ffda616584149',independentFromWindows:true},limitations:['role and role_or_permission aliases remain unexecuted/old plural namespace','route:list remains blocked by intentionally excluded Firebase credentials','Original synthetic106 column/FK table shapes; only five permission tables use actual original migration','Linux880 completed checks are independent of this pending Windows execution','Browser/native generation/installer modules are not run; no provider credentials/fakes or full coverage claim','Original nullable created_at category-list limitation remains outside the suite']};
    fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
    console.log('WINDOWS_LARAVEL12_FULL_SUITE_SOURCE_PREPARED:2245 exact Git source files; fresh diagnostic inputs.');
    return;
  }
  const receipt=json(receiptPath);assert.deepEqual(receipt.inputs.original,inputHashes);
  for(const [name,digest]of Object.entries(expected))assert.equal(fileHash(path.join(app,name)),digest,'Installed Composer input changed.');
  const manifest=json(path.join(app,'composer.json'));
  assert.ok(!Object.hasOwn(manifest.config||{},'audit')&&!Object.hasOwn(manifest.config||{},'policy'));
  assert.deepEqual(manifest.config.platform,{php:'8.2.33'});
  const platform=json(path.join(artifact,'platform.json'));
  assert.equal(platform.version,'8.2.34');assert.equal(platform.os_family,'Windows');assert.equal(platform.int_size,8);
  const extensions=['pdo_mysql','pdo_sqlite','sqlite3','mbstring','exif','gd','fileinfo','intl','curl','openssl','sodium'];
  assert.deepEqual(Object.keys(platform.extensions).sort(),extensions.sort());for(const value of Object.values(platform.extensions))assert.equal(value.loaded,true);
  const composerText=fs.readFileSync(path.join(artifact,'composer-version.txt'),'utf8');
  assert.equal(/Composer version (\d+\.\d+\.\d+)\b/.exec(composerText)?.[1],'2.10.3');
  const exits=json(path.join(artifact,'command-exits.json'));assert.deepEqual(exits,{install:0,platform:0,audit:0,core:mode==='verify'?0:null,legacy:mode==='verify'?0:null});
  const installedData=json(path.join(app,'vendor/composer/installed.json')),installed=Array.isArray(installedData)?installedData:installedData.packages;
  const locked=json(path.join(app,'composer.lock')).packages;assert.equal(locked.length,139);assert.equal(installed.length,139);
  const versions=rows=>Object.fromEntries(rows.map(row=>[row.name,row.version]));
  assert.equal(Object.keys(versions(installed)).length,139);assert.deepEqual(versions(installed),versions(locked));
  const audit=json(path.join(artifact,'audit.json'));assert.ok(Object.hasOwn(audit,'advisories')&&Object.hasOwn(audit,'abandoned'));
  assert.ok(Object.keys(audit).every(key=>['advisories','ignored-advisories','abandoned','filter','unreachable-repositories'].includes(key)));
  for(const field of ['advisories','ignored-advisories','abandoned','filter','unreachable-repositories'])assert.equal(Object.keys(audit[field]||[]).length,0,'Strict audit findings: '+field);
  const requirements=json(path.join(artifact,'platform-requirements.json'));assert.ok(Array.isArray(requirements)&&requirements.length===21);for(const row of requirements)assert.equal(row.status,'success');
  if(mode==='dependencies'){
    assert.deepEqual(sourceEvidence(),receipt.source);
    receipt.platform={...platform,composerVersion:'2.10.3',resolutionPhp:'8.2.33'};
    receipt.installed={packages:139,versions:versions(installed),lockSha256:expected['composer.lock'],actualInstalledMatchesLock:true};
    receipt.windowsProof={dependenciesInstalled:true,completed:false,suites:'notRun',auditFindings:0,platformRequirementsPassed:true};
    receipt.status={...statuses,vendorInstalled:true};
    fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
    console.log('WINDOWS_LARAVEL12_FULL_SUITE_DEPENDENCIES_COMPLETE:139 actual installed versions match the unchanged candidate lock.');
    return;
  }
  if(mode==='fixtures'){
    assert.ok(php);assert.equal(receipt.windowsProof.dependenciesInstalled,true);
    assert.equal(process.env.DESKTOP_TEST_BROWSER_MODULE,undefined);assert.equal(process.env.DESKTOP_TEST_SNAPSHOT_FILE,undefined);
    const fixtureRoot=path.join(root,'private-suite-fixtures');
    const fixture=require(path.join(repository,'.github/scripts/laravel12-full-suite-fixtures.cjs')).prepare(repository,app,fixtureRoot,commit,php);
    assert.equal(fixture.fixture.files,39);assert.equal(fixture.fixture.baselineTree,'0fcd11fe0ec2426ed3966cb13a96d36de97add86');
    assert.equal(fixture.source.freshPackagingCommit,commit);assert.equal(fixture.source.sourceContentSha256,receipt.source.sourceContentSha256);
    receipt.privateFixture={...fixture,receiptSha256:fileHash(path.join(fixtureRoot,'candidate-fixture-receipt.json'))};
    fs.copyFileSync(path.join(fixtureRoot,'candidate-fixture-receipt.json'),path.join(artifact,'private-fixture-receipt.json'));
    fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
    console.log('WINDOWS_LARAVEL12_FULL_SUITE_FIXTURES_COMPLETE:39 original fixture files; pinned12 setup; default8 unchanged.');
    return;
  }
  const fixture=json(path.join(artifact,'private-fixture-receipt.json'));
  assert.equal(fileHash(path.join(root,'private-suite-fixtures/candidate-fixture-receipt.json')),receipt.privateFixture.receiptSha256);
  assert.deepEqual({...fixture,receiptSha256:receipt.privateFixture.receiptSha256},receipt.privateFixture);
  for(const [name,row]of Object.entries(fixture.fixture.changes))assert.equal(fileHash(path.join(root,'private-suite-fixtures',name)),row.privateSha256);
  const originalNames=git(['ls-tree','-r','--name-only',fixture.fixture.baselineTree,'--','tests/desktop_dashboard_runtime','desktop-pos/src/dashboard-source.cjs','deployment/desktop_dashboard_inspect.php']).toString().trim().split('\n');
  assert.equal(originalNames.length,39);
  for(const name of originalNames){
    const digest=fixture.fixture.changes[name]?.privateSha256||hash(git(['show',fixture.fixture.baselineTree+':'+name]));
    assert.equal(fileHash(path.join(root,'private-suite-fixtures',name)),digest,'Private fixture source changed after execution: '+name);
  }
  const core=fs.readFileSync(path.join(artifact,'core-output.log'),'utf8'),legacy=fs.readFileSync(path.join(artifact,'legacy-output.log'),'utf8');
  const completed=(text,count,summary)=>{
    assert.equal(text.split(/\r?\n/).filter(line=>line.startsWith('PASS ')).length,count);
    assert.equal(text.split(/\r?\n/).filter(line=>line===summary).length,1);
    assert.ok(!/^Original (?:runtime|legacy) fixture did not complete\.$/m.test(text));
  };
  completed(core,158,'158 checks passed using the original Laravel application and real MariaDB');
  completed(legacy,722,'722 legacy schema checks passed');
  const mandatory=[
    'PASS all 106 inspected schemas are available for original dashboard queries',
    'PASS the full inspected dataset imports with legacy cyclic foreign keys intact',
    'PASS the original role controller creates its local role and permission pivot with one command',
    'PASS the original role update replaces permissions and captures its complete role before-state',
    'PASS a saved local role creation response cannot bypass its currently revoked original permission',
    'PASS the real local original availability endpoint and saved reply preserve restaurant-owner user_id fallback',
    'PASS the real server reconciliation HTTP endpoint preserves current original owner fallback and deduplicates its exact receipt',
    'PASS settlement observes the final server commit after the real write lock is released',
    'CANDIDATE_AUTHENTICATED_CSRF_READY local-owner','CANDIDATE_AUTHENTICATED_CSRF_READY remote-owner','CANDIDATE_AUTHENTICATED_CSRF_READY remote-foreign',
  ];
  const legacyLines=legacy.split(/\r?\n/);for(const marker of mandatory)assert.ok(legacyLines.includes(marker),'Missing real original mandatory marker: '+marker);
  const before=json(path.join(artifact,'database-before.json')),after=json(path.join(artifact,'database-after.json'));
  for(const database of [before,after]){assert.match(database.mariaVersion,/^11\.4\.13-MariaDB/);assert.equal(database.foreignKeysEnabled,true);assert.deepEqual(database.platform,{php:'8.2.34',osFamily:'Windows',intSize:8});}
  assert.deepEqual(after.databases,before.databases,'Original fixtures left a private application database behind.');
  assert.equal(fingerprint.same(await fingerprint.fingerprint(app),receipt.source.sourceFingerprint),true);
  assert.deepEqual(sourceEvidence(),receipt.source);
  receipt.commands={strictInstall:['install','--no-dev','--no-scripts','--no-interaction','--prefer-dist','--no-progress'],lockedAudit:['audit','--locked','--no-dev','--format=json','--no-interaction'],exits};
  receipt.platform={...platform,composerVersion:'2.10.3',resolutionPhp:'8.2.33',mariaVersion:after.mariaVersion};
  receipt.installed={packages:139,versions:versions(installed),lockSha256:expected['composer.lock'],actualInstalledMatchesLock:true};
  receipt.windowsProof={completed:true,coreChecks:158,legacyChecks:722,totalPassLines:880,mandatoryMarkers:mandatory,logs:{coreSha256:hash(core),legacySha256:hash(legacy)},privateDatabaseCleanupVerified:true,databaseBefore:before,databaseAfter:after,auditFindings:0,platformRequirementsPassed:true,browserModuleEnabled:false};
  receipt.status={...statuses,vendorInstalled:true};
  fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
  console.log('WINDOWS_LARAVEL12_FULL_SUITE_COMPLETE:139 exact packages;158 core;722 original legacy; source/fixture/database bindings; migration/release/full flags false.');
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});
