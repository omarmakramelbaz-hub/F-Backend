'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sources = ['desktop-pos/src/dashboard-generation.cjs', 'desktop-pos/src/dashboard-media.cjs',
  'desktop-pos/src/dashboard-source.cjs', 'desktop-pos/package.json', 'tests/desktop_native_safety/safe-storage-worker.cjs',
  'tests/desktop_native_safety/safe-storage-native.cjs'];

async function runPhase(executable, phase, profile, input, output) {
  const env = { ...process.env };
  for (const key of ['ELECTRON_RUN_AS_NODE', 'NODE_OPTIONS', 'NODE_PATH']) delete env[key];
  const child = spawn(executable, [path.join(__dirname, 'safe-storage-worker.cjs'), phase, profile, input, output,
    '--user-data-dir=' + path.join(profile, 'electron-user')], { env, windowsHide: true, shell: false, stdio: ['ignore', 'pipe', 'pipe'] });
  let stdout = '', stderr = '', timedOut = false;
  child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
  child.stdout.on('data', bytes => { stdout += bytes; }); child.stderr.on('data', bytes => { stderr += bytes; });
  const timer = setTimeout(() => { timedOut = true; child.kill(); }, 45000);
  let code;
  try { code = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); }); }
  finally { clearTimeout(timer); }
  assert.equal(timedOut, false, 'Electron safeStorage phase timed out.');
  assert.equal(code, 0, 'Electron safeStorage failed: ' + phase + '\n' + stderr.slice(-2000));
  assert.ok(stdout.includes('WINDOWS_SAFE_STORAGE_PHASE_COMPLETE ' + phase), 'Mandatory phase marker is missing.');
  const receipt = JSON.parse(await fs.readFile(output, 'utf8'));
  assert.equal(receipt.phase, phase); assert.equal(receipt.platform, 'win32'); assert.equal(receipt.electron, '44.6.0');
  assert.equal(receipt.realSafeStorage, true); assert.equal(receipt.metadataRoundTrip, true);
  assert.equal(receipt.connectionCipherRoundTrip, true); assert.equal(receipt.ciphertextContainsPlaintext, false);
  return receipt;
}

async function main() {
  assert.equal(process.platform, 'win32', 'This acceptance requires real Windows.');
  const [executable, artifact] = process.argv.slice(2);
  assert.ok(executable && artifact, 'Electron executable and output directory are required.');
  await fs.mkdir(artifact, { recursive: true });
  const revision = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  const sourceFiles = {};
  for (const file of sources) {
    const bytes = await fs.readFile(path.join(root, file));
    assert.deepEqual(bytes, execFileSync('git', ['show', 'HEAD:' + file], { cwd: root }), 'Source differs from frozen Git bytes: ' + file);
    sourceFiles[file] = hash(bytes);
  }
  assert.equal(JSON.parse(await fs.readFile(path.join(root, 'desktop-pos/package.json'), 'utf8')).devDependencies.electron,
    '44.6.0', 'The tested native Electron pin differs from the application dependency.');
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'fasakhansta-dpapi-'));
  const input = path.join(profile, 'synthetic-input.json');
  const token = crypto.randomBytes(32).toString('hex'), deviceId = crypto.randomUUID();
  const journal = Buffer.from('CI synthetic retained journal; no financial operation is claimed.\n');
  await fs.writeFile(path.join(profile, 'retained-journal.fixture'), journal);
  await fs.writeFile(input, JSON.stringify({ credentials: { deviceId, actorId: 30, token, note: 'بيانات اختبار فقط' },
    prepared: { deviceId, actorId: 30, token, database: 'synthetic-only', generation: crypto.randomBytes(8).toString('hex') },
    refresh: { deviceId, token, previous: { database: 'synthetic-only' }, id: crypto.randomUUID() } }));
  const phases = [];
  try {
    for (const phase of ['write', 'restart', 'corruption']) {
      const receipt = await runPhase(executable, phase, profile, input, path.join(artifact, phase + '.json'));
      assert.equal(receipt.journalSha256, hash(journal));
      if (phases.length) {
        assert.deepEqual(receipt.files, phases[0].files, 'Restart or corruption changed retained encrypted metadata.');
        assert.equal(receipt.connectionCipherSha256, phases[0].connectionCipherSha256, 'Retained connection cipher changed.');
      }
      phases.push(receipt);
    }
    assert.equal(phases[2].corruptionRejected, true);
    for (const file of sources) assert.equal(hash(await fs.readFile(path.join(root, file))), sourceFiles[file], 'Source changed during native acceptance.');
    const receipt = { format: 1, sourceRevision: revision, sourceFiles, platform: 'win32', electron: '44.6.0',
      actualWindowsSafeStorage: true, independentProcesses: 3, restartRoundTrip: true, corruptedCiphertextRejected: true,
      retainedJournalBytes: true, phases, acceptedRelease: false, fullDashboard: false,
      limits: ['Same Windows logon only; no cross-user claim.', 'Source module/native API proof; installed application pairing is not exercised.',
        'Synthetic JSON round-trips do not exercise prepared() validation or refresh recovery.',
        'Synthetic journal bytes; real financial retention is outside this diagnostic and requires separate installer evidence.'] };
    await fs.writeFile(path.join(artifact, 'safe-storage-native-receipt.json'), JSON.stringify(receipt, null, 2) + '\n');
    console.log('WINDOWS_SAFE_STORAGE_NATIVE_COMPLETE');
  } finally { await fs.rm(profile, { recursive: true, force: true }); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
