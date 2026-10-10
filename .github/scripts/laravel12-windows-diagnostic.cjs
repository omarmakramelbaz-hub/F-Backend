'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const [mode,repository,root]=process.argv.slice(2),env=process.env;
assert.ok(['prepare','dependencies','verify'].includes(mode)&&repository&&root);
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const fileHash=file=>fs.existsSync(file)?hash(fs.readFileSync(file)):null;
const json=file=>JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,''));
const git=(args,options={})=>execFileSync('git',args,{cwd:repository,windowsHide:true,maxBuffer:128*1024**2,...options});
const commit=git(['rev-parse','HEAD']).toString().trim(),tree=git(['rev-parse','HEAD^{tree}']).toString().trim();
assert.equal(commit,env.GITHUB_SHA);assert.equal(env.GITHUB_REF,'refs/heads/codex/laravel12-windows-diagnostic-20261010');
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
const receiptPath=path.join(artifact,'windows-pilot-receipt.json');
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
  assert.equal(contentHash,'48f80de58494dbea65d3e135fbfd071e12dec2b6fe010d57a87c24fcc3cd6424','Application source differs from frozen aac5822 Linux pilot.');
  const helper='.github/scripts/laravel12-windows-fixture.php';
  const originalHelper='tests/desktop_dashboard_runtime/full-schema.php',report='tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json';
  assert.equal(fileHash(path.join(repository,originalHelper)),'ddb64a18b4a40404daaedaac3af3508403bbc357be7f00fc079cf8a95be64ae0');
  assert.equal(fileHash(path.join(repository,report)),'20b933f7ec95b25d32ab65f1225262f867bef2b66457c0a8c152d4f5cea9b6a5');
  return {commit,tree,ref:env.GITHUB_REF,applicationBase:'aac5822e9ace557c2179c14f197892de64e19522',sourceFiles:2245,sourceContentSha256:contentHash,sourceFingerprint:manifest.sourceFingerprint,files:Object.fromEntries([helper,originalHelper,report,'.github/scripts/laravel12-windows-diagnostic.cjs','.github/workflows/laravel12-windows-diagnostic.yml','app/Http/Kernel.php','database/migrations/2022_08_05_174522_create_permission_tables.php'].map(name=>[name,{sha256:fileHash(path.join(repository,name)),blob:git(['rev-parse',commit+':'+name]).toString().trim()}]))};
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
    const receipt={format:1,kind:'isolated-windows-laravel12-bootstrap-diagnostic',source:sourceEvidence(),inputs:{candidate:expected,original:inputHashes},run:{repository:env.GITHUB_REPOSITORY,id:env.GITHUB_RUN_ID,attempt:env.GITHUB_RUN_ATTEMPT},status:statuses,windowsProof:'notRun',linuxProof:{commit:'aac5822e9ace557c2179c14f197892de64e19522',platform:'Linux PHP8.3.6',independentFromWindows:true},limitations:['role and role_or_permission aliases remain unexecuted/old plural namespace','route:list remains blocked by intentionally excluded Firebase credentials','synthetic106 column/FK table shapes; only five permission tables use actual original migration','original web entrypoint desktopLocal=false; empty roles listing','no broader migration, provider, desktop gateway/browser/installer or release validation']};
    fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
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
  const exits=json(path.join(artifact,'command-exits.json'));assert.deepEqual(exits,{install:0,platform:0,audit:0,fixture:mode==='verify'?0:null});
  const installedData=json(path.join(app,'vendor/composer/installed.json')),installed=Array.isArray(installedData)?installedData:installedData.packages;
  const locked=json(path.join(app,'composer.lock')).packages;assert.equal(locked.length,139);assert.equal(installed.length,139);
  const versions=rows=>Object.fromEntries(rows.map(row=>[row.name,row.version]));
  assert.equal(Object.keys(versions(installed)).length,139);assert.deepEqual(versions(installed),versions(locked));
  const audit=json(path.join(artifact,'audit.json'));assert.ok(Object.hasOwn(audit,'advisories')&&Object.hasOwn(audit,'abandoned'));
  assert.ok(Object.keys(audit).every(key=>['advisories','ignored-advisories','abandoned','filter','unreachable-repositories'].includes(key)));
  for(const field of ['advisories','ignored-advisories','abandoned','filter','unreachable-repositories'])assert.equal(Object.keys(audit[field]||[]).length,0,'Strict audit findings: '+field);
  const requirements=json(path.join(artifact,'platform-requirements.json'));assert.ok(Array.isArray(requirements)&&requirements.length);for(const row of requirements)assert.equal(row.status,'success');
  if(mode==='dependencies'){
    assert.deepEqual(sourceEvidence(),receipt.source);
    receipt.platform={...platform,composerVersion:'2.10.3',resolutionPhp:'8.2.33'};
    receipt.installed={packages:139,versions:versions(installed),lockSha256:expected['composer.lock'],actualInstalledMatchesLock:true};
    receipt.windowsProof={dependenciesInstalled:true,completed:false,http:'notRun',auditFindings:0,platformRequirementsPassed:true};
    receipt.status={...statuses,vendorInstalled:true};
    fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
    console.log('WINDOWS_LARAVEL12_DEPENDENCIES_COMPLETE:139 actual installed versions match the unchanged candidate lock.');
    return;
  }
  const fixture=json(path.join(artifact,'fixture-receipt.json'));
  assert.equal(fixture.completed,true);assert.equal(fixture.databaseDropped,true);assert.equal(fixture.fullSchemaTables,106);assert.equal(fixture.syntheticActor,30);assert.equal(fixture.desktopLocal,false);
  assert.deepEqual(fixture.platform,{php:'8.2.34',osFamily:'Windows',intSize:8});assert.equal(fixture.laravel,'12.69.3');assert.match(fixture.mariaVersion,/^11\.4\.13-MariaDB/);
  assert.deepEqual(fixture.proof,{loginGET:200,signinPOST:302,rolesGranted:200,rolesRevoked:403,rolesRestored:200,originalBladeMarkers:true,csrfAndCookies:true,redirectFollowing:false,foreignKeysEnabled:true,emptyRolesListing:true});
  assert.deepEqual(fixture.attempts.map(row=>row.status),[200,302,200,403,200]);
  assert.match(fs.readFileSync(path.join(artifact,'fixture-output.log'),'utf8'),/^LARAVEL12_ORIGINAL_HTTP_COMPLETE \{/m);
  assert.equal(fingerprint.same(await fingerprint.fingerprint(app),receipt.source.sourceFingerprint),true);
  assert.deepEqual(sourceEvidence(),receipt.source);
  receipt.platform={...platform,composerVersion:'2.10.3',resolutionPhp:'8.2.33',mariaVersion:fixture.mariaVersion};
  receipt.commands={strictInstall:['install','--no-dev','--no-scripts','--no-interaction','--prefer-dist','--no-progress'],lockedAudit:['audit','--locked','--no-dev','--format=json','--no-interaction'],exits};
  receipt.installed={packages:139,versions:versions(installed),lockSha256:expected['composer.lock'],actualInstalledMatchesLock:true};
  receipt.windowsProof={completed:true,fixture,auditFindings:0,platformRequirementsPassed:true};receipt.status={...statuses,vendorInstalled:true};
  fs.writeFileSync(receiptPath,JSON.stringify(receipt,null,2)+'\n');
  console.log('WINDOWS_LARAVEL12_DIAGNOSTIC_COMPLETE:139 actual installed packages;106 synthetic schema tables; original login and roles200/403/200; full migration/release flags remain false.');
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});
