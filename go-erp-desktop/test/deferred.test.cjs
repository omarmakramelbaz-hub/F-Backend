'use strict';
// Every dashboard operation works offline: one no offline page answers is kept on the device under the page's operation
// number and goes to the dashboard as the same account once it answers again.
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const db = require('../src/main/db.cjs');
const { Store } = require('../src/main/store.cjs');
const { PageCache } = require('../src/main/offline/cache.cjs');
const { DashboardOffline } = require('../src/main/offline/interceptor.cjs');
const { deferral, replayScript } = require('../src/main/offline/deferred.cjs');

const ORIGIN = 'https://fasakhaninja.com';
const KEY = '7d4c2a10-1111-4222-8333-444455556666';

function device() {
  let clock = Date.parse('2026-11-02T10:00:00Z');
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.replaceUsers([{ id: 4, name: 'كاشير المعادي', mobile: '1000000004', email: 'c@example.test', account_type: 'admin', password_hash: 'x',
    branches: ['f:100'], can_checkout: true, can_manage: true }], 'h');
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const user = { id: 4, name: 'كاشير المعادي' };
  const offline = new DashboardOffline({ origin: ORIGIN, cache, pages: [], user: () => user, onDefer: (who, request) => store.deferRequest(store.user(who.id), request) });
  const post = (path, body, type = 'application/json') => offline.answer({}, { request: { url: ORIGIN + path, method: 'POST',
    headers: { 'Content-Type': type, Accept: 'application/json' }, postData: typeof body === 'string' ? body : JSON.stringify(body) }, resourceType: 'Fetch' }, true);
  return { store, post, tick: (ms) => { clock += ms; } };
}

test('an operation no offline page answers is kept with its operation number and answered as done on the device', async () => {
  const { store, post } = device();
  const employee = { branch: 'f:100', name: 'سعيد', job_title: 'كاشير', idempotency_key: KEY };
  const kept = await post('/admin/employees/save', employee);
  assert.deepEqual([kept.status, kept.json.success, kept.json.device_queued, kept.json.replayed], [200, true, true, false]);
  assert.match(kept.json.message, /هتتبعت للداشبورد/);
  const [waiting] = store.pendingDeferred(4);
  assert.deepEqual([waiting.id, waiting.url, waiting.label, JSON.parse(waiting.body).name, waiting.actor_name], [KEY, '/admin/employees/save', 'بيانات موظف', 'سعيد', 'كاشير المعادي']);
  // The page asks again with the same operation number: the same operation, kept once.
  assert.equal((await post('/admin/employees/save', employee)).json.replayed, true);
  assert.equal(store.pendingDeferred(4).length, 1);
  assert.equal((await post('/admin/employees/save', { ...employee, name: 'غيره' })).status, 409, 'The same number for another operation is refused');
  assert.deepEqual(store.outboxStats(), { pending: 1, rejected: 0 });
  // A table of the dine-in page, a form sent as fields: kept too.
  const table = await post('/admin/dining/tables', `branch=f%3A100&name=T9&idempotency_key=8d4c2a10-1111-4222-8333-444455556666`, 'application/x-www-form-urlencoded');
  assert.equal(table.json.device_queued, true);
  assert.equal(store.pendingDeferred(4)[1].label, 'طاولة صالة');
});

test('what must see the dashboard, a file, or a request without an operation number still needs the internet', async () => {
  const { store, post } = device();
  for (const [path, body] of [['/admin/employees/close', { branch: 'f:100', employee_id: 5, idempotency_key: KEY }],
    ['/admin/employees/pay', { branch: 'f:100', payroll_id: 3, idempotency_key: KEY }], ['/admin/dining/quote', { branch: 'f:100', items: [] }],
    ['/admin/desktop-dashboard/commands', { idempotency_key: KEY }]]) {
    const answer = await post(path, body);
    assert.equal(answer.status, 422, path);
    assert.equal(answer.json.device_queued, undefined, path);
  }
  const boundary = 'X1';
  const withFile = `--${boundary}\r\nContent-Disposition: form-data; name="idempotency_key"\r\n\r\n${KEY}\r\n--${boundary}\r\nContent-Disposition: form-data; name="photo"; filename="id.jpg"\r\nContent-Type: image/jpeg\r\n\r\nxx\r\n--${boundary}--\r\n`;
  const file = await post('/admin/customers/directory-save', withFile, `multipart/form-data; boundary=${boundary}`);
  assert.deepEqual([file.status, /المرفقات/.test(file.json.message)], [422, true]);
  assert.equal(store.pendingDeferred(4).length, 0);
  assert.equal(deferral({ method: 'GET', headers: {} }, new URL(ORIGIN + '/admin/employees/save'), { idempotency_key: KEY }), null);
});

test('once online they go to the dashboard in order, as that account, with its current security token', async () => {
  const { store, post, tick } = device();
  await post('/admin/employees/save', { branch: 'f:100', name: 'سعيد', idempotency_key: KEY, _token: 'old' });
  tick(1000);
  await post('/admin/dining/tables', 'branch=f%3A100&name=T9&_token=old&idempotency_key=8d4c2a10-1111-4222-8333-444455556666', 'application/x-www-form-urlencoded');
  tick(1000);
  await post('/admin/delivery-companies/save', { branch: 'f:100', name: 'سريع', idempotency_key: '9d4c2a10-1111-4222-8333-444455556666' });
  const sent = [];
  const answers = [{ status: 200, body: { success: true } }, { status: 422, body: { message: 'الاسم مستخدم', errors: { name: ['اسم الطاولة مستخدم.'] } } },
    { status: 500, body: {} }];
  const context = vm.createContext({ document: { querySelector: () => ({ content: 'fresh' }) }, URLSearchParams, JSON, Object,
    fetch: async (url, options) => { sent.push({ url, options }); const answer = answers.shift(); return { ok: answer.status < 400, status: answer.status, redirected: false, json: async () => answer.body }; } });
  // Made in the page's own world: read back as plain data.
  const results = JSON.parse(JSON.stringify(await vm.runInContext(replayScript(store.pendingDeferred(4)), context)));
  assert.deepEqual(sent.map((item) => item.url), ['/admin/employees/save', '/admin/dining/tables', '/admin/delivery-companies/save']);
  assert.equal(JSON.parse(sent[0].options.body)._token, 'fresh');
  assert.match(sent[1].options.body, /_token=fresh/);
  assert.equal(sent[0].options.headers['X-CSRF-TOKEN'], 'fresh');
  assert.equal(sent[1].options.headers['Content-Type'], 'application/x-www-form-urlencoded');
  assert.deepEqual(results.map((item) => [item.done || false, item.rejected || false, item.retry || false]), [[true, false, false], [false, true, false], [false, false, true]]);
  assert.equal(results[1].message, 'اسم الطاولة مستخدم.');
  for (const result of results) {
    if (result.done) store.finishDeferred(result.id, 'done');
    else if (result.rejected) store.finishDeferred(result.id, 'rejected', result.message);
    else store.retryDeferred(result.id, result.error);
  }
  assert.deepEqual(store.pendingDeferred(4).map((item) => item.label), ['شركة دليفري'], 'A server fault waits for the next sync');
  assert.deepEqual(store.outboxStats(), { pending: 1, rejected: 1 });
  const refused = store.rejectedOperations();
  assert.deepEqual([refused[0].kind, refused[0].error], ['طاولة صالة', 'اسم الطاولة مستخدم.']);
  // Without a dashboard session nothing is sent.
  const signedOut = vm.createContext({ document: { querySelector: () => null }, fetch: async () => { throw new Error('never'); }, JSON, Object, URLSearchParams });
  assert.deepEqual(JSON.parse(JSON.stringify(await vm.runInContext(replayScript(store.pendingDeferred(4)), signedOut))), [{ retry: true, error: 'session' }]);
});
