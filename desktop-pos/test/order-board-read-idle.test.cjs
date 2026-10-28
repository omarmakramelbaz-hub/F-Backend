'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/order-board.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function polling(fetch) {
  const timers = new Map(), calls = [], messages = []; let next = 0;
  const context = { URL, URLSearchParams, AbortController, DOMException, TextDecoder,
    window: { location: { href: 'http://127.0.0.1:1234/admin/applies-orders' } },
    translated: key => key, fetch: async (url, options) => { calls.push({ url, ...options }); return fetch(url, options); },
    setTimeout: (callback, delay) => { const id = ++next; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    disposed: false, pendingAction: 0, activeFeed: null, generation: 0,
    needsRefresh: false, reconcileUrgent: false, filters: () => new URLSearchParams(),
    board: { dataset: { feedUrl: '/admin/getOrders' } },
    message: { hidden: false, querySelector: () => ({ hidden: false }) },
    setLive: value => messages.push(value), notify: (...value) => messages.push(value),
    syncOrderAlerts() {}, modalIsOpen: () => false, syncCards: html => messages.push({ html }), syncCounts() {} };
  vm.createContext(context);
  const start = source.indexOf('    function errorText('), end = source.indexOf("\n    form.addEventListener('submit'", start);
  assert.ok(start >= 0 && end > start); vm.runInContext(source.slice(start, end), context);
  return { context, calls, messages, timers, expire() {
    assert.equal(timers.size, 1); const timer = [...timers.values()][0];
    assert.equal(timer.delay, 15000); timer.callback();
  } };
}

test('a stalled board GET times out, releases its polling slot, and admits a later read', async () => {
  let count = 0, late;
  const f = polling(async () => ++count === 1 ? new Promise(resolve => { late = resolve; })
    : new Response(JSON.stringify({ html: 'fresh board', counts: {} })));
  const first = f.context.refresh(false); await settle();
  await f.context.refresh(false); assert.equal(f.calls.length, 1, 'polls cannot overlap');
  f.expire(); await first;
  assert.equal(f.context.activeFeed, null); assert.equal(f.calls[0].signal.aborted, true);
  assert.equal(f.messages.some(value => Array.isArray(value) && value[2] === true), true);
  await f.context.refresh(false);
  assert.equal(f.calls.length, 2); assert.equal(f.calls.every(call => call.method === 'GET'), true);
  assert.equal(f.messages.some(value => value?.html === 'fresh board'), true);
  let cancelled = false;
  late(new Response(new ReadableStream({ cancel() { cancelled = true; } })));
  await settle(); assert.equal(cancelled, true);
  assert.equal(f.messages.filter(value => value?.html).length, 1, 'late responses cannot replace fresh data');
  assert.equal(f.timers.size, 0);
});

test('the deadline covers a response body stalled after successful headers and cancels its reader', async () => {
  let cancelled = false;
  const f = polling(async () => new Response(new ReadableStream({ cancel() { cancelled = true; } }),
    { headers: { 'Content-Type': 'application/json' } }));
  const pending = f.context.refresh(false); await settle(); f.expire(); await pending; await settle();
  assert.equal(cancelled, true); assert.equal(f.context.activeFeed, null);
  assert.equal(f.messages.includes('offline'), true); assert.equal(f.timers.size, 0);
});

test('an intentional read cancellation stays silent and does not discard a newer polling slot', async () => {
  const f = polling(async () => new Promise(() => {}));
  const first = f.context.refresh(false); await settle();
  const second = f.context.refresh(true); await settle();
  assert.equal(f.calls.length, 2); assert.equal(f.calls[0].signal.aborted, true);
  await first; assert.notEqual(f.context.activeFeed, null);
  assert.equal(f.messages.some(value => Array.isArray(value)), false);
  f.context.activeFeed.abort(); await second; assert.equal(f.context.activeFeed, null);
});

test('a pending order mutation is never replayed or bypassed by read polling', async () => {
  const f = polling(async () => assert.fail('no reads while an order action is pending'));
  f.context.pendingAction = 1;
  await f.context.refresh(true);
  assert.equal(f.calls.length, 0); assert.equal(f.timers.size, 0);
  assert.equal(f.context.needsRefresh, true); assert.equal(f.context.reconcileUrgent, true);
});

function idle() {
  let callback;
  const context = { URL, URLSearchParams, JSON, disposed: false, pendingAction: 0, pendingKeys: new Set(),
    activeFeed: null, searchTimer: null, reconcileTimer: null, reconcileNeeded: false, reconcileUrgent: false,
    modalIsOpen: () => false, filters: () => new URLSearchParams(),
    form: { id: 'ob-filters', method: 'get', getAttribute: () => '/admin/applies-orders' },
    window: { location: { href: 'http://127.0.0.1:1234/admin/applies-orders', origin: 'http://127.0.0.1:1234', search: '' },
      DashboardSPA: { onIdle: work => { callback = work; } } } };
  vm.createContext(context);
  const start = source.indexOf('    if (window.DashboardSPA && window.DashboardSPA.onIdle)'),
    end = source.indexOf('\n    if (window.DashboardSPA) window.DashboardSPA.onCleanup', start);
  assert.ok(start >= 0 && end > start); vm.runInContext(source.slice(start, end), context);
  return { context, check: () => callback() };
}

test('the board idle observation admits only its settled read-only filter state', () => {
  const f = idle(); assert.equal(f.check(), true);
  f.context.window.location.search = '?branch=f%3A100&date=2026-10-28';
  f.context.filters = () => new URLSearchParams('date=2026-10-28&branch=f%3A100');
  assert.equal(f.check(), true, 'matched applied GET filters are a settled observation');
  f.context.filters = () => new URLSearchParams('branch=f%3A101'); assert.equal(f.check(), false);
  f.context.form.method = 'post'; assert.equal(f.check(), false);
  f.context.form.method = 'get'; f.context.form.getAttribute = () => '/admin/order-board/legacy/1/action';
  assert.equal(f.check(), false);
});

test('actions, active reads, unapplied search, reconciliation and open editors keep the board nonidle', () => {
  for (const [name, value] of Object.entries({ disposed: true, pendingAction: 1, activeFeed: {},
    searchTimer: 1, reconcileTimer: 1, reconcileNeeded: true, reconcileUrgent: true })) {
    const f = idle(); f.context[name] = value; assert.equal(f.check(), false, name);
  }
  const pending = idle(); pending.context.pendingKeys.add('order'); assert.equal(pending.check(), false);
  const modal = idle(); modal.context.modalIsOpen = () => true; assert.equal(modal.check(), false);
  const foreign = idle(); foreign.context.form.getAttribute = () => 'https://foreign.test/admin/applies-orders';
  assert.equal(foreign.check(), false);
});

test('an omitted single-branch filter is settled only at its original HTML default', () => {
  const f = idle();
  f.context.form.elements = [{ name: 'branch', tagName: 'SELECT', multiple: false,
    options: [{ value: '', defaultSelected: false }, { value: 'f:100', defaultSelected: true }] }];
  f.context.filters = () => new URLSearchParams('branch=f%3A100');
  assert.equal(f.check(), true, 'reloading the same URL preserves the server-selected branch');
  f.context.filters = () => new URLSearchParams('branch=f%3A101');
  assert.equal(f.check(), false, 'an unapplied choice cannot masquerade as an omitted default');
});
