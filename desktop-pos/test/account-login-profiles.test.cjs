'use strict';
// Actual encrypted on-disk registry plus the original native sign-in broker.
// The HTTP adapter below is synthetic; Laravel role/branch permissions have
// separate original-runtime acceptance and are not asserted by this fixture.
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto');
const AccountProfiles=require('../src/account-profiles.cjs');
const {AccountLogin}=require('../src/account-login.cjs');
const {GenerationStore}=require('../src/dashboard-generation.cjs');
const origin='https://dashboard.example.test',password='synthetic-registry-restart-password',csrf='a'.repeat(40);
const users=[{input:'00501000123',normalized:'0501000123',actorId:84},{input:'owner@example.test',normalized:'owner@example.test',actorId:42}];
const response=(body,status,url,location)=>{const value=new Response(body,{status,headers:location?{location}:{}});Object.defineProperty(value,'url',{value:url});return value;};
function cookieJar(){
  let entries=[];
  return {async get(query={}){return structuredClone(entries.filter(value=>(!query.name||value.name===query.name)&&(!query.url||value.domain===new URL(query.url).hostname)));},
    async set(value){entries=entries.filter(item=>item.name!==value.name);entries.push({...value,domain:new URL(value.url).hostname,session:!value.expirationDate});},
    async remove(_url,name){entries=entries.filter(item=>item.name!==name);},async flushStore(){},clear(){entries=[];}};
}
async function fixture(t){
  const root=await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()),'login-profiles-restart-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const key=crypto.randomBytes(32),safeStorage={isEncryptionAvailable:()=>true,
    encryptString(value){const iv=crypto.randomBytes(12),cipher=crypto.createCipheriv('aes-256-gcm',key,iv);return Buffer.concat([iv,cipher.update(value),cipher.final(),cipher.getAuthTag()]);},
    decryptString(value){const decipher=crypto.createDecipheriv('aes-256-gcm',key,value.subarray(0,12));decipher.setAuthTag(value.subarray(-16));return Buffer.concat([decipher.update(value.subarray(12,-16)),decipher.final()]).toString();}};
  const requests=[],sessions=new Map();
  function createSession(name){
    if(!sessions.has(name)){
      const cookies=cookieJar(),browser={cookies,async clearStorageData(){cookies.clear();delete browser.actor;},async fetch(url,options){
        requests.push({url,method:options.method||'GET',partition:name});assert.equal(options.redirect,'manual');
        if(url===origin+'/admin/login')return response(`<body><form method="POST" action="${origin}/admin/signin" data-dashboard-login><input name="_token" value="${csrf}"></form></body>`,200,url);
        if(url===origin+'/admin/signin'){
          assert.equal(options.method,'POST');const fields=new URLSearchParams(options.body);assert.equal(fields.get('_token'),csrf);
          // Match the original controller's one-zero submitted-login transform.
          const input=fields.get('email'),normalized=input.startsWith('0')?input.slice(1):input,user=users.find(value=>value.normalized===normalized);
          if(!user||fields.get('password')!==password)return response('',302,url,origin+'/admin/login');
          browser.actor=user.actorId;
          await cookies.set({url:origin+'/',name:'laravel_session',value:'synthetic-'+user.actorId,path:'/',httpOnly:true,secure:true});
          return response('',302,url,origin+'/admin/dashboard');
        }
        assert.equal(url,origin+'/admin/choose_type');assert.ok(browser.actor,'The actor document requires an original successful POST in this fresh session.');
        return response(`<body data-dashboard-actor="${browser.actor}:admin"></body>`,200,url);
      }};sessions.set(name,browser);
    }
    return sessions.get(name);
  }
  function restart(){
    const profiles=new AccountProfiles({root,safeStorage});
    const broker=new AccountLogin({profiles,serverOrigin:origin,createSession,createRuntime:account=>({account,metadata:new GenerationStore(account.profile,safeStorage),
      async isPrepared(){return false;},async start(){throw Error('An unprepared account must never start a local runtime.');},async stop(){}})});
    return {profiles,broker};
  }
  return {root,requests,restart};
}
test('Branch first-login with two leading zeroes can reopen, then Owner authenticates into its own profile without registry resets',async t=>{
  const f=await fixture(t),first=f.restart(),sentinel=crypto.randomBytes(64);await fs.writeFile(path.join(f.root,'legacy-account-sentinel.bin'),sentinel);
  const credentials=user=>({email:user.input,password,remember:false});
  const branch=await first.broker.authenticate(credentials(users[0]));
  assert.equal(branch.account.actorId,84);assert.equal(branch.local,false);await first.broker.handoff(branch);await first.broker.stop();
  const stored=await fs.readFile(path.join(f.root,'account-logins.enc'));
  const reopened=f.restart();
  assert.equal((await reopened.profiles.findLogin(users[0].input,origin)).id,branch.account.id);
  assert.equal(await reopened.profiles.findLogin(users[1].input,origin),null);
  assert.deepEqual(await fs.readFile(path.join(f.root,'account-logins.enc')),stored);
  const branchAgain=await reopened.broker.authenticate(credentials(users[0]));
  assert.equal(branchAgain.account.id,branch.account.id);assert.equal(branchAgain.account.actorId,84);await reopened.broker.handoff(branchAgain);await reopened.broker.stop();
  const next=f.restart(),owner=await next.broker.authenticate(credentials(users[1]));
  assert.equal(owner.account.actorId,42);assert.notEqual(owner.account.id,branch.account.id);assert.notEqual(owner.account.partition,branch.account.partition);
  assert.equal((await next.profiles.account(branch.account.id)).actorId,84);
  assert.equal((await next.profiles.findLogin(users[0].input,origin)).id,branch.account.id);
  assert.equal((await next.profiles.findLogin(users[1].input,origin)).id,owner.account.id);
  await assert.rejects(next.broker.authenticate({...credentials(users[0]),password:'incorrect-synthetic-password'}),{code:'ACCOUNT_CREDENTIALS_REJECTED'});
  assert.equal((await next.profiles.selected()).id,owner.account.id,'A rejected password never changes the selected identity.');
  assert.deepEqual(await fs.readFile(path.join(f.root,'legacy-account-sentinel.bin')),sentinel);
  assert.equal(f.requests.filter(value=>value.method==='POST').length,4,'Each explicit login must submit its own original password POST.');
  assert.equal(f.requests.every(value=>!value.partition.startsWith('persist:')),true,'Persistent remembered sessions cannot authorize explicit login.');
  await next.broker.stop();
});
