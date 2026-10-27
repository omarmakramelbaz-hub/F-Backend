'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const prepared = require('../src/workspace-prepared.cjs');
const { record: validate, digest } = require('../src/workspace-profile.cjs');
const { record, hash } = require('./helpers/workspace-fixture.cjs');

function deltaRecord({ nextRevision = false } = {}) {
  const value = record(), { candidate, credential } = value;
  const previous = { database: candidate.database, snapshotId: candidate.snapshotId, schemaHash: candidate.schemaHash,
    manifest: structuredClone(value.manifest) };
  if (nextRevision) {
    value.manifest.revision++;
    value.manifest.signature = hash('next signed manifest');
    candidate.manifestRevision = value.manifest.revision;
    candidate.receipt.coverage.manifest_revision = value.manifest.revision;
  }
  candidate.database = 'fasakhansta_dashboard_stage_' + 'c'.repeat(16); candidate.generation = 'c'.repeat(16);
  candidate.snapshotId = '44444444-4444-4444-8444-444444444444';
  const receipt = candidate.receipt;
  receipt.snapshot_id = candidate.snapshotId; receipt.workspace = prepared.workspaceBinding(value.manifest);
  receipt.delta_source_binding = prepared.expectedBinding(credential, previous.manifest);
  receipt.delta_source_identity = { snapshot_id: previous.snapshotId, schema_hash: previous.schemaHash,
    workspace: prepared.workspaceBinding(previous.manifest) };
  receipt.delta_replay = { format: 2, workspace_id: value.manifest.workspace_id, root_fingerprint: value.manifest.root_fingerprint,
    source_database: previous.database, sequence: 17, rows: 7, sha256: hash('all actors replay rows and sidecars') };
  receipt.delta_stage = { format: 2, kind: 'desktop-workspace-delta-stage', stage_id: hash('stage marker'),
    stage_database: candidate.database, source_database: previous.database,
    capture_database: 'fasakhansta_dashboard_stage_' + 'd'.repeat(16), capture_id: hash('capture marker'),
    device_id: value.manifest.workspace_id, actor_id: receipt.actor_id, snapshot_id: receipt.snapshot_id,
    workspace: structuredClone(receipt.workspace), receipt_sha256: hash('exact original PHP receipt bytes') };
  candidate.prepared = prepared.create({ credential, manifest: value.manifest, candidate: { ...candidate, mediaVerified: true },
    refreshId: '55555555-5555-4555-8555-555555555555' });
  return value;
}

function rebindReceiptDigest(value) { value.candidate.prepared.importReceiptSha256 = digest(value.candidate.receipt); }
const invalid = error => error.code === 'WORKSPACE_PREPARED_INVALID';

test('verified delta receipts preserve all four private proof fields and immutable physical custody', () => {
  const value = deltaRecord(), checked = validate(value, value.manifest.workspace_id);
  assert.equal(checked.candidate.prepared.actorId, 101);
  assert.equal(checked.candidate.receipt.delta_stage.actor_id, 101);
  assert.equal(checked.candidate.receipt.delta_replay.rows, 7);
  assert.notEqual(checked.candidate.receipt.delta_stage.capture_database, checked.candidate.database);
  assert.deepEqual(prepared.deltaReceipt(checked.candidate.receipt, checked.manifest, checked.candidate, checked.credential), checked.candidate.receipt);
});

test('membership can advance while the replay proof remains bound to the authenticated previous revision', () => {
  const value = deltaRecord({ nextRevision: true }), checked = validate(value, value.manifest.workspace_id);
  assert.equal(checked.manifest.revision, 2);
  assert.equal(checked.candidate.receipt.delta_source_binding.revision, 1);
  assert.equal(checked.candidate.receipt.delta_source_identity.workspace.manifest_revision, 1);
  assert.equal(checked.candidate.receipt.workspace.manifest_revision, 2);
});

for (const field of ['delta_stage', 'delta_replay', 'delta_source_identity', 'delta_source_binding'])
  test('a partial delta proof missing ' + field + ' cannot become a prepared pointer', () => {
    const value = deltaRecord(); delete value.candidate.receipt[field]; rebindReceiptDigest(value);
    assert.throws(() => validate(value, value.manifest.workspace_id), invalid);
  });

for (const [name, mutate] of [
  ['legacy protocol replay', receipt => { receipt.delta_replay.format = 1; }],
  ['foreign root', receipt => { receipt.delta_source_binding.root_fingerprint = hash('foreign'); }],
  ['foreign root replay', receipt => { receipt.delta_replay.root_fingerprint = hash('foreign'); }],
  ['foreign credential token', receipt => { receipt.delta_source_binding.token_hash = hash('foreign credential'); }],
  ['future source manifest', receipt => { receipt.delta_source_binding.revision = 2; }],
  ['same revision changed roster', receipt => { receipt.delta_source_binding.roster_sha256 = hash('changed roster'); }],
  ['same revision changed manifest', receipt => { receipt.delta_source_binding.manifest_sha256 = hash('changed manifest'); }],
  ['source identity rebound', receipt => { receipt.delta_source_identity.workspace.manifest_revision = 2; }],
  ['source identity arbitrary authority', receipt => { receipt.delta_source_identity.workspace.actor_id = 202; }],
  ['source database is target', (receipt, candidate) => { receipt.delta_replay.source_database = candidate.database; receipt.delta_stage.source_database = candidate.database; }],
  ['target database is capture', (receipt, candidate) => { receipt.delta_stage.capture_database = candidate.database; }],
  ['capture database is source', receipt => { receipt.delta_stage.capture_database = receipt.delta_stage.source_database; }],
  ['unrestricted capture database', receipt => { receipt.delta_stage.capture_database = 'production'; }],
  ['stage target rebound', receipt => { receipt.delta_stage.stage_database = 'fasakhansta_dashboard_stage_' + 'f'.repeat(16); }],
  ['custodian rewritten as current B', receipt => { receipt.delta_stage.actor_id = 202; }],
  ['foreign stage workspace', receipt => { receipt.delta_stage.workspace.root_fingerprint = hash('foreign'); }],
  ['stage snapshot rebound', receipt => { receipt.delta_stage.snapshot_id = '66666666-6666-4666-8666-666666666666'; }],
  ['unsafe sequence', receipt => { receipt.delta_replay.sequence = Number.MAX_SAFE_INTEGER + 1; }],
  ['negative row count', receipt => { receipt.delta_replay.rows = -1; }],
  ['legacy stage kind', receipt => { receipt.delta_stage.kind = 'desktop-delta-stage'; }],
  ['unknown replay fields', receipt => { receipt.delta_replay.actor_id = 101; }],
  ['unknown source binding fields', receipt => { receipt.delta_source_binding.role = 'admin'; }],
  ['unknown stage proof fields', receipt => { receipt.delta_stage.trusted = true; }],
  ['invalid exact-wire digest', receipt => { receipt.delta_stage.receipt_sha256 = 'not a digest'; }],
]) test('delta pointer rejects ' + name + ' even if its outer JSON digest is updated', () => {
  const value = deltaRecord(); mutate(value.candidate.receipt, value.candidate); rebindReceiptDigest(value);
  assert.throws(() => validate(value, value.manifest.workspace_id), invalid);
});

test('PHP exact-wire receipt digest is retained as an opaque commitment for PHP verification', () => {
  const value = deltaRecord(), opaque = value.candidate.receipt.delta_stage.receipt_sha256;
  assert.notEqual(opaque, digest(value.candidate.receipt));
  assert.equal(validate(value, value.manifest.workspace_id).candidate.receipt.delta_stage.receipt_sha256, opaque);
});
