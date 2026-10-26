const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os');
const source=require('../src/dashboard-source.cjs'),LocalRuntime=require('../src/local-runtime.cjs');
async function fixture(t){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'dashboard-source-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  await fs.mkdir(path.join(root,'app'),{recursive:true});await fs.mkdir(path.join(root,'bootstrap/cache'),{recursive:true});
  await fs.writeFile(path.join(root,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
  await fs.writeFile(path.join(root,'app/business.php'),Buffer.from([60,63,112,104,112,13,10,128,13,10]));return root;
}
test('code binding preserves source bytes across Windows line endings and detects a real business-code change',async t=>{
  const root=await fixture(t),first=await source.fingerprint(root);assert.equal(first.files,1);assert.equal(first.framework,'8.83.29');
  await fs.writeFile(path.join(root,'app/business.php'),Buffer.from([60,63,112,104,112,10,128,10]));
  assert.ok(source.same(first,await source.fingerprint(root)));
  await fs.appendFile(path.join(root,'app/business.php'),'changed business rule');assert.ok(!source.same(first,await source.fingerprint(root)));
});
test('environment files, service credentials and compiled configuration caches do not enter the business-code binding',async t=>{
  const root=await fixture(t),first=await source.fingerprint(root);
  await fs.writeFile(path.join(root,'.env'),'APP_KEY=private fixture');
  await fs.writeFile(path.join(root,'app/service-account-fixture.json'),'private fixture credential');
  await fs.writeFile(path.join(root,'bootstrap/cache/config.php'),'private fixture configuration');
  assert.ok(source.same(first,await source.fingerprint(root)));assert.equal(JSON.stringify(first).includes('private fixture'),false);
  await fs.writeFile(path.join(root,'app/new-rule.php'),'new business code');assert.ok(!source.same(first,await source.fingerprint(root)));
});
test('unknown framework packages and malformed code descriptors cannot become verified sources',async t=>{
  const root=await fixture(t);await fs.writeFile(path.join(root,'composer.lock'),'{}');await assert.rejects(source.fingerprint(root));
  for(const value of [null,{format:1,files:0,sha256:'a'.repeat(64),framework:'8.83.29'},
    {format:1,files:1,sha256:'a'.repeat(64),framework:'8.83.29',extra:'unknown'}])assert.equal(source.valid(value),false);
});
test('an explicit captured contract binds the supported engine without claiming that the engine is Laravel 8',async t=>{
  const root=await fixture(t);await fs.mkdir(path.join(root,'desktop'));
  await fs.writeFile(path.join(root,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v12.70.0'}]}));
  await assert.rejects(source.fingerprint(root),/requires its reviewed source/);
  await fs.writeFile(path.join(root,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v8.83.29'}]}));
  const contract={format:1,framework:'8.83.29',supportedRuntimeFrameworks:['8.83.29','12.70.0']};
  await fs.writeFile(path.join(root,'desktop/source-contract.json'),JSON.stringify(contract));
  const server=await source.fingerprint(root);
  await fs.writeFile(path.join(root,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v12.70.0'}]}));
  const desktop=await source.fingerprint(root);
  assert.ok(source.same(server,desktop));assert.equal(desktop.framework,'8.83.29');
  assert.deepEqual(await source.frameworks(root),{contractFramework:'8.83.29',runtimeFramework:'12.70.0'});
  await fs.writeFile(path.join(root,'composer.lock'),JSON.stringify({packages:[{name:'laravel/framework',version:'v12.71.0'}]}));
  await assert.rejects(source.fingerprint(root),/has not passed/);
  contract.supportedRuntimeFrameworks.push('12.70.0');
  await fs.writeFile(path.join(root,'desktop/source-contract.json'),JSON.stringify(contract));
  await assert.rejects(source.fingerprint(root),/has not passed/);
});
test('a different server code is refused before creating a generation directory or touching the retained journal',async t=>{
  const root=await fixture(t),journal=path.join(root,'retained-journal');await fs.writeFile(journal,'confirmed and pending work');
  const runtime=new LocalRuntime({bundle:root,profile:root,safeStorage:{}});runtime.environment={};runtime.sourceFingerprint=await source.fingerprint(root);
  await assert.rejects(runtime.stage({source:{...runtime.sourceFingerprint,sha256:'f'.repeat(64)},media:[]},'a'.repeat(16)));
  assert.equal(await fs.readFile(journal,'utf8'),'confirmed and pending work');await assert.rejects(fs.access(path.join(runtime.profile,'generations')));
});
test('source fingerprint cancellation interrupts a legacy walk without changing source bytes',async t=>{
  const root=await fixture(t),before=await fs.readFile(path.join(root,'app/business.php'));
  for(let index=0;index<20;index++)await fs.writeFile(path.join(root,'app','rule-'+index+'.php'),'<?php return '+index+';');
  let checks=0;
  await assert.rejects(source.fingerprint(root,{stopped:()=>++checks>=12}),/البرنامج يُغلق/);
  assert.equal(checks,12,'the walk checks cancellation between directory and file reads');
  assert.deepEqual(await fs.readFile(path.join(root,'app/business.php')),before);
  assert.equal((await source.fingerprint(root)).files,21,'cancellation did not replace or remove any source');
});
