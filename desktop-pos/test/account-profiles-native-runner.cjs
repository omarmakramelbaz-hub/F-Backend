'use strict';
// Node owns one synthetic HTTPS server while production app.relaunch starts
// three independent Electron processes. Only failure cleanup force-terminates.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const https = require('node:https');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { spawn, spawnSync, execFileSync } = require('node:child_process');
const asar = require('../node_modules/@electron/asar');

assert.equal(process.platform, 'win32', 'Run native account-profile acceptance on actual Windows.');
assert.equal(process.argv.length, 3);
const output = path.resolve(process.argv[2]);
const repository = path.resolve(__dirname, '../..');
const executable = path.resolve(__dirname, '../node_modules/electron/dist/electron.exe');
assert.equal(fs.existsSync(executable), true);
const electronVersion = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../package-lock.json'), 'utf8')).packages['node_modules/electron'].version;
const asarVersion = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../node_modules/@electron/asar/package.json'), 'utf8')).version;
assert.equal(asarVersion, '3.4.1');
const sourceRevision = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repository, encoding: 'utf8' }).trim();
assert.match(sourceRevision, /^[a-f0-9]{40}$/);
if (process.env.GITHUB_SHA) assert.equal(sourceRevision, process.env.GITHUB_SHA);
const sha = file => crypto.createHash('sha256').update(fs.readFileSync(path.join(repository, file))).digest('hex');
const sourceFiles = [
  'desktop-pos/src/main.cjs', 'desktop-pos/src/shutdown.cjs', 'desktop-pos/src/account-signout.cjs', 'desktop-pos/src/startup.html', 'desktop-pos/src/account-switch.cjs',
  'desktop-pos/src/account-profiles.cjs', 'desktop-pos/src/account-picker.cjs', 'desktop-pos/src/account-picker-preload.cjs',
  'desktop-pos/src/account-picker-renderer.js', 'desktop-pos/src/account-picker.css', 'desktop-pos/src/account-picker.html',
  'desktop-pos/src/dashboard.cjs', 'desktop-pos/src/dashboard-preload.cjs', 'desktop-pos/src/preparation-clock.js', 'desktop-pos/src/local-runtime.cjs',
  'desktop-pos/src/dashboard-generation.cjs', 'desktop-pos/src/dashboard-preparation.cjs', 'desktop-pos/src/store.cjs', 'desktop-pos/src/preload.cjs',
  'public/dashboard/js/dashboard-spa.js', 'desktop-pos/test/account-profiles-native.cjs',
  'desktop-pos/test/account-profiles-native-runner.cjs', '.github/workflows/desktop-account-profiles-native.yml'
];
const sourceHashes = Object.fromEntries(sourceFiles.map(file => [file, sha(file)]));
// GitHub Windows TEMP can contain a RUNNER~1 alias. The production registry
// deliberately refuses aliased profile roots, so start with its canonical path.
const root = fs.mkdtempSync(path.join(fs.realpathSync.native(os.tmpdir()), 'go-erp-native-accounts-'));
const profile = path.join(root, 'synthetic profile'), browserRoot = path.join(root, 'browser profile');
const application = path.join(root, 'application'), archive = path.join(root, 'app.asar');
fs.mkdirSync(profile); fs.mkdirSync(browserRoot); fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(path.join(root, 'account-profiles-fixture.marker'), 'GO_ERP_DISPOSABLE_ACCOUNT_PROFILES_ONLY\n', { flag: 'wx' });
const cert = fs.readFileSync(path.join(__dirname, 'fixtures/connected-loopback-cert.pem'));
const key = fs.readFileSync(path.join(__dirname, 'fixtures/connected-loopback-key.pem'));
const spa = fs.readFileSync(path.join(repository, 'public/dashboard/js/dashboard-spa.js'));
const recaller = 'remember_admin_' + crypto.createHash('sha1').update('Illuminate\\Auth\\SessionGuard').digest('hex');
const secret = crypto.randomBytes(32);
const cookieFor = actor => actor + '.' + crypto.createHmac('sha256', secret).update('synthetic-account-' + actor).digest('hex');
const actors = [42, 84];
const authenticatedActor = cookies => actors.find(actor => cookies[recaller] === cookieFor(actor) || cookies.fixture_session === cookieFor(actor));
const requests = { ownerSignins: 0, managerSignins: 0, ownerRecallerOnlyReads: 0, logins: 0, dashboards: 0, internalHeaders: 0 };
let server, child, serverFailure, initialExit;
const alive = pid => { try { process.kill(pid, 0); return true; } catch { return false; } };
const ownPids = () => fs.readdirSync(root).filter(name => /^started-[012]\.json$/.test(name))
  .map(name => JSON.parse(fs.readFileSync(path.join(root, name), 'utf8')).pid);
function diagnostics() {
  return fs.readdirSync(root).filter(name => /^diagnostic-[1-9][0-9]*\.json$/.test(name)).slice(0, 6).map(name => {
    const file = path.join(root, name); assert.ok(fs.statSync(file).size <= 8192);
    const value = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.equal(value.kind, 'go-erp-native-account-diagnostic');
    return value;
  });
}
function failureEvidence() {
  const value = { format: 1, kind: 'go-erp-native-account-runner-diagnostic', sourceRevision,
    initialExit: initialExit || null, requests, processes: diagnostics(),
    receipts: [0, 1, 2].map(phase => ({ phase, started: fs.existsSync(path.join(root, 'started-' + phase + '.json')),
      completed: fs.existsSync(path.join(root, 'phase-' + phase + '.json')) })) };
  try {
    const trace = JSON.parse(fs.readFileSync(path.join(profile, 'lifecycle-trace.json'), 'utf8'));
    value.lifecycle = (Array.isArray(trace.events) ? trace.events : []).filter(row => typeof row?.phase === 'string'
      && /^(?:startup|ledger|splash|prepared|runtime|remote|central|dashboard|recovery|generation|local|shutdown)-[a-z-]{1,45}$/.test(row.phase))
      .slice(-12).map(row => ({ phase: row.phase, seconds: Number.isFinite(row.elapsed_ms) ? Math.round(row.elapsed_ms / 100) / 10 : null }));
  } catch { value.lifecycle = []; }
  fs.writeFileSync(path.join(path.dirname(output), 'account-profiles-native-diagnostic.json'), JSON.stringify(value, null, 2) + '\n');
  console.error('NATIVE_ACCOUNT_RUNNER_DIAGNOSTIC ' + JSON.stringify(value));
}
async function waitFor(predicate, message, timeout = 180000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (serverFailure) throw serverFailure;
    if (diagnostics().some(value => value.failed === true)) throw Error('An actual native account phase failed; bounded diagnostics are retained.');
    const result = predicate(); if (result) return result;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw Error(message);
}
async function main() {
  let completed = false;
  try {
    assert.equal(await fs.promises.realpath(root), root, 'The private fixture root must use the same canonical Windows path as the production registry.');
    fs.mkdirSync(path.join(application, 'desktop-pos'), { recursive: true });
    fs.cpSync(path.join(repository, 'desktop-pos/src'), path.join(application, 'desktop-pos/src'), { recursive: true });
    for (const file of sourceFiles) {
      const destination = path.join(application, file);
      fs.mkdirSync(path.dirname(destination), { recursive: true });
      fs.copyFileSync(path.join(repository, file), destination);
    }
    fs.writeFileSync(path.join(application, 'package.json'), JSON.stringify({ name: 'private-account-profile-acceptance', version: '1.0.0', main: 'desktop-pos/test/account-profiles-native.cjs' }));
    await asar.createPackage(application, archive);
    const asarSha256 = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
    server = https.createServer({ key, cert }, async (request, response) => {
      try {
        const pathname = new URL(request.url, 'https://127.0.0.1').pathname;
        if (Object.keys(request.headers).some(name => /^x-fasakhansta-/i.test(name))) requests.internalHeaders++;
        assert.equal(requests.internalHeaders, 0, 'An unprepared original server request must not import a native local capability.');
        const cookies = Object.fromEntries(String(request.headers.cookie || '').split(/;\s*/).filter(Boolean).map(item => {
          const at = item.indexOf('='); return [item.slice(0, at), item.slice(at + 1)];
        }));
        const actor = authenticatedActor(cookies);
        if (pathname === '/dashboard/js/dashboard-spa.js') {
          response.writeHead(200, { 'Content-Type': 'application/javascript', 'Cache-Control': 'no-store' }); response.end(spa); return;
        }
        if (pathname === '/admin/login') {
          requests.logins++;
          if (actor) { response.writeHead(302, { Location: '/admin/dashboard' }); response.end(); return; }
          response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store',
            'Set-Cookie': 'fixture_csrf=synthetic-csrf; Path=/; Secure; HttpOnly; SameSite=Lax' });
          response.end('<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>دخول حساب اختبار</title></head><body>'
            + '<form data-dashboard-login action="/admin/signin" method="POST"><input type="hidden" name="_token" value="synthetic-csrf">'
            + '<input name="email" type="email"><input name="password" type="password"><input name="remember" value="1" type="checkbox"><button type="submit">دخول</button></form></body></html>'); return;
        }
        if (pathname === '/admin/signin') {
          assert.equal(request.method, 'POST'); let body = '';
          for await (const bytes of request) { body += bytes; assert.ok(body.length < 4096); }
          const form = new URLSearchParams(body);
          assert.equal(form.get('_token'), 'synthetic-csrf'); assert.equal(cookies.fixture_csrf, 'synthetic-csrf');
          assert.equal(form.get('password'), 'synthetic-fixture-password'); assert.equal(form.get('remember'), '1');
          const signinActor = form.get('email') === 'owner@example.test' ? 42 : form.get('email') === 'manager@example.test' ? 84 : 0;
          assert.ok(signinActor); if (signinActor === 42) requests.ownerSignins++; else requests.managerSignins++;
          const value = cookieFor(signinActor);
          response.writeHead(302, { Location: '/admin/dashboard', 'Cache-Control': 'no-store', 'Set-Cookie': [
            'fixture_session=' + value + '; Path=/; Secure; HttpOnly; SameSite=Lax',
            recaller + '=' + value + '; Max-Age=86400; Path=/; Secure; HttpOnly; SameSite=Lax'
          ] }); response.end(); return;
        }
        if (pathname === '/admin/dashboard') {
          requests.dashboards++;
          if (!actor) { response.writeHead(302, { Location: '/admin/login' }); response.end(); return; }
          if (actor === 42 && !cookies.fixture_session && cookies[recaller] === cookieFor(42)) requests.ownerRecallerOnlyReads++;
          const branch = actor === 42 ? 100 : 200, role = actor === 42 ? 'owner' : 'branch_manager';
          response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'private, no-store' });
          response.end('<!doctype html><html dir="rtl"><head><meta charset="utf-8"><script src="/dashboard/js/dashboard-spa.js"></script></head>'
            + '<body data-actor="' + actor + '" data-role="' + role + '" data-branches="f:' + branch + '"><nav class="main-header"></nav>'
            + '<main data-dashboard-page><h1>حساب اختبار معزول</h1><a id="allowed-branch-' + branch + '" href="/admin/dashboard">فرع الاختبار</a></main></body></html>'); return;
        }
        response.writeHead(404); response.end();
      } catch (error) {
        serverFailure = error;
        if (!response.headersSent) response.writeHead(500); response.end();
      }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'https://127.0.0.1:' + server.address().port;
    const configFile = path.join(root, 'fixture-config.json');
    fs.writeFileSync(configFile, JSON.stringify({ root, profile, browserRoot, archive, origin, recaller, sourceRevision, sourceHashes,
      electronVersion, certificateFingerprint: new crypto.X509Certificate(cert).fingerprint256, artifactDirectory: path.dirname(output) }), { flag: 'wx' });
    const env = { ...process.env, POS_TEST_PROFILE: profile, POS_TEST_DASHBOARD_ORIGIN: origin }; delete env.ELECTRON_RUN_AS_NODE; delete env.NODE_OPTIONS;
    child = spawn(executable, [archive, configFile], { env, shell: false, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    child.once('error', error => { serverFailure = error; });
    child.once('exit', (code, signal) => { initialExit = { code, signal }; if (code !== 0 || signal !== null) serverFailure = Error('The initial actual Electron process failed.'); });
    for (const stream of [child.stdout, child.stderr]) stream.on('data', bytes => process.stdout.write(bytes));
    await waitFor(() => fs.existsSync(path.join(root, 'phase-2.json')), 'The real menu/picker did not naturally relaunch through three processes.');
    const phases = [0, 1, 2].map(phase => JSON.parse(fs.readFileSync(path.join(root, 'phase-' + phase + '.json'), 'utf8')));
    const pids = phases.map(value => value.pid); assert.equal(new Set(pids).size, 3); assert.equal(pids[0], child.pid);
    await waitFor(() => initialExit && pids.every(pid => !alive(pid)), 'An actual account process remained alive after its natural quit.', 15000);
    assert.deepEqual(initialExit, { code: 0, signal: null });
    for (const [phase, receipt] of phases.entries()) {
      assert.equal(receipt.phase, phase); assert.equal(receipt.sourceRevision, sourceRevision); assert.deepEqual(receipt.sourceHashes, sourceHashes);
      assert.equal(receipt.platform, 'win32'); assert.equal(receipt.electronVersion, electronVersion);
      for (const field of ['actualElectron', 'actualProductionMain', 'actualAsarCode', 'actualDpapi', 'actualUnpreparedProfile', 'actualSelectedLedger', 'firstVisibleRealAccountPage', 'startupBridgeNeverShown', 'naturalQuit', 'completed']) assert.equal(receipt[field], true, phase + ':' + field);
      for (const field of ['hardware', 'productionProfile', 'actualPhpAuthorization', 'actualInstalledApplication', 'forcedSuccessExit']) assert.equal(receipt[field], false, phase + ':' + field);
    }
    for (const receipt of phases.slice(0, 2)) {
      for (const field of ['actualNativeMenu', 'actualNaturalRelaunch', 'actualNativePicker', 'pickerActionsVisible', 'transientFixtureSessionEnded']) assert.equal(receipt[field], true, field);
    }
    assert.equal(phases[0].pendingJournalBlockedSwitch, true); assert.equal(phases[0].interceptedDialogs, 1);
    for (const field of ['distinctDevicesAndSecrets', 'distinctPrivateAuthStorage', 'emptyNewCookiePartition', 'ownerDurableBytesUnchanged']) assert.equal(phases[1][field], true, field);
    for (const field of ['ownerDurableBytesUnchanged', 'otherDurableBytesUnchanged', 'originalJournalRestored', 'persistentCookiePartitionsIsolated', 'ownerRememberedWithoutCredentialPost', 'actorBindingMismatchRejected', 'corruptEncryptedRegistryRejected', 'capacity64PickerActionsVisible', 'labelsRenderedAsText']) assert.equal(phases[2][field], true, field);
    assert.equal(requests.ownerSignins, 1, 'Returning to the original remembered profile must not submit another password.');
    assert.equal(requests.managerSignins, 1); assert.equal(requests.internalHeaders, 0);
    assert.ok(requests.ownerRecallerOnlyReads > 0, 'The original profile must reenter using only its persistent recaller.');
    for (const [file, expected] of Object.entries(sourceHashes)) assert.equal(sha(file), expected, 'Sources must not change during actual native acceptance.');
    const proof = { format: 1, kind: 'go-erp-native-account-profiles', sourceRevision, sourceHashes, electronVersion, asarVersion, asarSha256, platform: 'win32',
      hardware: false, productionProfile: false, actualPhpAuthorization: false, actualInstalledApplication: false, syntheticRoleProjectionOnly: true, syntheticPrivateProofOnly: true,
      actualElectron: true, actualProductionMain: true, actualAsarCode: true, actualDpapi: true, processes: 3, actualNaturalRelaunch: true, actualNativeMenu: true,
      actualNativePicker: true, pickerActionsVisible: true, distinctDevicesAndSecrets: true, distinctPrivateAuthStorage: true,
      capacity64PickerActionsVisible: true, labelsRenderedAsText: true, transientFixtureSessionEnded: true,
      firstAuthenticationRequired: true, firstVisibleRealAccountPage: true, startupBridgeNeverShown: true, emptyNewCookiePartition: true, persistentCookiePartitionsIsolated: true,
      ownerRememberedWithoutCredentialPost: true, ownerDurableBytesUnchanged: true, otherDurableBytesUnchanged: true,
      selectedJournalsIsolated: true, originalJournalRestored: true, pendingJournalBlockedSwitch: true, syntheticAcknowledgementOnly: true,
      actorBindingMismatchRejected: true, corruptEncryptedRegistryRejected: true, naturalQuit: true, forcedSuccessExit: false,
      dialogsIntercepted: true, completed: true, requests,
      phases: phases.map(({ phase, pid, naturalQuit, actualNaturalRelaunch, interceptedDialogs }) => ({ phase, pid, naturalQuit, actualNaturalRelaunch, interceptedDialogs })) };
    fs.writeFileSync(output, JSON.stringify(proof, null, 2) + '\n', { flag: 'wx' });
    completed = true;
    console.log('NATIVE_ACCOUNT_PROFILES_COMPLETE ' + JSON.stringify({ processes: 3, sourceRevision, electronVersion }));
  } finally {
    if (!completed) {
      try { failureEvidence(); } catch { console.error('NATIVE_ACCOUNT_RUNNER_DIAGNOSTIC_UNAVAILABLE'); }
      for (const pid of new Set([child?.pid, ...ownPids()].filter(Number.isInteger))) {
        if (alive(pid)) spawnSync('taskkill.exe', ['/PID', String(pid), '/T', '/F'], { stdio: 'ignore', shell: false, windowsHide: true, timeout: 10000 });
      }
    }
    if (server) await new Promise(resolve => server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true, maxRetries: 30, retryDelay: 100 });
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
