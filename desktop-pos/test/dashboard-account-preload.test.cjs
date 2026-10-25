'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../src/dashboard-preload.cjs'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function fixture(options = {}) {
  const calls = [], inserted = [], events = new Map(); let api;
  class Element {
    constructor(tag) { this.tagName = tag.toUpperCase(); this.attributes = new Map(); this.children = []; this.listeners = new Map(); this.isConnected = true; }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    append(...nodes) { this.children.push(...nodes); }
    after(node) { inserted.push(node); node.afterForm = this; }
    addEventListener(name, listener) { this.listeners.set(name, listener); }
    click() { const event = { prevented: false, preventDefault() { this.prevented = true; } }; return { event, result: this.listeners.get('click')?.(event) }; }
  }
  const form = new Element('form');
  form.setAttribute('method', options.method ?? 'post'); form.setAttribute('action', options.action ?? '/admin/signin');
  if (options.target !== undefined) form.setAttribute('target', options.target);
  if (options.detached) form.isConnected = false;
  for (const name of ['elements', 'value', 'password', 'email']) Object.defineProperty(form, name, { get() { throw Error('Credentials must never be read.'); } });
  const document = {
    readyState: options.readyState ?? 'complete',
    querySelector(selector) {
      if (selector === 'form[data-dashboard-login]') return options.missingForm ? null : form;
      if (selector === '[data-go-erp-account-switch]') return inserted[0] ?? null;
      throw Error('Unexpected credential or page selector: ' + selector);
    },
    createElement: tag => new Element(tag),
    addEventListener(name, listener, configuration) { events.set(name, { listener, configuration }); }
  };
  const window = { location: { href: options.url ?? 'https://fixture.invalid/admin/login' } }; window.top = options.iframe ? {} : window;
  const ipcRenderer = { invoke: async (...args) => {
    calls.push(args);
    if (options.invoke) return options.invoke(...args);
    return { ok: true, value: args[0] === 'dashboard:account-switch-available' ? options.available !== false : true };
  } };
  vm.runInNewContext(source, { URL, window, document, require: () => ({ ipcRenderer, contextBridge: { exposeInMainWorld: (_name, value) => { api = value; } } }) });
  return { api, calls, inserted, events, form, document, window, button: () => inserted[0]?.children[0], status: () => inserted[0]?.children[1] };
}

test('the isolated preload adds native account switch beside only the original login form without reading credentials', async () => {
  const f = fixture(); await flush();
  assert.equal(f.inserted.length, 1); assert.equal(f.inserted[0].afterForm, f.form);
  assert.equal(f.button().type, 'button'); assert.equal(f.button().textContent, 'تبديل الحساب');
  assert.equal(f.status().getAttribute('aria-live'), 'polite'); assert.equal(f.status().hidden, true);
  assert.deepEqual(f.calls, [['dashboard:account-switch-available', undefined]]);
});

test('login injection rejects frames, route aliases and forms outside the exact original POST action', async () => {
  for (const options of [
    { iframe: true }, { url: 'https://fixture.invalid/admin/login/' }, { url: 'https://fixture.invalid/admin/%6cogin' },
    { url: 'https://fixture.invalid/admin/dashboard' }, { url: 'file:///admin/login' }, { url: 'https://user:pass@fixture.invalid/admin/login' },
    { missingForm: true }, { detached: true }, { method: 'get' }, { action: '/admin/signin?target=other' },
    { action: '/admin/signin#other' }, { action: '/admin/signin/' }, { action: '/admin/%73ignin' },
    { action: 'https://foreign.invalid/admin/signin' }, { target: '_blank' }
  ]) {
    const f = fixture(options); await flush(); assert.equal(f.inserted.length, 0, JSON.stringify(options)); assert.equal(f.calls.length, 0);
  }
});

test('native capability refusal or a changed page during capability lookup keeps the original login untouched', async () => {
  for (const response of [{ ok: true, value: false }, { ok: false, error: 'unavailable' }]) {
    const f = fixture({ invoke: async () => response }); await flush(); assert.equal(f.inserted.length, 0);
  }
  let answer; const f = fixture({ invoke: () => new Promise(resolve => { answer = resolve; }) });
  f.window.location.href = 'https://fixture.invalid/admin/dashboard'; answer({ ok: true, value: true }); await flush();
  assert.equal(f.inserted.length, 0); assert.equal(f.form.children.length, 0);
});

test('a button click sends no account or password payload and suppresses concurrent switches', async () => {
  let finish;
  const f = fixture({ invoke: async channel => channel === 'dashboard:account-switch-available' ? { ok: true, value: true }
    : new Promise(resolve => { finish = resolve; }) }); await flush();
  const first = f.button().click(); assert.equal(first.event.prevented, true); assert.equal(f.button().disabled, true);
  const second = f.button().click(); await second.result;
  assert.equal(f.calls.filter(([channel]) => channel === 'dashboard:switch-account').length, 1);
  assert.deepEqual(f.calls.at(-1), ['dashboard:switch-account', undefined]);
  finish({ ok: true, value: { switching: true } }); await first.result;
  assert.equal(f.button().disabled, false); assert.equal(f.status().hidden, true);
});

test('a failed switch shows a fixed safe message and lets the original login remain usable', async () => {
  let attempts = 0;
  const f = fixture({ invoke: async channel => channel === 'dashboard:account-switch-available' ? { ok: true, value: true }
    : (++attempts === 1 ? { ok: false, error: 'private-token-password' } : { ok: true, value: true }) }); await flush();
  await f.button().click().result; assert.equal(f.button().disabled, false); assert.equal(f.status().hidden, false);
  assert.match(f.status().textContent, /تعذر تبديل الحساب/); assert.equal(f.status().textContent.includes('private'), false);
  assert.equal(f.form.children.length, 0); await f.button().click().result; assert.equal(attempts, 2); assert.equal(f.status().hidden, true);
});

test('DOMContentLoaded initializes once and bridge calls discard any supplied renderer arguments', async () => {
  const f = fixture({ readyState: 'loading' }); assert.equal(f.calls.length, 0); assert.equal(f.events.get('DOMContentLoaded').configuration.once, true);
  f.document.readyState = 'complete'; await f.events.get('DOMContentLoaded').listener(); await f.events.get('DOMContentLoaded').listener();
  assert.equal(f.inserted.length, 1); await f.api.switchAccount({ account: 'untrusted', password: 'must-not-forward' });
  assert.deepEqual(f.calls.at(-1), ['dashboard:switch-account', undefined]);
  let api; assert.doesNotThrow(() => vm.runInNewContext(source, { require: () => ({ contextBridge: { exposeInMainWorld: (_name, value) => { api = value; } }, ipcRenderer: {} }) }));
  assert.equal(typeof api.switchAccount, 'function');
});
