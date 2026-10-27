'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { record: validate } = require('../src/workspace-profile.cjs');
const prepared = require('../src/workspace-prepared.cjs');
const { record, hash } = require('./helpers/workspace-fixture.cjs');

test('the sole root pointer contains complete prepared coverage/media/refresh identity and the verified import receipt', () => {
  const value = record(), checked = validate(value, value.manifest.workspace_id);
  assert.equal(checked.candidate.prepared.actorId, 101);
  assert.equal(checked.candidate.prepared.workspaceProtocol, 2);
  assert.equal(checked.candidate.prepared.importReceiptSha256, require('../src/workspace-profile.cjs').digest(checked.candidate.receipt));
  assert.deepEqual(prepared.expectedBinding(value.credential, value.manifest), { workspace_id: value.manifest.workspace_id,
    root_fingerprint: value.manifest.root_fingerprint, revision: value.manifest.revision, roster_sha256: value.manifest.roster_sha256,
    manifest_sha256: require('../src/workspace-profile.cjs').digest(value.manifest), token_hash: hash(value.credential.token) });
});

for (const [name, mutate] of [
  ['missing complete descriptor', value => { delete value.candidate.prepared; }],
  ['missing verified import receipt', value => { delete value.candidate.receipt; }],
  ['unverified media', value => { value.candidate.prepared.mediaVerified = false; }],
  ['lost refresh capability', value => { delete value.candidate.prepared.refreshId; }],
  ['custody rewritten as B', value => { value.candidate.prepared.actorId = 202; }],
  ['unverified PHP receipt', value => { value.candidate.receipt.verified = false; }],
  ['receipt count tampered', value => { value.candidate.receipt.table_rows.users = 3; }],
  ['manifest rebound after import', value => { value.candidate.receipt.workspace.manifest_revision = 2; }],
  ['renderer-style boolean proof', value => { value.candidate.receipt = { verified: true }; }],
  ['foreign credential', value => { value.candidate.prepared.token = hash('foreign'); }],
  ['unknown per-file authority', value => { value.candidate.receipt.media = [{ path: 'x.png', sha256: hash('x'), bytes: 1, mime: 'image/png', ticket: 'forbidden' }]; }]
]) test('root activation rejects ' + name, () => {
  const value = record(); mutate(value);
  assert.throws(() => validate(value, value.manifest.workspace_id), error => /^WORKSPACE_(?:CANDIDATE_INVALID|PREPARED_INVALID)$/.test(error.code));
});
