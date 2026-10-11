'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const crypto = require('node:crypto');
const Attempts = require('../src/dashboard-remote-attempts.cjs');
const renderer = fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/desktop-dashboard.js'), 'utf8');
const origin = 'https://dashboard.example';

async function page({ local = true, prepared = true, links = [] } = {}) {
  const all = links.map(href => ({ href: new URL(href, origin).href, dataset: {} }));
  let mutation, issued = 0;
  const document = {
    body: { dataset: { dashboardLocal: local ? '1' : '0', dashboardRemoteAttempts: '1', dashboardActor: '1:admin' } },
    documentElement: {}, querySelectorAll: selector => selector === 'a[href]' ? all : [],
    addEventListener() {}, querySelector: () => null
  };
  await vm.runInNewContext(renderer, {
    document, window: { FasakhanstaDesktop: { status: async () => ({ prepared }) } },
    HTMLFormElement: class {}, URL, URLSearchParams, location: { origin, href: origin + '/admin/resturants/100' },
    crypto: { randomUUID: () => { issued++; return crypto.randomUUID(); } },
    MutationObserver: class { constructor(fn) { mutation = fn; } observe() {} }
  });
  return { all, mutate: () => mutation?.(), issued: () => issued };
}

test('actual product links carry a stable UUID through mutation scans and a lost navigation reply', async () => {
  for (const local of [true, false]) {
    const env = await page({ local, links: [
      '/admin/resturant_products/20/update/status?status=hide',
      '/admin/resturant_products/20/update/highest_rated?highest_rated=yes'
    ] });
    const first = env.all.map(link => link.href), commands = first.map(href => new URL(href).searchParams.get('_desktop_command'));
    commands.forEach(command => assert.match(command, /^[a-f0-9-]{36}$/i));
    assert.notEqual(commands[0], commands[1]); env.mutate(); env.mutate();
    assert.deepEqual(env.all.map(link => link.href), first); assert.equal(env.issued(), 2);
    assert.equal(new URL(first[0]).searchParams.get('status'), 'hide');
    assert.equal(new URL(first[1]).searchParams.get('highest_rated'), 'yes');
    // Editing the value preserves the UUID so the server can reject a changed-payload retry.
    const edited = new URL(env.all[0].href); edited.searchParams.set('status', 'show'); env.all[0].href = edited.href;
    env.mutate(); assert.equal(new URL(env.all[0].href).searchParams.get('_desktop_command'), commands[0]);
    const acceptedPage = await page({ local, links: ['/admin/resturant_products/20/update/status?status=show'] });
    assert.notEqual(new URL(acceptedPage.all[0].href).searchParams.get('_desktop_command'), commands[0]);
  }
});

test('foreign, unreviewed and unprepared links retain their original href', async () => {
  const links = [
    'https://other.example/admin/resturant_products/20/update/status?status=hide',
    '/admin/resturant_products/0/update/status', '/admin/resturant_products/01/update/status',
    '/admin/resturant_products/99999999999999999999/update/status', '/admin/resturant_products/20/update/status/',
    '/admin/resturant_products/20/update/price', '/admin/resturantControl', '/admin/resturants/20'
  ];
  const env = await page({ links }); assert.equal(env.issued(), 0);
  assert.deepEqual(env.all.map(link => link.href), links.map(href => new URL(href, origin).href));
  const remote = await page({ local: false, prepared: false, links: ['/admin/resturant_products/20/update/status?status=hide'] });
  assert.equal(remote.issued(), 0); assert.equal(new URL(remote.all[0].href).searchParams.has('_desktop_command'), false);
});

test('native product reservations include only the two reviewed GET writes and preserve uncertainty across recovery', async () => {
  const records = new Map(), calls = [], pending = new Set();
  const credential = { deviceId: crypto.randomUUID(), actorId: 1, serverOrigin: origin };
  const options = {
    metadata: { read: async key => records.get(key), write: async (key, value) => records.set(key, value) },
    state: { begin: async id => pending.add(id), complete: async id => pending.delete(id), read: async () => ({ pending: [...pending] }) },
    credential: async () => credential,
    request: async value => {
      calls.push(value); return { format: 1, id: value.id, device_id: credential.deviceId, actor_id: 1,
        method: value.method, path: value.path, status: value.action === 'reserve' ? 'ready' : 'committed', capability: 'a'.repeat(64) };
    }
  };
  const attempts = new Attempts(options);
  const unpaired = new Attempts({ ...options, credential: async () => null });
  for (const instance of [attempts, unpaired]) {
    for (const field of ['status', 'highest_rated']) {
      await assert.rejects(instance.begin(crypto.randomUUID(), {
        url: origin + '/admin/resturant_products/20/update/' + field + '?' + field + '=hide', method: 'HEAD'
      }), /فحص رابط الصنف/);
    }
  }
  assert.equal(pending.size, 0); assert.equal(records.size, 0); assert.equal(calls.length, 0);
  for (const field of ['status', 'highest_rated']) {
    const pathname = '/admin/resturant_products/20/update/' + field, url = origin + pathname + '?' + field + '=hide';
    assert.equal(attempts.supported({ url, method: 'GET' }), true);
    for (const method of ['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']) assert.equal(attempts.supported({ url, method }), false);
    const id = crypto.randomUUID(); await attempts.begin(id, { url, method: 'GET' }); attempts.failed(id);
    assert.equal(pending.has(id), true); await new Attempts(options).recover(); assert.equal(pending.has(id), false);
    assert.deepEqual(calls.slice(-2).map(call => [call.action, call.id, call.method, call.path]), [
      ['reserve', id, 'GET', pathname], ['settle', id, 'GET', pathname]
    ]);
  }
  for (const pathname of ['/admin/resturant_products/0/update/status', '/admin/resturant_products/20/update/price', '/admin/products/20'])
    assert.equal(attempts.supported({ url: origin + pathname, method: 'GET' }), false);
});
