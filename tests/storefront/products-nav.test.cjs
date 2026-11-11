'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createStorefrontHarness, savedCart, cartKey, deliveryContext } = require('./helpers.cjs');

const motionSource = fs.readFileSync(path.join(__dirname, '../../public/storefront/motion.js'), 'utf8');

function addMotion(h) {
  // Run the real second production script as well as storefront.js. Media is local
  // presentation only; this stub avoids Happy DOM's unimplemented video player.
  h.window.HTMLMediaElement.prototype.play = function () { return Promise.resolve(); };
  h.window.HTMLMediaElement.prototype.pause = function () {};
  h.window.eval(motionSource);
}

function productsNav(h, mobile) {
  const selector = `${mobile ? '.f-mobile-menu' : '.f-nav'} [data-action="start-order"]`;
  const button = h.element(selector);
  assert.equal(button.textContent.trim(), 'منتجاتنا');
  assert.equal(button.hasAttribute('data-scroll'), false, 'Products navigation must have only the ordering route');
  return button;
}

function openMobileMenu(h) {
  h.click('[data-toggle-menu]');
  assert.equal(h.element('[data-mobile-menu]').hidden, false);
  assert.equal(h.element('[data-toggle-menu]').getAttribute('aria-expanded'), 'true');
}

for (const [mobile, storeId] of [[false, 94], [true, 342]]) {
  test(`${mobile ? 'mobile' : 'desktop'} products navigation opens its selected ${mobile ? 'store' : 'branch'} menu without changing the order`, async t => {
    const cart = { ...savedCart, branch: String(storeId) };
    const h = await createStorefrontHarness(t, { session: { [cartKey]: cart } });
    h.requestStore(storeId);
    await h.discover();
    await h.waitFor(() => h.element('#cart-count').textContent === '1' && h.element('#product-grid').querySelector('.product-card'), 'Selected menu and saved cart did not finish loading');
    addMotion(h);
    if (mobile) openMobileMenu(h);
    const before = {
      requests: h.requests.length,
      cart: h.window.sessionStorage.getItem(cartKey),
      products: h.element('#product-grid').innerHTML,
      address: h.element('#delivery-address').textContent,
      phone: h.element('#delivery-phone').textContent,
      name: h.element('#header-branch').textContent
    };
    h.scrolls.length = 0;

    productsNav(h, mobile).click();
    await h.flush();

    assert.deepEqual(h.scrolls, ['menu'], 'The two production scripts must route once to the selected menu, never the product showcase');
    assert.equal(h.element('[data-mobile-menu]').hidden, true);
    assert.equal(h.element('[data-toggle-menu]').getAttribute('aria-expanded'), 'false');
    assert.equal(h.element('#branch-select').value, String(storeId));
    assert.equal(h.window.sessionStorage.getItem(cartKey), before.cart);
    assert.equal(h.element('#product-grid').innerHTML, before.products);
    assert.equal(h.element('#delivery-address').textContent, before.address);
    assert.equal(h.element('#delivery-phone').textContent, before.phone);
    assert.equal(h.element('#header-branch').textContent, before.name);
    assert.equal(h.requests.length, before.requests, 'Menu navigation must not rediscover, reload catalog, quote or submit');
    assert.equal(h.calls('quote').length, 0);
    assert.equal(h.calls('checkout').length, 0);
    assert.equal(h.document.querySelector('dialog[open]'), null);
  });
}

for (const mobile of [false, true]) {
  test(`${mobile ? 'mobile' : 'desktop'} products navigation opens the eligible-store picker when no store is selected`, async t => {
    const h = await createStorefrontHarness(t);
    await h.discover();
    await h.waitFor(() => !h.element('#branch-select').disabled, 'Confirmed address did not enable the existing store picker');
    addMotion(h);
    if (mobile) openMobileMenu(h);
    const requests = h.requests.length;
    const cart = h.window.sessionStorage.getItem(cartKey);
    h.scrolls.length = 0;

    productsNav(h, mobile).click();
    await h.flush();

    assert.deepEqual(h.scrolls, ['branch-select'], 'Navigation must reach the existing order picker without also scrolling to the product showcase');
    assert.equal(h.document.activeElement, h.element('#branch-select'));
    assert.equal(h.element('#branch-select').value, '');
    assert.equal(h.element('#branch-select').disabled, false);
    assert.equal(h.element('[data-mobile-menu]').hidden, true);
    assert.equal(h.element('[data-toggle-menu]').getAttribute('aria-expanded'), 'false');
    assert.equal(h.element('#delivery-form').hidden, true);
    assert.equal(h.element('#delivery-phone').textContent, deliveryContext.customer_phone);
    assert.equal(h.window.sessionStorage.getItem(cartKey), cart);
    assert.equal(h.requests.length, requests);
    assert.equal(h.calls('catalog').length, 0);
    assert.equal(h.calls('quote').length, 0);
    assert.equal(h.calls('checkout').length, 0);
  });
}
