'use strict';
const prepared = require('../../src/workspace-prepared.cjs');
const { record, hash, digest } = require('./workspace-fixture.cjs');
const { canonical } = require('../../src/workspace-profile.cjs');
const crypto = require('node:crypto');
const readSnapshot = require('../../src/dashboard-download.cjs');
const codec = require('../../../tests/fixtures/desktop-workspace-delta-codec-v2.json');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const tableHash = (scope, name, schema, rowset, count) => sha('desktop-workspace-table-v2\0'
  + canonical({ ...scope, table: name, schema_sha256: schema, rowset_sha256: rowset, count }));
const scopeFor = m => ({ workspace_id: m.workspace_id, revision: m.revision,
  root_fingerprint: m.root_fingerprint, roster_sha256: m.roster_sha256 });

function fixture() {
  const previous = record();
  previous.manifest = structuredClone(codec.manifest);
  const m = previous.manifest, c = previous.candidate, credential = previous.credential;
  const root = { format: 2, workspace_id: m.workspace_id, custodian_actor_id: 101,
    enrollment_hash: hash('synthetic enrollment nonce'), token_hash: hash(credential.token), enrolled_at: '2026-10-27 12:34:56' };
  m.root_fingerprint = digest({ ...root, kind: 'desktop-dashboard-workspace-root' });
  credential.enrollmentId = m.root_fingerprint;
  c.rosterSha256 = m.roster_sha256;
  c.receipt.branches = ['f:100'];
  c.receipt.rows = 3; c.receipt.table_rows = { users: 3 };
  c.receipt.coverage.root_fingerprint = m.root_fingerprint;
  c.receipt.coverage.roster_sha256 = m.roster_sha256;
  c.receipt.workspace = prepared.workspaceBinding(m);
  c.prepared = prepared.create({ credential, manifest: m, candidate: { ...c, mediaVerified: true }, refreshId: c.prepared.refreshId });
  const scope = scopeFor(m), before = { ...structuredClone(codec.users_index), ...scope };
  before.table_sha256 = tableHash(scope, 'users', before.schema_sha256, before.rowset_sha256, before.count);
  const capture = { format: 2, kind: 'desktop-workspace-delta-capture', capture_id: hash('private PHP capture'),
    capture_database: 'fasakhansta_dashboard_stage_' + 'd'.repeat(16), source_database: c.database, sequence: 7,
    identity: { device_id: m.workspace_id, actor_id: 101, snapshot_id: c.snapshotId, schema_hash: c.schemaHash,
      branches: [...c.receipt.branches], coverage: structuredClone(c.receipt.coverage), source: c.sourceFingerprint,
      media: [], workspace: prepared.workspaceBinding(m) },
    index: { format: 2, kind: 'desktop-workspace-delta-index', device_id: m.workspace_id, actor_id: 101,
      workspace: scope, source: c.sourceFingerprint, tables: { users: before } },
    replay: { format: 2, workspace_id: m.workspace_id, root_fingerprint: m.root_fingerprint,
      source_database: c.database, sequence: 7, rows: 2, sha256: hash('A and retired B commands, with authority proofs') } };
  const delta = { format: 2, kind: 'workspace-data-delta', device_id: m.workspace_id, actor_id: 101,
    snapshot_id: '44444444-4444-4444-8444-444444444444', schema_hash: c.schemaHash,
    generated_at: '2026-10-27T13:00:00+00:00', transport_php: { capture_scope: 'snapshot_export', php_sapi: 'fpm-fcgi',
      php_version: '8.2.29', serialize_precision: '-1', precision: '14' }, source: c.sourceFingerprint,
    branches: [...c.receipt.branches], coverage: structuredClone(c.receipt.coverage), media: [], media_issues: [], created_scopes: [],
    enrollment: { enrollment_hash: root.enrollment_hash, enrolled_at: root.enrolled_at, base_branches: [...c.receipt.branches] },
    workspace_manifest: structuredClone(m), workspace_enrollment: root,
    delta_summary: { changed_tables: 0, added_rows: 0, removed_rows: 0 },
    tables: { users: { ddl: codec.users_ddl, delta: { format: 2, kind: 'desktop-workspace-table-delta', ...scope,
      table: 'users', before_sha256: before.table_sha256, schema_sha256: before.schema_sha256, remove: [], add: [],
      rowset_sha256: before.rowset_sha256, target_hash: before.table_sha256, count: before.count } } } };
  const context = { manifest: { sourceRevision: c.sourceRevision, sourceFingerprint: c.sourceFingerprint },
    sourceFingerprint: c.sourceFingerprint, workspaceApproval: { sequence: c.sourceSequence,
      sourceRevision: c.sourceRevision, sourceFingerprint: c.sourceFingerprint } };
  return { previous, capture, delta, context };
}
async function catalogFixture(raw = '[{"descriptor":{"branch":"gs:9007199254740999","id":9007199254740999,"amount":1.0},"creation":{"kind":"server","label":"\\u0641\\/x"}}]') {
  const value = fixture(), { previous, capture, delta } = value, sum = sha(raw), rows = JSON.parse(raw).length;
  for (const facts of [previous.candidate.receipt.coverage, capture.identity.coverage, delta.coverage]) {
    facts.catalog_scopes = 1; facts.catalog_grants_sha256 = sum;
  }
  previous.candidate.prepared = prepared.create({ credential: previous.credential, manifest: previous.manifest,
    candidate: { ...previous.candidate, mediaVerified: true }, refreshId: previous.candidate.prepared.refreshId });
  capture.catalog = { format: 2, workspace_id: previous.manifest.workspace_id, root_fingerprint: previous.manifest.root_fingerprint,
    snapshot_id: capture.identity.snapshot_id, schema_hash: capture.identity.schema_hash, sequence: capture.sequence, count: rows, sha256: sum };
  const wire = JSON.stringify(delta).replace('"created_scopes":[]', '"created_scopes":' + raw);
  value.delta = await readSnapshot(new Response(wire, { headers: { 'content-type': 'application/json' } }));
  return value;
}
module.exports = { fixture, catalogFixture };
