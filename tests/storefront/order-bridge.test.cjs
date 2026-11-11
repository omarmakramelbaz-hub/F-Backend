'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createStorefrontHarness, deferred, deliveryContext, eligibleBranches, savedCart, cartKey, pendingKey } = require('./helpers.cjs');

test('the original eligible-store selector keeps cart controls and checkout delivery data intact', async t => {
  const h = await createStorefrontHarness(t, { session: { [cartKey]: savedCart } });
  await h.discover();
  await h.waitFor(() => !h.element('#branch-select').disabled, 'Delivery discovery did not enable the existing store selector');
  const select = h.element('#branch-select');
  select.value = '94';
  select.dispatchEvent(new h.window.Event('change', { bubbles: true }));
  await h.waitFor(() => h.element('#cart-count').textContent === '1', 'The existing selector did not restore the saved cart');

  h.click('[data-action="cart"]');
  h.click('[data-action="quantity"][data-delta="1"]');
  assert.equal(h.storedCart().cart[0].quantity, 3);
  h.click('[data-action="checkout"]');
  assert.equal(h.element('#cart-dialog').open, false);
  assert.equal(h.element('#checkout-dialog').open, true);
  const form = h.element('#checkout-form');
  assert.equal(form.elements.customer_phone.value, deliveryContext.customer_phone);
  assert.equal(form.elements.address.value, deliveryContext.address);
  assert.equal(form.elements.area.value, deliveryContext.area);
  assert.equal(form.elements.customer_name.value, 'عميل تجريبي');
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('a public directory card cannot load a catalog or start checkout before confirmed delivery', async t => {
  const h = await createStorefrontHarness(t);
  h.requestStore(94);
  await h.flush();

  assert.equal(h.element('#delivery-form').hidden, false);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.equal(h.element('#branch-select').value, '');
  assert(h.scrolls.includes('delivery'), 'Directory selection should direct the visitor to delivery details');
  assert.equal(h.document.activeElement, h.element('#delivery-form').elements.customer_phone);
  assert.deepEqual(h.requests, [], 'The public directory must not be treated as server-confirmed eligibility');
  assert.equal(h.element('#checkout-dialog').open, false);
});

test('requested eligible store waits for discovery and restores its saved cart using the original checkout flow', async t => {
  const discovery = deferred();
  const h = await createStorefrontHarness(t, {
    session: { [cartKey]: savedCart },
    routes: { branches: () => discovery.promise }
  });
  h.requestStore('94');
  await h.discover();

  assert.equal(h.calls('branches').length, 1);
  assert.equal(h.calls('branches')[0].method, 'POST');
  assert.equal(h.calls('branches')[0].body.location_confirmed, true);
  assert.equal(h.calls('catalog').length, 0, 'A pending discovery must not make a store eligible');

  discovery.resolve({ branches: eligibleBranches, context: deliveryContext });
  await h.waitFor(() => h.element('#cart-count').textContent === '1', 'Eligible saved cart was not restored');
  assert.equal(h.calls('catalog').length, 1);
  assert.equal(h.calls('catalog')[0].method, 'GET');
  assert.equal(h.calls('catalog')[0].url.searchParams.get('branch_id'), '94');
  assert.equal(h.element('#branch-select').value, '94');
  assert.equal(h.element('#delivery-form').hidden, true);
  assert.equal(h.element('#delivery-summary').hidden, false);
  assert.deepEqual(h.storedCart(), savedCart);

  h.click('[data-action="cart"]');
  assert.equal(h.element('#cart-dialog').open, true);
  assert(h.element('#cart-content').textContent.includes('صنف اختبار'));
  h.click('[data-action="checkout"]');
  assert.equal(h.element('#checkout-dialog').open, true);
  const checkout = h.element('#checkout-form');
  assert.equal(checkout.elements.customer_phone.value, deliveryContext.customer_phone);
  assert.equal(checkout.elements.address.value, deliveryContext.address);
  assert.equal(checkout.elements.area.value, deliveryContext.area);
  assert.equal(h.calls('quote').length, 0, 'Opening checkout must not quote or submit automatically');
  assert.equal(h.calls('checkout').length, 0);
});

test('switching directory stores preserves the original cart until branch-change confirmation', async t => {
  const h = await createStorefrontHarness(t, { session: { [cartKey]: savedCart } });
  h.requestStore(94);
  await h.discover();
  await h.waitFor(() => h.element('#cart-count').textContent === '1', 'Expected the initial saved cart');

  h.requestStore(342);
  await h.flush();
  assert.equal(h.element('#confirm-branch-dialog').open, true);
  assert.equal(h.element('#branch-select').value, '94');
  assert.deepEqual(h.storedCart(), savedCart);
  assert.equal(h.calls('catalog').length, 1, 'Another store must not load while confirmation is pending');

  h.click('#confirm-branch-dialog [data-close]');
  assert.equal(h.element('#confirm-branch-dialog').open, false);
  assert.deepEqual(h.storedCart(), savedCart, 'Canceling must leave the saved order intact');

  h.requestStore('342');
  h.click('#confirm-branch');
  await h.waitFor(() => h.calls('catalog').length === 2 && h.element('#branch-select').value === '342' && h.element('#product-grid').querySelector('.product-card'), 'Confirmed switch did not finish rendering the eligible store');
  assert.equal(h.element('#confirm-branch-dialog').open, false);
  assert.equal(h.calls('catalog')[1].url.searchParams.get('branch_id'), '342');
  assert.deepEqual(h.storedCart(), { branch: '342', cart: [] });
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('malformed directory IDs cannot change a confirmed store or discard its cart', async t => {
  const h = await createStorefrontHarness(t, { session: { [cartKey]: savedCart } });
  h.requestStore(94);
  await h.discover();
  await h.waitFor(() => h.element('#cart-count').textContent === '1', 'Expected the initial saved cart');

  for (const id of [0, -1, '94.5', '94&branch_id=342', null, { id: 342 }]) h.requestStore(id);
  await h.flush();
  assert.equal(h.element('#branch-select').value, '94');
  assert.deepEqual(h.storedCart(), savedCart);
  assert.equal(h.element('#confirm-branch-dialog').open, false);
  assert.equal(h.element('#change-location-dialog').open, false);
  assert.equal(h.calls('catalog').length, 1);
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});

test('a pending order stays sealed when a directory store is requested or delivery is resubmitted', async t => {
  const pending = { token: 'synthetic-quote-token', idempotency_key: 'synthetic-order-key' };
  const h = await createStorefrontHarness(t, { session: { [pendingKey]: pending } });
  h.requestStore('94');
  await h.discover();

  assert.deepEqual(h.requests, [], 'An unresolved order must block new discovery, catalogs, quotes, and checkout requests');
  assert.deepEqual(JSON.parse(h.window.sessionStorage.getItem(pendingKey)), pending);
  assert.equal(h.element('#branch-select').disabled, true);
  assert(h.element('#delivery-error').textContent.length > 0);

  h.click('[data-action="cart"]');
  assert.equal(h.element('#cart-dialog').open, true);
  assert(h.element('#cart-footer').querySelector('[data-action="resume-checkout"]'), 'The existing pending-order recovery action should remain available');
  h.click('[data-action="resume-checkout"]');
  assert.equal(h.element('#checkout-dialog').open, true);
  assert.equal(h.element('#checkout-form').hidden, true);
  assert(h.element('#review-panel').querySelector('[data-action="confirm-pending"]'));
  assert.deepEqual(JSON.parse(h.window.sessionStorage.getItem(pendingKey)), pending);
  assert.deepEqual(h.requests, [], 'Showing recovery controls must never submit the pending order automatically');
});

test('an ineligible directory ID uses address-change safeguards without fetching its menu', async t => {
  const h = await createStorefrontHarness(t, { session: { [cartKey]: savedCart } });
  h.requestStore(94);
  await h.discover();
  await h.waitFor(() => h.element('#cart-count').textContent === '1', 'Expected the initial saved cart');

  h.requestStore(999);
  await h.flush();
  assert.equal(h.element('#change-location-dialog').open, true, 'Unavailable store should invoke the existing edit-location safeguard');
  assert.equal(h.element('#confirm-branch-dialog').open, false);
  assert.equal(h.element('#toast').hidden, false);
  assert.deepEqual(h.storedCart(), savedCart);
  assert.equal(h.calls('catalog').length, 1);

  h.click('#change-location-dialog [data-close]');
  assert.deepEqual(h.storedCart(), savedCart);
  h.requestStore('999');
  h.click('#confirm-location-change');
  await h.flush();
  assert.equal(h.element('#delivery-form').hidden, false);
  assert.equal(h.element('#delivery-summary').hidden, true);
  assert.equal(h.element('#branch-select').disabled, true);
  assert.deepEqual(h.storedCart(), { branch: '', cart: [] });
  assert.equal(h.calls('catalog').length, 1, 'An unavailable public-directory ID must never load catalog data');
  assert.equal(h.calls('quote').length, 0);
  assert.equal(h.calls('checkout').length, 0);
});
