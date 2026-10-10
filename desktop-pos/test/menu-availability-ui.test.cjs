'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { randomUUID } = require('node:crypto');
const source = fs.readFileSync(path.resolve(__dirname, '../../public/dashboard/js/order-board-menu.js'), 'utf8');

// Execute the original page script, including its registered click listener.
// The DOM shim models just that page's controls; actual rendering remains in
// the separate browser fixture.
class Element {
  constructor() {
    this.dataset = new Proxy({}, { set(target, key, value) { target[key] = String(value); return true; } });
    this.children = []; this.listeners = {}; this.attributes = {}; this.value = ''; this.disabled = false;
    this.classList = { toggle() {} };
  }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  replaceChildren() { this.children = []; }
  replaceWith(other) { const index = this.parent.children.indexOf(this); other.parent = this.parent; this.parent.children[index] = other; }
  setAttribute(key, value) { this.attributes[key] = String(value); }
  addEventListener(name, callback) { this.listeners[name] = callback; }
  querySelectorAll(selector) {
    const rows = this.children.flatMap(child => [child, ...(child.querySelectorAll ? child.querySelectorAll(selector) : [])]);
    return rows.filter(child => selector === '[data-menu-availability]' && child.dataset && child.dataset.menuAvailability);
  }
  querySelector(selector) {
    const match = selector.match(/^\[data-menu-product="([0-9]+)"\]$/);
    if (match) return this.children.find(child => child.dataset.menuProduct === match[1]);
    return this.controls && this.controls[selector];
  }
}
const response = (status, payload) => ({ ok: status < 400, status, json: async () => payload });
const tick = () => new Promise(resolve => setImmediate(resolve));
async function instance(branch, storage, initial = true, revision = 1) {
  const panel = new Element(), list = new Element(), select = new Element(), form = new Element();
  select.value = branch; select.selectedOptions = [{ textContent: branch }]; form.controls = { '[name="branch"]': select };
  panel.dataset.menuUrl = '/admin/order-board/menu'; panel.dataset.menuAction = '/admin/order-board/menu';
  panel.controls = { '[data-menu-items]': list };
  for (const selector of ['[data-menu-message]', 'input[type="search"]', '[data-menu-page="previous"]', '[data-menu-page="next"]', '[data-menu-branch]', '[data-menu-count]', '[data-menu-refresh]', '[data-menu-close]']) panel.controls[selector] = new Element();
  const controls = { '#branch-menu': panel, '#ob-filters': form, '#ob-translations': { textContent: '{}' }, 'meta[name="csrf-token"]': { content: 'csrf' } };
  for (const selector of ['[data-menu-toggle]', '[data-menu-backdrop]', '[data-board-reset]']) controls[selector] = new Element();
  const posts = [], cleanups = [], generation = '00000000-0000-4000-8000-000000000001';
  const context = { URL, AbortController, Map, Number, JSON, setTimeout, clearTimeout, crypto: { randomUUID },
    sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
    document: { body: { dataset: { dashboardLocal: '1', dashboardActor: '1' } }, querySelector: key => controls[key], createElement: () => new Element(), createTextNode: value => ({ textContent: value }), addEventListener() {} },
    window: { location: { href: 'http://127.0.0.1/admin/applies-orders' }, innerWidth: 1280, addEventListener() {}, DashboardSPA: { isCurrentPage: () => true, onCleanup: callback => cleanups.push(callback) } },
    fetch: async (url, options) => {
      if (options.method === 'POST') return new Promise((resolve, reject) => posts.push({ values: JSON.parse(options.body), resolve, reject }));
      return response(200, { success: true, ready: true, can_toggle: true, desktop_generation: generation, branch: { label: branch }, items: [{ id: 1, name: 'Product', available: initial, revision: branch.startsWith('gs:') ? revision : null, options: [] }], pagination: { page: 1, last_page: 1, total: 1 } });
    }
  };
  vm.runInNewContext(source, context, { filename: 'original-order-board-menu.js' }); await tick();
  return { posts, dispose() { cleanups.forEach(callback => callback()); },
    click() { const button = list.querySelectorAll('[data-menu-availability]')[0]; return list.listeners.click({ target: { closest: () => button } }); } };
}
for (const branch of ['f:100', 'gs:60']) for (const status of [401, 403, 419]) {
  test(`original ${branch} script retains committed-lost UUID across changed-state refresh and ${status}`, async () => {
    const storage = new Map(), first = await instance(branch, storage);
    const lost = first.click(); await tick(); const frozen = first.posts[0].values;
    first.posts[0].reject(new TypeError('Server committed but response was lost')); await lost; first.dispose();
    const current = await instance(branch, storage, false, 2), denied = current.click(); await tick();
    assert.deepEqual(current.posts[0].values, frozen);
    current.posts[0].resolve(response(status, { message: 'Current session or authority temporarily unavailable' })); await denied;
    assert.equal(storage.size, 1);
    const restored = current.click(); await tick(); assert.deepEqual(current.posts[1].values, frozen);
    current.posts[1].resolve(response(200, { success: true, item: { id: 1, name: 'Product', available: false, revision: branch.startsWith('gs:') ? 2 : null, options: [] } })); await restored;
    assert.equal(storage.size, 0);
  });
}
for (const branch of ['f:100', 'gs:60']) for (const lateStatus of [200, 422]) {
  test(`original ${branch} script retains newer UUID after disposed instance replies ${lateStatus}`, async () => {
    const storage = new Map(), first = await instance(branch, storage);
    const oldPending = first.click(); await tick(); const old = first.posts[0].values;
    first.dispose(); const current = await instance(branch, storage);
    const retryPending = current.click(); await tick(); assert.deepEqual(current.posts[0].values, old);
    current.posts[0].resolve(response(200, { success: true, item: { id: 1, name: 'Product', available: false, revision: branch.startsWith('gs:') ? 2 : null, options: [] } })); await retryPending;
    const newerPending = current.click(); await tick(); const newer = current.posts[1].values;
    assert.notEqual(newer.idempotency_key, old.idempotency_key);
    current.posts[1].reject(new TypeError('Lost newer reply')); await newerPending;
    first.posts[0].resolve(response(lateStatus, lateStatus === 200 ? { success: true, item: { id: 1, available: false } } : { message: 'Rejected old reply' })); await oldPending;
    const newerRetry = current.click(); await tick(); assert.deepEqual(current.posts[2].values, newer);
    current.posts[2].resolve(response(200, { success: true, item: { id: 1, name: 'Product', available: true, revision: branch.startsWith('gs:') ? 3 : null, options: [] } })); await newerRetry;
    assert.equal(storage.size, 0);
  });
}
