'use strict';
// CI-only synthetic dataset. Runs real Windows PHP/MariaDB supervisor processes, not Electron mocks.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const http = require('node:http');
const LocalRuntime = require('../src/local-runtime.cjs');
const { DashboardGeneration } = require('../src/dashboard-generation.cjs');
const { DashboardPreparation } = require('../src/dashboard-preparation.cjs');
const DashboardMode = require('../src/dashboard-mode.cjs');
const DashboardRemoteState = require('../src/dashboard-remote-state.cjs');

async function main() {
  if (process.platform !== 'win32') throw Error('This native supervisor fixture requires Windows.');
  const [bundle, sourceFile] = process.argv.slice(2);
  const snapshot = JSON.parse(await fs.readFile(sourceFile, 'utf8'));
  const imageBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=', 'base64');
  const imagePath = 'products/42/رنجة.png', imageTicket = 'synthetic-media-capability';
  snapshot.media = [{ path: imagePath, sha256: crypto.createHash('sha256').update(imageBytes).digest('hex'),
    bytes: imageBytes.length, mime: 'image/png', ticket: imageTicket }];
  const pdfBytes = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');
  const pdfPath = 'branch-expenses/فاتورة.pdf', pdfTicket = 'synthetic-private-capability';
  snapshot.media.push({ area: 'private', path: pdfPath, sha256: crypto.createHash('sha256').update(pdfBytes).digest('hex'),
    bytes: pdfBytes.length, mime: 'application/pdf', ticket: pdfTicket });
  let imageRequests = 0;
  const mediaServer = http.createServer((request, response) => {
    const ticket = new URL(request.url, 'http://fixture').searchParams.get('ticket');
    if (request.headers.authorization !== 'Bearer synthetic-device' || ![imageTicket, pdfTicket].includes(ticket)) {
      response.writeHead(403).end(); return;
    }
    const bytes = ticket === pdfTicket ? pdfBytes : imageBytes;
    imageRequests++; response.writeHead(200, { 'Content-Type': ticket === pdfTicket ? 'application/pdf' : 'image/png', 'Content-Length': bytes.length }); response.end(bytes);
  });
  await new Promise(resolve => mediaServer.listen(0, '127.0.0.1', resolve));
  const mediaOrigin = 'http://127.0.0.1:' + mediaServer.address().port;
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-generation-'));
  const key = crypto.randomBytes(32), failures = [];
  // Test adapter for Electron safeStorage. This is not shipped or a DPAPI integration claim.
  const safeStorage = { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
      return Buffer.concat([iv, cipher.update(value), cipher.final(), cipher.getAuthTag()]); },
    decryptString(value) { const cipher = crypto.createDecipheriv('aes-256-gcm', key, value.subarray(0, 12));
      cipher.setAuthTag(value.subarray(-16)); return Buffer.concat([cipher.update(value.subarray(12, -16)), cipher.final()]).toString(); } };
  const create = (installed = bundle) => new LocalRuntime({ bundle: installed, profile, safeStorage,
    downloadMedia: ticket => fetch(mediaOrigin + '/image?ticket=' + encodeURIComponent(ticket), { headers: { Authorization: 'Bearer synthetic-device' }, redirect: 'error' }),
    onFailure: error => failures.push(error) });
  let runtime = create();
  const php = async (code, input = {}, env = runtime.environment) => {
    const child = spawn(runtime.php, ['-c', runtime.phpIni, '-r', code], { cwd: runtime.application,
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
    const preparation = new DashboardPreparation({ runtime,
      enroll: async () => ({ protocol: 1, device_id: snapshot.device_id, actor_id: snapshot.actor_id, token: 'a'.repeat(64), branches: snapshot.branches }),
      download: async () => snapshot });
    await preparation.prepare('https://fixture.test', 'synthetic-signed-in-CSRF');
    assert.deepEqual(await php("echo json_encode(['upload'=>ini_get('upload_max_filesize'),'post'=>ini_get('post_max_size'),'memory'=>ini_get('memory_limit')]);"),
      { upload: '5M', post: '12M', memory: '256M' });
    const initial = await runtime.connection();
    const localImage = await fetch(runtime.origin + '/storage/products/42/' + encodeURIComponent('رنجة.png'), { headers: { 'X-Fasakhansta-Desktop': runtime.token } });
    assert.equal(localImage.status, 200); assert.deepEqual(Buffer.from(await localImage.arrayBuffer()), imageBytes);
    const privateFile = await php(bootstrap + "echo json_encode(['bytes'=>base64_encode(\\Illuminate\\Support\\Facades\\Storage::disk('local')->get($input['path']))]);", { path: pdfPath });
    assert.deepEqual(Buffer.from(privateFile.bytes, 'base64'), pdfBytes);
    const publicPdf = await fetch(runtime.origin + '/storage/' + encodeURI(pdfPath), { headers: { 'X-Fasakhansta-Desktop': runtime.token } });
    assert.equal(publicPdf.status, 404);
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
    let next = await coordinator().run(); assert.notEqual(next.database, initial.database);
    assert.equal(imageRequests, 4);
    assert.deepEqual(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app/public', imagePath)), imageBytes);
    assert.deepEqual(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app', pdfPath)), pdfBytes);
    assert.equal(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions/supervisor-fixture-session'), 'utf8'), 'synthetic preserved session');
    const archived = await php(bootstrap + "echo json_encode(['commands'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_commands')->where('status','acknowledged')->count(),'state'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->value('state')]);",
      {}, { ...runtime.environment, DB_DATABASE: initial.database });
    assert.deepEqual(archived, { commands: 1, state: 'held' });
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    const replay = await php(bootstrap + "try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',[],[],fn()=>throw new RuntimeException('Archived work ran again.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",
      { device: snapshot.device_id, id: pending.commands[0].command_id, actor: snapshot.actor_id });
    assert.equal(replay.status, 409);
    // Synthetic server-session response; fences, processes, DBs and restart recovery are real.
    let local = true; const destinations = [];
    const view = { current: () => ({ local }), switchTo: async (target, work = async () => {}) => {
      await work(); destinations.push(target.origin); local = Boolean(target.localToken);
    } };
    const remoteState = new DashboardRemoteState(runtime.metadata);
    const mode = new DashboardMode({ runtime, view, generations: coordinator(), remoteState,
      probe: async () => ({ actor_id: snapshot.actor_id, device_id: snapshot.device_id }) });
    snapshot.snapshot_id = crypto.randomUUID(); await mode.refresh(); next = await runtime.connection();
    assert.equal(local, false); assert.ok(await runtime.metadata.read('return'));
    await assert.rejects(mode.local({ method: 'POST' }));
    const heldWrite = await php(bootstrap + "try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',[],[],fn()=>throw new RuntimeException('Inactive local write ran.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",
      { device: snapshot.device_id, id: crypto.randomUUID(), actor: snapshot.actor_id });
    assert.equal(heldWrite.status, 409);
    await remoteState.begin('native-http-operation');
    await assert.rejects(mode.local({ method: 'GET' }));
    await assert.rejects(new DashboardRemoteState(runtime.metadata).assertClean());
    await remoteState.complete('native-http-operation');
    await assert.rejects(mode.local({ method: 'GET' }));
    snapshot.snapshot_id = crypto.randomUUID(); await mode.refresh(); next = await runtime.connection();
    await remoteState.assertClean();
    await mode.local({ method: 'GET' }); assert.equal(local, true); assert.equal(await runtime.metadata.read('return'), null);
    assert.deepEqual(destinations, ['https://fixture.test', runtime.origin]);
    const intent = { format: 1, id: crypto.randomUUID(), token: 'd'.repeat(64), generation: 'e'.repeat(16), previous: next };
    await runtime.metadata.write('refresh', intent);
    await runtime.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token });
    await runtime.stop();
    const upgraded = path.join(profile, 'synthetic-new-install');await fs.mkdir(upgraded);
    const installedManifest = JSON.parse(await fs.readFile(path.join(bundle, 'manifest.json'), 'utf8'));
    await fs.writeFile(path.join(upgraded, 'manifest.json'), JSON.stringify({ ...installedManifest, sourceRevision: 'f'.repeat(40) }));
    runtime = create(upgraded); await runtime.start();
    assert.equal(runtime.manifest.sourceRevision, next.sourceRevision);
    assert.notEqual(runtime.bundle, upgraded);
    await coordinator().recover(); assert.equal(await runtime.metadata.read('refresh'), null);
    const status = await runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    assert.equal(status.held, false); assert.equal((await runtime.connection()).database, next.database);
    assert.equal(failures.length, 0);
    const activeImage = path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app/public', imagePath);
    await runtime.stop(); await fs.unlink(activeImage); runtime = create();
    await assert.rejects(runtime.start());
    assert.equal((await runtime.connection()).database, next.database);
    await fs.writeFile(activeImage, imageBytes); runtime = create(); await runtime.start();
    const activePdf = path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app', pdfPath);
    await runtime.stop(); await fs.unlink(activePdf); runtime = create();
    await assert.rejects(runtime.start()); assert.equal((await runtime.connection()).database, next.database);
    await fs.writeFile(activePdf, pdfBytes); runtime = create(); await runtime.start();
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    console.log('PASS real Windows supervisor prepares account data, Arabic images and private expense PDFs, fences server return, resumes local work, preserves journals and sessions, and recovers a held fence after restart');
  } finally { await runtime.stop(); await new Promise(resolve => mediaServer.close(resolve)); await fs.rm(profile, { recursive: true, force: true }); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
