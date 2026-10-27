'use strict';
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { valid: validSource, same: sameSource } = require('../../desktop-pos/src/dashboard-source.cjs');
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);

function verify(proof, expected) {
  assert.equal(proof?.format, 2); assert.equal(proof.kind, 'go-erp-shared-workspace-native');
  assert.equal(proof.sourceRevision, expected.sourceRevision); assert.equal(proof.electronVersion, expected.electronVersion);
  assert.deepEqual(proof.sourceHashes, expected.sourceHashes);
  for (const name of ['actualElectron', 'actualEncryptedMetadata', 'actualPhpMariaDB', 'productionFactory', 'syntheticPublication',
    'onePreparation', 'zeroOfflineHttp', 'samePhysicalDatabase', 'aPendingVisibleToB', 'bWritesKeepOriginalActor',
    'globalSequenceAThenB', 'actualProtectedView', 'fixedCliOpcodeDisabled', 'fixedCliNotAttached', 'persistentWebOpcodeEnabled',
    'freshWebOpcodeNamespaces', 'noSharedWebFileCache', 'cachedRememberedCloseReopen', 'explicitLogoutRequiresPassword',
    'noHeavyLoginWork', 'retiredLegacyPreserved', 'lostAcceptedReplyRetriedSameUuid', 'noChildrenAfterStop', 'completed'])
    assert.equal(proof[name], true, 'Native receipt is missing ' + name);
  for (const name of ['productionMain', 'productionProfile']) assert.equal(proof[name], false, 'Native scope differs: ' + name);
  assert.ok(uuid(proof.physicalWorkspaceId)); assert.match(proof.generation, /^[a-f0-9]{16}$/);
  assert.equal(proof.database, 'fasakhansta_dashboard_stage_' + proof.generation);
  assert.equal(proof.sourceSequence, 1); assert.ok(validSource(proof.sourceFingerprint));
  assert.equal(sameSource(proof.sourceFingerprint, expected.sourceFingerprint), true);
  assert.ok(Number.isSafeInteger(proof.certificateChecks) && proof.certificateChecks > 0);
  assert.deepEqual(proof.firstEverOfflineActors, [11, 1, 10]); assert.equal(proof.loginStageCalls, 0);
  assert.equal(proof.cachedLoginMilliseconds?.length, 3);
  assert.ok(proof.cachedLoginMilliseconds.every(value => Number.isSafeInteger(value) && value >= 0 && value < 45000));
  assert.equal(proof.setupTimer?.running, false); assert.ok(Number.isSafeInteger(proof.setupTimer.elapsed_ms) && proof.setupTimer.elapsed_ms > 0);
  assert.equal(proof.originalCommandIds?.length, 2); assert.ok(proof.originalCommandIds.every(uuid));
  const [a, b] = proof.originalCommandIds; assert.notEqual(a, b); assert.deepEqual(proof.commandAttempts, [a, a, b]);
  assert.ok(Array.isArray(proof.nativePids) && proof.nativePids.length > 0 && proof.nativePids.every(value => Number.isSafeInteger(value) && value > 0));
  assert.equal(new Set(proof.nativePids).size, proof.nativePids.length);
  assert.ok(Array.isArray(proof.webOpcodeLaunches) && proof.webOpcodeLaunches.length >= 4);
  for (const launch of proof.webOpcodeLaunches) {
    assert.deepEqual(Object.keys(launch).sort(), ['cacheId','enableCli','fileCache','fileCacheOnly','pid']);
    assert.ok(Number.isSafeInteger(launch.pid) && launch.pid > 0 && proof.nativePids.includes(launch.pid));
    assert.match(launch.cacheId, /^go-erp-web-[a-f0-9]{48}$/);
    assert.equal(launch.enableCli, '1'); assert.equal(launch.fileCache, ''); assert.equal(launch.fileCacheOnly, '0');
  }
  assert.equal(new Set(proof.webOpcodeLaunches.map(value => value.cacheId)).size, proof.webOpcodeLaunches.length);
  const server = proof.serverInspection;
  assert.equal(server?.format, 2); assert.equal(server.kind, 'go-erp-workspace-native-server-inspection');
  assert.equal(server.sourceRevision, expected.sourceRevision); assert.equal(sameSource(server.sourceFingerprint, expected.sourceFingerprint), true);
  assert.equal(server.workspaceCount, 1); assert.equal(server.custodianActorId, 10); assert.equal(server.workspaces?.length, 1);
  assert.equal(server.workspaces[0].workspace_id, proof.physicalWorkspaceId);
  assert.match(server.workspaces[0].root_fingerprint, /^[a-f0-9]{64}$/);
  assert.equal(server.commands?.length, 2);
  // Original Reconciliation::ingest acknowledges the server execution row in
  // its commit transaction. Native outbox acknowledgment is a separate step;
  // both original server rows must already be committed and acknowledged here.
  assert.deepEqual(server.commands.map(row => [row.command_id, row.actor_id, row.route_name, row.status]),
    [[a, 10, 'customers.save', 'acknowledged'], [b, 11, 'customers.save', 'acknowledged']]);
  assert.ok(server.commands.every(row => Number.isSafeInteger(row.sequence) && row.sequence > 0));
  assert.ok(server.commands[0].sequence < server.commands[1].sequence);
  assert.equal(server.customers?.length, 2);
  assert.deepEqual(server.customers.map(row => [row.phone_key, row.actor_id, row.branch]),
    [['01012345671', 10, 'f:100'], ['01012345672', 11, 'f:100']]);
  assert.equal(proof.requestCounts?.offlineRequests, 0);
  assert.ok(Number.isSafeInteger(proof.requestCounts.totalRequests) && proof.requestCounts.totalRequests > 0);
  assert.ok(Number.isSafeInteger(proof.requestCounts.publicationRequests) && proof.requestCounts.publicationRequests > 0);
  return true;
}

function sourceFiles(repository) {
  return [...fs.readdirSync(path.join(repository, 'desktop-pos/src')).filter(name => name.endsWith('.cjs') && (name.startsWith('workspace-')
    || ['main.cjs', 'local-runtime.cjs', 'dashboard.cjs', 'account-login.cjs', 'account-signout.cjs', 'shutdown.cjs', 'runtime-archive.cjs'].includes(name)))
    .map(name => 'desktop-pos/src/' + name), 'desktop-pos/test/workspace-runtime-native.cjs', 'desktop-pos/test/workspace-runtime-native-runner.cjs',
    'desktop-pos/test/local-runtime-import.test.cjs', 'desktop-pos/test/local-runtime-opcode.test.cjs',
    'tests/desktop_dashboard_runtime/workspace-native-server.php', 'tests/desktop_dashboard_runtime/workspace-native-router.php',
    'tests/desktop_dashboard_runtime/workspace-native-fixture.php', 'tests/desktop_dashboard_runtime/full-schema.php',
    'tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json'];
}

/** Resolve only the three source-bound PHP fixture files; hash rosters are not path components. */
function fixturePaths(repository) {
  const fixture = path.join(repository, 'tests/desktop_dashboard_runtime/workspace-native-server.php');
  const router = path.join(repository, 'tests/desktop_dashboard_runtime/workspace-native-router.php');
  const helper = path.join(repository, 'tests/desktop_dashboard_runtime/workspace-native-fixture.php');
  for (const file of [fixture, router, helper]) {
    const info = fs.lstatSync(file);
    assert.ok(info.isFile() && !info.isSymbolicLink() && info.nlink === 1 && info.size > 0 && info.size <= 1024 ** 2,
      'The source-bound PHP fixture must be a regular file.');
  }
  return { fixture, router };
}

if (require.main === module) {
  assert.equal(process.platform, 'win32');
  const [receipt, bundle] = process.argv.slice(2); assert.ok(receipt && bundle && process.env.GITHUB_SHA);
  assert.ok(fs.statSync(receipt).size <= 1024 ** 2);
  const repository = path.resolve(__dirname, '../..'), manifest = JSON.parse(fs.readFileSync(path.join(bundle, 'manifest.json'), 'utf8'));
  assert.equal(manifest.sourceRevision, process.env.GITHUB_SHA);
  const hashes = Object.fromEntries(sourceFiles(repository).map(name => [name, crypto.createHash('sha256').update(fs.readFileSync(path.join(repository, name))).digest('hex')]));
  const lock = JSON.parse(fs.readFileSync(path.join(repository, 'desktop-pos/package-lock.json'), 'utf8'));
  verify(JSON.parse(fs.readFileSync(receipt, 'utf8')), { sourceRevision: process.env.GITHUB_SHA, sourceHashes: hashes,
    electronVersion: lock.packages['node_modules/electron'].version, sourceFingerprint: manifest.sourceFingerprint });
  process.stdout.write('SHARED_WORKSPACE_NATIVE_VERIFIED ' + process.env.GITHUB_SHA + '\n');
}
module.exports = { verify, sourceFiles, fixturePaths };
