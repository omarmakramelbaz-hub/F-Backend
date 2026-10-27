'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path'), crypto = require('node:crypto');
const { WorkspaceRegistry, manifest, digest, canonical } = require('../src/workspace-profile.cjs');
const { GenerationStore } = require('../src/dashboard-generation.cjs');
const workspaceId = '11111111-1111-4111-8111-111111111111';
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function encryption() {
  const key = crypto.randomBytes(32);
  return { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      const body = Buffer.concat([cipher.update(value, 'utf8'), cipher.final()]); return Buffer.concat([iv, cipher.getAuthTag(), body]); },
    decryptString(value) { const data = Buffer.from(value), cipher = crypto.createDecipheriv('aes-256-gcm', key, data.subarray(0, 12));
      cipher.setAuthTag(data.subarray(12, 28)); return Buffer.concat([cipher.update(data.subarray(28)), cipher.final()]).toString('utf8'); } };
}
function candidate({ revision = 1, sequence = 1, generation = 'a'.repeat(16), members } = {}) {
  members ||= [
    { actor_id: 101, role: 'admin', enabled: true, aliases: ['owner@example.test'], branches: ['f:100', 'f:101'],
      permissions: { super_admin: true, names: ['expenses-approve', 'orders-list'], roles: ['owner'] }, password_binding: hash('public-owner-password-binding'), grant: hash('public-owner-grant') },
    { actor_id: 202, role: 'vendor', enabled: true, aliases: ['0branch', 'branch@example.test'], branches: ['f:100'],
      permissions: { super_admin: false, names: ['orders-list'], roles: ['مدير فرع'] }, password_binding: hash('public-branch-password-binding'), grant: hash('public-branch-grant') }
  ];
  const roster = { format: 2, kind: 'desktop-dashboard-workspace-members', workspace_id: workspaceId, revision,
    root_fingerprint: hash('public-root-fingerprint'), members, roster_sha256: digest(members), signature: hash('public-synthetic-signature-' + revision) };
  return { manifest: roster, credential: { workspaceId, serverOrigin: 'https://dashboard.example.test', token: hash('public-synthetic-group-token'), enrollmentId: roster.root_fingerprint },
    candidate: { workspaceId, database: 'fasakhansta_dashboard_stage_' + generation, generation,
      snapshotId: '22222222-2222-4222-8222-222222222222', schemaHash: hash('schema'), sourceRevision: 'a'.repeat(40),
      sourceFingerprint: { format: 1, files: 42, sha256: hash('source'), framework: '8.83.29' }, sourceSequence: sequence,
      manifestRevision: revision, rosterSha256: roster.roster_sha256 } };
}
async function fixture(t, verifyAuthority = async () => true) {
  const temporary = await fs.realpath(os.tmpdir());
  const root = await fs.mkdtemp(path.join(temporary, 'workspace-profile-')), safeStorage = encryption();
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  assert.equal(await fs.realpath(root), root, 'The fixture uses the canonical Windows path, not the 8.3 temporary-directory alias.');
  const create = () => new WorkspaceRegistry({ root, workspaceId, safeStorage, verifyAuthority });
  return { root, safeStorage, create, registry: create() };
}
async function active(registry, value = candidate()) {
  const ticket = await registry.stage(value); await registry.activate(ticket, { verifyCandidate: async () => true }); return value;
}
const deferred = () => { let resolve; const promise = new Promise(yes => { resolve = yes; }); return { promise, resolve }; };

test('one encrypted active record binds the physical database, complete roster and private credential atomically', async t => {
  const f = await fixture(t), value = candidate(), ticket = await f.registry.stage(value);
  assert.equal(await f.registry.current(), null);
  assert.deepEqual(await f.registry.publicState(), { protocol: 2, prepared: false, staging: true, members: 0, revision: 0 });
  await assert.rejects(f.registry.activate(ticket, { verifyCandidate: async () => false }), { code: 'WORKSPACE_CANDIDATE_REJECTED' });
  assert.equal(await f.create().current(), null);
  await f.registry.activate(ticket, { verifyCandidate: async checked => checked.candidate.rosterSha256 === value.manifest.roster_sha256 });
  assert.deepEqual(await f.create().current(), value);
  const raw = await fs.readFile(f.registry.file), publicState = JSON.stringify(await f.registry.publicState());
  for (const secret of [value.credential.token, value.manifest.members[0].grant, value.manifest.members[0].password_binding, 'owner@example.test']) {
    assert.equal(raw.includes(Buffer.from(secret)), false); assert.equal(publicState.includes(secret), false);
  }
  assert.deepEqual(await f.registry.publicState(), { protocol: 2, prepared: true, staging: false, members: 2, revision: 1 });
});

test('actor sessions share one workspace and database while keeping cookie partitions and sign-out metadata separate', async t => {
  const f = await fixture(t); await active(f.registry);
  assert.deepEqual(await fs.readdir(f.root), ['workspace-registry.enc'], 'Preparation creates no per-member runtime directories.');
  const a = await f.registry.actorSession(101), b = await f.registry.actorSession(202);
  assert.equal(a.database, b.database); assert.equal(a.workspaceProfile, b.workspaceProfile);
  assert.notEqual(a.profile, b.profile); assert.notEqual(a.partition, b.partition);
  const ma = new GenerationStore(a.profile, f.safeStorage), mb = new GenerationStore(b.profile, f.safeStorage);
  await ma.write('explicit-signout', { requirePassword: true }); assert.equal(await mb.read('explicit-signout'), null);
  assert.equal((await ma.read('explicit-signout')).requirePassword, true);
  assert.equal(a.role, 'admin'); assert.equal(b.role, 'vendor');
  await assert.rejects(f.registry.actorSession(303), { code: 'WORKSPACE_ACTOR_DENIED' });
});

test('already canonical aliases retain leading zeroes and submitted identifiers are normalized exactly once', async t => {
  const f = await fixture(t); await active(f.registry);
  assert.equal((await f.registry.findActor('  OWNER@EXAMPLE.TEST ')).actor_id, 101);
  assert.equal((await f.registry.findActor('00branch')).actor_id, 202);
  assert.equal(await f.registry.findActor('0branch'), null);
  assert.equal(await f.registry.findActor('unknown@example.test'), null);
});

test('new complete membership replaces old lookups without deleting a removed actor private state', async t => {
  const f = await fixture(t), first = await active(f.registry), a = await f.registry.actorSession(101);
  const sentinel = path.join(a.profile, 'uncertain-print.enc'); await fs.writeFile(sentinel, 'retained-original-bytes');
  const second = candidate({ revision: 2, generation: 'b'.repeat(16), members: [first.manifest.members[1]] });
  await active(f.registry, second);
  assert.equal(await f.registry.member(101), null); assert.equal(await f.registry.findActor('owner@example.test'), null);
  await assert.rejects(f.registry.actorSession(101), { code: 'WORKSPACE_ACTOR_DENIED' });
  assert.equal(await fs.readFile(sentinel, 'utf8'), 'retained-original-bytes');
  assert.equal((await f.create().member(202)).actor_id, 202);
});

test('membership and source high waters reject rollback, same-version replacement, root replacement and mismatched snapshots', async t => {
  const f = await fixture(t); await active(f.registry, candidate({ revision: 2, sequence: 2 }));
  await assert.rejects(f.registry.stage(candidate()), { code: 'WORKSPACE_MEMBERSHIP_ROLLBACK' });
  const changed = candidate({ revision: 2, sequence: 2 }); changed.manifest.members[1].permissions.names.push('stock-list');
  changed.manifest.roster_sha256 = digest(changed.manifest.members); changed.candidate.rosterSha256 = changed.manifest.roster_sha256;
  await assert.rejects(f.registry.stage(changed), { code: 'WORKSPACE_MEMBERSHIP_ROLLBACK' });
  await assert.rejects(f.registry.stage(candidate({ revision: 3, sequence: 1 })), { code: 'WORKSPACE_SOURCE_ROLLBACK' });
  const sourceChanged = candidate({ revision: 3, sequence: 2 }); sourceChanged.candidate.sourceRevision = 'b'.repeat(40);
  await assert.rejects(f.registry.stage(sourceChanged), { code: 'WORKSPACE_SOURCE_ROLLBACK' });
  const rootChanged = candidate({ revision: 3, sequence: 3 }); rootChanged.manifest.root_fingerprint = hash('other-root'); rootChanged.credential.enrollmentId = rootChanged.manifest.root_fingerprint;
  await assert.rejects(f.registry.stage(rootChanged), { code: 'WORKSPACE_ROOT_CHANGED' });
  const mismatch = candidate({ revision: 3, sequence: 3 }); mismatch.candidate.rosterSha256 = '0'.repeat(64);
  await assert.rejects(f.registry.stage(mismatch), { code: 'WORKSPACE_CANDIDATE_INVALID' });
});

test('manifest validation rejects disabled, forged role, ambiguous identifiers, malformed source and unknown fields', async t => {
  const f = await fixture(t);
  for (const mutate of [
    value => { value.manifest.members[0].enabled = false; },
    value => { value.manifest.members[0].role = 'customer'; },
    value => { value.manifest.members[1].permissions.super_admin = 'yes'; },
    value => { value.manifest.members[1].aliases = ['owner@example.test']; },
    value => { value.manifest.members[0].password = 'forbidden'; },
    value => { value.manifest.members[0].actor_id = Number.MAX_SAFE_INTEGER + 1; },
    value => { value.candidate.sourceFingerprint.files = 0; },
    value => { value.credential.serverOrigin = 'http://dashboard.example.test'; }
  ]) {
    const value = candidate(); mutate(value); value.manifest.roster_sha256 = digest(value.manifest.members); value.candidate.rosterSha256 = value.manifest.roster_sha256;
    await assert.rejects(f.registry.stage(value), error => /^WORKSPACE_(MEMBER_INVALID|ALIAS_AMBIGUOUS|CANDIDATE_INVALID|CREDENTIAL_INVALID)$/.test(error.code));
  }
  assert.equal(await f.registry.current(), null);
});

test('trusted authority and import verifiers are mandatory and callback mutation cannot change the stored record', async t => {
  const denied = await fixture(t, async () => ({ trusted: true }));
  await assert.rejects(denied.registry.stage(candidate()), { code: 'WORKSPACE_AUTHORITY_REJECTED' });
  const f = await fixture(t, async value => { value.manifest.members.length = 0; return true; });
  const ticket = await f.registry.stage(candidate());
  await assert.rejects(f.registry.activate(ticket), { code: 'WORKSPACE_VERIFIER_REQUIRED' });
  await f.registry.activate(ticket, { verifyCandidate: async value => { value.credential.token = 'bad'; return true; } });
  assert.equal((await f.registry.current()).manifest.members.length, 2);
  assert.equal((await f.registry.current()).credential.token, hash('public-synthetic-group-token'));
});

test('cancellation during authority and imported-candidate verification does not publish incomplete state', async t => {
  const gate = deferred(), entered = deferred(), controller = new AbortController();
  const f = await fixture(t, async () => { entered.resolve(); await gate.promise; return true; });
  const staging = f.registry.stage(candidate(), { signal: controller.signal }); await entered.promise; controller.abort(); gate.resolve();
  await assert.rejects(staging, { code: 'WORKSPACE_CANCELLED' }); assert.equal(await f.registry.current(), null);
  const good = await fixture(t); await active(good.registry);
  const ticket = await good.registry.stage(candidate({ revision: 2, generation: 'b'.repeat(16) }));
  const later = new AbortController();
  await assert.rejects(good.registry.activate(ticket, { signal: later.signal, verifyCandidate: async () => { later.abort(); return true; } }), { code: 'WORKSPACE_CANCELLED' });
  assert.equal((await good.registry.current()).manifest.revision, 1);
  assert.equal((await good.registry.publicState()).staging, true);
});

for (const failure of ['late-cancel', 'post-rename-error']) test(failure + ' restores the old active pointer while retaining monotonic high waters', async t => {
  const f = await fixture(t); await active(f.registry);
  const second = candidate({ revision: 2, sequence: 2, generation: 'b'.repeat(16) }), ticket = await f.registry.stage(second), controller = new AbortController();
  const original = f.registry.metadata.write.bind(f.registry.metadata); let once = true;
  f.registry.metadata.write = async (name, value) => {
    await original(name, value);
    if (once && value.active?.manifest.revision === 2) { once = false; if (failure === 'late-cancel') controller.abort(); else throw Error('simulated post-rename error'); }
  };
  await assert.rejects(f.registry.activate(ticket, { signal: controller.signal, verifyCandidate: async () => true }));
  const reopened = f.create(); assert.equal((await reopened.current()).manifest.revision, 1);
  await assert.rejects(reopened.activate(ticket, { verifyCandidate: async () => true }), { code: 'WORKSPACE_STAGE_STALE' });
  await assert.rejects(reopened.stage(candidate()), { code: 'WORKSPACE_MEMBERSHIP_ROLLBACK' });
  const fresh = await reopened.stage(second); await reopened.activate(fresh, { verifyCandidate: async () => true });
  assert.equal((await reopened.current()).manifest.revision, 2);
});

test('reopened registries reject stale stage tickets and rollback never removes the active workspace', async t => {
  const f = await fixture(t); await active(f.registry);
  const ticket = await f.registry.stage(candidate({ revision: 2, generation: 'b'.repeat(16) }));
  await f.create().rollback(ticket);
  await assert.rejects(f.registry.activate(ticket, { verifyCandidate: async () => true }), { code: 'WORKSPACE_STAGE_STALE' });
  assert.equal((await f.registry.current()).manifest.revision, 1);
  const privateCopy = await f.registry.current(); privateCopy.manifest.members.length = 0;
  assert.equal((await f.registry.current()).manifest.members.length, 2);
});

test('registry ciphertext corruption and hard links fail closed without replacing accepted bytes', async t => {
  const f = await fixture(t); await active(f.registry); const original = await fs.readFile(f.registry.file);
  const corrupt = Buffer.from(original); corrupt[corrupt.length - 1] ^= 1; await fs.writeFile(f.registry.file, corrupt);
  await assert.rejects(f.create().current(), { code: 'WORKSPACE_STATE_INVALID' }); assert.deepEqual(await fs.readFile(f.registry.file), corrupt);
  await fs.writeFile(f.registry.file, original); const link = path.join(f.root, 'other.enc'); await fs.link(f.registry.file, link);
  await assert.rejects(f.create().current(), { code: 'WORKSPACE_FILE_INVALID' }); assert.deepEqual(await fs.readFile(link), original);
});

test('the public synthetic manifest has one cross-language canonical roster digest', () => {
  const fixture = require('./fixtures/workspace-members-v2.json'), value = fixture.manifest;
  assert.equal(manifest(value, workspaceId).roster_sha256, digest(value.members));
  assert.equal(digest(value.members), fixture.members_sha256);
  const { signature, ...body } = value; assert.equal(digest(body), fixture.manifest_body_sha256);
  assert.equal(digest(Object.fromEntries(Object.entries(value).reverse())), digest(value));
  assert.equal(canonical({ 'ز': true, a: ['ب', 'a'] }), '{"a":["ب","a"],"ز":true}');
});

test('a different staged candidate cannot replace the first ticket and identical restaging is idempotent', async t => {
  const f = await fixture(t), ticket = await f.registry.stage(candidate());
  assert.deepEqual(await f.registry.stage(candidate()), ticket);
  await assert.rejects(f.registry.stage(candidate({ revision: 2, generation: 'b'.repeat(16) })), { code: 'WORKSPACE_STAGE_BUSY' });
  await f.registry.rollback(ticket); assert.equal(await f.registry.current(), null);
  const next = await f.registry.stage(candidate({ revision: 2, generation: 'b'.repeat(16) }));
  await assert.rejects(f.registry.activate(ticket, { verifyCandidate: async () => true }), { code: 'WORKSPACE_STAGE_STALE' });
  await f.registry.activate(next, { verifyCandidate: async () => true }); assert.equal((await f.registry.current()).manifest.revision, 2);
});

test('malicious actor directories and registry symlinks are rejected before an actor descriptor is returned', async t => {
  const f = await fixture(t); await active(f.registry);
  const outside = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'workspace-outside-')); t.after(() => fs.rm(outside, { recursive: true, force: true }));
  const actors = path.join(f.root, 'workspace-actors', workspaceId); await fs.mkdir(actors, { recursive: true });
  try { await fs.symlink(outside, path.join(actors, '202'), 'junction'); }
  catch (error) { if (process.platform === 'win32' && error.code === 'EPERM') return t.skip('Windows host does not permit test symlink creation.'); throw error; }
  await assert.rejects(f.registry.actorSession(202), { code: 'WORKSPACE_PATH_INVALID' });
  assert.deepEqual(await fs.readdir(outside), []);
  const accepted = await fs.readFile(f.registry.file); await fs.unlink(f.registry.file); const target = path.join(outside, 'registry.enc'); await fs.writeFile(target, accepted);
  await fs.symlink(target, f.registry.file, 'file'); await assert.rejects(f.create().current(), { code: 'WORKSPACE_FILE_INVALID' });
  assert.deepEqual(await fs.readFile(target), accepted);
});

test('lazy actor access never enumerates unrelated actor session directories', async t => {
  const f = await fixture(t); await active(f.registry);
  const a = await f.registry.actorSession(101), b = await f.registry.actorSession(202); await fs.rm(b.profile, { recursive: true });
  await fs.writeFile(b.profile, 'an unrelated invalid actor path');
  assert.equal((await f.registry.actorSession(101, { create: false })).profile, a.profile);
  await assert.rejects(f.registry.actorSession(202), { code: 'WORKSPACE_PATH_INVALID' });
});

test('nonfinancial accounts can retain an empty branch scope without gaining financial access', async t => {
  const f = await fixture(t), value = candidate();
  value.manifest.members.push({ actor_id: 303, role: 'admin', enabled: true, aliases: ['contracts@example.test'], branches: [],
    permissions: { super_admin: false, names: ['contract-list'], roles: ['Contracts admin'] }, password_binding: hash('nonfinancial-password'), grant: hash('nonfinancial-grant') });
  value.manifest.roster_sha256 = digest(value.manifest.members); value.candidate.rosterSha256 = value.manifest.roster_sha256;
  await active(f.registry, value);
  assert.deepEqual((await f.registry.member(303)).branches, []); assert.equal((await f.registry.member(303)).permissions.super_admin, false);
  assert.equal((await f.registry.actorSession(303)).database, value.candidate.database);
});

test('the directory guard rejects dot components before leaving the configured root', async t => {
  const f = await fixture(t);
  const escape = f.root + path.sep + '..' + path.sep + 'workspace-outside';
  await assert.rejects(f.registry.directory(escape, true), { code: 'WORKSPACE_PATH_INVALID' });
});

test('the cached internal record is immutable and private lookup copies cannot change later grants', async t => {
  const f = await fixture(t); await active(f.registry);
  const raw = await f.registry.read(); assert.equal(Object.isFrozen(raw.active.manifest.members[1].permissions.names), true);
  assert.throws(() => { raw.active.manifest.members[1].permissions.super_admin = true; }, TypeError);
  const exposed = await f.registry.member(202); exposed.permissions.super_admin = true;
  assert.equal((await f.registry.member(202)).permissions.super_admin, false);
});

test('registries in the same native owner serialize concurrent stages and reject a later stale rollback', async t => {
  const gate = deferred(), entered = deferred(); let calls = 0;
  const f = await fixture(t, async () => { if (++calls === 1) { entered.resolve(); await gate.promise; } return true; });
  const second = f.create(), firstStage = f.registry.stage(candidate()); await entered.promise;
  const blocked = assert.rejects(second.stage(candidate({ revision: 2, generation: 'b'.repeat(16) })), { code: 'WORKSPACE_STAGE_BUSY' });
  await new Promise(setImmediate); assert.equal(calls, 1, 'Another registry must not pass the shared writer admission while authority verification is pending.');
  gate.resolve(); const ticket = await firstStage; await blocked;
  const activation = second.activate(ticket, { verifyCandidate: async () => true });
  const stale = assert.rejects(f.registry.rollback(ticket), { code: 'WORKSPACE_STAGE_STALE' });
  await activation; await stale; assert.equal((await f.registry.current()).manifest.revision, 1);
});
