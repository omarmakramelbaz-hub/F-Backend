'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { WorkspaceGuard } = require('../src/workspace-guard.cjs');
function fixture() {
  const value = { commands: [], counts: { pending: 0, conflicts: 0 }, print_state: { prepared: 0, uncertain: 0, unacknowledged: 0 } };
  const state = { pending: [], dirty: true }, remoteState = { blocked: false, read: async () => state };
  const guard = new WorkspaceGuard({ workspace: { start: async () => {}, control: async () => value }, remoteState });
  return { value, state, remoteState, guard };
}

test('B online/offline transitions cannot hide any A pending command, prepared print, unknown paper effect or lost outcome', async () => {
  const f = fixture(); assert.equal(await f.guard.check({ operation: 'remote-write' }), true);
  for (const key of ['prepared', 'uncertain', 'unacknowledged']) { f.value.print_state[key] = 1;
    assert.equal(await f.guard.check({ operation: 'remote-write', actor: { actorId: 202 } }), false); f.value.print_state[key] = 0; }
  f.value.counts.pending = 1; assert.equal(await f.guard.check({ operation: 'capture' }), false); f.value.counts.pending = 0;
  f.state.pending.push('11111111-1111-4111-8111-111111111111'); assert.equal(await f.guard.check({ operation: 'remote-write' }), false);
});

test('missing/malformed global counters and an active snapshot fail closed instead of treating a recent audit as all-clear', async () => {
  const f = fixture(); delete f.value.print_state; assert.equal(await f.guard.check(), false);
  f.value.audit = []; assert.equal(await f.guard.check(), false);
  f.value.print_state = { prepared: 0, uncertain: 0, unacknowledged: -1 }; assert.equal(await f.guard.check(), false);
  f.value.print_state.unacknowledged = 0; f.remoteState.blocked = true; assert.equal(await f.guard.check({ operation: 'remote-write' }), false);
  assert.equal(await f.guard.check({ operation: 'capture' }), true, 'The accepted snapshot itself owns the global blocked gate.');
});
