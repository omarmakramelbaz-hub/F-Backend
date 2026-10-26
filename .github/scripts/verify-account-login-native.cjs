'use strict';
// Source-bound acceptance of the actual Windows login, prepared-account HTTPS
// session and same-actor social fixture. This is not PHP authorization proof.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const ROOT=path.resolve(__dirname,'../..');
const SOURCES=Object.freeze([
  'desktop-pos/src/native-auth-http.cjs','desktop-pos/src/account-login.cjs','desktop-pos/src/account-signout.cjs',
  'desktop-pos/src/dashboard-live-social.cjs','desktop-pos/src/native-deadline.cjs','desktop-pos/src/login-window.cjs',
  'desktop-pos/src/login-preload.cjs','desktop-pos/src/login.js','desktop-pos/src/login.html','desktop-pos/src/login.css',
  'desktop-pos/src/assets/go-erp.svg','desktop-pos/test/account-login-window-native.cjs','desktop-pos/test/run-electron-native.cjs',
  'desktop-pos/test/fixtures/connected-loopback-cert.pem','desktop-pos/test/fixtures/connected-loopback-key.pem'
]);
const TRUE_FLAGS=Object.freeze([
  'actualElectron','actualBrowserWindow','actualPreload','actualIpc','sandbox','trustedFile','singleOrdinaryForm',
  'passwordClearedBeforeAuthenticationSettles','passwordNeverPersisted','rendererReceivesOnlyBoundedEnvelope',
  'fullResultStaysInMain','formRetainedDisabledUntilHandoff','editingPreventsRememberedOverride','actualLoginFullscreenF12',
  'fullscreenKeepsRememberedCheck','actualNativeCloseCancels','quitCallbackRequested',
  'actualOriginalSessionAuthClient','actualSessionCookieJar','originalCsrfHeadersObserved','guardedRedirectCookiesPreserved',
  'actualClientRequestManualAdmission','authOnlyRedirectAdmission','foreignRedirectNotTransmitted','repeatedPostRedirectBlocked',
  'actualCookieHandoff','wrongPasswordRejected','actualRememberedSessionClient','rememberedRoleRedirectAccepted','rememberedWithoutPasswordPost',
  'nativeIdentityDocument','nativePasswordSigninSkipsHome','nativeGuestIdentityRejected','nativeMalformedIdentityRejected',
  'nativeIdentityCancellation','nativeRememberedIdentityRead',
  'preparedLocalKeepsMode','preparedLocalHasFreshServerSession','preparedSocialUsesSameActor','preparedOfflineHintSkipsServer',
  'preparedOptionalDeadlineDrained','preparedServerRefusalDrainsRuntime','preparedLoginHasNoLateCookieWrites','preparedServerProofReused',
  'syntheticHttpAuthServer','syntheticBroker','quitCallbackFixture','naturalQuit','completed'
]);
const FALSE_FLAGS=Object.freeze(['actualPhpAuthorization','actualProductionMain','productionProfile','hardware','forcedSuccessExit']);
const sha=raw=>crypto.createHash('sha256').update(raw).digest('hex');
const object=value=>value!==null&&typeof value==='object'&&!Array.isArray(value);

function bytes(file,maximum){
  const before=fs.lstatSync(file);
  assert.ok(before.isFile()&&!before.isSymbolicLink(),'Native proof inputs must be regular files: '+file);
  const fd=fs.openSync(file,fs.constants.O_RDONLY|(fs.constants.O_NOFOLLOW||0));
  try{
    const current=fs.fstatSync(fd);
    assert.ok(current.isFile()&&current.dev===before.dev&&current.ino===before.ino
      &&Number.isSafeInteger(current.size)&&current.size>0&&current.size<=maximum,'Native proof file is invalid: '+file);
    const raw=fs.readFileSync(fd);
    assert.equal(raw.length,current.size,'Native proof file changed while reading: '+file);
    return raw;
  }finally{fs.closeSync(fd);}
}

function validate(proof,{root=ROOT,revision=process.env.GITHUB_SHA,capture}={}){
  assert.ok(object(proof),'A native login receipt is required.');
  assert.equal(proof.format,1);assert.equal(proof.kind,'go-erp-login-window-native');
  assert.equal(typeof revision,'string');assert.match(revision,/^[a-f0-9]{40}$/);
  assert.equal(proof.sourceRevision,revision,'Native login receipt belongs to another revision.');
  assert.equal(proof.platform,'win32');
  const locked=JSON.parse(bytes(path.join(root,'desktop-pos/package-lock.json'),4*1024**2).toString('utf8'));
  const electron=locked.packages?.['node_modules/electron']?.version;
  assert.equal(typeof electron,'string');assert.ok(electron.length>0);
  assert.equal(proof.electronVersion,electron,'Native login Electron differs from the exact package lock.');
  assert.deepEqual(Object.keys(proof).sort(),[
    'format','kind','sourceRevision','sourceHashes','electronVersion','platform','capture',...TRUE_FLAGS,...FALSE_FLAGS
  ].sort(),'Native login receipt fields changed.');
  for(const name of TRUE_FLAGS)assert.equal(proof[name],true,'Missing actual native login acceptance: '+name);
  for(const name of FALSE_FLAGS)assert.equal(proof[name],false,'Native login fixture scope changed: '+name);
  assert.ok(object(proof.sourceHashes),'Native login source hashes are required.');
  assert.deepEqual(Object.keys(proof.sourceHashes).sort(),SOURCES.slice().sort(),'Native login source roster changed.');
  for(const name of SOURCES){
    assert.equal(typeof proof.sourceHashes[name],'string');assert.match(proof.sourceHashes[name],/^[a-f0-9]{64}$/);
    assert.equal(proof.sourceHashes[name],sha(bytes(path.join(root,name),4*1024**2)),'Native login source changed: '+name);
  }
  assert.ok(object(proof.capture),'Native login capture is required.');
  assert.deepEqual(Object.keys(proof.capture).sort(),['height','sha256','width']);
  for(const dimension of ['width','height'])assert.ok(Number.isSafeInteger(proof.capture[dimension])&&proof.capture[dimension]<=16384,'Native login capture dimensions are invalid.');
  assert.ok(proof.capture.width>=1000&&proof.capture.height>=650,'Native login capture is smaller than its required form.');
  assert.equal(typeof proof.capture.sha256,'string');assert.match(proof.capture.sha256,/^[a-f0-9]{64}$/);
  assert.ok(Buffer.isBuffer(capture)&&capture.length>=33&&capture.length<=64*1024**2,'Native login PNG capture bytes are required.');
  assert.equal(capture.subarray(0,8).toString('hex'),'89504e470d0a1a0a','Native login capture is not PNG.');
  assert.equal(capture.readUInt32BE(8),13);assert.equal(capture.subarray(12,16).toString('ascii'),'IHDR');
  assert.equal(capture.readUInt32BE(16),proof.capture.width,'Native login PNG width differs from its receipt.');
  assert.equal(capture.readUInt32BE(20),proof.capture.height,'Native login PNG height differs from its receipt.');
  assert.equal(sha(capture),proof.capture.sha256,'Native login capture differs from its receipt.');
  return proof;
}

function verify(file,options={}){
  assert.equal(typeof file,'string');assert.ok(file.endsWith('.json'),'Native login receipt path must end in .json.');
  const receipt=path.resolve(file),captureFile=receipt.slice(0,-5)+'-login.png';
  return validate(JSON.parse(bytes(receipt,1024**2).toString('utf8')),
    {...options,capture:bytes(captureFile,64*1024**2)});
}

if(require.main===module){
  assert.equal(process.argv.length,3,'Provide exactly one native login receipt path.');
  verify(process.argv[2]);
  console.log('PASS exact Windows native login, small identity, prepared HTTPS session and same-actor social proof');
}
module.exports={validate,verify,SOURCES,TRUE_FLAGS,FALSE_FLAGS};
