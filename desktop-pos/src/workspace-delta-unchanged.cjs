'use strict';

const crypto = require('node:crypto');
const source = require('./dashboard-source.cjs');
const media = require('./dashboard-media.cjs');
const coverage = require('./dashboard-coverage.cjs');
const prepared = require('./workspace-prepared.cjs');
const readSnapshot = require('./dashboard-download.cjs');
const { rawJsonField } = require('./workspace-json-field.cjs');
const { canonical, digest, record: checkedRecord, manifest: checkedManifest } = require('./workspace-profile.cjs');

const MAX_ROWS = 500000, MAX_TABLES = 200, MAX_DDL = 1024 ** 2;
const object = value => value !== null && typeof value === 'object' && [Object.prototype, null].includes(Object.getPrototypeOf(value));
const exact = (value, keys) => object(value) && Object.keys(value).sort().join(',') === [...keys].sort().join(',');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const count = value => Number.isSafeInteger(value) && value >= 0 && value <= MAX_ROWS;
const positive = value => Number.isSafeInteger(value) && value > 0;
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const table = value => typeof value === 'string' && /^[a-z][a-z0-9_]{0,63}$/.test(value);
const same = (left, right) => canonical(left) === canonical(right);
const emptyMap = value => (object(value) || Array.isArray(value)) && Object.keys(value).length === 0;
const sha = value => crypto.createHash('sha256').update(value).digest('hex');

// This is the PHP codec's DDL identity, including its narrowly scoped removal
// of a mutable SHOW CREATE table counter. SQL rows never enter this helper.
function schemaHash(ddl, name) {
  if (typeof ddl !== 'string' || Buffer.byteLength(ddl) === 0 || Buffer.byteLength(ddl) > MAX_DDL) return null;
  ddl = ddl.replace(/\r\n/g, '\n');
  const start = ddl.match(/^CREATE TABLE `([a-z][a-z0-9_]{0,63})` \(\n/);
  const end = ddl.lastIndexOf('\n)');
  if (!start || start[1] !== name || end < 0) return null;
  const body = ddl.slice(0, end + 2), tail = ddl.slice(end + 2);
  if (!/^ ENGINE=[a-z][a-z0-9_]*\b/i.test(tail)) return null;
  const parts = tail.split(/('(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|``)*`)/s);
  for (let i = 0; i < parts.length; i += 2) parts[i] = parts[i].replace(/ AUTO_INCREMENT=[0-9]+(?= |$)/gi, '');
  return sha(body + parts.join('').replace(/[ \n\r\t\v\0]+$/, ''));
}

function tableHash(scope, name, schema, rowset, rows) {
  return sha('desktop-workspace-table-v2\0' + canonical({ ...scope, table: name,
    schema_sha256: schema, rowset_sha256: rowset, count: rows }));
}

/** Check only authenticated digests and small integer multiplicities, never SQL values. */
function validIndex(name, value, scope) {
  if (!table(name) || !exact(scope, ['workspace_id', 'revision', 'root_fingerprint', 'roster_sha256'])
    || !uuid(scope.workspace_id) || !positive(scope.revision) || !hash(scope.root_fingerprint) || !hash(scope.roster_sha256)
    || !exact(value, ['format', 'kind', 'workspace_id', 'revision', 'root_fingerprint', 'roster_sha256', 'table',
      'schema_sha256', 'count', 'hashes', 'rowset_sha256', 'table_sha256'])
    || value.format !== 2 || value.kind !== 'desktop-workspace-table-index' || value.table !== name
    || !Object.keys(scope).every(key => value[key] === scope[key]) || !hash(value.schema_sha256)
    || !count(value.count) || !hash(value.rowset_sha256) || !hash(value.table_sha256)
    || !(object(value.hashes) || (Array.isArray(value.hashes) && value.hashes.length === 0))) return false;
  const names = Object.keys(value.hashes);
  if (names.length > MAX_ROWS) return false;
  let total = 0;
  const rowset = crypto.createHash('sha256').update('dashboard-delta-table-v1\0');
  for (const key of names.sort()) {
    const multiplicity = value.hashes[key];
    if (!hash(key) || !positive(multiplicity) || multiplicity > MAX_ROWS) return false;
    total += multiplicity;
    if (total > MAX_ROWS) return false;
    rowset.update(key + ':' + multiplicity + '\n');
  }
  return total === value.count && rowset.digest('hex') === value.rowset_sha256
    && tableHash(scope, name, value.schema_sha256, value.rowset_sha256, value.count) === value.table_sha256;
}

function catalog(capture, delta, manifest) {
  if (!Array.isArray(delta.created_scopes) || delta.created_scopes.length > 1000) return false;
  if (!Object.hasOwn(capture, 'catalog')) return delta.created_scopes.length === 0;
  const value = capture.catalog, identity = capture.identity;
  if (!exact(value, ['format', 'workspace_id', 'root_fingerprint', 'snapshot_id', 'schema_hash', 'sequence', 'count', 'sha256'])
    || value.format !== 2 || value.workspace_id !== manifest.workspace_id || value.root_fingerprint !== manifest.root_fingerprint
    || value.snapshot_id !== identity.snapshot_id || value.schema_hash !== identity.schema_hash || value.sequence !== capture.sequence
    || !count(value.count) || value.count !== delta.created_scopes.length || !hash(value.sha256)
    || delta.coverage?.catalog_grants_sha256 !== value.sha256) return false;
  const raw = rawJsonField(readSnapshot.input(delta), 'created_scopes');
  return raw !== null && raw.startsWith('[') && raw.endsWith(']') && sha(raw) === value.sha256;
}

/**
 * An unchanged response can retain the active DB only when every public
 * binding and every table multiset agrees. Unfamiliar input takes the ordinary
 * verified reconstruction path. The native supervisor still owns idle/fence
 * checks, publication approval, capture cleanup and the private no-op audit.
 */
function unchanged(capture, delta, previousRecord, context) {
  try {
    const m = previousRecord?.manifest, id = m?.workspace_id;
    checkedRecord(previousRecord, id);
    const previous = previousRecord.candidate, p = previous.prepared, oldReceipt = previous.receipt;
    if (!exact(capture, ['format', 'kind', 'capture_database', 'source_database', 'capture_id', 'sequence', 'identity', 'index', 'replay',
      ...(Object.hasOwn(capture, 'catalog') ? ['catalog'] : [])])
      || capture.format !== 2 || capture.kind !== 'desktop-workspace-delta-capture' || !hash(capture.capture_id)
      || !/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(capture.capture_database || '')
      || capture.capture_database === p.database || capture.source_database !== p.database
      || !Number.isSafeInteger(capture.sequence) || capture.sequence < 0) return false;
    const identity = capture.identity, index = capture.index, replay = capture.replay;
    const scope = { workspace_id: id, revision: m.revision, root_fingerprint: m.root_fingerprint, roster_sha256: m.roster_sha256 };
    if (!exact(identity, ['device_id', 'actor_id', 'snapshot_id', 'schema_hash', 'branches', 'coverage', 'source', 'media', 'workspace'])
      || identity.device_id !== id || identity.actor_id !== p.actorId || identity.snapshot_id !== p.snapshotId
      || identity.schema_hash !== p.schemaHash || !same(identity.branches, p.branches)
      || !same(identity.coverage, oldReceipt.coverage) || !source.same(identity.source, p.sourceFingerprint)
      || !same(identity.workspace, prepared.workspaceBinding(m)) || !same(identity.media, oldReceipt.media)
      || !exact(index, ['format', 'kind', 'device_id', 'actor_id', 'workspace', 'source', 'tables'])
      || index.format !== 2 || index.kind !== 'desktop-workspace-delta-index' || index.device_id !== id
      || index.actor_id !== p.actorId || !same(index.workspace, scope) || !source.same(index.source, p.sourceFingerprint)
      || !exact(replay, ['format', 'workspace_id', 'root_fingerprint', 'source_database', 'sequence', 'rows', 'sha256'])
      || replay.format !== 2 || replay.workspace_id !== id || replay.root_fingerprint !== m.root_fingerprint
      || replay.source_database !== p.database || replay.sequence !== capture.sequence || !count(replay.rows) || !hash(replay.sha256)) return false;
    if (context?.manifest?.sourceRevision !== previous.sourceRevision || !source.same(context.sourceFingerprint, previous.sourceFingerprint)
      || !source.same(context.manifest.sourceFingerprint || context.sourceFingerprint, previous.sourceFingerprint)
      || context.workspaceApproval?.sourceRevision !== previous.sourceRevision
      || !source.same(context.workspaceApproval.sourceFingerprint, previous.sourceFingerprint)
      || context.workspaceApproval.sequence !== previous.sourceSequence) return false;
    if (!exact(delta, ['format', 'kind', 'snapshot_id', 'device_id', 'actor_id', 'branches', 'enrollment', 'created_scopes',
      'generated_at', 'source', 'transport_php', 'schema_hash', 'tables', 'media', 'media_issues', 'coverage',
      'workspace_manifest', 'workspace_enrollment', 'delta_summary'])
      || delta.format !== 2 || delta.kind !== 'workspace-data-delta' || !uuid(delta.snapshot_id)
      || delta.device_id !== id || delta.actor_id !== p.actorId || delta.schema_hash !== p.schemaHash
      || !source.same(delta.source, previous.sourceFingerprint) || !same(delta.branches, identity.branches)
      || !same(delta.coverage, identity.coverage) || !same(checkedManifest(delta.workspace_manifest, id), m)
      || !catalog(capture, delta, m)
      || !Array.isArray(delta.media_issues) || delta.media_issues.length !== 0
      || !exact(delta.delta_summary, ['changed_tables', 'added_rows', 'removed_rows'])
      || Object.values(delta.delta_summary).some(value => value !== 0)) return false;
    const root = delta.workspace_enrollment, binding = prepared.expectedBinding(previousRecord.credential, m);
    if (!exact(root, ['format', 'workspace_id', 'custodian_actor_id', 'enrollment_hash', 'token_hash', 'enrolled_at'])
      || root.format !== 2 || root.workspace_id !== id || root.custodian_actor_id !== p.actorId
      || !hash(root.enrollment_hash) || root.token_hash !== binding.token_hash || typeof root.enrolled_at !== 'string'
      || !root.enrolled_at || root.enrolled_at.length > 100
      || digest({ format: 2, kind: 'desktop-dashboard-workspace-root', workspace_id: root.workspace_id,
        custodian_actor_id: root.custodian_actor_id, enrollment_hash: root.enrollment_hash,
        token_hash: root.token_hash, enrolled_at: root.enrolled_at }) !== m.root_fingerprint
      || !exact(delta.enrollment, ['enrollment_hash', 'enrolled_at', 'base_branches'])
      || delta.enrollment.enrollment_hash !== root.enrollment_hash || delta.enrollment.enrolled_at !== root.enrolled_at
      || !same(delta.enrollment.base_branches, identity.branches)) return false;
    media.manifest(delta.media);
    if (!same(coverage.mediaReceipt(delta.media), identity.media)) return false;
    if (!object(index.tables) || !object(delta.tables)) return false;
    const names = Object.keys(index.tables).sort(), nextNames = Object.keys(delta.tables).sort();
    if (!names.length || names.length > MAX_TABLES || !names.includes('users') || !same(names, nextNames)
      || !same(names, Object.keys(oldReceipt.table_rows).sort())) return false;
    let total = 0;
    for (const name of names) {
      const before = index.tables[name], part = delta.tables[name], after = part?.delta;
      if (!validIndex(name, before, scope) || !exact(part, ['ddl', 'delta'])
        || schemaHash(part.ddl, name) !== before.schema_sha256
        || !exact(after, ['format', 'kind', 'workspace_id', 'revision', 'root_fingerprint', 'roster_sha256', 'table',
          'before_sha256', 'schema_sha256', 'remove', 'add', 'rowset_sha256', 'target_hash', 'count'])
        || after.format !== 2 || after.kind !== 'desktop-workspace-table-delta' || after.table !== name
        || !Object.keys(scope).every(key => after[key] === scope[key]) || after.schema_sha256 !== before.schema_sha256
        || after.before_sha256 !== before.table_sha256 || after.target_hash !== before.table_sha256
        || after.rowset_sha256 !== before.rowset_sha256 || after.count !== before.count
        || !Array.isArray(after.add) || after.add.length !== 0 || !emptyMap(after.remove)) return false;
      total += before.count;
      if (total > MAX_ROWS) return false;
    }
    return true;
  } catch { return false; }
}

module.exports = { unchanged, validIndex, schemaHash };
