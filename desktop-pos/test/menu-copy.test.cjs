'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const Attempts = require('../src/dashboard-remote-attempts.cjs'), State = require('../src/dashboard-remote-state.cjs');
const read = name => fs.readFileSync(path.join(__dirname, '../../' + name), 'utf8');
const renderer = read('public/dashboard/js/desktop-dashboard.js'), spa = read('public/dashboard/js/dashboard-spa.js');
const footer = read('resources/views/admin/layouts/footer.blade.php');
const origin = 'https://dashboard.example', copyPath = '/admin/resturant/copy/items';
const tick = () => new Promise(resolve => setImmediate(resolve));

async function originalCopy(options = {}) {
  const listeners = [], requests = [], pending = [], visits = [], observers = [], issued = [];
  class Form {
    constructor() {
      this.action = new URL(options.path || copyPath, origin).href; this.method = options.method || 'post';
      this.target = options.target || ''; this.isConnected = true; this.dataset = {};
      this.fields = [{ name: '_token', value: 'original-copy-csrf' }, { name: 'resturant_id', value: '100' },
        ...['92', '7', '15'].map(value => ({ name: 'restraunt_product[]', value, type: 'checkbox', checked: false }))];
      if (options.overrideMethod) this.fields.push({ name: '_method', value: options.overrideMethod });
      this.fields.forEach(field => { field.form = this; });
      this.button = { tagName: 'BUTTON', type: 'submit', name: options.named ? 'copy-submit' : '', form: this,
        disabled: false, innerHTML: 'original Save changes', hasAttribute: name => name === options.override, getAttribute: () => null };
    }
    get elements() { return [...this.fields, this.button]; }
    querySelector(selector) { const match = /^\[name="([^"]+)"\]$/.exec(selector); return match ? this.fields.find(field => field.name === match[1]) || null : null; }
    querySelectorAll() { return [this.button]; }
    append(field) { field.form = this; this.fields.push(field); }
    hasAttribute(name) { return options.off && name === 'data-spa-off'; }
    getAttribute(name) { return name === 'action' ? this.action : name === 'method' ? this.method : name === 'target' ? this.target : null; }
  }
  const form = new Form(), all = [form], master = { checked: false };
  const document = { body: { dataset: { dashboardLocal: options.local === false ? '0' : '1', dashboardRemoteAttempts: '1' } }, documentElement: {},
    addEventListener(type, callback, capture) { listeners.push({ type, callback, capture: capture === true }); },
    querySelectorAll: selector => selector === 'form' ? all : [],
    querySelector: selector => selector === '[data-dashboard-page]' ? options.page === false ? null : {}
      : selector === 'meta[name="csrf-token"]' ? { content: 'original-copy-csrf' } : null,
    createElement: () => ({}) };
  const window = { DashboardSPA: { ready: () => options.ready !== false } };
  if (options.bridge !== false) window.FasakhanstaDesktop = { status: async () => ({ prepared: options.prepared !== false }) };
  const native = { add: document.addEventListener, submit() { throw Error('Copy retry must retain its original form.'); },
    fetch(url, settings) { requests.push({ url, ...settings }); return new Promise((resolve, reject) => pending.push({ resolve, reject })); } };
  const context = vm.createContext({ document, window, native, HTMLFormElement: Form, URL, URLSearchParams,
    location: { origin, href: origin + '/admin/resturants/100?q=fish&page=2' },
    FormData: class {
      constructor(actual) { this.entries = actual.elements.filter(field => field.name && !field.disabled
        && (field.type !== 'checkbox' || field.checked)).map(field => [field.name, field.value]); }
      append(name, value) { this.entries.push([name, value]); }
      getAll(name) { return this.entries.filter(entry => entry[0] === name).map(entry => entry[1]); }
    }, File: class {}, MutationObserver: class { constructor(callback) { observers.push(callback); } observe() {} },
    crypto: { randomUUID() { const id = crypto.randomUUID(); issued.push(id); return id; } },
    started: true, submitting: false, navigation: null, sequence: 0, pendingHistory: null,
    eligible: value => new URL(value).origin === origin, authRedirect: () => false, busy() {}, notify() {}, message: value => value,
    visit: async value => { visits.push(value); }, console: { error() {} }, Promise, Array, Object });
  await vm.runInContext(renderer, context);
  const start = spa.indexOf('    function submitButtons(form) {'), end = spa.indexOf('    function start() {', start);
  assert.ok(start >= 0 && end > start); vm.runInContext(spa.slice(start, end), context);
  const capture = spa.split('\n').find(line => line.includes("native.add.call(document, 'submit', function (event) { if (event.target instanceof HTMLFormElement)"));
  assert.ok(capture); vm.runInContext(capture, context);
  let selectAll, spinner;
  const originalForms = { not: selector => { assert.equal(selector, '[data-wa-reply-form]'); return originalForms; }, submit: callback => { spinner = callback; } };
  context.$ = value => value === '#master' ? { on: (_type, callback) => { selectAll = callback; } }
    : value === master ? { is: () => master.checked }
    : value === '.sub_chk' ? { prop: (_name, value) => form.fields.filter(field => field.type === 'checkbox').forEach(field => { field.checked = value; }) }
    : value === 'form' ? originalForms
    : { find: () => ({ html: value => { form.button.innerHTML = value; } }) };
  const selectStart = footer.indexOf("        $('#master').on('click', function(e) {"), selectEnd = footer.indexOf('        });', selectStart) + 11;
  assert.ok(selectStart >= 0); vm.runInContext(footer.slice(selectStart, selectEnd), context);
  const spinStart = footer.indexOf('    $( "form" ).not("[data-wa-reply-form]").submit(function() {'), spinEnd = footer.indexOf('    });', spinStart) + 7;
  assert.ok(spinStart >= 0); vm.runInContext(footer.slice(spinStart, spinEnd).replace("@lang('main.under click')", 'جاري التنفيذ'), context);
  const submit = () => {
    const event = { target: form, submitter: options.noSubmitter ? null : options.foreignSubmitter ? { ...form.button, form: {} } : form.button,
      defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    listeners.filter(item => item.type === 'submit' && item.capture).forEach(item => item.callback(event));
    if (event.defaultPrevented) spinner.call(form); return event;
  };
  return { form, all, Form, requests, visits, issued, submit, mutate: () => observers.forEach(callback => callback()),
    selectAll(value) { master.checked = value; selectAll.call(master); },
    async settle(result) {
      const request = pending.shift(); assert.ok(request);
      if (result === 'lost') request.reject(Error('Committed original copy response was lost.'));
      else request.resolve({ ok: result !== 409, url: origin + '/admin/resturants/100?q=fish&page=2',
        headers: { get: () => result === 409 ? 'application/json' : 'text/html' }, json: async () => ({ error: 'changed ordered selection' }) });
      await tick();
    }
  };
}

test('original copy select-all and SPA preserve DOM order, selection and one UUID through lost response and changed409 retry', async () => {
  const env = await originalCopy(); env.selectAll(true);
  const command = env.form.querySelector('[name="_desktop_command"]').value;
  for (const [selected, result] of [[true, 'lost'], [false, 409], [true, 302]]) {
    env.form.fields.find(field => field.value === '7').checked = selected;
    assert.equal(env.form.button.disabled, false); assert.equal(env.submit().defaultPrevented, true);
    assert.equal(env.form.button.disabled, true); await env.settle(result); env.mutate();
    assert.equal(env.form.button.disabled, false); assert.equal(env.form.button.innerHTML, 'original Save changes');
    assert.equal(env.form.isConnected, true); assert.equal(env.issued.length, 1);
  }
  assert.deepEqual(env.requests.map(request => Array.from(request.body.getAll('restraunt_product[]'))), [['92', '7', '15'], ['92', '15'], ['92', '7', '15']]);
  for (const request of env.requests) {
    assert.equal(request.url, origin + copyPath); assert.equal(request.method, 'POST'); assert.equal(request.credentials, 'same-origin');
    assert.deepEqual(Array.from(request.body.getAll('_desktop_command')), [command]);
    assert.deepEqual(Array.from(request.body.getAll('_token')), ['original-copy-csrf']);
    assert.deepEqual(Array.from(request.body.getAll('resturant_id')), ['100']);
    assert.deepEqual(Array.from(request.body.getAll('_method')), []);
    assert.equal(request.body.entries.length, request.body.getAll('restraunt_product[]').length + 3);
    assert.equal(Object.keys(request.headers).some(name => /content-type|fasakhansta-remote|fasakhansta-desktop/i.test(name)), false);
  }
  assert.equal(env.visits.length, 1);
  const replacement = new env.Form(); env.all.splice(0, 1, replacement); env.mutate();
  assert.notEqual(replacement.querySelector('[name="_desktop_command"]').value, command);
});

test('copy capture and UUID preparation stay limited to the original method/path, owned submitter and prepared session', async () => {
  for (const options of [
    { path: 'https://other.example' + copyPath }, { path: 'https://user:pass@dashboard.example' + copyPath },
    { path: copyPath + '/' }, { path: '/admin/resturant/copy' }, { path: '/admin/resturants/copy/items' },
    { method: 'get' }, { overrideMethod: 'PUT' }, { overrideMethod: 'DELETE' },
    { off: true }, { target: '_blank' }, { ready: false }, { page: false }, { named: true }, { noSubmitter: true }, { foreignSubmitter: true },
    { local: false, prepared: false }, { local: false, bridge: false },
    ...['formaction', 'formmethod', 'formtarget', 'formenctype'].map(override => ({ override }))
  ]) {
    const env = await originalCopy(options); assert.equal(env.submit().defaultPrevented, false, JSON.stringify(options));
    assert.equal(env.requests.length, 0, JSON.stringify(options));
  }
  const prepared = await originalCopy({ local: false }); assert.equal(prepared.issued.length, 1);
});

function native() {
  const records = new Map(), calls = [], credential = { deviceId: crypto.randomUUID(), actorId: 1, serverOrigin: origin, token: 'a'.repeat(64) };
  const metadata = { read: async key => structuredClone(records.get(key) || null), write: async (key, value) => records.set(key, structuredClone(value)) };
  const state = new State(metadata); let committed = false;
  const request = async value => { calls.push(value); return { format: 1, id: value.id, device_id: credential.deviceId, actor_id: 1,
    method: value.method, path: value.path, capability: 'b'.repeat(64), status: value.action === 'reserve' ? 'ready' : committed ? 'committed' : 'cancelled' }; };
  const options = { state, metadata, credential: async () => credential, request };
  return { records, calls, state, options, attempts: new Attempts(options), commit() { committed = true; } };
}

test('copy native reservation adds only exact POST and keeps original multipart selection outside native metadata', async () => {
  const f = native();
  for (const method of ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']) assert.equal(f.attempts.supported({ url: origin + copyPath, method }), false);
  for (const path of [copyPath + '/', '/admin/resturant/copy', '/admin/resturants/copy/items', '/api/resturant/copy/items'])
    assert.equal(f.attempts.supported({ url: origin + path, method: 'POST' }), false);
  const id = crypto.randomUUID(), details = { url: origin + copyPath, method: 'POST' };
  Object.defineProperty(details, 'uploadData', { get() { throw Error('Native attempts cannot inspect the original copy multipart body.'); } });
  const proof = await f.attempts.begin(id, details);
  assert.equal(proof['X-Fasakhansta-Remote-Attempt'], id);
  assert.deepEqual(f.calls, [{ action: 'reserve', id, method: 'POST', path: copyPath }]);
  assert.deepEqual(Object.keys(f.records.get('remote-attempts/' + id)).sort(), ['actorId', 'deviceId', 'format', 'id', 'method', 'path', 'serverOrigin'].sort());
  assert.deepEqual((await f.state.read()).pending, [id]);
  await assert.rejects(f.attempts.begin(crypto.randomUUID(), { url: 'https://foreign.example' + copyPath, method: 'POST' }));
  assert.equal(f.calls.length, 1);
});

test('copy lost outcomes and revoked binding retain the same attempt until current server authority returns a terminal decision', async () => {
  for (const committed of [false, true]) {
    const f = native(), id = crypto.randomUUID(), details = { url: origin + copyPath, method: 'POST' };
    if (committed) { await f.attempts.begin(id, details); f.commit(); f.attempts.failed(id); }
    else {
      f.attempts.request = async value => { await f.options.request(value); throw Error('lost reserve reply'); };
      await assert.rejects(f.attempts.begin(id, details), /lost reserve reply/);
    }
    await assert.rejects(new Attempts({ ...f.options, credential: async () => null }).recover());
    await assert.rejects(new Attempts({ ...f.options, request: async () => { throw Error('source or target authority revoked'); } }).recover());
    assert.deepEqual((await f.state.read()).pending, [id]);
    await new Attempts(f.options).recover();
    assert.deepEqual(f.calls, [{ action: 'reserve', id, method: 'POST', path: copyPath }, { action: 'settle', id, method: 'POST', path: copyPath }]);
    assert.deepEqual((await f.state.read()).pending, []); assert.equal((await f.state.read()).dirty, true);
    assert.equal(f.records.get('remote-attempts/' + id).receipt.status, committed ? 'committed' : 'cancelled');
  }
});
