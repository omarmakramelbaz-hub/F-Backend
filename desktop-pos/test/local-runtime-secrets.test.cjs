'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const LocalRuntime=require('../src/local-runtime.cjs');

test('the actual native environment owns a profile-specific JWT secret across restart, refresh and retained-code changes',async t=>{
  const temporary=await fs.mkdtemp(path.join(os.tmpdir(),'native-local-secrets-'));
  t.after(()=>fs.rm(temporary,{recursive:true,force:true}));
  const key=crypto.randomBytes(32);
  const safeStorage={isEncryptionAvailable:()=>true,
    encryptString(value){const iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);return Buffer.concat([iv,cipher.update(value),cipher.final(),cipher.getAuthTag()]);},
    decryptString(value){const cipher=crypto.createDecipheriv('aes-256-gcm',key,value.subarray(0,12));cipher.setAuthTag(value.subarray(-16));return Buffer.concat([cipher.update(value.subarray(12,-16)),cipher.final()]).toString();}};
  const make=profile=>{const runtime=new LocalRuntime({bundle:path.join(temporary,'retained-code'),profile:path.join(temporary,profile),safeStorage});runtime.origin='http://127.0.0.1:33408';runtime.dbPort=33409;return runtime;};
  const previous=process.env.JWT_SECRET;process.env.JWT_SECRET='synthetic-production-secret-never-import';
  t.after(()=>{if(previous===undefined)delete process.env.JWT_SECRET;else process.env.JWT_SECRET=previous;});
  const first=make('first-profile'),settings=await first.settings(),environment=first.nativeEnvironment(settings,first.storageFor(null));
  assert.match(environment.JWT_SECRET,/^[a-f0-9]{64}$/);assert.notEqual(environment.JWT_SECRET,process.env.JWT_SECRET);
  assert.notEqual(environment.JWT_SECRET,environment.APP_KEY);assert.notEqual(environment.JWT_SECRET,Buffer.from(settings.appKey.slice(7),'base64').toString('hex'));
  const encrypted=await fs.readFile(path.join(first.profile,'credentials.enc'));
  assert.equal(encrypted.includes(Buffer.from(settings.appKey)),false);assert.equal(encrypted.includes(Buffer.from(environment.JWT_SECRET)),false);
  const restarted=make('first-profile'),saved=await restarted.settings();
  assert.equal(restarted.nativeEnvironment(saved,restarted.storageFor(null)).JWT_SECRET,environment.JWT_SECRET);
  first.environment=environment;const generation=first.environmentFor({generation:'a'.repeat(16),database:'fasakhansta_dashboard_stage_'+'b'.repeat(16)});
  assert.equal(generation.JWT_SECRET,environment.JWT_SECRET);assert.equal(generation.APP_KEY,environment.APP_KEY);
  first.bundle=path.join(temporary,'different-verified-retained-code');
  assert.equal(first.nativeEnvironment(settings,first.storageFor(null)).JWT_SECRET,environment.JWT_SECRET);
  const other=make('second-profile');assert.notEqual(other.nativeEnvironment(await other.settings(),other.storageFor(null)).JWT_SECRET,environment.JWT_SECRET);
  assert.equal((await fs.readFile(path.join(first.profile,'credentials.enc'))).equals(encrypted),true);
});

test('a malformed native encryption key cannot create a JWT signing environment',()=>{
  const runtime=new LocalRuntime({bundle:'/synthetic-code',profile:'/synthetic-profile',safeStorage:{}});
  for(const appKey of [undefined,'server-secret','base64:','base64:'+Buffer.alloc(31).toString('base64'),'base64:'+Buffer.alloc(33).toString('base64')])
    assert.throws(()=>runtime.nativeEnvironment({appKey},'/synthetic-private-storage'),/مفتاح بيانات الجهاز/);
});
