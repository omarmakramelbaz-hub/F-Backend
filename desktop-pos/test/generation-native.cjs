'use strict';
// CI-only synthetic dataset. Runs real Windows PHP/MariaDB supervisor processes, not Electron mocks.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const LocalRuntime = require('../src/local-runtime.cjs');
const { DashboardGeneration } = require('../src/dashboard-generation.cjs');

async function main() {
  if (process.platform !== 'win32') throw Error('This native supervisor fixture requires Windows.');
  const [bundle, sourceFile] = process.argv.slice(2);
  const snapshot = JSON.parse(await fs.readFile(sourceFile, 'utf8'));
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-generation-'));
  const key = crypto.randomBytes(32), failures = [];
  // Test adapter for Electron safeStorage. This is not shipped or a DPAPI integration claim.
  const safeStorage = { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      return Buffer.concat([iv, cipher.update(value), cipher.final(), cipher.getAuthTag()]); },
    decryptString(value) { const cipher = crypto.createDecipheriv('aes-256-gcm', key, value.subarray(0, 12));
      cipher.setAuthTag(value.subarray(-16)); return Buffer.concat([cipher.update(value.subarray(12, -16)), cipher.final()]).toString(); } };
  const create = () => new LocalRuntime({ bundle, profile, safeStorage, onFailure: error => failures.push(error) });
  let runtime = create();
  const php = async (code, input = {}, env = runtime.environment) => {
    const child = spawn(runtime.php, ['-c', path.join(bundle, 'php/php.ini'), '-r', code], { cwd: runtime.application,
      env: { ...env, DESKTOP_TEST_APPLICATION: runtime.application }, windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', error = ''; child.stdout.setEncoding('utf8'); child.stdout.on('data', bytes => { output += bytes; });
    child.stderr.setEncoding('utf8'); child.stderr.on('data', bytes => { error += bytes; });
    child.stdin.end(JSON.stringify(input));
    const exit = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); });
    assert.equal(exit, 0, error); return JSON.parse(output);
  };
  const bootstrap = "$input=json_decode(stream_get_contents(STDIN),true);$app=require getenv('DESKTOP_TEST_APPLICATION').'/desktop/bootstrap.php';";
  try {
    const settings = await runtime.settings(); settings.deviceId = snapshot.device_id;
    await runtime.metadata.write('credentials', settings);
    await runtime.start();
    const id = crypto.randomBytes(8).toString('hex'), candidate = await runtime.stage(snapshot, id);
    const initial = { format: 2, deviceId: snapshot.device_id, actorId: snapshot.actor_id, schemaHash: snapshot.schema_hash,
      token: 'a'.repeat(64), serverOrigin: 'https://fixture.test', fullCoverage: true, mediaVerified: true,
      generation: id, database: candidate.database, snapshotId: snapshot.snapshot_id, refreshId: crypto.randomUUID(), sourceRevision: candidate.sourceRevision };
    await runtime.activate(initial, () => runtime.metadata.write('prepared', initial));
    const loginSession = path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions/supervisor-fixture-session');
    await fs.writeFile(loginSession, 'synthetic preserved session');
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    const command = await php(bootstrap + "$values=['branch'=>'f:100','idempotency_key'=>$input['id'],'name'=>'عميل مزامنة','phone'=>'01012345678','address'=>'المنصورة'];"
      + "$actor=\\App\\Models\\User::withoutGlobalScopes()->findOrFail($input['actor']);$journal=app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class);"
      + "$result=$journal->execute($input['device'],$input['id'],$input['actor'],'customers.save',['values'=>$values],[],fn()=>app(\\App\\Services\\Dashboard\\BranchCustomers::class)->save($values,$actor));"
      + "echo json_encode(['result'=>$result,'rows'=>\\Illuminate\\Support\\Facades\\DB::table('branch_customers')->get()->map(fn($row)=>(array)$row)->all()]);",
      { id: crypto.randomUUID(), device: snapshot.device_id, actor: snapshot.actor_id });
    const pending = await runtime.control({ action: 'pending' }); assert.equal(pending.counts.pending, 1);
    let downloads = 0;
    const coordinator = () => new DashboardGeneration({ runtime, metadata: runtime.metadata,
      download: async () => { downloads++; return snapshot; } });
    await assert.rejects(coordinator().run()); assert.equal(downloads, 0);
    await runtime.control({ action: 'failed', command_id: pending.commands[0].command_id, message: 'synthetic conflict', conflict: true });
    await assert.rejects(coordinator().run()); assert.equal(downloads, 0);
    await runtime.control({ action: 'acknowledge', command_id: pending.commands[0].command_id,
      receipt: { device_id: snapshot.device_id, command_id: pending.commands[0].command_id, committed: true, result: command.result } });
    // The synthetic server fixture now reflects the confirmed customer, as a coherent bootstrap must.
    snapshot.snapshot_id = crypto.randomUUID(); snapshot.tables.branch_customers.rows = command.rows;
    snapshot.tables.branch_customers.sha256 = crypto.createHash('sha256').update(JSON.stringify(command.rows)).digest('hex');
    const next = await coordinator().run(); assert.notEqual(next.database, initial.database);
    assert.equal(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions/supervisor-fixture-session'), 'utf8'), 'synthetic preserved session');
    const archived = await php(bootstrap + "echo json_encode(['commands'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_commands')->where('status','acknowledged')->count(),'state'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->value('state')]);",
      {}, { ...runtime.environment, DB_DATABASE: initial.database });
    assert.deepEqual(archived, { commands: 1, state: 'held' });
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    const replay = await php(bootstrap + "try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',[],[],fn()=>throw new RuntimeException('Archived work ran again.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",
      { device: snapshot.device_id, id: pending.commands[0].command_id, actor: snapshot.actor_id });
    assert.equal(replay.status, 409);
    const intent = { format: 1, id: crypto.randomUUID(), token: 'd'.repeat(64), generation: 'e'.repeat(16), previous: next };
    await runtime.metadata.write('refresh', intent);
    await runtime.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token });
    await runtime.stop(); runtime = create(); await runtime.start();
    await coordinator().recover(); assert.equal(await runtime.metadata.read('refresh'), null);
    const status = await runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    assert.equal(status.held, false); assert.equal((await runtime.connection()).database, next.database);
    assert.equal(failures.length, 0);
    console.log('PASS real Windows supervisor stages, verifies and activates coherent data, preserves the old journal and sessions, and recovers a held fence after restart');
  } finally { await runtime.stop(); await fs.rm(profile, { recursive: true, force: true }); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
