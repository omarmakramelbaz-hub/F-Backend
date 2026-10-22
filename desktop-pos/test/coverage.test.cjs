'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path');
const coverage = require('../src/dashboard-coverage.cjs');
const { prepared } = require('../src/dashboard-generation.cjs');

function fixture() {
  const source = { format: 1, files: 10, sha256: 'd'.repeat(64), framework: '8.83.29' };
  const manifest = { format: 1, platform: 'win32-x64', sourceRevision: 'a'.repeat(40), sourceFingerprint: source,
    channel: 'preview', fullDashboard: false, previewWriteRoutes: ['customers.save', 'takeaway.checkout'] };
  const snapshot = { format: 1, kind: 'initial-dashboard-data', device_id: crypto.randomUUID(), actor_id: 10,
    schema_hash: 'b'.repeat(64), snapshot_id: crypto.randomUUID(), branches: ['f:100'], source, media: [],
    coverage: { full_dashboard: false, media: true, write_routes: [...manifest.previewWriteRoutes], role_memberships: [] } };
  const candidate = { database: 'fasakhansta_dashboard_stage_' + 'c'.repeat(16), sourceRevision: manifest.sourceRevision,
    sourceFingerprint: source, mediaVerified: true, receipt: { verified: true, device_id: snapshot.device_id,
      actor_id: snapshot.actor_id, schema_hash: snapshot.schema_hash, snapshot_id: snapshot.snapshot_id,
      source, coverage: snapshot.coverage, branches: snapshot.branches, media: [] } };
  const next = () => {
    const value = { format: 2, deviceId: snapshot.device_id, actorId: snapshot.actor_id, schemaHash: snapshot.schema_hash,
      snapshotId: snapshot.snapshot_id, branches: snapshot.branches, generation: 'c'.repeat(16), database: candidate.database,
      sourceRevision: candidate.sourceRevision, sourceFingerprint: candidate.sourceFingerprint, mediaVerified: true,
      refreshId: crypto.randomUUID(), token: 'e'.repeat(64), serverOrigin: 'https://fixture.test',
      ...coverage.preparedCoverage(snapshot, candidate, manifest, true) };
    value.coverageBinding = coverage.binding(value); return value;
  };
  return { source, manifest, snapshot, candidate, next };
}
test('capacity failures explain the aggregate limit without accepting an incomplete image snapshot', () => {
  const f = fixture(); f.snapshot.coverage.media = false;
  f.snapshot.media_issues = [{ path: 'products/1/original.png', reason: 'media-capacity-exceeded' }];
  assert.throws(() => coverage.snapshotCoverage(f.snapshot, f.manifest, true), /سعة تجهيز الجهاز/);
  f.snapshot.media_issues[0].reason = 'unavailable-or-unsupported';
  assert.throws(() => coverage.snapshotCoverage(f.snapshot, f.manifest, true), /قائمة صور التجهيز/);
});
test('partial scope requires native opt-in and an exact explicitly preview source-bound route inventory', () => {
  const f = fixture();
  assert.throws(() => coverage.snapshotCoverage(f.snapshot, f.manifest));
  for (const change of [m => delete m.channel, m => m.channel = 'release', m => m.fullDashboard = true,
    m => m.previewWriteRoutes.pop(), m => m.previewWriteRoutes.push('unknown.write'),
    m => m.previewWriteRoutes.push(m.previewWriteRoutes[0]), m => m.sourceFingerprint.sha256 = '0'.repeat(64)]) {
    const manifest = structuredClone(f.manifest); change(manifest);
    assert.throws(() => coverage.snapshotCoverage(f.snapshot, manifest, true));
  }
  const next = f.next(); assert.equal(next.fullCoverage, false); assert.equal(next.previewOnly, true);
  assert.deepEqual(next.coverage, f.snapshot.coverage);
  assert.equal(prepared(next, next.deviceId), false);
  assert.equal(prepared(next, next.deviceId, f.manifest), true);
});
test('a verified partial import must bind actor, device, schema, snapshot, branches, source, scope and media', () => {
  for (const change of [r => r.verified = false, r => r.device_id = crypto.randomUUID(), r => r.actor_id++,
    r => r.schema_hash = '0'.repeat(64), r => r.snapshot_id = crypto.randomUUID(), r => r.branches.push('f:200'),
    r => r.source.sha256 = '0'.repeat(64), r => r.coverage.role_memberships.push({ changed: true }),
    r => r.media.push({ path: 'unexpected.png' })]) {
    const f = fixture(), candidate = structuredClone(f.candidate); change(candidate.receipt);
    assert.throws(() => coverage.preparedCoverage(f.snapshot, candidate, f.manifest, true));
  }
  for (const field of ['deviceId', 'actorId', 'schemaHash', 'snapshotId', 'branches', 'coverage', 'mediaManifestHash']) {
    const f = fixture(), value = f.next();
    if (field === 'branches') value.branches.push('f:200');
    else if (field === 'coverage') value.coverage.role_memberships.push({ changed: true });
    else if (field === 'actorId') value.actorId++;
    else value[field] = '0'.repeat(64);
    assert.equal(prepared(value, value.deviceId, f.manifest), false, field);
  }
  const f = fixture(), promoted = f.next(); promoted.fullCoverage = true;
  assert.equal(prepared(promoted, promoted.deviceId, f.manifest), false);
});
test('route set equality permits ordering differences but retains the complete original coverage metadata', () => {
  const f = fixture(); f.snapshot.coverage.write_routes.reverse();
  assert.deepEqual(coverage.snapshotCoverage(f.snapshot, f.manifest, true).coverage, f.snapshot.coverage);
  f.snapshot.coverage.write_routes.pop(); assert.throws(() => coverage.snapshotCoverage(f.snapshot, f.manifest, true));
});
test('packaged PHP route parsing uses the original literal source and fails closed on expressions or unexpected grammar', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-coverage-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const directory = path.join(root, 'app/Services/Dashboard'); await fs.mkdir(directory, { recursive: true });
  const direct = "<?php class DesktopDashboardRoutes { public const WRITES=['customers.save','takeaway.checkout',]; }";
  const legacy = "<?php class DesktopDashboardLegacy { public const ROUTES=[\n 'roles.store'=>['model'=>User::class,'entity'=>'role','table'=>'roles','method'=>'POST','action'=>'store','parameter'=>'role'],\n ]; }";
  await fs.writeFile(path.join(directory, 'DesktopDashboardRoutes.php'), direct);
  await fs.writeFile(path.join(directory, 'DesktopDashboardLegacy.php'), legacy);
  assert.deepEqual(await coverage.packagedRoutes(root), ['customers.save', 'roles.store', 'takeaway.checkout']);
  await fs.writeFile(path.join(directory, 'DesktopDashboardRoutes.php'), direct.replace("'customers.save'", 'someFunction()'));
  await assert.rejects(coverage.packagedRoutes(root));
  await fs.writeFile(path.join(directory, 'DesktopDashboardRoutes.php'), direct);
  await fs.writeFile(path.join(directory, 'DesktopDashboardLegacy.php'), legacy.replace('User::class', 'someFunction()'));
  await assert.rejects(coverage.packagedRoutes(root));
});
