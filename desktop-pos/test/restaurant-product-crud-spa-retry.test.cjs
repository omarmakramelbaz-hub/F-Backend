'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const { File } = require('node:buffer');
const read = name => fs.readFileSync(path.join(__dirname, '../../' + name), 'utf8');
const renderer = read('public/dashboard/js/desktop-dashboard.js'), spa = read('public/dashboard/js/dashboard-spa.js');
const footer = read('resources/views/admin/layouts/footer.blade.php');
const origin = 'https://dashboard.example', originalIcon = '<i class="fa fa-save"></i>حفظ';
const paths = { store: '/admin/resturant_products', update: '/admin/resturant_products/update', destroy: '/admin/resturant_products/20' };

async function browser(kind = 'store', options = {}) {
  const listeners = [], observers = [], requests = [], pending = [], visits = [], notices = [], issued = [], natural = [];
  const originalFile = new File([Buffer.from('original selected image bytes')], 'product.png', { type: 'image/png' });
  class Form {
    constructor() {
      this.action = new URL(options.path || paths[kind], origin).href; this.method = options.method || 'post';
      this.target = options.target || ''; this.dataset = {}; this.isConnected = true; this.attributes = new Set(options.off ? ['data-spa-off'] : []);
      this.fields = [{ name: '_token', value: 'original-product-csrf' }];
      if (kind !== 'store') this.fields.push({ name: '_method', value: kind === 'update' ? 'put' : 'DELETE' });
      if (kind !== 'destroy') this.fields.push(
        { name: 'resturant_id', value: '100' }, { name: 'added_by', value: '1' }, { name: 'category_id', value: '10' },
        { name: 'product_id', value: '12' }, { name: 'product_name', value: 'فسخنستا' }, { name: 'product_price', value: '5' },
        { name: 'product_description', value: 'original description' }, { name: 'product_image', type: 'file', files: [originalFile] }
      );
      if (kind === 'update') this.fields.push({ name: 'id', value: '20' });
      this.fields.forEach(field => { field.form = this; });
      this.button = { tagName: 'BUTTON', type: 'submit', name: options.named ? 'original-button' : '', form: this, disabled: false, innerHTML: originalIcon,
        hasAttribute: name => name === options.override, getAttribute: () => null };
    }
    get elements() { return [...this.fields.filter(field => field.form === this), this.button]; }
    querySelector(selector) { const match = /^\[name="([^"]+)"\]$/.exec(selector); return match ? this.fields.find(field => field.name === match[1] && !field.external) || null : null; }
    querySelectorAll() { return [this.button]; }
    append(field) { field.form = this; this.fields.push(field); }
    hasAttribute(name) { return this.attributes.has(name); }
    getAttribute(name) { return name === 'action' ? this.action : name === 'method' ? this.method : name === 'target' ? this.target : null; }
  }
  const form = new Form(), all = [form];
  if (options.overrideMethod) form.fields.find(field => field.name === '_method').value = options.overrideMethod;
  const document = {
    body: { dataset: { dashboardLocal: options.local === false ? '0' : '1', dashboardRemoteAttempts: '1' } }, documentElement: {},
    addEventListener(type, callback, capture) { listeners.push({ type, callback, capture: capture === true }); },
    querySelectorAll: selector => selector === 'form' ? all : [],
    querySelector: selector => selector === '[data-dashboard-page]' ? options.page === false ? null : {}
      : selector === 'meta[name="csrf-token"]' ? { content: 'original-product-csrf' } : null,
    createElement(tag) { assert.equal(tag, 'input'); return {}; }
  };
  const native = { add: document.addEventListener,
    submit() { natural.push(this); },
    fetch(url, request) { requests.push({ url, ...request }); return new Promise((resolve, reject) => pending.push({ resolve, reject })); }
  };
  const window = { DashboardSPA: { ready: () => options.ready !== false } };
  if (options.bridge !== false) window.FasakhanstaDesktop = { status: async () => ({ prepared: options.prepared !== false }) };
  const context = vm.createContext({ document, window, HTMLFormElement: Form, URL, URLSearchParams,
    location: { origin, href: origin + '/admin/resturants/100?q=%D9%81&page=3' }, File,
    FormData: class {
      constructor(actual) {
        this.entries = actual.elements.filter(field => field.name && !field.disabled && field.tagName !== 'BUTTON')
          .map(field => [field.name, field.type === 'file' ? field.files[0] : field.value]);
      }
      append(name, value) { this.entries.push([name, value]); }
      get(name) { return this.entries.find(entry => entry[0] === name)?.[1] ?? null; }
      getAll(name) { return this.entries.filter(entry => entry[0] === name).map(entry => entry[1]); }
    },
    MutationObserver: class { constructor(callback) { observers.push(callback); } observe() {} },
    crypto: { randomUUID() { const id = 'd3030303-3030-4030-8030-' + String(issued.length + 1).padStart(12, '0'); issued.push(id); return id; } },
    native, started: true, submitting: false, navigation: null, sequence: 0, pendingHistory: null,
    eligible: value => { const url = new URL(value, origin); return url.origin === origin && !url.username && !url.password; },
    authRedirect: () => false, busy() {}, message: value => value, notify: value => notices.push(value),
    visit: async value => { visits.push(value); }, console: { error() {} }, Promise, Array, Object
  });
  await vm.runInContext(renderer, context);
  const start = spa.indexOf('    function submitButtons(form) {'), end = spa.indexOf('    function start() {', start);
  assert.ok(start >= 0 && end > start); vm.runInContext(spa.slice(start, end), context);
  const capture = spa.split('\n').find(line => line.includes("native.add.call(document, 'submit', function (event) { if (event.target instanceof HTMLFormElement)"));
  assert.ok(capture); vm.runInContext(capture, context);
  let footerSubmit, confirm;
  context.$ = value => value === 'form' ? { submit: callback => { footerSubmit = callback; } }
    : value === '.show_confirm' ? { click: callback => { confirm = callback; } }
    : value === form.button ? { data: () => undefined, closest: () => ({ submit: () => { footerSubmit.call(form); Form.prototype.submit.call(form); } }) }
    : { find: () => ({ html: value => { form.button.innerHTML = value; } }) };
  context.swal = () => Promise.resolve(context.confirmed);
  const footerStart = footer.indexOf('    $( "form" ).submit(function() {'), footerEnd = footer.indexOf('    });', footerStart) + 7;
  assert.ok(footerStart >= 0); vm.runInContext(footer.slice(footerStart, footerEnd).replace("@lang('main.under click')", 'جاري التنفيذ'), context);
  const confirmStart = footer.indexOf("    $('.show_confirm').click(function(event) {"), confirmEnd = footer.indexOf('$(function() {', confirmStart);
  assert.ok(confirmStart >= 0 && confirmEnd > confirmStart); vm.runInContext(footer.slice(confirmStart, confirmEnd), context);
  const event = () => ({ target: form, submitter: options.noSubmitter ? null : options.foreignSubmitter ? { ...form.button, form: {} } : form.button,
    defaultPrevented: Boolean(options.prevented), preventDefault() { this.defaultPrevented = true; } });
  const captureSubmit = () => {
    const current = event();
    for (const listener of listeners.filter(item => item.type === 'submit' && item.capture)) listener.callback(current);
    return current;
  };
  const click = () => {
    if (form.button.disabled) return false;
    const current = captureSubmit(); assert.equal(current.defaultPrevented, true);
    footerSubmit.call(form); return true;
  };
  const settle = async result => {
    const request = pending.shift(); assert.ok(request, 'The original SPA must issue exactly one preserved-form request.');
    if (result === 'lost') request.reject(Error('The committed original response was lost.'));
    else request.resolve({ ok: result !== 409, url: origin + '/admin/resturants/100?q=%D9%81&page=3',
      headers: { get: () => result === 409 ? 'application/json' : 'text/html' },
      json: async () => ({ message: 'Changed UUID payload', error: 'conflict' }) });
    await new Promise(resolve => setImmediate(resolve));
  };
  return { form, all, Form, requests, visits, issued, natural, originalFile, click, captureSubmit, settle,
    mutate: () => observers.forEach(callback => callback()),
    async confirmDelete(value) {
      context.confirmed = value; let prevented = false;
      confirm.call(form.button, { preventDefault() { prevented = true; } });
      await new Promise(resolve => setImmediate(resolve)); assert.equal(prevented, true);
    }
  };
}

test('actual original CRUD controls keep their UUID, selected file and usable button after loss, changed409 and original retry', async () => {
  for (const kind of ['store', 'update', 'destroy']) {
    const env = await browser(kind), command = env.form.querySelector('[name="_desktop_command"]').value;
    for (const [value, result] of [['5', 'lost'], ['6', 409], ['5', 302]]) {
      if (kind === 'destroy') env.form.action = origin + '/admin/resturant_products/' + (value === '6' ? '21' : '20');
      else env.form.querySelector('[name="product_price"]').value = value;
      assert.equal(env.click(), true); assert.equal(env.form.button.disabled, true);
      await env.settle(result); assert.equal(env.form.button.disabled, false); assert.equal(env.form.button.innerHTML, originalIcon);
      assert.equal(env.form.isConnected, true); env.mutate(); assert.equal(env.issued.length, 1);
    }
    assert.equal(env.requests.length, 3); assert.equal(env.visits.length, 1); assert.equal(env.natural.length, 0);
    for (const request of env.requests) {
      assert.equal(request.method, 'POST'); assert.equal(request.credentials, 'same-origin');
      assert.deepEqual(Array.from(request.body.getAll('_desktop_command')), [command]);
      assert.equal(request.body.get('_token'), 'original-product-csrf');
      assert.equal(request.body.get('_method'), kind === 'update' ? 'put' : kind === 'destroy' ? 'DELETE' : null);
      assert.equal(Object.keys(request.headers).some(name => /content-type|fasakhansta-remote|fasakhansta-desktop/i.test(name)), false);
      if (kind !== 'destroy') {
        assert.equal(request.body.get('product_image'), env.originalFile);
        assert.equal(Buffer.from(await request.body.get('product_image').arrayBuffer()).toString(), 'original selected image bytes');
        assert.equal(env.form.querySelector('[name="product_image"]').files[0], env.originalFile);
      }
    }
  }
});

test('CRUD preparation uses only enabled controls owned by the actual form, including an externally associated method override', async () => {
  const env = await browser('update'), form = env.form, command = form.querySelector('[name="_desktop_command"]').value;
  const method = form.querySelector('[name="_method"]'); method.external = true;
  form.fields.unshift({ name: '_method', value: 'PATCH', form: {} }, { name: '_desktop_command', value: 'foreign-command', form: {} });
  env.mutate(); assert.equal(env.issued.length, 1);
  assert.equal(env.click(), true); await env.settle('lost');
  assert.equal(env.requests[0].body.get('_method'), 'put'); assert.deepEqual(Array.from(env.requests[0].body.getAll('_desktop_command')), [command]);
  method.disabled = true; assert.equal(env.captureSubmit().defaultPrevented, false);
  assert.equal(env.requests.length, 1);
});

test('product capture leaves foreign, overridden, inactive and unreviewed original forms alone', async () => {
  const scenarios = [
    { path: 'https://other.example/admin/resturant_products' }, { path: 'https://user:pass@dashboard.example/admin/resturant_products' },
    { path: '/admin/resturant/copy/items' }, { path: '/admin/resturants/20' }, { path: '/admin/resturant_products/' },
    { path: '/admin/resturant_products/0', kind: 'destroy' }, { path: '/admin/resturant_products/01', kind: 'destroy' },
    { path: '/admin/resturant_products/99999999999999999999', kind: 'destroy' },
    { method: 'get' }, { kind: 'update', overrideMethod: 'PATCH' }, { kind: 'destroy', overrideMethod: 'PUT' },
    { off: true }, { target: '_blank' }, { ready: false }, { page: false }, { prevented: true }, { foreignSubmitter: true }, { noSubmitter: true }, { named: true },
    ...['formaction', 'formmethod', 'formtarget', 'formenctype'].map(override => ({ override }))
  ];
  for (const scenario of scenarios) {
    const env = await browser(scenario.kind || 'store', scenario), event = env.captureSubmit();
    assert.equal(event.defaultPrevented, Boolean(scenario.prevented), JSON.stringify(scenario));
    assert.equal(env.requests.length, 0, JSON.stringify(scenario));
    assert.equal(env.natural.length, 0, JSON.stringify(scenario));
  }
});

test('CRUD UUID preparation stays scoped to local or verified prepared desktop sessions and fresh replacement forms', async () => {
  for (const options of [{ local: false, prepared: false }, { local: false, bridge: false }]) {
    const env = await browser('store', options); assert.equal(env.issued.length, 0);
    assert.equal(env.captureSubmit().defaultPrevented, false); assert.equal(env.requests.length, 0);
  }
  const env = await browser('store', { local: false });
  const first = env.form.querySelector('[name="_desktop_command"]').value, replacement = new env.Form();
  env.all.splice(0, 1, replacement); env.mutate();
  assert.equal(env.issued.length, 2); assert.notEqual(replacement.querySelector('[name="_desktop_command"]').value, first);
});

test('actual original delete confirmation can cancel without sending and retains its command after a confirmed lost reply', async () => {
  const env = await browser('destroy'), command = env.form.querySelector('[name="_desktop_command"]').value;
  await env.confirmDelete(false); assert.equal(env.requests.length, 0);
  await env.confirmDelete(true); assert.equal(env.requests.length, 1);
  await env.settle('lost'); assert.equal(env.form.button.disabled, false); assert.equal(env.form.isConnected, true);
  await env.confirmDelete(true); assert.equal(env.requests.length, 2); await env.settle(302);
  for (const request of env.requests) assert.deepEqual(Array.from(request.body.getAll('_desktop_command')), [command]);
  assert.equal(env.visits.length, 1); assert.equal(env.natural.length, 0);
});
