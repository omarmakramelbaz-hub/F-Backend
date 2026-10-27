'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), path = require('node:path');
const { WorkspaceSourceAuthority, groupUpdateTransport } = require('../src/workspace-source.cjs');
const { DashboardUpdater } = require('../src/dashboard-updater.cjs');
const { GenerationStore } = require('../src/dashboard-generation.cjs');
const { fixture, hash } = require('./helpers/workspace-fixture.cjs');

test('group preparation uses the unchanged release/source grammar and durable sequence high-water with only a native protocol header', async t => {
  const f = await fixture(t), c = f.value.candidate, headers = [], manifest = { format: 1, poll_seconds: 60, state: 'ready', server_source: c.sourceFingerprint,
    release: { version: '0.4.4-preview.60', sequence: 100, source_revision: c.sourceRevision, source: c.sourceFingerprint,
      installer: { bytes: 42, sha256: hash('installer'), path: '/api/desktop-dashboard/update/installer/' + hash('installer') } } };
  const updater = new DashboardUpdater({ profile: path.join(f.root, 'updates'), metadata: new GenerationStore(f.root, f.safeStorage),
    credential: async () => f.value.credential, currentVersion: '0.4.4-preview.59', currentRevision: c.sourceRevision,
    canApply: async () => false, apply: async () => {}, fetch: groupUpdateTransport(async (url, options) => {
      assert.equal(url, f.value.credential.serverOrigin + '/api/desktop-dashboard/update'); headers.push(options.headers);
      return new Response(JSON.stringify(manifest), { headers: { 'content-type': 'application/json' } });
    }) });
  const authority = new WorkspaceSourceAuthority({ updater }), input = { context: { manifest: { sourceRevision: c.sourceRevision }, sourceFingerprint: c.sourceFingerprint },
    credential: f.value.credential, snapshot: { source: c.sourceFingerprint } };
  assert.equal((await authority.authorize(input)).sequence, 100);
  assert.equal(headers[0]['X-Fasakhansta-Update-Protocol'], '2'); assert.equal(headers[0].Authorization, 'Bearer ' + f.value.credential.token);
  manifest.release.sequence = 99;
  await assert.rejects(authority.authorize(input), /أقدم|متغير/);
  manifest.release.sequence = 101; manifest.release.source_revision = 'c'.repeat(40);
  await assert.rejects(authority.authorize(input), { code: 'WORKSPACE_SOURCE_REJECTED' });
});
