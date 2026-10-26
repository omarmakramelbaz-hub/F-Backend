'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),file=path.resolve(process.argv[2]||'');
const proof=JSON.parse(fs.readFileSync(file,'utf8'));
assert.equal(proof.format,1);assert.equal(proof.kind,'go-erp-native-account-profiles');
assert.match(process.env.GITHUB_SHA||'',/^[a-f0-9]{40}$/);assert.equal(proof.sourceRevision,process.env.GITHUB_SHA);
assert.equal(proof.platform,'win32');assert.equal(proof.processes,3);
const locked=JSON.parse(fs.readFileSync(path.join(root,'desktop-pos/package-lock.json'),'utf8'));
assert.equal(proof.electronVersion,locked.packages['node_modules/electron'].version);
assert.equal(proof.asarVersion,locked.packages['node_modules/@electron/asar'].version);
assert.match(proof.asarSha256,/^[a-f0-9]{64}$/);
for(const name of ['actualElectron','actualProductionMain','actualDpapi','actualAsarCode','actualNaturalRelaunch','actualNativeMenu',
  'actualNativePicker','pickerActionsVisible','capacity64PickerActionsVisible','labelsRenderedAsText','transientFixtureSessionEnded',
  'distinctDevicesAndSecrets','distinctPrivateAuthStorage','firstAuthenticationRequired','firstVisibleRealAccountPage','startupBridgeNeverShown','emptyNewCookiePartition',
  'persistentCookiePartitionsIsolated','ownerRememberedWithoutCredentialPost','ownerDurableBytesUnchanged','otherDurableBytesUnchanged',
  'selectedJournalsIsolated','originalJournalRestored','pendingJournalBlockedSwitch','actorBindingMismatchRejected',
  'corruptEncryptedRegistryRejected','naturalQuit','completed','syntheticRoleProjectionOnly','syntheticPrivateProofOnly',
  'syntheticAcknowledgementOnly','dialogsIntercepted'])assert.equal(proof[name],true,name);
for(const name of ['hardware','productionProfile','actualPhpAuthorization','actualInstalledApplication','forcedSuccessExit'])assert.equal(proof[name],false,name);
const sources=['desktop-pos/src/main.cjs','desktop-pos/src/shutdown.cjs','desktop-pos/src/account-signout.cjs','desktop-pos/src/startup.html','desktop-pos/src/account-switch.cjs',
  'desktop-pos/src/account-profiles.cjs','desktop-pos/src/account-picker.cjs','desktop-pos/src/account-picker-preload.cjs',
  'desktop-pos/src/account-picker-renderer.js','desktop-pos/src/account-picker.css','desktop-pos/src/account-picker.html',
  'desktop-pos/src/dashboard.cjs','desktop-pos/src/dashboard-preload.cjs','desktop-pos/src/preparation-clock.js','desktop-pos/src/dashboard-preparation.cjs',
  'desktop-pos/src/local-runtime.cjs','desktop-pos/src/dashboard-generation.cjs','desktop-pos/src/store.cjs','desktop-pos/src/preload.cjs',
  'public/dashboard/js/dashboard-spa.js','desktop-pos/test/account-profiles-native.cjs',
  'desktop-pos/test/account-profiles-native-runner.cjs','.github/workflows/desktop-account-profiles-native.yml'];
assert.deepEqual(Object.keys(proof.sourceHashes).sort(),sources.slice().sort());
for(const name of sources)assert.equal(proof.sourceHashes[name],crypto.createHash('sha256').update(fs.readFileSync(path.join(root,name))).digest('hex'),name);
assert.equal(proof.requests.ownerSignins,1);assert.equal(proof.requests.managerSignins,1);assert.equal(proof.requests.internalHeaders,0);
assert.ok(Number.isSafeInteger(proof.requests.ownerRecallerOnlyReads)&&proof.requests.ownerRecallerOnlyReads>0);
assert.equal(proof.phases.length,3);assert.equal(new Set(proof.phases.map(value=>value.pid)).size,3);
for(const [index,value]of proof.phases.entries()){
  assert.equal(value.phase,index);assert.ok(Number.isSafeInteger(value.pid)&&value.pid>0);assert.equal(value.naturalQuit,true);
  if(index<2)assert.equal(value.actualNaturalRelaunch,true);
}
console.log('PASS exact source native account profiles, natural relaunch and isolated persisted state');
