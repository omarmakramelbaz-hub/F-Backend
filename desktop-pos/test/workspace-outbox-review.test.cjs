'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { WorkspaceOutboxReview } = require('../src/workspace-outbox-review.cjs');
const { fixture } = require('./helpers/workspace-fixture.cjs');

test('B cannot review/retry A first pending command or see its private error, and the physical custody identity stays A', async t => {
  const f = await fixture(t), messages = [], controls = [], review = new WorkspaceOutboxReview({
    workspace: { connection: async () => f.value.candidate.prepared }, actor: { authenticated: async () => true,
      authority: async () => ({ workspaceId: f.registry.workspaceId, actorId: 202 }), async control(value) { controls.push(value.action); return {
        format: 1, device_id: f.registry.workspaceId, actor_id: 202, snapshot_id: f.value.candidate.snapshotId,
        counts: { pending: 1, conflicts: 1, acknowledged: 0 }, first: { actor_id: 101, last_error: 'A-private-financial-error' } }; } },
    dialog: { async showMessageBox(_window, message) { messages.push(message); return { response: 0 }; } } });
  const result = await review.open({}); assert.equal(result.otherActor, true); assert.equal(result.retried, false);
  assert.deepEqual(controls, ['outbox-review']); assert.equal(JSON.stringify(messages).includes('A-private-financial-error'), false);
  assert.equal((await f.registry.current()).candidate.prepared.actorId, 101);
});
