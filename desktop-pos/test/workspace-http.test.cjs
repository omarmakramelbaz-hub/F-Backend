'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { WorkspaceHTTP } = require('../src/workspace-http.cjs');
const { record } = require('./helpers/workspace-fixture.cjs');
function fixture() {
  const active = record(), calls = [], body = { value: '{}' };
  const transport = async (url, options) => { calls.push({ url, options }); return new Response(body.value, { headers: { 'content-type': 'application/json' } }); };
  const browser = { fetch: transport }, http = new WorkspaceHTTP({ serverOrigin: active.credential.serverOrigin, transport, serverSession: () => browser });
  return { active, calls, body, browser, http };
}

test('group transport pins fixed protocol2 endpoints, preserves raw PHP snapshot numbers and never falls through to protocol1', async () => {
  const f = fixture(); f.body.value = '{"format":2,"tables":{"orders":{"rows":[{"id":9007199254740999,"amount":1.0}]}}}';
  const snapshot = await f.http.root('bootstrap', undefined, f.active.credential);
  assert.equal(require('../src/dashboard-download.cjs').input(snapshot), f.body.value);
  assert.equal(f.calls[0].url, f.active.credential.serverOrigin + '/api/desktop-dashboard/workspace/bootstrap');
  assert.equal(f.calls[0].options.redirect, 'error'); assert.equal(f.calls[0].options.headers.Authorization, 'Bearer ' + f.active.credential.token);
  assert.throws(() => f.http.root('../commands', {}, f.active.credential), { code: 'WORKSPACE_HTTP_FAILED' });
  assert.throws(() => f.http.root('commands', {}, { ...f.active.credential, serverOrigin: 'https://foreign.test' }), { code: 'WORKSPACE_HTTP_FAILED' });
  assert.equal(f.calls.length, 1);
});

test('native enrollment returns the untouched sealed wire and requires the fresh challenge CSRF and exact body', async () => {
  const f = fixture(); f.body.value = '{"ciphertext":"raw","duplicate":1,"duplicate":2}';
  const body = { device_id: f.active.credential.workspaceId, name: 'GO ERP', nonce: 'a'.repeat(64), public_key: 'synthetic-public-key' };
  assert.equal(await f.http.enroll(f.active.credential.serverOrigin, body, 'native_csrf_token_123456789'), f.body.value);
  assert.equal(f.calls[0].options.headers['X-CSRF-TOKEN'], 'native_csrf_token_123456789');
  assert.equal(f.calls[0].options.headers.Authorization, undefined, 'Enrollment uses the actual authenticated server cookie, not a borrowed bearer.');
  assert.throws(() => f.http.enroll(f.active.credential.serverOrigin, { ...body, actor_id: 101 }, 'native_csrf_token_123456789'), { code: 'WORKSPACE_HTTP_FAILED' });
});

test('local password proof uses the same browser and only a literal loopback endpoint with the private root bearer', async () => {
  const f = fixture(), local = { origin: 'http://127.0.0.1:32120', token: 'private-loopback-token' };
  await f.http.localSession({ browser: f.browser, local, active: f.active });
  assert.equal(f.calls[0].url, local.origin + '/admin/desktop-dashboard/workspace/local-session');
  assert.equal(f.calls[0].options.headers['X-Fasakhansta-Desktop'], local.token);
  assert.equal(f.calls[0].options.headers.Authorization, 'Bearer ' + f.active.credential.token);
  assert.throws(() => f.http.localSession({ browser: f.browser, local: { ...local, origin: 'http://foreign.test:32120' }, active: f.active }), { code: 'WORKSPACE_HTTP_FAILED' });
});

test('shutdown aborts a pending body after headers and cannot authorize partial authority JSON', async () => {
  let closed = false, observed;
  const active = record(), response = new Response(new ReadableStream({ start(c) { c.enqueue(new TextEncoder().encode('{"format":2,')); }, cancel() { closed = true; } }), { headers: { 'content-type': 'application/json' } });
  const http = new WorkspaceHTTP({ serverOrigin: active.credential.serverOrigin, serverSession: () => null, transport: async (_url, options) => { observed = options.signal; return response; } });
  const task = http.root('manifest', undefined, active.credential); await new Promise(setImmediate); http.stop();
  await assert.rejects(task); assert.equal(observed.aborted, true); assert.equal(closed, true);
});
