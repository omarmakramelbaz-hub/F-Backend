'use strict';
const fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const { constants } = require('node:fs');
const { canonical } = require('./workspace-protocol.cjs');
const source = require('./dashboard-source.cjs');
// Filled only by the release owner after the complete source/data/read/print,
// recovery and Windows acceptance. No renderer/env/cached boolean enables it.
const ACCEPTED_POLICY = "historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1";
const ACCEPTED_CONTRACT_SHA256 = "74a7106a21fd662f49548f883475c867fdff4e574a6b1e319ea719e8564c2ecb";
const CONTRACT_PATH = 'desktop/workspace-contract.json';
const PROGRAMS = Object.freeze(['desktop/workspace-import.php', 'desktop/workspace-verify.php', 'desktop/workspace-delta.php', 'desktop/workspace-control.php', 'desktop/workspace-archive.php']);
const invalid = () => Object.assign(Error('تعذر تأكيد عقد تشغيل مساحة الجهاز؛ البيانات الحالية محفوظة.'), { code: 'WORKSPACE_POLICY_INVALID' });
const need = value => { if (!value) throw invalid(); };
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const identity = value => [value.dev, value.ino, value.mode, value.nlink, value.size, value.mtimeNs, value.ctimeNs].join(':');
const nodeIdentity = value => [value.dev, value.ino, value.mode].join(':');
const normalized = value => process.platform === 'win32' ? path.normalize(value).toLowerCase() : path.normalize(value);
function programs(manifest) {
  if (!manifest?.sourceHashes || !PROGRAMS.every(name => hash(manifest.sourceHashes[name])))
    throw Object.assign(Error('نسخة التشغيل لا تحتوي بروتوكول تجهيز الجهاز المعتمد؛ البيانات محفوظة.'), { code: 'WORKSPACE_PROGRAMS_UNVERIFIED' });
  return true;
}
function available() { return typeof ACCEPTED_POLICY === 'string' && ACCEPTED_POLICY.length > 0 && hash(ACCEPTED_CONTRACT_SHA256); }
async function directories(target) {
  need(typeof target === 'string' && path.isAbsolute(target) && path.resolve(target) === target);
  let cursor = path.parse(target).root; const chain = [];
  for (const component of ['', ...target.slice(cursor.length).split(path.sep).filter(Boolean)]) {
    if (component) cursor = path.join(cursor, component);
    const info = await fs.lstat(cursor, { bigint: true }); need(info.isDirectory() && !info.isSymbolicLink()); chain.push({ path: cursor, identity: nodeIdentity(info) });
  }
  need(normalized(await fs.realpath(target)) === normalized(target)); return chain;
}
async function unchanged(chain) { for (const entry of chain) need(nodeIdentity(await fs.lstat(entry.path, { bigint: true })) === entry.identity); }
async function bounded(file, maximum) {
  const chain = await directories(path.dirname(file)), before = await fs.lstat(file, { bigint: true });
  need(before.isFile() && !before.isSymbolicLink() && before.nlink === 1n && before.size > 0n && before.size <= BigInt(maximum));
  const handle = await fs.open(file, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
  try {
    need(identity(await handle.stat({ bigint: true })) === identity(before)); await unchanged(chain);
    const bytes = await handle.readFile();
    need(bytes.length <= maximum && BigInt(bytes.length) === before.size && identity(await handle.stat({ bigint: true })) === identity(before)
      && identity(await fs.lstat(file, { bigint: true })) === identity(before)); await unchanged(chain);
    return { bytes, chain, file, identity: identity(before) };
  } finally { await handle.close(); }
}
function object(value) { return value && typeof value === 'object' && !Array.isArray(value); }
function freeze(value) { if (value && typeof value === 'object' && !Object.isFrozen(value)) { for (const child of Object.values(value)) freeze(child); Object.freeze(value); } return value; }
function exact(value, keys) { return object(value) && Object.keys(value).sort().join(',') === [...keys].sort().join(','); }
/**
 * Bounded installed metadata proof only: no archive inventory, source copy,
 * roster, database or network work. The original physical.start still verifies
 * every retained byte/source BEFORE any PHP program may execute.
 */
async function verifyInstalled(bundle) {
  if (!available()) return null;
  try {
    need(typeof bundle === 'string' && path.isAbsolute(bundle) && path.resolve(bundle) === bundle);
    await directories(bundle);
    const manifestRead = await bounded(path.join(bundle, 'manifest.json'), 16 * 1024 ** 2);
    const decode = bytes => new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    const manifest = JSON.parse(decode(manifestRead.bytes));
    need(manifest?.format === 1 && manifest.platform === 'win32-x64' && /^[a-f0-9]{40}$/.test(manifest.sourceRevision || '')
      && source.valid(manifest.sourceFingerprint) && object(manifest.sourceHashes)); programs(manifest);
    const read = await bounded(path.join(bundle, 'application', 'desktop', 'workspace-contract.json'), 16384), raw = decode(read.bytes);
    const sha256 = crypto.createHash('sha256').update(read.bytes).digest('hex');
    need(sha256 === ACCEPTED_CONTRACT_SHA256 && sha256 === manifest.sourceHashes[CONTRACT_PATH]);
    const contract = JSON.parse(raw);
    need(exact(contract, ['format', 'kind', 'protocol', 'policy', 'programs']) && contract.format === 2 && contract.protocol === 2
      && contract.kind === 'desktop-workspace-runtime-contract' && contract.policy === ACCEPTED_POLICY
      && exact(contract.programs, PROGRAMS) && PROGRAMS.every(name => hash(contract.programs[name]) && contract.programs[name] === manifest.sourceHashes[name])
      && raw === canonical(contract) + '\n');
    await unchanged(manifestRead.chain); await unchanged(read.chain);
    need(identity(await fs.lstat(manifestRead.file, { bigint: true })) === manifestRead.identity
      && identity(await fs.lstat(read.file, { bigint: true })) === read.identity);
    return Object.freeze({ protocol: 2, policy: ACCEPTED_POLICY, contractSha256: sha256, manifest: freeze(manifest) });
  } catch { throw invalid(); }
}
async function accepted(bundle) { return Boolean(await verifyInstalled(bundle)); }
module.exports = { available, accepted, verifyInstalled, programs, PROGRAMS };
