'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs/promises'), path = require('node:path');
const { WorkspaceRuntime } = require('../src/workspace-runtime.cjs');
const LocalRuntime = require('../src/local-runtime.cjs');
const readSnapshot = require('../src/dashboard-download.cjs');
const { fixture } = require('./helpers/workspace-fixture.cjs');
const create = f => new WorkspaceRuntime({ registry: f.registry, bundle: path.join(f.root, 'synthetic-source'), safeStorage: f.safeStorage,
  authorizeSource: async () => { throw Error('not needed before first view'); }, verifyActive: async () => true });

test('A/B share one physical credential, database and prepared pointer while private signout state stays separate', async t => {
  const f = await fixture(t), owner = create(f), credentials = await owner.settings();
  assert.equal(credentials.deviceId, f.registry.workspaceId);
  const a = await owner.actor(101), b = await owner.actor(202);
  assert.equal((await a.runtime.connection()).actorId, 101); assert.equal((await b.runtime.connection()).actorId, 101);
  assert.equal((await b.runtime.authority()).actorId, 202); assert.equal((await b.runtime.authority()).role, 'vendor');
  assert.equal(a.descriptor.database, b.descriptor.database); assert.equal(a.descriptor.workspaceProfile, b.descriptor.workspaceProfile);
  assert.notEqual(a.descriptor.profile, b.descriptor.profile); assert.notEqual(a.descriptor.partition, b.descriptor.partition);
  assert.equal(a.runtime.stop, undefined); assert.equal(b.runtime.cancelStartup, undefined);
  await a.runtime.metadata.write('explicit-signout', { requirePassword: true }); assert.equal(await b.runtime.metadata.read('explicit-signout'), null);
  assert.deepEqual(await owner.metadata.read('prepared'), f.value.candidate.prepared);
  await assert.rejects(owner.metadata.write('prepared', f.value.candidate.prepared), { code: 'WORKSPACE_RUNTIME_POINTER_WRITE_FORBIDDEN' });
  await assert.rejects(fs.stat(path.join(owner.physical.profile, 'prepared.enc')), { code: 'ENOENT' });
  assert.equal((await create(f).settings()).deviceId, credentials.deviceId);
  assert.deepEqual(await fs.readdir(path.join(f.registry.workspaceProfile, 'dashboard')), ['credentials.enc']);
});

test('a physical UUID mismatch fails closed without overwriting accepted legacy credentials', async t => {
  const f = await fixture(t), foreign = new LocalRuntime({ bundle: '/unused', profile: f.registry.workspaceProfile, safeStorage: f.safeStorage });
  await foreign.settings(); const file = path.join(foreign.profile, 'credentials.enc'), before = await fs.readFile(file);
  await assert.rejects(create(f).settings(), /تعذر فتح بيانات/); assert.deepEqual(await fs.readFile(file), before);
});

test('workspace local flags are explicit and default protocol1 native environments stay unchanged', async t => {
  const f = await fixture(t), owner = create(f), settings = await owner.settings();
  owner.physical.origin = 'http://127.0.0.1:32111'; owner.physical.dbPort = 32112;
  const grouped = owner.physical.nativeEnvironment(settings, '/synthetic-storage');
  assert.equal(grouped.DESKTOP_DASHBOARD_WORKSPACE_LOCAL, 'true'); assert.equal(grouped.DESKTOP_DASHBOARD_WORKSPACE_ENABLED, 'true');
  const legacy = new LocalRuntime({ bundle: '/unused', profile: '/legacy', safeStorage: f.safeStorage });
  legacy.origin = owner.physical.origin; legacy.dbPort = owner.physical.dbPort;
  assert.equal(legacy.nativeEnvironment(settings, '/synthetic-storage').DESKTOP_DASHBOARD_WORKSPACE_LOCAL, undefined);
});

test('workspace importer preserves exact raw snapshot numeric bytes in its second frame', async () => {
  const input = '{"format":2,"tables":{"orders":{"rows":[{"id":9007199254740999,"amount":1.0}]}}}';
  const snapshot = await readSnapshot(new Response(input, { headers: { 'content-type': 'application/json' } }));
  const framed = WorkspaceRuntime.importInput(snapshot, { token_hash: '1'.repeat(64) }), newline = framed.indexOf('\n');
  assert.equal(framed.slice(newline + 1), input);
  assert.deepEqual(JSON.parse(framed.slice(0, newline)), { format: 2, kind: 'desktop-workspace-import-input', expected_binding: { token_hash: '1'.repeat(64) } });
});

test('actor control requires the actual password-established private capability and overwrites supplied actor/proof fields', async t => {
  const f = await fixture(t), calls = [], owner = new WorkspaceRuntime({ registry: f.registry, bundle: path.join(f.root, 'unused'), safeStorage: f.safeStorage,
    authorizeSource: async () => false, verifyActive: async () => true, nativeFetch: async (url, options) => {
      calls.push({ url, options, body: JSON.parse(options.body) }); return new Response('{"ok":true}', { headers: { 'content-type': 'application/json' } });
    } });
  owner.physical.origin = 'http://127.0.0.1:32113'; const b = await owner.actor(202);
  await assert.rejects(b.runtime.control({ action: 'print-list' }), { code: 'WORKSPACE_RUNTIME_ACTOR_ACTION_DENIED' }); assert.equal(calls.length, 0);
  const member = await f.registry.member(202), cap = { format: 2, kind: 'desktop-workspace-native-session', workspace_id: f.registry.workspaceId,
    root_fingerprint: f.value.manifest.root_fingerprint, actor_id: 202, manifest_revision: 1, password_binding: member.password_binding,
    snapshot_id: f.value.candidate.snapshotId, schema_hash: f.value.candidate.schemaHash,
    control_binding: require('node:crypto').createHash('sha256').update(owner.physical.controlToken).digest('hex'), nonce: '1'.repeat(64), mac: '2'.repeat(64) };
  await owner.acceptSession({ actorId: 202, role: 'vendor' }, { native_session: cap });
  await b.runtime.control({ action: 'print-list', actor_id: 101, native_session: { actor_id: 101 }, workspace_actor: { grant: 'forged' } });
  assert.equal(calls[0].body.actor_id, 202); assert.deepEqual(calls[0].body.native_session, cap); assert.equal(calls[0].body.workspace_actor, undefined);
  assert.equal(calls[0].url, owner.physical.origin + '/_desktop/workspace-control');
  assert.equal(calls[0].options.headers.Authorization, 'Bearer ' + f.value.credential.token);
  await owner.control({ action: 'pending' }); assert.equal(calls[1].body.native_session, undefined);
  assert.throws(() => owner.control({ action: 'print-list' }), { code: 'WORKSPACE_RUNTIME_ROOT_ACTION_DENIED' });
  assert.throws(() => owner.control({ action: 'pending', native_session: cap }), { code: 'WORKSPACE_RUNTIME_CONTROL_REJECTED' });
  await assert.rejects(b.runtime.control({ action: 'refresh-begin' }), { code: 'WORKSPACE_RUNTIME_ACTOR_ACTION_DENIED' });
  owner.revokeSession(202); await assert.rejects(b.runtime.control({ action: 'print-list' }), { code: 'WORKSPACE_RUNTIME_ACTOR_ACTION_DENIED' });
  assert.equal(calls.length, 2);
});

test('a private capability from a previous native process cannot authorize a new physical control token', async t => {
  const f = await fixture(t), owner = create(f), other = create(f), member = await f.registry.member(202);
  const cap = { format: 2, kind: 'desktop-workspace-native-session', workspace_id: f.registry.workspaceId,
    root_fingerprint: f.value.manifest.root_fingerprint, actor_id: 202, manifest_revision: 1, password_binding: member.password_binding,
    snapshot_id: f.value.candidate.snapshotId, schema_hash: f.value.candidate.schemaHash,
    control_binding: require('node:crypto').createHash('sha256').update(owner.physical.controlToken).digest('hex'), nonce: '1'.repeat(64), mac: '2'.repeat(64) };
  await assert.rejects(other.acceptSession({ actorId: 202, role: 'vendor' }, { native_session: cap }), { code: 'WORKSPACE_RUNTIME_SESSION_REJECTED' });
  assert.equal(await other.hasSession(202), false);
});

test('A B and Owner reuse the hot physical start without import, publication, generation, roster scan or optional source upgrade', async t => {
  const f = await fixture(t), owner = create(f); let starts = 0, checks = 0;
  owner.verifyActive = async record => { checks++; assert.equal(record.candidate.prepared.workspaceProtocol, 2); return true; };
  for (const name of ['verifyCandidate', 'restoreStage', 'stage']) owner[name] = async () => { throw Error('Heavy inactive preparation must not run on login.'); };
  owner.authorizeSource = async () => { throw Error('Publication HTTP must not run on login.'); };
  owner.physical.candidate = async () => { throw Error('Installed source staging must not run on login.'); };
  owner.physical.start = async () => { starts++; return { origin: 'http://127.0.0.1:32122', token: 'private' }; };
  for (const actorId of [101, 202, 101]) { const actor = await owner.actor(actorId); await actor.runtime.start(); }
  assert.equal(starts, 1); assert.equal(checks, 1);
});

test('shutdown cancellation leaves an already-started physical generation available for accepted cutover/print settlement until final stop', async t => {
  const f = await fixture(t), owner = create(f); owner.physical.started = true;
  owner.sessions.set(202, { retained: 'accepted-private-capability' });
  owner.cancelStartup();
  assert.equal(owner.cancelReads, true); assert.equal(owner.physical.cancelReads, true);
  assert.equal(owner.stopping, false); assert.equal(owner.physical.stopping, false);
  assert.equal(owner.sessions.has(202), true);
  owner.physical.stop = async () => {};
  await owner.stop(); assert.equal(owner.stopping, true); assert.equal(owner.sessions.size, 0);
});
