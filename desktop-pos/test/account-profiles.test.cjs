'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const AccountProfiles=require('../src/account-profiles.cjs'),LocalRuntime=require('../src/local-runtime.cjs');
async function fixture(t){
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'account-profiles-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const key=crypto.randomBytes(32),safeStorage={isEncryptionAvailable:()=>true,
    encryptString(value){const iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);return Buffer.concat([iv,cipher.update(value),cipher.final(),cipher.getAuthTag()]);},
    decryptString(value){const decipher=crypto.createDecipheriv('aes-256-gcm',key,value.subarray(0,12));decipher.setAuthTag(value.subarray(-16));return Buffer.concat([decipher.update(value.subarray(12,-16)),decipher.final()]).toString();}};
  return {root,safeStorage,profiles:new AccountProfiles({root,safeStorage}),restart:()=>new AccountProfiles({root,safeStorage})};
}
const origin='https://fasakhaninja.com';

test('a missing index preserves the original profile, cookie partition and every legacy file byte',async t=>{
  const f=await fixture(t),sentinel=crypto.randomBytes(256);await fs.writeFile(path.join(f.root,'fasakhansta-pos.sqlite'),sentinel);
  assert.deepEqual(await f.profiles.selected(),{id:'default',label:'الحساب الحالي',profile:f.root,partition:'persist:fasakhansta-dashboard'});
  assert.deepEqual(await f.profiles.list(),[{id:'default',label:'الحساب الحالي',selected:true}]);
  assert.deepEqual(await fs.readdir(f.root),['fasakhansta-pos.sqlite']);assert.deepEqual(await fs.readFile(path.join(f.root,'fasakhansta-pos.sqlite')),sentinel);
});

test('two created accounts retain separate native credentials, device IDs, auth storage and partitions across restart',async t=>{
  const f=await fixture(t),[first,second]=await Promise.all([f.profiles.create(),f.profiles.create()]);
  assert.notEqual(first.id,second.id);assert.match(first.id,/^[0-9a-f-]{36}$/);assert.notEqual(first.profile,second.profile);assert.notEqual(first.partition,second.partition);
  assert.equal(first.profile,path.join(f.root,'accounts',first.id));assert.equal(first.partition,'persist:fasakhansta-dashboard-'+first.id);
  assert.equal((await f.profiles.selected()).id,'default');
  const make=descriptor=>new LocalRuntime({bundle:path.join(f.root,'unused-bundle'),profile:descriptor.profile,safeStorage:f.safeStorage});
  const firstRuntime=make(first),secondRuntime=make(second),a=await firstRuntime.settings(),b=await secondRuntime.settings();
  assert.notEqual(a.deviceId,b.deviceId);assert.notEqual(a.appKey,b.appKey);assert.notEqual(a.rootPassword,b.rootPassword);
  assert.deepEqual(await make(first).settings(),a);assert.notEqual(firstRuntime.profile,secondRuntime.profile);
  assert.notEqual(firstRuntime.nativeEnvironment(a,firstRuntime.storageFor(null)).DESKTOP_DASHBOARD_AUTH_STORAGE,
    secondRuntime.nativeEnvironment(b,secondRuntime.storageFor(null)).DESKTOP_DASHBOARD_AUTH_STORAGE);
  const ledger=crypto.randomBytes(30);await fs.writeFile(path.join(first.profile,'saved-outbox.sqlite'),ledger);
  await f.profiles.select(first.id);assert.deepEqual(await f.restart().selected(),first);
  await f.profiles.select(second.id);assert.deepEqual(await fs.readFile(path.join(first.profile,'saved-outbox.sqlite')),ledger);
  await f.profiles.select('default');assert.equal((await f.restart().selected()).profile,f.root);
});

test('binding learns only a trusted actor/origin and never changes an established account identity or stores credential fields',async t=>{
  const f=await fixture(t),created=await f.profiles.create(),credential={actorId:10,serverOrigin:origin,label:'فرع المنصورة',token:'PRIVATE_TOKEN',password:'PRIVATE_PASSWORD'};
  const bound=await f.profiles.assertBinding(created.id,credential);assert.equal(bound.label,'فرع المنصورة');
  await f.restart().assertBinding(created.id,credential);
  await assert.rejects(f.profiles.bind(created.id,{actorId:11,serverOrigin:origin}),/لا يطابق/);
  await assert.rejects(f.profiles.assertBinding(created.id,{actorId:10,serverOrigin:'https://other.invalid'}),/لا يطابق/);
  const record=await f.profiles.metadata.read('account-profiles'),raw=await fs.readFile(path.join(f.root,'account-profiles.enc'));
  assert.equal(JSON.stringify(record).includes('PRIVATE_'),false);assert.equal(raw.includes(Buffer.from('فرع المنصورة')),false);
  assert.deepEqual(Object.keys(record.profiles[1]).sort(),['actorId','id','label','serverOrigin']);
  assert.deepEqual(await f.profiles.list(),[{id:'default',label:'الحساب الحالي',selected:true},{id:created.id,label:'فرع المنصورة',selected:false}]);
});

test('malformed, oversized or path-bearing indexes and unknown IDs fail closed without changing selection',async t=>{
  const f=await fixture(t),created=await f.profiles.create(),good=await f.profiles.metadata.read('account-profiles');
  for(const mutate of [value=>{value.selected='../outside';},value=>{value.profiles[1].profile='/outside';},
    value=>{value.profiles.push({...value.profiles[1]});},value=>{value.profiles[1].actorId=10;},
    value=>{value.profiles[1].label='x\nunsafe';},value=>{value.password='secret';}]){
    const bad=structuredClone(good);mutate(bad);await f.profiles.metadata.write('account-profiles',bad);await assert.rejects(f.restart().selected());
  }
  await f.profiles.metadata.write('account-profiles',good);
  await assert.rejects(f.profiles.select('../outside'));await assert.rejects(f.profiles.select(crypto.randomUUID()));
  await assert.rejects(f.profiles.bind(created.id,{actorId:10,serverOrigin:origin+'/'}));
  assert.equal((await f.profiles.selected()).id,'default');
  await fs.writeFile(path.join(f.root,'account-profiles.enc'),Buffer.alloc(65537));await assert.rejects(f.restart().list());
});

test('profile and metadata symlinks are rejected and their external targets remain untouched',async t=>{
  const f=await fixture(t),outside=await fs.mkdtemp(path.join(os.tmpdir(),'account-outside-'));t.after(()=>fs.rm(outside,{recursive:true,force:true}));
  const created=await f.profiles.create(),marker=path.join(outside,'keep');await fs.writeFile(marker,'outside-retained');
  await fs.rmdir(created.profile);await fs.symlink(outside,created.profile,process.platform==='win32'?'junction':'dir');
  await assert.rejects(f.profiles.select(created.id));await assert.rejects(f.profiles.list());assert.equal(await fs.readFile(marker,'utf8'),'outside-retained');
  await fs.unlink(created.profile);
  await fs.mkdir(created.profile);await fs.rename(path.join(f.root,'account-profiles.enc'),path.join(outside,'index.enc'));
  // Ordinary Windows users can create directory junctions but need elevated
  // privileges for file symlinks. The Linux cohort checks the real file link;
  // Windows still checks that a redirected index directory cannot be opened.
  if(process.platform==='win32')await fs.symlink(outside,path.join(f.root,'account-profiles.enc'),'junction');
  else await fs.symlink(path.join(outside,'index.enc'),path.join(f.root,'account-profiles.enc'));
  await assert.rejects(f.restart().selected());assert.ok((await fs.stat(path.join(outside,'index.enc'))).size>0);
});

test('failed selection writes preserve the previous durable selection even when rename committed before a reported failure',async t=>{
  const f=await fixture(t),created=await f.profiles.create(),write=f.profiles.metadata.write.bind(f.profiles.metadata);
  f.profiles.metadata.write=async()=>{throw Error('synthetic precommit failure');};
  await assert.rejects(f.profiles.select(created.id),/synthetic precommit failure/);assert.equal((await f.restart().selected()).id,'default');
  let attempts=0;
  f.profiles.metadata.write=async(...args)=>{attempts++;if(attempts===1){await write(...args);throw Error('synthetic directory flush failure');}return write(...args);};
  await assert.rejects(f.profiles.select(created.id),/synthetic directory flush failure/);
  assert.equal((await f.restart().selected()).id,'default');assert.equal(attempts,2);
  assert.equal((await fs.stat(created.profile)).isDirectory(),true);
});

test('the registry bounds its rows and serializes independent creates without losing account entries',async t=>{
  const f=await fixture(t);await f.profiles.create();const value=await f.profiles.metadata.read('account-profiles');
  while(value.profiles.length<64){const id=crypto.randomUUID();await fs.mkdir(path.join(f.root,'accounts',id));value.profiles.push({id,label:'حساب جديد',actorId:null,serverOrigin:null});}
  await f.profiles.metadata.write('account-profiles',value);await assert.rejects(f.profiles.create(),/الحد المسموح/);
  assert.equal((await f.profiles.list()).length,64);assert.equal((await f.profiles.selected()).id,'default');
});
