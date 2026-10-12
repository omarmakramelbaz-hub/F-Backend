'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/dashboard-spa.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function actualBlock(start, end) {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, 'Exercise the actual SPA lifecycle source.');
  return source.slice(from, to);
}

function browser() {
  const document = new EventTarget(), window = new EventTarget(), instances = new WeakMap();
  const timers = new Map(), listeners = [], trail = [], modals = []; let timerId = 0;
  const classList = () => {
    const values = new Set(); return { add: value => values.add(value), remove: value => values.delete(value),
      contains: value => values.has(value), toggle(value, enabled) { enabled ? values.add(value) : values.delete(value); } };
  };
  const native = {
    add(type, callback, options) { listeners.push({ target: this, type, callback }); EventTarget.prototype.addEventListener.call(this, type, callback, options); },
    remove(type, callback, options) {
      const index = listeners.findIndex(item => item.target === this && item.type === type && item.callback === callback);
      if (index >= 0) listeners.splice(index, 1);
      EventTarget.prototype.removeEventListener.call(this, type, callback, options);
    },
    timeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout(id) { timers.delete(id); }
  };
  function emit(element, type) {
    const event = new Event(type, { cancelable: type === 'show.bs.modal' || type === 'hide.bs.modal' });
    Object.defineProperty(event, 'target', { value: element });
    // Public Bootstrap events reach the permanent document phase observer as well
    // as the modal's own lifecycle listener, without a browser-specific DOM mock.
    document.dispatchEvent(event); element.dispatchEvent(event); return event;
  }
  function modal(phase = 'shown') {
    const element = new EventTarget(); element.classList = classList(); element.style = { display: 'block' };
    element.isConnected = true; element.matches = selector => selector === '.modal';
    if (phase === 'shown') element.classList.add('show');
    const instance = {
      _element: element, phase, hideCalls: 0, disposeCalls: 0,
      hide() {
        this.hideCalls++;
        if (this.phase !== 'shown') return;
        if (emit(element, 'hide.bs.modal').defaultPrevented) return;
        this.phase = 'hiding'; element.classList.remove('show');
      },
      finishShow() {
        assert.equal(this._element, element, 'A pending vendor show callback still needs its element.');
        element.style.display = 'block'; element.classList.add('show'); this.phase = 'shown';
        emit(element, 'shown.bs.modal');
      },
      finishModalHide() {
        assert.equal(this._element, element, 'The vendor hide callback must run before disposal nulls its element.');
        this._element.style.display = 'none'; this.phase = 'backdrop'; trail.push('modal-hidden');
      },
      finishBackdropHide() {
        assert.equal(this._element, element, 'Backdrop completion still uses the live modal instance.');
        this.phase = 'hidden'; trail.push('backdrop-hidden'); emit(element, 'hidden.bs.modal');
      },
      dispose() { this.disposeCalls++; trail.push('dispose-modal'); this._element = null; }
    };
    instances.set(element, instance); modals.push(element);
    if (phase === 'showing') emit(element, 'show.bs.modal');
    if (phase === 'hiding') emit(element, 'hide.bs.modal');
    if (phase === 'hidden') emit(element, 'hidden.bs.modal');
    return { element, instance };
  }
  const outgoing = { inert: true,
    querySelectorAll: selector => selector === '.modal' ? modals : [], querySelector: () => null, contains: () => true,
    replaceChildren() { trail.push('replace-dom'); modals.forEach(element => { element.isConnected = false; }); }
  };
  const incoming = { childNodes: [{ incoming: true }], querySelectorAll: () => [] };
  const doc = { title: 'next dashboard', body: { classList: classList() },
    querySelector: selector => selector === '[data-dashboard-page]' ? incoming : selector === '.main-header' ? {} : null,
    querySelectorAll: () => [] };
  const style = { remove() { trail.push('remove-staged-style'); } };
  document.body = { classList: classList(), style: { removeProperty() {} }, appendChild() { trail.push('mount-scripts'); } };
  document.querySelector = selector => selector === '[data-dashboard-page]' ? outgoing : null;
  document.querySelectorAll = () => [];
  document.createElement = () => ({ setAttribute() {}, appendChild() {} }); document.importNode = node => node;
  window.bootstrap = { Modal: { getInstance: element => instances.get(element) } }; window.scrollTo = () => {};
  function $(target) {
    const collection = elements => ({ each(callback) { elements.forEach(element => callback.call(element)); return this; },
      addBack() { return this; }, off() { trail.push('off-widgets'); } });
    return { find: selector => collection(selector === '.modal' ? modals : []),
      modal(action) { assert.equal(action, 'dispose', 'Cleanup must never chain an asynchronous hide with disposal.'); instances.get(target)?.dispose(); return this; } };
  }
  $.fn = {}; window.jQuery = $;
  const oldPage = { closed: false, dispose() { trail.push('dispose-page'); this.closed = true; } };
  const history = { state: {}, pushState(state) { this.state = state; trail.push('history'); }, replaceState(state) { this.state = state; trail.push('history'); } };
  const context = vm.createContext({ document, window, native, WeakMap, Promise, Array, Object, Event,
    CustomEvent: class extends Event {}, URL, Error, sequence: 1, historyKey: 0, historyPosition: 0, committedUrl: '/old', committedState: null,
    history, page: oldPage, PageScope: class { constructor() { this.closed = false; } }, innerWidth: 1200,
    stageCss: async () => [style], commitCss() { trail.push('commit-css'); }, activeNavigation() {}, notify() {} });
  vm.runInContext(actualBlock('    var modalPhases = new WeakMap();', '    function currentScope() {'), context);
  vm.runInContext(actualBlock('    async function closeModals(region) {', '    function activeNavigation(url) {'), context);
  vm.runInContext(actualBlock('    async function render(doc, url, options, current) {', '    function authRedirect(response) {'), context);
  return { context, document, window, outgoing, doc, modal, emit, instances, timers, listeners, trail, oldPage,
    render: () => context.render(doc, 'https://dashboard.example/admin/resturants/100', { replace: true }, 1),
    expire() { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(item => item.callback()); }
  };
}

test('actual SPA keeps modal and page alive through modal and backdrop hide callbacks before replacing DOM', async () => {
  const env = browser(), { element, instance } = env.modal(); let settled = false;
  const rendering = env.render().then(() => { settled = true; }); await tick();
  assert.equal(instance.hideCalls, 1); assert.equal(instance.disposeCalls, 0); assert.equal(env.oldPage.closed, false);
  assert.equal(element.isConnected, true); assert.equal(env.trail.includes('history'), false);
  instance.finishModalHide(); await tick(); assert.equal(settled, false); assert.equal(instance._element, element);
  assert.equal(env.oldPage.closed, false); assert.equal(env.trail.includes('replace-dom'), false);
  instance.finishBackdropHide(); await rendering;
  assert.equal(instance.disposeCalls, 1); assert.equal(instance._element, null); assert.equal(env.oldPage.closed, true);
  assert.equal(element.isConnected, false); assert.equal(env.timers.size, 0);
  assert.ok(env.trail.indexOf('backdrop-hidden') < env.trail.indexOf('dispose-page'));
  assert.ok(env.trail.indexOf('dispose-page') < env.trail.indexOf('dispose-modal'));
  assert.ok(env.trail.indexOf('dispose-modal') < env.trail.indexOf('replace-dom'));
});

test('public showing and already-hiding phases complete without duplicate hides or premature disposal', async () => {
  const env = browser(), showing = env.modal('showing'), hiding = env.modal('hiding'); let settled = false;
  const closing = env.context.closeModals(env.outgoing).then(() => { settled = true; });
  assert.equal(showing.instance.hideCalls, 0); assert.equal(hiding.instance.hideCalls, 0);
  hiding.instance.finishModalHide(); hiding.instance.finishBackdropHide(); await tick(); assert.equal(settled, false);
  showing.instance.finishShow(); assert.equal(showing.instance.hideCalls, 1); assert.equal(showing.instance._element, showing.element);
  showing.instance.finishModalHide(); await tick(); assert.equal(settled, false);
  showing.instance.finishBackdropHide(); await closing;
  assert.equal(showing.instance.disposeCalls, 0); assert.equal(hiding.instance.disposeCalls, 0);
  assert.equal(env.listeners.filter(item => item.target !== env.document).length, 0); assert.equal(env.timers.size, 0);
  env.context.destroyWidgets(env.outgoing); assert.equal(showing.instance.disposeCalls, 1); assert.equal(hiding.instance.disposeCalls, 1);
});

test('a superseding navigation after modal wait leaves the old page and DOM undisposed and discards staged styles', async () => {
  const env = browser(), { element, instance } = env.modal(); const rendering = env.render(); await tick();
  env.context.sequence = 2; instance.finishModalHide(); instance.finishBackdropHide(); await rendering;
  assert.equal(instance.disposeCalls, 0); assert.equal(instance._element, element); assert.equal(element.isConnected, true);
  assert.equal(env.oldPage.closed, false); assert.equal(env.trail.includes('history'), false); assert.equal(env.trail.includes('replace-dom'), false);
  assert.equal(env.trail.filter(value => value === 'remove-staged-style').length, 1);
});

test('cancelled hides retain public shown state and bounded failure preserves the original page and live instance', async () => {
  const env = browser(), { element, instance } = env.modal();
  env.document.addEventListener('hide.bs.modal', event => event.preventDefault());
  const rendering = env.render(); const rejected = assert.rejects(rendering, /could not finish closing/); await tick();
  assert.equal(env.context.modalPhase(element), 'shown');
  assert.equal([...env.timers.values()][0].delay, 2000); env.expire(); await rejected;
  assert.equal(instance.disposeCalls, 0); assert.equal(instance._element, element); assert.equal(element.isConnected, true);
  assert.equal(env.oldPage.closed, false); assert.equal(env.trail.includes('history'), false); assert.equal(env.trail.includes('replace-dom'), false);
  assert.equal(env.trail.filter(value => value === 'remove-staged-style').length, 1);
  assert.equal(env.listeners.filter(item => item.target === element).length, 0);
});

test('cancelled show and closed modals do not wait for an event that will never occur', async () => {
  const env = browser(), closed = env.modal('hidden');
  env.document.addEventListener('show.bs.modal', event => event.preventDefault());
  const cancelled = env.modal('hidden'); env.emit(cancelled.element, 'show.bs.modal');
  assert.equal(env.context.modalPhase(cancelled.element), 'hidden');
  await env.context.closeModals(env.outgoing);
  assert.equal(env.timers.size, 0); assert.equal(closed.instance.hideCalls, 0); assert.equal(cancelled.instance.hideCalls, 0);
  assert.equal(closed.instance._element, closed.element); assert.equal(cancelled.instance._element, cancelled.element);
});

test('a vendor hide exception removes its lifecycle listeners and timer without disposing the old page', async () => {
  const env = browser(), { element, instance } = env.modal(); instance.hide = () => { throw Error('vendor hide failure'); };
  await assert.rejects(env.render(), /vendor hide failure/);
  assert.equal(env.timers.size, 0); assert.equal(env.listeners.filter(item => item.target === element).length, 0);
  assert.equal(instance._element, element); assert.equal(instance.disposeCalls, 0); assert.equal(env.oldPage.closed, false);
  assert.equal(env.trail.includes('replace-dom'), false);
});
