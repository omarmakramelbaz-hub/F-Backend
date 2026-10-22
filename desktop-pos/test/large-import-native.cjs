'use strict';
// Focused CI proof: real bundled Windows PHP, full Laravel kernel, private MariaDB.
// It reuses accepted native binaries; the only application change is the reviewed importer.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const LocalRuntime = require('../src/local-runtime.cjs');
const sourceCode = require('../src/dashboard-source.cjs');
const IMPORTER = 'app/Services/Dashboard/DesktopDashboardImport.php';
const FIXED_HASH = 'e50fdeb5f16fb9bb5b37527bed30dc8ab8a9127fa3d7f013de4a4f7063f119db';
const ORIGINAL_REVISION = 'a0178ead801e8d39185cd0830171804141767995';
const ORIGINAL_SOURCE = '5f09c4ecc61909e4fe4cbc313b0ea31349e676a70d47415d6047c25ad9fba98a';
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

async function execute(file, args, options, input = '', timeout = 300000) {
  const child = spawn(file, args, { ...options, shell: false, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] });
  let stdout = '', stderr = '', stdoutCarry = '', stderrCarry = '', memoryCount = 0, exceeded = false, timedOut = false;
  const memoryRows = [], observed = { outOfMemory: false, outOfMemory256: false, startupFailure: false, importDiagnostic: false };
  const observe = text => {
    observed.outOfMemory ||= /Allowed memory size .* exhausted/.test(text);
    observed.outOfMemory256 ||= /Allowed memory size of 268435456 bytes exhausted/.test(text);
    observed.startupFailure ||= /PHP Startup|auto_prepend_file|Failed opening required/.test(text);
    observed.importDiagnostic ||= text.includes('DESKTOP_IMPORT_DIAGNOSTIC ');
  };
  const retainMemory = line => { if (line.startsWith('GO_ERP_NATIVE_MEMORY ')) { ++memoryCount; if (memoryRows.length < 2) memoryRows.push(line.slice(0, 512)); } };
  child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
  child.stdout.on('data', bytes => { observe(stdoutCarry + bytes); stdoutCarry = (stdoutCarry + bytes).slice(-512); stdout += bytes; if (Buffer.byteLength(stdout) > 1024 * 1024) { exceeded = true; child.kill(); } });
  child.stderr.on('data', bytes => {
    stderr = (stderr + bytes).slice(-65536);
    observe(stderrCarry + bytes);
    const lines = (stderrCarry + bytes).split(/\r?\n/); stderrCarry = lines.pop().slice(-512);
    for (const line of lines) retainMemory(line);
  });
  const timer = setTimeout(() => { timedOut = true; child.kill(); }, timeout);
  child.stdin.on('error', () => {});
  if (input && typeof input.pipe === 'function') input.pipe(child.stdin); else child.stdin.end(input);
  try {
    const code = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); });
    assert.equal(exceeded, false, 'Native proof output stays bounded.');
    assert.equal(timedOut, false, 'Native proof child completes within deadline.');
    retainMemory(stderrCarry);
    return { code, stdout, stderr, memoryRows, memoryCount, observed };
  } finally { clearTimeout(timer); }
}
function probe(result, fixed) {
  const rows = result.memoryRows;
  if (result.memoryCount !== 1 || rows.length !== 1) throw Error('Native shutdown probe differs: ' + JSON.stringify({
    exitCode: result.code, markers: result.memoryCount, ...result.observed,
    stdoutBytes: Buffer.byteLength(result.stdout), stderrTailBytes: Buffer.byteLength(result.stderr)
  }));
  assert.equal(rows.length, 1, 'Actual PHP shutdown memory receipt occurs once.');
  const value = JSON.parse(rows[0].slice('GO_ERP_NATIVE_MEMORY '.length));
  console.log('GO_ERP_NATIVE_MEMORY_OBSERVED ' + JSON.stringify({ mode: fixed ? 'fixed' : 'original', ...value, exitCode: result.code, ...result.observed }));
  assert.equal(value.initial_limit, '256M'); assert.equal(value.limit, '256M');
  if (fixed) assert.equal(value.framework, '12.70.0');
  else assert.ok(value.framework === null || value.framework === '12.70.0');
  assert.ok(Number.isSafeInteger(value.peak_bytes) && value.peak_bytes > 0, 'Actual reserved allocator peak is recorded.');
  assert.ok(Number.isSafeInteger(value.used_peak_bytes) && value.used_peak_bytes > 0, 'Actual used allocator peak is recorded.');
  return value;
}
async function runCase(bundle, temporary, fixed) {
  const profile = await fs.mkdtemp(path.join(temporary, fixed ? 'fixed-' : 'original-'));
  const failures = [], key = crypto.randomBytes(32);
  const safeStorage = { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      return Buffer.concat([iv, cipher.update(value), cipher.final(), cipher.getAuthTag()]); },
    decryptString(value) { const decipher = crypto.createDecipheriv('aes-256-gcm', key, value.subarray(0, 12));
      decipher.setAuthTag(value.subarray(-16)); return Buffer.concat([decipher.update(value.subarray(12, -16)), decipher.final()]).toString(); } };
  const runtime = new LocalRuntime({ bundle, profile, safeStorage, downloadMedia: async () => { throw Error('No synthetic media expected.'); }, onFailure: error => failures.push(error) });
  try {
    const settings = await runtime.settings();
    settings.deviceId = '11111111-1111-4111-8111-111111111111';
    await runtime.metadata.write('credentials', settings);
    await runtime.start();
    assert.equal(runtime.manifest.runtimeFramework, '12.70.0');
    const config = path.join(profile, 'synthetic-fixture-input.json'), wire = path.join(profile, 'synthetic-snapshot.json');
    await fs.writeFile(config, JSON.stringify({ device: settings.deviceId, source: runtime.sourceFingerprint }));
    const generated = await execute(runtime.php, ['-n', path.resolve(__dirname, '../../tests/desktop_dashboard_runtime/large-import-fixture.php'), runtime.application, config, wire], { cwd: runtime.application }, '', 30000);
    assert.equal(generated.code, 0, generated.stderr);
    const scale = JSON.parse(generated.stdout); assert.equal(scale.rows, 34000); assert.equal(scale.tables, 119);
    const id = fixed ? '2222222222222222' : '1111111111111111';
    const value = { generation: id, database: 'fasakhansta_dashboard_stage_' + id };
    const env = runtime.environmentFor(value), storage = runtime.storageFor(value);
    for (const dir of ['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'bootstrap/cache', 'private']) await fs.mkdir(path.join(storage, dir), { recursive: true });
    const args = LocalRuntime.phpArguments(runtime), options = { env, cwd: runtime.application };
    const database = await execute(runtime.php, [...args, path.join(runtime.application, 'desktop/database.php'), 'stage'], options,
      JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }));
    assert.equal(database.code, 0, database.stderr);
    // A small shutdown-only reserve lets the negative OOM control report its
    // fixed memory counters. It is identical in both cases, outside app source.
    // Require the exact entry point from a fixed CLI wrapper so Windows startup
    // INI path parsing cannot skip the probe. The importer and full kernel remain
    // unchanged, and the same wrapper/reserve is used in both controls.
    const importProgram = `$GLOBALS['native_proof_initial_limit']=ini_get('memory_limit');$GLOBALS['native_proof_reserve']=str_repeat('r',65536);register_shutdown_function(static function(){unset($GLOBALS['native_proof_reserve']);fwrite(STDERR,'GO_ERP_NATIVE_MEMORY '.json_encode(['peak_bytes'=>memory_get_peak_usage(true),'used_peak_bytes'=>memory_get_peak_usage(false),'initial_limit'=>$GLOBALS['native_proof_initial_limit'],'limit'=>ini_get('memory_limit'),'framework'=>class_exists('Illuminate\\\\Foundation\\\\Application',false)?\\Illuminate\\Foundation\\Application::VERSION:null],JSON_THROW_ON_ERROR).PHP_EOL);});require $argv[1].'/desktop/import.php';`;
    const imported = await execute(runtime.php, [...args, '-r', importProgram, runtime.application], options, fsSync.createReadStream(wire));
    const memory = probe(imported, fixed);
    const bootstrap = `require $argv[1].'/desktop/bootstrap.php';`;
    const inspect = async code => {
      const result = await execute(runtime.php, [...args, '-r', bootstrap + code, runtime.application], options);
      assert.equal(result.code, 0, result.stderr); return JSON.parse(result.stdout);
    };
    if (!fixed) {
      assert.notEqual(imported.code, 0, 'Original full native importer must reproduce the large snapshot memory failure.');
      assert.equal(imported.observed.outOfMemory256, true, 'Original full native importer reaches actual PHP exhaustion at256MiB.');
      const state = await inspect(`echo json_encode(['tables'=>count(\\Illuminate\\Support\\Facades\\DB::select('SHOW TABLES'))]);`);
      assert.equal(state.tables, 0, 'Original memory failure occurs before first staging DDL.');
      console.log('PASS original bundled Windows importer reproduces the synthetic large snapshot OOM before DDL at256M');
      return { ...scale, memory, reproducedOutOfMemory: true, stoppedBeforeDdl: true };
    }
    assert.equal(imported.code, 0, imported.stderr);
    const receipt = JSON.parse(imported.stdout); assert.equal(receipt.rows, 34000); assert.equal(receipt.tables, 119);
    assert.equal(receipt.coverage.print_protocol, 1); assert.equal(receipt.coverage.full_dashboard, false);
    assert.ok(sourceCode.same(receipt.source, runtime.sourceFingerprint));
    const reopened = await execute(runtime.php, [...args, path.join(runtime.application, 'desktop/verify.php')], options, JSON.stringify(receipt));
    assert.equal(reopened.code, 0, reopened.stderr);
    const verified = JSON.parse(reopened.stdout); assert.equal(verified.verified, true); assert.equal(verified.rows, 34000); assert.equal(verified.tables, 119);
    assert.equal(verified.device_id, settings.deviceId); assert.deepEqual(verified.coverage, receipt.coverage);
    const state = await inspect(`$db=\\Illuminate\\Support\\Facades\\DB::connection();$row=$db->table('users')->where('id',1)->first();echo json_encode(['memory'=>ini_get('memory_limit'),'framework'=>app()->version(),'commands'=>$db->table('desktop_dashboard_commands')->count(),'state'=>$db->table('desktop_dashboard_local_state')->value('state'),'devices'=>$db->table('desktop_dashboard_devices')->where('id',getenv('DESKTOP_DASHBOARD_DEVICE_ID'))->count(),'print_scopes'=>$db->table('fasakhansta_dashboard_effects.print_ledger_scopes')->where('device_id',getenv('DESKTOP_DASHBOARD_DEVICE_ID'))->count(),'double'=>is_float($row->c00)&&$row->c00===1.0,'bigint'=>$row->c01===9007199254740993,'source_match'=>app(\\App\\Services\\Dashboard\\DesktopDashboardSource::class)->matches(json_decode($db->table('desktop_dashboard_source_manifest')->value('source'),true))]);`);
    assert.deepEqual(state, { memory: '256M', framework: '12.70.0', commands: 0, state: 'ready', devices: 1, print_scopes: 1, double: true, bigint: true, source_match: true });
    assert.equal(failures.length, 0);
    console.log('PASS reviewed importer completes34000rows119tables at256M through the full Windows kernel, actual InnoDB writes, late enrollment, private print binding and independent verify');
    return { ...scale, memory, completed: true, independentVerification: true, printProtocol: 1, exactNumericTransport: true, state };
  } finally { await runtime.stop(); await fs.rm(profile, { recursive: true, force: true }); }
}
async function main() {
  assert.equal(process.platform, 'win32');
  const [bundle, receiptPath] = process.argv.slice(2); assert.ok(bundle && receiptPath);
  const application = path.join(bundle, 'application'), manifestPath = path.join(bundle, 'manifest.json');
  const manifest = JSON.parse(await fs.readFile(manifestPath, 'utf8'));
  assert.equal(manifest.sourceRevision, ORIGINAL_REVISION); assert.equal(manifest.sourceFingerprint.sha256, ORIGINAL_SOURCE);
  assert.ok(sourceCode.same(await sourceCode.fingerprint(application), manifest.sourceFingerprint));
  const originalImporter = await fs.readFile(path.join(application, IMPORTER));
  assert.equal(sha256(originalImporter), manifest.sourceHashes[IMPORTER]);
  const fixedImporter = await fs.readFile(path.resolve(__dirname, '../../', IMPORTER)); assert.equal(sha256(fixedImporter), FIXED_HASH);
  assert.notDeepEqual(fixedImporter, originalImporter);
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-large-import-'));
  try {
    const original = await runCase(bundle, temporary, false);
    await fs.writeFile(path.join(application, IMPORTER), fixedImporter);
    const sourceFingerprint = await sourceCode.fingerprint(application);
    assert.notEqual(sourceFingerprint.sha256, ORIGINAL_SOURCE); assert.equal(sourceFingerprint.files, manifest.sourceFingerprint.files);
    const patched = { ...manifest, sourceRevision: process.env.GITHUB_SHA, sourceFingerprint, sourceHashes: { ...manifest.sourceHashes, [IMPORTER]: FIXED_HASH } };
    assert.match(patched.sourceRevision, /^[a-f0-9]{40}$/);
    await fs.writeFile(manifestPath, JSON.stringify(patched));
    const fixed = await runCase(bundle, temporary, true);
    const result = { format: 1, kind: 'go-erp-large-native-import', platform: process.platform, fixtureRevision: process.env.GITHUB_SHA,
      acceptedRuntimeRevision: ORIGINAL_REVISION, importerSha256: FIXED_HASH, source: sourceFingerprint,
      actualBundledPhp: true, fullDesktopKernel: true, privateMariaDb: true, productionData: false, physicalPrinter: false,
      concurrentDevicesTested: 1, original, fixed, completed: true };
    await fs.mkdir(path.dirname(receiptPath), { recursive: true }); await fs.writeFile(receiptPath, JSON.stringify(result, null, 2) + '\n');
    console.log('GO_ERP_LARGE_NATIVE_IMPORT_COMPLETE ' + JSON.stringify(result));
  } finally { await fs.rm(temporary, { recursive: true, force: true }); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
