'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto'), fs = require('node:fs/promises'), path = require('node:path');
const { WorkspaceRemoteState, KEY } = require('../src/workspace-remote-state.cjs');
const { modeFixture } = require('./helpers/workspace-mode-fixture.cjs');

test('one encrypted root uncertainty survives A completion, B restart and remains dirty until a verified full union', async t => {
  const f = await modeFixture(t), id = crypto.randomUUID(), state = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => true });
  await state.begin(id); const raw = await fs.readFile(path.join(f.metadata.profile, KEY + '.enc'));
  assert.equal(raw.includes(Buffer.from(id)), false);
  const b = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => true });
  assert.deepEqual((await b.read()).pending, [id]); await assert.rejects(b.assertClean(), { code: 'WORKSPACE_REMOTE_STATE_OUTCOME_UNKNOWN' });
  await b.complete(id); await assert.rejects(b.assertClean(), { code: 'WORKSPACE_REMOTE_STATE_REFRESH_REQUIRED' });
  await b.snapshot(async () => ({ fullUnionVerified: true })); await b.assertClean();
});

test('unverified/deferred snapshots cannot clear root dirty state or admit a write during the snapshot gate', async t => {
  const f = await modeFixture(t), state = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => false });
  await state.browse(); let release; const started = state.snapshot(() => new Promise(resolve => { release = resolve; }));
  for (let i = 0; i < 8; i++) await Promise.resolve();
  const b = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => true });
  await assert.rejects(b.begin(crypto.randomUUID()), { code: 'WORKSPACE_REMOTE_STATE_SNAPSHOT_RUNNING' });
  while (!release) await new Promise(setImmediate); release({ deferred: true });
  await assert.rejects(started, { code: 'WORKSPACE_REMOTE_STATE_SNAPSHOT_UNVERIFIED' }); assert.equal((await state.read()).dirty, true);
});

test('missing global index cannot hide retained immutable remote attempts', async t => {
  const f = await modeFixture(t), state = new WorkspaceRemoteState({ workspace: f.workspace, verifySnapshot: async () => true });
  await state.initialize(); await fs.mkdir(path.join(f.metadata.profile, 'workspace-remote-attempts'));
  await fs.writeFile(path.join(f.metadata.profile, 'workspace-remote-attempts', 'retained-proof.enc'), 'accepted original request evidence');
  await fs.unlink(path.join(f.metadata.profile, KEY + '.enc'));
  await assert.rejects(state.read(), { code: 'WORKSPACE_REMOTE_STATE_INDEX_MISSING' });
  assert.equal(await fs.readFile(path.join(f.metadata.profile, 'workspace-remote-attempts', 'retained-proof.enc'), 'utf8'), 'accepted original request evidence');
});
