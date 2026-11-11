'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createStorefrontHarness, savedCart, cartKey, pendingKey, deliveryContext } = require('./helpers.cjs');

const motionSource = fs.readFileSync(path.join(__dirname, '../../public/storefront/motion.js'), 'utf8');
async function createHeaderHarness(t, options) {
  const h = await createStorefrontHarness(t, options);
  h.window.HTMLMediaElement.prototype.play = function () { return Promise.resolve(); };
  h.window.HTMLMediaElement.prototype.pause = function () {};
  h.window.eval(motionSource);
  return h;
}

function clickStoreLabel(h) {
  const label = h.element('.header-store-picker[data-action="choose-store"]');
  const text = label.querySelector('span');
  assert(text, 'The existing header store label must retain its visible text');
  assert.equal(text.textContent.trim(), 'المتجر المناسب لعنوانك');
  const event = new h.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
  text.dispatchEvent(event);
  assert.equal(event.defaultPrevented, true, 'The label action must own focus so native label behavior cannot override the address flow');
}

async function selectedStore(t) {
  const h = await createHeaderHarness(t, { session: { [cartKey]: savedCart } });
  h.requestStore(94);
  await h.discover();
  await h.waitFor(() => h.element('#cart-count').textContent === '1' && h.element('#product-grid').querySelector('.product-card'), 'The selected branch did not finish restoring its menu and cart');
  return h;
}

test('the header store text opens the picker even when a branch is already selected', async t => {
  const h = await selectedStore(t);
  const before = {
    requests: h.requests.length,
    cart: h.window.sessionStorage.getItem(cartKey),
    products: h.element('#product-grid').innerHTML,
    address: h.element('#delivery-address').textContent,
    phone: h.element('#delivery-phone').textContent
  };
  h.scrolls.length = 0;

  clickStoreLabel(h);
  await h.flush();

  assert.deepEqual(h.scrolls, ['branch-select'], 'The header label must open the store selector rather than the selected store menu');
  assert.equal(h.document.activeElement, h.element('#branch-select'));
  assert.equal(h.element('#branch-select').value, '94');
  assert.equal(h.requests.length, before.requests);
  assert.equal(h.window.sessionStorage.getItem(cartKey), before.cart);
  assert.equal(h.element('#product-grid').innerHTML, before.products);
  assert.equal(h.element('#delivery-address').textContent, before.address);
  assert.equal(h.element('#delivery-phone').textContent, before.phone);
  assert.equal(h.document.querySelector('dialog[open]'), null);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('the header store text focuses the existing eligible picker without making a selection', async t => {
  const h = await createHeaderHarness(t);
  await h.discover();
  await h.waitFor(() => !h.element('#branch-select').disabled, 'Confirmed address did not enable eligible stores');
  const requests = h.requests.length;
  const cart = h.window.sessionStorage.getItem(cartKey);
  h.scrolls.length = 0;

  clickStoreLabel(h);
  await h.flush();

  assert.deepEqual(h.scrolls, ['branch-select']);
  assert.equal(h.document.activeElement, h.element('#branch-select'));
  assert.equal(h.element('#branch-select').value, '');
  assert.equal(h.element('#branch-select').disabled, false);
  assert.equal(h.element('#delivery-form').hidden, true);
  assert.equal(h.element('#delivery-phone').textContent, deliveryContext.customer_phone);
  assert.equal(h.requests.length, requests);
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.equal(h.calls('catalog').length, 0);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('the header store text uses delivery confirmation when no address is available', async t => {
  const h = await createHeaderHarness(t);
  const cart = h.window.sessionStorage.getItem(cartKey);
  h.scrolls.length = 0;

  clickStoreLabel(h);
  await h.flush();

  assert.equal(h.scrolls.at(-1), 'delivery');
  assert.equal(h.document.activeElement, h.element('#delivery-form').elements.customer_phone);
  assert.equal(h.element('#delivery-form').hidden, false);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.equal(h.element('#branch-select').value, '');
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.deepEqual(h.requests, [], 'The label must not bypass delivery confirmation');
});

test('clicking the native store select remains unconsumed by the header label action', async t => {
  const h = await selectedStore(t);
  const requests = h.requests.length;
  const cart = h.window.sessionStorage.getItem(cartKey);
  h.scrolls.length = 0;
  const select = h.element('#branch-select');
  const event = new h.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });

  select.dispatchEvent(event);
  await h.flush();

  assert.equal(event.defaultPrevented, false, 'Native arrow/list interaction must retain normal select behavior');
  assert.deepEqual(h.scrolls, [], 'A select click must not recursively run the label picker action');
  assert.equal(select.value, '94');
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.equal(h.requests.length, requests);
  assert.equal(h.document.querySelector('dialog[open]'), null);
});

test('changing the store after opening its header picker still protects the existing cart with confirmation', async t => {
  const h = await selectedStore(t);
  clickStoreLabel(h);
  const requests = h.requests.length;
  const cart = h.window.sessionStorage.getItem(cartKey);
  const select = h.element('#branch-select');

  select.value = '342';
  select.dispatchEvent(new h.window.Event('change', { bubbles: true }));
  await h.flush();

  assert.equal(h.element('#confirm-branch-dialog').open, true);
  assert.equal(select.value, '94', 'The selected branch must stay unchanged until the visitor confirms');
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.equal(h.requests.length, requests, 'Opening the confirmation must not fetch another store menu');
  h.click('#confirm-branch-dialog [data-close]');
  assert.equal(h.element('#confirm-branch-dialog').open, false);
  assert.equal(select.value, '94');
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('the header store text keeps an unresolved order sealed and opens its existing recovery controls', async t => {
  const pending = { token: 'synthetic-header-token', idempotency_key: 'synthetic-header-order-key' };
  const h = await createHeaderHarness(t, { session: { [pendingKey]: pending } });
  const sealed = h.window.sessionStorage.getItem(pendingKey);

  clickStoreLabel(h);
  await h.flush();

  assert.equal(h.element('#cart-dialog').open, true);
  assert(h.element('#cart-footer').querySelector('[data-action="resume-checkout"]'));
  assert.equal(h.window.sessionStorage.getItem(pendingKey), sealed);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.equal(h.element('#branch-select').value, '');
  assert.deepEqual(h.requests, [], 'Opening recovery must not discover stores, quote, or retry an unresolved order');
});
