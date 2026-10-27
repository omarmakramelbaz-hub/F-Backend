'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { verify } = require('../../.github/scripts/verify-workspace-runtime-native.cjs');

function fixture() {
  const a = '11111111-1111-4111-8111-111111111111', b = '22222222-2222-4222-8222-222222222222';
  const workspace = '33333333-3333-4333-8333-333333333333';
  const source = { format: 1, files: 42, sha256: 'a'.repeat(64), framework: '8.83.29' };
  const expected = { sourceRevision: 'b'.repeat(40), electronVersion: '44.6.0', sourceHashes: { 'desktop-pos/src/main.cjs': 'c'.repeat(64) }, sourceFingerprint: source };
  const proof = { format: 2, kind: 'go-erp-shared-workspace-native', sourceRevision: expected.sourceRevision, electronVersion: expected.electronVersion,
    sourceHashes: expected.sourceHashes, productionMain: false, productionProfile: false, physicalWorkspaceId: workspace,
    generation: 'd'.repeat(16), database: 'fasakhansta_dashboard_stage_' + 'd'.repeat(16), sourceSequence: 1,
    sourceFingerprint: source, certificateChecks: 5, firstEverOfflineActors: [11, 1, 10], loginStageCalls: 0,
    cachedLoginMilliseconds: [200, 100, 100], setupTimer: { elapsed_ms: 1000, running: false },
    originalCommandIds: [a, b], commandAttempts: [a, a, b], nativePids: [100, 101],
    requestCounts: { offlineRequests: 0, totalRequests: 20, publicationRequests: 1 },
    serverInspection: { format: 2, kind: 'go-erp-workspace-native-server-inspection', sourceRevision: expected.sourceRevision,
      sourceFingerprint: source, workspaceCount: 1, custodianActorId: 10,
      workspaces: [{ workspace_id: workspace, root_fingerprint: 'e'.repeat(64) }],
      commands: [{ command_id: a, actor_id: 10, route_name: 'customers.save', status: 'pending', sequence: 1 },
        { command_id: b, actor_id: 11, route_name: 'customers.save', status: 'pending', sequence: 2 }],
      customers: [{ phone_key: '01012345671', actor_id: 10, branch: 'f:100' }, { phone_key: '01012345672', actor_id: 11, branch: 'f:100' }] } };
  for (const name of ['actualElectron', 'actualEncryptedMetadata', 'actualPhpMariaDB', 'productionFactory', 'syntheticPublication',
    'onePreparation', 'zeroOfflineHttp', 'samePhysicalDatabase', 'aPendingVisibleToB', 'bWritesKeepOriginalActor',
    'globalSequenceAThenB', 'actualProtectedView', 'cachedRememberedCloseReopen', 'explicitLogoutRequiresPassword',
    'noHeavyLoginWork', 'retiredLegacyPreserved', 'lostAcceptedReplyRetriedSameUuid', 'noChildrenAfterStop', 'completed']) proof[name] = true;
  return { proof, expected };
}

test('the native receipt binds source, actor order, original commands and its declared factory-only scope', () => {
  const { proof, expected } = fixture(); assert.equal(verify(proof, expected), true);
});

test('different source, omitted evidence or an inflated production-main claim cannot satisfy release acceptance', () => {
  const changes = [p => { p.sourceRevision = 'f'.repeat(40); }, p => { p.sourceHashes = {}; }, p => { p.completed = 'true'; },
    p => { delete p.explicitLogoutRequiresPassword; }, p => { p.productionMain = true; }, p => { p.syntheticPublication = false; },
    p => { p.sourceFingerprint = { ...p.sourceFingerprint, sha256: 'f'.repeat(64) }; }];
  for (const change of changes) { const { proof, expected } = fixture(); change(proof); assert.throws(() => verify(proof, expected)); }
});

test('wrong actors, duplicate server execution, reordered queue or hidden offline requests are rejected', () => {
  const changes = [p => { p.firstEverOfflineActors = [10, 11, 1]; }, p => { p.serverInspection.commands[0].actor_id = 11; },
    p => { p.serverInspection.commands.push({ ...p.serverInspection.commands[0] }); },
    p => { p.serverInspection.commands[1].sequence = 1; }, p => { p.commandAttempts.reverse(); },
    p => { p.serverInspection.customers[0].branch = 'f:101'; }, p => { p.requestCounts.offlineRequests = 1; },
    p => { p.serverInspection.workspaceCount = 2; }, p => { p.noChildrenAfterStop = false; }];
  for (const change of changes) { const { proof, expected } = fixture(); change(proof); assert.throws(() => verify(proof, expected)); }
});

test('re-preparation during cached login and an unfinished or fictitious setup timer are rejected', () => {
  const changes = [p => { p.loginStageCalls = 1; }, p => { p.cachedLoginMilliseconds[0] = 45000; },
    p => { p.setupTimer.running = true; }, p => { p.setupTimer.elapsed_ms = 0; }, p => { p.sourceSequence = 0; }];
  for (const change of changes) { const { proof, expected } = fixture(); change(proof); assert.throws(() => verify(proof, expected)); }
});
