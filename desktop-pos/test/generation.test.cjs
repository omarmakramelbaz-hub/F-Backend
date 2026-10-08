const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { DashboardGeneration, GenerationStore, prepared } = require('../src/dashboard-generation.cjs');
const LocalRuntime = require('../src/local-runtime.cjs');
const readSnapshot = require('../src/dashboard-download.cjs');

async function fixture(t) {
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-generation-'));
  t.after(() => fs.rm(profile, { recursive: true, force: true }));
  const key = crypto.randomBytes(32);
  const safeStorage = { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      return Buffer.concat([iv, cipher.update(value), cipher.final(), cipher.getAuthTag()]); },
    decryptString(value) { const cipher = crypto.createDecipheriv('aes-256-gcm', key, value.subarray(0, 12));
      cipher.setAuthTag(value.subarray(-16)); return Buffer.concat([cipher.update(value.subarray(12, -16)), cipher.final()]).toString(); } };
  const metadata = new GenerationStore(profile, safeStorage);
  const previous = { format: 1, deviceId: crypto.randomUUID(), actorId: 10, schemaHash: 'a'.repeat(64),
    token: 'b'.repeat(64), fullCoverage: true, serverOrigin: 'https://example.test' };
  await metadata.write('prepared', previous);
  const oldLedger = [{ command_id: crypto.randomUUID(), status: 'acknowledged', amount: '10.00' }];
  const dbs = new Map([['fasakhansta_dashboard', oldLedger]]), attempts = new Map(), calls = [];
  let held = null;
  const state = id => ({ format: 1, device_id: previous.deviceId, actor_id: previous.actorId, schema_hash: previous.schemaHash,
    snapshot_id: crypto.randomUUID(), held: true, refresh_id: id, fenced_sequence: oldLedger.length });
  const runtime = { connection: () => metadata.read('prepared'),
    async control(value) {
      calls.push(value.action);
      const attempt = attempts.get(value.refresh_id);
      if (attempt && attempt.token !== value.token) throw Error('wrong owner');
      if (value.action === 'refresh-begin') {
        if (attempt) return attempt.state;
        if (held || oldLedger.some(row => row.status !== 'acknowledged')) throw Error('pending');
        held = value.refresh_id; attempts.set(held, { token: value.token, state: state(held) }); return attempts.get(held).state;
      }
      if (value.action === 'refresh-status') return attempt ? { exists: true, ...attempt.state } : { exists: false, held: false };
      if (value.action === 'refresh-cancel') { assert.ok(attempt); held = null; attempt.state = { ...attempt.state, held: false }; return attempt.state; }
      throw Error('unexpected control');
    },
    async stage(snapshot, generation) { calls.push('stage'); const database = 'fasakhansta_dashboard_stage_' + generation;
      assert.ok(!dbs.has(database)); dbs.set(database, []); return { database, sourceRevision: 'c'.repeat(40), sourceFingerprint:structuredClone(snapshot.source),mediaVerified: true, receipt: { verified: true, snapshot_id: snapshot.snapshot_id } }; },
    async activate(next, commit) { calls.push('activate'); await commit(); }
  };
  const snapshot = { format: 1, kind: 'initial-dashboard-data', device_id: previous.deviceId, actor_id: previous.actorId,
    source:{format:1,files:10,sha256:'e'.repeat(64),framework:'8.83.29'},
    snapshot_id: crypto.randomUUID(), schema_hash: previous.schemaHash, branches: ['f:100'],
    coverage: { full_dashboard: true, media: true }, media: [] };
  const create = (options = {}) => new DashboardGeneration({ runtime, metadata, download: async () => snapshot, ...options });
  return { profile, safeStorage, metadata, previous, runtime, calls, oldLedger, dbs, snapshot, create, held: () => held };
}

test('verified generations commit an encrypted pointer while retaining the old confirmed business journal', async t => {
  const f = await fixture(t), next = await f.create().run();
  assert.ok(prepared(next, f.previous.deviceId)); assert.equal(next.format, 2);
  assert.deepEqual(f.dbs.get('fasakhansta_dashboard'), f.oldLedger); assert.equal(f.dbs.size, 2);
  assert.equal((await f.metadata.read('prepared')).database, next.database);
  const archive = await f.metadata.read('generations/' + next.refreshId);
  assert.deepEqual(archive.previous, f.previous); assert.equal(archive.fence.fenced_sequence, 1);
  assert.equal(await f.metadata.read('refresh'), null); assert.equal(f.held(), next.refreshId);
  assert.ok(!(await fs.readFile(path.join(f.profile, 'prepared.enc'))).includes(Buffer.from(f.previous.token)));
});

test('incomplete dashboard/media, another account and changed schemas cannot create or activate a generation', async t => {
  const f = await fixture(t);
  for (const change of [{ coverage: { full_dashboard: false, media: false } }, { actor_id: 11 },
    { schema_hash: 'd'.repeat(64) }, { media: [{ path: 'logo.png' }] }]) {
    await assert.rejects(f.create({ download: async () => ({ ...f.snapshot, ...change }) }).run());
    assert.deepEqual(await f.metadata.read('prepared'), f.previous); assert.equal(f.held(), null);
  }
  assert.equal(f.calls.includes('stage'), false); assert.equal(f.dbs.size, 1);
});

test('retained conflicts block refresh before downloading; no empty outbox shortcut can erase them', async t => {
  const f = await fixture(t); f.oldLedger[0].status = 'conflict'; let downloads = 0;
  await assert.rejects(f.create({ download: async () => { downloads++; return f.snapshot; } }).run(), /pending/);
  assert.equal(downloads, 0); assert.equal(f.dbs.size, 1); assert.equal(await f.metadata.read('refresh'), null);
});
test('different verified staging code cancels refresh without replacing the old journal or pointer',async t=>{
  const f=await fixture(t),stage=f.runtime.stage;
  f.runtime.stage=async(...args)=>({...await stage(...args),sourceFingerprint:{...f.snapshot.source,sha256:'f'.repeat(64)}});
  await assert.rejects(f.create().run());assert.deepEqual(await f.metadata.read('prepared'),f.previous);
  assert.equal(f.held(),null);assert.equal(f.calls.includes('activate'),false);assert.equal(f.oldLedger.length,1);
});

test('failed download, failed staging and rejected pointer writes resume the existing data and retain partial databases', async t => {
  const f = await fixture(t);
  await assert.rejects(f.create({ download: async () => { throw Error('offline'); } }).run(), /offline/);
  const stage = f.runtime.stage;
  f.runtime.stage = async (...args) => { await stage(...args); throw Error('verification failed'); };
  await assert.rejects(f.create().run(), /verification failed/); f.runtime.stage = stage;
  const write = f.metadata.write.bind(f.metadata);
  f.metadata.write = async (name, value) => { if (name === 'prepared') throw Error('disk full'); return write(name, value); };
  await assert.rejects(f.create().run(), /disk full/);
  assert.deepEqual(await f.metadata.read('prepared'), f.previous); assert.equal(f.held(), null);
  assert.equal(f.dbs.size, 3); assert.equal(f.oldLedger.length, 1);
});

test('a lost fence reply and lost cancellation retain a recoverable capability across a new supervisor', async t => {
  const f = await fixture(t), control = f.runtime.control;
  f.runtime.control = async request => { const state = await control(request);
    if (request.action === 'refresh-begin') throw Error('lost begin reply');
    if (request.action === 'refresh-status') throw Error('local service stopped'); return state; };
  await assert.rejects(f.create().run(), /استرجاع/); const intent = await f.metadata.read('refresh');
  assert.equal(f.held(), intent.id);
  let lost = true;
  f.runtime.control = async request => { const state = await control(request);
    if (request.action === 'refresh-cancel' && lost) { lost = false; throw Error('lost cancel reply'); } return state; };
  await assert.rejects(f.create().recover(), /lost cancel/); assert.ok(await f.metadata.read('refresh'));
  await f.create().recover(); assert.equal(await f.metadata.read('refresh'), null); assert.equal(f.held(), null);
  assert.deepEqual(await f.metadata.read('prepared'), f.previous);
});

test('restart before acquiring a fence clears only the native intent and does not acquire a new fence', async t => {
  const f = await fixture(t);
  await f.metadata.write('refresh', { format: 1, id: crypto.randomUUID(), token: 'e'.repeat(64), generation: 'f'.repeat(16), previous: f.previous });
  await f.create().recover(); assert.deepEqual(f.calls, ['refresh-status']); assert.equal(f.held(), null);
});

test('a lost reply after the pointer commits keeps the new generation and never reopens its archived database', async t => {
  const f = await fixture(t);
  f.runtime.activate = async (next, commit) => { await commit(); throw Error('restart after commit'); };
  await assert.rejects(f.create().run(), /restart after commit/);
  const active = await f.metadata.read('prepared'); assert.equal(active.format, 2);
  assert.equal(f.held(), active.refreshId); assert.equal(f.calls.includes('refresh-cancel'), false);
  await f.create().recover(); assert.equal((await f.metadata.read('prepared')).refreshId, active.refreshId);
});

test('overlapping refresh ticks share a single staging and activation', async t => {
  const f = await fixture(t); let release;
  const blocked = new Promise(resolve => { release = resolve; });
  const coordinator = f.create({ download: async () => { await blocked; return f.snapshot; } });
  const first = coordinator.run(), second = coordinator.run(); assert.equal(first, second);
  release(); await first; assert.equal(f.calls.filter(value => value === 'stage').length, 1);
});

test('generation storage cannot escape the device profile and each generation has isolated caches', async t => {
  const f = await fixture(t), runtime = new LocalRuntime({ bundle: 'bundle', profile: f.profile, safeStorage: f.safeStorage });
  runtime.environment = { APP_KEY: 'private', DB_DATABASE: 'old' };
  assert.throws(() => runtime.storageFor({ generation: '../outside' }));
  const env = runtime.environmentFor({ generation: 'a'.repeat(16), database: 'fasakhansta_dashboard_stage_' + 'a'.repeat(16) });
  assert.equal(env.DB_DATABASE, 'fasakhansta_dashboard_stage_' + 'a'.repeat(16));
  assert.ok(env.APP_CONFIG_CACHE.startsWith(env.DESKTOP_DASHBOARD_STORAGE)); assert.equal(env.APP_KEY, 'private');
});

test('bounded downloads reject dishonest or missing lengths, wrong formats and malformed JSON', async () => {
  assert.deepEqual(await readSnapshot(new Response('{"text":"عربي"}', { headers: { 'content-type': 'application/json' } })), { text: 'عربي' });
  await assert.rejects(readSnapshot(new Response('x'.repeat(20), { headers: { 'content-type': 'application/json', 'content-length': '1' } }), 10));
  await assert.rejects(readSnapshot(new Response('{}', { headers: { 'content-type': 'text/html' } })));
  await assert.rejects(readSnapshot(new Response('invalid', { headers: { 'content-type': 'application/json' } })));
});
