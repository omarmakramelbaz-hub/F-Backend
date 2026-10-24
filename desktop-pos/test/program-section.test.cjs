'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/dashboard/js/desktop-client.js'), 'utf8');
class Control {
  constructor() { this.hidden = false; this.disabled = false; this.textContent = ''; this.listeners = new Map(); }
  addEventListener(name, callback) { this.listeners.set(name, callback); }
  removeEventListener(name, callback) { if (this.listeners.get(name) === callback) this.listeners.delete(name); }
  click() { return this.listeners.get('click')?.(); }
}
const names = ['controls', 'browser-note', 'description', 'connection', 'refreshed', 'progress', 'error', 'action', 'review', 'update'];
function section() {
  const controls = Object.fromEntries(names.map(name => [name, new Control()]));
  controls.controls.hidden = true; controls.action.disabled = true;
  return { controls, querySelector: selector => controls[selector.slice(14, -1)] };
}
function fixture({ bridge = true, page = true } = {}) {
  let current = page ? section() : null, listener, subscriptions = 0, unsubscribed = 0, prepares = 0, syncs = 0, reviews = 0;
  const events = new Map(), timers = new Map(); let timerId = 0;
  let state = { available: true, prepared: false, phase: 'idle', mode: 'server', progress: 0, error: '' };
  const native = { status: async () => state,
    onState: callback => { listener = callback; subscriptions++; return () => { unsubscribed++; }; },
    prepare: async csrf => { prepares++; assert.equal(csrf, 'original-csrf'); return { ...state, phase: 'failed', error: '<img src=x onerror=alert(1)>' }; },
    synchronize: async () => { syncs++; return { ...state, sync_queued: true }; }, reviewSync: async () => { reviews++; } };
  const window = { addEventListener: (name, callback) => events.set(name, callback) };
  if (bridge) window.FasakhanstaDesktop = native;
  vm.runInNewContext(source, { window, document: { querySelector: selector => selector === '[data-desktop-program]' ? current : { content: 'original-csrf' }, addEventListener: (name, callback) => events.set(name, callback) },
    setInterval: callback => { timers.set(++timerId, callback); return timerId; }, clearInterval: id => timers.delete(id), Date });
  return { get current() { return current; }, native, timers, events, window,
    async ready() { await new Promise(resolve => setImmediate(resolve)); },
    state(value) { state = value; listener?.(value); },
    replace() { current = section(); events.get('dashboard:page-mounted')(); },
    leave() { events.get('dashboard:before-unload')(); current = null; events.get('dashboard:page-mounted')(); },
    counts: () => ({ subscriptions, unsubscribed, prepares, syncs, reviews }) };
}
test('normal browser preserves download and instructions without native actions, timers or navbar injection', async () => {
  const f = fixture({ bridge: false }); await f.ready();
  assert.equal(f.current.controls.controls.hidden, true); assert.equal(f.timers.size, 0);
  assert.equal(f.window.FasakhanstaDesktopUI, undefined);
});
test('section prepares using current CSRF, keeps errors as text and never creates a navbar/modal', async () => {
  const f = fixture(); await f.ready();
  assert.equal(f.current.controls.controls.hidden, false); assert.equal(f.current.controls['browser-note'].hidden, true);
  assert.equal(f.current.controls.action.disabled, false);
  await f.current.controls.action.click();
  assert.equal(f.counts().prepares, 1); assert.equal(f.current.controls.error.textContent, '<img src=x onerror=alert(1)>');
  assert.equal(f.current.controls.action.disabled, false);
});
test('prepared section reports actual connection, refresh time and updater state and invokes synchronization once', async () => {
  const f = fixture(); await f.ready();
  f.state({ available: true, prepared: true, phase: 'ready', mode: 'local', online: true, refreshedAt: '2026-10-23T16:40:00.000Z', pending: 2, conflicts: 1,
    update: { status: 'staged', version: '0.4.4-preview.99', error: 'PRIVATE updater details' } });
  assert.match(f.current.controls.description.textContent, /٢|2/); assert.match(f.current.controls.connection.textContent, /متصل/);
  assert.match(f.current.controls.refreshed.textContent, /آخر تحديث/); assert.match(f.current.controls.update.textContent, /انتهاء العمليات/);
  assert.match(f.current.controls.update.textContent, /0\.4\.4-preview\.99/); assert.doesNotMatch(f.current.controls.update.textContent, /PRIVATE/);
  await f.current.controls.action.click(); assert.equal(f.counts().syncs, 1); assert.equal(f.counts().prepares, 0);
  assert.equal(f.current.controls.action.disabled, true); assert.equal(f.current.controls.review.hidden, false);
  await f.current.controls.review.click(); assert.equal(f.counts().reviews, 1);
});
test('unknown updater status, invalid version and invented refresh time are never displayed', async () => {
  const f = fixture(); await f.ready();
  f.state({ available: true, prepared: true, phase: '__proto__', online: false, refreshedAt: '<private>', update: { status: '<private>', version: '<img src=x>' } });
  assert.equal(f.current.controls.refreshed.textContent, ''); assert.doesNotMatch(f.current.controls.update.textContent, /private|img|object/);
  assert.match(f.current.controls.connection.textContent, /لم يتأكد/); assert.match(f.current.controls.description.textContent, /يحتاج تجهيز/);
});
test('SPA leaving and remounting disposes native subscriptions and timers and ignores old callbacks', async () => {
  const f = fixture(); await f.ready(); const old = f.current;
  f.leave(); assert.equal(f.timers.size, 0); assert.equal(f.counts().unsubscribed, 1);
  f.state({ available: true, phase: 'failed', error: 'late callback' }); assert.equal(old.controls.error.textContent, '');
  f.replace(); await f.ready(); assert.equal(f.timers.size, 1); assert.equal(f.counts().subscriptions, 2);
  assert.equal(f.counts().unsubscribed, 1);
  f.events.get('dashboard:page-mounted')(); assert.equal(f.counts().subscriptions, 2);
});
test('browser history restores section status exactly once after pagehide', async () => {
  const f = fixture(); await f.ready();
  f.events.get('pagehide')(); assert.equal(f.timers.size, 0); assert.equal(f.counts().unsubscribed, 1);
  f.events.get('pageshow')(); await f.ready(); assert.equal(f.timers.size, 1); assert.equal(f.counts().subscriptions, 2);
  f.events.get('pageshow')(); assert.equal(f.counts().subscriptions, 2);
});
test('authenticated server controller offers only the validated GO ERP publication and refuses local/unauthorized downloads', t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php'; const check = spawnSync(php, ['-n', path.join(root, 'tests/desktop_dashboard_program_test.php')], { encoding: 'utf8' });
  if (check.error?.code === 'ENOENT' && !process.env.DESKTOP_TEST_PHP) { t.skip('PHP not installed'); return; }
  assert.equal(check.status, 0, check.stdout + check.stderr); assert.match(check.stdout, /PASS 18 modern program controller checks/);
});
