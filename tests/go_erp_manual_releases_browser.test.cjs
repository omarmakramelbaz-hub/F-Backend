'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const code = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/go-erp-releases.js'), 'utf8');
const nonce = 'ab'.repeat(16);
function status(state = 'idle', wanted = null) {
    return { format: 1, kind: 'go-erp-manual-release-status', state, nonce: wanted,
        updated_at: 1792843200, version: null, code: null };
}
const absent = () => ({ ready: false, version: null, url: null });
function published(number, letter = 'a') {
    return { ready: true, version: '0.4.4-preview.' + number,
        url: 'https://dashboard.test/admin/desktop-dashboard/published/' + letter.repeat(64) };
}
function payload(state = 'idle', wanted = null, download = absent(), retained = { current: absent(), previous: absent() }) {
    return { status: status(state, wanted), download, published_downloads: retained };
}
class Node {
    constructor(tag = 'div') { this.tagName = tag; this.dataset = {}; this.listeners = new Map(); this.children = []; this.hidden = false; this.textContent = ''; }
    addEventListener(name, callback, options) { const list = this.listeners.get(name) || []; list.push({ callback, once: !!options?.once }); this.listeners.set(name, list); }
    removeEventListener(name, callback) { this.listeners.set(name, (this.listeners.get(name) || []).filter(item => item.callback !== callback)); }
    dispatch(name) { for (const item of [...(this.listeners.get(name) || [])]) { if (item.once) this.removeEventListener(name, item.callback); item.callback({ type: name }); } }
    setAttribute(name, value) { this[name] = value; }
    appendChild(child) { this.children.push(child); }
    replaceChildren(...children) { this.children = children; }
}
function harness(respond) {
    const root = new Node(), button = new Node('button'), label = new Node('p'), error = new Node('p');
    const currentLink = new Node('a'), previousLink = new Node('a'), currentVersion = new Node('bdi'), previousVersion = new Node('bdi'), empty = new Node('p');
    currentLink.hidden = previousLink.hidden = true;
    root.dataset.statusUrl = '/admin/desktop-dashboard/release/status';
    root.dataset.requestUrl = '/admin/desktop-dashboard/release/request';
    const selectors = { '[data-go-erp-release-request]': button, '[data-go-erp-release-status]': label, '[data-go-erp-release-error]': error,
        '[data-go-erp-published-current]': currentLink, '[data-go-erp-published-previous]': previousLink,
        '[data-go-erp-current-version]': currentVersion, '[data-go-erp-previous-version]': previousVersion,
        '[data-go-erp-published-empty]': empty };
    root.querySelector = selector => selectors[selector] || null;
    const document = new Node(), window = new Node(), token = { content: 'fixture-csrf' };
    document.querySelector = selector => ({ '[data-go-erp-release]': root, 'meta[name="csrf-token"]': token }[selector] || null);
    document.createElement = tag => new Node(tag);
    const calls = [], timers = new Map(), storage = new Map(); let timerId = 0;
    const context = { document, window, location: new URL('https://dashboard.test/admin/desktop-dashboard'), URL, AbortController, Uint8Array,
        crypto: { getRandomValues(bytes) { bytes.fill(0xab); return bytes; } },
        sessionStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; }, clearTimeout: id => timers.delete(id),
        async fetch(url, options) {
            calls.push({ url, options });
            const value = await respond(url, options, calls.length);
            if (value instanceof Error) throw value;
            return { ok: value?.httpStatus === undefined, status: value?.httpStatus || 200,
                headers: { get: () => 'application/json' }, json: async () => value };
        } };
    vm.runInNewContext(code, context, { filename: 'go-erp-releases.js' });
    return { root, button, label, error, currentLink, previousLink, currentVersion, previousVersion, empty, document, window, calls, timers, storage,
        async tick() { const row = timers.entries().next().value; if (row) { timers.delete(row[0]); row[1](); } await flush(); } };
}
async function flush() { await new Promise(resolve => setImmediate(resolve)); }

test('opening the server page only reads status and never starts a build', async () => {
    const h = harness(async () => payload()); await flush();
    assert.equal(h.calls.length, 1); assert.equal(h.calls[0].options.method, undefined);
    assert.match(h.calls[0].url, /\/release\/status$/); assert.equal(h.timers.size, 0);
    assert.equal(h.button.disabled, false);
});

test('one explicit click sends a CSRF-bound nonce, then active polling only reads status', async () => {
    let reads = 0;
    const h = harness(async (url, options) => {
        if (options.method === 'POST') {
            const sent = JSON.parse(options.body);
            assert.deepEqual(Object.keys(sent), ['nonce']); assert.equal(sent.nonce, nonce);
            assert.equal(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
            return { accepted: true, idempotent: false, nonce, ...payload('queued', nonce) };
        }
        return ++reads === 1 ? payload() : payload('waiting-ci', nonce);
    });
    await flush(); h.button.dispatch('click'); await flush();
    assert.equal(h.calls.filter(call => call.options.method === 'POST').length, 1);
    assert.equal(h.button.disabled, true); assert.equal(h.timers.size, 1);
    await h.tick();
    assert.equal(h.calls.filter(call => call.options.method === 'POST').length, 1);
    assert.match(h.label.textContent, /فحص/);
});

test('an uncertain transport failure reuses the same idempotency nonce on retry', async () => {
    const sent = []; let failures = 1;
    const h = harness(async (url, options) => {
        if (options.method !== 'POST') return payload();
        sent.push(JSON.parse(options.body).nonce);
        if (failures--) return new Error('network uncertain');
        return { accepted: true, nonce, ...payload('running', nonce) };
    });
    await flush(); h.button.dispatch('click'); await flush();
    assert.equal(h.button.disabled, false); assert.equal(h.error.hidden, false);
    h.button.dispatch('click'); await flush();
    assert.deepEqual(sent, [nonce, nonce]); assert.equal(h.button.disabled, true);
});

test('a root ready message alone cannot invent a retained download', async () => {
    const h = harness(async () => payload('ready', nonce)); await flush();
    assert.equal(h.currentLink.hidden, true);
    assert.equal(h.timers.size, 0);
    const ready = harness(async () => payload('ready', nonce, absent(), { current: published(42), previous: absent() }));
    await flush();
    assert.equal(ready.currentLink.href, published(42).url);
    assert.equal(ready.currentVersion.textContent, '0.4.4-preview.42');
});

test('foreign download URLs are refused instead of inserted into the page', async () => {
    const h = harness(async () => payload('ready', nonce, absent(), { current: { ...published(42), url: 'https://foreign.test/setup.exe' }, previous: absent() }));
    await flush();
    assert.equal(h.currentLink.hidden, true);
    assert.equal(h.error.hidden, false);
});

test('leaving an active page disposes polling and cannot enqueue another request', async () => {
    const h = harness(async () => payload('running', nonce)); await flush();
    assert.equal(h.timers.size, 1);
    h.document.dispatch('dashboard:before-unload');
    assert.equal(h.timers.size, 0); h.button.dispatch('click'); await flush();
    assert.equal(h.calls.length, 1); assert.equal(h.root.dataset.goErpMounted, undefined);
});

test('active work discovered on page open disables duplicate update requests', async () => {
    const h = harness(async () => payload('waiting-ci', nonce)); await flush();
    h.button.dispatch('click'); await flush();
    assert.equal(h.calls.length, 1); assert.equal(h.button.disabled, true);
});

test('current and previous installers remain downloadable during build and failed CI', async () => {
    let failed = false;
    const h = harness(async () => payload(failed ? 'failed' : 'waiting-ci', nonce, absent(),
        { current: published(42), previous: published(40, 'b') }));
    await flush();
    assert.equal(h.currentLink.hidden, false); assert.equal(h.previousLink.hidden, false);
    assert.equal(h.currentLink.href, published(42).url); assert.equal(h.button.disabled, true);
    failed = true; await h.tick();
    assert.equal(h.currentLink.href, published(42).url); assert.equal(h.previousLink.href, published(40, 'b').url);
    assert.equal(h.currentVersion.textContent, '0.4.4-preview.42'); assert.equal(h.button.disabled, false);
    assert.equal(h.timers.size, 0);
});

test('successful publication promotes new current and retains the old SHA-pinned previous link', async () => {
    let ready = false;
    const h = harness(async () => payload(ready ? 'ready' : 'running', nonce, absent(), ready
        ? { current: published(44, 'c'), previous: published(42) }
        : { current: published(42), previous: absent() }));
    await flush();
    const old42 = h.currentLink.href;
    ready = true; await h.tick();
    assert.equal(h.currentLink.href, published(44, 'c').url); assert.equal(h.currentVersion.textContent, '0.4.4-preview.44');
    assert.equal(h.previousLink.href, old42); assert.equal(h.previousVersion.textContent, '0.4.4-preview.42');
    assert.equal(h.previousLink.hidden, false); assert.equal(h.calls.some(call => call.options.method === 'POST'), false);
});

test('the server template contains current, previous, and explicit update controls without duplicated strict download block', () => {
    const blade = fs.readFileSync(path.join(__dirname, '../resources/views/admin/desktop-dashboard.blade.php'), 'utf8');
    for (const attribute of ['data-go-erp-published-current', 'data-go-erp-published-previous', 'data-go-erp-release-request']) {
        assert.equal(blade.split(attribute).length - 1, 1);
    }
    assert.match(blade, /تحميل التطبيق/); assert.match(blade, /تحميل النسخة السابقة/); assert.match(blade, /تحديث التطبيق/);
    assert.equal(blade.includes('data-go-erp-release-download'), false);
});
