'use strict';
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path'), crypto = require('node:crypto');
const { WorkspaceRegistry, digest } = require('../../src/workspace-profile.cjs');
const prepared = require('../../src/workspace-prepared.cjs');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function encryption() {
  const key = crypto.randomBytes(32);
  return { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      return Buffer.concat([iv, cipher.update(value, 'utf8'), cipher.final(), cipher.getAuthTag()]); },
    decryptString(value) { const cipher = crypto.createDecipheriv('aes-256-gcm', key, value.subarray(0, 12)); cipher.setAuthTag(value.subarray(-16));
      return Buffer.concat([cipher.update(value.subarray(12, -16)), cipher.final()]).toString('utf8'); } };
}
function record() {
  const manifest = structuredClone(require('../fixtures/workspace-members-v2.json').manifest), workspaceId = manifest.workspace_id;
  const credential = { workspaceId, serverOrigin: 'https://dashboard.example.test', token: hash('synthetic-group-bearer'), enrollmentId: manifest.root_fingerprint };
  const candidate = { workspaceId, database: 'fasakhansta_dashboard_stage_' + 'a'.repeat(16), generation: 'a'.repeat(16),
    snapshotId: '22222222-2222-4222-8222-222222222222', schemaHash: hash('schema'), sourceRevision: 'b'.repeat(40),
    sourceFingerprint: { format: 1, files: 42, sha256: hash('source'), framework: '8.83.29' }, sourceSequence: 99,
    manifestRevision: manifest.revision, rosterSha256: manifest.roster_sha256 };
  candidate.receipt = { format: 2, kind: 'desktop-workspace-import-receipt', snapshot_id: candidate.snapshotId, device_id: workspaceId,
    actor_id: 101, schema_hash: candidate.schemaHash, tables: 1, rows: 2, table_rows: { users: 2 }, branches: ['f:100', 'f:101'], media: [], verified: true,
    source: candidate.sourceFingerprint, coverage: { full_dashboard: true, media: true, workspace_protocol: 2,
      manifest_revision: manifest.revision, roster_sha256: manifest.roster_sha256, root_fingerprint: manifest.root_fingerprint }, workspace: prepared.workspaceBinding(manifest) };
  candidate.prepared = prepared.create({ credential, manifest, candidate: { ...candidate, mediaVerified: true }, refreshId: '33333333-3333-4333-8333-333333333333' });
  return { manifest, credential, candidate };
}
async function fixture(t, { activate = true } = {}) {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'workspace-integration-')), safeStorage = encryption(), value = record();
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const registry = new WorkspaceRegistry({ root, workspaceId: value.manifest.workspace_id, safeStorage, verifyAuthority: async () => true });
  if (activate) { const ticket = await registry.stage(value); await registry.activate(ticket, { verifyCandidate: async () => true }); }
  return { root, safeStorage, registry, value };
}
module.exports = { fixture, record, hash, digest, encryption };
