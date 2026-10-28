'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), http = require('node:http');
const source = fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/dashboard-spa.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function block(start, end) {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, 'Exercise the production SPA source.');
  return source.slice(from, to);
}
function scopedFetch({ legacy = false, transport = fetch } = {}) {
  const scope = { closed: false, cleanups: [] }, window = {};
  let forwarded = 0;
  const native = { fetch: transport,
    add(type, callback, options) { forwarded++; EventTarget.prototype.addEventListener.call(this, type, callback, options); },
    remove(type, callback, options) { forwarded--; EventTarget.prototype.removeEventListener.call(this, type, callback, options); }
  };
  const context = vm.createContext({ window, native, currentScope: () => scope, AbortController,
    AbortSignal: legacy ? {} : AbortSignal, DOMException, Promise, Object, Array });
  vm.runInContext(block('    window.fetch = function (input, options) {', '    function attachJQuery() {'), context);
  return { scope, fetch: window.fetch, forwarded: () => forwarded,
    dispose() { scope.closed = true; scope.cleanups.reverse().forEach(callback => callback()); scope.cleanups = []; } };
}
async function stalledBody(t) {
  const server = http.createServer((_request, response) => {
    response.writeHead(200, { 'Content-Type': 'application/json' }); response.write('{');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); });
  return 'http://127.0.0.1:' + server.address().port + '/read';
}
for (const legacy of [false, true]) {
  test('a caller abort after headers cancels the actual response body' + (legacy ? ' with legacy forwarding' : ' with dependent signals'), async t => {
    const url = await stalledBody(t), env = scopedFetch({ legacy }), caller = new AbortController();
    const response = await env.fetch(url, { signal: caller.signal });
    const reading = response.json(); caller.abort();
    await assert.rejects(reading, error => error.name === 'AbortError');
    assert.equal(env.scope.cleanups.length, 0);
    assert.equal(env.forwarded(), 0, 'Forwarded caller listeners cannot accumulate after body failure.');
  });
}
test('legacy body readers release forwarding after stream completion', async () => {
  const env = scopedFetch({ legacy: true, transport: async () => new Response('complete') });
  const response = await env.fetch('/read', { signal: new AbortController().signal });
  const reader = response.body.getReader();
  while (!(await reader.read()).done) {}
  assert.equal(env.scope.cleanups.length, 0); assert.equal(env.forwarded(), 0);
});
test('disposing a legacy page cancels its unfinished read body and releases forwarding', async t => {
  const url = await stalledBody(t), env = scopedFetch({ legacy: true });
  const response = await env.fetch(url, { signal: new AbortController().signal });
  const reading = response.text(); env.dispose();
  await assert.rejects(reading, error => error.name === 'AbortError');
  assert.equal(env.forwarded(), 0);
});
test('closed page callbacks cannot start a new transport', async () => {
  let requests = 0; const env = scopedFetch({ transport: async () => { requests++; return new Response('unexpected'); } });
  env.scope.closed = true;
  await assert.rejects(env.fetch('/read'), error => error.name === 'AbortError');
  assert.equal(requests, 0);
});

function navigation() {
  const timers = new Map(), requests = [], renders = [], notices = [], busy = [], redirects = [];
  let timerId = 0;
  const oldPage = { closed: false }, doc = { querySelector: () => ({}), body: { getAttribute: () => '1' } };
  const native = {
    add: EventTarget.prototype.addEventListener, remove: EventTarget.prototype.removeEventListener,
    timeout(callback, milliseconds) { const id = ++timerId; timers.set(id, { callback, milliseconds }); return id; },
    clearTimeout(id) { timers.delete(id); },
    fetch(url, options) { return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })); }
  };
  const context = vm.createContext({ native, Promise, AbortController, DOMException, URL, Error, Object,
    navigationReadTimeoutMs: 25000, navigation: null, sequence: 0, submitting: false,
    pendingHistory: null, location: { href: 'https://dashboard.example/admin/old' },
    window: { location: { assign: url => redirects.push(url) } },
    document: { body: { getAttribute: () => '1' } },
    DOMParser: class { parseFromString() { return doc; } },
    eligible: () => true, mayLeave: () => true, recordScroll() {}, restoreCommittedHistory() {},
    authRedirect(response) { if (response.redirected) { redirects.push(response.url); return true; } return false; },
    render: async (...args) => renders.push(args),
    busy: value => busy.push(value), message: value => value,
    notify: (...args) => notices.push(args), console: { error() {} }
  });
  vm.runInContext(block('    function readOnlyNavigation(value) {', '    function copyAttributes('), context);
  vm.runInContext(block('    function readNavigation(url, controller) {', '    function submitButtons(form) {'), context);
  const response = (text, extra = {}) => ({ headers: { get: () => 'text/html' }, text, ok: true,
    redirected: false, url: 'https://dashboard.example/admin/next', ...extra });
  return { context, requests, renders, notices, busy, redirects, oldPage, timers, response,
    visit: (url = '/admin/next', options) => context.visit(url, options),
    expire() { const due = [...timers.values()]; for (const item of due) item.callback(); } };
}
test('a GET header deadline preserves the old page and offers one user-controlled read retry', async () => {
  const env = navigation(), work = env.visit(); await tick();
  assert.equal(env.requests.length, 1); assert.equal(env.requests[0].options.method, undefined);
  env.expire(); await work;
  assert.equal(env.requests[0].options.signal.aborted, true);
  assert.deepEqual(env.busy, [true, false]); assert.equal(env.oldPage.closed, false);
  assert.equal(env.renders.length, 0); assert.equal(typeof env.notices[0][1], 'function');
  assert.equal(env.requests.length, 1, 'Read failure must not automatically retry.');
  env.requests[0].resolve(env.response(async () => 'late', { redirected: true, url: 'https://dashboard.example/admin/login' }));
  await tick(); assert.equal(env.renders.length, 0); assert.equal(env.redirects.length, 0, 'A late failed read cannot navigate the active document.');
  assert.equal(env.timers.size, 0);
});
test('the GET deadline covers stalled body consumption and ignores a late body', async () => {
  const env = navigation(), work = env.visit(); await tick(); let finishBody;
  env.requests[0].resolve(env.response(() => new Promise(resolve => { finishBody = resolve; })));
  await tick(); env.expire(); await work;
  assert.equal(env.requests[0].options.signal.aborted, true); assert.equal(env.renders.length, 0);
  finishBody('late HTML'); await tick(); assert.equal(env.renders.length, 0);
  assert.deepEqual(env.busy, [true, false]); assert.equal(env.timers.size, 0);
});
test('successful GET navigation renders once and releases the read deadline', async () => {
  const env = navigation(), work = env.visit(); await tick();
  env.requests[0].resolve(env.response(async () => 'valid HTML')); await work;
  assert.equal(env.renders.length, 1); assert.equal(env.timers.size, 0);
  assert.equal(env.requests[0].options.signal.aborted, false); assert.deepEqual(env.busy, [true, false]);
});
test('superseding a pending GET cancels it without a stale retry notice or render', async () => {
  const env = navigation(), first = env.visit(); await tick(); const second = env.visit('/admin/latest'); await tick();
  assert.equal(env.requests[0].options.signal.aborted, true); await first;
  env.requests[1].resolve(env.response(async () => 'latest')); await second;
  assert.equal(env.renders.length, 1); assert.equal(env.notices.length, 0); assert.equal(env.timers.size, 0);
});
test('a submitted response is consumed once with no timed transport or mutation replay', async () => {
  const env = navigation(); let consumed = 0;
  await env.visit('/admin/store', { afterSubmit: true, response: env.response(async () => { consumed++; return 'confirmed response'; }) });
  assert.equal(consumed, 1); assert.equal(env.requests.length, 0); assert.equal(env.renders.length, 1);
  assert.equal(env.timers.size, 0);
});
test('legacy mutation and command-bearing GETs retain their original untimed transport', async () => {
  for (const pathname of ['/admin/change-status/4', '/admin//change-status/4/',
    '/admin/resturant_products/4/update/status', '/admin/users/4/go_drive_activation',
    '/admin/cancel/order/4/delegate', '/admin/resturantControl',
    '/admin/takeaway/receipts/4/print', '/admin/order-board/legacy/4/print',
    '/admin/phone-orders/batches/4/print', '/admin/dining/tickets/4/print',
    '/admin/branch-shifts/4/print', '/admin/branch-shift-print/4/receipt',
    '/admin/dashboard?_desktop_command=original', '/admin/dashboard?idempotency_key=original']) {
    const env = navigation(), work = env.visit(pathname); await tick();
    assert.equal(env.timers.size, 0, pathname);
    assert.equal(env.requests.length, 1); assert.equal(env.requests[0].options.signal.aborted, false);
    env.requests[0].resolve(env.response(async () => 'original response')); await work;
    assert.equal(env.renders.length, 1); assert.equal(env.requests.length, 1);
  }
});
