'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const policy = require('../src/workspace-policy.cjs');

test('the workspace installer gate cannot be enabled by renderer/env/cached booleans and does no optional startup verification', async () => {
  let invoked = false;
  const disabled = compiled(null);
  assert.equal(disabled.available(), false);
  assert.equal(await disabled.accepted(async () => { invoked = true; return { protocol: 2, enabled: true, verified: true }; }), false);
  assert.equal(invoked, false);
});

test('all five fixed group entrypoints must have verified manifest hashes before native staging can invoke any of them', () => {
  const manifest = { sourceHashes: Object.fromEntries(policy.PROGRAMS.map(name => [name, 'a'.repeat(64)])) };
  assert.equal(policy.programs(manifest), true);
  for (const name of policy.PROGRAMS) { const missing = structuredClone(manifest); delete missing.sourceHashes[name];
    assert.throws(() => policy.programs(missing), { code: 'WORKSPACE_PROGRAMS_UNVERIFIED' }); }
  assert.equal(policy.PROGRAMS.includes('desktop/import.php'), false, 'The separate v1 exact script inventory is not broadened.');
});

test('the actual main-process workspace factory is a side-effect-free null while the immutable acceptance policy is absent', async () => {
  let called = false;
  const file=path.join(__dirname,'../src/workspace-native.cjs'),module={exports:{}},localRequire=createRequire(file);
  vm.runInNewContext(syncFs.readFileSync(file,'utf8'),{module,require:name=>name==='./workspace-policy.cjs'?compiled(null):localRequire(name),process,Buffer,AbortController,AbortSignal});
  const result = await module.exports.createWorkspaceNative({
    verifyRuntimePolicy: async () => { called = true; throw Error('Optional source verification must not hold the normal login.'); },
    ownRuntime() { throw Error('No children may be owned while disabled.'); }
  });
  assert.equal(result, null); assert.equal(called, false);
});

const fs = require('node:fs/promises'), syncFs = require('node:fs'), os = require('node:os'), path = require('node:path'), vm = require('node:vm'), crypto = require('node:crypto');
const { createRequire } = require('node:module');
const { canonical } = require('../src/workspace-profile.cjs');
const policyId = 'historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1';
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
function compiled(contractSha) {
  const file = path.join(__dirname, '../src/workspace-policy.cjs'), module = { exports: {} }, localRequire = createRequire(file);
  const code = syncFs.readFileSync(file, 'utf8').replace(/^const ACCEPTED_POLICY = .*;$/m, 'const ACCEPTED_POLICY = ' + JSON.stringify(contractSha === null ? null : policyId) + ';')
    .replace(/^const ACCEPTED_CONTRACT_SHA256 = .*;$/m, 'const ACCEPTED_CONTRACT_SHA256 = ' + JSON.stringify(contractSha) + ';');
  vm.runInNewContext(code, { module, require: localRequire, process, Buffer, TextDecoder, Object, JSON, Error, BigInt }); return module.exports;
}
async function bundleFixture(t, rawTransform = value => canonical(value) + '\n') {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'workspace-policy-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const contractFile = path.join(root, 'application', 'desktop', 'workspace-contract.json'); await fs.mkdir(path.dirname(contractFile), { recursive: true });
  const contract = { format: 2, kind: 'desktop-workspace-runtime-contract', protocol: 2, policy: policyId,
    programs: Object.fromEntries(policy.PROGRAMS.map((name, i) => [name, sha('public-program-' + i)])) };
  const raw = rawTransform(contract), contractSha = sha(raw);
  const manifest = { format: 1, platform: 'win32-x64', sourceRevision: 'a'.repeat(40),
    sourceFingerprint: { format: 1, files: 42, sha256: sha('public-source'), framework: '8.83.29' },
    sourceHashes: { ...contract.programs, 'desktop/workspace-contract.json': contractSha } };
  await fs.writeFile(contractFile, raw); await fs.writeFile(path.join(root, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  return { root, contractFile, raw, manifest, accepted: compiled(contractSha) };
}

test('an acceptance build uses only the bounded canonical installed contract pinned by native constants and original manifest', async t => {
  const f = await bundleFixture(t), proof = await f.accepted.verifyInstalled(f.root);
  assert.equal(proof.policy, policyId); assert.equal(proof.contractSha256, sha(f.raw));
  assert.equal(proof.manifest.sourceRevision, f.manifest.sourceRevision);
  assert.deepEqual((await fs.readdir(f.root)).sort(), ['application', 'manifest.json'], 'Policy does not allocate an archive/profile/DB.');
});

test('a changed contract, manifest program hash, missing program or noncanonical pinned wire fails before any runtime execution', async t => {
  for (const change of ['contract', 'program', 'missing']) {
    const f = await bundleFixture(t);
    if (change === 'contract') await fs.appendFile(f.contractFile, ' ');
    else { if (change === 'program') f.manifest.sourceHashes[policy.PROGRAMS[0]] = sha('changed'); else delete f.manifest.sourceHashes[policy.PROGRAMS[0]];
      await fs.writeFile(path.join(f.root, 'manifest.json'), JSON.stringify(f.manifest)); }
    await assert.rejects(f.accepted.verifyInstalled(f.root), { code: 'WORKSPACE_POLICY_INVALID' });
  }
  for (const transform of [value => JSON.stringify(value) + '\n', value => canonical(value), value => '{"format":1,' + canonical(value).slice(1) + '\n']) {
    const f = await bundleFixture(t, transform); await assert.rejects(f.accepted.verifyInstalled(f.root), { code: 'WORKSPACE_POLICY_INVALID' });
  }
});

test('hardlinked, linked, oversized and dot-aliased installed policy paths are rejected without changing accepted bytes', async t => {
  const f = await bundleFixture(t), original = await fs.readFile(f.contractFile), link = f.contractFile + '.hardlink'; await fs.link(f.contractFile, link);
  await assert.rejects(f.accepted.verifyInstalled(f.root), { code: 'WORKSPACE_POLICY_INVALID' }); await fs.unlink(link);
  await assert.rejects(f.accepted.verifyInstalled(path.join(f.root, 'application') + path.sep + '..'), { code: 'WORKSPACE_POLICY_INVALID' });
  assert.deepEqual(await fs.readFile(f.contractFile), original);
  await fs.writeFile(f.contractFile, Buffer.alloc(16385, 0x20)); await assert.rejects(f.accepted.verifyInstalled(f.root), { code: 'WORKSPACE_POLICY_INVALID' });
});

test('an installed runtime directory junction cannot supply an otherwise accepted contract', async t => {
  const f = await bundleFixture(t), linked = f.root + '-linked';
  try { await fs.symlink(f.root, linked, process.platform === 'win32' ? 'junction' : 'dir'); }
  catch (error) { if (['EPERM', 'EACCES', 'ENOTSUP'].includes(error.code)) { t.skip('Creating a directory junction is unavailable.'); return; } throw error; }
  t.after(() => fs.rm(linked, { force: true }));
  await assert.rejects(f.accepted.verifyInstalled(linked), { code: 'WORKSPACE_POLICY_INVALID' });
  assert.equal((await fs.readFile(f.contractFile, 'utf8')), f.raw);
});
