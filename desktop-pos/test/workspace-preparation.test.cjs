'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto'), fs = require('node:fs/promises'), path = require('node:path');
const AccountProfiles = require('../src/account-profiles.cjs');
const { WorkspaceMigrationInventory, COUNTS, HOLDS } = require('../src/workspace-migration.cjs');
const { WorkspacePreparation } = require('../src/workspace-preparation.cjs');
const { canonical } = require('../src/workspace-profile.cjs');
const { fixture, hash } = require('./helpers/workspace-fixture.cjs');
const csrf = 'fresh_native_session_csrf_123456';
function seal(body, request) {
  const key = crypto.randomBytes(32), iv = crypto.randomBytes(12), publicKey = crypto.createPublicKey(request.public_key);
  const header = { format: 2, kind: 'desktop-dashboard-workspace-enrollment-seal', workspace_id: request.device_id,
    recipient_sha256: hash(publicKey.export({ type: 'spki', format: 'der' })), nonce_sha256: hash(request.nonce) };
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv); cipher.setAAD(Buffer.from(canonical(header)));
  const ciphertext = Buffer.concat([cipher.update(canonical(body)), cipher.final()]);
  return canonical({ header, encrypted_key: crypto.publicEncrypt({ key: publicKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, key).toString('base64'),
    iv: iv.toString('base64'), ciphertext: ciphertext.toString('base64'), tag: cipher.getAuthTag().toString('base64') });
}
async function setup(t) {
  const f = await fixture(t, { activate: false }), profiles = new AccountProfiles({ root: f.root, safeStorage: f.safeStorage });
  await profiles.bind('default', { actorId: 101, serverOrigin: f.value.credential.serverOrigin });
  const a = await profiles.account('default'), b = await profiles.create(); await profiles.bind(b.id, { actorId: 202, serverOrigin: f.value.credential.serverOrigin });
  const clean = () => ({ prepared: true, counts: Object.fromEntries(COUNTS.map(k => [k, 0])), holds: Object.fromEntries(HOLDS.map(k => [k, false])) });
  for (const row of [a, b]) await fs.writeFile(path.join(row.profile, 'accepted-legacy.json'), JSON.stringify(clean()));
  const proofB = path.join(b.profile, 'accepted-legacy.json'), visited = [], calls = [];
  const migration = new WorkspaceMigrationInventory({ profiles, inspectProfile: async row => {
    visited.push(row.id); const raw = await fs.readFile(path.join(row.profile, 'accepted-legacy.json'));
    return { format: 1, profileId: row.id, identitySha256: hash(raw), ...JSON.parse(raw) };
  } });
  const workspace = { registry: f.registry, async settings() { calls.push('settings'); }, async start() { calls.push('start'); },
    async stage(_snapshot, generation) { calls.push('stage'); return { ...f.value.candidate, generation,
      database: 'fasakhansta_dashboard_stage_' + generation, mediaVerified: true, sourceManifest: null }; },
    async verifyCandidate() { calls.push('verify'); return true; }, async restoreStage() { calls.push('restore'); return true; },
    async stop() { calls.push('stop'); } };
  const states = [];
  const options = { workspace, serverOrigin: f.value.credential.serverOrigin, migration, onState: state => states.push(state),
    challenge: async () => { calls.push('challenge'); return { protocol: 2, actor_id: 202, role: 'vendor', server_origin: f.value.credential.serverOrigin, csrf_token: csrf }; },
    enroll: async (_origin, request, suppliedCsrf) => {
      calls.push('enroll'); assert.equal(suppliedCsrf, csrf);
      assert.deepEqual(Object.keys(request).sort(), ['device_id', 'name', 'nonce', 'public_key']);
      const pending = await f.registry.metadata.read('workspace-preparation');
      assert.equal(pending.recipient.publicKey, request.public_key); assert.equal(pending.nonce, request.nonce);
      assert.match(pending.recipient.privateKey, /BEGIN PRIVATE KEY/);
      const raw = await fs.readFile(path.join(f.root, 'workspace-preparation.enc'));
      assert.equal(raw.includes(Buffer.from(pending.recipient.privateKey)), false);
      return seal({ credential: f.value.credential, manifest: f.value.manifest }, request);
    }, download: async () => { calls.push('download'); return { snapshot_id: f.value.candidate.snapshotId }; },
    transition: async work => { calls.push('fence-enter'); const result = await work(); calls.push('fence-exit'); return result; },
    relaunch: async () => { calls.push('relaunch'); assert.equal(calls.at(-3), 'stop'); } };
  return { ...f, profiles, a, b, clean, proofB, visited, calls, states, workspace, options,
    create: overrides => new WorkspacePreparation({ ...options, ...overrides }) };
}

test('one setup from an ordinary branch seals private enrollment, checks every legacy profile and commits before stopping/relaunching', async t => {
  const f = await setup(t), before = await fs.readFile(f.proofB), preparation = f.create(), status = await preparation.prepare(f.value.credential.serverOrigin, 'untrusted-page-csrf');
  assert.equal(status.prepared, true); assert.equal(status.phase, 'ready');
  assert.equal((await f.registry.current()).candidate.prepared.actorId, 101, 'The stable snapshot custody actor stays distinct from the initiating branch session.');
  assert.deepEqual(new Set(f.visited), new Set(['default', f.b.id])); assert.deepEqual(await fs.readFile(f.proofB), before);
  assert.deepEqual(f.calls, ['settings', 'challenge', 'enroll', 'download', 'start', 'stage', 'fence-enter', 'verify', 'stop', 'fence-exit', 'relaunch']);
  assert.equal(f.states.some(s => s.setup_timing?.running === true), true); assert.equal((await preparation.status()).setup_timing.running, false);
  const publicText = JSON.stringify(f.states); for (const secret of [f.value.credential.token, f.value.manifest.members[0].grant, 'PRIVATE KEY', 'owner@example.test']) assert.equal(publicText.includes(secret), false);
  assert.equal(await f.registry.metadata.read('workspace-preparation'), null);
});

test('clean A cannot start network/preparation while B has accepted pending work or uncertainty', async t => {
  const f = await setup(t), blocked = f.clean(); blocked.counts.pending = 1; blocked.counts.printEffects = 1;
  await fs.writeFile(f.proofB, JSON.stringify(blocked)); const before = await fs.readFile(f.proofB);
  await assert.rejects(f.create().prepare(f.value.credential.serverOrigin, csrf), { code: 'WORKSPACE_PREPARATION_LEGACY_WORK_PENDING' });
  assert.deepEqual(f.calls, ['stop']); assert.equal(await f.registry.current(), null); assert.deepEqual(await fs.readFile(f.proofB), before);
});

test('work accepted by B during staging invalidates the final all-profile inventory under the activation fence', async t => {
  const f = await setup(t), originalStage = f.workspace.stage;
  f.workspace.stage = async (...args) => { const result = await originalStage(...args), blocked = f.clean(); blocked.holds.remoteDirty = true;
    await fs.writeFile(f.proofB, JSON.stringify(blocked)); return result; };
  await assert.rejects(f.create().prepare(f.value.credential.serverOrigin, csrf), { code: 'WORKSPACE_PREPARATION_LEGACY_WORK_CHANGED' });
  assert.equal(await f.registry.current(), null); assert.equal((await f.registry.publicState()).staging, true);
  assert.equal(f.calls.includes('verify'), false); assert.equal(f.calls.includes('relaunch'), false);
});

test('a failed final check resumes the SAME staged database and source without redownloading or reimporting', async t => {
  const f = await setup(t); f.workspace.verifyCandidate = async () => { f.calls.push('verify-failed'); return false; };
  await assert.rejects(f.create().prepare(f.value.credential.serverOrigin, csrf), { code: 'WORKSPACE_CANDIDATE_REJECTED' });
  const state = await f.registry.read(), oldCandidate = state.staged.record.candidate, oldTicket = f.registry.ticket(state);
  assert.equal(state.active, null); f.calls.length = 0;
  f.workspace.verifyCandidate = async value => { f.calls.push('verify'); assert.equal(value.candidate.database, oldCandidate.database); return true; };
  await f.create().prepare(f.value.credential.serverOrigin, csrf);
  assert.equal((await f.registry.current()).candidate.database, oldCandidate.database);
  assert.equal(f.calls.includes('download'), false); assert.equal(f.calls.includes('stage'), false); assert.equal(f.calls.includes('restore'), true);
  await assert.rejects(f.registry.activate(oldTicket, { verifyCandidate: async () => true }), { code: 'WORKSPACE_STAGE_STALE' });
});

test('late cancellation before activation preserves every legacy byte and never reports a partial workspace as ready', async t => {
  const f = await setup(t), controller = new AbortController(), originalStage = f.workspace.stage, before = await fs.readFile(f.proofB);
  f.workspace.stage = async (...args) => { const result = await originalStage(...args); controller.abort(); return result; };
  await assert.rejects(f.create().prepare(f.value.credential.serverOrigin, csrf, { signal: controller.signal }), { code: 'WORKSPACE_PREPARATION_CANCELLED' });
  assert.equal(await f.registry.current(), null); assert.deepEqual(await fs.readFile(f.proofB), before);
  assert.equal(f.calls.includes('relaunch'), false); assert.equal(f.states.some(s => s.phase === 'ready'), false);
  assert.notEqual(await f.registry.metadata.read('workspace-preparation'), null);
});

test('a clean but changed legacy identity between retries cannot reuse a staged snapshot from before that change', async t => {
  const f = await setup(t); f.workspace.verifyCandidate = async () => false;
  await assert.rejects(f.create().prepare(f.value.credential.serverOrigin, csrf), { code: 'WORKSPACE_CANDIDATE_REJECTED' });
  const old = (await f.registry.read()).staged.record.candidate;
  // The trusted inspector's identity captures acknowledged history as well as
  // current pending counts; both profiles can be clean while that identity moves.
  const clean = await fs.readFile(f.proofB, 'utf8'); await fs.writeFile(f.proofB, clean + '\n');
  f.calls.length = 0; f.workspace.verifyCandidate = async () => true;
  await f.create().prepare(f.value.credential.serverOrigin, csrf);
  assert.equal(f.calls.includes('download'), true); assert.equal(f.calls.includes('stage'), true); assert.equal(f.calls.includes('restore'), false);
  assert.notEqual((await f.registry.current()).candidate.generation, old.generation);
});
