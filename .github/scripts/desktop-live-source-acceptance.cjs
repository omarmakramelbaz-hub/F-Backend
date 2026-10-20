'use strict';
// This is an additional input to the live-source publication worker. The worker
// still independently checks the successful exact CI run and its current attempt.
const fs = require('node:fs/promises');
const { constants } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');
const packaging = require('../../desktop-pos/installer-runtime.cjs');
const receipts = require('./desktop-update-receipt.cjs');
const policy = require('../../desktop-pos/runtime-source-policy.cjs');
const LIVE_BRANCH = 'codex/go-erp-live-source-20261021';
const REPOSITORY = 'omarmakramelbaz-hub/F-Backend';
const CANDIDATE = 'deployment/go_erp_live_source_candidate.json';
const HASH = /^[a-f0-9]{64}$/, REV = /^[a-f0-9]{40}$/;
const ROOTS = new Set(['app','bootstrap','config','database','desktop','public','resources','routes']);
const MAX_FILES = 20000, MAX_JSON = 16 * 1024 ** 2;
const need = (value, message) => { if (!value) throw Error(message); };
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const exact = (value, keys) => record(value) && isDeepStrictEqual(Object.keys(value).sort(), keys.slice().sort());
const compare = (a,b) => Buffer.compare(Buffer.from(a),Buffer.from(b));
function canonical(value) {
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  if (record(value)) return '{' + Object.keys(value).sort(compare).map(key => JSON.stringify(key)+':'+canonical(value[key])).join(',') + '}';
  return JSON.stringify(value);
}
const mapHash = value => sha256(Buffer.from(canonical(value),'utf8'));
function sourcePath(name) {
  return typeof name === 'string' && name.length > 0 && name.length <= 512
    && !/[\u0000-\u001f\u007f-\u009f\ud800-\udfff\\:<>"|?*]/.test(name)
    && name.split('/').every(part => part && part !== '.' && part !== '..' && part.toLowerCase() !== '.git'
      && !/[. ]$/.test(part) && !/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(part))
    && (name === 'artisan' || ROOTS.has(name.split('/')[0])) && !policy.excludedPath(name);
}
function hashMap(value) {
  need(record(value) && Object.keys(value).length <= MAX_FILES, 'The declared source map is invalid.');
  const seen = new Set();
  for (const [name,hash] of Object.entries(value)) {
    need(sourcePath(name) && !seen.has(name.toLowerCase()) && typeof hash === 'string' && HASH.test(hash), 'The declared source map contains an unsafe file.');
    seen.add(name.toLowerCase());
  }
  return value;
}
function validateCandidate(candidate, {repository,branch,version}) {
  need(exact(candidate,['format','kind','repository','branch','baseRevision','version','versionSequence','inventorySha256','inventory',
    'authoritativeLiveFiles','packagedFiles','retainedTrustedFiles','removedFiles'])
    && candidate.format === 1 && candidate.kind === 'go-erp-live-source-candidate'
    && repository === REPOSITORY && candidate.repository === repository && branch === LIVE_BRANCH && candidate.branch === branch
    && REV.test(candidate.baseRevision || '') && candidate.version === version
    && /^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)-preview\.[1-9][0-9]*$/.test(version || '')
    && Number.isSafeInteger(candidate.versionSequence) && candidate.versionSequence > 0
    && Number(version.split('-preview.')[1]) === candidate.versionSequence
    && record(candidate.inventory) && Object.keys(candidate.inventory).length > 0 && Object.keys(candidate.inventory).length <= MAX_FILES,
  'The live-source candidate identity is invalid.');
  need(HASH.test(candidate.inventorySha256 || '') && mapHash(candidate.inventory) === candidate.inventorySha256, 'The candidate inventory binding differs.');
  const live = {}, trusted = {}, packaged = {}, removed = [], cases = new Set();
  for (const [name,item] of Object.entries(candidate.inventory)) {
    need(sourcePath(name) && !cases.has(name.toLowerCase()) && exact(item,['authority','present','sha256','bytes','mode'])
      && ['100644','100755'].includes(item.mode), 'The candidate inventory contains an unsafe file.');
    cases.add(name.toLowerCase());
    if (item.authority === 'removed') {
      need(item.present === false && item.sha256 === null && item.bytes === 0, 'The removed source inventory is invalid.');
      removed.push(name); continue;
    }
    need(['live','trusted-base'].includes(item.authority) && item.present === true && HASH.test(item.sha256 || '')
      && Number.isSafeInteger(item.bytes) && item.bytes >= 0 && item.bytes <= 64 * 1024 ** 2, 'The selected source inventory is invalid.');
    packaged[name] = item.sha256;
    (item.authority === 'live' ? live : trusted)[name] = item.sha256;
  }
  need(Object.keys(live).length > 0 && Object.keys(packaged).length > 0, 'The live-source candidate contains no captured source.');
  need(isDeepStrictEqual(hashMap(candidate.authoritativeLiveFiles),live)
    && isDeepStrictEqual(hashMap(candidate.retainedTrustedFiles),trusted)
    && isDeepStrictEqual(hashMap(candidate.packagedFiles),packaged)
    && Array.isArray(candidate.removedFiles) && isDeepStrictEqual(candidate.removedFiles.slice().sort(compare),removed.sort(compare))
    && new Set(candidate.removedFiles).size === candidate.removedFiles.length, 'The candidate selected source maps differ from its full inventory.');
  return packaged;
}
const identity = stat => [stat.dev,stat.ino,stat.size,stat.mtimeMs,stat.ctimeMs].join(':');
async function safePath(file, directory = false) {
  const absolute = path.resolve(file); let cursor = absolute;
  for (;;) {
    const stat = await fs.lstat(cursor);
    need(!stat.isSymbolicLink() && (cursor === absolute && !directory ? stat.isFile() && stat.nlink === 1 : stat.isDirectory()), 'A live-source acceptance path is unsafe.');
    const parent = path.dirname(cursor); if (parent === cursor) return fs.lstat(absolute); cursor = parent;
  }
}
async function read(file, limit = MAX_JSON) {
  const named = await safePath(file); need(named.size > 0 && named.size <= limit, 'A live-source acceptance input exceeds its bound.');
  const handle = await fs.open(file,constants.O_RDONLY | (constants.O_NOFOLLOW || 0) | (constants.O_NONBLOCK || 0));
  try {
    const before = await handle.stat(); need(identity(named) === identity(before), 'An acceptance input changed before verification.');
    const chunks = []; let length = 0;
    while (length <= limit) {
      const block = Buffer.alloc(Math.min(65536,limit + 1 - length));
      const {bytesRead} = await handle.read(block,0,block.length,null);
      if (!bytesRead) break;
      length += bytesRead;chunks.push(block.subarray(0,bytesRead));
    }
    need(length <= limit,'A live-source acceptance input exceeds its bound.');
    const bytes = Buffer.concat(chunks);
    need(bytes.length === before.size && identity(before) === identity(await handle.stat()) && identity(before) === identity(await fs.lstat(file)), 'An acceptance input changed during verification.');
    need(bytes.toString('utf8') === new TextDecoder('utf-8',{fatal:true}).decode(bytes), 'An acceptance input is not UTF-8.');
    return {bytes,value:JSON.parse(bytes.toString('utf8'))};
  } finally { await handle.close(); }
}
async function verifySource(bundle, verified, candidate, context) {
  const expected = validateCandidate(candidate,context);
  need(isDeepStrictEqual(verified.manifest.sourceHashes,expected), 'The built original source map differs from the complete live candidate.');
  const selected = {};
  for (const [name,hash] of Object.entries(verified.files)) {
    if (!name.startsWith('application/')) continue;
    const relative = name.slice(12);
    if ((relative === 'artisan' || ROOTS.has(relative.split('/')[0])) && !relative.startsWith('bootstrap/cache/'))
      need(sourcePath(relative),'The runtime contains an excluded or unsafe source file.');
    if (sourcePath(relative)) selected[relative] = hash;
  }
  need(isDeepStrictEqual(selected,expected), 'The runtime contains missing or additional selected application source.');
  for (const [name,hash] of Object.entries(expected)) {
    const stat = await safePath(path.join(bundle,'application',name));
    need(stat.size === candidate.inventory[name].bytes && selected[name] === hash, 'The built application bytes differ from the captured inventory.');
  }
  return mapHash(verified.files);
}
function positive(value, label) {
  need(typeof value === 'number' || typeof value === 'string' && /^[1-9][0-9]*$/.test(value), label);
  const result = Number(value); need(Number.isSafeInteger(result) && result > 0,label); return result;
}
async function writeExclusive(file, value) {
  const temporary = file+'.'+crypto.randomBytes(12).toString('hex')+'.tmp';
  const handle = await fs.open(temporary,'wx',0o600);
  try { await handle.writeFile(JSON.stringify(value,null,2)+'\n'); await handle.sync(); }
  finally { await handle.close(); }
  try { await fs.link(temporary,file); }
  finally { await fs.unlink(temporary); }
}
async function generate(project, options = {}) {
  const repository = options.repository ?? process.env.GITHUB_REPOSITORY;
  const branch = options.branch ?? process.env.GITHUB_REF_NAME;
  if (branch !== LIVE_BRANCH) return null;
  const revision = options.revision ?? process.env.GITHUB_SHA;
  const eventName = options.eventName ?? process.env.GITHUB_EVENT_NAME;
  need(repository === REPOSITORY && eventName === 'push' && REV.test(revision || ''), 'The exact live-source push identity is required.');
  const runId = positive(options.runId ?? process.env.GITHUB_RUN_ID,'The live-source CI run is invalid.');
  const runAttempt = positive(options.runAttempt ?? process.env.GITHUB_RUN_ATTEMPT,'The live-source CI attempt is invalid.');
  project = path.resolve(project);
  const bundle = path.join(project,'runtime-bundle'), dist = path.join(project,'dist');
  await safePath(project,true); await safePath(bundle,true); await safePath(dist,true);
  const candidate = await read(path.join(path.dirname(project),CANDIDATE));
  const info = await read(path.join(project,'package.json'),65536);
  const runtime = await read(path.join(dist,'runtime-package.json'));
  const native = await read(path.join(dist,'installer-checks.json'),65536);
  const connected = await read(path.join(dist,'connected-request-native.json'),65536);
  const release = await read(path.join(dist,'go-erp-update-release.json'),512 * 1024);
  const verified = await packaging.verifyBundle(bundle);
  const accepted = receipts.validateParts({revision,sequence:runId,info:info.value,runtimePackage:runtime.value,
    installerChecks:native.value,connected:connected.value,verified});
  const fullPackage = await verifySource(bundle,verified,candidate.value,{repository,branch,version:info.value.version});
  const gui = await receipts.guiSnapshot(path.join(bundle,'application'),verified);
  const installer = await receipts.installer(path.join(dist,'GO-ERP-Preview-Setup.exe'));
  need(isDeepStrictEqual(installer,native.value.installer)
    && isDeepStrictEqual(release.value,{...accepted,installer,gui}), 'The existing accepted update receipt differs from this runtime or installer.');
  // Bind the exact candidate JSON that was reviewed; a late rewrite cannot inherit
  // the earlier package comparison even if it reuses a declared inventory hash.
  need((await read(path.join(path.dirname(project),CANDIDATE))).bytes.equals(candidate.bytes), 'The candidate changed during acceptance.');
  const proof = {format:1,kind:'go-erp-live-source-acceptance',repository,branch,revision,run_id:runId,run_attempt:runAttempt,
    version:info.value.version,candidate_inventory_sha256:candidate.value.inventorySha256,full_package_sha256:fullPackage,
    update_receipt_sha256:sha256(release.bytes),installer};
  await writeExclusive(path.join(dist,'go-erp-live-source-acceptance.json'),proof);
  return proof;
}
module.exports = {generate,validateCandidate,verifySource,canonical,mapHash,sourcePath,LIVE_BRANCH,REPOSITORY,CANDIDATE};
if (require.main === module) {
  if (process.argv.length !== 3) { process.stderr.write('Usage: node desktop-live-source-acceptance.cjs /path/to/desktop-pos\n'); process.exitCode = 1; }
  else generate(process.argv[2]).then(value => process.stdout.write(value ? 'GO_ERP_LIVE_SOURCE_ACCEPTED '+JSON.stringify(value)+'\n' : 'GO_ERP_LIVE_SOURCE_ACCEPTANCE_NOT_REQUIRED\n'))
    .catch(error => { process.stderr.write(error.message+'\n'); process.exitCode = 1; });
}
