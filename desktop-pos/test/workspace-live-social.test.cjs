'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), { EventEmitter } = require('node:events');
const { WorkspaceLiveSocial, SESSION_PATH, CHALLENGE_PATH } = require('../src/workspace-live-social.cjs');
const { modeFixture } = require('./helpers/workspace-mode-fixture.cjs');
const { CENTRAL_KEY } = require('../src/workspace-connected.cjs');
const settle = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); await new Promise(setImmediate); };
async function socialFixture(t, { confirmMicrophone = async () => false, holdProbe = false } = {}) {
  const f = await modeFixture(t), origin = f.current.serverOrigin, windows = [], sessions = [], probes = [], notes = [], writes = [];
  let nextId = 0, proofActor = 202, protocol = 2, probeError, quitting = false, releaseProbe, authenticated = true, grant;
  const waiting = holdProbe ? new Promise(resolve => { releaseProbe = resolve; }) : null;
  const baseActor = f.actor(202), actor = { authenticated: async () => authenticated, authority: async () => ({ ...await baseActor.authority(), ...(grant ? { grant } : {}) }) };
  const source = { cookies: { get: async () => [
    { name: 'erp_session', value: 'actual-B-cookie', domain: 'dashboard.example.test', path: '/', hostOnly: true, httpOnly: true },
    { name: 'foreign_cookie', value: 'must-never-copy', domain: 'foreign.example.test', path: '/', hostOnly: true, httpOnly: true }
  ] } };
  function createSession(partition) {
    const hooks = {}, copied = [], session = { partition, hooks, copied, clearCount: 0,
      cookies: { set: async value => { copied.push(value); } }, clearStorageData: async () => { session.clearCount++; },
      setPermissionCheckHandler: callback => { hooks.check = callback; }, setPermissionRequestHandler: callback => { hooks.permission = callback; },
      webRequest: { onBeforeSendHeaders: (_filter, callback) => { hooks.headers = callback; }, onErrorOccurred: (_filter, callback) => { hooks.error = callback; } } };
    sessions.push(session); return session;
  }
  function createWindow(options) {
    const window = new EventEmitter(), contents = new EventEmitter();
    Object.assign(window, { options, destroyed: false, visible: false, loads: [] }); contents.id = ++nextId;
    contents.getURL = () => window.url || ''; contents.setWindowOpenHandler = callback => { contents.popup = callback; };
    contents.executeJavaScript = async code => { window.injected = code; }; window.webContents = contents;
    window.loadURL = async url => { window.url = url; window.loads.push(url); contents.emit('did-finish-load'); };
    window.show = () => { window.visible = true; }; window.focus = () => {}; window.isDestroyed = () => window.destroyed;
    window.destroy = () => { if (!window.destroyed) { window.destroyed = true; window.emit('closed'); } };
    windows.push(window); return window;
  }
  const social = new WorkspaceLiveSocial({ workspace: f.workspace, actor, sourceSession: () => source, createSession, createWindow,
    quitting: () => quitting, notify: message => { notes.push(message); }, confirmMicrophone,
    probeSession: async ({ browser, root, actor: effective, signal }) => {
      probes.push({ browser, root, actor: effective, signal });
      assert.equal(browser, sessions.at(-1)); assert.equal(root.actorId, 101); assert.equal(effective.actorId, 202);
      const internal = async (pathname, method) => new Promise(resolve => browser.hooks.headers({ url: origin + pathname,
        method, webContentsId: -1, resourceType: 'xhr', requestHeaders: { Authorization: 'renderer-forgery', 'X-Fasakhansta-Connected-Token': 'old-forgery' } }, resolve));
      const challenge = await internal(CHALLENGE_PATH, 'GET'); assert.equal(challenge.requestHeaders.Authorization, undefined);
      const session = await internal(SESSION_PATH, 'POST'); assert.equal(session.requestHeaders.Authorization, 'Bearer ' + root.token);
      assert.equal(session.requestHeaders['X-Fasakhansta-Connected-Token'], undefined);
      if (waiting) await waiting;
      if (probeError) throw probeError;
      return { protocol: 2, workspace_id: root.deviceId, custodian_actor_id: 101, actor_id: proofActor, role: proofActor === 101 ? 'admin' : 'vendor',
        manifest_revision: root.workspace.manifest_revision, root_fingerprint: root.workspace.root_fingerprint, connected_request_protocol: protocol };
    } });
  t.after(() => social.close());
  async function request(url, method = 'GET', headers = {}, details = {}) {
    const value = await new Promise(resolve => sessions.at(-1).hooks.headers({ url, method, requestHeaders: headers,
      webContentsId: windows.at(-1).webContents.id, resourceType: 'xhr', ...details }, resolve));
    if (!value.cancel && method === 'POST') writes.push(url); return value;
  }
  return { ...f, origin, actor, social, windows, sessions, probes, notes, writes, request, source,
    setProbeActor(value) { proofActor = value; }, setProtocol(value) { protocol = value; }, setProbeError(value) { probeError = value; },
    setGrant(value) { grant = value; }, setAuthenticated(value) { authenticated = value; }, releaseProbe() { releaseProbe?.(); }, setQuitting() { quitting = true; } };
}

test('actual B social messages use separate HTTPS cookies and six group headers while custody A and local ERP remain untouched', async t => {
  const f = await socialFixture(t), prepared = f.current; await f.social.open('messages');
  const socialFingerprint = await f.social.runtime.connection();
  assert.equal(socialFingerprint.actorId, 202); assert.notEqual(socialFingerprint.token, prepared.token); assert.equal((await f.workspace.connection()).actorId, 101);
  assert.equal(f.windows[0].url, f.origin + '/admin/meta'); assert.equal(f.windows[0].options.webPreferences.sandbox, true);
  assert.equal(f.windows[0].options.webPreferences.nodeIntegration, false); assert.equal(f.windows[0].options.webPreferences.preload, undefined);
  assert.equal(f.sessions[0].partition.startsWith('persist:'), false); assert.equal(f.sessions[0].copied.length, 1);
  assert.equal(f.sessions[0].copied[0].value, 'actual-B-cookie');
  const sent = await f.request(f.origin + '/admin/meta/conversations/25/replies', 'POST', { Authorization: 'forged', 'X-Fasakhansta-Connected-Token': 'old' });
  assert.equal(sent.cancel, undefined); assert.equal(sent.requestHeaders.Authorization, undefined);
  assert.equal(sent.requestHeaders['X-Fasakhansta-Workspace-Actor'], '202'); assert.equal(sent.requestHeaders['X-Fasakhansta-Workspace-Token'], prepared.token);
  assert.equal(sent.requestHeaders['X-Fasakhansta-Workspace-Device'], prepared.deviceId); assert.equal(sent.requestHeaders['X-Fasakhansta-Connected-Token'], undefined);
  assert.equal(f.probes.length, 2); assert.equal(f.generations, 0); assert.deepEqual(f.calls, []);
  assert.equal(await f.metadata.read(CENTRAL_KEY), null); assert.deepEqual(await f.workspace.connection(), prepared);
});

test('group native probes cannot be borrowed by the renderer and the old account-scoped session path is always blocked', async t => {
  const f = await socialFixture(t); await f.social.open('comments');
  for (const [pathname, method] of [[SESSION_PATH, 'POST'], [CHALLENGE_PATH, 'GET'], ['/admin/desktop-dashboard/session', 'GET']])
    assert.equal((await f.request(f.origin + pathname, method, { Authorization: 'renderer-forgery' })).cancel, true);
  assert.equal(f.writes.length, 0);
});

test('wrong actual server actor, lost request protocol and expired native B session cannot send or claim custody A authority', async t => {
  const f = await socialFixture(t); await f.social.open('messages');
  f.setProbeActor(101); assert.equal((await f.request(f.origin + '/admin/meta/conversations/25/replies', 'POST')).cancel, true);
  f.setProbeActor(202); f.setProtocol(1); assert.equal((await f.request(f.origin + '/admin/meta/comments/reply', 'POST')).cancel, true);
  f.setProtocol(2); f.setAuthenticated(false); assert.equal((await f.request(f.origin + '/admin/meta/conversations/25/replies', 'POST')).cancel, true);
  assert.deepEqual(f.writes, []); assert.equal((await f.workspace.connection()).actorId, 101);
});

test('membership/grant changes invalidate the social fingerprint before a stale cookie sends another message', async t => {
  const f = await socialFixture(t); await f.social.open('messages'); f.setGrant('d'.repeat(64));
  assert.equal((await f.request(f.origin + '/admin/meta/conversations/25/replies', 'POST')).cancel, true); assert.deepEqual(f.writes, []);
});

test('ERP routes and foreign assets never acquire a group bearer, grant, cookie, local capability or connected proof', async t => {
  const f = await socialFixture(t); await f.social.open('messages');
  for (const [pathname, method] of [['/admin/orders', 'POST'], ['/admin/change-status/20', 'GET'], ['/admin/meta/orders/7/dispatch', 'POST'], ['/admin/meta/conversations/%32%35/replies', 'POST']])
    assert.equal((await f.request(f.origin + pathname, method)).cancel, true);
  const asset = await f.request('https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js', 'GET', { Cookie: 'B-private', Origin: f.origin,
    Referer: f.origin + '/admin/meta', Authorization: 'Bearer forged', 'X-Fasakhansta-Workspace-Grant': 'forged', 'X-Fasakhansta-Desktop': 'local' }, { resourceType: 'script' });
  assert.deepEqual(asset.requestHeaders, {});
  assert.equal((await f.request('https://foreign.example.test/collect', 'POST')).cancel, true);
  assert.deepEqual(f.writes, []);
});

test('a lost social response preserves drafts and never queues a retry, ERP sync or central transition', async t => {
  const f = await socialFixture(t); await f.social.open('messages'); const url = f.origin + '/admin/meta/conversations/25/replies';
  await f.request(url, 'POST'); f.sessions[0].hooks.error({ url, method: 'POST', error: 'net::ERR_CONNECTION_RESET', webContentsId: f.windows[0].webContents.id });
  await settle(); assert.deepEqual(f.writes, [url]); assert.equal(f.windows[0].loads.length, 1); assert.match(f.windows[0].injected, /لن تُرسل رسالة تلقائيًا/);
  assert.equal(f.generations, 0); assert.deepEqual(f.calls, []);
});

test('voice permission retains the original explicit audio-only prompt and revalidates B before capture', async t => {
  let prompts = 0; const f = await socialFixture(t, { confirmMicrophone: async () => { prompts++; return true; } }); await f.social.open('messages');
  const window = f.windows[0], handler = f.sessions[0].hooks.permission; let allowed;
  await handler(window.webContents, 'media', value => { allowed = value; }, { mediaTypes: ['audio'], requestingUrl: f.origin + '/admin/meta' });
  assert.equal(allowed, true); assert.equal(prompts, 1);
  await handler(window.webContents, 'media', value => { allowed = value; }, { mediaTypes: ['video'], requestingUrl: f.origin + '/admin/meta' }); assert.equal(allowed, false);
  f.setProbeActor(101);
  await handler(window.webContents, 'media', value => { allowed = value; }, { mediaTypes: ['audio'], requestingUrl: f.origin + '/admin/meta' }); assert.equal(allowed, false);
});

test('closing while the group session proof is pending cannot reopen or authorize the disposed social surface', async t => {
  const f = await socialFixture(t, { holdProbe: true }), opening = f.social.open('messages'); await settle(); assert.equal(f.probes.length, 1);
  f.social.close(); f.releaseProbe(); await assert.rejects(opening); await settle();
  assert.equal(f.probes[0].signal.aborted, true); assert.equal(f.windows[0].destroyed, true); assert.equal(f.windows[0].loads.length, 0); assert.ok(f.sessions[0].clearCount > 0);
});
