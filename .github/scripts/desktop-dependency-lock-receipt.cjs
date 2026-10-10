'use strict';
// Diagnostic evidence only: this never installs or validates the Laravel application.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const [repository,working,artifact]=process.argv.slice(2);
assert.ok(repository&&working&&artifact,'Repository, disposable working directory and artifact directory are required.');
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const fileHash=file=>fs.existsSync(file)?hash(fs.readFileSync(file)):null;
const json=file=>JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,''));
const env=process.env,git=args=>execFileSync('git',args,{cwd:repository,encoding:'utf8',windowsHide:true}).trim();
const sourceCommit=git(['rev-parse','HEAD']);
assert.match(sourceCommit,/^[a-f0-9]{40}$/);assert.equal(sourceCommit,env.GITHUB_SHA);
assert.equal(env.GITHUB_REF,'refs/heads/codex/dependency-lock-diagnostic-20261009');
const overrides=Object.keys(env).filter(key=>/^COMPOSER(?:_|$)/i.test(key)&&!['COMPOSER_HOME','COMPOSER_CACHE_DIR'].includes(key));
assert.equal(overrides.length,0,'An inherited Composer audit, policy or platform override remains.');
for(const key of ['COMPOSER_HOME','COMPOSER_CACHE_DIR']){
  assert.ok(env[key]&&path.resolve(env[key]).startsWith(path.dirname(path.resolve(working))+path.sep),'Composer state must stay in the disposable directory.');
}
const inputs=json(path.join(working,'input-hashes.json'));
const expectedInputs=['composer.json','composer.lock','desktop-pos/runtime/composer.json','desktop-pos/runtime/composer.lock'];
assert.deepEqual(Object.keys(inputs).sort(),expectedInputs.slice().sort());
for(const name of expectedInputs)assert.equal(fileHash(path.join(repository,name)),inputs[name],'A Git input changed: '+name);
assert.equal(git(['status','--porcelain','--',...expectedInputs]),'');
assert.equal(inputs['desktop-pos/runtime/composer.json'],env.EXPECTED_MANIFEST_SHA256);
assert.equal(inputs['desktop-pos/runtime/composer.lock'],env.EXPECTED_OLD_LOCK_SHA256);
assert.equal(fileHash(path.join(working,'composer.json')),inputs['desktop-pos/runtime/composer.json']);
const manifest=json(path.join(working,'composer.json'));
assert.ok(!Object.hasOwn(manifest.config||{},'audit')&&!Object.hasOwn(manifest.config||{},'policy'),'The diagnostic manifest must not contain audit or policy exceptions.');
assert.equal(manifest.config?.platform?.php,'8.2.33');
assert.deepEqual(Object.keys(manifest.config.platform),['php'],'No extension platform emulation is allowed.');
assert.equal(manifest.require?.php,'^8.2');assert.equal(manifest.require?.['laravel/framework'],'^12.69.0');
const platform=json(path.join(working,'platform.json'));
assert.equal(platform.version,'8.2.34');assert.equal(platform.os_family,'Windows');assert.equal(platform.int_size,8);
const required=['pdo_mysql','pdo_sqlite','sqlite3','mbstring','exif','gd','fileinfo','intl','curl','openssl','sodium'];
assert.deepEqual(Object.keys(platform.extensions).sort(),required.slice().sort());
for(const name of required)assert.equal(platform.extensions[name].loaded,true,'Missing actual extension: '+name);
const composerText=fs.readFileSync(path.join(working,'composer-version.txt'),'utf8').trim();
const composerVersion=/Composer version (\d+\.\d+\.\d+)\b/.exec(composerText)?.[1];
assert.equal(composerVersion,'2.10.3');
const exits=json(path.join(working,'command-exits.json'));
assert.equal(exits.update,0);assert.equal(exits.audit,0);
const lock=json(path.join(working,'composer.lock')),lockSha256=fileHash(path.join(working,'composer.lock'));
assert.notEqual(lockSha256,inputs['desktop-pos/runtime/composer.lock'],'The output must be a newly resolved candidate lock.');
assert.equal(lock['platform-overrides']?.php,manifest.config.platform.php);
assert.ok(Array.isArray(lock.packages)&&Array.isArray(lock['packages-dev']));
const packages=[...lock.packages,...lock['packages-dev']],selectedVersions={};
for(const row of packages){
  assert.ok(typeof row.name==='string'&&typeof row.version==='string');
  assert.ok(!Object.hasOwn(selectedVersions,row.name),'Duplicate locked package: '+row.name);
  selectedVersions[row.name]=row.version;
}
for(const name of Object.keys(manifest.require))if(name!=='php')assert.ok(selectedVersions[name],'A direct runtime requirement is missing: '+name);
assert.match(selectedVersions['laravel/framework'],/^v?12\./);
const audit=json(path.join(working,'audit.json'));
assert.ok(Object.hasOwn(audit,'advisories')&&Object.hasOwn(audit,'abandoned'),'The locked audit is incomplete.');
const auditFields=['advisories','ignored-advisories','abandoned','filter','unreachable-repositories'];
assert.ok(Object.keys(audit).every(key=>auditFields.includes(key)),'The locked audit schema has an unreviewed field.');
const collection=value=>{assert.ok(value!==null&&typeof value==='object');return Object.keys(value).length;};
const findings={advisories:collection(audit.advisories),ignoredAdvisories:collection(audit['ignored-advisories']||[]),
  abandoned:collection(audit.abandoned),policy:collection(audit.filter||[]),unreachableRepositories:collection(audit['unreachable-repositories']||[])};
for(const [name,count]of Object.entries(findings))assert.equal(count,0,'The strict locked audit has findings: '+name);
for(const directory of [path.join(repository,'vendor'),path.join(repository,'desktop-pos/runtime/vendor'),path.join(working,'vendor')])
  assert.equal(fs.existsSync(directory),false,'Application dependencies were installed.');
const receipt={format:1,kind:'candidate-dependency-lock-diagnostic',createdAt:new Date().toISOString(),
  source:{commit:sourceCommit,ref:env.GITHUB_REF,repository:env.GITHUB_REPOSITORY,runId:env.GITHUB_RUN_ID,runAttempt:env.GITHUB_RUN_ATTEMPT},
  inputs:{hashes:inputs,manifestSha256:inputs['desktop-pos/runtime/composer.json'],previousLockSha256:inputs['desktop-pos/runtime/composer.lock'],gitInputsUnchanged:true},
  platform:{...platform,composerResolutionPhp:manifest.config.platform.php,composerVersion},
  commands:{update:['update','-W','--no-install','--no-scripts','--no-interaction'],audit:['audit','--locked','--format=json','--no-interaction'],updateExit:exits.update,auditExit:exits.audit},
  output:{lockSha256,auditSha256:fileHash(path.join(working,'audit.json')),packageCount:packages.length,
    selectedVersions:Object.fromEntries(Object.entries(selectedVersions).sort(([a],[b])=>a.localeCompare(b))),findings},
  status:{vendorInstalled:false,applicationMigrated:false,applicationValidated:false,acceptedRelease:false,fullDashboard:false}};
fs.copyFileSync(path.join(working,'composer.lock'),path.join(artifact,'composer.lock'));
fs.copyFileSync(path.join(working,'audit.json'),path.join(artifact,'audit.json'));
assert.equal(fileHash(path.join(artifact,'composer.lock')),lockSha256);
fs.writeFileSync(path.join(artifact,'dependency-lock-receipt.json'),JSON.stringify(receipt,null,2)+'\n');
process.stdout.write('Retained strict candidate lock '+lockSha256+' with '+packages.length+' selected packages and zero audit findings; application migration/validation remain unperformed.\n');
