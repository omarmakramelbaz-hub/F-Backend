'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const { WorkspaceSync } = require('../src/workspace-sync.cjs');
const { fixture, hash } = require('./helpers/workspace-fixture.cjs');
function original(actor, active, dependencies = []) {
  return { command_id: crypto.randomUUID(), actor_id: actor,
    route_name: 'orders.store', payload: { original_actor: actor }, dependencies,
    workspace_authority: { format: 2, workspace_id: active.manifest.workspace_id, root_fingerprint: active.manifest.root_fingerprint,
      actor_id: actor, manifest_revision: 1, grant: hash('historical-grant-' + actor), command_sha256: hash('original-body-' + actor) } };
}

test('shared root queue drains original A then B with unchanged dependencies and a lost reply replays the exact A UUID/proof', async t => {
  const f = await fixture(t), a = original(101, f.value), b = original(202, f.value, [a.command_id]);
  const queue = [a, b], sent = [], acknowledged = [], serverReceipts = new Map(); let loseReply = true;
  const workspace = { registry: f.registry, async control(value) {
    if (value.action === 'pending') return { commands: queue.slice(0, 1), counts: { pending: queue.length, conflicts: 0 }, print_protocol: 0 };
    if (value.action === 'failed') return {};
    if (value.action === 'acknowledge') { assert.equal(value.command_id, queue[0].command_id); acknowledged.push(value.command_id); queue.shift(); return { counts: { pending: queue.length, conflicts: 0 } }; }
  } };
  const remote = async (value, credential) => {
    assert.equal(credential.token, f.value.credential.token); sent.push(structuredClone(value));
    if (!serverReceipts.has(value.command_id)) serverReceipts.set(value.command_id, { command_id: value.command_id, actor_id: value.actor_id });
    if (loseReply) { loseReply = false; throw Error('lost response after server commit'); }
    return serverReceipts.get(value.command_id);
  };
  const sync = new WorkspaceSync({ workspace, remote }); await sync.run(); assert.equal(queue.length, 2);
  await sync.run(); assert.equal(queue.length, 0); assert.equal(serverReceipts.size, 2);
  assert.deepEqual(sent, [a, a, b]); assert.deepEqual(acknowledged, [a.command_id, b.command_id]);
});

test('foreign actor/root proof is rejected before any server request or local acknowledgement', async t => {
  const f = await fixture(t), a = original(101, f.value); a.workspace_authority.actor_id = 202; let remoteCalls = 0;
  const sync = new WorkspaceSync({ workspace: { registry: f.registry, control: async () => ({ commands: [a], counts: { pending: 1, conflicts: 0 } }) },
    remote: async () => { remoteCalls++; } });
  await sync.run(); assert.equal(remoteCalls, 0); assert.match(sync.state.error, /ترتيب عمليات/);
});

test('accepted history is not filtered by a later current actor or roster', async t => {
  const f = await fixture(t), accepted = original(999, f.value), sent = [];
  let pending = true;
  const sync = new WorkspaceSync({ workspace: { registry: f.registry, async control(value) {
    if (value.action === 'pending') return { commands: pending ? [accepted] : [], counts: { pending: pending ? 1 : 0, conflicts: 0 } };
    if (value.action === 'acknowledge') { pending = false; return { counts: { pending: 0, conflicts: 0 } }; }
  } }, remote: async value => { sent.push(value.actor_id); return { command_id: value.command_id }; } });
  await sync.run(); assert.deepEqual(sent, [999]);
});
