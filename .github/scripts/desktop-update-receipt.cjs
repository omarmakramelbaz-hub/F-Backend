'use strict';
// Run only after this checkout's original-runtime, connected-native and actual
// installed-Windows acceptance jobs. This receipt is a CI publication input,
// not a replacement for independently checking that workflow's success.
const fs = require('node:fs/promises');
const { constants } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');
const source = require('../../desktop-pos/src/dashboard-source.cjs');
const packaging = require('../../desktop-pos/installer-runtime.cjs');
const REV = /^[a-f0-9]{40}$/, HASH = /^[a-f0-9]{64}$/;
const MAX_INSTALLER = 1024 ** 3;
const CONNECTED = ['actualElectronSessionFetch','actualWebRequest','actualDpapi','pinnedLoopbackHttps',
  'pairedProofOnWire','internalMarkerStripped','foreignProofStripped','nativeReplayBlocked','fragmentNormalized',
  'oldProtocolBlocked','lostReplyRetained','unboundedOriginalWrite','nativeReauthentication','expiredCookieBlocked',
  'reauthSameActorProtocol','reauthWrongActorBlocked','reauthOldProtocolBlocked','reauthMutationBlocked',
  'reauthCancelled','reauthPreservedMode','reauthNoPreload','reauthRedirectBlocked','nativeLocalReauthentication'];
const AUTOMATIC_UPGRADE = ['waitedForExit','preservedJournal','preservedUnrelatedFiles','registrationRetained','restartedInstalledExecutable'];
const need = (value, message) => { if (!value) throw Error(message); };
const version = value => typeof value === 'string' && /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-preview\.[1-9][0-9]*)?$/.test(value);
function validateParts({revision,sequence,info,installerChecks,runtimePackage,connected,verified}) {
  need(typeof revision === 'string' && REV.test(revision), 'The exact CI source revision is required.');
  need(Number.isSafeInteger(sequence) && sequence > 0, 'A positive safe CI release sequence is required.');
  need(version(info?.version) && info?.build?.productName === 'GO ERP', 'The GO ERP package version is invalid.');
  need(verified?.manifest?.sourceRevision === revision && source.valid(verified.manifest.sourceFingerprint), 'The verified runtime belongs to another source.');
  const expected = verified.manifest.sourceFingerprint;
  need(runtimePackage?.format === 1 && runtimePackage.sourceRevision === revision && runtimePackage.version === info.version
    && source.same(runtimePackage.sourceFingerprint, expected) && isDeepStrictEqual(runtimePackage.files, verified.files)
    && runtimePackage.lockSha256 === verified.lockSha256, 'The runtime package receipt differs from the current verified runtime.');
  need(installerChecks?.format === 1 && installerChecks.sourceRevision === revision && installerChecks.version === info.version
    && ['installation','installedNativeExecutables','installedDashboardRecovery','packagedLedger','uninstallRetention'].every(key => installerChecks[key] === true)
    && installerChecks.prerequisite?.signatureValid === true && installerChecks.prerequisite?.registered === true
    && installerChecks.prerequisite?.failureBeforeExtraction === true
    && HASH.test(installerChecks.installer?.sha256 || '') && Number.isSafeInteger(installerChecks.installer?.bytes)
    && installerChecks.installer.bytes >= 128 && installerChecks.installer.bytes <= MAX_INSTALLER,
    'Current actual Windows installer acceptance is incomplete.');
  const automatic = installerChecks.automaticUpgrade;
  need(automatic && typeof automatic === 'object' && !Array.isArray(automatic)
    && isDeepStrictEqual(Object.keys(automatic).sort(), AUTOMATIC_UPGRADE.slice().sort())
    && AUTOMATIC_UPGRADE.every(key => automatic[key] === true), 'Current actual automatic Windows upgrade acceptance is incomplete.');
  need(connected?.sourceRevision === revision && connected.platform === 'win32' && connected.hardware === false && connected.completed === true
    && CONNECTED.every(key => connected[key] === true) && connected.printedSources === 4 && connected.originalWrites === 1
    && connected.invocations === 4 && connected.lostPrintRequests === 1 && connected.reauthentications === 6
    && connected.reauthOriginalSignins === 5 && connected.reauthSuccesses === 3 && connected.localReauthentications === 1,
    'Current actual connected Electron acceptance is incomplete.');
  return {format:1,kind:'go-erp-desktop-release',version:info.version,sequence,source_revision:revision,
    source:expected,acceptance:{windows_installer:true,runtime:true,connected:true,source_revision:revision}};
}
async function realPath(file, directory = false) {
  const absolute = path.resolve(file); let cursor = absolute;
  for (;;) {
    const item = await fs.lstat(cursor);
    need(!item.isSymbolicLink() && (cursor === absolute && !directory ? item.isFile() && item.nlink === 1 : item.isDirectory()), 'A release input path is unsafe.');
    const parent = path.dirname(cursor); if (parent === cursor) break; cursor = parent;
  }
  return fs.lstat(absolute);
}
const identity = value => [value.dev,value.ino,value.size,value.mtimeMs,value.ctimeMs].join(':');
async function json(file, maximum = 16 * 1024 ** 2) {
  const before = await realPath(file);
  need(before.size > 0 && before.size <= maximum, 'A release receipt exceeds its bound.');
  const bytes = await fs.readFile(file), after = await fs.lstat(file);
  need(identity(before) === identity(after) && bytes.length === before.size, 'A release receipt changed during verification.');
  return JSON.parse(bytes.toString('utf8'));
}
const SECRET = /(?:^|\/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$/i;
const guiName = name => !SECRET.test(name) && (/^public\/js\/dashboard-[^/]*\.js$/.test(name) || /^public\/css\/dashboard-[^/]*\.css$/.test(name));
async function guiSnapshot(application, verified) {
  need(verified?.files && verified?.manifest?.sourceHashes, 'The verified GUI inventory is missing.');
  const indexed = Object.keys(verified.files).filter(name => name.startsWith('application/') && guiName(name.slice(12))).map(name => name.slice(12)).sort();
  const original = Object.keys(verified.manifest.sourceHashes).filter(guiName).sort();
  need(isDeepStrictEqual(indexed, original), 'The packaged dashboard GUI roster differs from the verified original source.');
  const values = {}, cases = new Set(); let total = 0;
  for (const [directory,suffix] of [['public/js','.js'],['public/css','.css']]) {
    const folder = path.join(application, directory); let before;
    try { before = await realPath(folder, true); }
    catch (error) { if (error.code === 'ENOENT') continue; throw error; }
    for (const name of (await fs.readdir(folder)).sort()) {
      const relative = directory + '/' + name;
      // Private and unrelated names are excluded before opening or inspecting
      // their contents. Only immediate original dashboard assets are included.
      if (SECRET.test(relative) || !name.startsWith('dashboard-') || !name.endsWith(suffix)) continue;
      need(/^[A-Za-z0-9_.@-]+$/.test(name) && !cases.has(relative.toLowerCase()) && cases.size < 20000,
        'The packaged dashboard GUI name is unsafe.');
      cases.add(relative.toLowerCase());
      const file = path.join(folder, name), named = await realPath(file);
      need(named.size <= 8 * 1024 ** 2 && total + named.size <= 64 * 1024 ** 2, 'The packaged dashboard GUI exceeds its bound.');
      const handle = await fs.open(file, constants.O_RDONLY | (constants.O_NOFOLLOW || 0) | (constants.O_NONBLOCK || 0));
      try {
        const start = await handle.stat();
        need(start.isFile() && start.nlink === 1 && identity(start) === identity(named), 'A packaged dashboard GUI input changed.');
        const chunks = []; let length = 0;
        while (length <= 8 * 1024 ** 2) {
          const block = Buffer.alloc(Math.min(65536, 8 * 1024 ** 2 + 1 - length));
          const {bytesRead} = await handle.read(block, 0, block.length, null);
          if (!bytesRead) break;
          length += bytesRead; chunks.push(block.subarray(0, bytesRead));
        }
        need(length <= 8 * 1024 ** 2 && total + length <= 64 * 1024 ** 2, 'The packaged dashboard GUI exceeds its bound.');
        const bytes = Buffer.concat(chunks), after = await handle.stat(), current = await fs.lstat(file);
        need(bytes.length === start.size && identity(start) === identity(after) && identity(start) === identity(current)
          && current.isFile() && !current.isSymbolicLink() && current.nlink === 1, 'A packaged dashboard GUI input changed.');
        total += bytes.length;
        const raw = crypto.createHash('sha256').update(bytes).digest('hex');
        need(raw === verified.files['application/' + relative] && raw === verified.manifest.sourceHashes[relative],
          'The packaged dashboard GUI bytes differ from the verified build.');
        values[relative] = crypto.createHash('sha256').update(Buffer.from(bytes.toString('latin1').replace(/\r\n/g, '\n'), 'latin1')).digest('hex');
      } finally { await handle.close(); }
    }
    const after = await fs.lstat(folder);
    need(identity(before) === identity(after) && after.isDirectory() && !after.isSymbolicLink(), 'The packaged dashboard GUI directory changed.');
  }
  const names = Object.keys(values).sort();
  need(isDeepStrictEqual(names, original), 'The packaged dashboard GUI roster is incomplete or changed.');
  return Object.fromEntries(names.map(name => [name, values[name]]));
}
async function installer(file) {
  const named = await realPath(file);
  need(named.size >= 128 && named.size <= MAX_INSTALLER, 'The installer exceeds its allowed size.');
  const handle = await fs.open(file, 'r');
  try {
    const before = await handle.stat();
    need(identity(before) === identity(named), 'The installer changed before verification.');
    const dos = Buffer.alloc(64); need((await handle.read(dos, 0, 64, 0)).bytesRead === 64 && dos.toString('ascii', 0, 2) === 'MZ', 'The installer is not a Windows executable.');
    const offset = dos.readUInt32LE(60), pe = Buffer.alloc(24);
    need(offset >= 64 && offset + 24 <= before.size && (await handle.read(pe, 0, 24, offset)).bytesRead === 24
      && pe.toString('ascii', 0, 4) === 'PE\0\0', 'The installer PE header is invalid.');
    const machine = pe.readUInt16LE(4), sections = pe.readUInt16LE(6), optionalBytes = pe.readUInt16LE(20);
    need([0x14c,0x8664].includes(machine) && sections > 0 && sections <= 96 && (pe.readUInt16LE(22) & 2)
      && optionalBytes >= (machine === 0x14c ? 96 : 112) && offset + 24 + optionalBytes + sections * 40 <= before.size,
      'The installer executable structure is invalid.');
    const magic = Buffer.alloc(2); await handle.read(magic, 0, 2, offset + 24);
    need(magic.readUInt16LE(0) === (machine === 0x14c ? 0x10b : 0x20b), 'The installer architecture header is invalid.');
    const digest = crypto.createHash('sha256'); let bytes = 0;
    for await (const block of handle.createReadStream({start:0,autoClose:false})) { bytes += block.length; need(bytes <= MAX_INSTALLER, 'The installer grew during verification.'); digest.update(block); }
    need(bytes === before.size && identity(before) === identity(await handle.stat()) && identity(before) === identity(await fs.lstat(file)), 'The installer changed during verification.');
    return {sha256:digest.digest('hex'),bytes};
  } finally { await handle.close(); }
}
async function writeExclusive(file, value) {
  const bytes = Buffer.from(JSON.stringify(value, null, 2) + '\n');
  const temporary = file + '.' + crypto.randomBytes(12).toString('hex') + '.tmp';
  const handle = await fs.open(temporary, 'wx', 0o600);
  try { await handle.writeFile(bytes); await handle.sync(); } finally { await handle.close(); }
  try { await fs.link(temporary, file); }
  finally { await fs.unlink(temporary); }
}
async function generate(project, {revision = process.env.GITHUB_SHA, sequence = process.env.GITHUB_RUN_ID} = {}) {
  need(typeof sequence === 'number' || typeof sequence === 'string' && /^[1-9][0-9]*$/.test(sequence), 'The CI release sequence is invalid.');
  project = path.resolve(project);
  await realPath(project, true); await realPath(path.join(project, 'dist'), true);
  const verified = await packaging.verifyBundle(path.join(project, 'runtime-bundle'));
  const info = await json(path.join(project, 'package.json'), 65536);
  const runtimePackage = await json(path.join(project, 'dist/runtime-package.json'));
  const installerChecks = await json(path.join(project, 'dist/installer-checks.json'), 65536);
  const connected = await json(path.join(project, 'dist/connected-request-native.json'), 65536);
  const accepted = validateParts({revision,sequence:Number(sequence),info,runtimePackage,installerChecks,connected,verified});
  const gui = await guiSnapshot(path.join(project, 'runtime-bundle/application'), verified);
  const payload = await installer(path.join(project, 'dist/GO-ERP-Preview-Setup.exe'));
  need(HASH.test(payload.sha256) && isDeepStrictEqual(payload, installerChecks.installer), 'The final installer differs from the executable accepted on Windows.');
  const receipt = {...accepted,installer:payload,gui};
  const destination = path.join(project, 'dist/go-erp-update-release.json');
  await writeExclusive(destination, receipt);
  return receipt;
}
module.exports = {generate,validateParts,installer,guiSnapshot,CONNECTED,AUTOMATIC_UPGRADE};
if (require.main === module) {
  if (process.argv.length !== 3) { process.stderr.write('Usage: node desktop-update-receipt.cjs /path/to/desktop-pos\n'); process.exitCode = 1; }
  else generate(process.argv[2]).then(value => process.stdout.write('GO_ERP_UPDATE_RELEASE_READY '+JSON.stringify(value)+'\n')).catch(error => { process.stderr.write(error.message+'\n'); process.exitCode = 1; });
}
