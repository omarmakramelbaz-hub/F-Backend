'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const AccountProfiles=require('../src/account-profiles.cjs'),LocalRuntime=require('../src/local-runtime.cjs');
async function fixture(t){
  // Hosted Windows TEMP can use a RUNNER~1 short-name alias. The production
  // registry intentionally rejects aliased roots; give ordinary fixtures the
  // real directory spelling while testing deliberate junctions separately.
  const temporary=await fs.realpath(os.tmpdir());
  const root=await fs.mkdtemp(path.join(temporary,'account-profiles-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  assert.equal(await fs.realpath(root),root);
  const key=crypto.randomBytes(32),safeStorage={isEncryptionAvailable:()=>true,
    encryptString(value){const iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);return Buffer.concat([iv,cipher.update(value),cipher.final(),cipher.getAuthTag()]);},
    decryptString(value){const decipher=crypto.createDecipheriv('aes-256-gcm',key,value.subarray(0,12));decipher.setAuthTag(value.subarray(-16));return Buffer.concat([decipher.update(value.subarray(12,-16)),decipher.final()]).toString();}};
  return {root,safeStorage,profiles:new AccountProfiles({root,safeStorage}),restart:()=>new AccountProfiles({root,safeStorage})};
}
const origin='https://fasakhaninja.com';

test('trusted login aliases survive restart encrypted and do not change the selected account or legacy index shape',async t=>{
  const f=await fixture(t),branch=await f.profiles.create();await f.profiles.bind(branch.id,{actorId:10,serverOrigin:origin});
  const index=await fs.readFile(path.join(f.root,'account-profiles.enc'));
  await f.profiles.rememberLogin(branch.id,{actorId:10,serverOrigin:origin,password:'PRIVATE_PASSWORD'},'01012345678');
  await f.profiles.rememberLogin(branch.id,{actorId:10,serverOrigin:origin},'Branch@Example.test');
  assert.equal((await f.restart().findLogin('1012345678',origin)).id,branch.id);
  assert.equal((await f.restart().findLogin('branch@example.test',origin)).id,branch.id);
  assert.equal((await f.profiles.selected()).id,'default');
  assert.deepEqual(await fs.readFile(path.join(f.root,'account-profiles.enc')),index);
  const raw=await fs.readFile(path.join(f.root,'account-logins.enc')),aliases=await f.profiles.metadata.read('account-logins');
  assert.equal(raw.includes(Buffer.from('branch@example.test')),false);assert.equal(JSON.stringify(aliases).includes('PRIVATE_PASSWORD'),false);
  assert.equal(await f.profiles.findLogin('branch@example.test','https://other.invalid'),null);
  assert.equal((await f.profiles.forActor(10,origin)).partition,branch.partition);
});

test('a trusted login with repeated leading zeroes survives restart without blocking another account or changing its meaning',async t=>{
  const f=await fixture(t),branch=await f.profiles.create(),owner=await f.profiles.create();
  await f.profiles.bind(branch.id,{actorId:10,serverOrigin:origin});await f.profiles.bind(owner.id,{actorId:11,serverOrigin:origin});
  // The original Laravel controller removes exactly one leading zero. The
  // stored alias is already normalized and must not lose another on reading.
  await f.profiles.rememberLogin(branch.id,{actorId:10,serverOrigin:origin},'00501000123');
  const before=await fs.readFile(path.join(f.root,'account-logins.enc'));
  const restarted=f.restart();
  assert.equal((await restarted.findLogin('00501000123',origin)).id,branch.id);
  assert.equal(await restarted.findLogin('0501000123',origin),null,'Removing a different zero is another original login identity.');
  assert.equal(await restarted.findLogin('owner@example.test',origin),null,'A stored branch alias must not poison unrelated account lookup.');
  assert.deepEqual(await fs.readFile(path.join(f.root,'account-logins.enc')),before,'Previously written version-56 metadata needs no rewrite.');
  await restarted.rememberLogin(owner.id,{actorId:11,serverOrigin:origin},'owner@example.test');
  assert.equal((await f.restart().findLogin('owner@example.test',origin)).id,owner.id);
  assert.equal((await f.restart().findLogin('00501000123',origin)).id,branch.id);
  assert.equal((await f.profiles.selected()).id,'default');
});

test('an alias cannot bind another actor and corrupt or duplicate alias rows never select a profile',async t=>{
  const f=await fixture(t),first=await f.profiles.create(),second=await f.profiles.create();
  await f.profiles.bind(first.id,{actorId:10,serverOrigin:origin});await f.profiles.bind(second.id,{actorId:11,serverOrigin:origin});
  await f.profiles.rememberLogin(first.id,{actorId:10,serverOrigin:origin},'account@example.test');
  const good=await f.profiles.metadata.read('account-logins');
  await assert.rejects(f.profiles.rememberLogin(second.id,{actorId:11,serverOrigin:origin},'account@example.test'),/مرتبط/);
  await assert.rejects(f.profiles.rememberLogin(first.id,{actorId:11,serverOrigin:origin},'other@example.test'));
  for(const mutate of [value=>{value.entries[0].actorId=11;},value=>{value.entries.push({...value.entries[0]});},value=>{value.entries[0].id='../outside';},
    value=>{value.entries[0].login='ACCOUNT@example.test';},value=>{value.entries[0].login=' account@example.test';},
    value=>{value.entries[0].login='account@example.test\n';},value=>{value.entries[0].login='';},value=>{value.password='PRIVATE_PASSWORD';}]){
    const value=structuredClone(good);mutate(value);await f.profiles.metadata.write('account-logins',value);
    await assert.rejects(f.restart().findLogin('account@example.test',origin));assert.equal((await f.profiles.selected()).id,'default');
  }
  await f.profiles.metadata.write('account-logins',good);
  assert.equal((await f.profiles.findLogin('account@example.test',origin)).actorId,10);
});

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
  const f=await fixture(t),outside=await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()),'account-outside-'));t.after(()=>fs.rm(outside,{recursive:true,force:true}));
  const created=await f.profiles.create(),marker=path.join(outside,'keep');await fs.writeFile(marker,'outside-retained');
  assert.equal((await f.profiles.select(created.id)).id,created.id,'The ordinary profile must pass before introducing a malicious junction.');
  await fs.rmdir(created.profile);await fs.symlink(outside,created.profile,process.platform==='win32'?'junction':'dir');
  assert.equal((await fs.lstat(created.profile)).isSymbolicLink(),true,'The rejection must exercise a real profile link/junction.');
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
