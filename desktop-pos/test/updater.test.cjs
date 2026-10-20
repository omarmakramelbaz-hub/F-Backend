'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { DashboardUpdater, validateManifest, compareVersions, POLL_MS, MANIFEST_TIMEOUT_MS, INSTALLER_TIMEOUT_MS } = require('../src/dashboard-updater.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const bytes = Buffer.from('MZ-test-only-installer-payload');
const sha = crypto.createHash('sha256').update(bytes).digest('hex');
const origin = 'https://erp.example.test';
const credential = { serverOrigin: origin, token: 'a'.repeat(64) };
const oldSource = { format: 1, files: 2, sha256: '1'.repeat(64), framework: '8.83.29' };
const newSource = { ...oldSource, files: 3, sha256: '2'.repeat(64) };
function release(sequence = 10, version = '0.4.4-preview.6') {
  return { format: 1, poll_seconds: 60, state: 'ready', server_source: clone(newSource), release: {
    version, sequence, source_revision: 'b'.repeat(40), source: clone(newSource),
    installer: { sha256: sha, bytes: bytes.length, path: '/api/desktop-dashboard/update/installer/' + sha }
  } };
}
const unavailable = () => ({ format: 1, poll_seconds: 60, state: 'unavailable', server_source: clone(newSource), release: null });
const json = value => new Response(JSON.stringify(value), { status: 200, headers: { 'content-type': 'application/json' } });
async function fixture(t, options = {}) {
  const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'go-updater-test-'));
  t.after(() => fs.rm(profile, { recursive: true, force: true }));
  const records = options.records || new Map(), requests = [], applied = [], states = [], timers = [];
  let manifest = options.manifest || release(), gates = 0;
  const metadata = { read: async name => records.has(name) ? clone(records.get(name)) : null,
    write: async (name, value) => { records.set(name, clone(value)); } };
  const updater = new DashboardUpdater({ profile, metadata, credential: options.credential || (() => credential),
    currentVersion: options.currentVersion || '0.4.4-preview.5', currentSource: options.currentSource || oldSource,
    currentRevision: Object.hasOwn(options, 'currentRevision') ? options.currentRevision : 'a'.repeat(40),
    canApply: async () => { gates++; return options.canApply ? options.canApply(gates, profile) : true; },
    apply: async candidate => { applied.push(candidate); if (options.apply) return options.apply(candidate); },
    onState: value => states.push(clone(value)),
    setTimer: (fn, delay) => { const timer = { fn, delay }; timers.push(timer); return timer; },
    clearTimer: timer => { if (timer) timer.cancelled = true; },
    fetch: async (url, config) => {
      requests.push({ url, config });
      if (options.fetch) return options.fetch(url, config, requests.length, profile);
      return url.endsWith('/update') ? json(typeof manifest === 'function' ? manifest(requests.length) : manifest) : new Response(bytes, { status: 200 });
    } });
  t.after(() => updater.stop());
  return { profile, updater, requests, applied, states, records, timers, gates: () => gates, setManifest: value => { manifest = value; } };
}
const installer = f => path.join(f.profile, 'updates', sha + '.exe');
const installed = () => ({ sourceRevision: 'b'.repeat(40), sourceFingerprint: clone(newSource) });
async function authorizationFixture(t) {
  const f = await fixture(t, { currentVersion: '0.4.4-preview.6', currentSource: newSource, currentRevision: 'b'.repeat(40) });
  await f.updater.start(); return f;
}

test('installed runtime authorization fresh-checks exact revision, version and source without downloading', async t => {
  const f = await authorizationFixture(t), state = clone(f.updater.state), states = f.states.length;
  assert.equal(await f.updater.authorizeInstalled(installed()), true);
  assert.equal(f.requests.length, 2); assert.equal(f.requests.every(item => item.url.endsWith('/api/desktop-dashboard/update')), true);
  assert.equal(f.applied.length, 0); assert.equal(f.gates(), 0); assert.equal(f.states.length, states);
  assert.deepEqual(f.updater.state, state);
  await assert.rejects(fs.access(path.join(f.profile, 'updates')), { code: 'ENOENT' });
});

test('installed runtime authorization refuses unopened, stopped and invalid runtime candidates', async t => {
  const f = await fixture(t);
  assert.equal(await f.updater.authorizeInstalled(installed()), false); assert.equal(f.requests.length, 0);
  f.setManifest(unavailable()); await f.updater.start(); const calls = f.requests.length;
  for (const candidate of [null, {}, { ...installed(), sourceRevision: 'x'.repeat(40) }, { ...installed(), sourceFingerprint: null }])
    assert.equal(await f.updater.authorizeInstalled(candidate), false);
  assert.equal(f.requests.length, calls);
  f.updater.stop(); assert.equal(await f.updater.authorizeInstalled(installed()), false); assert.equal(f.requests.length, calls);
});

test('installed authorization refuses stale, revoked, incompatible and rebound server authority', async t => {
  const cases = [
    f => f.setManifest(unavailable()),
    f => f.setManifest(release(9)),
    f => { const manifest = release(11); manifest.release.source_revision = 'c'.repeat(40); f.setManifest(manifest); },
    f => { const manifest = release(11); manifest.release.source = { ...newSource, sha256: '3'.repeat(64) }; manifest.server_source = clone(manifest.release.source); f.setManifest(manifest); },
    f => f.setManifest(release(11, '0.4.4-preview.7')),
    f => { f.updater.currentRevision = async () => 'c'.repeat(40); },
    f => { let calls = 0; f.updater.credential = () => ({ ...credential, token: (++calls === 1 ? 'a' : 'c').repeat(64) }); }
  ];
  for (const change of cases) {
    const f = await authorizationFixture(t), state = clone(f.updater.state); change(f);
    assert.equal(await f.updater.authorizeInstalled(installed()), false); assert.equal(f.applied.length, 0);
    assert.deepEqual(f.updater.state, state); assert.equal(f.requests.some(item => item.url.includes('/installer/')), false);
  }
});

test('authorization transport and metadata errors fail closed without business-state errors', async t => {
  const f = await authorizationFixture(t), state = clone(f.updater.state);
  const transport = f.updater.transport; f.updater.transport = async () => { throw Error('offline'); };
  assert.equal(await f.updater.authorizeInstalled(installed()), false); assert.deepEqual(f.updater.state, state);
  f.updater.transport = transport;
  f.records.set([...f.records.keys()][0], { broken: true });
  assert.equal(await f.updater.authorizeInstalled(installed()), false); assert.deepEqual(f.updater.state, state);
});

test('stop aborts independent installed-runtime authorization readers', async t => {
  const f = await authorizationFixture(t);
  let entered; const started = new Promise(resolve => { entered = resolve; }); let cancelled = false;
  f.updater.transport = async () => new Response(new ReadableStream({ start() { entered(); }, cancel() { cancelled = true; } }));
  const authorization = f.updater.authorizeInstalled(installed()); await started; f.updater.stop();
  assert.equal(await authorization, false); assert.equal(cancelled, true); assert.equal(f.updater.authorizations.size, 0);
  assert.equal(f.applied.length, 0);
});

test('parallel authorization cannot race a newer watermark back to an older release', async t => {
  const f = await authorizationFixture(t); let calls = 0;
  f.updater.transport = async () => json(release(++calls === 1 ? 12 : 11));
  const result = await Promise.all([f.updater.authorizeInstalled(installed()), f.updater.authorizeInstalled(installed())]);
  assert.deepEqual(result, [true, false]); assert.equal([...f.records.values()][0].sequence, 12);
});

test('verified same-origin release is rechecked and handed off once without exposing credentials', async t => {
  const f = await fixture(t); await f.updater.check();
  assert.equal(f.applied.length, 1); assert.equal(f.gates(), 2);
  assert.equal(f.applied[0].file, installer(f)); assert.deepEqual(await fs.readFile(installer(f)), bytes);
  assert.deepEqual(f.requests.map(item => item.url), [origin + '/api/desktop-dashboard/update',
    origin + release().release.installer.path, origin + '/api/desktop-dashboard/update']);
  for (const { config } of f.requests) {
    assert.equal(config.redirect, 'error'); assert.equal(config.credentials, 'omit'); assert.equal(config.cache, 'no-store');
    assert.equal(config.headers.Authorization, 'Bearer ' + credential.token);
  }
  assert.equal(JSON.stringify(f.states).includes(credential.token), false);
  assert.equal(JSON.stringify(f.states).includes(f.profile), false);
  await f.updater.check(); assert.equal(f.applied.length, 1);
  assert.equal([...f.records.values()][0].sequence, 10);
});

test('busy device retains verified stage and later reuses it without another download', async t => {
  let idle = false; const f = await fixture(t, { canApply: () => idle });
  await f.updater.check(); assert.equal(f.updater.state.status, 'staged'); assert.equal(f.applied.length, 0);
  assert.deepEqual(await fs.readFile(installer(f)), bytes); idle = true;
  await f.updater.check(); assert.equal(f.applied.length, 1);
  assert.equal(f.requests.filter(item => item.url.includes('/installer/')).length, 1);
});

test('a device becoming busy at the second gate cannot run the installer', async t => {
  const f = await fixture(t, { canApply: gate => gate === 1 }); await f.updater.check();
  assert.equal(f.gates(), 2); assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'staged');
  assert.deepEqual(await fs.readFile(installer(f)), bytes);
});

test('serialized installer handoff may defer without marking the candidate applied', async t => {
  let defer = true;
  const f = await fixture(t, { apply: () => ({ deferred: defer }) });
  await f.updater.check(); assert.equal(f.updater.state.status, 'staged'); assert.equal(f.applied.length, 1);
  defer = false; await f.updater.check(); assert.equal(f.applied.length, 2); assert.equal(f.updater.state.status, 'installing');
  await f.updater.check(); assert.equal(f.applied.length, 2);
});

test('application shutdown during a successful installer handoff preserves success', async t => {
  const f = await fixture(t); f.updater.apply = async candidate => { f.applied.push(candidate); f.updater.stop(); return { installing: true }; };
  await f.updater.start(); assert.equal(f.applied.length, 1); assert.equal(f.updater.state.status, 'installing');
  assert.equal(f.timers.length, 0);
});

test('truthy non-boolean idle results do not authorize installation', async t => {
  const f = await fixture(t, { canApply: () => ({ idle: true }) }); await f.updater.check();
  assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'staged');
});

test('revoked ready feed keeps the stage and cannot apply', async t => {
  const f = await fixture(t, { manifest: call => call < 3 ? release() : unavailable() }); await f.updater.check();
  assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'unavailable');
  assert.deepEqual(await fs.readFile(installer(f)), bytes);
});

test('replacement feed during download is observed as a higher watermark and cannot apply stale stage', async t => {
  const f = await fixture(t, { manifest: call => call < 3 ? release() : release(11, '0.4.4-preview.7') });
  await f.updater.check(); assert.equal(f.applied.length, 0);
  assert.equal([...f.records.values()][0].sequence, 11);
  f.setManifest(release()); await f.updater.check(); assert.equal(f.updater.state.status, 'error');
});

test('corrupt download is rejected and unique temporary data is removed', async t => {
  const f = await fixture(t, { fetch: url => url.endsWith('/update') ? json(release()) : new Response(Buffer.alloc(bytes.length, 1)) });
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
  assert.deepEqual(await fs.readdir(path.join(f.profile, 'updates')), []);
});

test('installer stream cannot exceed pinned size without a content-length header', async t => {
  const f = await fixture(t, { fetch: url => url.endsWith('/update') ? json(release()) : new Response(Buffer.concat([bytes, bytes])) });
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
  assert.deepEqual(await fs.readdir(path.join(f.profile, 'updates')), []);
});

test('short installer and false declared size are rejected', async t => {
  for (const headers of [{}, { 'content-length': String(bytes.length + 1) }]) {
    const f = await fixture(t, { fetch: url => url.endsWith('/update') ? json(release()) : new Response(bytes.subarray(1), { headers }) });
    await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
  }
});

test('redirects and mismatched final URLs are refused for feed and installer', async t => {
  for (const location of ['manifest', 'installer']) for (const field of ['redirected', 'url']) {
    const f = await fixture(t, { fetch: url => {
      const feed = url.endsWith('/update'), response = feed ? json(release()) : new Response(bytes);
      if (feed === (location === 'manifest')) Object.defineProperty(response, field, { value: field === 'redirected' ? true : 'https://other.example.test/payload' });
      return response;
    } });
    await f.updater.check(); assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'error');
  }
});

test('mutation after staging is caught by pre-install rehash', async t => {
  const f = await fixture(t, { canApply: async (gate, profile) => {
    if (gate === 1) await fs.writeFile(path.join(profile, 'updates', sha + '.exe'), Buffer.alloc(bytes.length, 1));
    return true;
  } });
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0); assert.equal(f.gates(), 1);
});

test('sequence downgrade is rejected after a process restart', async t => {
  const records = new Map(), first = await fixture(t, { records, canApply: () => false }); await first.updater.check();
  const second = await fixture(t, { records, manifest: release(9) }); await second.updater.check();
  assert.equal(second.updater.state.status, 'error'); assert.equal(second.applied.length, 0); assert.equal(second.requests.length, 1);
});

test('malformed persisted watermark fails closed before download', async t => {
  const f = await fixture(t, { canApply: () => false }); await f.updater.check();
  f.records.set([...f.records.keys()][0], { format: 1, sequence: '10', digest: sha, version: '0.4.4-preview.6' });
  const downloads = f.requests.filter(item => item.url.includes('/installer/')).length;
  await f.updater.check(); assert.equal(f.updater.state.status, 'error');
  assert.equal(f.requests.filter(item => item.url.includes('/installer/')).length, downloads);
});

test('same sequence cannot describe divergent release bytes or metadata', async t => {
  const records = new Map(), f = await fixture(t, { records, canApply: () => false }); await f.updater.check();
  const altered = release(); altered.release.source_revision = 'c'.repeat(40); f.setManifest(altered);
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
});

test('higher sequence cannot downgrade accepted semantic version', async t => {
  const f = await fixture(t, { canApply: () => false }); await f.updater.check(); f.setManifest(release(11, '0.4.4-preview.4'));
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal([...f.records.values()][0].sequence, 10);
});

test('installed newer version is never replaced by an older manifest', async t => {
  const f = await fixture(t, { currentVersion: '0.5.0' }); await f.updater.check();
  assert.equal(f.updater.state.status, 'error'); assert.equal(f.requests.length, 1); assert.equal(f.applied.length, 0);
});

test('unavailable feed and already matching installed source do not download', async t => {
  const missing = await fixture(t, { manifest: unavailable() }); await missing.updater.check();
  assert.equal(missing.updater.state.status, 'unavailable'); assert.equal(missing.requests.length, 1);
  const current = await fixture(t, { currentVersion: '0.4.4-preview.6', currentSource: newSource, currentRevision: 'b'.repeat(40) }); await current.updater.check();
  assert.equal(current.updater.state.status, 'current'); assert.equal(current.requests.length, 1);
});

test('same version with a different installed source still requires the verified source repair', async t => {
  const f = await fixture(t, { currentVersion: '0.4.4-preview.6' }); await f.updater.check(); assert.equal(f.applied.length, 1);
});

test('same version and core fingerprint still update for changed GUI or native revision', async t => {
  const f = await fixture(t, { currentVersion: '0.4.4-preview.6', currentSource: newSource, currentRevision: async () => 'c'.repeat(40) });
  await f.updater.check(); assert.equal(f.applied.length, 1); assert.equal(f.updater.state.status, 'installing');
});

test('missing or invalid installed revision fails closed', async t => {
  for (const currentRevision of [undefined, null, 'b'.repeat(39), ['b'.repeat(40)]]) {
    await assert.rejects(() => fixture(t, { currentRevision }));
  }
  const f = await fixture(t, { currentVersion: '0.4.4-preview.6', currentSource: newSource, currentRevision: async () => undefined });
  await f.updater.check(); assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
  assert.equal(f.requests.length, 1);
});

test('installer gets a thirty-minute deadline while feed and poll stay at sixty seconds', async t => {
  const deadlines = [];
  t.mock.method(AbortSignal, 'timeout', milliseconds => { deadlines.push(milliseconds); return new AbortController().signal; });
  const f = await fixture(t); await f.updater.check();
  assert.equal(MANIFEST_TIMEOUT_MS, 60000); assert.equal(INSTALLER_TIMEOUT_MS, 1800000); assert.equal(POLL_MS, 60000);
  assert.deepEqual(deadlines, [MANIFEST_TIMEOUT_MS, INSTALLER_TIMEOUT_MS, MANIFEST_TIMEOUT_MS]);
  assert.equal(f.applied.length, 1);
});

test('installer deadline cancels a stalled stream without waiting for the next poll', async t => {
  const deadlines = [];
  t.mock.method(AbortSignal, 'timeout', milliseconds => { const controller = new AbortController(); deadlines.push({ milliseconds, controller }); return controller.signal; });
  let entered; const started = new Promise(resolve => { entered = resolve; }); let cancelled = false;
  const f = await fixture(t, { fetch: url => url.endsWith('/update') ? json(release()) : new Response(new ReadableStream({
    start() { entered(); }, cancel() { cancelled = true; }
  })) });
  const check = f.updater.check(); await started;
  assert.equal(deadlines[1].milliseconds, INSTALLER_TIMEOUT_MS); deadlines[1].controller.abort(); await check;
  assert.equal(cancelled, true); assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'error');
  assert.deepEqual(await fs.readdir(path.join(f.profile, 'updates')), []);
});

test('rebound enrollment cannot apply a file downloaded for the old binding', async t => {
  let calls = 0; const f = await fixture(t, { credential: () => ({ ...credential, token: (++calls === 1 ? 'a' : 'c').repeat(64) }) });
  await f.updater.check(); assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'error');
});

test('check joins one in-flight operation and stop aborts a blocked stream', async t => {
  let entered; const started = new Promise(resolve => { entered = resolve; }); let cancelled = false;
  const f = await fixture(t, { fetch: () => new Response(new ReadableStream({
    start() { entered(); }, cancel() { cancelled = true; }
  })) });
  const first = f.updater.start(); assert.equal(first, f.updater.check()); await started;
  f.updater.stop(); await first;
  assert.equal(cancelled, true); assert.equal(f.applied.length, 0); assert.equal(f.timers.length, 0);
});

test('automatic poll schedules sixty seconds after completion without overlap or post-stop restart', async t => {
  const f = await fixture(t, { manifest: unavailable() }); await f.updater.start();
  assert.equal(f.timers.length, 1); assert.equal(f.timers[0].delay, POLL_MS);
  f.timers[0].fn(); await f.updater.running;
  assert.equal(f.requests.length, 2); assert.equal(f.timers.length, 2);
  f.updater.stop(); assert.equal(f.timers[1].cancelled, true);
});

test('symlink stage cannot redirect installer verification outside updates', async t => {
  const f = await fixture(t); const other = path.join(f.profile, 'untouched.dat'); await fs.writeFile(other, bytes);
  await fs.mkdir(path.join(f.profile, 'updates')); await fs.symlink(other, installer(f));
  await f.updater.check(); assert.equal(f.applied.length, 0); assert.equal(f.updater.state.status, 'error');
  assert.deepEqual(await fs.readFile(other), bytes);
});

test('oversized JSON is bounded even when content-length is absent', async t => {
  const f = await fixture(t, { fetch: () => new Response(' '.repeat(16385)) }); await f.updater.check();
  assert.equal(f.updater.state.status, 'error'); assert.equal(f.applied.length, 0);
});

test('unencrypted origins and noncanonical credential URLs never receive a token', async t => {
  for (const serverOrigin of ['http://erp.example.test', origin + '/', 'https://user@erp.example.test', origin + '/path']) {
    const f = await fixture(t, { credential: () => ({ ...credential, serverOrigin }) });
    await f.updater.check(); assert.equal(f.requests.length, 0); assert.equal(f.updater.state.status, 'error');
  }
});

test('manifest protocol rejects unbound sources, arbitrary paths and malformed metadata', () => {
  const changes = [
    value => { value.extra = true; }, value => { value.poll_seconds = 1; },
    value => { value.release.source.sha256 = '3'.repeat(64); }, value => { value.release.sequence = 0; },
    value => { value.release.installer.path = 'https://other.example.test/setup.exe'; },
    value => { value.release.installer.path += '/../setup.exe'; }, value => { value.release.installer.bytes = 1024 ** 3 + 1; },
    value => { value.release.source_revision = ['b'.repeat(40)]; }, value => { value.server_source.sha256 = ['2'.repeat(64)]; },
    value => { value.release.version = '0.4.4-preview.06'; }, value => { value.release.version = '999999999999999999.1.1'; },
    value => { value.release.installer.sha256 = 'A'.repeat(64); }, value => { value.state = 'unavailable'; }
  ];
  for (const change of changes) { const value = release(); change(value); assert.throws(() => validateManifest(value)); }
  assert.equal(validateManifest(release()).state, 'ready'); assert.equal(validateManifest(unavailable()).state, 'unavailable');
});

test('release precedence handles preview identifiers and stable versions', () => {
  assert.equal(compareVersions('0.4.4-preview.10', '0.4.4-preview.9'), 1);
  assert.equal(compareVersions('0.4.4', '0.4.4-preview.10'), 1);
  assert.equal(compareVersions('0.4.4-preview.6+a', '0.4.4-preview.6+b'), 0);
  assert.equal(compareVersions('0.4.5-preview.1', '0.4.4'), 1);
  assert.equal(compareVersions('0.4.4-alpha', '0.4.4-preview'), -1);
});
