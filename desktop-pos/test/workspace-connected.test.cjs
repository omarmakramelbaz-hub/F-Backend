'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { modeFixture } = require('./helpers/workspace-mode-fixture.cjs');
const { CENTRAL_KEY } = require('../src/workspace-connected.cjs');

test('central B proves actual server B while the shared source and physical custody remain A', async t => {
  const f = await modeFixture(t), connected = f.createConnected(202);
  await connected.open(); const record = await f.metadata.read(CENTRAL_KEY);
  assert.equal(record.binding.actor.actorId, 202); assert.equal(record.binding.root.custodianActorId, 101);
  assert.equal(f.view.current().local, false); assert.equal(f.generations, 1); assert.notEqual(f.held, null);
  const accepted = await connected.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/order/update' });
  assert.equal(accepted.actor.actorId, 202); assert.equal(accepted.root.workspaceId, f.registry.workspaceId);
  f.setProbeActor(101);
  await assert.rejects(connected.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/order/update' }), { code: 'WORKSPACE_MODE_SERVER_ACTOR_MISMATCH' });
});

test('A central latch remains globally selected but B cannot adopt its direct write grant or clear A uncertainty', async t => {
  const f = await modeFixture(t), a = f.createConnected(101); await a.open();
  const accepted = await f.metadata.read(CENTRAL_KEY), b = f.createConnected(202);
  assert.equal(await b.selected(), true);
  await assert.rejects(b.open(), { code: 'WORKSPACE_CONNECTED_OTHER_ACTOR' });
  await assert.rejects(b.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/order/update' }), { code: 'WORKSPACE_CONNECTED_OTHER_ACTOR' });
  f.setPrintPending(1);
  await assert.rejects(b.local(), { code: 'WORKSPACE_CONNECTED_GLOBAL_WORK_UNSETTLED' });
  assert.deepEqual(await f.metadata.read(CENTRAL_KEY), accepted); assert.notEqual(f.held, null);
  assert.equal(f.generations, 1);
  f.setPrintPending(0); await b.local(); assert.equal(f.generations, 2); assert.equal(f.view.current().local, true);
  assert.equal(await f.metadata.read(CENTRAL_KEY), null);
});

test('unconfirmed A local commands block direct central entry for B before source refresh or authority adoption', async t => {
  const f = await modeFixture(t), b = f.createConnected(202); f.setLocalPending(1);
  await assert.rejects(b.open(), { code: 'WORKSPACE_CONNECTED_GLOBAL_WORK_UNSETTLED' });
  assert.equal(f.generations, 0); assert.equal(await f.metadata.read(CENTRAL_KEY), null); assert.equal(f.held, null);
});

test('crash after a fresh union pointer commit clears returning latch only with verified audit and global clean evidence', async t => {
  const f = await modeFixture(t), b = f.createConnected(202); await b.open();
  const old = await f.metadata.read(CENTRAL_KEY); old.phase = 'returning'; await f.metadata.write(CENTRAL_KEY, old);
  const next = f.current; next.database = 'fasakhansta_dashboard_stage_' + 'd'.repeat(16); next.generation = 'd'.repeat(16);
  next.snapshotId = '66666666-6666-4666-8666-666666666666'; next.refreshId = '77777777-7777-4777-8777-777777777777'; f.setCurrent(next);
  await assert.rejects(b.selected({ recover: true }), { code: 'WORKSPACE_CONNECTED_LATCH_FOREIGN' });
  f.setVerifyReturn(true); f.setRemotePending(1);
  await assert.rejects(b.selected({ recover: true }), /Unknown remote/); assert.deepEqual(await f.metadata.read(CENTRAL_KEY), old);
  f.setRemotePending(0); assert.equal(await b.selected({ recover: true }), false); assert.equal(await f.metadata.read(CENTRAL_KEY), null);
});

test('group central authority rejects unsupported server protocol, foreign origin, and HEAD business writes', async t => {
  const f = await modeFixture(t), b = f.createConnected(202); await b.open();
  await assert.rejects(b.authorizeDirect({ method: 'POST', url: 'https://foreign.example.test/admin/order/update' }), { code: 'WORKSPACE_CONNECTED_REQUEST_FOREIGN' });
  await assert.rejects(b.authorizeDirect({ method: 'HEAD', url: 'https://dashboard.example.test/admin/order/update' }), { code: 'WORKSPACE_CONNECTED_REQUEST_FOREIGN' });
  b.probe = async (_root, actor) => ({ protocol: 2, workspace_id: f.current.deviceId, custodian_actor_id: 101, actor_id: actor.actorId, role: actor.role,
    manifest_revision: f.current.workspace.manifest_revision, root_fingerprint: f.current.workspace.root_fingerprint });
  await assert.rejects(b.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/order/update' }), { code: 'WORKSPACE_CONNECTED_SERVER_PROTOCOL_UNSUPPORTED' });
});


test('same online-only B can renew its fresh server grant without adopting another actor or entering stale local data', async t => {
  const f = await modeFixture(t), connected = f.createConnected(202); let revision = 2;
  const actor = { onlineOnly: true, authenticated: async () => true, offlineAuthenticated: async () => false, authority: async () => ({
    protocol: 2, workspaceId: f.current.deviceId, actorId: 202, role: 'vendor', manifestRevision: revision, grant: String(revision).repeat(64),
    workspace: { ...f.current.workspace, manifest_revision: revision, roster_sha256: 'e'.repeat(64), manifest_sha256: 'f'.repeat(64) } }) };
  connected.actor = actor; connected.probe = async (_root, effective) => ({ protocol: 2, workspace_id: f.current.deviceId, custodian_actor_id: 101,
    actor_id: effective.actorId, role: effective.role, manifest_revision: effective.manifestRevision, root_fingerprint: f.current.workspace.root_fingerprint, connected_request_protocol: 2 });
  await connected.open(); const original = await f.metadata.read(CENTRAL_KEY); revision = 3;
  const proof = await connected.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/settings/update' });
  assert.equal(proof.actor.actorId, 202); assert.equal(proof.actor.manifestRevision, 3); assert.deepEqual(await f.metadata.read(CENTRAL_KEY), original);
  await assert.rejects(connected.local(), { code: 'WORKSPACE_CONNECTED_LOCAL_PASSWORD_REQUIRED' });
});

test('a fresh online-only account may take its own server mode after A settles while the same cached DB stays fenced and A history remains', async t => {
  const f = await modeFixture(t), a = f.createConnected(101); await a.open();
  const previous = await f.metadata.read(CENTRAL_KEY), capability = { ...f.held }, generations = f.generations;
  const b = f.createConnected(202), actor = { onlineOnly: true, authenticated: async () => true, offlineAuthenticated: async () => false,
    authority: async () => ({ protocol: 2, workspaceId: f.current.deviceId, actorId: 303, role: 'vendor', manifestRevision: 2, grant: 'b'.repeat(64),
      workspace: { ...f.current.workspace, manifest_revision: 2, roster_sha256: 'c'.repeat(64), manifest_sha256: 'd'.repeat(64) } }) };
  b.actor = actor; b.probe = async (_root, effective) => ({ protocol: 2, workspace_id: f.current.deviceId, custodian_actor_id: 101,
    actor_id: effective.actorId, role: effective.role, manifest_revision: effective.manifestRevision, root_fingerprint: f.current.workspace.root_fingerprint,
    connected_request_protocol: 2 });
  f.setRemotePending(1);
  await assert.rejects(b.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/custom-extension/save' }), /Unknown remote/);
  assert.deepEqual(await f.metadata.read(CENTRAL_KEY), previous);
  f.setRemotePending(0);
  const authorized = await b.authorizeDirect({ method: 'POST', url: 'https://dashboard.example.test/admin/custom-extension/save' });
  assert.equal(authorized.actor.actorId, 303); assert.equal(authorized.actor.manifestRevision, 2);
  assert.deepEqual(f.held, capability); assert.equal(f.generations, generations, 'The server-to-server actor change does not rebuild or refresh the cached DB.');
  assert.equal((await f.metadata.read(CENTRAL_KEY)).binding.actor.actorId, 303);
  const history = 'workspace-central-history/' + require('../src/workspace-profile.cjs').digest(previous);
  assert.deepEqual(await f.metadata.read(history), previous); assert.ok(f.calls.some(c => c.action === 'remote-browse'));
  await assert.rejects(b.local(), { code: 'WORKSPACE_CONNECTED_LOCAL_PASSWORD_REQUIRED' });
});

test('online-only cached adoption verifies the union then actual local password before revealing local view', async t => {
  const f = await modeFixture(t), b = f.createConnected(202); f.setLocal(false);
  let authenticated = false, consumed = false;
  const original = f.actor(202);
  b.actor = { ...original, onlineOnly: true, offlineAuthenticated: async () => authenticated };
  b.canAuthenticateLocal = async () => !consumed;
  b.authenticateLocal = async () => {
    assert.equal(f.generations, 1); assert.equal(f.view.current().local, false);
    assert.equal(f.calls.some(c => c.action === 'snapshot-finish'), true);
    consumed = true; authenticated = true; b.actor.onlineOnly = false; return true;
  };
  await b.local(); assert.equal(consumed, true); assert.equal(f.view.current().local, true);
  assert.equal(await f.metadata.read(CENTRAL_KEY), null);
});

test('expired or rejected one-use local password keeps the already usable server view after union verification', async t => {
  const f = await modeFixture(t), b = f.createConnected(202); f.setLocal(false);
  b.actor = { ...f.actor(202), onlineOnly: true, offlineAuthenticated: async () => false };
  b.canAuthenticateLocal = async () => true;
  b.authenticateLocal = async () => { throw Object.assign(Error('Password changed.'), { code: 'ACCOUNT_CREDENTIALS_REJECTED' }); };
  await assert.rejects(b.local(), { code: 'ACCOUNT_CREDENTIALS_REJECTED' });
  assert.equal(f.view.current().local, false); assert.notEqual(f.held, null); assert.equal(f.generations, 1);
  b.canAuthenticateLocal = async () => false;
  await assert.rejects(b.local(), { code: 'WORKSPACE_CONNECTED_LOCAL_PASSWORD_REQUIRED' });
  assert.equal(f.generations, 1, 'Expired bridge does not loop login/import or silently enter local data.');
});
