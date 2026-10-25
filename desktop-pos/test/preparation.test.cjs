const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const { DashboardPreparation } = require('../src/dashboard-preparation.cjs');
function fixture() {
  const saved = new Map(), deviceId = crypto.randomUUID(), states = [], calls = [];
  const snapshot = { format: 1, kind: 'initial-dashboard-data', snapshot_id: crypto.randomUUID(), device_id: deviceId, actor_id: 10,
    source:{format:1,files:10,sha256:'d'.repeat(64),framework:'8.83.29'},
    schema_hash: 'a'.repeat(64), branches: ['f:100'], media: [], coverage: { full_dashboard: true, media: true } };
  const runtime = { metadata: { read: async name => saved.get(name) || null, write: async (name, value) => { saved.set(name, structuredClone(value)); } },
    settings: async () => ({ deviceId }), isPrepared: async () => saved.has('prepared'), connection: async () => saved.get('prepared'),
    start: async () => { calls.push('start'); }, stage: async (_snapshot, id) => { calls.push('stage'); return { database: 'fasakhansta_dashboard_stage_' + id, mediaVerified: true, sourceRevision: 'b'.repeat(40), sourceFingerprint:structuredClone(_snapshot.source),receipt: {} }; },
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
test('the authenticated actor is bound before local work and only its display label reaches account selection',async()=>{
  const f=fixture();f.snapshot.tables={users:{rows:[{id:11,name:'Other account',password:'never exposed'},
    {id:10,name:'  حساب الفرع\nالرئيسي  ',password:'never exposed'}]}};
  f.setup.onEnrolled=async value=>{
    assert.equal(value.actorId,10);assert.equal(value.serverOrigin,'https://fixture.test');
    assert.equal(f.saved.get('enrollment').token,value.token);f.calls.push('bound');
  };
  let details;
  f.setup.onPrepared=async(next,value)=>{assert.equal(next.actorId,10);details=value;};
  await f.setup.prepare('https://fixture.test','csrf');
  assert.equal(f.calls[0],'bound');assert.deepEqual(details,{accountLabel:'حساب الفرع الرئيسي'});
  assert.equal(JSON.stringify(details).includes('password'),false);
});
test('a rejected account binding keeps enrollment recoverable and cannot start or activate another account',async()=>{
  const f=fixture();f.setup.onEnrolled=async()=>{throw Error('another enrolled actor');};
  await assert.rejects(f.setup.prepare('https://fixture.test','csrf'),/another enrolled actor/);
  assert.equal(f.saved.get('enrollment').actorId,10);assert.equal(f.saved.has('prepared'),false);assert.deepEqual(f.calls,[]);
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
test('a missing server code binding or a different verified staging code cannot activate initial preparation', async () => {
  const missing=fixture();delete missing.snapshot.source;
  await assert.rejects(missing.setup.prepare('https://fixture.test','csrf'));assert.deepEqual(missing.calls,[]);
  const changed=fixture(),stage=changed.runtime.stage;
  changed.runtime.stage=async(...args)=>({...await stage(...args),sourceFingerprint:{...changed.snapshot.source,sha256:'e'.repeat(64)}});
  await assert.rejects(changed.setup.prepare('https://fixture.test','csrf'));
  assert.equal(changed.saved.has('prepared'),false);assert.equal(changed.calls.includes('activate'),false);
  assert.equal(changed.saved.get('enrollment').enrolled,true);
});
test('the explicit preview handshake prepares a verified original partial scope without promoting coverage', async () => {
  const f = fixture(); f.snapshot.coverage = { full_dashboard: false, media: true, write_routes: ['customers.save'], role_memberships: [] };
  const manifest = { format: 1, platform: 'win32-x64', channel: 'preview', fullDashboard: false,
    sourceRevision: 'b'.repeat(40), sourceFingerprint: f.snapshot.source, previewWriteRoutes: ['customers.save'] };
  f.runtime.coverageManifest = async () => manifest;
  const stage = f.runtime.stage;
  f.runtime.stage = async (...args) => ({ ...await stage(...args), receipt: { verified: true, device_id: f.snapshot.device_id,
    actor_id: f.snapshot.actor_id, schema_hash: f.snapshot.schema_hash, snapshot_id: f.snapshot.snapshot_id,
    source: f.snapshot.source, branches: f.snapshot.branches, coverage: f.snapshot.coverage, media: [] } });
  const setup = new DashboardPreparation({ runtime: f.runtime, allowPreview: true, enroll: async () => f.link, download: async () => f.snapshot });
  await setup.prepare('https://fixture.test', 'csrf');
  const ready = f.saved.get('prepared'); assert.equal(ready.fullCoverage, false); assert.equal(ready.previewOnly, true);
  assert.deepEqual(ready.coverage, f.snapshot.coverage);
});
