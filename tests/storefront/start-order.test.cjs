'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createStorefrontHarness, savedCart, cartKey, pendingKey, deliveryContext } = require('./helpers.cjs');

function startOrder(h) {
  const link = h.element('.f-hero [data-action="start-order"]');
  assert.equal(link.getAttribute('href'), '#menu', 'The hero keeps a native menu anchor when scripting is unavailable');
  assert(link.textContent.includes('ابدأ طلبك'));
  const click = new h.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
  link.dispatchEvent(click);
  assert.equal(click.defaultPrevented, true, 'Scripted routing must consume the anchor so its hash cannot override address discovery');
}

for (const storeId of [94, 342]) {
  test(`hero start order opens the selected ${storeId === 94 ? 'branch' : 'store'} menu without refetching or writing the cart`, async t => {
    const cart = { ...savedCart, branch: String(storeId) };
    const h = await createStorefrontHarness(t, { session: { [cartKey]: cart } });
    h.requestStore(storeId);
    await h.discover();
    await h.waitFor(() => h.element('#cart-count').textContent === '1' && h.element('#product-grid').querySelector('.product-card'), 'Selected store did not finish restoring its menu and cart');

    const before = {
      requests: h.requests.length,
      storage: h.window.sessionStorage.getItem(cartKey),
      products: h.element('#product-grid').innerHTML,
      address: h.element('#delivery-address').textContent,
      phone: h.element('#delivery-phone').textContent,
      branch: h.element('#header-branch').textContent
    };
    const writes = [];
    const originalSetItem = h.window.Storage.prototype.setItem;
    h.window.Storage.prototype.setItem = function (key, value) { writes.push(key); return originalSetItem.call(this, key, value); };
    t.after(() => { h.window.Storage.prototype.setItem = originalSetItem; });
    h.scrolls.length = 0;
    h.element('#search').focus();

    startOrder(h);
    await h.flush();

    assert.deepEqual(h.scrolls, ['menu'], 'A selected store should open its current menu directly');
    assert.equal(h.document.activeElement, h.element('#search'), 'Opening the selected menu must not reopen the store picker');
    assert.equal(h.requests.length, before.requests, 'Opening the current menu requires no new discovery, catalog, quote or checkout request');
    assert.deepEqual(writes, [], 'Navigation must not rewrite a saved cart or pending order');
    assert.equal(h.window.sessionStorage.getItem(cartKey), before.storage);
    assert.equal(h.element('#branch-select').value, String(storeId));
    assert.equal(h.element('#product-grid').innerHTML, before.products);
    assert.equal(h.element('#header-branch').textContent, before.branch);
    assert.equal(h.element('#delivery-address').textContent, before.address);
    assert.equal(h.element('#delivery-phone').textContent, before.phone);
    assert.equal(h.document.querySelector('dialog[open]'), null);
    assert.equal(h.calls('quote').length, 0);
    assert.equal(h.calls('checkout').length, 0);
  });
}

test('hero start order uses the existing eligible-store picker when an address is confirmed without a selection', async t => {
  const h = await createStorefrontHarness(t);
  await h.discover();
  await h.waitFor(() => !h.element('#branch-select').disabled, 'Confirmed delivery did not expose the eligible-store picker');
  const requests = h.requests.length;
  const cart = h.window.sessionStorage.getItem(cartKey);
  h.scrolls.length = 0;

  startOrder(h);
  await h.flush();

  assert.equal(h.document.activeElement, h.element('#branch-select'));
  assert.equal(h.element('#branch-select').value, '');
  assert.equal(h.element('#branch-select').disabled, false);
  assert.equal(h.scrolls.at(-1), 'branch-select', 'The existing eligible-store picker should be brought into view');
  assert.equal(h.element('#delivery-form').hidden, true, 'The visitor must retain the already confirmed delivery details');
  assert.equal(h.element('#delivery-phone').textContent, deliveryContext.customer_phone);
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.equal(h.requests.length, requests, 'The CTA should not select or fetch a store on the visitor’s behalf');
  assert.equal(h.calls('catalog').length, 0);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('hero start order without a confirmed address directs the visitor to the existing delivery form', async t => {
  const h = await createStorefrontHarness(t);
  h.scrolls.length = 0;
  const cart = h.window.sessionStorage.getItem(cartKey);

  startOrder(h);
  await h.flush();

  assert.equal(h.scrolls.at(-1), 'delivery');
  assert.equal(h.document.activeElement, h.element('#delivery-form').elements.customer_phone);
  assert.equal(h.element('#delivery-form').hidden, false);
  assert.equal(h.element('#delivery-summary').hidden, true);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
  assert.deepEqual(h.requests, [], 'The CTA must not bypass address confirmation or contact ordering APIs');
  assert.equal(h.element('#checkout-dialog').open, false);
});

test('hero start order keeps an unresolved order sealed and leaves its recovery controls available', async t => {
  const pending = { token: 'synthetic-start-order-token', idempotency_key: 'synthetic-start-order-key' };
  const h = await createStorefrontHarness(t, { session: { [pendingKey]: pending } });
  const sealed = h.window.sessionStorage.getItem(pendingKey);

  startOrder(h);
  await h.flush();

  assert.equal(h.window.sessionStorage.getItem(pendingKey), sealed);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.equal(h.calls('catalog').length, 0);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
  assert.equal(h.element('#cart-dialog').open, true, 'An unresolved order should lead to its existing cart recovery controls');
  assert(h.element('#cart-footer').querySelector('[data-action="resume-checkout"]'));
  h.click('[data-action="resume-checkout"]');
  assert.equal(h.element('#checkout-dialog').open, true);
  assert.equal(h.element('#checkout-form').hidden, true);
  assert(h.element('#review-panel').querySelector('[data-action="confirm-pending"]'));
  assert.equal(h.window.sessionStorage.getItem(pendingKey), sealed);
  assert.deepEqual(h.requests, [], 'Showing recovery must not retry or replace the pending order automatically');
});
