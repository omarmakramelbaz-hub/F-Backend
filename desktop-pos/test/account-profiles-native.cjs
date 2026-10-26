'use strict';
// Actual production main/menu/picker/relaunch wiring. The HTTPS accounts and
// private capability below are synthetic; Laravel permissions run separately.
const { app, BrowserWindow, Menu, dialog, safeStorage, session } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { pathToFileURL } = require('node:url');
const AccountProfiles = require('../src/account-profiles.cjs');
const LocalRuntime = require('../src/local-runtime.cjs');
const Store = require('../src/store.cjs');

assert.equal(process.platform, 'win32');
assert.equal(process.argv.length, 3);
const configFile = path.resolve(process.argv[2]);
const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));
assert.equal(fs.readFileSync(path.join(config.root, 'account-profiles-fixture.marker'), 'utf8'), 'GO_ERP_DISPOSABLE_ACCOUNT_PROFILES_ONLY\n');
assert.equal(process.versions.electron, config.electronVersion);
assert.equal(process.env.POS_TEST_PROFILE, config.profile);
assert.equal(process.env.POS_TEST_DASHBOARD_ORIGIN, config.origin);
assert.equal(app.isPackaged, false, 'The default Electron executable must keep private unpackaged test overrides enabled.');
assert.equal(path.resolve(__dirname, '../..'), config.archive, 'Execute production main and picker modules from the actual measured ASAR.');
const stateFile = path.join(config.root, 'phase-state.enc');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const sourceHash = file => sha(fs.readFileSync(path.resolve(__dirname, '../..', file)));
for (const [file, expected] of Object.entries(config.sourceHashes)) assert.equal(sourceHash(file), expected);
app.setPath('userData', config.browserRoot);
assert.equal(app.requestSingleInstanceLock(), true, 'Each natural relaunch must acquire the released native lock.');
let failed = false, phaseReady = false, phase, selected, state, evidence = {}, dialogs = 0, expectedDialog = false;
let certificateChecks = 0;
let diagnosticStage = 'loaded';
function diagnostic(error) {
  const value = { format: 1, kind: 'go-erp-native-account-diagnostic', pid: process.pid,
    phase: Number.isInteger(phase) ? phase : null, stage: diagnosticStage, failed: Boolean(error) };
  if (error) {
    value.errorType = ['Error','AssertionError','TypeError','ReferenceError','SyntaxError','RangeError'].includes(error.name) ? error.name : 'Error';
    if (error.code === 'ERR_ASSERTION') value.errorCode = error.code;
    let message = String(error.message || '').replace(/[\x00-\x1f\x7f]/g, ' ')
      .replace(/base64:[A-Za-z0-9+/=]+/g, '[redacted-key]').replace(/\b[a-f0-9]{32,}\b/gi, '[redacted-token]')
      .replace(/https?:\/\/[^\s]+/gi, '[redacted-url]').split(config.root).join('[fixture]');
    for (const account of [state?.owner, state?.other]) for (const secret of [account?.settings?.rootPassword,
      account?.settings?.appPassword, account?.settings?.appKey, account?.jwtSecret]) {
      if (typeof secret === 'string' && secret) message = message.split(secret).join('[redacted-secret]');
    }
    value.message = message.slice(0, 500);
  }
  const target = path.join(config.root, 'diagnostic-' + process.pid + '.json'), temporary = target + '.tmp';
  try { fs.writeFileSync(temporary, JSON.stringify(value) + '\n'); fs.renameSync(temporary, target); } catch {}
  return value;
}
function stage(value) { diagnosticStage = value; diagnostic(); }
const fail = error => {
  if (failed) return;
  failed = true; process.stderr.write('NATIVE_ACCOUNT_PHASE_FAILURE ' + JSON.stringify(diagnostic(error)) + '\n'); app.exit(1);
};
process.on('uncaughtException', fail); process.on('unhandledRejection', fail);
stage('loaded');
const watchdog = setTimeout(() => fail(Error('Actual main account switching exceeded its bounded fixture deadline.')), 55000);
const originalDialog = dialog.showMessageBox.bind(dialog);
dialog.showMessageBox = async (...args) => {
  const options = args.at(-1);
  if (expectedDialog && options?.type === 'error' && /أكمل حفظ ومزامنة/.test(options.message)) {
    dialogs++; return { response: 0, checkboxChecked: false };
  }
  return originalDialog(...args);
};
dialog.showErrorBox = (_title, message) => {
  // This interceptor runs only after the disposable-profile marker/source
  // checks above. Keep the real fixed error useful without logging generated
  // private settings, token-shaped values, arbitrary trace fields or URLs.
  const knownPhases = new Set(['startup-ready', 'ledger-open', 'splash-loading', 'splash-loaded', 'prepared-check',
    'runtime-start', 'runtime-archive', 'runtime-source', 'runtime-database', 'runtime-media', 'runtime-web',
    'runtime-ready', 'remote-state', 'central-selection', 'dashboard-create', 'recovery-check', 'generation-recovery',
    'local-fence-recovery', 'central-fence-recovery', 'dashboard-navigation', 'dashboard-open-settled', 'startup-complete', 'startup-failed']);
  let phases = [];
  try {
    const trace = JSON.parse(fs.readFileSync(path.join(config.profile, 'lifecycle-trace.json'), 'utf8'));
    if (Array.isArray(trace.events)) phases = trace.events.filter(value => knownPhases.has(value?.phase)).slice(-8).map(value => value.phase);
  } catch {}
  let nativeMessage = String(message ?? '').replace(/[\x00-\x1f\x7f]/g, ' ')
    .replace(/base64:[A-Za-z0-9+/=]+/g, '[redacted-key]').replace(/\b[a-f0-9]{32,}\b/gi, '[redacted-token]')
    .replace(/https?:\/\/[^\s]+/gi, '[redacted-url]');
  for (const account of [state?.owner, state?.other]) {
    for (const secret of [account?.settings?.rootPassword, account?.settings?.appPassword, account?.settings?.appKey, account?.jwtSecret]) {
      if (typeof secret === 'string' && secret) nativeMessage = nativeMessage.split(secret).join('[redacted-secret]');
    }
  }
  process.stderr.write('NATIVE_ACCOUNT_STARTUP_ERROR ' + JSON.stringify({ nativeMessage: nativeMessage.slice(0, 400), phases }) + '\n');
  fail(Error('Production main reported an unexpected startup error.'));
};
app.on('session-created', browser => browser.setCertificateVerifyProc((request, callback) => {
  let fingerprint;
  try { fingerprint = new crypto.X509Certificate(request.certificate.data).fingerprint256; } catch {}
  const accepted = request.hostname === '127.0.0.1' && fingerprint === config.certificateFingerprint;
  if (accepted) certificateChecks++;
  callback(accepted ? 0 : -2);
}));
// Observe actual native presentation, including remembered reopen. The hidden
// POS bridge may exist for internal IPC, but must never become the first page.
let firstVisiblePage = false;
app.on('browser-window-created', (_event, window) => {
  window.on('show', () => {
    try {
      const url = window.webContents.getURL();
      assert.notEqual(url, 'fasakhansta://pos/startup.html', 'The internal startup bridge must stay hidden.');
      if (!firstVisiblePage) {
        assert.equal(url.startsWith(config.origin + '/admin/'), true, 'The first visible native window must contain a real account page.');
        assert.notEqual(url, 'about:blank');
        firstVisiblePage = true;
      }
    } catch (error) { fail(error); }
  });
});
// Register the production shutdown handlers before this fixture's will-quit
// receipt, so a receipt observes the actual ledger-close barrier.
require('../src/main.cjs');

function readState() {
  try { return JSON.parse(safeStorage.decryptString(fs.readFileSync(stateFile))); }
  catch (error) { if (error.code === 'ENOENT') return { next: 0 }; throw error; }
}
function saveState(value) {
  const temporary = stateFile + '.tmp';
  fs.writeFileSync(temporary, safeStorage.encryptString(JSON.stringify(value)), { mode: 0o600 });
  fs.renameSync(temporary, stateFile);
}
function publishReceipt(name, value) {
  const target = path.join(config.root, name), temporary = target + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(value) + '\n', { flag: 'wx' });
  fs.renameSync(temporary, target);
}
const waitFor = async (predicate, message, timeout = 15000) => {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    const result = await predicate();
    if (result) return result;
    await new Promise(resolve => setTimeout(resolve, 25));
  }
  throw Error(message);
};
const preferences = window => {
  const options = window.webContents.getLastWebPreferences();
  assert.equal(options.nodeIntegration, false); assert.equal(options.contextIsolation, true); assert.equal(options.sandbox, true);
};
function hashes(profile) {
  const result = {};
  for (const relative of ['fasakhansta-pos.sqlite', 'legacy-account-sentinel.bin', 'dashboard/credentials.enc', 'dashboard/auth/native-fixture-proof.enc']) {
    result[relative] = sha(fs.readFileSync(path.join(profile, relative)));
  }
  return result;
}
function runtimeFor(descriptor) {
  const runtime = new LocalRuntime({ bundle: path.join(config.root, 'no-bundled-php'), profile: descriptor.profile, safeStorage });
  runtime.origin = 'http://127.0.0.1:33408'; runtime.dbPort = 33409;
  return runtime;
}
async function mainState() {
  const splash = BrowserWindow.getAllWindows().find(window => window.webContents.getURL() === 'fasakhansta://pos/startup.html');
  assert.ok(splash, 'The actual main POS bridge must exist.');
  assert.equal(splash.isVisible(), false, 'Internal POS IPC must not expose the startup loader.');
  return splash.webContents.executeJavaScript('window.POS.state()', true);
}
function switchMenu() {
  const item = Menu.getApplicationMenu()?.items.flatMap(value => value.submenu?.items || []).find(value => value.label === 'تبديل الحساب');
  assert.ok(item && item.enabled, 'Use the production native account menu.');
  item.click(item, BrowserWindow.getFocusedWindow());
}
async function signin(window, actor) {
  assert.equal(new URL(window.webContents.getURL()).pathname, '/admin/login');
  const email = actor === 42 ? 'owner@example.test' : 'manager@example.test';
  await window.webContents.executeJavaScript(`(() => {
    const form = document.querySelector('form[data-dashboard-login]');
    if (!form || typeof window.require !== 'undefined') throw Error('Synthetic original-login contract is absent.');
    form.elements.email.value = ${JSON.stringify(email)};
    form.elements.password.value = 'synthetic-fixture-password';
    form.elements.remember.checked = true;
    form.requestSubmit(); return true;
  })()`, true);
  await waitFor(async () => !window.isDestroyed() && new URL(window.webContents.getURL()).pathname === '/admin/dashboard'
    && await window.webContents.executeJavaScript('document.readyState === "complete"', true), 'The actual original-form sign-in did not finish.');
}
async function accountPage(window, actor) {
  const content = await window.webContents.executeJavaScript(`({actor:document.body.dataset.actor,role:document.body.dataset.role,
    branches:document.body.dataset.branches,allowed:!!document.getElementById('allowed-branch-${actor === 42 ? 100 : 200}'),
    foreign:!!document.getElementById('allowed-branch-${actor === 42 ? 200 : 100}'),node:typeof window.require})`, true);
  assert.equal(content.actor, String(actor)); assert.equal(content.role, actor === 42 ? 'owner' : 'branch_manager');
  assert.equal(content.branches, actor === 42 ? 'f:100' : 'f:200'); assert.equal(content.allowed, true); assert.equal(content.foreign, false);
  assert.equal(content.node, 'undefined');
  const cookies = await window.webContents.session.cookies.get({ url: config.origin });
  const recaller = cookies.find(cookie => cookie.name === config.recaller);
  assert.ok(recaller); assert.equal(recaller.httpOnly, true); assert.equal(recaller.secure, true); assert.equal(recaller.session, false);
  assert.ok(recaller.expirationDate > Date.now() / 1000);
}
function seedJournal(profile, actor) {
  const store = new Store(path.join(profile, 'fasakhansta-pos.sqlite'));
  try {
    assert.equal(store.history().length, 0);
    store.set('fixture-actor', actor);
    store.saveSnapshot({ id: crypto.randomUUID(), generated_at: new Date().toISOString(), branch: { value: actor === 42 ? 'f:100' : 'f:200', name: 'فرع اختبار معزول' },
      actor: { id: actor, name: 'حساب اختبار' }, tax_bps: 0, service_bps: 0, can_discount: false, categories: [],
      products: [{ id: 1, name: 'منتج اختبار', unit: 'piece', variants: [{ option_id: '', label: 'أساسي', unit_price_cents: 100, quantity_mode: 'piece', inventory: {} }] }] });
    const order = store.newOrder();
    store.update(order.id, { ...order.data, items: [{ product_id: 1, option_id: '', quantity_mode: 'piece', quantity: '1' }], cash_received: '1.00' });
    return store.dispatch(order.id, 'sale').id;
  } finally { store.close(); }
}
function acknowledgeFixture(profile, eventId) {
  const store = new Store(path.join(profile, 'fasakhansta-pos.sqlite'));
  try {
    store.acknowledge(eventId, { id: eventId, syntheticFixtureAcknowledgement: true });
    store.db.exec('PRAGMA wal_checkpoint(TRUNCATE)');
  } finally { store.close(); }
}
async function privateState(descriptor, actor) {
  const runtime = runtimeFor(descriptor), settings = await runtime.settings();
  assert.equal(await runtime.isPrepared(), false, 'An added native profile must obtain its own server enrollment.');
  const environment = runtime.nativeEnvironment(settings, runtime.storageFor(null));
  assert.equal(environment.DESKTOP_DASHBOARD_AUTH_STORAGE, path.join(descriptor.profile, 'dashboard', 'auth'));
  fs.mkdirSync(environment.DESKTOP_DASHBOARD_AUTH_STORAGE, { recursive: true });
  const proof = { syntheticOnly: true, actor, deviceId: settings.deviceId, marker: crypto.randomBytes(32).toString('hex') };
  fs.writeFileSync(path.join(environment.DESKTOP_DASHBOARD_AUTH_STORAGE, 'native-fixture-proof.enc'), safeStorage.encryptString(JSON.stringify(proof)), { flag: 'wx' });
  fs.writeFileSync(path.join(descriptor.profile, 'legacy-account-sentinel.bin'), Buffer.alloc(8192, actor), { flag: 'wx' });
  const ciphertext = fs.readFileSync(path.join(descriptor.profile, 'dashboard', 'credentials.enc'));
  for (const secret of [settings.rootPassword, settings.appPassword, settings.appKey]) assert.equal(ciphertext.includes(Buffer.from(secret)), false);
  return { settings, authStorage: environment.DESKTOP_DASHBOARD_AUTH_STORAGE, jwtSecret: environment.JWT_SECRET };
}
async function pickerFor(action, { invokeMenu = true } = {}) {
  if (invokeMenu) switchMenu();
  const pickerURL = pathToFileURL(path.resolve(__dirname, '../src/account-picker.html')).href;
  assert.ok(pickerURL.includes('.asar/'), 'The real picker URL must resolve inside the ASAR.');
  const picker = await waitFor(() => BrowserWindow.getAllWindows().find(window => window.webContents.getURL() === pickerURL), 'The real account picker did not open.');
  await waitFor(() => picker.webContents.executeJavaScript('document.getElementById("account-list").getAttribute("aria-busy") === "false" && !document.getElementById("account-add").disabled', true), 'The real picker bridge did not return its inert account list.');
  await waitFor(() => picker.isVisible(), 'The actual native picker must be visible before capture and interaction.');
  preferences(picker);
  assert.notEqual(picker.webContents.session, session.fromPartition(selected.partition));
  const viewport = await picker.webContents.executeJavaScript(`(() => {
    const box = id => { const r=document.getElementById(id).getBoundingClientRect();return {top:r.top,bottom:r.bottom,width:r.width,height:r.height}; };
    return {height:innerHeight,width:innerWidth,add:box('account-add'),select:box('account-select'),cancel:box('account-cancel'),node:typeof window.require,
      scrollHeight:document.documentElement.scrollHeight,clientHeight:document.documentElement.clientHeight};
  })()`, true);
  assert.equal(viewport.node, 'undefined');
  assert.ok(viewport.scrollHeight <= viewport.clientHeight, 'Only the profile list may scroll, not the outer picker viewport.');
  for (const button of [viewport.add, viewport.select, viewport.cancel]) {
    assert.ok(button.top >= 0 && button.bottom <= viewport.height && button.width > 0 && button.height > 0, 'Every picker action must fit the actual native viewport.');
  }
  fs.writeFileSync(path.join(config.artifactDirectory, 'account-profiles-picker-' + action + '.png'), (await picker.capturePage()).toPNG());
  evidence.actualNativePicker = true; evidence.pickerActionsVisible = true;
  return picker;
}
async function endTransientFixtureSession(window) {
  // Simulate ending this synthetic HTTP client session without server logout;
  // a fresh process must subsequently authenticate with the persistent recaller.
  await window.webContents.session.cookies.remove(config.origin, 'fixture_session');
  const cookies = await window.webContents.session.cookies.get({ url: config.origin });
  assert.equal(cookies.some(cookie => cookie.name === 'fixture_session'), false);
  assert.ok(cookies.some(cookie => cookie.name === config.recaller));
  evidence.transientFixtureSessionEnded = true;
}
async function commitPicker(picker, action) {
  phaseReady = true;
  if (action === 'add') {
    await picker.webContents.executeJavaScript('document.getElementById("account-add").click();true', true).catch(error => { if (!picker.isDestroyed()) throw error; });
  } else {
    await picker.webContents.executeJavaScript(`(() => {
      const row=Array.from(document.querySelectorAll('.account-row')).find(row=>row.querySelector('.account-label').textContent==='مالك اختبار منفصل');
      if(!row)throw Error('The original owner profile is absent.');row.click();document.getElementById('account-select').click();return true;
    })()`, true).catch(error => { if (!picker.isDestroyed()) throw error; });
  }
}

app.once('will-quit', () => {
  clearTimeout(watchdog); if (failed) return;
  stage('will-quit');
  try {
    assert.equal(phaseReady, true); assert.equal(firstVisiblePage, true); assert.equal(BrowserWindow.getAllWindows().length, 0);
    const trace = JSON.parse(fs.readFileSync(path.join(config.profile, 'lifecycle-trace.json'), 'utf8'));
    assert.equal(trace.events.at(-1).phase, 'shutdown-complete', 'The actual main must close its selected ledger before success.');
    const index = JSON.parse(safeStorage.decryptString(fs.readFileSync(path.join(config.profile, 'account-profiles.enc'))));
    assert.equal(phase === 0 ? index.selected !== 'default' : index.selected === 'default', true);
    if (phase < 2) assert.deepEqual(hashes(selected.profile), state[phase === 0 ? 'ownerHashes' : 'otherHashes']);
    const receipt = { format: 1, kind: 'go-erp-account-profile-phase', phase, pid: process.pid, sourceRevision: config.sourceRevision,
      sourceHashes: config.sourceHashes, electronVersion: process.versions.electron, platform: process.platform,
      actualElectron: true, actualProductionMain: true, actualAsarCode: true, actualInstalledApplication: false,
      actualDpapi: true, firstVisibleRealAccountPage: true, startupBridgeNeverShown: true, actualNativeMenu: phase < 2,
      actualNaturalRelaunch: phase < 2, actualUnpreparedProfile: true, actualSelectedLedger: true,
      hardware: false, productionProfile: false, actualPhpAuthorization: false, syntheticRoleProjectionOnly: true,
      syntheticPrivateProofOnly: true, dialogsIntercepted: true, naturalQuit: true, forcedSuccessExit: false,
      certificateChecks, interceptedDialogs: dialogs, ...evidence, completed: true };
    stage('will-quit-validated');publishReceipt('phase-' + phase + '.json', receipt);stage('receipt-written');
  } catch (error) { process.exitCode = 1; process.stderr.write('NATIVE_ACCOUNT_PHASE_FAILURE ' + JSON.stringify(diagnostic(error)) + '\n'); }
});

async function run() {
  await app.whenReady(); assert.equal(app.isPackaged, false); assert.equal(safeStorage.isEncryptionAvailable(), true);
  state = readState(); phase = state.next; assert.ok([0, 1, 2].includes(phase));
  stage('ready');
  publishReceipt('started-' + phase + '.json', { pid: process.pid, phase });
  const profiles = new AccountProfiles({ root: config.profile, safeStorage });
  selected = await profiles.selected();
  const window = await waitFor(() => BrowserWindow.getAllWindows().find(window => window.webContents.getURL().startsWith(config.origin + '/admin/')), 'Production main did not open its dashboard.');
  await waitFor(() => {
    try { return JSON.parse(fs.readFileSync(path.join(config.profile, 'lifecycle-trace.json'), 'utf8')).events.some(value => value.phase === 'startup-complete'); } catch { return false; }
  }, 'Production main startup did not settle.');
  await waitFor(() => window.webContents.executeJavaScript('document.readyState === "complete"', true), 'The dashboard document did not settle.');
  preferences(window); assert.equal(window.webContents.session, session.fromPartition(selected.partition));
  stage('dashboard-ready');
  if (phase === 0) {
    assert.equal(selected.id, 'default'); assert.equal(selected.profile, config.profile);
    assert.equal(new URL(window.webContents.getURL()).pathname, '/admin/login');
    assert.equal((await mainState()).history.length, 0);
    await signin(window, 42); await accountPage(window, 42);stage('owner-authenticated');
    state.owner = await privateState(selected, 42);
    await profiles.bind('default', { actorId: 42, serverOrigin: config.origin, label: 'مالك اختبار منفصل' });
    const eventId = seedJournal(selected.profile, 42); state.ownerEventId = eventId;
    assert.equal((await mainState()).counts.pending, 1);
    expectedDialog = true; switchMenu();
    await waitFor(() => dialogs === 1, 'A pending journal must reject switching before showing the picker.');
    expectedDialog = false;
    assert.equal(BrowserWindow.getAllWindows().some(window => window.webContents.getURL().endsWith('/account-picker.html')), false);
    assert.equal((await profiles.selected()).id, 'default');
    assert.equal((await mainState()).history[0].id, eventId);
    evidence.pendingJournalBlockedSwitch = true;
    acknowledgeFixture(selected.profile, eventId); assert.equal((await mainState()).counts.pending, 0);
    await endTransientFixtureSession(window);
    state.ownerHashes = hashes(selected.profile); state.next = 1; saveState(state);
    stage('owner-add-picker');await commitPicker(await pickerFor('add'), 'add');
  } else if (phase === 1) {
    assert.notEqual(selected.id, 'default'); assert.equal(selected.profile, path.join(config.profile, 'accounts', selected.id));
    assert.notEqual(selected.partition, 'persist:fasakhansta-dashboard');
    assert.equal(new URL(window.webContents.getURL()).pathname, '/admin/login');
    assert.equal((await mainState()).history.length, 0, 'Production main must open the new profile ledger.');
    assert.equal((await window.webContents.session.cookies.get({ url: config.origin })).some(cookie => cookie.name === config.recaller), false);
    assert.ok((await session.fromPartition('persist:fasakhansta-dashboard').cookies.get({ url: config.origin })).some(cookie => cookie.name === config.recaller));
    assert.deepEqual(hashes(config.profile), state.ownerHashes);
    await signin(window, 84); await accountPage(window, 84);stage('manager-authenticated');
    state.other = await privateState(selected, 84); state.otherId = selected.id; state.otherProfile = selected.profile; state.otherPartition = selected.partition;
    for (const key of ['deviceId', 'rootPassword', 'appPassword', 'appKey']) assert.ok(state.owner.settings[key] !== state.other.settings[key], 'Profiles must generate distinct ' + key);
    assert.notEqual(state.owner.authStorage, state.other.authStorage); assert.ok(state.owner.jwtSecret !== state.other.jwtSecret, 'Native signing keys must be separate.');
    await profiles.bind(selected.id, { actorId: 84, serverOrigin: config.origin, label: 'مدير اختبار منفصل' });
    const eventId = seedJournal(selected.profile, 84); state.otherEventId = eventId;
    const ledger = await mainState(); assert.equal(ledger.history.length, 1); assert.equal(ledger.history[0].id, eventId);
    assert.notEqual(eventId, state.ownerEventId); acknowledgeFixture(selected.profile, eventId);
    await endTransientFixtureSession(window);
    state.otherHashes = hashes(selected.profile); state.next = 2; saveState(state);
    evidence.distinctDevicesAndSecrets = true; evidence.distinctPrivateAuthStorage = true; evidence.emptyNewCookiePartition = true;
    evidence.ownerDurableBytesUnchanged = true;
    stage('manager-select-picker');await commitPicker(await pickerFor('select'), 'select');
  } else {
    assert.equal(selected.id, 'default'); assert.equal(selected.profile, config.profile);
    await accountPage(window, 42);
    const restoredRuntime = runtimeFor(selected);
    assert.equal(await restoredRuntime.isPrepared(), false);
    assert.equal(sha(JSON.stringify(await restoredRuntime.settings())), sha(JSON.stringify(state.owner.settings)), 'The original encrypted native settings must be restored exactly.');
    assert.deepEqual(hashes(config.profile), state.ownerHashes); assert.deepEqual(hashes(state.otherProfile), state.otherHashes);
    const ledger = await mainState(); assert.equal(ledger.history.length, 1); assert.equal(ledger.history[0].id, state.ownerEventId);
    assert.equal(ledger.history[0].acked, 1); assert.equal(ledger.counts.pending, 0);
    const otherCookies = await session.fromPartition(state.otherPartition).cookies.get({ url: config.origin });
    const ownerCookie = (await window.webContents.session.cookies.get({ url: config.origin })).find(cookie => cookie.name === config.recaller);
    assert.ok(otherCookies.find(cookie => cookie.name === config.recaller)?.value !== ownerCookie.value, 'Remembered capabilities must remain separate.');
    assert.ok(otherCookies.some(cookie => cookie.name === config.recaller));
    const indexFile = path.join(config.profile, 'account-profiles.enc'), originalIndex = fs.readFileSync(indexFile);
    await assert.rejects(profiles.assertBinding('default', { actorId: 84, serverOrigin: config.origin }), /لا يطابق/);
    await assert.rejects(profiles.assertBinding(state.otherId, { actorId: 42, serverOrigin: config.origin }), /لا يطابق/);
    await assert.rejects(profiles.assertBinding('default', { actorId: 42, serverOrigin: 'https://foreign.invalid' }), /لا يطابق/);
    assert.equal(fs.readFileSync(indexFile).equals(originalIndex), true);
    const damaged = Buffer.from(originalIndex); damaged[damaged.length - 1] ^= 1; fs.writeFileSync(indexFile, damaged);
    try { await assert.rejects(profiles.selected(), /تعذر التحقق/); }
    finally { fs.writeFileSync(indexFile, originalIndex); }
    assert.equal((await profiles.selected()).id, 'default'); assert.equal(fs.readFileSync(indexFile).equals(originalIndex), true);
    assert.deepEqual(hashes(config.profile), state.ownerHashes); assert.deepEqual(hashes(state.otherProfile), state.otherHashes);
    evidence.ownerDurableBytesUnchanged = true; evidence.otherDurableBytesUnchanged = true; evidence.originalJournalRestored = true;
    evidence.persistentCookiePartitionsIsolated = true; evidence.ownerRememberedWithoutCredentialPost = true;
    evidence.actorBindingMismatchRejected = true; evidence.corruptEncryptedRegistryRejected = true;
    // Exercise the exact native viewport at maximum renderer-list capacity
    // without inventing sixty-two real enrolled profiles or changing selection.
    const capacity = [{ id: 'default', label: 'الحساب الحالي', selected: true },
      ...Array.from({ length: 63 }, () => ({ id: crypto.randomUUID(), label: 'اسم <img src=x onerror=alert(1)> '.repeat(4).slice(0, 120), selected: false }))];
    const choose = require('../src/account-picker.cjs')({ parent: window, profiles: capacity });
    const picker = await pickerFor('capacity', { invokeMenu: false });stage('capacity-picker');
    const capacityState = await picker.webContents.executeJavaScript(`({rows:document.querySelectorAll('.account-row').length,
      images:document.querySelectorAll('img').length,scroll:document.getElementById('account-list').scrollHeight>document.getElementById('account-list').clientHeight})`, true);
    assert.deepEqual(capacityState, { rows: 64, images: 0, scroll: true });
    stage('capacity-cancel-requested');
    // The real native IPC action destroys this renderer. Its executeJavaScript
    // reply can disappear with the frame; completion is the actual chooser
    // result, not a response from the intentionally destroyed document.
    picker.webContents.executeJavaScript('document.getElementById("account-cancel").click();true', true)
      .catch(error => { if (!picker.isDestroyed()) fail(error); });
    assert.equal(await choose, null); assert.equal((await profiles.selected()).id, 'default');stage('capacity-cancelled');
    evidence.capacity64PickerActionsVisible = true; evidence.labelsRenderedAsText = true;
    phaseReady = true;stage('quit-requested'); app.quit();
  }
}
run().catch(fail);
