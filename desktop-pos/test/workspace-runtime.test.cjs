'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs/promises'), path = require('node:path');
const { WorkspaceRuntime } = require('../src/workspace-runtime.cjs');
const LocalRuntime = require('../src/local-runtime.cjs');
const readSnapshot = require('../src/dashboard-download.cjs');
const { fixture } = require('./helpers/workspace-fixture.cjs');
const create = f => new WorkspaceRuntime({ registry: f.registry, bundle: path.join(f.root, 'synthetic-source'), safeStorage: f.safeStorage,
  authorizeSource: async () => { throw Error('not needed before first view'); }, verifyActive: async () => true });

test('A/B share one physical credential, database and prepared pointer while private signout state stays separate', async t => {
  const f = await fixture(t), owner = create(f), credentials = await owner.settings();
  assert.equal(credentials.deviceId, f.registry.workspaceId);
  const a = await owner.actor(101), b = await owner.actor(202);
  assert.equal((await a.runtime.connection()).actorId, 101); assert.equal((await b.runtime.connection()).actorId, 101);
  assert.equal((await b.runtime.authority()).actorId, 202); assert.equal((await b.runtime.authority()).role, 'vendor');
  assert.equal(a.descriptor.database, b.descriptor.database); assert.equal(a.descriptor.workspaceProfile, b.descriptor.workspaceProfile);
  assert.notEqual(a.descriptor.profile, b.descriptor.profile); assert.notEqual(a.descriptor.partition, b.descriptor.partition);
  assert.equal(a.runtime.stop, undefined); assert.equal(b.runtime.cancelStartup, undefined);
  await a.runtime.metadata.write('explicit-signout', { requirePassword: true }); assert.equal(await b.runtime.metadata.read('explicit-signout'), null);
  assert.deepEqual(await owner.metadata.read('prepared'), f.value.candidate.prepared);
  await assert.rejects(owner.metadata.write('prepared', f.value.candidate.prepared), { code: 'WORKSPACE_RUNTIME_POINTER_WRITE_FORBIDDEN' });
  await assert.rejects(fs.stat(path.join(owner.physical.profile, 'prepared.enc')), { code: 'ENOENT' });
  assert.equal((await create(f).settings()).deviceId, credentials.deviceId);
  assert.deepEqual(await fs.readdir(path.join(f.registry.workspaceProfile, 'dashboard')), ['credentials.enc']);
});

test('a physical UUID mismatch fails closed without overwriting accepted legacy credentials', async t => {
  const f = await fixture(t), foreign = new LocalRuntime({ bundle: '/unused', profile: f.registry.workspaceProfile, safeStorage: f.safeStorage });
  await foreign.settings(); const file = path.join(foreign.profile, 'credentials.enc'), before = await fs.readFile(file);
  await assert.rejects(create(f).settings(), /تعذر فتح بيانات/); assert.deepEqual(await fs.readFile(file), before);
});

test('workspace local flags are explicit and default protocol1 native environments stay unchanged', async t => {
  const f = await fixture(t), owner = create(f), settings = await owner.settings();
  owner.physical.origin = 'http://127.0.0.1:32111'; owner.physical.dbPort = 32112;
  const grouped = owner.physical.nativeEnvironment(settings, '/synthetic-storage');
  assert.equal(grouped.DESKTOP_DASHBOARD_WORKSPACE_LOCAL, 'true'); assert.equal(grouped.DESKTOP_DASHBOARD_WORKSPACE_ENABLED, 'true');
  const legacy = new LocalRuntime({ bundle: '/unused', profile: '/legacy', safeStorage: f.safeStorage });
  legacy.origin = owner.physical.origin; legacy.dbPort = owner.physical.dbPort;
  assert.equal(legacy.nativeEnvironment(settings, '/synthetic-storage').DESKTOP_DASHBOARD_WORKSPACE_LOCAL, undefined);
});

test('workspace importer preserves exact raw snapshot numeric bytes in its second frame', async () => {
  const input = '{"format":2,"tables":{"orders":{"rows":[{"id":9007199254740999,"amount":1.0}]}}}';
  const snapshot = await readSnapshot(new Response(input, { headers: { 'content-type': 'application/json' } }));
  const framed = WorkspaceRuntime.importInput(snapshot, { token_hash: '1'.repeat(64) }), newline = framed.indexOf('\n');
  assert.equal(framed.slice(newline + 1), input);
  assert.deepEqual(JSON.parse(framed.slice(0, newline)), { format: 2, kind: 'desktop-workspace-import-input', expected_binding: { token_hash: '1'.repeat(64) } });
});
