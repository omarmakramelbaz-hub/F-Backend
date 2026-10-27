'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const util = require('node:util');
const { authenticateServerWithLease } = require('../src/workspace-password-lease.cjs');

function server({ accepted = true, role = 'vendor', password = 'كلمة مرور 00 &+秘密', identityHook } = {}) {
  const origin = 'https://workspace.example.test', requests = [];
  let authenticated = false;
  const response = (url, body, options) => {
    const result = new Response(body, options);
    Object.defineProperty(result, 'url', { value: url });
    return result;
  };
  const browser = { async fetch(url, options = {}) {
    const pathname = new URL(url).pathname;
    requests.push({ pathname, method: options.method || 'GET' });
    assert.equal(new URL(url).origin, origin);
    assert.equal(options.headers['X-Fasakhansta-Desktop'], undefined);
    if (pathname === '/admin/login') return response(url,
      '<form data-dashboard-login method="POST" action="/admin/signin"><input name="_token" value="lease_fixture_csrf_token_123456"></form>', { status: 200 });
    if (pathname === '/admin/signin') {
      const values = new URLSearchParams(options.body);
      authenticated = accepted && values.get('email') === '007-branch' && values.get('password') === password;
      return response(url, '', { status: 302, headers: { location: authenticated ? '/admin/applies-orders' : '/admin/login' } });
    }
    if (pathname === '/admin/choose_type') {
      assert.equal(authenticated, true);
      identityHook?.();
      return response(url, '<body data-dashboard-actor="202:' + role + '"></body>', { status: 200 });
    }
    throw Error('A password lease must not request a dashboard or optional data.');
  } };
  return { input: { browser, origin, email: ' 007-branch ', password, remember: false }, requests };
}

test('only the original successful server password POST and protected actor document issue a one-use lease', async () => {
  const f = server();
  const result = await authenticateServerWithLease(f.input);
  assert.deepEqual(result.actor, { actorId: 202, role: 'vendor' });
  assert.deepEqual(f.requests.map(value => value.pathname), ['/admin/login', '/admin/signin', '/admin/choose_type']);
  assert.equal(result.lease.valid(), true);
  assert.equal(JSON.stringify(result).includes(f.input.password), false);
  assert.equal(util.inspect(result).includes(f.input.password), false);
  assert.deepEqual(Object.keys(result.lease).sort(), ['consume', 'dispose', 'valid']);
  const value = await result.lease.consume(async password => {
    assert.equal(password, f.input.password);
    assert.equal(result.lease.valid(), false);
    await assert.rejects(result.lease.consume(() => 'reused'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
    return { locallyAuthenticated: true };
  });
  assert.deepEqual(value, { locallyAuthenticated: true });
  await assert.rejects(result.lease.consume(() => 'reused'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
});

test('wrong passwords, refused server accounts and untrusted role documents never issue a lease', async () => {
  const wrong = server(); wrong.input.password = 'wrong';
  await assert.rejects(authenticateServerWithLease(wrong.input), { code: 'ACCOUNT_CREDENTIALS_REJECTED' });
  assert.equal(wrong.requests.length, 2);
  await assert.rejects(authenticateServerWithLease(server({ accepted: false }).input), { code: 'ACCOUNT_CREDENTIALS_REJECTED' });
  await assert.rejects(authenticateServerWithLease(server({ role: 'user' }).input));
});

test('expiry, backward or invalid clocks close the lease instead of extending its password lifetime', async () => {
  for (const next of [1010, 999, NaN]) {
    let current = 1000;
    const { lease } = await authenticateServerWithLease(server().input, { ttlMs: 10, now: () => current });
    current = next;
    assert.equal(lease.valid(), false);
    current = 1000;
    await assert.rejects(lease.consume(() => 'late'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
  }
});

test('the actual unref expiry timer disposes even if an injected clock stays fixed', async () => {
  const { lease } = await authenticateServerWithLease(server().input, { ttlMs: 5, now: () => 1 });
  await new Promise(resolve => setTimeout(resolve, 15));
  assert.equal(lease.valid(), false);
});

test('cancellation before and after successful authentication leaves no usable password', async () => {
  const before = new AbortController(); before.abort();
  const f = server();
  await assert.rejects(authenticateServerWithLease({ ...f.input, signal: before.signal }), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
  assert.equal(f.requests.length, 0);
  const after = new AbortController();
  const { lease } = await authenticateServerWithLease({ ...server().input, signal: after.signal });
  after.abort();
  await assert.rejects(lease.consume(() => 'cancelled'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
  const during = new AbortController();
  await assert.rejects(authenticateServerWithLease({ ...server({ identityHook: () => during.abort() }).input, signal: during.signal }),
    { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
});

test('dispose is idempotent and a failing consumer cannot leave a reusable lease', async () => {
  const disposed = await authenticateServerWithLease(server().input);
  disposed.lease.dispose(); disposed.lease.dispose();
  await assert.rejects(disposed.lease.consume(() => 'disposed'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });
  const { lease } = await authenticateServerWithLease(server().input);
  const failure = Error('The fixed local password endpoint refused authentication.');
  await assert.rejects(lease.consume(async () => { throw failure; }), error => error === failure);
  assert.equal(lease.valid(), false);
});

test('invalid options and local/runtime or caller-actor fields fail before sending credentials', async () => {
  const f = server();
  for (const input of [
    { ...f.input, origin: 'http://127.0.0.1:45123' },
    { ...f.input, origin: f.input.origin + '/' },
    { ...f.input, origin: 'https://user:secret@workspace.example.test' },
    { ...f.input, localToken: 'cannot-authorize-a-lease' },
    { ...f.input, actor: { actorId: 202, role: 'vendor' } },
    { ...f.input, email: '' }, { ...f.input, email: 'a\nb' },
    { ...f.input, email: 'x'.repeat(321) }, { ...f.input, password: '' },
    { ...f.input, password: { toString() { throw Error('must not coerce'); } } },
    { ...f.input, password: 'x'.repeat(4097) }, { ...f.input, remember: 'false' },
    { ...f.input, signal: {} }, Object.assign(Object.create({}), f.input)
  ]) await assert.rejects(authenticateServerWithLease(input), { code: 'WORKSPACE_PASSWORD_LEASE_INVALID' });
  const getter = { ...f.input }; Object.defineProperty(getter, 'password', { get() { throw Error('must not read accessors'); } });
  await assert.rejects(authenticateServerWithLease(getter), { code: 'WORKSPACE_PASSWORD_LEASE_INVALID' });
  for (const ttlMs of [0, -1, 120001, 1.5, Infinity, '120000'])
    await assert.rejects(authenticateServerWithLease(f.input, { ttlMs }), { code: 'WORKSPACE_PASSWORD_LEASE_INVALID' });
  assert.equal(f.requests.length, 0);
});

test('an invalid consumer irreversibly discards the password', async () => {
  const { lease } = await authenticateServerWithLease(server().input);
  await assert.rejects(lease.consume(null), { code: 'WORKSPACE_PASSWORD_LEASE_INVALID' });
  assert.equal(lease.valid(), false);
});
