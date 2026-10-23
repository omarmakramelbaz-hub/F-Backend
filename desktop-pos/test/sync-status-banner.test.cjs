'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/desktop-sync-status.js'), 'utf8');
class Element {
  constructor(name) { this.name = name; this.style = {}; this.attributes = {}; this.children = []; this.handlers = new Map(); this.textContent = ''; this.hidden = false; this.disabled = false; }
  setAttribute(name, value) { this.attributes[name] = value; }
  append(child) { this.children.push(child); child.parent = this; }
  addEventListener(name, handler) { this.handlers.set(name, handler); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(value => value !== this); }
  click() { return this.handlers.get('click')?.(); }
}
function fixture({ bridge = true, local = true } = {}) {
  const body = new Element('body'); body.dataset = { dashboardLocal: local ? '1' : '0' };
  const events = new Map(), timers = new Map(); let nextTimer = 0, subscribed = 0, disposed = 0, reviews = 0, failed = false;
  let state = { available: true, prepared: true, mode: 'local', conflicts: 0, error: '' }, listener, reply;
  const native = { status: async () => reply ? await reply : state,
    onState: callback => { listener = callback; subscribed++; return () => { disposed++; }; },
    reviewSync: async () => { reviews++; if (failed) throw Error('PRIVATE account data'); } };
  const window = { addEventListener: (name, callback) => events.set(name, callback) }; if (bridge) window.FasakhanstaDesktop = native;
  const document = { body, createElement: name => new Element(name), addEventListener: (name, callback) => events.set(name, callback) };
  const context = { window, document, setInterval: callback => { timers.set(++nextTimer, callback); return nextTimer; }, clearInterval: id => timers.delete(id) };
  vm.runInNewContext(source, context);
  return { body, events, timers, context, native, get banner() { return body.children[0]; },
    state(value) { state = { ...state, ...value }; listener?.(state); },
    async ready() { await new Promise(resolve => setImmediate(resolve)); },
    fail() { failed = true; }, pending(value) { reply = value; }, counts: () => ({ subscribed, disposed, reviews }) };
}
test('normal browser and server window never receive a conflict notice or new write capability', async () => {
  const browser = fixture({ bridge: false }); await browser.ready(); assert.equal(browser.body.children.length, 0); assert.equal(browser.timers.size, 0);
  const server = fixture({ local: false }); await server.ready(); server.state({ conflicts: 1 }); assert.equal(server.body.children.length, 0);
});
test('a local conflict raises one persistent clear notice with only its count and fixed review guidance', async () => {
  const f = fixture(); await f.ready(); assert.equal(f.body.children.length, 0);
  f.state({ conflicts: 1, error: 'PRIVATE user-name account details <img src=x>' });
  assert.equal(f.body.children.length, 1); assert.equal(f.banner.hidden, false); assert.equal(f.banner.attributes.role, 'alert');
  assert.match(f.banner.children[0].textContent, /العدد: 1/); assert.match(f.banner.children[0].textContent, /متوقف حتى تسوية التعارض/);
  assert.doesNotMatch(f.banner.children.map(value => value.textContent).join(' '), /PRIVATE|user-name|img/);
  const first = f.banner; f.state({ conflicts: 3 }); assert.equal(f.banner, first); assert.match(f.banner.children[0].textContent, /العدد: 3/);
  f.state({ conflicts: 0 }); assert.equal(first.hidden, true); f.state({ conflicts: 1 }); assert.equal(first.hidden, false);
});
test('review invokes only the established review action and exposes no raw error', async () => {
  const f = fixture(); await f.ready(); f.state({ conflicts: 1 });
  await f.banner.children[1].click(); assert.equal(f.counts().reviews, 1);
  f.fail(); await f.banner.children[1].click(); assert.equal(f.counts().reviews, 2);
  assert.match(f.banner.children[2].textContent, /تعذر فتح/); assert.doesNotMatch(f.banner.children[2].textContent, /PRIVATE/);
  assert.equal(f.banner.children[1].disabled, false); assert.equal(Object.hasOwn(f.native, 'synchronize'), false);
});
test('unprepared, nonlocal and invalid counts cannot claim a local conflict', async () => {
  for (const value of [{ prepared: false }, { available: false }, { mode: 'server' }, { conflicts: '1' }, { conflicts: Infinity }, { conflicts: -1 }]) {
    const f = fixture(); await f.ready(); f.state({ conflicts: 1, ...value }); assert.equal(f.body.children.length, 0);
  }
});
test('older polling replies cannot hide a newly published conflict', async () => {
  const f = fixture(); await f.ready(); let resolve; f.pending(new Promise(done => { resolve = done; }));
  const poll = [...f.timers.values()][0](); f.state({ conflicts: 1 }); resolve({ available: true, prepared: true, mode: 'local', conflicts: 0 }); await poll;
  assert.equal(f.banner.hidden, false);
});
test('pagehide removes the notice and subscription; history restores one live notice', async () => {
  const f = fixture(); await f.ready(); f.state({ conflicts: 1 }); f.events.get('pagehide')();
  assert.equal(f.body.children.length, 0); assert.equal(f.timers.size, 0); assert.equal(f.counts().disposed, 1);
  f.events.get('pageshow')(); await f.ready(); assert.equal(f.banner.hidden, false); assert.equal(f.timers.size, 1);
  f.events.get('pageshow')(); assert.equal(f.counts().subscribed, 2);
});
