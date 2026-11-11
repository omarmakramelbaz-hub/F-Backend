'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { normalizeEntries, filterEntries } = require('../../public/storefront/motion.js');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/storefront/motion.js'), 'utf8');
const directoryView = fs.readFileSync(path.join(root, 'resources/views/site/motion/directory.blade.php'), 'utf8');
const snapshot = JSON.parse(directoryView.match(/class="f-store-directory-data">([\s\S]*?)<\/script>/)[1]);
const flush = async () => { await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };

async function directoryHarness(t, options = {}) {
  const { Window } = await import(pathToFileURL(require.resolve('happy-dom')).href);
  const window = new Window({
    url: 'https://storefront.test/',
    settings: {
      enableJavaScriptEvaluation: true,
      suppressInsecureJavaScriptEnvironmentWarning: true,
      disableJavaScriptFileLoading: true,
      disableCSSFileLoading: true,
      enableImageFileLoading: false
    }
  });
  const requests = [];
  const errors = [];
  const sentinel = JSON.stringify({ branches: [{ id: 94 }], context: null, endpoints: { checkout: '/shop/checkout' } });
  const cart = JSON.stringify({ branch: '94', cart: [{ productId: 1001, quantity: 2 }] });
  const html = directoryView
    .replace(/\{\{ url\('\/api\/resturants'\) \}\}/g, options.endpoint || '/api/resturants')
    .replace(/\{\{ asset\('storefront\/motion-logo.webp'\) \}\}/g, '/storefront/motion-logo.webp');
  window.document.write('<!doctype html><html><body id="fasakhansta-storefront-concept">' + html +
    '<script id="storefront-data" type="application/json">' + sentinel + '</script>' +
    '<span id="header-branch">العنوان المؤكد</span><input id="branch-select" value="94"></body></html>');
  window.sessionStorage.setItem('fasakhansta.web.cart.v1', cart);
  window.addEventListener('error', event => errors.push(event.error?.message || event.message));
  window.fetch = async (input, init) => {
    requests.push({ url: String(input), init });
    if (options.reject) throw new Error('Offline fixture');
    return { ok: true, json: async () => options.payload === undefined ? { data: snapshot } : options.payload };
  };
  window.eval(source);
  await flush();
  t.after(async () => {
    await window.happyDOM.close();
    assert.deepEqual(errors, [], 'Directory interactions must not throw browser errors');
  });
  const document = window.document;
  const element = selector => {
    const node = document.querySelector(selector);
    assert(node, 'Missing production directory selector: ' + selector);
    return node;
  };
  const cards = () => [...document.querySelectorAll('.f-store-card')];
  const search = query => {
    const input = element('#f-store-search'); input.value = query;
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
  };
  const assertOrderUntouched = () => {
    assert.equal(element('#storefront-data').textContent, sentinel);
    assert.equal(window.sessionStorage.getItem('fasakhansta.web.cart.v1'), cart);
    assert.equal(element('#header-branch').textContent, 'العنوان المؤكد');
    assert.equal(element('#branch-select').value, '94');
    assert(requests.every(request => request.url === 'https://storefront.test/api/resturants'));
    assert(requests.every(request => !request.init.method || request.init.method === 'GET'));
  };
  return { window, document, element, cards, search, requests, assertOrderUntouched };
}

test('Arabic area search finds current stores despite hamza, vowels and spacing differences', () => {
  const entries = normalizeEntries(snapshot);
  assert.equal(entries.length, 32);
  assert.equal(filterEntries(entries, 'store', 'أُكْتُوبَر').length, 2);
  assert.equal(filterEntries(entries, 'store', 'مدينه نصر').length, 2);
  assert.deepEqual(filterEntries(entries, 'branch', 'المحلة').map(item => item.id), [94]);
  assert.equal(filterEntries(entries, 'store', 'فرع المحله').length, 0);
});

test('invalid, duplicate and hidden directory records cannot become selectable stores', () => {
  const entries = normalizeEntries([
    { id: 94, name: 'المحلة', type: 'store' },
    { id: '342', name: ' المعادي ', type: 'branch', price: 1 },
    { id: 342, name: 'نسخة مكررة' },
    { id: 900, name: 'متجر مخفي', status: 'hide' },
    { id: -1, name: 'معرف سالب' }, { id: 1.5, name: 'كسر' },
    { id: 'not-an-id', name: 'معرف غير صالح' }, { id: 99, name: '' }, null
  ]);
  assert.deepEqual(entries, [
    { id: 94, name: 'المحلة', type: 'branch' },
    { id: 342, name: 'المعادي', type: 'store' }
  ]);
  assert.throws(() => normalizeEntries({ data: [] }), TypeError);
});

test('the production directory remains visible offline with usable filters, expansion and search', async t => {
  const h = await directoryHarness(t, { reject: true });
  assert.equal(h.cards().length, 8);
  assert.equal(h.element('[data-store-filter="store"] span').textContent, '٢٨');
  h.element('.f-stores-more').click();
  assert.equal(h.cards().length, 28);
  assert.equal(h.element('.f-stores-more').getAttribute('aria-expanded'), 'true');
  h.element('[data-store-filter="branch"]').click();
  assert.equal(h.cards().length, 4);
  assert.equal(h.element('.f-stores-more').hidden, true);
  h.element('[data-store-filter="all"]').click();
  h.search('مدينه نصر');
  assert.equal(h.cards().length, 2);
  assert(h.cards().every(card => card.textContent.includes('نصر')));
  h.search('منطقة غير موجودة');
  assert.equal(h.cards().length, 0);
  assert.equal(h.element('.f-stores-empty').hidden, false);
  h.search('');
  assert.equal(h.cards().length, 8);
  assert.equal(h.element('.f-stores-empty').hidden, true);
  h.assertOrderUntouched();
});

test('refresh uses only public anonymous GET data and renders untrusted names as text', async t => {
  const hostile = '<img src=x onerror="window.directoryInjected=true">';
  const h = await directoryHarness(t, { payload: { data: [
    { id: 342, name: hostile, type: 'branch' }, { id: 94, name: 'المحلة' },
    { id: 800, name: 'مخفي', status: 'hide' }
  ] } });
  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].init.credentials, 'omit');
  assert.equal(h.requests[0].init.cache, 'no-store');
  assert.equal(h.requests[0].init.headers.Accept, 'application/json');
  assert.equal(h.cards().length, 1);
  assert.equal(h.cards()[0].querySelector('h3').textContent, hostile);
  assert.equal(h.cards()[0].querySelectorAll('img').length, 1, 'Only the fixed local brand image may be created');
  assert.equal(h.document.querySelector('[onerror]'), null);
  assert.equal(h.window.directoryInjected, undefined);
  assert.equal(h.element('[data-directory-store="342"]').getAttribute('href'), '#delivery');
  h.element('[data-store-filter="branch"]').click();
  assert.equal(h.cards().length, 1);
  assert.equal(h.cards()[0].dataset.storeId, '94');
  h.assertOrderUntouched();
});

test('directory links use native delivery navigation until the order bridge is ready', async t => {
  const h = await directoryHarness(t, { reject: true });
  const events = [];
  h.document.addEventListener('storefront:request-store', event => events.push(event.detail.id));
  const link = h.element('[data-directory-store="308"]');
  const click = options => link.dispatchEvent(new h.window.MouseEvent('click', { bubbles: true, cancelable: true, ...options }));
  assert.equal(click(), true, 'An unavailable order bridge must leave the native anchor usable');
  assert.deepEqual(events, []);
  h.document.body.dataset.storefrontOrderBridge = 'ready';
  assert.equal(click(), false, 'The ready bridge consumes an ordinary primary click');
  assert.deepEqual(events, ['308']);
  assert.equal(click({ ctrlKey: true }), true, 'Modified clicks retain native browser behavior');
  assert.equal(click({ button: 1 }), true, 'Middle clicks retain native browser behavior');
  assert.deepEqual(events, ['308']);
  h.assertOrderUntouched();
});

test('a valid empty refresh removes stale stores while malformed responses retain the snapshot', async t => {
  const empty = await directoryHarness(t, { payload: { data: null } });
  assert.equal(empty.cards().length, 0);
  assert.equal(empty.element('.f-stores-empty').hidden, false);
  assert.equal(empty.element('[data-store-filter="all"] span').textContent, '٠');
  empty.assertOrderUntouched();

  const malformed = await directoryHarness(t, { payload: { data: { bad: true } } });
  assert.equal(malformed.cards().length, 8);
  assert.equal(malformed.element('[data-store-filter="all"] span').textContent, '٣٢');
  malformed.assertOrderUntouched();
});

test('a directory endpoint outside the approved same-origin API is never contacted', async t => {
  const h = await directoryHarness(t, { endpoint: 'https://other.test/api/resturants' });
  assert.deepEqual(h.requests, []);
  assert.equal(h.cards().length, 8);
  h.assertOrderUntouched();
});
