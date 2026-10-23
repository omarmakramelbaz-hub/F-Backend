'use strict';
// CI-only synthetic dataset. Runs real Windows PHP/MariaDB supervisor processes, not Electron mocks.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const http = require('node:http');
// The installer job supplies the installed ASAR code directory and runs this
// fixture with the installed Electron executable, so both layers are exercised.
const codeRoot = process.argv[4] || path.join(__dirname, '../src');
const LocalRuntime = require(path.join(codeRoot, 'local-runtime.cjs'));
const { DashboardGeneration } = require(path.join(codeRoot, 'dashboard-generation.cjs'));
const { DashboardPreparation } = require(path.join(codeRoot, 'dashboard-preparation.cjs'));
const DashboardMode = require(path.join(codeRoot, 'dashboard-mode.cjs'));
const DashboardRemoteState = require(path.join(codeRoot, 'dashboard-remote-state.cjs'));
const sourceCode = require(path.join(codeRoot, 'dashboard-source.cjs'));
const coverageCode = require(path.join(codeRoot, 'dashboard-coverage.cjs'));
const readSnapshot = require(path.join(codeRoot, 'dashboard-download.cjs'));

async function importMemoryProbe(bundle, snapshot) {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-import-memory-'));
  const application = path.join(root, 'application'), phpIni = path.join(root, 'php/php.ini');
  const observations = path.join(root, 'stage-limits.jsonl');
  const allocationBytes = 272 * 1024 * 1024;
  const fixture = { snapshot_id: snapshot.snapshot_id, device_id: snapshot.device_id, actor_id: snapshot.actor_id,
    schema_hash: snapshot.schema_hash, branches: snapshot.branches, coverage: snapshot.coverage, source: snapshot.source, media: [] };
  const receipt = { ...fixture, verified: true };
  const context = { bundle: root, application, phpIni, php: path.join(bundle, 'php/php.exe'),
    manifest: { sourceRevision: 'a'.repeat(40) }, sourceFingerprint: snapshot.source };
  const limitRecord = "$record=['step'=>basename(__FILE__,'.php'),'memory'=>ini_get('memory_limit')];"
    + "file_put_contents(getenv('DESKTOP_IMPORT_MEMORY_LOG'),json_encode($record).PHP_EOL,FILE_APPEND);";
  try {
    await fs.mkdir(path.join(application, 'desktop'), { recursive: true });
    await fs.mkdir(path.join(root, 'php/conf.d'), { recursive: true });
    const ini = 'memory_limit=256M\ndisplay_errors=stderr\nlog_errors=0\n';
    await fs.writeFile(phpIni, ini);
    await fs.writeFile(path.join(application, 'desktop/receipt.json'), JSON.stringify(receipt));
    await fs.writeFile(path.join(application, 'desktop/database.php'), '<?php stream_get_contents(STDIN);' + limitRecord);
    await fs.writeFile(path.join(application, 'desktop/import.php'), '<?php stream_get_contents(STDIN);' + limitRecord
      + '$allocation=str_repeat("x",' + allocationBytes + ');'
      + '$receipt=json_decode(file_get_contents(__DIR__."/receipt.json"),true,512,JSON_THROW_ON_ERROR);'
      + '$receipt["allocation_bytes"]=strlen($allocation);echo json_encode($receipt,JSON_THROW_ON_ERROR);');
    await fs.writeFile(path.join(application, 'desktop/verify.php'), '<?php $input=stream_get_contents(STDIN);' + limitRecord + 'echo $input;');
    const baselineLog = path.join(root, 'baseline-limits.jsonl');
    const baseline = spawn(context.php, [...LocalRuntime.phpArguments(context), path.join(application, 'desktop/import.php')],
      { cwd: application, env: { ...process.env, PHP_INI_SCAN_DIR: path.join(root, 'php/conf.d'), DESKTOP_IMPORT_MEMORY_LOG: baselineLog },
        windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', error = '', timedOut = false, overflow = false;
    baseline.stdout.setEncoding('utf8'); baseline.stderr.setEncoding('utf8');
    const collect = (kind, part) => {
      if (kind === 'output') output += part; else error += part;
      if (output.length + error.length > 65536) { overflow = true; baseline.kill(); }
    };
    baseline.stdout.on('data', part => collect('output', part)); baseline.stderr.on('data', part => collect('error', part));
    baseline.stdin.on('error', () => {}); baseline.stdin.end(readSnapshot.input(fixture));
    const deadline = setTimeout(() => { timedOut = true; baseline.kill(); }, 30000);
    let exit;
    try { exit = await new Promise((resolve, reject) => { baseline.once('error', reject); baseline.once('close', resolve); }); }
    finally { clearTimeout(deadline); if (baseline.exitCode === null && baseline.signalCode === null) baseline.kill(); }
    assert.equal(timedOut, false, 'The bounded native memory probe must finish.');
    assert.equal(overflow, false, 'The native memory failure must have bounded output.');
    assert.notEqual(exit, 0, 'The same allocation must exceed the old 256 MiB import limit.');
    assert.match(error + output, /Allowed memory size of [0-9]+ bytes exhausted/);
    assert.deepEqual(JSON.parse((await fs.readFile(baselineLog, 'utf8')).trim()), { step: 'import', memory: '256M' });
    const runtime = Object.create(LocalRuntime.prototype);
    Object.assign(runtime, { stopping: false, environment: { ...process.env, DESKTOP_IMPORT_MEMORY_LOG: observations },
      profile: path.join(root, 'profile'), stagedContexts: new Map(), candidate: async () => context,
      settings: async () => ({ deviceId: fixture.device_id, rootPassword: 'synthetic-only', appPassword: 'synthetic-only' }) });
    const staged = await runtime.stage(fixture, '0123456789abcdef');
    assert.equal(staged.receipt.allocation_bytes, allocationBytes);
    assert.equal(staged.mediaVerified, true);
    const limits = (await fs.readFile(observations, 'utf8')).trim().split(/\r?\n/).map(line => JSON.parse(line));
    assert.deepEqual(limits, [{ step: 'database', memory: '256M' }, { step: 'import', memory: '-1' }, { step: 'verify', memory: '256M' }]);
    assert.equal(await fs.readFile(phpIni, 'utf8'), ini, 'The one-shot import override must not rewrite the shared PHP configuration.');
    console.log('PASS actual Windows LocalRuntime.stage imports a 272 MiB allocation that fails at 256 MiB; database-stage and verification remain at 256 MiB');
  } finally { await fs.rm(root, { recursive: true, force: true }); }
}

async function main() {
  if (process.platform !== 'win32') throw Error('This native supervisor fixture requires Windows.');
  const [bundle, sourceFile] = process.argv.slice(2);
  // Keep the server's canonical bytes throughout the fixture. Rebuilding its
  // table rows in JavaScript would lose the numeric types this test protects.
  let snapshotWire = await fs.readFile(sourceFile, 'utf8');
  const downloadSnapshot = (wire = snapshotWire) => readSnapshot(new Response(wire, { headers: { 'content-type': 'application/json' } }));
  let snapshot = await downloadSnapshot();
  assert.equal(snapshot.coverage.full_dashboard, false, 'The original scoped bootstrap must not be promoted to full dashboard coverage.');
  await importMemoryProbe(bundle, snapshot);
  const rewriteProgram = String.raw`
use App\Services\Dashboard\DesktopDashboardBootstrap as Bootstrap;
$raw=stream_get_contents(STDIN,32*1024*1024+1);
if(strlen($raw)>32*1024*1024)throw new RuntimeException('Synthetic snapshot rewrite input exceeds its bound.');
$input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);unset($raw);
require $argv[1].'/app/Services/Dashboard/DesktopDashboardBootstrap.php';
$snapshot=json_decode($input['wire'],true,512,JSON_THROW_ON_ERROR);$patch=$input['patch'];unset($input);
switch($patch['kind']){
case 'media':$snapshot['media']=$patch['files'];break;
case 'id':$snapshot['snapshot_id']=$patch['id'];break;
case 'coverage':array_shift($snapshot['coverage']['write_routes']);break;
case 'source':$snapshot['source']['sha256']=$patch['sha256'];break;
case 'crm':
    $snapshot['snapshot_id']=$patch['id'];
    $tables=json_decode($patch['tables_wire'],true,512,JSON_THROW_ON_ERROR);
    foreach($tables as $table=>$rows){
        if(!in_array($table,['branch_customers','crm_customers','crm_customer_sources','branch_operation_commands'],true)||!isset($snapshot['tables'][$table]))throw new RuntimeException('Unexpected synthetic CRM table.');
        $snapshot['tables'][$table]['rows']=$rows;
        $snapshot['tables'][$table]['sha256']=hash('sha256',Bootstrap::json($rows));
    }
    break;
case 'upgrade':
    $snapshot['snapshot_id']=$patch['id'];$snapshot['source']=$patch['source'];
    $table=&$snapshot['tables']['branch_customers'];
    $prefix='CREATE TABLE '.chr(96).'branch_customers'.chr(96).' (';
    if(!str_starts_with($table['ddl'],$prefix))throw new RuntimeException('Synthetic upgrade DDL did not match.');
    $table['ddl']=$prefix.chr(10).'  '.chr(96).'desktop_upgrade_fixture'.chr(96).' varchar(40) DEFAULT NULL,'.substr($table['ddl'],strlen($prefix));
    foreach($table['rows'] as &$row)$row['desktop_upgrade_fixture']=null;unset($row);
    $table['sha256']=hash('sha256',Bootstrap::json($table['rows']));unset($table);
    $schema=[];foreach($snapshot['tables'] as $name=>$part)$schema[$name]=hash('sha256',$part['ddl']);ksort($schema);
    $snapshot['schema_hash']=hash('sha256',Bootstrap::json($schema));
    break;
default:throw new RuntimeException('Unknown synthetic snapshot rewrite.');
}
foreach($snapshot['tables'] as $part)if(!hash_equals($part['sha256'],hash('sha256',Bootstrap::json($part['rows']))))throw new RuntimeException('Synthetic rewrite changed an advertised table hash.');
$wire=Bootstrap::json($snapshot);
if(strlen($wire)>16*1024*1024)throw new RuntimeException('Synthetic snapshot rewrite output exceeds its bound.');
echo $wire;
`;
  const rewriteWire = async (patch, wire = snapshotWire) => {
    const input = JSON.stringify({ wire, patch });
    assert.ok(Buffer.byteLength(input) <= 32 * 1024 * 1024, 'Synthetic rewrite input remains bounded.');
    const child = spawn(path.join(bundle, 'php/php.exe'), ['-n', '-d', 'memory_limit=256M', '-r', rewriteProgram, path.join(bundle, 'application')],
      { cwd: path.join(bundle, 'application'), windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', error = '', bytes = 0, overflow = false, timedOut = false;
    child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
    child.stdout.on('data', part => { bytes += Buffer.byteLength(part); if (bytes > 16 * 1024 * 1024) { overflow = true; child.kill(); } else output += part; });
    child.stderr.on('data', part => { if (error.length < 65536) error += part.slice(0, 65536 - error.length); });
    const timeout = setTimeout(() => { timedOut = true; child.kill(); }, 30000);
    try {
      child.stdin.on('error', () => {}); child.stdin.end(input);
      const exit = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); });
      assert.equal(timedOut, false, 'Synthetic rewrite completes within its deadline.');
      assert.equal(overflow, false, 'Synthetic rewrite output remains bounded.');
      assert.equal(exit, 0, error);
      return output;
    } finally { clearTimeout(timeout); }
  };
  const updateSnapshot = async patch => { snapshotWire = await rewriteWire(patch); snapshot = await downloadSnapshot(); };
  const imageBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=', 'base64');
  const imagePath = 'products/42/رنجة.png', imageTicket = 'synthetic-media-capability';
  const pdfBytes = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');
  const pdfPath = 'branch-expenses/فاتورة.pdf', pdfTicket = 'synthetic-private-capability';
  await updateSnapshot({ kind: 'media', files: [
    { path: imagePath, sha256: crypto.createHash('sha256').update(imageBytes).digest('hex'), bytes: imageBytes.length, mime: 'image/png', ticket: imageTicket },
    { area: 'private', path: pdfPath, sha256: crypto.createHash('sha256').update(pdfBytes).digest('hex'), bytes: pdfBytes.length, mime: 'application/pdf', ticket: pdfTicket }
  ] });
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
  // Resolve Windows 8.3 aliases on this own fresh fixture root before the
  // unmodified media guard checks its descendants for redirected paths.
  const profile = await fs.realpath(await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-native-generation-')));
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
    const child = spawn(runtime.php, [...LocalRuntime.phpArguments(runtime), '-r', code], { cwd: runtime.application,
      env: { ...env, DESKTOP_TEST_APPLICATION: runtime.application }, windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let output = '', error = ''; child.stdout.setEncoding('utf8'); child.stdout.on('data', bytes => { output += bytes; });
    child.stderr.setEncoding('utf8'); child.stderr.on('data', bytes => { error += bytes; });
    child.stdin.end(JSON.stringify(input));
    const exit = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); });
    assert.equal(exit, 0, error); return JSON.parse(output);
  };
  const bootstrap = "$input=json_decode(stream_get_contents(STDIN),true);$app=require getenv('DESKTOP_TEST_APPLICATION').'/desktop/bootstrap.php';";
  const numericProbe = () => php(bootstrap + "$row=(array)\\Illuminate\\Support\\Facades\\DB::table('resturants')->where('id',100)->first();"
    + "echo json_encode(['integralFloat'=>is_float($row['avg_rate'])&&$row['avg_rate']===0.0,'exactInteger'=>$row['desktop_transport_bigint']===9007199254740993,'integerText'=>(string)$row['desktop_transport_bigint']]);");
  try {
    // A tiny malformed input must reach JSON validation under the installed memory
    // limit, rather than allocating the complete 256 MiB permitted input ceiling.
    const inputProbe = spawn(path.join(bundle, 'php/php.exe'), [...LocalRuntime.phpArguments({ application: path.join(bundle,'application'), phpIni: path.join(bundle,'php/php.ini') }), '-d', 'memory_limit=256M',
      path.join(bundle, 'application/desktop/import.php')], { cwd: path.join(bundle,'application'), windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let probeError = ''; inputProbe.stderr.setEncoding('utf8'); inputProbe.stderr.on('data', bytes => { probeError += bytes; });
    inputProbe.stdin.on('error', () => {}); inputProbe.stdin.end('{"synthetic":');
    const probeExit = await new Promise((resolve, reject) => { inputProbe.once('error', reject); inputProbe.once('close', resolve); });
    assert.equal(probeExit, 1);
    const report = probeError.split(/\r?\n/).find(line => line.startsWith('DESKTOP_IMPORT_DIAGNOSTIC '));
    assert.ok(report, probeError);
    const diagnostic = JSON.parse(report.slice('DESKTOP_IMPORT_DIAGNOSTIC '.length));
    assert.equal(diagnostic.phase, 'json'); assert.equal(diagnostic.type, 'JsonException');
    assert.ok(!probeError.includes('synthetic'));
    console.log('PASS Windows snapshot input reaches redacted JSON validation within the installed 256 MiB memory limit');
    const settings = await runtime.settings(); settings.deviceId = snapshot.device_id;
    await runtime.metadata.write('credentials', settings);
    const preparationOptions = { runtime,
      enroll: async () => ({ protocol: 1, device_id: snapshot.device_id, actor_id: snapshot.actor_id, token: 'a'.repeat(64), branches: snapshot.branches }),
      download: async () => downloadSnapshot() };
    await assert.rejects(new DashboardPreparation(preparationOptions).prepare('https://fixture.test', 'synthetic-signed-in-CSRF'));
    assert.equal(runtime.children.length, 0); assert.equal(await runtime.isPrepared(), false);
    await assert.rejects(new DashboardPreparation({ ...preparationOptions, allowPreview: true,
      download: async () => downloadSnapshot(await rewriteWire({ kind: 'coverage' }))
    }).prepare('https://fixture.test', 'synthetic-signed-in-CSRF'));
    assert.equal(runtime.children.length, 0); assert.equal(await runtime.isPrepared(), false);
    const preparation = new DashboardPreparation({ ...preparationOptions, allowPreview: true });
    await preparation.prepare('https://fixture.test', 'synthetic-signed-in-CSRF');
    assert.deepEqual(await numericProbe(), { integralFloat: true, exactInteger: true, integerText: '9007199254740993' });
    console.log('PASS real Windows initial preparation imports the PHP integral DOUBLE and exact 64-bit integer through the original snapshot download bytes');
    assert.match(runtime.environment.JWT_SECRET,/^[a-f0-9]{64}$/);
    assert.notEqual(runtime.environment.JWT_SECRET,runtime.environment.APP_KEY);
    assert.deepEqual(await php(bootstrap + "echo json_encode(['guard'=>get_class(auth('api')),'anonymous'=>auth('api')->user()===null,'privateSecret'=>hash_equals(getenv('JWT_SECRET'),(string)config('jwt.secret'))]);"),
      {guard:'Tymon\\JWTAuth\\JWTGuard',anonymous:true,privateSecret:true});
    console.log('PASS the installed original API guard resolves with the private native JWT environment and no fabricated API login');
    // PHP resolves Windows 8.3 aliases; compare the same actual retained files.
    const extensionDirectory = (await fs.realpath(path.join(runtime.bundle,'php/ext'))).replaceAll('\\','/');
    const certificateFile = (await fs.realpath(path.join(runtime.bundle,'php/ssl/cacert.pem'))).replaceAll('\\','/');
    assert.deepEqual(await php("echo json_encode(['upload'=>ini_get('upload_max_filesize'),'post'=>ini_get('post_max_size'),'memory'=>ini_get('memory_limit'),'jit'=>ini_get('opcache.jit'),'jitBuffer'=>ini_get('opcache.jit_buffer_size'),'extensions'=>str_replace(chr(92),'/',realpath(ini_get('extension_dir'))),'curlCA'=>str_replace(chr(92),'/',realpath(ini_get('curl.cainfo'))),'opensslCA'=>str_replace(chr(92),'/',realpath(ini_get('openssl.cafile')))]);"),
      { upload: '5M', post: '12M', memory: '256M', jit: '0', jitBuffer: '0',
        extensions: extensionDirectory, curlCA: certificateFile, opensslCA: certificateFile });
    const initial = await runtime.connection();
    assert.equal(initial.fullCoverage, false); assert.equal(initial.previewOnly, true);
    assert.deepEqual(initial.coverage, snapshot.coverage);
    assert.equal(await runtime.isPrepared(), true);
    const originalScope = await php(bootstrap + "echo json_encode(json_decode(\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->value('coverage'),true));");
    assert.deepEqual(originalScope, snapshot.coverage);
    console.log('PASS real Windows preparation accepts the original partial bootstrap only in the explicit preview runtime and preserves its exact incomplete coverage');
    assert.ok(sourceCode.same(snapshot.source, initial.sourceFingerprint));
    assert.ok(sourceCode.same(snapshot.source, runtime.manifest.sourceFingerprint));
    const localImage = await fetch(runtime.origin + '/storage/products/42/' + encodeURIComponent('رنجة.png'), { headers: { 'X-Fasakhansta-Desktop': runtime.token } });
    assert.equal(localImage.status, 200); assert.deepEqual(Buffer.from(await localImage.arrayBuffer()), imageBytes);
    const privateFile = await php(bootstrap + "echo json_encode(['bytes'=>base64_encode(\\Illuminate\\Support\\Facades\\Storage::disk('local')->get($input['path']))]);", { path: pdfPath });
    assert.deepEqual(Buffer.from(privateFile.bytes, 'base64'), pdfBytes);
    const publicPdf = await fetch(runtime.origin + '/storage/' + encodeURI(pdfPath), { headers: { 'X-Fasakhansta-Desktop': runtime.token } });
    assert.equal(publicPdf.status, 404);
    const loginSession = path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions/supervisor-fixture-session');
    await fs.writeFile(loginSession, 'synthetic preserved session');
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    const captureTablesPhp = "$tables=\\Illuminate\\Support\\Facades\\DB::transaction(function(){$rows=[];foreach(['branch_customers','crm_customers','crm_customer_sources','branch_operation_commands'] as $table)$rows[$table]=\\Illuminate\\Support\\Facades\\DB::table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();return $rows;});";
    const command = await php(bootstrap + "$values=['branch'=>'f:100','idempotency_key'=>$input['id'],'name'=>'عميل مزامنة','phone'=>'01012345678','address'=>'المنصورة'];"
      + "$actor=\\App\\Models\\User::withoutGlobalScopes()->findOrFail($input['actor']);$journal=app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class);"
      + "$result=$journal->execute($input['device'],$input['id'],$input['actor'],'customers.save',['values'=>$values],[],fn()=>app(\\App\\Services\\Dashboard\\BranchCustomers::class)->save($values,$actor));"
      + captureTablesPhp + "echo json_encode(['result'=>$result,'values'=>$values,'tables'=>$tables,'tables_wire'=>\\App\\Services\\Dashboard\\DesktopDashboardBootstrap::json($tables)]);",
      { id: crypto.randomUUID(), device: snapshot.device_id, actor: snapshot.actor_id });
    const pending = await runtime.control({ action: 'pending' }); assert.equal(pending.counts.pending, 1);
    let downloads = 0;
    const coordinator = () => new DashboardGeneration({ runtime, metadata: runtime.metadata, allowPreview: true,
      download: async () => { downloads++; return downloadSnapshot(); } });
    await assert.rejects(coordinator().run()); assert.equal(downloads, 0);
    await runtime.control({ action: 'failed', command_id: pending.commands[0].command_id, message: 'synthetic conflict', conflict: true });
    await assert.rejects(coordinator().run()); assert.equal(downloads, 0);
    await runtime.control({ action: 'acknowledge', command_id: pending.commands[0].command_id,
      receipt: { device_id: snapshot.device_id, command_id: pending.commands[0].command_id, committed: true, result: command.result } });
    // The synthetic server fixture now reflects the confirmed customer, as a coherent bootstrap must.
    assert.deepEqual(Object.keys(command.tables).sort(), ['branch_customers','branch_operation_commands','crm_customer_sources','crm_customers']);
    const capturedCrm = command.tables.crm_customers.find(row => row.phone === '+201012345678');
    assert.ok(capturedCrm);
    assert.equal(capturedCrm.phone_key, '01012345678');
    assert.equal(Number(capturedCrm.service_consent), 0); assert.equal(Number(capturedCrm.marketing_consent), 0);
    assert.ok(command.tables.crm_customer_sources.some(row => Number(row.customer_id) === Number(capturedCrm.id)
      && row.branch === 'f:100' && row.channel === 'delivery'));
    for (const table of Object.keys(command.tables)) assert.ok(snapshot.tables[table], 'Confirmed snapshot requires ' + table);
    await updateSnapshot({ kind: 'crm', id: crypto.randomUUID(), tables_wire: command.tables_wire });
    const retainedPointer = await runtime.metadata.read('prepared');
    const retainedFiles = await fs.readdir(path.join(runtime.profile, 'generations'));
    await assert.rejects(new DashboardGeneration({ runtime, metadata: runtime.metadata, allowPreview: true,
      download: async () => downloadSnapshot(await rewriteWire({ kind: 'source', sha256: '0'.repeat(64) })) }).run(), /نسخة البرنامج/);
    assert.deepEqual(await runtime.metadata.read('prepared'), retainedPointer);
    assert.deepEqual(await fs.readdir(path.join(runtime.profile, 'generations')), retainedFiles);
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    assert.equal((await runtime.control({ action: 'pending' })).counts.acknowledged, 1);
    assert.equal(imageRequests, 2);
    let next = await coordinator().run(); assert.notEqual(next.database, initial.database);
    assert.deepEqual(await numericProbe(), { integralFloat: true, exactInteger: true, integerText: '9007199254740993' });
    console.log('PASS real Windows refresh preserves the PHP integral DOUBLE and exact 64-bit integer without rebuilding server table hashes in JavaScript');
    assert.equal(imageRequests, 4);
    const refreshedTables = await php(bootstrap + captureTablesPhp + "echo json_encode($tables);");
    assert.deepEqual(refreshedTables, command.tables);
    assert.deepEqual(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app/public', imagePath)), imageBytes);
    assert.deepEqual(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'app', pdfPath)), pdfBytes);
    assert.equal(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions/supervisor-fixture-session'), 'utf8'), 'synthetic preserved session');
    const archived = await php(bootstrap + "echo json_encode(['commands'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_commands')->where('status','acknowledged')->count(),'state'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->value('state')]);",
      {}, { ...runtime.environment, DB_DATABASE: initial.database });
    assert.deepEqual(archived, { commands: 1, state: 'held' });
    assert.equal((await runtime.control({ action: 'pending' })).counts.pending, 0);
    const replay = await php(bootstrap + "try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',['values'=>$input['values']],[],fn()=>throw new RuntimeException('Archived work ran again.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",
      { device: snapshot.device_id, id: pending.commands[0].command_id, actor: snapshot.actor_id, values: command.values });
    assert.equal(replay.status, 409);
    // Synthetic server-session response; fences, processes, DBs and restart recovery are real.
    let local = true; const destinations = [];
    const view = { current: () => ({ local }), switchTo: async (target, work = async () => {}) => {
      await work(); destinations.push(target.origin); local = Boolean(target.localToken);
    } };
    const remoteState = new DashboardRemoteState(runtime.metadata);
    const mode = new DashboardMode({ runtime, view, generations: coordinator(), remoteState,
      probe: async () => ({ actor_id: snapshot.actor_id, device_id: snapshot.device_id }) });
    await updateSnapshot({ kind: 'id', id: crypto.randomUUID() }); await mode.refresh(); next = await runtime.connection();
    assert.equal(local, false); assert.ok(await runtime.metadata.read('return'));
    await assert.rejects(mode.local({ method: 'POST' }));
    const heldId = crypto.randomUUID(), heldValues = {...command.values, idempotency_key:heldId};
    const heldWrite = await php(bootstrap + "try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',['values'=>$input['values']],[],fn()=>throw new RuntimeException('Inactive local write ran.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",
      { device: snapshot.device_id, id: heldId, actor: snapshot.actor_id, values: heldValues });
    assert.equal(heldWrite.status, 409);
    await remoteState.begin('native-http-operation');
    await assert.rejects(mode.local({ method: 'GET' }));
    await assert.rejects(new DashboardRemoteState(runtime.metadata).assertClean());
    await remoteState.complete('native-http-operation');
    await assert.rejects(mode.local({ method: 'GET' }));
    await updateSnapshot({ kind: 'id', id: crypto.randomUUID() }); await mode.refresh(); next = await runtime.connection();
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
    assert.equal(await runtime.isPrepared(), true); assert.equal((await runtime.connection()).fullCoverage, false);
    assert.deepEqual((await runtime.connection()).coverage, snapshot.coverage);
    console.log('PASS real Windows restart verifies the retained preview source, route scope and imported media while preserving fullCoverage false');
    assert.equal(runtime.manifest.sourceRevision, next.sourceRevision);
    assert.notEqual(runtime.bundle, upgraded);
    await coordinator().recover(); assert.equal(await runtime.metadata.read('refresh'), null);
    const status = await runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    assert.equal(status.held, false); assert.equal((await runtime.connection()).database, next.database);
    assert.equal(failures.length, 0);
    await runtime.stop();
    const currentPointer = await runtime.metadata.read('prepared');
    const wrongScope = structuredClone(currentPointer); wrongScope.coverage.write_routes.pop();
    wrongScope.coverageBinding = coverageCode.binding(wrongScope);
    await runtime.metadata.write('prepared', wrongScope); runtime = create();
    await assert.rejects(runtime.start(), /بيانات تجهيز/); assert.equal(runtime.children.length, 0);
    assert.deepEqual(await runtime.metadata.read('prepared'), wrongScope);
    await runtime.metadata.write('prepared', currentPointer); runtime = create();
    const wrongSource = { ...currentPointer, sourceFingerprint: { ...currentPointer.sourceFingerprint, sha256: '0'.repeat(64) } };
    await runtime.metadata.write('prepared', wrongSource); runtime = create();
    await assert.rejects(runtime.start(), /مصدر البرنامج|بيانات تجهيز/);
    assert.deepEqual(await runtime.metadata.read('prepared'), wrongSource);
    assert.equal(runtime.children.length, 0);
    await runtime.metadata.write('prepared', currentPointer); runtime = create(); await runtime.start();
    assert.equal((await runtime.connection()).database, next.database);
    console.log('PASS real Windows source binding refuses changed server code before staging and changed active code before starting native services, preserving the journal and prepared database');
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
    const beforeUpgrade=await runtime.connection(),beforeApplication=runtime.application;
    const newInstall=path.join(profile,'synthetic-code-upgrade');await fs.cp(bundle,newInstall,{recursive:true});
    const marker='app/Services/Dashboard/DesktopUpgradeFixture.php',markerBytes=Buffer.from('<?php\nnamespace App\\Services\\Dashboard;\nclass DesktopUpgradeFixture { public const VERSION=2; }\n');
    await fs.writeFile(path.join(newInstall,'application',marker),markerBytes);
    const newManifest={...installedManifest,sourceRevision:'b'.repeat(40),sourceHashes:{...installedManifest.sourceHashes,
      [marker]:crypto.createHash('sha256').update(markerBytes).digest('hex')},sourceFingerprint:await sourceCode.fingerprint(path.join(newInstall,'application'))};
    await fs.writeFile(path.join(newInstall,'manifest.json'),JSON.stringify(newManifest));
    await runtime.stop();runtime=create(newInstall);await runtime.start();
    assert.equal(runtime.application,beforeApplication);assert.equal(runtime.manifest.sourceRevision,beforeUpgrade.sourceRevision);
    await updateSnapshot({ kind: 'upgrade', id: crypto.randomUUID(), source: newManifest.sourceFingerprint });
    const write=runtime.metadata.write.bind(runtime.metadata);
    runtime.metadata.write=async(name,value)=>{if(name==='prepared'&&value.sourceRevision===newManifest.sourceRevision)throw Error('synthetic upgrade pointer failure');return write(name,value);};
    await assert.rejects(coordinator().run(),/synthetic upgrade pointer failure/);runtime.metadata.write=write;
    assert.deepEqual(await runtime.connection(),beforeUpgrade);assert.equal(runtime.application,beforeApplication);
    assert.equal(await runtime.metadata.read('refresh'),null);assert.equal((await runtime.control({action:'pending'})).counts.pending,0);
    const activate=runtime.activate.bind(runtime);
    runtime.activate=async(...args)=>{await activate(...args);throw Error('synthetic lost upgrade reply');};
    await assert.rejects(coordinator().run(),/synthetic lost upgrade reply/);runtime.activate=activate;
    next=await runtime.connection();assert.notEqual(next.database,beforeUpgrade.database);
    assert.equal(next.sourceRevision,newManifest.sourceRevision);assert.equal(next.schemaHash,snapshot.schema_hash);
    assert.ok(sourceCode.same(next.sourceFingerprint,newManifest.sourceFingerprint));assert.equal(await runtime.metadata.read('refresh'),null);
    const upgradedData=await php(bootstrap+"echo json_encode(['code'=>class_exists('App\\\\Services\\\\Dashboard\\\\DesktopUpgradeFixture'),'field'=>\\Illuminate\\Support\\Facades\\Schema::hasColumn('branch_customers','desktop_upgrade_fixture'),'archived'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_archived_commands')->count()]);");
    assert.deepEqual(upgradedData,{code:true,field:true,archived:1});
    assert.deepEqual(await php("echo json_encode(['upload'=>ini_get('upload_max_filesize'),'post'=>ini_get('post_max_size'),'memory'=>ini_get('memory_limit')]);"),
      {upload:'5M',post:'12M',memory:'256M'});
    assert.equal(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE,'framework/sessions/supervisor-fixture-session'),'utf8'),'synthetic preserved session');
    assert.deepEqual(await fs.readFile(path.join(runtime.environment.DESKTOP_DASHBOARD_STORAGE,'app',pdfPath)),pdfBytes);
    const previousData=await php(bootstrap+"echo json_encode(['field'=>\\Illuminate\\Support\\Facades\\Schema::hasColumn('branch_customers','desktop_upgrade_fixture'),'customers'=>\\Illuminate\\Support\\Facades\\DB::table('branch_customers')->count(),'state'=>\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_local_state')->value('state')]);",{}, {...runtime.environment,DB_DATABASE:beforeUpgrade.database});
    assert.deepEqual(previousData,{field:false,customers:1,state:'held'});
    await runtime.stop();await fs.rm(newInstall,{recursive:true});runtime=create();await runtime.start();
    assert.equal(runtime.manifest.sourceRevision,next.sourceRevision);assert.equal((await runtime.connection()).database,next.database);
    assert.deepEqual(await php("echo json_encode(['upload'=>ini_get('upload_max_filesize'),'post'=>ini_get('post_max_size'),'memory'=>ini_get('memory_limit')]);"),
      {upload:'5M',post:'12M',memory:'256M'});
    const upgradedReplay=await php(bootstrap+"try{app(\\App\\Services\\Dashboard\\DesktopDashboardJournal::class)->execute($input['device'],$input['id'],$input['actor'],'customers.save',['values'=>$input['values']],[],fn()=>throw new RuntimeException('Old work ran after code upgrade.'));echo json_encode(['status'=>200]);}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $error){echo json_encode(['status'=>$error->getStatusCode()]);}",{device:snapshot.device_id,id:pending.commands[0].command_id,actor:snapshot.actor_id,values:command.values});
    assert.equal(upgradedReplay.status,409);assert.equal(failures.length,0);
    console.log('PASS real Windows code/schema upgrade retains the matching installed application with unchanged database binaries, recovers a rejected pointer and lost reply, preserves the previous schema and journal, and restarts from its retained new runtime');
    console.log('PASS real Windows supervisor prepares account data, Arabic images and private expense PDFs, fences server return, resumes local work, preserves journals and sessions, and recovers a held fence after restart');
  } finally { await runtime.stop(); await new Promise(resolve => mediaServer.close(resolve)); await fs.rm(profile, { recursive: true, force: true }); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
