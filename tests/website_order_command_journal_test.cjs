'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/order-board.js'), 'utf8');
const journalStart = source.indexOf('    function journalCommand(');
const journalEnd = source.indexOf('\n    function setLive(', journalStart);
assert(journalStart >= 0 && journalEnd > journalStart, 'the actual board command helper must be present');
const journal = source.slice(journalStart, journalEnd);

// Exercise the actual request-options expression with a recording fetch. No
// application boot, browser session, credentials or real network are involved.
const transportStart = source.indexOf("            var response = await fetch(actionForm.getAttribute('action'), {");
const transportEnd = source.indexOf('\n            });', transportStart) + '\n            });'.length;
assert(transportStart >= 0 && transportEnd > transportStart, 'the actual action transport must be present');
const transport = source.slice(transportStart, transportEnd);

function environment() {
    const storage = new Map();
    const network = [];
    let generated = 0;
    const response = { synthetic: true };
    const context = {
        window: { location: { href: 'https://board.invalid/admin/applies-orders', origin: 'https://board.invalid' } },
        document: {
            body: { dataset: { dashboardLocal: '0', dashboardActor: '11', dashboardRemoteAttempts: '0' } },
            querySelector: selector => {
                assert.equal(selector, 'meta[name="csrf-token"]');
                return { content: 'synthetic-csrf-token' };
            }
        },
        sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
        crypto: { randomUUID: () => `00000000-0000-4000-a000-${String(++generated).padStart(12, '0')}` },
        URL,
        translated: () => 'journal-error',
        fetch: async (url, options) => {
            network.push({ url, options });
            return response;
        }
    };
    vm.createContext(context);
    vm.runInContext(journal + '\nthis.invoke = journalCommand;', context);
    const request = vm.runInContext('(async function (actionForm, data) {\n' + transport + '\nreturn response;\n})', context);
    return { context, storage, network, response, request, generated: () => generated };
}

function form({ key = 'website:17', endpoint = '/admin/order-board/website/17/action', epoch = 'server' } = {}) {
    return {
        dataset: { desktopBoardGeneration: epoch },
        closest: selector => {
            assert.equal(selector, '[data-order-key]');
            return { dataset: { orderKey: key } };
        },
        getAttribute: attribute => {
            assert.equal(attribute, 'action');
            return endpoint;
        }
    };
}

function payload(overrides = {}) {
    return new Map(Object.entries({ _token: 'csrf-a', expected_status: 'new', expected_revision: '1', action: 'accept', ...overrides }));
}

test('ordinary browser legacy actions keep their prior transport behavior', () => {
    const env = environment();
    const data = payload();
    env.context.invoke(form({ key: 'legacy:17', endpoint: '/admin/order-board/legacy/17/action', epoch: 'not-an-epoch' }), data);
    assert.equal(data.has('idempotency_key'), false);
    assert.equal(data.has('_desktop_command'), false);
    assert.equal(env.generated(), 0);
    assert.equal(env.storage.size, 0);
    assert.equal(env.network.length, 0);
});

test('a website browser action receives an idempotency key without a desktop marker or network request', () => {
    const env = environment();
    const data = payload();
    env.context.invoke(form(), data);
    assert.match(data.get('idempotency_key'), /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/);
    assert.equal(data.has('_desktop_command'), false);
    assert.equal(env.generated(), 1);
    assert.equal(env.network.length, 0);
});

test('a lost-response retry reuses its key across CSRF refresh and form field reordering', () => {
    const env = environment();
    const first = payload();
    env.context.invoke(form(), first);
    const retry = new Map(Array.from(payload({ _token: 'csrf-refreshed', idempotency_key: 'caller-supplied-placeholder' })).reverse());
    env.context.invoke(form(), retry);
    assert.equal(retry.get('idempotency_key'), first.get('idempotency_key'));
    assert.equal(env.generated(), 1);
});

test('a changed revision or actor is a new intent rather than a replay of another action', () => {
    const env = environment();
    const first = payload();
    env.context.invoke(form(), first);
    const revised = payload({ expected_revision: '2' });
    env.context.invoke(form(), revised);
    assert.notEqual(revised.get('idempotency_key'), first.get('idempotency_key'));
    env.context.document.body.dataset.dashboardActor = '12';
    const otherActor = payload({ expected_revision: '2' });
    env.context.invoke(form(), otherActor);
    assert.notEqual(otherActor.get('idempotency_key'), revised.get('idempotency_key'));
    assert.equal(env.generated(), 3);
});

test('company and cash changes produce distinct intents while unchanged selections retry safely', () => {
    const env = environment();
    const first = payload({ action: 'dispatch', company_id: '5' });
    env.context.invoke(form(), first);
    const same = payload({ action: 'dispatch', company_id: '5' });
    env.context.invoke(form(), same);
    assert.equal(same.get('idempotency_key'), first.get('idempotency_key'));
    const different = payload({ action: 'dispatch', company_id: '6' });
    env.context.invoke(form(), different);
    assert.notEqual(different.get('idempotency_key'), first.get('idempotency_key'));
    const cash = payload({ action: 'complete', cash_received: '215.00', payment_confirmed: '1' });
    env.context.invoke(form(), cash);
    const changedCash = payload({ action: 'complete', cash_received: '250.00', payment_confirmed: '1' });
    env.context.invoke(form(), changedCash);
    assert.notEqual(changedCash.get('idempotency_key'), cash.get('idempotency_key'));
});

test('cross-origin, foreign-path and invalid-order endpoints fail before a key or request is issued', () => {
    const env = environment();
    for (const endpoint of [
        'https://other.invalid/admin/order-board/website/17/action',
        '/admin/unrelated/17',
        '/admin/order-board/website/0/action',
        '/admin/order-board/unknown/17/action'
    ]) assert.throws(() => env.context.invoke(form({ endpoint }), payload()), /journal-error/);
    assert.equal(env.generated(), 0);
    assert.equal(env.network.length, 0);
});

test('server and local generation validation reject an incompatible replay context', () => {
    const env = environment();
    const missingEpoch = form();
    delete missingEpoch.dataset.desktopBoardGeneration;
    assert.throws(() => env.context.invoke(missingEpoch, payload()), /journal-error/);
    assert.throws(() => env.context.invoke(form({ epoch: 'stale' }), payload()), /journal-error/);
    env.context.document.body.dataset.dashboardLocal = '1';
    assert.throws(() => env.context.invoke(form(), payload()), /journal-error/);
    const local = payload();
    env.context.invoke(form({ epoch: 'aaaaaaaa-bbbb-4ccc-addd-eeeeeeeeeeee' }), local);
    assert.equal(local.get('_desktop_command'), local.get('idempotency_key'));
});

test('the authorized remote desktop path retains its matching desktop command marker', () => {
    const env = environment();
    env.context.window.FasakhanstaDesktop = {};
    env.context.document.body.dataset.dashboardRemoteAttempts = '1';
    const data = payload();
    env.context.invoke(form(), data);
    assert.equal(data.get('_desktop_command'), data.get('idempotency_key'));
    assert.equal(env.network.length, 0);
});

test('the actual action fetch keeps same-origin credentials and CSRF headers through a recording transport', async () => {
    const env = environment();
    const actionForm = form();
    const data = payload();
    env.context.invoke(actionForm, data);
    assert.equal(env.network.length, 0);
    assert.equal(await env.request(actionForm, data), env.response);
    assert.equal(env.network.length, 1);
    const { url, options } = env.network[0];
    assert.equal(url, '/admin/order-board/website/17/action');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.body, data);
    assert.equal(options.headers.Accept, 'application/json');
    assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(options.headers['X-CSRF-TOKEN'], 'synthetic-csrf-token');
});
