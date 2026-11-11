'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const repositoryRoot = path.resolve(__dirname, '../..');
const customerKey = 'fasakhansta.web.customer.v1';
const cartKey = 'fasakhansta.web.cart.v1';
const pendingKey = 'fasakhansta.web.pending.v1';

const deliveryContext = {
  customer_phone: '01000000000',
  area: 'منطقة تجريبية',
  address: 'عنوان تجريبي للاختبار فقط',
  latitude: 30.1,
  longitude: 31.3
};
const eligibleBranches = [
  { id: 94, name: 'فرع اختبار 94', open: true, distance_km: 1, address: 'عنوان اختبار', phone: '' },
  { id: 342, name: 'متجر اختبار 342', open: true, distance_km: 2, address: 'عنوان اختبار', phone: '' }
];
const catalog = {
  products: [{ id: 1001, name: 'صنف اختبار', category_id: 1, quantity_mode: 'piece', price: 25, options: [] }],
  categories: [{ id: 1, name: 'قسم اختبار' }],
  open: true
};
const savedCart = {
  branch: '94',
  cart: [{ productId: 1001, optionId: '', mode: 'piece', quantity: 2 }]
};

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function expandView(view, stack = []) {
  assert(!stack.includes(view), `Recursive Blade include: ${view}`);
  const filename = path.join(repositoryRoot, 'resources/views', view.replaceAll('.', '/') + '.blade.php');
  return fs.readFileSync(filename, 'utf8').replace(
    /@include\(\s*['"]([\w.-]+)['"][^\n]*\)/g,
    (_, child) => expandView(child, [...stack, view])
  );
}

async function flush() {
  await Promise.resolve();
  await new Promise(resolve => setImmediate(resolve));
}

async function waitFor(predicate, message) {
  for (let attempt = 0; attempt < 30; attempt++) {
    if (predicate()) return;
    await flush();
  }
  assert.fail(message);
}

async function createStorefrontHarness(t, options = {}) {
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
  const unexpected = [];
  const errors = [];
  const scrolls = [];
  const endpoints = Object.fromEntries(['branches', 'catalog', 'addresses', 'quote', 'checkout'].map(name => [name, `/test/${name}`]));
  const routes = {
    branches: () => ({ branches: eligibleBranches, context: deliveryContext }),
    catalog: () => catalog,
    ...options.routes
  };
  window.addEventListener('error', event => errors.push(event.error?.message || event.message));
  window.HTMLElement.prototype.scrollIntoView = function () { scrolls.push(this.id); };
  Object.defineProperty(window.navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition: success => success({ coords: { latitude: 30.1, longitude: 31.3, accuracy: 12 } }) }
  });
  // Every application request is intercepted. No real API, geocoder, or order is contacted.
  window.fetch = async (input, init = {}) => {
    const url = new URL(String(input));
    const name = url.pathname.replace('/test/', '');
    const request = { name, url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : null };
    requests.push(request);
    if (url.origin !== 'https://storefront.test' || !routes[name]) {
      unexpected.push(`${request.method} ${url.href}`);
      throw new Error('Unmocked request blocked by storefront test harness');
    }
    const result = await routes[name](request);
    return { ok: true, status: 200, json: async () => result };
  };

  // Use the production view and its local includes, rather than a parallel DOM fixture.
  // Server-generated data is supplied below and all external/inline scripts are removed.
  const html = expandView('site.home')
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<link\b[^>]*>/gi, '');
  window.document.write(html);
  const document = window.document;
  const csrf = document.querySelector('meta[name="csrf-token"]');
  assert(csrf, 'Production home must contain a CSRF meta tag');
  csrf.content = 'synthetic-test-csrf';
  const data = document.createElement('script');
  data.id = 'storefront-data';
  data.type = 'application/json';
  data.textContent = JSON.stringify({ branches: eligibleBranches, info: {}, endpoints });
  document.head.append(data);
  for (const [key, value] of Object.entries(options.session || {})) window.sessionStorage.setItem(key, JSON.stringify(value));
  for (const [key, value] of Object.entries(options.local || {})) window.localStorage.setItem(key, JSON.stringify(value));
  window.eval(fs.readFileSync(path.join(repositoryRoot, 'public/storefront/storefront.js'), 'utf8'));

  t.after(async () => {
    await window.happyDOM.close();
    assert.deepEqual(unexpected, [], 'All API calls must remain explicitly mocked');
    assert.deepEqual(errors, [], 'Application should not raise browser errors');
  });

  const element = selector => {
    const result = document.querySelector(selector);
    assert(result, `Production selector is missing: ${selector}`);
    return result;
  };
  const click = selector => element(selector).click();
  const requestStore = id => document.dispatchEvent(new window.CustomEvent('storefront:request-store', { detail: { id }, bubbles: true }));
  const discover = async () => {
    const form = element('#delivery-form');
    form.elements.customer_name.value = 'عميل تجريبي';
    form.elements.customer_phone.value = deliveryContext.customer_phone;
    form.elements.area.value = deliveryContext.area;
    form.elements.address.value = deliveryContext.address;
    form.elements.location_confirmed.checked = true;
    assert(form.reportValidity(), 'Synthetic delivery fixture must satisfy the real form constraints');
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    await flush();
  };
  return {
    window, document, element, click, requestStore, discover, requests, scrolls,
    calls: name => requests.filter(request => request.name === name),
    storedCart: () => JSON.parse(window.sessionStorage.getItem(cartKey)),
    flush, waitFor
  };
}

module.exports = { createStorefrontHarness, deferred, deliveryContext, eligibleBranches, savedCart, customerKey, cartKey, pendingKey };
