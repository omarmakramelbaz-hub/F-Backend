'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const {GenerationStore}=require('../src/dashboard-generation.cjs'),signout=require('../src/account-signout.cjs');
test('explicit sign-out survives a fresh encrypted metadata reader and does not change another account or saved work',async t=>{
  const root=await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()),'account-signout-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const key=crypto.randomBytes(32),safeStorage={isEncryptionAvailable:()=>true,
    encryptString(value){const iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);return Buffer.concat([iv,cipher.update(value),cipher.final(),cipher.getAuthTag()]);},
    decryptString(value){const decipher=crypto.createDecipheriv('aes-256-gcm',key,value.subarray(0,12));decipher.setAuthTag(value.subarray(-16));return Buffer.concat([decipher.update(value.subarray(12,-16)),decipher.final()]).toString();}};
  const make=name=>({metadata:new GenerationStore(path.join(root,name),safeStorage)}),first=make('first'),other=make('other');
  await first.metadata.write('prepared',{sentinel:'retained-preparation'});await other.metadata.write('prepared',{sentinel:'other-preparation'});
  const original=await fs.readFile(path.join(root,'first/prepared.enc')),foreign=await fs.readFile(path.join(root,'other/prepared.enc'));
  await signout.signedOut(first);
  assert.equal(await signout.requirePassword(make('first')),true);assert.equal(await signout.requirePassword(other),false);
  const encrypted=await fs.readFile(path.join(root,'first/explicit-signout.enc'));assert.equal(encrypted.includes(Buffer.from('requirePassword')),false);
  await signout.signedIn(make('first'));assert.equal(await signout.requirePassword(make('first')),false);
  assert.deepEqual(await fs.readFile(path.join(root,'first/prepared.enc')),original);assert.deepEqual(await fs.readFile(path.join(root,'other/prepared.enc')),foreign);
});
test('a canceled or failed password-marker commit restores explicit sign-out instead of enabling remembered reentry',async()=>{
  for(const fail of [false,true]){
    let marker={format:1,kind:'go-erp-explicit-signout',requirePassword:true},active=true;
    const runtime={metadata:{async read(){return marker;},async write(_key,value){marker=value;if(value===null){if(fail)throw Error('synthetic-disk-error');active=false;}}}};
    await assert.rejects(signout.signedIn(runtime,{active:()=>active}));assert.equal(await signout.requirePassword(runtime),true);
  }
});
