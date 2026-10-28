'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.DESKTOP_CLIENT_TEST_SOURCE ||
  path.join(__dirname, '../../public/dashboard/js/desktop-client.js'), 'utf8');
const successAt = '2026-10-28T16:00:00.000Z';
const lastSuccess = new Date(successAt).toLocaleString('ar-EG');
const base = { available: true, prepared: true, mode: 'local', phase: 'ready', online: true,
  pending: 0, conflicts: 0, error: '', progress: 100, data_refresh: { phase: 'idle' } };

function element() {
  const listeners = new Map();
  return { hidden: false, disabled: false, textContent: '', value: 0,
    addEventListener(name, work) { listeners.set(name, work); },
    removeEventListener(name) { listeners.delete(name); },
    set innerHTML(_value) { throw Error('Status rendering must remain text-only.'); } };
}

async function fixture(initial = {}) {
  const names = ['controls', 'browser-note', 'description', 'connection', 'refreshed',
    'progress', 'error', 'action', 'review', 'update'];
  const nodes = Object.fromEntries(names.map(name => [name, element()]));
  const root = { querySelector(selector) { return nodes[selector.slice(14, -1)]; } };
  let render;
  const native = { status: async () => ({ ...base, ...initial }),
    onState(work) { render = work; return () => {}; }, reviewSync: async () => {} };
  const window = { FasakhanstaDesktop: native, addEventListener() {} };
  const document = { querySelector(selector) {
    return selector === '[data-desktop-program]' ? root : null;
  }, addEventListener() {} };
  vm.runInNewContext(source, { window, document, setInterval: () => 1, clearInterval() {}, console });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(typeof render, 'function');
  return { nodes, push(values) { render({ ...base, ...values }); } };
}

test('a successful operation connection does not invent an incoming-data success', async () => {
  const f = await fixture();
  assert.match(f.nodes.connection.textContent, /آخر اتصال لمزامنة العمليات بالسيرفر: متصل/);
  assert.equal(f.nodes.refreshed.textContent, 'لم يتأكد تحديث بيانات الجهاز من السيرفر بعد');
  assert.doesNotMatch(f.nodes.refreshed.textContent, /آخر تحديث ناجح/);
});

test('incoming work shows a user status while retaining the actual previous success time', async () => {
  const f = await fixture();
  for (const phase of ['checking', 'capturing', 'downloading', 'staging', 'activating']) {
    f.push({ refreshedAt: successAt, data_refresh: { phase } });
    assert.match(f.nodes.refreshed.textContent, /^جارٍ تحديث بيانات الجهاز من السيرفر/);
    assert.ok(f.nodes.refreshed.textContent.includes(lastSuccess));
    assert.ok(!f.nodes.refreshed.textContent.includes(phase), 'Technical phase must not reach the card.');
  }
});

test('deferred data keeps an old success separate from the uncompleted incoming update', async () => {
  const f = await fixture({ refreshedAt: successAt, data_refresh: { phase: 'ready' } });
  f.push({ refreshedAt: successAt, data_refresh: { phase: 'deferred', deferred: true } });
  assert.match(f.nodes.refreshed.textContent, /^تحديث البيانات ينتظر جاهزية الصفحة أو انتهاء العمليات/);
  assert.ok(f.nodes.refreshed.textContent.includes(lastSuccess));
  f.push({ data_refresh: { phase: 'waiting' } });
  assert.match(f.nodes.refreshed.textContent, /^تحديث البيانات ينتظر/);
  assert.doesNotMatch(f.nodes.refreshed.textContent, /آخر تحديث ناجح/);
});

test('pending operations, conflicts and the legacy defer flag do not look fully fresh', async () => {
  const f = await fixture();
  for (const waiting of [{ pending: 3 }, { conflicts: 2 }, { refresh_deferred: true }]) {
    f.push({ refreshedAt: successAt, data_refresh: { phase: 'ready' }, ...waiting });
    assert.match(f.nodes.refreshed.textContent, /^تحديث البيانات ينتظر/);
    assert.ok(f.nodes.refreshed.textContent.includes(lastSuccess));
  }
  f.push({ pending: 3, conflicts: 2, refreshedAt: successAt });
  assert.match(f.nodes.description.textContent, /3 عملية تنتظر المزامنة/);
  assert.match(f.nodes.description.textContent, /2 عملية تحتاج مراجعة/);
});

test('an incoming-data error is visible even when upload connection and previous success remain valid', async () => {
  const f = await fixture();
  f.push({ online: true, refreshedAt: successAt,
    data_refresh: { phase: 'failed', error: 'تعذر استلام البيانات؛ ستعاد المحاولة.' } });
  assert.match(f.nodes.connection.textContent, /متصل/);
  assert.match(f.nodes.refreshed.textContent, /^تعذر تحديث بيانات الجهاز من السيرفر/);
  assert.ok(f.nodes.refreshed.textContent.includes(lastSuccess));
  assert.equal(f.nodes.error.textContent, 'تعذر استلام البيانات؛ ستعاد المحاولة.');
});

test('incoming error text uses the existing text-only sink and duplicate errors appear once', async () => {
  const f = await fixture();
  const value = '<img src=x onerror="throw 1">';
  f.push({ error: value, data_refresh: { phase: 'failed', error: value } });
  assert.equal(f.nodes.error.textContent, value);
  f.push({ error: 'عملية تحتاج مراجعة', data_refresh: { phase: 'failed', error: 'تعذر تحديث البيانات' } });
  assert.equal(f.nodes.error.textContent, 'عملية تحتاج مراجعة · تعذر تحديث البيانات');
});

test('ready state shows only a real confirmed success and clears prior incoming failure', async () => {
  const f = await fixture({ data_refresh: { phase: 'failed', error: 'تعذر التحديث' } });
  f.push({ refreshedAt: successAt, data_refresh: { phase: 'ready', error: '', deferred: false } });
  assert.equal(f.nodes.refreshed.textContent, 'آخر تحديث ناجح لبيانات الجهاز من السيرفر: ' + lastSuccess);
  assert.equal(f.nodes.error.textContent, '');
  f.push({ data_refresh: { phase: 'ready' } });
  assert.equal(f.nodes.refreshed.textContent, 'لم يتأكد تحديث بيانات الجهاز من السيرفر بعد');
});

test('invalid timestamps and unknown phases cannot create a confirmed success', async () => {
  const f = await fixture();
  for (const refreshedAt of ['not-a-date', '2026-13-28T16:00:00.000Z', '2026-10-28T16:00:00Z']) {
    f.push({ refreshedAt, data_refresh: { phase: 'capture-private-database' } });
    assert.equal(f.nodes.refreshed.textContent, 'لم يتأكد تحديث بيانات الجهاز من السيرفر بعد');
    assert.doesNotMatch(f.nodes.refreshed.textContent, /capture-private-database/);
  }
});

test('a running legacy refresh shows incoming work without generating a success time', async () => {
  const f = await fixture();
  f.push({ refresh_running: true });
  assert.equal(f.nodes.refreshed.textContent, 'جارٍ تحديث بيانات الجهاز من السيرفر');
  assert.doesNotMatch(f.nodes.refreshed.textContent, /آخر تحديث ناجح/);
});
