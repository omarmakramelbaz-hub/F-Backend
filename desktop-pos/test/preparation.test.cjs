const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const { DashboardPreparation } = require('../src/dashboard-preparation.cjs');
function fixture() {
  const saved = new Map(), deviceId = crypto.randomUUID(), states = [], calls = [];
  const snapshot = { format: 1, kind: 'initial-dashboard-data', snapshot_id: crypto.randomUUID(), device_id: deviceId, actor_id: 10,
    schema_hash: 'a'.repeat(64), branches: ['f:100'], media: [], coverage: { full_dashboard: true, media: true } };
  const runtime = { metadata: { read: async name => saved.get(name) || null, write: async (name, value) => { saved.set(name, structuredClone(value)); } },
    settings: async () => ({ deviceId }), isPrepared: async () => saved.has('prepared'), connection: async () => saved.get('prepared'),
    start: async () => { calls.push('start'); }, stage: async (_snapshot, id) => { calls.push('stage'); return { database: 'fasakhansta_dashboard_stage_' + id, mediaVerified: true, sourceRevision: 'b'.repeat(40), receipt: {} }; },
    activate: async (_next, commit) => { calls.push('activate'); await commit(); } };
  const link = { protocol: 1, device_id: deviceId, actor_id: 10, token: 'c'.repeat(64), branches: ['f:100'] };
  const setup = new DashboardPreparation({ runtime, enroll: async () => link, download: async () => snapshot,
    onState: value => states.push(value), onPrepared: async () => calls.push('ready') });
  return { saved, snapshot, runtime, setup, states, calls, link };
}
test('initial preparation verifies a coherent generation before activating and publishes only bounded progress', async () => {
  const f = fixture(); const result = await f.setup.prepare('https://fixture.test', 'csrf');
  assert.equal(result.prepared, true); assert.equal(result.phase, 'ready');
  assert.deepEqual(f.calls, ['start', 'stage', 'activate', 'ready']);
  assert.equal(f.saved.get('prepared').actorId, 10); assert.equal(f.saved.get('prepared').mediaVerified, true);
  for (const value of f.states) assert.deepEqual(Object.keys(value).sort(), ['error', 'phase', 'progress']);
  assert.equal(JSON.stringify(f.states).includes(f.link.token), false);
  assert.equal((await f.setup.credential()).token, f.link.token);
});
test('a lost enrollment reply retries the persisted nonce without replacing a device identity', async () => {
  const f = fixture(), nonces = []; let failed = true;
  f.setup.enroll = async (_origin, input) => { nonces.push(input.nonce); assert.equal(f.saved.get('enrollment').nonce, input.nonce);
    if (failed) { failed = false; throw Error('lost enrollment response'); } return f.link; };
  await assert.rejects(f.setup.prepare('https://fixture.test', 'csrf'));
  await f.setup.prepare('https://fixture.test', 'csrf');
  assert.equal(nonces.length, 2); assert.equal(nonces[0], nonces[1]);
});
test('incomplete coverage, another account and malformed images cannot start or activate local data', async () => {
  for (const change of [s => s.coverage.full_dashboard = false, s => s.coverage.media = false, s => s.actor_id = 11,
    s => s.media = [{ path: '../x.png' }]]) {
    const f = fixture(); change(f.snapshot);
    await assert.rejects(f.setup.prepare('https://fixture.test', 'csrf')); assert.deepEqual(f.calls, []);
    assert.equal(f.saved.has('prepared'), false); assert.equal(f.saved.get('enrollment').enrolled, true);
  }
});
test('failed staging keeps enrollment and partial-generation intent, and a retry uses a new private generation', async () => {
  const f = fixture(), ids = []; const stage = f.runtime.stage; let failed = true;
  f.runtime.stage = async (snapshot, id) => { ids.push(id); if (failed) { failed = false; throw Error('interrupted image'); } return stage(snapshot, id); };
  await assert.rejects(f.setup.prepare('https://fixture.test', 'csrf')); assert.equal(f.saved.has('prepared'), false);
  assert.equal(f.saved.get('preparation').generation, ids[0]);
  await f.setup.prepare('https://fixture.test', 'csrf'); assert.notEqual(ids[0], ids[1]);
});
test('preparation cannot replace a ready dataset or rebind an existing enrollment to another server', async () => {
  const f = fixture(); await f.setup.prepare('https://fixture.test', 'csrf');
  const before = structuredClone(f.saved.get('prepared'));
  await assert.rejects(f.setup.prepare('https://another.test', 'csrf'));
  await f.setup.prepare('https://fixture.test', 'csrf'); assert.deepEqual(f.saved.get('prepared'), before);
  assert.deepEqual(f.calls, ['start', 'stage', 'activate', 'ready']);
  const other = fixture(); other.snapshot.coverage.full_dashboard = false;
  await assert.rejects(other.setup.prepare('https://fixture.test', 'csrf'));
  await assert.rejects(other.setup.prepare('https://another.test', 'csrf'));
  assert.equal(other.saved.get('enrollment').serverOrigin, 'https://fixture.test');
});
test('overlapping preparation clicks share one accepted enrollment and activation', async () => {
  const f = fixture(); let release, requests = 0;
  f.setup.download = async () => { requests++; await new Promise(resolve => { release = resolve; }); return f.snapshot; };
  const first = f.setup.prepare('https://fixture.test', 'csrf'), second = f.setup.prepare('https://fixture.test', 'csrf');
  assert.equal(first, second); await new Promise(resolve => setImmediate(resolve)); release(); await first;
  assert.equal(requests, 1); assert.equal(f.calls.filter(value => value === 'activate').length, 1);
});
test('unconfirmed work from a previous version blocks initial preparation before enrolling or downloading', async () => {
  const f = fixture(); f.setup.beforePrepare = async () => { throw Error('old pending sale'); };
  await assert.rejects(f.setup.prepare('https://fixture.test', 'csrf'));
  assert.equal(f.saved.has('enrollment'), false); assert.equal(f.saved.has('prepared'), false); assert.deepEqual(f.calls, []);
});
