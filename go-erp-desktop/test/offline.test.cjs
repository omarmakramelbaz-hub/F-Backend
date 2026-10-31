'use strict';
// The dashboard's own pages offline: receipts identical to the dashboard's, and the cashier page's answers.
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { renderReceipt } = require('../src/main/offline/receipt.cjs');

const normalize = (html) => html.replace(/\s+/g, ' ').replace(/\s*([<>])\s*/g, '$1').trim();

test('offline receipts are the dashboard receipt page, case by case', () => {
  // Rendered by test/receipt-fixtures.php from resources/views/admin/takeaway/receipt.blade.php.
  const { cases } = require(path.join(__dirname, 'fixtures', 'receipts.json'));
  assert.ok(cases.length >= 5);
  for (const sample of cases) {
    const html = renderReceipt(sample.receipt, { origin: 'https://fasakhaninja.com', preview: sample.preview, dashboardPrint: sample.dashboard_print });
    assert.equal(normalize(html), normalize(sample.html), sample.name);
  }
});

// ---- the cashier page (تيك أواي) answered from the device while offline ----
const db = require('../src/main/db.cjs');
const { Store } = require('../src/main/store.cjs');
const { TakeawayOffline, LOCAL_RECEIPT_BASE } = require('../src/main/offline/takeaway.cjs');

const ORIGIN = 'https://fasakhaninja.com';
const card = (id, name, price, extra = {}) => ({ id, name, price, unit_price: price, available: true, image_url: '', unit: '', quantity_mode: 'select',
  category_id: 1, options: [], revision: null, preparation_options: [], ...extra });
const MENU = {
  branch: { value: 'f:100', id: 100, kind: 'f', name: 'فرع المعادي', label: 'فرع المعادي', address: 'شارع ٩ - المعادي', phone: '01000000000' },
  tax_bps: 1400, service_bps: 0, can_discount: true, categories: [{ id: 1, name: 'أسماك' }, { id: 3, name: 'أطباق جانبية' }],
  products: [
    { id: 1, name: 'بوري مشوي', unit: '', category_id: 1, variants: [{ option_id: '', label: 'الأساسي', unit_price_cents: 18000, quantity_mode: 'select' },
      { option_id: 'f:0:extra_clean', label: 'تنظيف', unit_price_cents: 20000, quantity_mode: 'select' }] },
    { id: 7, name: 'سلطة طحينة', unit: '', category_id: 3, variants: [{ option_id: '', label: 'الأساسي', unit_price_cents: 2500, quantity_mode: 'piece' }] },
  ],
  listing: [card(1, 'بوري مشوي', '180.00', { preparation_options: [{ id: '', label: 'كامل', price: '180.00', feature_id: 0, product_clean: '' },
    { id: 'f:0:extra_clean', label: 'تنظيف', price: '200.00', feature_id: 0, product_clean: 'extra_clean' }] }),
  card(5, 'كابوريا', null, { available: false }), card(7, 'سلطة طحينة', '25.00', { category_id: 3, quantity_mode: 'piece', unit: 'piece' })],
};
const ACCOUNT = { id: 4, name: 'كاشير المعادي', mobile: '1000000004', email: 'c@example.test', account_type: 'admin', password_hash: 'x',
  branches: ['f:100'], can_checkout: true, can_manage: false, can_reprint: false };

function cashierPage(start = '2026-10-30T18:00:00Z') {
  let clock = Date.parse(start);
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'فرع المعادي', address: '', phone: '' }]);
  store.replaceUsers([ACCOUNT, { ...ACCOUNT, id: 1, name: 'المالك', can_manage: true, can_reprint: true }], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', catalog: MENU,
    today: { date: '2026-10-30', count: 3, total: '450.00', cash: '450.00', card: '0.00', mobile_wallet: '0.00', other: '0.00', mixed: '0.00' } }]);
  let kicks = 0;
  const page = new TakeawayOffline({ store, origin: ORIGIN, now: () => new Date(clock), kick: () => { kicks++; } });
  const ask = async (method, path, { user = ACCOUNT, body = null } = {}) => {
    const url = new URL(ORIGIN + path);
    const route = page.match(method, url.pathname);
    assert.ok(route, `${method} ${path} is answered on the device`);
    return page.answer(route, { user, url, body });
  };
  return { store, page, ask, tick: (ms) => { clock += ms; }, kicks: () => kicks };
}

const ITEMS = [{ product_id: 1, quantity: '1.500', quantity_mode: 'weight' }, { product_id: 7, quantity: '2', quantity_mode: 'piece' }];

async function sellOnPage(ask, extra = {}) {
  const quote = (await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: ITEMS, discount: '0.00', discount_reason: '' } })).json;
  const body = { branch: 'f:100', items: ITEMS, discount: '0.00', discount_reason: '', quote_hash: quote.quote_hash,
    idempotency_key: '6f1c2b8e-1d2a-4c3b-9e8f-0a1b2c3d4e5f', payment_method: 'cash', cash_received: '400.00', payment_confirmed: false,
    customer_phone: '+201012345678', customer_name: 'أحمد', service_consent: true, marketing_consent: false, payment_reference: '', notes: 'بدون بصل', ...extra };
  return { quote, body, reply: await ask('POST', '/admin/takeaway/checkout', { body }) };
}

test('the cashier page lists the branch menu cards like the dashboard', async () => {
  const { ask } = cashierPage();
  const all = (await ask('GET', '/admin/takeaway/catalog?branch=f:100&page=1')).json;
  assert.deepEqual(all.items.map((item) => item.id), [1, 5, 7], 'Same cards, same order, unavailable ones included');
  assert.equal(all.items, all.products);
  assert.deepEqual(all.pagination, { page: 1, last_page: 1, per_page: 40, total: 3 });
  assert.deepEqual(all.permissions, { can_checkout: true, can_manage_tables: true, can_manage: false, can_reprint: false });
  assert.deepEqual(all.policy, { tax_rate: '14.00', service_rate: '0.00', can_discount: false, payment_methods: ['cash'], currency: 'EGP' });
  assert.equal(all.register.tax_rate, '14.00');
  assert.equal(all.register.balance, undefined, 'The drawer balance needs the dashboard');
  assert.equal(all.today.count, 3, 'The day so far as the dashboard counted it at the last sync');
  assert.deepEqual((await ask('GET', '/admin/takeaway/catalog?branch=f:100&search=طحينة')).json.items.map((item) => item.id), [7]);
  assert.deepEqual((await ask('GET', '/admin/takeaway/catalog?branch=f:100&category_id=3&catalog_only=1')).json.items.map((item) => item.id), [7]);
  assert.equal((await ask('GET', '/admin/takeaway/catalog?branch=f:100&catalog_only=1')).json.today, undefined);
  const paged = (await ask('GET', '/admin/takeaway/catalog?branch=f:100&per_page=2&page=9')).json;
  assert.deepEqual([paged.pagination.page, paged.pagination.last_page, paged.items.length], [2, 2, 1]);
  assert.equal((await ask('GET', '/admin/takeaway/catalog?branch=f:101')).status, 403, 'Another branch');
});

test('quotes use the synced menu prices and the dashboard discount rules', async () => {
  const { ask } = cashierPage();
  const quote = (await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: ITEMS } })).json;
  // 1.5 kg × 180 = 270, 2 × 25 = 50, tax 14% of 320 = 44.80.
  assert.deepEqual([quote.subtotal, quote.tax, quote.total, quote.tax_rate], ['320.00', '44.80', '364.80', '14.00']);
  assert.match(quote.quote_hash, /^[a-f0-9]{64}$/);
  assert.deepEqual(quote.items.map((line) => [line.product_id, line.option_label, line.quantity, line.total]),
    [[1, '', '1.500', '270.00'], [7, '', '2.000', '50.00']]);
  const cleaned = (await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: [{ product_id: 1, option_id: 'f:0:extra_clean', quantity: '0.750', quantity_mode: 'weight' }] } })).json;
  assert.deepEqual([cleaned.items[0].option_label, cleaned.items[0].unit_price, cleaned.total], ['تنظيف', '200.00', '171.00']);
  const discount = await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: ITEMS, discount: '10.00', discount_reason: 'عميل' } });
  assert.equal(discount.status, 403, 'A cashier cannot discount');
  const owner = { ...ACCOUNT, id: 1 };
  assert.equal((await ask('POST', '/admin/takeaway/quote', { user: owner, body: { branch: 'f:100', items: ITEMS, discount: '10.00' } })).status, 422);
  const discounted = (await ask('POST', '/admin/takeaway/quote', { user: owner, body: { branch: 'f:100', items: ITEMS, discount: '10.00', discount_reason: 'عميل دائم' } })).json;
  assert.equal(discounted.total, '353.40');
  assert.equal((await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: [{ product_id: 7, quantity: '1.5', quantity_mode: 'piece' }] } })).status, 422);
  assert.equal((await ask('POST', '/admin/takeaway/quote', { body: { branch: 'f:100', items: [{ product_id: 99, quantity: '1', quantity_mode: 'piece' }] } })).status, 422);
});

test('checkout saves the sale once, queues it for the dashboard and prints the dashboard receipt', async () => {
  const { store, ask, kicks } = cashierPage();
  const { body, reply } = await sellOnPage(ask);
  assert.equal(reply.status, 200, JSON.stringify(reply.json));
  const { receipt } = reply.json;
  assert.equal(receipt.idempotency_key, body.idempotency_key);
  assert.equal(receipt.status, 'completed');
  assert.equal(receipt.branch.value, 'f:100');
  assert.equal(receipt.total, '364.80');
  assert.equal(receipt.change, '35.20');
  assert.equal(receipt.number, 'A1B-1030-0001');
  assert.ok(receipt.id > LOCAL_RECEIPT_BASE && Number.isSafeInteger(receipt.id));
  assert.equal(receipt.created_at, '2026-10-30T20:00:00+02:00');
  assert.equal(receipt.receipt_url, null, 'A cashier cannot reprint');
  assert.match(reply.json.first_print_url, new RegExp(`/admin/takeaway/receipts/${receipt.id}/print\\?initial_print=[0-9a-f]{64}$`));
  assert.equal(reply.json.today.count, 4, 'The dashboard\'s 3 plus this one');
  assert.equal(kicks(), 1, 'The sync is asked to send it right away');
  const [operation] = store.pendingOperations();
  assert.equal(operation.id, body.idempotency_key, 'The page\'s operation number: the dashboard never records it twice');
  assert.equal(operation.kind, 'sale');
  assert.equal(operation.data.snapshot_id, 'snap-1');
  assert.equal(operation.data.total_cents, 36480);
  assert.equal(operation.data.customer_phone, '+201012345678');
  assert.equal(operation.data.notes, 'بدون بصل');
  assert.deepEqual(operation.data.items, [{ product_id: 1, option_id: '', quantity_mode: 'weight', quantity: '1.500' },
    { product_id: 7, option_id: '', quantity_mode: 'piece', quantity: '2.000' }]);

  // The page retries (lost answer): the same invoice, nothing new.
  const again = await ask('POST', '/admin/takeaway/checkout', { body });
  assert.equal(again.json.replayed, true);
  assert.equal(again.json.receipt.id, receipt.id);
  assert.equal(store.pendingOperations().length, 1);
  // The same operation number for a different invoice is refused.
  assert.equal((await ask('POST', '/admin/takeaway/checkout', { body: { ...body, notes: 'غير' } })).status, 409);

  // Printing: the first print once, then only owners and the administration reprint.
  const first = new URL(reply.json.first_print_url);
  const printed = await ask('GET', first.pathname + first.search + '&dashboard_print=1');
  assert.equal(printed.status, 200);
  assert.match(printed.html, /data-dashboard-receipt="takeaway"/);
  assert.match(printed.html, /A1B-1030-0001/);
  assert.doesNotMatch(printed.html, /window\.print\(\);\}\);/, 'Printed from the dashboard\'s hidden frame: no automatic print');
  assert.equal((await ask('GET', first.pathname + first.search)).status, 403, 'The first print is used');
  const owner = { ...ACCOUNT, id: 1 };
  assert.equal((await ask('GET', first.pathname, { user: owner })).status, 200);
  const details = await ask('GET', `/admin/takeaway/receipts/${receipt.id}/details`);
  assert.match(details.html, /data-dashboard-invoice-details="1"/);
});

test('checkout refuses what the dashboard refuses', async () => {
  const { store, ask } = cashierPage();
  assert.equal((await sellOnPage(ask, { customer_phone: '123' })).reply.status, 422);
  assert.equal((await sellOnPage(ask, { service_consent: false })).reply.status, 422);
  assert.equal((await sellOnPage(ask, { cash_received: '100.00' })).reply.status, 422);
  assert.equal((await sellOnPage(ask, { payment_method: 'card', payment_confirmed: true })).reply.status, 422);
  assert.equal((await sellOnPage(ask, { quote_hash: 'a'.repeat(64) })).reply.status, 409, 'A quote from the dashboard is redone on the device');
  assert.equal((await sellOnPage(ask, { idempotency_key: 'not-a-key' })).reply.status, 422);
  assert.equal(store.pendingOperations().length, 0);
});

test('the day\'s invoices and a lost sale\'s recovery come from the device', async () => {
  const { store, ask, tick } = cashierPage();
  const { body, reply } = await sellOnPage(ask);
  const list = (await ask('GET', '/admin/takeaway/receipts?branch=f:100&page=1')).json;
  assert.deepEqual(list.items.map((item) => item.id), [reply.json.receipt.id]);
  assert.equal(list.pagination.total, 1);
  const recovered = (await ask('GET', `/admin/takeaway/receipts?branch=f:100&idempotency_key=${body.idempotency_key}`)).json;
  assert.equal(recovered.receipt.id, reply.json.receipt.id);
  assert.equal(recovered.replayed, true);
  assert.ok(recovered.first_print_url);
  tick(11 * 60 * 1000);
  assert.equal((await ask('GET', `/admin/takeaway/receipts?branch=f:100&idempotency_key=${body.idempotency_key}`)).json.first_print_url, null,
    'The first print lasts ten minutes, like the dashboard');
  const other = (await ask('GET', `/admin/takeaway/receipts?branch=f:100&idempotency_key=${body.idempotency_key}`, { user: { ...ACCOUNT, id: 1 } })).json;
  assert.equal(other.receipt, null, 'Recovery is the cashier\'s own sale only');

  // Once the dashboard has it (and its daily count includes it), it is not counted twice.
  store.applyResults([{ id: body.idempotency_key, status: 'applied', receipt: { id: 77, number: 'F100-0004' } }]);
  tick(1000);
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1',
    today: { date: '2026-10-30', count: 4, total: '814.80', cash: '814.80', card: '0.00', mobile_wallet: '0.00', other: '0.00', mixed: '0.00' } }]);
  const after = (await ask('GET', '/admin/takeaway/receipts?branch=f:100')).json;
  assert.equal(after.today.count, 4);
  assert.equal(after.today.total, '814.80');
  assert.equal(after.items[0].number, 'F100-0004', 'The dashboard\'s number once it has the invoice');
});

// ---- the device's copy of the dashboard ----
const { PageCache } = require('../src/main/offline/cache.cjs');

test('saved pages are kept per account, files are shared, and the copy stays within its size', () => {
  let clock = Date.parse('2026-10-30T10:00:00Z');
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  cache.put('u:4', 'page', 'https://fasakhaninja.com/admin/takeaway?branch=f:100', 'text/html', Buffer.from('<p>4</p>'));
  cache.put('shared', 'file', 'https://fasakhaninja.com/dashboard/js/takeaway-pos.js?v=1', 'text/javascript', Buffer.from('js'));
  assert.equal(cache.get('u:4', 'page', 'https://fasakhaninja.com/admin/takeaway?branch=f:100').body.toString(), '<p>4</p>');
  assert.equal(cache.get('u:4', 'page', 'https://fasakhaninja.com/admin/takeaway'), null, 'Exactly this address');
  assert.equal(cache.get('u:4', 'page', 'https://fasakhaninja.com/admin/takeaway', { pathOnly: true }).body.toString(), '<p>4</p>', 'Or the same page');
  assert.equal(cache.get('u:1', 'page', 'https://fasakhaninja.com/admin/takeaway', { pathOnly: true }), null, 'Never another account\'s page');
  assert.ok(cache.get('shared', 'file', 'https://fasakhaninja.com/dashboard/js/takeaway-pos.js?v=1&_=123#x'), 'Cache-busting and fragments ignored');
  assert.ok(cache.fresh('shared', 'file', 'https://fasakhaninja.com/dashboard/js/takeaway-pos.js?v=1', 1000));
  clock += 2000;
  assert.ok(!cache.fresh('shared', 'file', 'https://fasakhaninja.com/dashboard/js/takeaway-pos.js?v=1', 1000));
  assert.equal(cache.latest('u:4', 'page'), '/admin/takeaway');
  assert.deepEqual(cache.scopes().sort(), ['shared', 'u:4']);
  for (let index = 0; index < 5; index++) {
    clock += 1000;
    cache.put('shared', 'file', `https://fasakhaninja.com/i/${index}.png`, 'image/png', Buffer.alloc(400));
  }
  cache.get('shared', 'file', 'https://fasakhaninja.com/i/0.png'); // used again: kept
  assert.ok(cache.trim(1500) > 0);
  assert.ok(cache.get('shared', 'file', 'https://fasakhaninja.com/i/0.png'), 'Recently used files stay');
  assert.equal(cache.get('shared', 'file', 'https://fasakhaninja.com/i/1.png'), null, 'The longest unused go first');
  cache.forgetScope('u:4');
  assert.equal(cache.count('u:4', 'page'), 0);
});

// ---- the expenses page (مصروفات الفروع) answered from the device while offline ----
const { ExpensesOffline, LOCAL_EXPENSE_BASE } = require('../src/main/offline/expenses.cjs');

function expensesPage() {
  let clock = Date.parse('2026-10-30T18:00:00Z');
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  store.replaceUsers([ACCOUNT, { ...ACCOUNT, id: 1, name: 'المالك', can_manage: true, can_reprint: true, can_approve_expenses: true }], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', catalog: { ...MENU, branch: { ...MENU.branch, name: 'المعادي' },
    expense_categories: [{ key: 'electricity', name: 'كهرباء', stock: null }, { key: 'purchase_tomato', name: 'طماطم', stock: 'purchase_tomato' }] } }]);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const page = new ExpensesOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock) });
  const ask = async (method, path, { user = ACCOUNT, body = null } = {}) => {
    const url = new URL(ORIGIN + path);
    const route = page.match(method, url.pathname);
    assert.ok(route, `${method} ${path} is answered on the device`);
    return page.answer(route, { user, url, body });
  };
  return { store, cache, ask, tick: (ms) => { clock += ms; } };
}

const expenseForm = (extra = {}) => ({ __files: [], branch: 'f:100', category: 'electricity', description: 'فاتورة كهرباء', amount: '350.50',
  occurred_on: '2026-10-30', payment_method: 'cash', approve: '0', idempotency_key: '2b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', ...extra });

test('the expenses page saves a cash expense on the device once, under the page\'s operation number', async () => {
  const { store, ask } = expensesPage();
  const saved = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm() });
  assert.equal(saved.status, 200, JSON.stringify(saved.json));
  const { expense } = saved.json;
  assert.ok(expense.id > LOCAL_EXPENSE_BASE);
  assert.deepEqual([expense.status, expense.amount, expense.occurred_on, expense.category_name, expense.payment_method],
    ['pending', '350.50', '2026-10-30', 'كهرباء — فرع المعادي', 'cash']);
  const [operation] = store.pendingOperations();
  assert.equal(operation.id, expenseForm().idempotency_key);
  assert.equal(operation.kind, 'expense');
  assert.deepEqual([operation.data.category, operation.data.amount, operation.data.approve], ['electricity', '350.50', false]);
  const again = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm() });
  assert.equal(again.json.replayed, true);
  assert.equal(again.json.expense.id, expense.id);
  assert.equal(store.pendingOperations().length, 1, 'Never twice');
  // What needs the internet says so.
  const attached = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm({ idempotency_key: '3b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', __files: [{ field: 'attachment', name: 'فاتورة.jpg' }] }) });
  assert.equal(attached.status, 422);
  assert.match(attached.json.message, /المرفقات/);
  const earlier = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm({ idempotency_key: '4b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', occurred_on: '2026-10-29' }) });
  assert.equal(earlier.status, 422);
  const approve = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm({ idempotency_key: '5b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', approve: '1' }) });
  assert.equal(approve.status, 422, 'A cashier cannot approve');
  const owner = { ...ACCOUNT, id: 1 };
  const approved = await ask('POST', '/admin/branch-expenses/save', { user: owner, body: expenseForm({ idempotency_key: '6b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', approve: '1', amount: '75' }) });
  assert.equal(approved.json.expense.status, 'approved');
  const stock = await ask('POST', '/admin/branch-expenses/save', { body: expenseForm({ idempotency_key: '7b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', category: 'purchase_tomato', amount: '60' }) });
  assert.equal(stock.status, 422, 'A stock purchase needs its kilograms, like the dashboard');
  assert.equal((await ask('GET', `/admin/branch-expenses/${expense.id}`)).json.expense.number, expense.number);
  assert.equal((await ask('POST', `/admin/branch-expenses/${expense.id}/review`)).status, 409);
});

test('the expenses list is the last one seen online with this device\'s expenses on top', async () => {
  const { cache, ask, tick } = expensesPage();
  const listUrl = '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1';
  // Nothing saved online for this list yet: only the device's expenses.
  await ask('POST', '/admin/branch-expenses/save', { body: expenseForm() });
  const alone = (await ask('GET', listUrl)).json;
  assert.deepEqual(alone.items.map((item) => item.amount), ['350.50']);
  assert.equal(alone.summary.pending, 1);
  // The dashboard's list seen online earlier.
  const server = { success: true, items: [{ id: 41, number: 'EXP-000041', amount: '100.00', status: 'approved', occurred_on: '2026-10-29' }],
    filters: { branch: 'f:100', from: '2026-10-01', to: '2026-10-30' }, actors: [{ id: 4, name: 'كاشير' }], pagination: { page: 1, last_page: 1, total: 1 },
    summary: { today: '0.00', month: '100.00', period: '100.00', average: '3.33', count: 1, pending: 0, top_category: 'gas', top_amount: '100.00' } };
  tick(-60000);
  cache.put('u:4', 'data', ORIGIN + listUrl, 'application/json', Buffer.from(JSON.stringify(server)));
  tick(60000);
  const merged = (await ask('GET', listUrl)).json;
  assert.deepEqual(merged.items.map((item) => item.number), ['جهاز-1', 'EXP-000041']);
  assert.deepEqual([merged.pagination.total, merged.summary.pending, merged.summary.period], [2, 1, '100.00']);
  // Another filter of the same period uses the same base, filtered the same way; another period does not.
  assert.deepEqual((await ask('GET', '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1&status=approved')).json.items
    .map((item) => item.number), ['EXP-000041']);
  assert.deepEqual((await ask('GET', '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1&status=pending')).json.items
    .map((item) => item.number), ['جهاز-1']);
  assert.equal((await ask('GET', '/admin/branch-expenses/data?branch=f:100&from=2026-09-01&to=2026-09-30')).json.items.length, 0);
  // A lost answer is found again; the badge counts the device's pending expense.
  const found = (await ask('GET', `/admin/branch-expenses/recover?branch=f:100&idempotency_key=${expenseForm().idempotency_key}`)).json;
  assert.equal(found.found, true);
  assert.equal((await ask('GET', '/admin/branch-expenses/recover?branch=f:100&idempotency_key=9b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f')).json.found, false);
  assert.equal((await ask('GET', '/admin/branch-expenses/pending-count')).json.count, 1);
});

test('without a list seen online, the expenses page\'s own saved list is the base for its period', async () => {
  const { cache, ask } = expensesPage();
  const boot = { actor_id: 4, initial: { success: true, filters: { branch: 'f:100', from: '2026-10-01', to: '2026-10-30' }, actors: [],
    items: [{ id: 7, number: 'EXP-000007', amount: '20.00', status: 'pending', category: 'gas', actor_id: 5, description: 'غاز' }],
    pagination: { page: 1, last_page: 1, total: 1 }, summary: { today: '0.00', month: '0.00', period: '0.00', average: '0.00', count: 0, pending: 1,
      top_category: null, top_amount: '0.00' } } };
  const html = `<html><body data-dashboard-actor="4:admin"><script type="application/json" id="branch-expenses-bootstrap">${JSON.stringify(boot).replace(/</g, '\\u003C')}</script></body></html>`;
  cache.put('u:4', 'page', ORIGIN + '/admin/branch-expenses', 'text/html', Buffer.from(html));
  await ask('POST', '/admin/branch-expenses/save', { body: expenseForm() });
  const list = (await ask('GET', '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1&search=&category=')).json;
  assert.deepEqual(list.items.map((item) => item.number), ['جهاز-1', 'EXP-000007']);
  assert.equal(list.summary.pending, 2);
  const gas = (await ask('GET', '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1&category=gas')).json;
  assert.deepEqual(gas.items.map((item) => item.number), ['EXP-000007'], 'Filters apply to the saved list too');
});

// ---- the employees page (الموظفون والرواتب): check-in and check-out from the device while offline ----
const { EmployeesOffline } = require('../src/main/offline/employees.cjs');

test('the employees page records a check-in and check-out on the device, once, with the day\'s notes', async () => {
  let clock = Date.parse('2026-10-30T08:40:00Z'); // 10:40 in Cairo
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  store.replaceUsers([ACCOUNT], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', attendance: [], catalog: { ...MENU,
    employees: [{ id: 50, name: 'سعيد', job_title: 'كاشير', schedule_type: 'branch', shift: 'صباحي' }],
    attendance_shifts: [{ shift: 'morning', starts_at: '10:00', ends_at: '18:00' }] } }]);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const page = new EmployeesOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock) });
  const ask = async (method, path, body = null) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user: ACCOUNT, url, body });
  };
  const key = '8b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f';
  const punch = { branch: 'f:100', employee_id: 50, day: '2026-10-30', status: 'morning', action: 'check_in', notes: 'جه بدري', expected_revision: null, idempotency_key: key };
  const arrived = await ask('POST', '/admin/employees/attendance', punch);
  assert.equal(arrived.status, 200, JSON.stringify(arrived.json));
  assert.deepEqual([arrived.json.attendance.status, arrived.json.attendance.check_in, arrived.json.attendance.checked_in_at, arrived.json.attendance.notes],
    ['morning', '10:40:00', '2026-10-30 08:40:00', 'جه بدري']);
  const [operation] = store.pendingOperations();
  assert.deepEqual([operation.id, operation.kind, operation.data.action, operation.data.notes], [key, 'attendance', 'check_in', 'جه بدري']);
  assert.equal((await ask('POST', '/admin/employees/attendance', punch)).json.replayed, true);
  assert.equal(store.pendingOperations().length, 1, 'Never twice');
  assert.equal((await ask('GET', `/admin/branch-operations/recover?branch=f:100&idempotency_key=${key}`)).json.found, true);
  assert.equal((await ask('POST', '/admin/employees/attendance', { ...punch, idempotency_key: '9c8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f', action: 'set_status', status: 'unauthorized_absence' })).status, 422);
  // The list seen online shows the device's punch.
  cache.put('u:4', 'data', ORIGIN + '/admin/employees/data?branch=f:100&day=2026-10-30&month=2026-10&page=1', 'application/json',
    Buffer.from(JSON.stringify({ success: true, filters: { branch: 'f:100', day: '2026-10-30', month: '2026-10' }, items: [{ id: 50, branch: 'f:100', name: 'سعيد', attendance: null }] })));
  clock += 4 * 3600 * 1000; // 14:40
  await ask('POST', '/admin/employees/attendance', { ...punch, action: 'check_out', notes: '', idempotency_key: '7c8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f' });
  const list = (await ask('GET', '/admin/employees/data?branch=f:100&day=2026-10-30&month=2026-10&page=1')).json;
  assert.deepEqual([list.items[0].attendance.check_in, list.items[0].attendance.check_out, list.items[0].attendance.notes], ['10:40:00', '14:40:00', 'جه بدري']);
  assert.equal((await ask('GET', '/admin/employees/data?branch=f:100&day=2026-10-29&month=2026-10&page=1')).status, 503, 'Another day needs the internet');
});
