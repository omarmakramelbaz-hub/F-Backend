'use strict';
// Accept only the source-bound disposable Windows/PHP/MariaDB fixture. Its
// publication authority is synthetic; it never claims production HTTPS proof.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const ROOT=path.resolve(__dirname,'../..'),MARKER='GO_ERP_STARTUP_SOURCE_NATIVE ';
const SOURCES=Object.freeze({helper:'desktop-pos/src/startup-source-upgrade.cjs',fixture:'desktop-pos/test/generation-native.cjs',
  nativeModule:'desktop-pos/test/startup-source-upgrade-native.cjs'});
const TRUE_FLAGS=Object.freeze(['actualWindows','actualPhp','actualMariaDB','actualDeltaCapture','actualDeltaStage','actualDeltaFinalize',
  'actualDeltaActivate','sourceFingerprintChanged','preparedDataPreserved','originalUsersPreserved','originalSessionBytesPreserved',
  'historicalReplayPreserved','originalPreviousDatabaseRetained','originalCoveragePreserved','offlineZeroHttp','sameSourceZeroHttp',
  'unapprovedPreviewDenied','authorityFixture','previewOnly','completed','restartRetainsActivatedSource']);
const FALSE_FLAGS=Object.freeze(['fullSnapshotFallback','actualHttpsPublication','actualFullDashboard']);
const hash=raw=>crypto.createHash('sha256').update(raw).digest('hex');
const object=value=>value!==null&&typeof value==='object'&&!Array.isArray(value);
function validate(proof,{root=ROOT,revision=process.env.GITHUB_SHA,installedAsarCode=false}={}){
  assert.equal(typeof installedAsarCode,'boolean');assert.ok(object(proof),'A native startup source receipt is required.');
  assert.equal(proof.format,1);assert.equal(proof.kind,'go-erp-startup-source-native');
  assert.equal(typeof revision,'string');assert.match(revision,/^[a-f0-9]{40}$/);
  assert.equal(proof.sourceRevision,revision,'Startup source proof belongs to another captured revision.');
  assert.equal(proof.installedAsarCode,installedAsarCode,'Startup source ASAR execution scope differs.');
  assert.deepEqual(Object.keys(proof).sort(),['format','kind','sourceRevision','sourceHashes','installedAsarCode',...TRUE_FLAGS,...FALSE_FLAGS].sort(),
    'Startup source proof fields changed.');
  for(const name of TRUE_FLAGS)assert.equal(proof[name],true,'Missing actual startup source acceptance: '+name);
  for(const name of FALSE_FLAGS)assert.equal(proof[name],false,'Startup source fixture scope changed: '+name);
  assert.ok(object(proof.sourceHashes));assert.deepEqual(Object.keys(proof.sourceHashes).sort(),Object.keys(SOURCES).sort());
  for(const [name,file]of Object.entries(SOURCES)){
    assert.equal(typeof proof.sourceHashes[name],'string');assert.match(proof.sourceHashes[name],/^[a-f0-9]{64}$/);
    assert.equal(proof.sourceHashes[name],hash(fs.readFileSync(path.join(root,file))),'Startup source proof code changed: '+file);
  }
  return proof;
}
function parse(output,options={}){
  assert.equal(typeof output,'string');assert.ok(Buffer.byteLength(output)<=16*1024**2,'Startup source log exceeds its bounded size.');
  const matches=[...output.matchAll(/^GO_ERP_STARTUP_SOURCE_NATIVE ([^\r\n]+)\r?$/gm)];
  assert.equal(matches.length,1,'The actual native startup fixture must complete exactly once.');
  assert.equal(output.split(MARKER).length-1,1,'The actual native startup marker is duplicated or unbound.');
  return validate(JSON.parse(matches[0][1]),options);
}
function verify(logFile,receiptFile,options={}){
  assert.equal(typeof logFile,'string');assert.equal(typeof receiptFile,'string');
  assert.notEqual(path.resolve(logFile),path.resolve(receiptFile));
  const info=fs.lstatSync(logFile);assert.ok(info.isFile()&&!info.isSymbolicLink()&&info.size<=16*1024**2);
  const proof=parse(fs.readFileSync(logFile,'utf8'),options);
  fs.writeFileSync(receiptFile,JSON.stringify(proof,null,2)+'\n',{flag:'wx',mode:0o600});
  return proof;
}
if(require.main===module){
  assert.equal(process.argv.length,4,'Provide the unchanged native log and a new proof JSON path.');
  verify(process.argv[2],process.argv[3]);
  console.log('PASS exact Windows PHP/MariaDB startup delta activation, preserved data, restart and synthetic authority scope');
}
module.exports={validate,parse,verify,SOURCES,TRUE_FLAGS,FALSE_FLAGS};
