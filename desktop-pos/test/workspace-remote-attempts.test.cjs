'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto'), fs = require('node:fs/promises'), path = require('node:path');
const { WorkspaceRemoteState } = require('../src/workspace-remote-state.cjs');
const { WorkspaceRemoteAttempts, requestKey, receiptKey } = require('../src/workspace-remote-attempts.cjs');
const { rootBinding, actorBinding } = require('../src/workspace-mode.cjs');
const { modeFixture } = require('./helpers/workspace-mode-fixture.cjs');
async function remoteFixture(t) {
  const f = await modeFixture(t), decisions = [], server = new Map(); let lost = false, refusal = false;
  const state = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => true });
  const request = async (body, credential) => {
    assert.deepEqual(Object.keys(body).sort(), ['actor_id', 'attempt', 'cancel', 'grant', 'manifest_revision']);
    assert.equal(credential.token, f.current.token); decisions.push(structuredClone(body));
    if (refusal) throw Object.assign(Error('root or historical proof refused'), { status: 401 });
    let row = server.get(body.attempt.id);
    if (!row) { row = { actor: body.actor_id, method: body.attempt.method, path: body.attempt.path, status: body.cancel ? 'cancelled' : 'ready' }; server.set(body.attempt.id, row); }
    assert.equal(row.actor, body.actor_id); assert.equal(row.method, body.attempt.method); assert.equal(row.path, body.attempt.path);
    if (body.cancel && row.status === 'ready') row.status = 'cancelled';
    if (lost) { lost = false; throw Error('lost reply after durable server decision'); }
    return { format: 2, kind: 'desktop-workspace-remote-attempt', id: body.attempt.id, device_id: credential.workspaceId,
      actor_id: row.actor, method: row.method, path: row.path, status: row.status, capability: body.cancel ? null : 'a'.repeat(64),
      workspace: { workspace_id: credential.workspaceId, root_fingerprint: credential.enrollmentId, manifest_revision: body.manifest_revision } };
  };
  const create = (id, central = false) => new WorkspaceRemoteAttempts({ workspace: f.workspace, actor: f.actor(id), state, request, guard: details => f.guard({ ...details, operation: 'central-write' }),
    direct: async () => central ? { format: 2, root: rootBinding(f.current), actor: await actorBinding(f.actor(id), rootBinding(f.current)) } : false });
  return { ...f, state, request, decisions, server, create, setLost(value) { lost = value; }, setRefusal(value) { refusal = value; } };
}
const details = path => ({ url: 'https://dashboard.example.test' + path, method: 'POST' });

test('actual B reservations persist immutable B evidence and the shared root fence before six-header transmission', async t => {
  const f = await remoteFixture(t), b = f.create(202), id = crypto.randomUUID();
  const proof = await b.begin(id, details('/admin/settings/update'));
  const record = await f.metadata.read(requestKey(id)); assert.equal(record.binding.actor.actorId, 202); assert.equal(record.binding.root.custodianActorId, 101);
  assert.deepEqual((await f.state.read()).pending, [id]); assert.equal(proof['X-Fasakhansta-Workspace-Actor'], '202');
  assert.equal(proof['X-Fasakhansta-Workspace-Token'], f.current.token); assert.equal(proof['X-Fasakhansta-Workspace-Attempt'], id);
  assert.equal(Object.keys(proof).some(name => name.startsWith('X-Fasakhansta-Connected') || name.startsWith('X-Fasakhansta-Remote')), false);
  const file = path.join(f.metadata.profile, requestKey(id) + '.enc'), accepted = await fs.readFile(file);
  await b.complete(id); assert.deepEqual(await fs.readFile(file), accepted); assert.equal((await f.metadata.read(receiptKey(id))).status, 'cancelled');
  assert.equal((await f.state.read()).dirty, true);
});

test('lost A reservation reply blocks B writes and recovers the same UUID with original A historical proof', async t => {
  const f = await remoteFixture(t), a = f.create(101), id = crypto.randomUUID(); f.setLost(true);
  await assert.rejects(a.begin(id, details('/admin/settings/update')), /lost reply/);
  const accepted = await f.metadata.read(requestKey(id)), b = f.create(202);
  await assert.rejects(b.begin(crypto.randomUUID(), details('/admin/settings/update')), { code: 'WORKSPACE_REMOTE_PREVIOUS_OUTCOME_UNKNOWN' });
  await b.recover(); assert.equal(f.decisions.length, 2); assert.equal(f.decisions[1].attempt.id, id); assert.equal(f.decisions[1].actor_id, 101);
  assert.equal(f.decisions[1].grant, accepted.binding.actor.grant); assert.equal(f.decisions[1].cancel, true);
  assert.deepEqual(await f.metadata.read(requestKey(id)), accepted); assert.deepEqual((await f.state.read()).pending, []);
});

test('a lost original HTTP response settles an A commit after B login without replaying business bytes', async t => {
  const f = await remoteFixture(t), a = f.create(101), id = crypto.randomUUID(); await a.begin(id, details('/admin/settings/update'));
  f.server.get(id).status = 'committed'; a.failed(id); await f.create(202).recover();
  assert.equal((await f.metadata.read(receiptKey(id))).status, 'committed'); assert.equal(f.decisions.length, 2);
  assert.equal(f.decisions[1].cancel, true); assert.equal(f.decisions[1].actor_id, 101); assert.equal(f.server.size, 1);
});

test('a terminal receipt flushed before a lost local completion is replayed after restart without another server decision', async t => {
  const f = await remoteFixture(t), a = f.create(101), id = crypto.randomUUID(); await a.begin(id, details('/admin/settings/update'));
  const originalComplete = f.state.complete.bind(f.state); let once = true;
  f.state.complete = async value => { if (once) { once = false; throw Error('lost local completion'); } return originalComplete(value); };
  await assert.rejects(a.complete(id), /lost local/); assert.notEqual(await f.metadata.read(receiptKey(id)), null);
  const count = f.decisions.length; await f.create(202).recover(); assert.equal(f.decisions.length, count); assert.deepEqual((await f.state.read()).pending, []);
});

test('B cannot review or adopt an A direct unknown outcome; original A can record human review without rewriting evidence', async t => {
  const f = await remoteFixture(t), a = f.create(101, true), id = crypto.randomUUID(); await a.begin(id, details('/admin/custom-extension/save'));
  a.failed(id); const record = await f.metadata.read(requestKey(id)), b = f.create(202, true);
  assert.deepEqual(await b.reviewable(), []); await assert.rejects(b.humanReview(id), { code: 'WORKSPACE_REMOTE_REVIEW_OTHER_ACTOR' });
  await assert.rejects(b.recover(), { code: 'WORKSPACE_REMOTE_DIRECT_OUTCOME_UNKNOWN' });
  assert.deepEqual((await f.state.read()).pending, [id]); assert.equal((await a.reviewable())[0].id, id);
  await a.humanReview(id); assert.deepEqual(await f.metadata.read(requestKey(id)), record); assert.equal((await f.state.read()).dirty, true);
  assert.deepEqual((await f.state.read()).pending, []);
});

test('received direct responses are observations, not invented terminal provider decisions', async t => {
  const f = await remoteFixture(t), b = f.create(202, true), id = crypto.randomUUID(); await b.begin(id, details('/admin/custom-extension/save'));
  const record = await f.metadata.read(requestKey(id)); await b.complete(id);
  assert.equal(await f.metadata.read(receiptKey(id)), null); assert.deepEqual(await f.metadata.read(requestKey(id)), record);
  assert.equal((await f.state.read()).dirty, true); assert.deepEqual((await f.state.read()).pending, []);
});

test('a refused historical/root proof never falls back to current B grant or erases the pending A capability', async t => {
  const f = await remoteFixture(t), a = f.create(101), id = crypto.randomUUID(); await a.begin(id, details('/admin/settings/update')); a.failed(id);
  f.setRefusal(true); await assert.rejects(f.create(202).recover(), { status: 401 });
  assert.deepEqual((await f.state.read()).pending, [id]); assert.equal(f.decisions.at(-1).actor_id, 101);
});

test('unknown noncentral routes and duplicate original UUIDs are blocked before another request can leave', async t => {
  const f = await remoteFixture(t), b = f.create(202), id = crypto.randomUUID();
  await assert.rejects(b.begin(id, details('/admin/custom-extension/save')), { code: 'WORKSPACE_REMOTE_ROUTE_UNSUPPORTED' });
  assert.equal(f.decisions.length, 0); assert.deepEqual((await f.state.read()).pending, []);
  await b.begin(id, details('/admin/settings/update')); await assert.rejects(b.begin(id, details('/admin/settings/update')), { code: 'WORKSPACE_REMOTE_REQUEST_ACTIVE' });
  await b.complete(id); await assert.rejects(b.begin(id, details('/admin/settings/update')), { code: 'WORKSPACE_REMOTE_REQUEST_EXISTS' });
});


test('a global uncertain print or accepted local command blocks every new server mutation before reservation', async t => {
  const f = await remoteFixture(t), b = f.create(202); f.setPrintPending(1);
  await assert.rejects(b.begin(crypto.randomUUID(), details('/admin/settings/update')), { code: 'WORKSPACE_REMOTE_GLOBAL_WORK_UNSETTLED' });
  f.setPrintPending(0); f.setLocalPending(1);
  await assert.rejects(b.begin(crypto.randomUUID(), details('/admin/settings/update')), { code: 'WORKSPACE_REMOTE_GLOBAL_WORK_UNSETTLED' });
  assert.equal(f.decisions.length, 0); assert.deepEqual((await f.state.read()).pending, []);
});
