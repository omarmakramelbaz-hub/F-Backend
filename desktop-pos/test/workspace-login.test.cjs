'use strict';
const crypto = require('node:crypto');
const test = require('node:test'), assert = require('node:assert/strict'), http = require('node:http'), fs = require('node:fs/promises'), path = require('node:path');
const { WorkspaceRuntime } = require('../src/workspace-runtime.cjs');
const { WorkspaceLogin } = require('../src/workspace-login.cjs');
const { originalSignin, installCookies } = require('../src/account-login.cjs');
const signout = require('../src/account-signout.cjs');
const { WorkspaceOnlineLogin } = require('../src/workspace-online-login.cjs');
const { WorkspaceHTTP } = require('../src/workspace-http.cjs');
const { digest } = require('../src/workspace-profile.cjs');
const { fixture } = require('./helpers/workspace-fixture.cjs');
const csrf = 'fixture_csrf_token_1234567890', localToken = 'native-private-local-token';
async function setup(t) {
  const f = await fixture(t), log = [], clients = new Map(); let refusal = false, broken = false, serverPassword = 'correct', serverManifest = f.value.manifest;
  const accounts = new Map([['owner@example.test', { actorId: 101, role: 'admin' }], ['branch@example.test', { actorId: 202, role: 'vendor' }],
    ['new@example.test', { actorId: 303, role: 'vendor' }]]);
  const server = http.createServer(async (req, res) => {
    const remote = req.headers['x-fixture-server'] === 'true'; log.push({ remote, path: req.url, method: req.method });
    let body = ''; for await (const chunk of req) body += chunk;
    if (req.url === '/admin/login') { res.setHeader('content-type', 'text/html'); res.end('<form data-dashboard-login method="POST" action="/admin/signin"><input name="_token" value="' + csrf + '"></form>'); return; }
    if (req.url === '/admin/signin') {
      const fields = new URLSearchParams(body), actor = accounts.get(fields.get('email'));
      if (!actor || fields.get('password') !== (remote ? serverPassword : 'correct') || remote && refusal) { res.statusCode = 302; res.setHeader('location', '/admin/login'); res.end(); return; }
      res.statusCode = 302; res.setHeader('location', '/admin/dashboard');
      res.setHeader('set-cookie', 'go_erp_session=' + actor.actorId + '; Path=/; HttpOnly; SameSite=Lax'); res.end(); return;
    }
    if (req.url === '/api/desktop-dashboard/workspace/manifest') {
      assert.equal(req.headers.authorization, 'Bearer ' + f.value.credential.token);
      res.setHeader('content-type', 'application/json'); res.end(JSON.stringify(serverManifest)); return;
    }
    const actorId = Number(/(?:^|; )go_erp_session=([0-9]+)/.exec(req.headers.cookie || '')?.[1]), actor = [...accounts.values()].find(a => a.actorId === actorId);
    if (!actor) { res.statusCode = 401; res.end(); return; }
    if (req.url === '/admin/choose_type') { res.end('<body data-dashboard-actor="' + actor.actorId + ':' + actor.role + '"></body>'); return; }
    if (req.url === '/admin/desktop-dashboard/workspace/local-session') {
      assert.equal(req.headers['x-fasakhansta-desktop'], localToken);
      res.setHeader('content-type', 'application/json'); res.end(JSON.stringify({ protocol: 2, workspace_id: f.registry.workspaceId,
        actor_id: actor.actorId, role: actor.role, custodian_actor_id: 101, manifest_revision: 1, root_fingerprint: f.value.manifest.root_fingerprint,
        native_session: { format: 2, kind: 'desktop-workspace-native-session', workspace_id: f.registry.workspaceId,
          root_fingerprint: f.value.manifest.root_fingerprint, actor_id: actor.actorId, manifest_revision: 1,
          password_binding: f.value.manifest.members.find(m => m.actor_id === actor.actorId).password_binding,
          snapshot_id: f.value.candidate.snapshotId, schema_hash: f.value.candidate.schemaHash,
          control_binding: crypto.createHash('sha256').update(workspace.physical.controlToken).digest('hex'),
          nonce: '1'.repeat(64), mac: '2'.repeat(64) } })); return;
    }
    res.statusCode = 404; res.end();
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); t.after(() => new Promise(resolve => server.close(resolve)));
  const localOrigin = 'http://127.0.0.1:' + server.address().port;
  function createSession(name) {
    if (clients.has(name)) return clients.get(name);
    let saved = [];
    const browser = { cookies: {
      async get(query) { return structuredClone(saved.filter(c => (!query.name || c.name === query.name) && (!query.url || new URL(query.url).hostname === c.domain))); },
      async set(value) { const domain = new URL(value.url).hostname; saved = saved.filter(c => c.name !== value.name || c.domain !== domain);
        saved.push({ ...value, domain, session: value.expirationDate === undefined, secure: value.secure === true, httpOnly: value.httpOnly === true }); },
      async remove(origin, name) { const host = new URL(origin).hostname; saved = saved.filter(c => c.name !== name || c.domain !== host); }, async flushStore() {} },
      async clearStorageData() { saved = []; },
      async fetch(url, options = {}) {
        const target = new URL(url), remote = target.protocol === 'https:';
        if (remote && broken) throw Object.assign(Error('network down'), { code: 'ENETUNREACH' });
        const cookie = saved.filter(c => c.domain === target.hostname).map(c => c.name + '=' + c.value).join('; ');
        const response = await fetch(localOrigin + target.pathname, { ...options, headers: { ...options.headers, Cookie: cookie,
          ...(remote ? { 'X-Fixture-Server': 'true' } : {}) }, redirect: 'manual' });
        for (const raw of response.headers.getSetCookie()) {
          const [pair] = raw.split(';'), equal = pair.indexOf('='); await browser.cookies.set({ url: target.origin, name: pair.slice(0, equal), value: pair.slice(equal + 1), path: '/', httpOnly: true, secure: remote });
        }
        Object.defineProperty(response, 'url', { value: url }); return response;
      }
    }; clients.set(name, browser); return browser;
  }
  const workspace = new WorkspaceRuntime({ registry: f.registry, bundle: path.join(f.root, 'unused-source'), safeStorage: f.safeStorage,
    authorizeSource: async () => { throw Error('unexpected source update'); }, verifyActive: async () => true });
  workspace.start = async () => ({ origin: localOrigin, token: localToken });
  const locked = [];
  const create = (online = () => false) => new WorkspaceLogin({ workspace, createSession, online, onLocked: async value => { locked.push(value); },
    verifyLocalSession: async ({ browser, local }, { signal }) => {
      const response = await browser.fetch(local.origin + '/admin/desktop-dashboard/workspace/local-session', { headers: { 'X-Fasakhansta-Desktop': local.token }, signal }); return response.json();
    }, onlineFallback: async (credentials, { signal }) => {
      const browser = createSession('fallback-' + Math.random()), actor = await originalSignin({ browser, origin: f.value.credential.serverOrigin, ...credentials, signal });
      const target = createSession('online-' + actor.actorId); await installCookies(browser, target, f.value.credential.serverOrigin);
      return { local: false, origin: f.value.credential.serverOrigin, actor, cachedPasswordRejected: credentials.cachedPasswordRejected }; 
    } });
  const createOnline = () => new WorkspaceOnlineLogin({ workspace, createSession,
    http: new WorkspaceHTTP({ serverOrigin: f.value.credential.serverOrigin, transport: createSession('root-transport').fetch, serverSession: () => createSession('unused-server') }),
    onLocked: async value => { locked.push(value); }, markServerView: async () => { await workspace.metadata.write('test-shared-server-fence', { dirty: true }); } });
  return { ...f, workspace, createSession, clients, log, locked, create, createOnline, localOrigin,
    setServerPassword: value => { serverPassword = value; }, setServerManifest: value => { serverManifest = value; }, setRefusal: value => { refusal = value; }, setBroken: value => { broken = value; } };
}
const credentials = email => ({ email, password: 'correct', remember: true });

test('one cached preparation allows first-ever offline B/A/B password sign-ins without one server request or another runtime', async t => {
  const f = await setup(t), login = f.create();
  await assert.rejects(fs.stat(path.join(f.root, 'workspace-actors')), { code: 'ENOENT' });
  for (const [email, actorId] of [['branch@example.test', 202], ['owner@example.test', 101], ['branch@example.test', 202]]) {
    const result = await login.authenticate(credentials(email)); assert.equal(result.actor.actorId, actorId); assert.equal(await result.runtime.authenticated(), true);
    assert.equal((await result.runtime.connection()).actorId, 101); assert.equal((await result.runtime.authority()).actorId, actorId);
    await result.afterView();
  }
  assert.equal(f.log.filter(row => row.remote).length, 0);
  assert.equal(f.log.filter(row => row.path === '/admin/signin').length, 3);
  assert.equal(f.log.filter(row => row.path.endsWith('/workspace/local-session')).length, 3);
  assert.equal(f.log.some(row => row.path === '/admin/dashboard'), false, 'Authentication never secretly calculates the expensive home.');
  assert.deepEqual((await fs.readdir(path.join(f.root, 'workspaces', f.registry.workspaceId))).sort(), []);
  await login.stop();
});

test('wrong passwords and forged authenticated context fail before persistent actor sessions or cookies', async t => {
  const f = await setup(t), login = f.create();
  await assert.rejects(login.authenticate({ ...credentials('branch@example.test'), password: 'wrong' }), { code: 'ACCOUNT_CREDENTIALS_REJECTED' });
  await assert.rejects(fs.stat(path.join(f.root, 'workspace-actors')), { code: 'ENOENT' });
  login.verifyLocalSession = async () => ({ protocol: 2, actor_id: 101 });
  await assert.rejects(login.authenticate(credentials('branch@example.test')), { code: 'WORKSPACE_CREDENTIALS_REJECTED' });
  await assert.rejects(fs.stat(path.join(f.root, 'workspace-actors')), { code: 'ENOENT' });
});

test('optional server auth starts only after view; explicit refusal persists through restart and network loss cannot clear it', async t => {
  const f = await setup(t), login = f.create(() => true), result = await login.authenticate(credentials('branch@example.test'));
  assert.equal(f.log.filter(row => row.remote).length, 0);
  f.setRefusal(true); await result.afterView(); assert.equal(f.locked.length, 1);
  assert.equal((await result.runtime.metadata.read('workspace-server-rejection')).actorId, 202);
  assert.equal(await signout.requirePassword(result.runtime), true);
  const restarted = f.create(() => true); f.setBroken(true);
  await assert.rejects(restarted.authenticate(credentials('branch@example.test')), { code: 'WORKSPACE_CREDENTIALS_REJECTED' });
  assert.notEqual(await result.runtime.metadata.read('workspace-server-rejection'), null);
  f.setBroken(false); f.setRefusal(false);
  const recovered = await restarted.authenticate(credentials('branch@example.test')); recovered.discardCredentials();
  assert.equal(await recovered.runtime.metadata.read('workspace-server-rejection'), null);
  assert.equal(await signout.requirePassword(recovered.runtime), false);
});

test('unknown offline actor is denied, while any freshly valid online actor uses ordinary original server login', async t => {
  const f = await setup(t), offline = f.create();
  await assert.rejects(offline.authenticate(credentials('new@example.test')), { code: 'WORKSPACE_CREDENTIALS_REJECTED' });
  assert.equal(f.log.length, 0);
  const online = f.create(() => true), result = await online.authenticate(credentials('new@example.test'));
  assert.equal(result.local, false); assert.equal(result.actor.actorId, 303);
  assert.equal(await f.registry.member(303), null, 'Online success cannot mint an offline membership grant.');
  assert.equal(f.log.some(row => !row.remote), false);
});


test('a known account whose new valid server password is rejected by the old local cache still opens the original online account', async t => {
  const f = await setup(t); f.setServerPassword('new-valid-password');
  const result = await f.create(() => true).authenticate({ ...credentials('branch@example.test'), password: 'new-valid-password' });
  assert.equal(result.local, false); assert.equal(result.actor.actorId, 202); assert.equal(result.cachedPasswordRejected, true);
  assert.equal((await f.registry.current()).candidate.prepared.actorId, 101);
});

test('server-only login starts no source/legacy runtime and obtains temporary B authority only after the view, without changing offline membership', async t => {
  const f = await setup(t), before = await f.registry.current(), fresh = structuredClone(f.value.manifest);
  fresh.revision = 2; fresh.members.push({ ...structuredClone(fresh.members[1]), actor_id: 303, aliases: ['new@example.test'] });
  fresh.roster_sha256 = digest(fresh.members); f.setServerManifest(fresh);
  f.workspace.start = async () => { throw Error('Source/runtime work must not precede an ordinary online view.'); };
  const online = f.createOnline(), result = await online.authenticate(credentials('new@example.test'));
  assert.equal(result.local, false); assert.equal(result.onlineOnly, true); assert.equal(result.actor.actorId, 303);
  assert.equal(result.runtime.onlineOnly, true); assert.equal(await result.runtime.isPrepared(), false);
  assert.equal(await result.runtime.authenticated(), true); assert.equal(await result.runtime.offlineAuthenticated(), false);
  assert.equal(f.log.some(row => row.path.startsWith('/api/')), false);
  assert.equal((await f.workspace.metadata.read('test-shared-server-fence')).dirty, true);
  await result.afterView(); const authority = await result.runtime.authority();
  assert.equal(authority.actorId, 303); assert.equal(authority.manifestRevision, 2); assert.equal(authority.workspace.roster_sha256, fresh.roster_sha256);
  assert.equal(await f.registry.member(303), null); assert.deepEqual(await f.registry.current(), before);
  assert.equal((await result.runtime.connection()).actorId, 101); await online.stop();
});
