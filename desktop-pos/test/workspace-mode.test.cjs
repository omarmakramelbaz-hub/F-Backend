'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { modeFixture } = require('./helpers/workspace-mode-fixture.cjs');
const { rootBinding, actorBinding, serverSession, RETURN_KEY } = require('../src/workspace-mode.cjs');

test('actual B uses one durable physical fence with immutable custody A and no actor grant in root controls', async t => {
  const f = await modeFixture(t), b = f.createMode(202), intent = await b.hold();
  assert.equal(intent.root.custodianActorId, 101); assert.equal((await b.binding()).actor.actorId, 202);
  assert.deepEqual(await f.metadata.read(RETURN_KEY), intent); assert.equal(f.held.id, intent.id);
  assert.equal(f.calls.filter(c => c.action === 'refresh-begin').length, 1);
  for (const call of f.calls) { assert.equal(call.native_session, undefined); assert.equal(call.grant, undefined); assert.equal(call.actor_id, undefined); }
});

test('a lost fence reply reuses the same persisted capability after restart instead of acquiring another fence', async t => {
  const f = await modeFixture(t); f.setLoseBeginReply(true);
  await assert.rejects(f.createMode(101).hold(), /lost reply/); const accepted = await f.metadata.read(RETURN_KEY);
  const recovered = await f.createMode(202).hold(); assert.deepEqual(recovered, accepted);
  assert.equal(f.calls.filter(c => c.action === 'refresh-begin').length, 1);
});

test('B cannot cancel A global hold while A has an uncertain print or remote outcome', async t => {
  const f = await modeFixture(t), a = f.createMode(101), b = f.createMode(202), intent = await a.hold();
  f.setPrintPending(1); await assert.rejects(b.recover(), { code: 'WORKSPACE_MODE_GLOBAL_WORK_UNSETTLED' });
  assert.deepEqual(await f.metadata.read(RETURN_KEY), intent); assert.equal(f.held.id, intent.id);
  f.setPrintPending(0); f.setRemotePending(1); await assert.rejects(b.recover(), /Unknown remote/);
  assert.deepEqual(await f.metadata.read(RETURN_KEY), intent);
  f.setRemotePending(0); await b.recover(); assert.equal(await f.metadata.read(RETURN_KEY), null); assert.equal(f.held, null);
});

test('all actor wrappers serialize global transitions and never return another actor job as their own', async t => {
  const f = await modeFixture(t), a = f.createMode(101), b = f.createMode(202), order = []; let release;
  const first = a.serialize('same-name', async () => { order.push('A-start'); await new Promise(resolve => { release = resolve; }); order.push('A-end'); return 'A'; });
  const second = b.serialize('same-name', async () => { order.push('B'); return 'B'; });
  await Promise.resolve(); assert.equal(a.accepted, b.accepted); assert.equal(a.accepted.size, 2); assert.deepEqual(order, ['A-start']);
  release(); assert.deepEqual(await Promise.all([first, second]), ['A', 'B']); assert.deepEqual(order, ['A-start', 'A-end', 'B']);
});

test('an old-generation fence capability is never sent to a new DB without verified cutover evidence', async t => {
  const f = await modeFixture(t), mode = f.createMode(202), previous = await mode.hold(), next = f.current;
  next.database = 'fasakhansta_dashboard_stage_' + 'c'.repeat(16); next.generation = 'c'.repeat(16); next.snapshotId = '44444444-4444-4444-8444-444444444444';
  next.refreshId = '55555555-5555-4555-8555-555555555555'; f.setCurrent(next); const count = f.calls.length;
  await assert.rejects(mode.cancelHold(), { code: 'WORKSPACE_MODE_FENCE_FOREIGN' }); assert.equal(f.calls.length, count);
  assert.deepEqual(await f.metadata.read(RETURN_KEY), previous);
  f.setVerifyReturn(true); await mode.cancelHold(); assert.equal(f.calls.length, count); assert.equal(await f.metadata.read(RETURN_KEY), null);
  assert.equal(rootBinding(next).custodianActorId, 101);
});

test('implicit offline fallback refuses unknown writes and late foreign uncertainty before local handoff', async t => {
  const f = await modeFixture(t), mode = f.createMode(202); f.setLocal(false); await mode.hold();
  await assert.rejects(mode.local({ method: 'POST' }), { code: 'WORKSPACE_MODE_WRITE_OUTCOME_UNKNOWN' });
  f.setRemotePending(1); await assert.rejects(mode.local({ method: 'GET' }), /Unknown remote/);
  assert.equal(f.calls.some(c => c.action === 'view-switch'), false); assert.notEqual(await f.metadata.read(RETURN_KEY), null);
  f.setRemotePending(0); await mode.local({ method: 'GET' }); assert.equal(f.view.current().local, true); assert.equal(await f.metadata.read(RETURN_KEY), null);
});


test('direct hold calls from two actor facades share a physical fence mutex and cannot overwrite the first durable capability', async t => {
  const f = await modeFixture(t), a = f.createMode(101), b = f.createMode(202);
  const [first, second] = await Promise.all([a.hold(), b.hold()]);
  assert.deepEqual(first, second); assert.equal(f.calls.filter(c => c.action === 'refresh-begin').length, 1);
  assert.deepEqual(await f.metadata.read(RETURN_KEY), first);
});

test('uncertainty arriving between read and settled local handoff keeps the original hold', async t => {
  const f = await modeFixture(t), b = f.createMode(202); f.setLocal(false); const intent = await b.hold();
  const original = f.view.switchTo; f.view.switchTo = async (...args) => { f.setPrintPending(1); return original(...args); };
  await assert.rejects(b.local({ method: 'GET' }), { code: 'WORKSPACE_MODE_GLOBAL_WORK_UNSETTLED' });
  assert.deepEqual(await f.metadata.read(RETURN_KEY), intent); assert.equal(f.view.current().local, false);
});


test('fresh online-only B authority may be newer than cached data but cannot enter local mode without a cached password session', async t => {
  const f = await modeFixture(t), root = rootBinding(f.current), fresh = { protocol: 2, workspaceId: root.workspaceId, actorId: 303, role: 'vendor',
    manifestRevision: 2, grant: 'f'.repeat(64), workspace: { ...f.current.workspace, manifest_revision: 2, roster_sha256: 'd'.repeat(64), manifest_sha256: 'e'.repeat(64) } };
  const actor = { onlineOnly: true, authenticated: async () => true, offlineAuthenticated: async () => false, authority: async () => fresh };
  const accepted = await actorBinding(actor, root); assert.equal(accepted.actorId, 303); assert.equal(accepted.manifestRevision, 2);
  serverSession({ protocol: 2, workspace_id: root.workspaceId, custodian_actor_id: 101, actor_id: 303, role: 'vendor', manifest_revision: 2, root_fingerprint: root.rootFingerprint }, root, accepted);
  await assert.rejects(actorBinding({ ...actor, onlineOnly: false }, root), { code: 'WORKSPACE_MODE_ACTOR_INVALID' });
  const mode = f.createMode(202); mode.actor = actor; f.setLocal(false);
  await assert.rejects(mode.local(), { code: 'WORKSPACE_MODE_LOCAL_PASSWORD_REQUIRED' });
  assert.equal(f.calls.some(c => c.action === 'start'), false);
});
