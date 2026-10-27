'use strict';

// Native protocol-2 pointer validation. Shape checks do not replace the trusted
// PHP import/source/media/current-work verification required before activation.
const crypto = require('node:crypto');
const { object, exact, hash, uuid, canonical, digest } = require('./workspace-protocol.cjs');
const coverage = require('./dashboard-coverage.cjs');
const source = require('./dashboard-source.cjs');
const media = require('./dashboard-media.cjs');
const positive = value => Number.isSafeInteger(value) && value > 0;
const count = value => Number.isSafeInteger(value) && value >= 0;
const database = value => typeof value === 'string' && /^(?:fasakhansta_dashboard|fasakhansta_dashboard_stage_[a-f0-9]{16})$/.test(value);
const stageDatabase = value => typeof value === 'string' && /^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(value);
const DELTA_FIELDS = Object.freeze(['delta_stage', 'delta_replay', 'delta_source_identity', 'delta_source_binding']);
const need = value => { if (!value) throw Object.assign(Error('The shared prepared workspace could not be verified.'), { code: 'WORKSPACE_PREPARED_INVALID' }); };
const same = (a, b) => canonical(a) === canonical(b);
function workspaceBinding(manifest) {
  return { workspace_id: manifest.workspace_id, root_fingerprint: manifest.root_fingerprint,
    manifest_revision: manifest.revision, roster_sha256: manifest.roster_sha256, manifest_sha256: digest(manifest) };
}
function expectedBinding(credential, manifest) {
  need(credential.workspaceId === manifest.workspace_id && credential.enrollmentId === manifest.root_fingerprint && hash(credential.token));
  return { workspace_id: credential.workspaceId, root_fingerprint: manifest.root_fingerprint, revision: manifest.revision,
    roster_sha256: manifest.roster_sha256, manifest_sha256: digest(manifest),
    token_hash: crypto.createHash('sha256').update(credential.token).digest('hex') };
}
function branches(value) {
  return Array.isArray(value) && value.length <= 10000 && value.every(item => typeof item === 'string' && /^(?:f|gs):[1-9][0-9]{0,18}$/.test(item))
    && value.every((item, index) => index === 0 || Buffer.compare(Buffer.from(value[index - 1]), Buffer.from(item)) < 0);
}
/** Native framing checks complement the PHP MACs; no caller-supplied digest authenticates a stage. */
function deltaReceipt(value, memberManifest, candidate, credential) {
  need(object(value) && object(memberManifest) && object(candidate));
  if (!DELTA_FIELDS.some(key => Object.hasOwn(value, key))) return null;
  need(DELTA_FIELDS.every(key => Object.hasOwn(value, key)));
  const binding = value.delta_source_binding, identity = value.delta_source_identity, replay = value.delta_replay, stage = value.delta_stage;
  need(exact(binding, ['workspace_id', 'root_fingerprint', 'revision', 'roster_sha256', 'manifest_sha256', 'token_hash'])
    && uuid(binding.workspace_id) && binding.workspace_id === memberManifest.workspace_id && binding.root_fingerprint === memberManifest.root_fingerprint
    && positive(binding.revision) && positive(memberManifest.revision) && binding.revision <= memberManifest.revision
    && hash(memberManifest.roster_sha256)
    && ['root_fingerprint', 'roster_sha256', 'manifest_sha256', 'token_hash'].every(key => hash(binding[key])));
  if (binding.revision === memberManifest.revision)
    need(binding.roster_sha256 === memberManifest.roster_sha256 && binding.manifest_sha256 === digest(memberManifest));
  if (credential) need(binding.token_hash === expectedBinding(credential, memberManifest).token_hash);
  const targetWorkspace = workspaceBinding(memberManifest);
  need(exact(value.workspace, Object.keys(targetWorkspace)) && same(value.workspace, targetWorkspace));
  const sourceWorkspace = { workspace_id: binding.workspace_id, root_fingerprint: binding.root_fingerprint,
    manifest_revision: binding.revision, roster_sha256: binding.roster_sha256, manifest_sha256: binding.manifest_sha256 };
  need(exact(identity, ['snapshot_id', 'schema_hash', 'workspace']) && uuid(identity.snapshot_id) && hash(identity.schema_hash)
    && exact(identity.workspace, Object.keys(sourceWorkspace)) && same(identity.workspace, sourceWorkspace));
  need(exact(replay, ['format', 'workspace_id', 'root_fingerprint', 'source_database', 'sequence', 'rows', 'sha256'])
    && replay.format === 2 && replay.workspace_id === memberManifest.workspace_id && replay.root_fingerprint === memberManifest.root_fingerprint
    && database(replay.source_database) && replay.source_database !== candidate.database
    && count(replay.sequence) && count(replay.rows) && hash(replay.sha256));
  need(exact(stage, ['format', 'kind', 'stage_id', 'stage_database', 'source_database', 'capture_database', 'capture_id',
    'device_id', 'actor_id', 'snapshot_id', 'workspace', 'receipt_sha256'])
    && stage.format === 2 && stage.kind === 'desktop-workspace-delta-stage' && hash(stage.stage_id) && hash(stage.capture_id)
    && stageDatabase(candidate.database) && stage.stage_database === candidate.database
    && stage.source_database === replay.source_database && stageDatabase(stage.capture_database)
    && stage.capture_database !== stage.stage_database && stage.capture_database !== stage.source_database
    && stage.device_id === value.device_id && stage.device_id === memberManifest.workspace_id
    && positive(stage.actor_id) && stage.actor_id === value.actor_id && uuid(stage.snapshot_id) && stage.snapshot_id === value.snapshot_id
    && same(stage.workspace, value.workspace) && hash(stage.receipt_sha256));
  // receipt_sha256 was produced from PHP's exact JSON bytes before adding the
  // stage marker, php_profiles and verified flag. PHP verifies that commitment.
  return value;
}
function receipt(value, memberManifest, candidate) {
  need(exact(value, ['format', 'kind', 'snapshot_id', 'device_id', 'actor_id', 'schema_hash', 'tables', 'rows', 'coverage', 'source', 'branches', 'table_rows', 'media', 'workspace', 'verified'], ['php_profiles', ...DELTA_FIELDS])
    && value.format === 2 && value.kind === 'desktop-workspace-import-receipt' && value.verified === true
    && uuid(value.snapshot_id) && value.snapshot_id === candidate.snapshotId && value.device_id === memberManifest.workspace_id
    && positive(value.actor_id) && value.schema_hash === candidate.schemaHash && hash(value.schema_hash)
    && positive(value.tables) && count(value.rows) && object(value.table_rows) && Object.keys(value.table_rows).length === value.tables
    && Object.entries(value.table_rows).every(([table, rows]) => /^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(table) && count(rows))
    && Object.values(value.table_rows).reduce((total, rows) => total + rows, 0) === value.rows
    && source.same(value.source, candidate.sourceFingerprint) && branches(value.branches)
    && object(value.coverage) && value.coverage.media === true && typeof value.coverage.full_dashboard === 'boolean'
    && value.coverage.workspace_protocol === 2 && value.coverage.manifest_revision === memberManifest.revision
    && value.coverage.roster_sha256 === memberManifest.roster_sha256 && value.coverage.root_fingerprint === memberManifest.root_fingerprint
    && same(value.workspace, workspaceBinding(memberManifest)) && Array.isArray(value.media), 'RECEIPT_INVALID');
  // Reuse the original path/MIME/size/collision bounds; the receipt intentionally
  // lacks download tickets and may contain no other per-file fields.
  for (const item of value.media) need(exact(item, ['path', 'sha256', 'bytes', 'mime'], ['area']) && item.area !== 'public');
  media.manifest(value.media.map(item => ({ ...item, ticket: 'native-receipt-validation' })));
  deltaReceipt(value, memberManifest, candidate);
  return value;
}
function validate(value, checkedReceipt, memberManifest, credential, candidate) {
  const common = ['format', 'deviceId', 'actorId', 'token', 'serverOrigin', 'schemaHash', 'branches', 'generation', 'database', 'snapshotId', 'refreshId',
    'sourceRevision', 'sourceFingerprint', 'fullCoverage', 'mediaVerified', 'workspaceProtocol', 'workspace', 'importReceiptSha256'];
  need(exact(value, common, ['previewOnly', 'coverage', 'mediaManifestHash', 'coverageBinding'])
    && value.format === 2 && value.workspaceProtocol === 2 && value.deviceId === memberManifest.workspace_id
    && value.actorId === checkedReceipt.actor_id && positive(value.actorId) && value.token === credential.token && value.serverOrigin === credential.serverOrigin
    && value.schemaHash === candidate.schemaHash && value.database === candidate.database && value.generation === candidate.generation
    && value.snapshotId === candidate.snapshotId && uuid(value.refreshId) && value.sourceRevision === candidate.sourceRevision
    && source.same(value.sourceFingerprint, candidate.sourceFingerprint) && value.mediaVerified === true
    && same(value.branches, checkedReceipt.branches) && same(value.workspace, workspaceBinding(memberManifest))
    && value.importReceiptSha256 === digest(checkedReceipt));
  receipt(checkedReceipt, memberManifest, candidate);
  deltaReceipt(checkedReceipt, memberManifest, candidate, credential);
  if (value.fullCoverage === true) {
    need(checkedReceipt.coverage.full_dashboard === true && !['previewOnly', 'coverage', 'mediaManifestHash', 'coverageBinding'].some(key => Object.hasOwn(value, key)));
  } else {
    need(value.fullCoverage === false && value.previewOnly === true && checkedReceipt.coverage.full_dashboard === false
      && same(value.coverage, checkedReceipt.coverage) && value.mediaManifestHash === coverage.digest(checkedReceipt.media)
      && hash(value.coverageBinding) && value.coverageBinding === coverage.binding(value));
    coverage.routes(value.coverage.write_routes);
  }
  return structuredClone(value);
}
/** Construct only from an independently verified native stage result. */
function create({ credential, manifest, candidate, refreshId, sourceManifest, allowPreview = false }) {
  const checked = receipt(candidate.receipt, manifest, candidate);
  const snapshot = { coverage: checked.coverage, media: checked.media, device_id: checked.device_id, actor_id: checked.actor_id,
    schema_hash: checked.schema_hash, snapshot_id: checked.snapshot_id, branches: checked.branches, source: checked.source };
  const scope = coverage.preparedCoverage(snapshot, candidate, sourceManifest, allowPreview);
  const value = { format: 2, deviceId: credential.workspaceId, actorId: checked.actor_id, token: credential.token, serverOrigin: credential.serverOrigin,
    schemaHash: checked.schema_hash, branches: checked.branches, generation: candidate.generation, database: candidate.database,
    snapshotId: checked.snapshot_id, refreshId, sourceRevision: candidate.sourceRevision, sourceFingerprint: candidate.sourceFingerprint,
    ...scope, mediaVerified: candidate.mediaVerified, workspaceProtocol: 2, workspace: workspaceBinding(manifest), importReceiptSha256: digest(checked) };
  if (value.previewOnly) value.coverageBinding = coverage.binding(value);
  return validate(value, checked, manifest, credential, candidate);
}
module.exports = { workspaceBinding, expectedBinding, receipt, deltaReceipt, validate, create };
