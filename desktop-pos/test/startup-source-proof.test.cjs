'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const {spawnSync}=require('node:child_process');
const {validate,parse,verify,SOURCES,PACKAGED_SOURCES,TRUE_FLAGS,FALSE_FLAGS}=require('../../.github/scripts/verify-startup-source-native.cjs');
const repository=path.resolve(__dirname,'../..'),revision='7'.repeat(40),hash=raw=>crypto.createHash('sha256').update(raw).digest('hex');
function fixture(t){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'go-erp-startup-proof-'));t.after(()=>fs.rmSync(root,{recursive:true,force:true}));
  const proof={format:1,kind:'go-erp-startup-source-native',sourceRevision:revision,installedAsarCode:false,
    ...Object.fromEntries(TRUE_FLAGS.map(name=>[name,true])),...Object.fromEntries(FALSE_FLAGS.map(name=>[name,false])),
    sourceHashes:Object.fromEntries(Object.entries(SOURCES).map(([name,file])=>[name,hash(fs.readFileSync(path.join(repository,file)))])),
    packagedNativeHashes:Object.fromEntries(Object.entries(PACKAGED_SOURCES).map(([name,file])=>[name,hash(fs.readFileSync(path.join(repository,file)))]))};
  const line=value=>'GO_ERP_STARTUP_SOURCE_NATIVE '+JSON.stringify(value);
  const log='PASS existing original generation assertion\r\n'+line(proof)+'\r\nPASS existing supervisor restart assertion\r\n';
  const file=path.join(root,'native.log'),receipt=path.join(root,'native.json');fs.writeFileSync(file,log);
  return {root,proof,line,log,file,receipt,options:{root:repository,revision}};
}
test('one complete current native marker preserves all actual and synthetic fixture scopes',t=>{
  const f=fixture(t);assert.equal(validate(f.proof,f.options),f.proof);assert.deepEqual(parse(f.log,f.options),f.proof);
  assert.deepEqual(verify(f.file,f.receipt,f.options),f.proof);assert.deepEqual(JSON.parse(fs.readFileSync(f.receipt,'utf8')),f.proof);
  assert.equal(fs.readFileSync(f.file,'utf8'),f.log,'The original fixture output must remain unchanged.');
});
test('missing, duplicate, malformed and prefixed native markers fail closed',t=>{
  const f=fixture(t);
  for(const output of ['PASS supervisor restart only',f.log+f.line(f.proof)+'\n','prefix '+f.line(f.proof)+'\n',
    'GO_ERP_STARTUP_SOURCE_NATIVE not-json\n',f.log+'prefix '+f.line(f.proof)+'\n'])assert.throws(()=>parse(output,f.options));
});
test('every native preservation flag must be true and synthetic authority cannot claim HTTPS or full dashboard proof',t=>{
  const f=fixture(t);
  for(const name of TRUE_FLAGS)for(const value of [undefined,false,1,'true']){
    const proof=structuredClone(f.proof);if(value===undefined)delete proof[name];else proof[name]=value;
    assert.throws(()=>validate(proof,f.options),undefined,name+'='+value);
  }
  for(const name of FALSE_FLAGS)for(const value of [undefined,true,0,'false']){
    const proof=structuredClone(f.proof);if(value===undefined)delete proof[name];else proof[name]=value;
    assert.throws(()=>validate(proof,f.options),undefined,name+'='+value);
  }
});
test('source revision and checkout versus actually installed ASAR scope cannot be substituted',t=>{
  const f=fixture(t),wrong=structuredClone(f.proof);wrong.sourceRevision='c'.repeat(40);
  assert.throws(()=>validate(wrong,f.options));
  assert.throws(()=>validate(f.proof,{...f.options,installedAsarCode:true}));
  const installed={...f.proof,installedAsarCode:true};
  assert.equal(validate(installed,{...f.options,installedAsarCode:true}),installed);
  assert.throws(()=>validate(installed,f.options));
  assert.throws(()=>validate({...f.proof,actualProductionMain:true},f.options));
});
test('omitted, extra, stale or physically changed helper and fixture source hashes cannot certify activation',t=>{
  const f=fixture(t);
  for(const name of Object.keys(SOURCES)){
    const missing=structuredClone(f.proof);delete missing.sourceHashes[name];assert.throws(()=>validate(missing,f.options));
    const stale=structuredClone(f.proof);stale.sourceHashes[name]='0'.repeat(64);assert.throws(()=>validate(stale,f.options));
  }
  const extra=structuredClone(f.proof);extra.sourceHashes.other='0'.repeat(64);assert.throws(()=>validate(extra,f.options));
  const checkout=path.join(f.root,'checkout');
  for(const file of [...Object.values(SOURCES),...Object.values(PACKAGED_SOURCES)]){const target=path.join(checkout,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.copyFileSync(path.join(repository,file),target);}
  assert.doesNotThrow(()=>validate(f.proof,{root:checkout,revision}));
  fs.appendFileSync(path.join(checkout,SOURCES.helper),'\n// changed after native receipt\n');
  assert.throws(()=>validate(f.proof,{root:checkout,revision}),/code changed/);
});
test('the installed ASAR receipt must bind every native module, including the compiled workspace gate',t=>{
  const f=fixture(t);assert.ok(Object.hasOwn(PACKAGED_SOURCES,'workspace-policy.cjs'));
  for(const name of ['workspace-policy.cjs','workspace-runtime.cjs','workspace-login.cjs','main.cjs']){
    const missing=structuredClone(f.proof);delete missing.packagedNativeHashes[name];assert.throws(()=>validate(missing,f.options));
    const changed=structuredClone(f.proof);changed.packagedNativeHashes[name]='0'.repeat(64);assert.throws(()=>validate(changed,f.options));
  }
});
test('the standalone CLI creates one strict proof and cannot overwrite a prior proof or accept an old source',t=>{
  const f=fixture(t),helper=path.join(repository,'.github/scripts/verify-startup-source-native.cjs');
  const run=(sha=revision)=>spawnSync(process.execPath,[helper,f.file,f.receipt],{env:{...process.env,GITHUB_SHA:sha},encoding:'utf8'});
  const current=run();assert.equal(current.status,0,current.stderr);assert.match(current.stdout,/PASS exact Windows PHP\/MariaDB/);
  const before=fs.readFileSync(f.receipt);assert.notEqual(run().status,0);assert.deepEqual(fs.readFileSync(f.receipt),before);
  fs.unlinkSync(f.receipt);assert.notEqual(run('8'.repeat(40)).status,0);assert.equal(fs.existsSync(f.receipt),false);
});
