'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),crypto=require('node:crypto'),zlib=require('node:zlib');
const {spawnSync}=require('node:child_process');
const {validate,verify,SOURCES,TRUE_FLAGS,FALSE_FLAGS}=require('../../.github/scripts/verify-account-login-native.cjs');
const repository=path.resolve(__dirname,'../..'),revision='7'.repeat(40),hash=raw=>crypto.createHash('sha256').update(raw).digest('hex');
function png(width=1000,height=650,white=true){
  // A synthetic PNG belongs only to these verifier tests, never to a UI receipt.
  function crc32(raw){let crc=0xffffffff;for(const byte of raw){crc^=byte;for(let n=0;n<8;n++)crc=crc&1?0xedb88320^(crc>>>1):crc>>>1;}return (crc^0xffffffff)>>>0;}
  function chunk(type,body){const label=Buffer.from(type),size=Buffer.alloc(4),crc=Buffer.alloc(4);size.writeUInt32BE(body.length);crc.writeUInt32BE(crc32(Buffer.concat([label,body])));return Buffer.concat([size,label,body,crc]);}
  const header=Buffer.alloc(13);header.writeUInt32BE(width);header.writeUInt32BE(height,4);header[8]=8;header[9]=0;
  const raw=Buffer.alloc((width+1)*height,white?255:0);for(let y=0;y<height;y++)raw[y*(width+1)]=0;
  return Buffer.concat([Buffer.from('89504e470d0a1a0a','hex'),chunk('IHDR',header),chunk('IDAT',zlib.deflateSync(raw)),chunk('IEND',Buffer.alloc(0))]);
}
function fixture(t){
  const folder=fs.mkdtempSync(path.join(os.tmpdir(),'go-erp-login-proof-'));t.after(()=>fs.rmSync(folder,{recursive:true,force:true}));
  const capture=png(),proof={format:1,kind:'go-erp-login-window-native',sourceRevision:revision,platform:'win32',
    electronVersion:JSON.parse(fs.readFileSync(path.join(repository,'desktop-pos/package-lock.json'),'utf8')).packages['node_modules/electron'].version,
    sourceHashes:Object.fromEntries(SOURCES.map(name=>[name,hash(fs.readFileSync(path.join(repository,name)))])),
    ...Object.fromEntries(TRUE_FLAGS.map(name=>[name,true])),...Object.fromEntries(FALSE_FLAGS.map(name=>[name,false])),
    capture:{width:1000,height:650,sha256:hash(capture)}};
  const file=path.join(folder,'native.json');
  const write=(value=proof,image=capture)=>{fs.writeFileSync(file,JSON.stringify(value));fs.writeFileSync(path.join(folder,'native-login.png'),image);};write();
  return {folder,file,proof,capture,write,options:{root:repository,revision}};
}

test('the verifier imports without invoking its CLI and accepts only the complete current native proof',t=>{
  const f=fixture(t);assert.equal(validate(f.proof,{...f.options,capture:f.capture}),f.proof);
  assert.deepEqual(verify(f.file,f.options),f.proof);
  assert.equal(SOURCES.length,15);assert.equal(new Set(SOURCES).size,15);
  for(const field of ['nativePasswordSigninSkipsHome','nativeIdentityCancellation','preparedLocalKeepsMode',
    'preparedLocalHasFreshServerSession','preparedSocialUsesSameActor','preparedOfflineHintSkipsServer',
    'preparedOptionalDeadlineDrained','preparedServerRefusalDrainsRuntime','preparedLoginHasNoLateCookieWrites','preparedServerProofReused'])
    assert.ok(TRUE_FLAGS.includes(field),'The phase-two acceptance must require '+field);
});

test('wrong or missing revision, platform, Electron lock, and fixture scopes fail closed',t=>{
  const f=fixture(t);
  for(const [key,value]of[['sourceRevision','8'.repeat(40)],['platform','linux'],['electronVersion','0.0.0'],['format','1'],['kind','go-erp-native-account-profiles'],
    ...FALSE_FLAGS.flatMap(name=>[[name,true],[name,0],[name,undefined]])]){
    const changed=structuredClone(f.proof);if(value===undefined)delete changed[key];else changed[key]=value;
    assert.throws(()=>validate(changed,{...f.options,capture:f.capture}),undefined,key+'='+value);
  }
  assert.throws(()=>validate(f.proof,{...f.options,revision:undefined,capture:f.capture}));
});

test('every actual UI, small identity and prepared HTTPS acceptance flag is required as a true boolean',t=>{
  const f=fixture(t);
  for(const name of TRUE_FLAGS)for(const value of [undefined,false,1,'true']){
    const changed=structuredClone(f.proof);if(value===undefined)delete changed[name];else changed[name]=value;
    assert.throws(()=>validate(changed,{...f.options,capture:f.capture}),undefined,name+'='+value);
  }
  const old=structuredClone(f.proof);
  for(const name of ['preparedLocalKeepsMode','preparedLocalHasFreshServerSession','preparedSocialUsesSameActor','preparedOfflineHintSkipsServer',
    'preparedOptionalDeadlineDrained','preparedServerRefusalDrainsRuntime','preparedLoginHasNoLateCookieWrites','preparedServerProofReused'])delete old[name];
  assert.throws(()=>validate(old,{...f.options,capture:f.capture}),'A phase-one proof cannot authorize phase-two code.');
});

test('changed, omitted, substituted and extra source bindings cannot authorize the receipt',t=>{
  const f=fixture(t);
  for(const source of SOURCES){
    const wrong=structuredClone(f.proof);wrong.sourceHashes[source]='0'.repeat(64);
    assert.throws(()=>validate(wrong,{...f.options,capture:f.capture}),undefined,source);
    const omitted=structuredClone(f.proof);delete omitted.sourceHashes[source];
    assert.throws(()=>validate(omitted,{...f.options,capture:f.capture}),undefined,'omitted '+source);
  }
  const extra=structuredClone(f.proof);extra.sourceHashes['desktop-pos/src/not-reviewed.cjs']='0'.repeat(64);
  assert.throws(()=>validate(extra,{...f.options,capture:f.capture}));
  const renamed=structuredClone(f.proof);renamed.sourceHashes['desktop-pos/src/other.cjs']=renamed.sourceHashes[SOURCES[0]];delete renamed.sourceHashes[SOURCES[0]];
  assert.throws(()=>validate(renamed,{...f.options,capture:f.capture}));
});

test('the verifier freshly hashes the actual source bytes instead of trusting a matching source roster',t=>{
  const f=fixture(t),root=path.join(f.folder,'checkout');
  for(const name of [...SOURCES,'desktop-pos/package-lock.json']){const target=path.join(root,name);fs.mkdirSync(path.dirname(target),{recursive:true});fs.copyFileSync(path.join(repository,name),target);}
  assert.doesNotThrow(()=>validate(f.proof,{root,revision,capture:f.capture}));
  fs.appendFileSync(path.join(root,'desktop-pos/src/dashboard-live-social.cjs'),'\n// changed after native proof\n');
  assert.throws(()=>validate(f.proof,{root,revision,capture:f.capture}),/Native login source changed/);
});

test('a copied, missing, altered, non-PNG or dimension-mismatched capture cannot reuse a native receipt',t=>{
  const f=fixture(t);
  for(const capture of [png(1000,650,false),f.capture.subarray(0,-1),Buffer.alloc(33)])
    assert.throws(()=>validate(f.proof,{...f.options,capture}));
  const dimensions=structuredClone(f.proof);dimensions.capture.width=1001;
  assert.throws(()=>validate(dimensions,{...f.options,capture:f.capture}),/width differs/);
  const tooSmall=structuredClone(f.proof);tooSmall.capture.width=999;
  assert.throws(()=>validate(tooSmall,{...f.options,capture:f.capture}));
  fs.unlinkSync(path.join(f.folder,'native-login.png'));assert.throws(()=>verify(f.file,f.options));
});

test('the one-path CLI verifies the bound image and current GITHUB_SHA, then fails for an old capture',t=>{
  const f=fixture(t),helper=path.join(repository,'.github/scripts/verify-account-login-native.cjs');
  const run=()=>spawnSync(process.execPath,[helper,f.file],{env:{...process.env,GITHUB_SHA:revision},encoding:'utf8'});
  const success=run();assert.equal(success.status,0,success.stderr);assert.match(success.stdout,/PASS exact Windows native login/);
  f.write(f.proof,png(1000,650,false));const copied=run();assert.notEqual(copied.status,0);assert.match(copied.stderr,/capture differs/);
  const missing=spawnSync(process.execPath,[helper],{env:{...process.env,GITHUB_SHA:revision},encoding:'utf8'});assert.notEqual(missing.status,0);
});
