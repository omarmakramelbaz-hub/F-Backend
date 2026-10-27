'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { verify, sourceFiles, fixturePaths } = require('../../.github/scripts/verify-workspace-runtime-native.cjs');

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
    originalCommandIds: [a, b], commandAttempts: [a, a, b], nativePids: [100, 101, 102, 103, 104],
    webOpcodeLaunches: [1,2,3,4].map(value => ({ pid: 100 + value, cacheId: 'go-erp-web-' + String(value).padStart(48,'0'),
      enableCli: '1', fileCache: '', fileCacheOnly: '0' })),
    requestCounts: { offlineRequests: 0, totalRequests: 20, publicationRequests: 1 },
    serverInspection: { format: 2, kind: 'go-erp-workspace-native-server-inspection', sourceRevision: expected.sourceRevision,
      sourceFingerprint: source, workspaceCount: 1, custodianActorId: 10,
      workspaces: [{ workspace_id: workspace, root_fingerprint: 'e'.repeat(64) }],
      commands: [{ command_id: a, actor_id: 10, route_name: 'customers.save', status: 'pending', sequence: 1 },
        { command_id: b, actor_id: 11, route_name: 'customers.save', status: 'pending', sequence: 2 }],
      customers: [{ phone_key: '01012345671', actor_id: 10, branch: 'f:100' }, { phone_key: '01012345672', actor_id: 11, branch: 'f:100' }] } };
  for (const name of ['actualElectron', 'actualEncryptedMetadata', 'actualPhpMariaDB', 'productionFactory', 'syntheticPublication',
    'onePreparation', 'zeroOfflineHttp', 'samePhysicalDatabase', 'aPendingVisibleToB', 'bWritesKeepOriginalActor',
    'globalSequenceAThenB', 'actualProtectedView', 'fixedCliOpcodeDisabled', 'fixedCliNotAttached', 'persistentWebOpcodeEnabled',
    'freshWebOpcodeNamespaces', 'noSharedWebFileCache', 'cachedRememberedCloseReopen', 'explicitLogoutRequiresPassword',
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


test('missing or false cold-CLI and persistent-web opcode evidence cannot satisfy native release acceptance', () => {
  for (const name of ['fixedCliOpcodeDisabled','fixedCliNotAttached','persistentWebOpcodeEnabled','freshWebOpcodeNamespaces','noSharedWebFileCache']) {
    for (const value of [undefined,false,'true']) {
      const { proof, expected } = fixture(); if (value === undefined) delete proof[name]; else proof[name] = value;
      assert.throws(() => verify(proof, expected));
    }
  }
});

test('reused web namespaces, shared file blobs and missing actual launch evidence cannot satisfy native acceptance', () => {
  const changes = [p => { delete p.webOpcodeLaunches; }, p => { p.webOpcodeLaunches = p.webOpcodeLaunches.slice(0,3); },
    p => { p.webOpcodeLaunches[1].cacheId = p.webOpcodeLaunches[0].cacheId; },
    p => { p.webOpcodeLaunches[0].cacheId = 'a'.repeat(64); }, p => { p.webOpcodeLaunches[0].pid = 999; },
    p => { p.webOpcodeLaunches[0].enableCli = '0'; }, p => { p.webOpcodeLaunches[0].fileCache = 'C:/old-cache'; },
    p => { p.webOpcodeLaunches[0].fileCacheOnly = '1'; }, p => { p.webOpcodeLaunches[0].unreviewed = true; }];
  for (const change of changes) { const { proof, expected } = fixture(); change(proof); assert.throws(() => verify(proof, expected)); }
});


test('runner preflight resolves the actual PHP entry and shares the exact verifier source roster', () => {
  const fs = require('node:fs'), path = require('node:path'), repository = path.resolve(__dirname,'../..');
  const files = fixturePaths(repository), roster = sourceFiles(repository);
  assert.equal(files.fixture, path.join(repository,'tests/desktop_dashboard_runtime/workspace-native-server.php'));
  assert.equal(files.router, path.join(repository,'tests/desktop_dashboard_runtime/workspace-native-router.php'));
  for (const name of ['desktop-pos/test/local-runtime-import.test.cjs','desktop-pos/test/local-runtime-opcode.test.cjs',
    'tests/desktop_dashboard_runtime/workspace-native-server.php']) assert.equal(roster.filter(value => value === name).length, 1);
  assert.equal(new Set(roster).size, roster.length);
  assert.equal(roster.every(value => fs.lstatSync(path.join(repository,value)).isFile()), true);
  const runner = fs.readFileSync(path.join(__dirname,'workspace-runtime-native-runner.cjs'),'utf8');
  assert.match(runner, /const \{ sourceFiles, fixturePaths \} = require/);
  assert.match(runner, /fixturePaths\(repository\)/); assert.match(runner, /sourceFiles\(repository\)\.map/);
});

test('preflight refuses a directory in place of the PHP entry before any process is started', t => {
  const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
  const repository = fs.mkdtempSync(path.join(fs.realpathSync.native(os.tmpdir()),'workspace-fixture-preflight-'));
  t.after(() => fs.rmSync(repository,{recursive:true,force:true}));
  const directory = path.join(repository,'tests/desktop_dashboard_runtime'); fs.mkdirSync(directory,{recursive:true});
  fs.mkdirSync(path.join(directory,'workspace-native-server.php'));
  for (const name of ['workspace-native-router.php','workspace-native-fixture.php']) fs.writeFileSync(path.join(directory,name),'<?php // disposable fixture');
  assert.throws(() => fixturePaths(repository), /regular file/);
});
