'use strict';
// Disposable actual Windows/Electron/PHP/MariaDB acceptance. No mock issuer,
// password endpoint, SQL importer, authority provider or process supervisor.
const assert = require('node:assert/strict'), fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const { app, session, safeStorage, net, BrowserWindow } = require('electron');
const configFile = path.resolve(process.argv[2] || ''), config = JSON.parse(require('node:fs').readFileSync(configFile, 'utf8'));
assert.equal(process.platform, 'win32'); assert.equal(config.kind, 'go-erp-workspace-native-runner');
const server = JSON.parse(require('node:fs').readFileSync(config.serverConfig, 'utf8'));
const codeRoot = path.resolve(config.codeRoot), root = path.resolve(config.root), profile = path.join(root, 'native-profile');
const requireNative = name => require(path.join(codeRoot, name));
const { createWorkspaceNative } = requireNative('workspace-native.cjs');
const { AccountLogin } = requireNative('account-login.cjs'), Profiles = requireNative('account-profiles.cjs');
const { WorkspaceLegacyInspector } = requireNative('workspace-legacy-inspector.cjs'), LocalRuntime = requireNative('local-runtime.cjs');
const { WorkspaceSync } = requireNative('workspace-sync.cjs'), signout = requireNative('account-signout.cjs');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
let quitting = false, online = true, browser, host, dropReply = false, certificates = 0, stageCalls = 0, webOpcodeEnabled = false;
const owned = new Set(), accepted = new Set(), observedPids = new Set(), commandRequests = [], phases = [], setupPhases = [], nativeFaults = [];
const observedWebChildren = new WeakSet(), webOpcodeLaunches = [];
let nativeStage = 'initializing';
app.setPath('userData', path.join(root, 'electron-profile'));
app.on('window-all-closed', () => {});
app.on('session-created', value => value.setCertificateVerifyProc((request, callback) => {
  let fingerprint; try { fingerprint = new crypto.X509Certificate(request.certificate.data).fingerprint256; } catch {}
  const allowed = request.hostname === '127.0.0.1' && fingerprint === config.certificateFingerprint;
  if (allowed) certificates++; callback(allowed ? 0 : -2);
}));
const work = async fn => { const task = Promise.resolve().then(fn); accepted.add(task); try { return await task; } finally { accepted.delete(task); } };
function observeWeb(child) {
  assert.ok(child?.spawnargs?.includes('-S'));
  if (observedWebChildren.has(child)) return;
  const overrides = Object.fromEntries(child.spawnargs.flatMap((argument, index) => argument === '-d' ? [child.spawnargs[index + 1].split(/=(.*)/s).slice(0,2)] : []));
  assert.equal(overrides['opcache.enable_cli'], '1');
  assert.match(overrides['opcache.cache_id'], /^go-erp-web-[a-f0-9]{48}$/);
  assert.equal(overrides['opcache.file_cache'], '');
  assert.equal(overrides['opcache.file_cache_only'], '0');
  assert.equal(overrides['opcache.revalidate_freq'], '60');
  assert.equal(overrides['opcache.validate_permission'], '0');
  assert.equal(webOpcodeLaunches.some(value => value.cacheId === overrides['opcache.cache_id']), false,
    'Every actual web launch must get a new shared-memory opcode namespace.');
  assert.ok(Number.isSafeInteger(child.pid) && child.pid > 0);
  observedWebChildren.add(child); observedPids.add(child.pid); webOpcodeEnabled = true;
  webOpcodeLaunches.push({ pid: child.pid, cacheId: overrides['opcache.cache_id'], enableCli: '1', fileCache: '', fileCacheOnly: '0' });
}
async function stopHost() {
  if (!host) return;
  for (const child of host.workspace.physical.children) {
    if (Number.isInteger(child.pid)) observedPids.add(child.pid);
    if (child.spawnargs?.includes('-S')) observeWeb(child);
  }
  await Promise.allSettled([...accepted]); await host.stop(); host = null;
}
async function createHost() {
  const profiles = new Profiles({ root: profile, safeStorage });
  const createRuntime = descriptor => new LocalRuntime({ bundle: config.bundle, profile: descriptor.profile, safeStorage });
  const legacy = new AccountLogin({ profiles, createRuntime, createSession: partition => session.fromPartition(partition),
    serverOrigin: server.origin, online: () => online, stopped: () => quitting });
  const inspector = new WorkspaceLegacyInspector({ profiles, createRuntime, ownRuntime: value => owned.add(value), safeStorage, stopped: () => quitting });
  const transport = async (url, options) => {
    if (!online && new URL(url).origin === server.origin) throw Object.assign(Error('Fixture network disconnected.'), { code: 'ENETUNREACH' });
    const response = await net.fetch(url, options);
    if (new URL(url).pathname === '/api/desktop-dashboard/workspace/commands') {
      commandRequests.push(JSON.parse(options.body).command_id);
      if (dropReply && response.ok) { dropReply = false; await response.arrayBuffer(); throw Object.assign(Error('Lost accepted command reply.'), { code: 'ECONNRESET' }); }
    }
    return response;
  };
  const value = await createWorkspaceNative({ root: profile, bundle: config.bundle, safeStorage, profiles, legacyBroker: legacy, legacyInspector: inspector,
    createSession: partition => session.fromPartition(partition), transport, serverSession: () => browser, serverOrigin: server.origin,
    currentVersion: config.publicationVersion, online: () => online, stopped: () => quitting,
    ownRuntime: owner => owned.add(owner), inspectLegacyProfile: (account, options) => inspector.inspectProfile(account, options),
    transition: fn => work(fn), accepted: work, relaunch: async () => { phases.push('preparation-relaunch-requested'); },
    onPhase: phase => { const name = typeof phase === 'string' ? phase : phase.phase; phases.push(name); process.stdout.write('NATIVE_WORKSPACE_PHASE ' + JSON.stringify({ phase: name }) + '\n'); },
    onState: value => { if (typeof value?.phase === 'string' && setupPhases.at(-1) !== value.phase) { setupPhases.push(value.phase); process.stdout.write('NATIVE_WORKSPACE_SETUP ' + JSON.stringify({ phase: value.phase }) + '\n'); } },
    onFailure: error => { nativeFaults.push(error); }, onLocked: () => { throw Error('The actual authenticated actor was locked.'); },
    canApplyUpdate: async () => false, applyUpdate: async () => { throw Error('A disposable acceptance fixture never installs updates.'); }, allowPreview: true });
  assert.ok(value, 'Compile the reviewed contract pins in the acceptance candidate; a disabled factory cannot satisfy the native proof.');
  // Observe the real supervisor after each successful start, including the
  // preparation cutover. The original method still owns process startup and
  // readiness; this wrapper only asserts its actual child argv and identity.
  const physical = value.workspace.physical, startWeb = physical.startWeb;
  physical.startWeb = async function (...args) {
    const result = await startWeb.apply(this, args); observeWeb(this.children[1]); return result;
  };
  if (await value.registry.current()) for (const name of ['stage', 'verifyCandidate', 'restoreStage']) value.workspace[name] = async () => {
    stageCalls++; throw Error('Heavy preparation must not run during cached login.');
  };
  return value;
}
function credentials(id) { const value = server.credentials.find(row => row.actorId === id); assert.ok(value); return { email: value.email, password: value.password, remember: true }; }
async function readActor(result) {
  const local = { origin: result.origin, token: result.localToken }, target = session.fromPartition(result.account.partition);
  const response = await target.fetch(local.origin + '/admin/choose_type', { headers: { 'X-Fasakhansta-Desktop': local.token }, redirect: 'manual' });
  assert.equal(response.status, 200); const html = await response.text();
  assert.match(html, new RegExp('data-dashboard-actor=["\\\']' + result.actor.actorId + ':' + result.actor.role + '["\\\']'));
  return { browser: target, local, html };
}
async function json(result, pathname, input) {
  const target = session.fromPartition(result.account.partition), response = await target.fetch(result.origin + '/admin/choose_type', {
    headers: { 'X-Fasakhansta-Desktop': result.localToken }, redirect: 'manual' });
  assert.equal(response.status, 200); const html = await response.text(), csrf = /<meta\b[^>]*name=["']csrf-token["'][^>]*content=["']([A-Za-z0-9_-]{16,256})["']/.exec(html)?.[1];
  assert.ok(csrf, 'Use the original real PHP CSRF session.');
  const received = await target.fetch(result.origin + pathname, { method: input === undefined ? 'GET' : 'POST', redirect: 'manual',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
      'X-Fasakhansta-Desktop': result.localToken, 'X-CSRF-TOKEN': csrf }, body: input === undefined ? undefined : JSON.stringify(input) });
  assert.equal(received.status, 200, 'Original business HTTP route ' + pathname + ' must succeed.'); return received.json();
}
async function show(result) {
  const window = new BrowserWindow({ width: 1000, height: 800, show: false, webPreferences: { partition: result.account.partition, sandbox: true, contextIsolation: true, nodeIntegration: false } });
  window.webContents.session.webRequest.onBeforeSendHeaders({ urls: [result.origin + '/*'] }, (details, callback) =>
    callback({ requestHeaders: { ...details.requestHeaders, 'X-Fasakhansta-Desktop': result.localToken } }));
  await window.loadURL(result.origin + '/admin/choose_type', { extraHeaders: 'X-Fasakhansta-Desktop: ' + result.localToken + '\r\n' });
  const loadedURL = window.webContents.getURL(); assert.equal(loadedURL, result.origin + '/admin/choose_type');
  assert.equal(await window.webContents.executeJavaScript('typeof window.require'), 'undefined');
  assert.equal(await window.webContents.executeJavaScript('document.body.dataset.dashboardActor'), result.actor.actorId + ':' + result.actor.role);
  // Windows dispatches native visibility after show() returns. Preserve the
  // real visible-view proof while allowing that event loop transition.
  let shown = false; window.once('show', () => { shown = true; });
  window.show();
  try {
    const deadline = Date.now() + 5000;
    while ((!shown || !window.isVisible()) && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 20));
    assert.equal(shown, true, 'The actual protected window must emit its native show event.');
    assert.equal(window.isVisible(), true, 'The actual protected window must become visible within five seconds.');
    assert.equal(window.webContents.getURL(), loadedURL);
    assert.equal(await window.webContents.executeJavaScript('document.body.dataset.dashboardActor'), result.actor.actorId + ':' + result.actor.role);
  } finally { window.destroy(); }
}
function originalLogout(result) {
  // Electron fetch rejects redirect:'manual' instead of surfacing a 302.
  // Exercise the original logout with the same partition cookies and approve
  // only its exact same-origin login redirect through the native request event.
  return new Promise((resolve,reject) => {
    let request, settled = false, redirects = 0, ended = false, bytes = 0; const parts = [];
    const timer = setTimeout(() => finish(Error('The original logout response timed out.')), 15000);
    const finish = (error,value) => {
      if (settled) return; settled = true; clearTimeout(timer);
      if (error) { try { request?.abort(); } catch {} reject(error); } else resolve(value);
    };
    try {
      request = net.request({ url: result.origin + '/admin/adminLogout', method: 'GET',
        session: session.fromPartition(result.account.partition), useSessionCookies: true, credentials: 'include',
        redirect: 'manual', cache: 'no-store', headers: { Accept: 'text/html', 'X-Fasakhansta-Desktop': result.localToken } });
      request.on('redirect',(status,method,target) => {
        try {
          assert.ok([302,303].includes(status)); assert.equal(method, 'GET');
          assert.equal(target, result.origin + '/admin/login'); assert.equal(++redirects, 1);
          request.followRedirect();
        } catch (error) { finish(error); }
      });
      request.on('response',response => {
        response.on('data',chunk => {
          if (settled) return; const value = Buffer.from(chunk); bytes += value.length;
          if (bytes > 4 * 1024 ** 2) { finish(Error('The original logout response exceeded its bound.')); return; } parts.push(value);
        });
        response.on('error',error => finish(error));
        response.on('aborted',() => finish(Error('The original logout response was aborted.')));
        response.on('close',() => { if (!ended) finish(Error('The original logout response closed before its body ended.')); });
        response.on('end',() => {
          ended = true;
          try {
            assert.equal(redirects, 1); assert.equal(response.statusCode, 200);
            assert.match(Buffer.concat(parts,bytes).toString('utf8'), /<form\b[^>]*\bdata-dashboard-login\b/);
            finish(null,{ status: response.statusCode });
          } catch (error) { finish(error); }
        });
      });
      request.on('error',error => finish(error));
      request.on('abort',() => finish(Error('The original logout request was aborted.')));
      request.end();
    } catch (error) { finish(error); }
  });
}
const alive = pid => { try { process.kill(pid, 0); return true; } catch { return false; } };
async function main() {
  await app.whenReady(); assert.equal(safeStorage.isEncryptionAvailable(), true);
  assert.equal(await fs.realpath(root), root); assert.equal(await fs.readFile(path.join(root, 'disposable-workspace-native.marker'), 'utf8'), 'GO_ERP_DISPOSABLE_SHARED_WORKSPACE_ONLY\n');
  await fs.mkdir(profile, { recursive: false });
  const sentinel = crypto.randomBytes(128); await fs.writeFile(path.join(profile, 'legacy-accepted-sentinel.bin'), sentinel, { flag: 'wx' });
  nativeStage = 'initial-online-login'; host = await createHost(); const original = await host.login.authenticate(credentials(10)); browser = session.fromPartition(original.account.partition);
  assert.equal(original.local, false); nativeStage = 'one-preparation'; const ready = await work(() => host.preparation.prepare(server.origin, 'page-token-is-not-native-authority'));
  assert.equal(ready.prepared, true); assert.equal(ready.setup_timing.running, false); assert.ok(ready.setup_timing.elapsed_ms > 0); assert.equal(phases.filter(value => value === 'preparation-relaunch-requested').length, 1);
  const prepared = await host.registry.current(), bound = prepared.candidate;
  assert.equal(bound.prepared.actorId, 10); assert.equal(bound.workspaceId, prepared.manifest.workspace_id);
  await stopHost();
  nativeStage = 'offline-first-logins'; online = false; await fs.writeFile(path.join(root, 'offline.marker'), 'offline\n', { flag: 'wx' });
  host = await createHost();
  const opened = [], loginMs = [];
  for (const id of [11, 1, 10]) {
    nativeStage = 'offline-first-login-' + id;
    process.stdout.write('NATIVE_WORKSPACE_ACTOR ' + JSON.stringify({ actorId: id, phase: 'password' }) + '\n');
    await assert.rejects(fs.lstat(path.join(profile, 'workspace-actors', prepared.manifest.workspace_id, String(id))), { code: 'ENOENT' });
    const start = Date.now(), result = await work(() => host.login.authenticate(credentials(id))); loginMs.push(Date.now() - start);
    assert.equal(result.local, true); assert.equal(result.actor.actorId, id); assert.equal((await result.runtime.connection()).database, bound.database);
    assert.equal((await result.runtime.authority()).actorId, id); await readActor(result); await show(result); await result.afterView(); opened.push(result);
    process.stdout.write('NATIVE_WORKSPACE_ACTOR ' + JSON.stringify({ actorId: id, phase: 'visible' }) + '\n');
  }
  nativeStage = 'offline-a-write'; const a = opened.at(-1), keyA = crypto.randomUUID(), keyB = crypto.randomUUID();
  await json(a, '/admin/customers/save', { branch: 'f:100', idempotency_key: keyA, name: 'A shared offline customer', phone: '01012345671', address: 'Synthetic branch address' });
  assert.equal((await host.workspace.control({ action: 'pending' })).counts.pending, 1);
  await stopHost(); host = await createHost();
  nativeStage = 'offline-b-login'; let b = await work(() => host.login.authenticate(credentials(11)));
  nativeStage = 'offline-b-identity'; await readActor(b);
  nativeStage = 'offline-b-visible'; await show(b);
  nativeStage = 'offline-b-after-view'; await b.afterView();
  nativeStage = 'offline-b-shared-read';
  const visible = await json(b, '/admin/customers/data?branch=f%3A100&search=01012345671');
  assert.equal(visible.items.some(row => row.name === 'A shared offline customer'), true, 'B must see A current local commit before any server sync.');
  nativeStage = 'offline-b-write';
  await json(b, '/admin/customers/save', { branch: 'f:100', idempotency_key: keyB, name: 'B shared offline customer', phone: '01012345672', address: 'Synthetic branch address' });
  const pending = await host.workspace.control({ action: 'pending' });
  assert.equal(pending.counts.pending, 2); assert.deepEqual(pending.commands.map(row => [row.command_id, row.actor_id]), [[keyA, 10]]);
  // Original pending() deliberately exposes only the first global sequence.
  // Inspect all two test-owned rows read-only, without manufacturing authority.
  const physical = host.workspace.physical;
  const journalRows = JSON.parse(await LocalRuntime.runNative(physical.php, [...LocalRuntime.phpArguments(physical), '-r',
    "$app=require getcwd().'/desktop/bootstrap.php';echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('desktop_dashboard_commands')->orderBy('sequence')->get(['command_id','actor_id','sequence']),JSON_THROW_ON_ERROR);"],
    { cwd: physical.application, env: physical.environment }));
  assert.deepEqual(journalRows.map(row => [row.command_id, Number(row.actor_id)]), [[keyA, 10], [keyB, 11]]);
  assert.ok(Number(journalRows[0].sequence) < Number(journalRows[1].sequence));
  await stopHost(); host = await createHost();
  nativeStage = 'remembered-reopen'; const remembered = await host.login.resumeRemembered(); assert.equal(remembered.actor.actorId, 11); assert.equal(remembered.local, true);
  b = remembered;
  nativeStage = 'explicit-logout'; await signout.signedOut(b.runtime); host.workspace.revokeSession(11);
  const loggedOut = await originalLogout(b); assert.equal(loggedOut.status, 200);
  assert.equal(await host.login.resumeRemembered(), null); assert.equal(await signout.requirePassword(b.runtime), true);
  await assert.rejects(host.login.authenticate({ ...credentials(11), password: 'invalid-native-fixture-password' }));
  const bAgain = await host.login.authenticate(credentials(11)); bAgain.discardCredentials();
  assert.equal(await signout.requirePassword(bAgain.runtime), false);
  const offlineCounts = JSON.parse(await fs.readFile(path.join(root, 'request-counts.json'), 'utf8')); assert.equal(offlineCounts.offlineRequests, 0);
  const activePhysical = host.workspace.physical;
  const opcode = JSON.parse(await LocalRuntime.runNative(activePhysical.php, [...LocalRuntime.phpArguments(activePhysical), '-r',
    "echo json_encode(['extension'=>extension_loaded('Zend OPcache'),'cli'=>ini_get('opcache.enable_cli'),'attached'=>function_exists('opcache_get_status')&&opcache_get_status(false)!==false,'memory'=>ini_get('memory_limit')],JSON_THROW_ON_ERROR);"],
    { cwd: activePhysical.application, env: activePhysical.environment }));
  assert.deepEqual(opcode, { extension: true, cli: '0', attached: false, memory: '256M' });
  assert.equal(stageCalls, 0); assert.equal(Math.max(...loginMs) < 45000, true, 'Cached local opening must stay within the real initial document deadline.');
  assert.deepEqual((await host.registry.current()).candidate, bound); assert.equal((await fs.readdir(path.join(profile, 'workspaces'))).length, 1);
  assert.deepEqual(await fs.readFile(path.join(profile, 'legacy-accepted-sentinel.bin')), sentinel);
  nativeStage = 'original-sync-lost-reply'; online = true; await fs.unlink(path.join(root, 'offline.marker'));
  const sync = new WorkspaceSync({ workspace: host.workspace, remote: (command, credential) => host.http.root('commands', command, credential) });
  dropReply = true; await work(() => sync.run()); assert.equal((await host.workspace.control({ action: 'pending' })).counts.pending, 2);
  await work(() => sync.run()); assert.equal((await host.workspace.control({ action: 'pending' })).counts.pending, 0); sync.stop();
  assert.deepEqual(commandRequests, [keyA, keyA, keyB]);
  const proof = { format: 2, kind: 'go-erp-shared-workspace-native', sourceRevision: server.sourceRevision, sourceHashes: config.sourceHashes,
    electronVersion: process.versions.electron, actualElectron: true, actualEncryptedMetadata: true, actualPhpMariaDB: true,
    productionFactory: true, productionMain: false, syntheticPublication: true, certificateChecks: certificates,
    physicalWorkspaceId: bound.workspaceId, database: bound.database, generation: bound.generation, sourceFingerprint: bound.sourceFingerprint,
    sourceSequence: bound.sourceSequence, onePreparation: true, firstEverOfflineActors: opened.map(value => value.actor.actorId), zeroOfflineHttp: true,
    samePhysicalDatabase: true, aPendingVisibleToB: true, bWritesKeepOriginalActor: true, globalSequenceAThenB: true,
    actualProtectedView: true, fixedCliOpcodeDisabled: true, fixedCliNotAttached: true, persistentWebOpcodeEnabled: true,
    freshWebOpcodeNamespaces: true, noSharedWebFileCache: true, cachedRememberedCloseReopen: true, explicitLogoutRequiresPassword: true,
    noHeavyLoginWork: true, loginStageCalls: stageCalls, cachedLoginMilliseconds: loginMs, retiredLegacyPreserved: true,
    originalCommandIds: [keyA, keyB], commandAttempts: commandRequests, lostAcceptedReplyRetriedSameUuid: true,
    setupTimer: { elapsed_ms: ready.setup_timing.elapsed_ms, running: false }, productionProfile: false, completed: false };
  nativeStage = 'teardown'; assert.equal(nativeFaults.length, 0);
  await stopHost(); for (const owner of owned) await owner.stop();
  assert.ok(webOpcodeLaunches.length >= 4); proof.webOpcodeLaunches = webOpcodeLaunches;
  assert.equal(webOpcodeEnabled, true); assert.equal([...observedPids].every(pid => !alive(pid)), true); proof.noChildrenAfterStop = true; proof.nativePids = [...observedPids]; proof.completed = true;
  const bytes = await fs.readFile(path.join(profile, 'workspace-registry.enc'));
  assert.equal(bytes.includes(Buffer.from(prepared.credential.token)), false, 'The durable native root bearer remains DPAPI sealed.');
  await fs.writeFile(config.proof, JSON.stringify(proof, null, 2) + '\n', { flag: 'wx' });
  process.stdout.write('NATIVE_SHARED_WORKSPACE_COMPLETE ' + JSON.stringify({ sourceRevision: server.sourceRevision, actors: proof.firstEverOfflineActors, processes: proof.nativePids.length }) + '\n');
}
function publicError(error) {
  const secrets = [...server.credentials.flatMap(row => [row.email, row.password]),
    ...Object.entries(server.environment || {}).filter(([name]) => /KEY|PASSWORD|TOKEN|SECRET/.test(name)).map(([, value]) => value),
    ...Object.entries(host?.workspace?.physical?.environment || {}).filter(([name]) => /KEY|PASSWORD|TOKEN|SECRET/.test(name)).map(([, value]) => value),
    host?.workspace?.physical?.token, host?.workspace?.physical?.controlToken];
  const sanitize = input => {
    let text = String(input || '');
    for (const secret of secrets) if (typeof secret === 'string' && secret.length >= 4) text = text.split(secret).join('[redacted]');
    return text.replace(/base64:[A-Za-z0-9+/=]+/g, '[redacted-key]').replace(/Bearer\s+[^\s"']+/gi, 'Bearer [redacted]')
      .replace(/\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}/g, '[redacted-verifier]')
      .replace(/\$argon2(?:id|i|d)\$[^\s"']+/g, '[redacted-verifier]')
      .replace(/\b[A-Za-z0-9+/]{64,}={0,2}\b/g, '[redacted-token]')
      .replace(/\b[a-f0-9]{32,}\b/gi, '[redacted-token]').replace(/https?:\/\/[^\s"']+/g, '[redacted-url]')
      .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, ' ').slice(0, 1200);
  };
  const frames = String(error?.stack || '').split('\n').slice(1).map(line => {
    const match = /(?:[\\/])([^\\/:()]+\.(?:c?js|php)):(\d+)(?::\d+)?\)?$/.exec(line.trim());
    return match ? { file: match[1], line: Number(match[2]) } : null;
  }).filter(Boolean).slice(0, 10);
  const cause = error?.cause, report = { type: error?.name || 'Error', code: /^[A-Z0-9_]{1,100}$/.test(error?.code || '') ? error.code : null,
    message: sanitize(error?.message), frames };
  if (cause && typeof cause === 'object') {
    report.cause = { exitCode: Number.isInteger(cause.exitCode) ? cause.exitCode : null, timedOut: cause.timedOut === true, overflow: cause.overflow === true };
    const detail = cause.import;
    if (detail && /^[A-Za-z0-9_\\]{1,160}$/.test(detail.type || '') && /^[A-Za-z0-9_.-]{1,120}$/.test(detail.file || '')) {
      report.cause.import = { phase: detail.phase, type: detail.type, file: detail.file, line: detail.line, status: detail.status ?? null };
      if (/^[A-Z0-9]{5}$/.test(detail.sql_state || '') && Number.isInteger(detail.driver_errno) && detail.driver_errno > 0 && detail.driver_errno <= 65535) {
        report.cause.import.sql_state = detail.sql_state; report.cause.import.driver_errno = detail.driver_errno;
        if (detail.sql_context) report.cause.import.sql_context = detail.sql_context;
      }
    }
  }
  return { report, sanitize };
}
async function diagnostics(error) {
  const physical = host?.workspace?.physical;
  // A network reset can arrive before libuv delivers the crashing Windows
  // child's exit event. Observe that event for a bounded diagnostic interval;
  // do not restart, retry the request or change any acceptance assertion.
  if (/ERR_CONNECTION_RESET|ECONNRESET/.test(String(error?.code || '') + ' ' + String(error?.message || '')) && physical?.children[1])
    await Promise.race([physical.children[1].completed, new Promise(resolve => setTimeout(resolve, 500))]);
  const { report, sanitize } = publicError(error), logs = []; let pathBudget = null, credentialChecks = null;
  if (physical) {
    if (error?.code === 'ACCOUNT_CREDENTIALS_REJECTED' && /^offline-first-login-\d+$/.test(nativeStage)) {
      try {
        // Read-only diagnostics for this disposable fixture. Only fixed booleans
        // and public actor IDs leave PHP; no rows, passwords or verifiers do.
        const code = "$input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);"
          + "$app=require getcwd().'/desktop/bootstrap.php';$out=[];$provider=auth('admin')->getProvider();"
          + "foreach($input as $expected){$row=\\Illuminate\\Support\\Facades\\DB::table('users')->where('id',$expected['actorId'])->first();"
          + "$model=\\App\\Models\\User::where('email',$expected['email'])->first();"
          + "$credentials=['mobile'=>$row->mobile??'', 'password'=>$expected['password'], 'account_type'=>$row->account_type??''];"
          + "$candidate=$provider->retrieveByCredentials($credentials);"
          + "$out[]=['actorId'=>$expected['actorId'],'rowExists'=>$row!==null,'emailMatches'=>$row!==null&&hash_equals($expected['email'],(string)$row->email),"
          + "'roleMatches'=>$row!==null&&$row->account_type===$expected['role'],'statusAccepted'=>$row!==null&&$row->status==='accepted',"
          + "'passwordMatches'=>$row!==null&&password_verify($expected['password'],(string)$row->password),"
          + "'modelMatches'=>$model!==null&&(int)$model->id===$expected['actorId'],"
          + "'providerMatches'=>$candidate!==null&&(int)$candidate->id===$expected['actorId'],"
          + "'providerPasswordMatches'=>$candidate!==null&&$provider->validateCredentials($candidate,$credentials)];}"
          + "echo json_encode($out,JSON_THROW_ON_ERROR);";
        credentialChecks = JSON.parse(await LocalRuntime.runNative(physical.php, [...LocalRuntime.phpArguments(physical), '-r', code],
          { cwd: physical.application, env: physical.environment }, JSON.stringify(server.credentials.map(row => ({ actorId: row.actorId, email: row.email, password: row.password, role: row.role }))), 10000, 8192));
        const keys = ['rowExists','emailMatches','roleMatches','statusAccepted','passwordMatches','modelMatches','providerMatches','providerPasswordMatches'];
        assert.ok(Array.isArray(credentialChecks) && credentialChecks.length === server.credentials.length);
        for (const row of credentialChecks) assert.ok(Object.keys(row).length === keys.length + 1
          && Number.isSafeInteger(row.actorId) && row.actorId > 0 && keys.every(key => typeof row[key] === 'boolean'));
      } catch { credentialChecks = { unavailable: true }; }
    }
    const locations = new Set([physical.environment?.DESKTOP_DASHBOARD_STORAGE]);
    try {
      const pending = await host.registry.metadata.read('workspace-preparation');
      if (/^[a-f0-9]{16}$/.test(pending?.generation || '')) {
        locations.add(physical.storageFor({ generation: pending.generation }));
        const databaseRoot = path.join(physical.profile, 'database', 'fasakhansta_dashboard_stage_' + pending.generation);
        pathBudget = { databaseRoot: databaseRoot.length, historyFrm: path.join(databaseRoot, 'desktop_dashboard_ws_authority_history.frm').length,
          commandAuthoritiesFrm: path.join(databaseRoot, 'desktop_dashboard_ws_command_auth.frm').length,
          oldCommandAuthoritiesFrm: path.join(databaseRoot, 'desktop_dashboard_workspace_command_authorities.frm').length };
      }
    } catch {}
    for (const storage of locations) if (typeof storage === 'string') {
      try {
        const file = path.join(storage, 'logs/laravel.log'), stat = await fs.lstat(file);
        if (stat.isFile() && !stat.isSymbolicLink() && stat.size <= 2 * 1024 ** 2) {
          const raw = await fs.readFile(file, 'utf8'); logs.push({ kind: 'laravel', lines: raw.slice(-12000).split(/\r?\n/)
            .filter(line => /ERROR|Exception|SQLSTATE|WORKSPACE_/.test(line)).slice(-6).map(sanitize) });
        }
      } catch {}
    }
    for (const [index, child] of physical.children.entries()) {
      if (!child) continue;
      const raw = child.stderrTail?.() || ''; logs.push({ kind: index === 0 ? 'native-maria' : 'native-php',
        exitCode: Number.isInteger(child.exitCode) ? child.exitCode : null,
        signal: /^[A-Z0-9]{1,20}$/.test(child.signalCode || '') ? child.signalCode : null,
        lines: raw.split(/\r?\n/).filter(line => /error|Warning|Fatal|Uncaught|Exception|WORKSPACE_/i.test(line)).slice(-6).map(sanitize) });
    }
  }
  return { ...report, stage: nativeStage, setupPhases: setupPhases.slice(-12), phases: phases.filter(value => typeof value === 'string' && /^[a-z-]{1,60}$/.test(value)).slice(-12),
    nativeFaults: nativeFaults.map(value => publicError(value).report), pathBudget, credentialChecks, logs };
}
main().then(() => app.exit(0), async error => {
  const report = await diagnostics(error); quitting = true;
  process.stderr.write('NATIVE_SHARED_WORKSPACE_FAILED ' + JSON.stringify(report) + '\n');
  try { await stopHost(); for (const owner of owned) await owner.stop(); } catch {}
  app.exit(1);
});
