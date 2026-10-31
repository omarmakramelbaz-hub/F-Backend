'use strict';
// The dashboard's own pages offline: receipts identical to the dashboard's, and the cashier page's answers.
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { renderReceipt, renderKitchen, renderBatch, ticketBill } = require('../src/main/offline/receipt.cjs');

const normalize = (html) => html.replace(/\s+/g, ' ').replace(/\s*([<>])\s*/g, '$1').trim();

test('offline receipts, bills and kitchen tickets are the dashboard print pages, case by case', () => {
  // Rendered by test/receipt-fixtures.php from the dashboard's own views (takeaway/receipt, pos_service/ticket and kitchen).
  const { cases } = require(path.join(__dirname, 'fixtures', 'receipts.json'));
  assert.ok(cases.length >= 13);
  const origin = 'https://fasakhaninja.com';
  for (const sample of cases) {
    const html = sample.kind === 'kitchen' ? renderKitchen(sample.kitchen, { origin, dashboardPrint: sample.dashboard_print })
      : sample.kind === 'batch' ? renderBatch(sample.batch, { origin })
      : renderReceipt(sample.kind === 'ticket' ? ticketBill(sample.ticket) : sample.receipt, { origin, preview: sample.preview, dashboardPrint: sample.dashboard_print });
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

// ---- the dine-in page (الصالة) answered from the device while offline ----
const { ServiceOffline, LOCAL_TICKET_BASE, LOCAL_KITCHEN_BASE } = require('../src/main/offline/service.cjs');

// The dashboard's own answers, recorded by test/dining-capture.php: the device must answer in the same shape.
const DASHBOARD = JSON.parse(JSON.stringify(require('./fixtures/dining.json')).replaceAll('http://127.0.0.1:8099', ORIGIN));
const shape = (value) => Object.keys(value).sort();
const KEYS = { tables: shape(DASHBOARD[0].json), table: shape(DASHBOARD[0].json.tables[0]), summary: shape(DASHBOARD[5].json.tables[0].ticket),
  ticket: shape(DASHBOARD[3].json.ticket), save: shape(DASHBOARD[2].json), kitchen: shape(DASHBOARD[3].json), settle: shape(DASHBOARD[8].json),
  receipt: shape(DASHBOARD[8].json.receipt), listing: shape(DASHBOARD[4].json), quote: shape(DASHBOARD[1].json), show: shape(DASHBOARD[7].json) };
// The dashboard adds the order's number when the branch numbers its orders per shift; the device always numbers its own.
const withNumber = (keys) => [...keys, 'number'].sort();

/** The checks dining-pos.js makes before it trusts a write's answer (validateOperationResult), in the same order. */
function scaled(value, places) {
  const found = String(value ?? '').trim().match(new RegExp('^([0-9]{1,10})(?:\\.([0-9]{1,' + places + '}))?$'));
  return found ? BigInt(found[1]) * 10n ** BigInt(places) + BigInt((found[2] || '').padEnd(places, '0') || '0') : null;
}
function validTicket(value, branch) {
  return !!value && value.channel === 'dine' && Number.isSafeInteger(Number(value.id)) && Number(value.id) > 0 && Number.isSafeInteger(Number(value.revision))
    && Number(value.revision) > 0 && !!value.branch && value.branch.value === branch && Array.isArray(value.items) && !!value.table && Number(value.table.id) > 0
    && scaled(value.total, 2) !== null;
}
function pageAccepts(result, op) {
  const evidence = result && result.operation;
  assert.ok(validTicket(result.ticket, op.payload.branch), 'a valid order');
  assert.equal(typeof result.replayed, 'boolean');
  assert.ok(evidence && evidence.idempotency_key === op.payload.idempotency_key && evidence.kind === op.kind, 'the same operation');
  assert.equal(Number(evidence.ticket_id), Number(result.ticket.id));
  assert.equal(Number(evidence.revision), op.payload.expected_revision ? Number(op.payload.expected_revision) + 1 : 1, 'the next revision');
  assert.ok(Number(result.ticket.revision) >= Number(evidence.revision));
  if (op.id) assert.equal(Number(result.ticket.id), Number(op.id));
  const superseded = result.replayed && Number(result.ticket.revision) > Number(evidence.revision);
  if (op.kind === 'save') {
    assert.equal(Number(result.ticket.table.id), Number(op.payload.table_id));
    if (!superseded) {
      assert.equal(result.ticket.quote_hash, op.payload.quote_hash);
      assert.equal(scaled(result.ticket.total, 2), op.expectedTotal);
      assert.equal(result.ticket.items.length, op.payload.items.length);
      assert.equal(result.ticket.waiter_name, op.payload.waiter_name);
      assert.equal(Number(result.ticket.guest_count), op.payload.guest_count);
      assert.equal(result.ticket.notes || '', op.payload.notes);
    }
  }
  if (!superseded && op.expectedQuoteHash) assert.equal(result.ticket.quote_hash, op.expectedQuoteHash);
  if (op.kind === 'action' && op.payload.action === 'request_bill') assert.ok(result.ticket.bill_locked);
  if (op.kind !== 'settle') return;
  const { receipt } = result;
  const printURL = result.first_print_url || result.receipt_url;
  const path = printURL && new URL(printURL).pathname.match(/\/takeaway\/receipts\/([1-9][0-9]*)\/print\/?$/);
  assert.equal(result.ticket.payment_status, 'paid');
  assert.ok(receipt && Number.isSafeInteger(Number(receipt.id)) && Number(receipt.id) > 0);
  assert.equal(Number(receipt.ticket_id), Number(op.id));
  assert.equal(receipt.channel, 'dine');
  assert.equal(receipt.branch.value, op.payload.branch);
  assert.equal(receipt.idempotency_key, op.payload.idempotency_key);
  assert.equal(receipt.payment_method, op.payload.payment_method);
  assert.equal(scaled(receipt.total, 2), op.expectedTotal);
  if (printURL) assert.ok(path && Number(path[1]) === Number(receipt.id), 'the receipt print link');
}

/** The branch's tables and open orders as the dashboard sends them: table 1 busy with order 1, table 2 free. */
function serviceCopy() {
  const busy = DASHBOARD[5].json.tables[0];
  const free = { ...DASHBOARD[0].json.tables[0], id: 2, name: 'ترابيزة 2', capacity: 6 };
  return { value: 'f:100', hash: 'service-1', tables: [busy, free], policy: DASHBOARD[0].json.policy, tickets: [DASHBOARD[3].json.ticket] };
}

function diningPage({ kinds = ['sale', 'ticket_settle', 'ticket_open', 'ticket_action'] } = {}) {
  const cashier = cashierPage();
  const { store } = cashier;
  store.set('server_kinds', kinds);
  store.applyService([serviceCopy()]);
  let kicks = 0;
  const now = () => store.now();
  const page = new ServiceOffline({ store, origin: ORIGIN, now, kick: () => { kicks++; }, channel: 'dine' });
  const ask = async (method, path, { user = ACCOUNT, body = null } = {}) => {
    const url = new URL(ORIGIN + path);
    const route = page.match(method, url.pathname);
    assert.ok(route, `${method} ${path} is answered on the device`);
    return page.answer(route, { user, url, body });
  };
  let keys = 0;
  const key = () => `00000000-0000-4000-8000-${String(++keys).padStart(12, '0')}`;
  return { ...cashier, page, ask, key, kicks: () => kicks };
}

const DINE_ITEMS = [{ product_id: 1, quantity: '1.000', quantity_mode: 'weight' }, { product_id: 7, quantity: '2', quantity_mode: 'piece' }];

test('the dine-in floor offline: the dashboard tables and open orders from the last sync, in the dashboard format', async () => {
  const { ask } = diningPage();
  const floor = await ask('GET', '/admin/dining/tables?branch=f:100');
  assert.equal(floor.status, 200, JSON.stringify(floor.json));
  assert.deepEqual(shape(floor.json), KEYS.tables);
  assert.deepEqual(floor.json.tables.map((table) => [table.id, table.status, table.ticket_id]), [[1, 'in_service', 1], [2, 'free', null]]);
  for (const table of floor.json.tables) assert.deepEqual(shape(table), KEYS.table);
  assert.deepEqual(floor.json.permissions, { can_checkout: true, can_manage_tables: true, can_manage: false, can_reprint: false });
  const order = await ask('GET', '/admin/dining/tickets/1');
  assert.deepEqual(shape(order.json), KEYS.show);
  assert.equal(order.json.ticket.status, 'preparing');
  const catalog = (await ask('GET', '/admin/dining/catalog?branch=f:100&page=1&per_page=24')).json;
  assert.deepEqual(catalog.policy, { tax_rate: '14.00', service_rate: '0.00', can_discount: false, payment_methods: ['cash'], currency: 'EGP',
    settings_revision: 1, channel: 'dine' });
  assert.equal((await ask('GET', '/admin/dining/tickets/77')).status, 404, 'An order the device does not have');
  const list = await ask('GET', '/admin/dining/tickets?branch=f:100&page=1&per_page=40');
  assert.deepEqual(shape(list.json), KEYS.listing);
  assert.deepEqual(list.json.items.map((item) => item.id), [1]);
});

test('a table opened offline: kitchen ticket, bill and payment on the device, sent to the dashboard as one invoice', async () => {
  const { store, ask, key } = diningPage();
  const quote = await ask('POST', '/admin/dining/quote', { body: { branch: 'f:100', items: DINE_ITEMS, discount: '0.00', discount_reason: '' } });
  assert.deepEqual(shape(quote.json), KEYS.quote);
  // 180 + 2 × 25 = 230, tax 14% = 32.20.
  assert.deepEqual([quote.json.subtotal, quote.json.tax, quote.json.total], ['230.00', '32.20', '262.20']);
  const save = { kind: 'save', id: null, expectedTotal: scaled(quote.json.total, 2), payload: { branch: 'f:100', items: DINE_ITEMS, discount: '0.00', discount_reason: '',
    table_id: 2, customer_name: 'أسرة محمد', customer_phone: null, service_consent: false, marketing_consent: false, waiter_name: 'حسن', guest_count: 3,
    notes: 'بدون بصل', quote_hash: quote.json.quote_hash, idempotency_key: key() } };
  const saved = await ask('POST', '/admin/dining/tickets/save', { body: save.payload });
  assert.equal(saved.status, 200, JSON.stringify(saved.json));
  assert.deepEqual(shape(saved.json), KEYS.save);
  assert.deepEqual(shape(saved.json.ticket), withNumber(KEYS.ticket));
  pageAccepts(saved.json, save);
  const ticket = saved.json.ticket;
  assert.ok(ticket.id > LOCAL_TICKET_BASE);
  assert.deepEqual([ticket.status, ticket.revision, ticket.table.name, ticket.total], ['open', 1, 'ترابيزة 2', '262.20']);
  assert.equal(ticket.number, 'DI-A1B-1');
  assert.equal(store.pendingOperations().length, 0, 'Nothing for the dashboard yet: the order is still open');

  const floor = (await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables;
  assert.deepEqual([floor[1].status, floor[1].ticket_id], ['in_service', ticket.id], 'The table is busy with this device\'s order');
  assert.deepEqual(shape(floor[1].ticket), [...KEYS.summary, 'number'].sort());
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...save.payload, idempotency_key: key() } })).status, 409, 'The table is taken');

  const kitchen = { kind: 'action', id: ticket.id, expectedQuoteHash: ticket.quote_hash,
    payload: { branch: 'f:100', expected_revision: 1, idempotency_key: key(), action: 'send_kitchen' } };
  const sent = await ask('POST', `/admin/dining/tickets/${ticket.id}/action`, { body: kitchen.payload });
  assert.equal(sent.status, 200, JSON.stringify(sent.json));
  assert.deepEqual(shape(sent.json), KEYS.kitchen);
  pageAccepts(sent.json, kitchen);
  assert.equal(sent.json.ticket.status, 'preparing');
  assert.equal(sent.json.kitchen_print_url, `${ORIGIN}/admin/dining/kitchen/${LOCAL_KITCHEN_BASE + 1}/print`);
  const slip = await ask('GET', `/admin/dining/kitchen/${LOCAL_KITCHEN_BASE + 1}/print?dashboard_print=1`);
  assert.equal(slip.status, 200);
  assert.match(slip.html, /data-dashboard-receipt="pos-kitchen"/);
  assert.match(slip.html, /بوري مشوي/);
  assert.match(slip.html, /DI-A1B-1/);
  assert.doesNotMatch(slip.html, /addEventListener\('load'/, 'Printed by the dashboard\'s own print frame');
  assert.equal((await ask('POST', `/admin/dining/tickets/${ticket.id}/action`, { body: { ...kitchen.payload, expected_revision: 2, idempotency_key: key() } })).status, 409);

  const bill = { kind: 'action', id: ticket.id, expectedQuoteHash: ticket.quote_hash,
    payload: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'request_bill' } };
  const billed = await ask('POST', `/admin/dining/tickets/${ticket.id}/action`, { body: bill.payload });
  pageAccepts(billed.json, bill);
  assert.equal(billed.json.ticket.status, 'awaiting_bill');
  const printed = await ask('GET', `/admin/dining/tickets/${ticket.id}/print?dashboard_print=1`);
  assert.equal(printed.status, 200);
  assert.match(printed.html, /data-dashboard-receipt="pos-ticket"/);
  assert.match(printed.html, /كشف حساب غير مسدد/);
  assert.equal((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables[1].status, 'awaiting_bill');

  const pay = { kind: 'settle', id: ticket.id, expectedTotal: scaled('262.20', 2), payload: { branch: 'f:100', expected_revision: 3, idempotency_key: key(),
    quote_hash: ticket.quote_hash, customer_phone: '+201012345678', service_consent: true, marketing_consent: false, payment_method: 'cash',
    cash_received: '300.00', payment_confirmed: true, payment_reference: '' } };
  assert.equal((await ask('POST', `/admin/dining/tickets/${ticket.id}/settle`, { body: { ...pay.payload, service_consent: false } })).status, 422);
  const paid = await ask('POST', `/admin/dining/tickets/${ticket.id}/settle`, { body: pay.payload });
  assert.equal(paid.status, 200, JSON.stringify(paid.json));
  assert.deepEqual(shape(paid.json), KEYS.settle);
  assert.deepEqual(shape(paid.json.receipt), [...KEYS.receipt, 'device'].sort());
  pageAccepts(paid.json, pay);
  assert.deepEqual([paid.json.ticket.status, paid.json.ticket.payment_status, paid.json.receipt.change], ['paid', 'paid', '37.80']);
  assert.equal(paid.json.receipt.id, LOCAL_RECEIPT_BASE + 1, 'The receipt keeps the order\'s number on the device');
  assert.deepEqual(paid.json.receipt.context.table, { id: 2, name: 'ترابيزة 2', capacity: 6 });
  assert.equal(paid.json.receipt.context.waiter_name, 'حسن');
  const [operation] = store.pendingOperations();
  assert.equal(operation.id, pay.payload.idempotency_key);
  assert.equal(operation.kind, 'sale');
  assert.deepEqual([operation.data.channel, operation.data.table_name, operation.data.total_cents, operation.data.snapshot_id], ['dine', 'ترابيزة 2', 26220, 'snap-1']);
  assert.equal(operation.data.customer_phone, '+201012345678');
  const again = await ask('POST', `/admin/dining/tickets/${ticket.id}/settle`, { body: pay.payload });
  assert.equal(again.json.replayed, true, 'A retry: the same payment');
  assert.equal(again.json.receipt.id, paid.json.receipt.id);
  assert.equal(store.pendingOperations().length, 1);
  const recovered = (await ask('GET', `/admin/dining/recover?branch=f:100&idempotency_key=${save.payload.idempotency_key}`)).json;
  assert.equal(recovered.found, true);
  assert.equal(recovered.ticket.payment_status, 'paid');
  assert.equal((await ask('GET', '/admin/dining/recover?branch=f:100&idempotency_key=00000000-0000-4000-8000-999999999999')).json.found, false);
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['in_service', 'free'], 'The table is free again');
  const receiptPage = await ask('GET', `/admin/dining/tickets/${ticket.id}/details`);
  assert.match(receiptPage.html, /data-dashboard-invoice-details="1"/);
});

test('an order open on the dashboard: its bill printed and paid offline, sent with the page\'s operation numbers', async () => {
  const { store, ask, key, kicks } = diningPage();
  const order = (await ask('GET', '/admin/dining/tickets/1')).json.ticket;
  const edit = await ask('POST', '/admin/dining/tickets/save', { body: { branch: 'f:100', ticket_id: 1, expected_revision: 2, table_id: 1, items: DINE_ITEMS,
    waiter_name: 'حسن', guest_count: 2, notes: '', quote_hash: order.quote_hash, idempotency_key: key() } });
  assert.equal(edit.status, 422);
  assert.match(edit.json.message, /يحتاج اتصالًا بالإنترنت/);
  const bill = { kind: 'action', id: 1, expectedQuoteHash: order.quote_hash, payload: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'request_bill' } };
  const billed = await ask('POST', '/admin/dining/tickets/1/action', { body: bill.payload });
  assert.equal(billed.status, 200, JSON.stringify(billed.json));
  pageAccepts(billed.json, bill);
  assert.deepEqual([billed.json.ticket.status, billed.json.ticket.revision], ['awaiting_bill', 3]);
  assert.equal(kicks(), 1);
  const [action] = store.pendingOperations();
  assert.deepEqual([action.id, action.kind, action.data.action, action.data.ticket_id, action.data.quote_hash], [bill.payload.idempotency_key, 'ticket_action', 'request_bill', 1, order.quote_hash]);
  const print = await ask('GET', '/admin/dining/tickets/1/print');
  assert.match(print.html, /كشف حساب غير مسدد/);
  assert.match(print.html, /addEventListener\('load',function\(\)\{window\.print\(\);\}\)/, 'Opened on its own: it prints itself');
  assert.equal((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables[0].status, 'awaiting_bill');

  const pay = { kind: 'settle', id: 1, expectedTotal: scaled(order.total, 2), payload: { branch: 'f:100', expected_revision: 3, idempotency_key: key(),
    quote_hash: order.quote_hash, customer_phone: '01012345678', service_consent: true, marketing_consent: false, payment_method: 'cash',
    cash_received: '250.00', payment_confirmed: true, payment_reference: '' } };
  assert.equal((await ask('POST', '/admin/dining/tickets/1/settle', { body: { ...pay.payload, expected_revision: 2 } })).status, 409, 'An old revision');
  assert.equal((await ask('POST', '/admin/dining/tickets/1/settle', { body: { ...pay.payload, cash_received: '100.00' } })).status, 422);
  const paid = await ask('POST', '/admin/dining/tickets/1/settle', { body: pay.payload });
  assert.equal(paid.status, 200, JSON.stringify(paid.json));
  pageAccepts(paid.json, pay);
  assert.equal(paid.json.receipt.total, order.total);
  assert.equal(paid.json.receipt.context.customer_phone, '+201012345678');
  const settle = store.pendingOperations().find((operation) => operation.kind === 'ticket_settle');
  assert.equal(settle.id, pay.payload.idempotency_key);
  assert.deepEqual(settle.data, { channel: 'dine', ticket_id: 1, expected_revision: 2, quote_hash: order.quote_hash, cash_received: '250.00',
    customer_phone: '+201012345678', service_consent: true });
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['free', 'free']);
  assert.equal(store.dayTotals(['f:100'], '2026-10-30').count, 4, 'The payment counts in the day until the dashboard has it');

  // Synced: the dashboard has both; its new copy (order paid, table free) replaces what the device showed.
  store.applyResults([{ id: bill.payload.idempotency_key, status: 'applied', ticket: { id: 1, revision: 3, status: 'awaiting_bill' } },
    { id: pay.payload.idempotency_key, status: 'applied', ticket: { id: 1, revision: 4, status: 'paid' }, receipt: { id: 51, number: 'TA-F-100-0000051', total: order.total } }]);
  const copy = serviceCopy();
  store.applyService([{ ...copy, hash: 'service-2', tables: [DASHBOARD[10].json.tables[0], copy.tables[1]], tickets: [] }]);
  assert.equal(store.serviceOverlay(1), null);
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['free', 'free']);
  assert.equal(store.ticket(pay.payload.idempotency_key).server_number, 'TA-F-100-0000051');
});

test('orders opened offline and still open move to the dashboard once the internet is back', async () => {
  const { store, ask, key } = diningPage();
  const quote = (await ask('POST', '/admin/dining/quote', { body: { branch: 'f:100', items: DINE_ITEMS } })).json;
  const open = async (table) => (await ask('POST', '/admin/dining/tickets/save', { body: { branch: 'f:100', items: DINE_ITEMS, table_id: table, waiter_name: 'حسن',
    guest_count: 2, customer_name: 'ضيف', notes: '', quote_hash: quote.quote_hash, idempotency_key: key() } })).json.ticket;
  const first = await open(2);
  await ask('POST', `/admin/dining/tickets/${first.id}/action`, { body: { branch: 'f:100', expected_revision: 1, idempotency_key: key(), action: 'send_kitchen' } });
  assert.equal(store.queueTicketMoves(), 1);
  const [move] = store.pendingOperations();
  assert.equal(move.kind, 'ticket_open');
  assert.deepEqual([move.data.table_id, move.data.waiter_name, move.data.kitchen_sent, move.data.bill_requested], [2, 'حسن', true, false]);
  assert.deepEqual(move.data.items, [{ product_id: 1, option_id: '', quantity_mode: 'weight', quantity: '1.000' }, { product_id: 7, option_id: '', quantity_mode: 'piece', quantity: '2' }]);
  assert.equal(store.queueTicketMoves(), 0, 'Queued once');

  // Changed here before the move left: the move waits and is queued again with the change.
  const billed = await ask('POST', `/admin/dining/tickets/${first.id}/action`, { body: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'request_bill' } });
  assert.equal(billed.status, 200, JSON.stringify(billed.json));
  assert.equal(store.pendingOperations().length, 0);
  assert.equal(store.queueTicketMoves(), 1);
  assert.equal(store.pendingOperations()[0].data.bill_requested, true);

  // Being sent right now: it can no longer change here.
  store.sending = new Set([move.id]);
  const late = await ask('POST', `/admin/dining/tickets/${first.id}/settle`, { body: { branch: 'f:100', expected_revision: 3, idempotency_key: key(),
    quote_hash: first.quote_hash, customer_phone: '01012345678', service_consent: true, payment_method: 'cash', cash_received: '300.00' } });
  assert.equal(late.status, 409);
  store.sending = null;

  store.applyResults([{ id: move.id, status: 'applied', ticket: { id: 31, revision: 3, status: 'awaiting_bill', number: '' } }]);
  const moved = await ask('GET', `/admin/dining/tickets/${first.id}`);
  assert.deepEqual([moved.status, moved.location], [302, `${ORIGIN}/admin/dining/tickets/31`], 'It opens from the dashboard now');
  assert.equal((await ask('POST', `/admin/dining/tickets/${first.id}/settle`, { body: { branch: 'f:100', expected_revision: 3, idempotency_key: key(),
    quote_hash: first.quote_hash, customer_phone: '01012345678', service_consent: true, payment_method: 'cash', cash_received: '300.00' } })).status, 409);
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['in_service', 'free'],
    'Its table shows what the dashboard sends next');

  // A server that does not know moves yet: the order stays on the device and is paid there.
  store.set('server_kinds', ['sale']);
  await open(2);
  assert.equal(store.queueTicketMoves(), 0);
});

test('the dine-in page offline keeps the dashboard rules', async () => {
  const { ask, key } = diningPage({ kinds: ['sale'] });
  const quote = (await ask('POST', '/admin/dining/quote', { body: { branch: 'f:100', items: DINE_ITEMS } })).json;
  const body = { branch: 'f:100', items: DINE_ITEMS, table_id: 2, waiter_name: 'حسن', guest_count: 2, notes: '', quote_hash: quote.quote_hash };
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: key(), guest_count: 7 } })).status, 422, 'More guests than seats');
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: key(), waiter_name: ' ' } })).status, 422);
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: key(), table_id: 9 } })).status, 422);
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: key(), quote_hash: 'a'.repeat(64) } })).status, 409);
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: 'not-a-key' } })).status, 422);
  const blocked = await ask('POST', '/admin/dining/tickets/save', { user: { ...ACCOUNT, id: 9 }, body: { ...body, idempotency_key: key() } });
  assert.equal(blocked.status, 401, 'An account the device does not know');
  const first = await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: key() } });
  const ticket = first.json.ticket;
  const replay = await ask('POST', '/admin/dining/tickets/save', { body: { ...body, idempotency_key: first.json.operation.idempotency_key } });
  assert.equal(replay.json.replayed, true);
  assert.equal(replay.json.ticket.id, ticket.id);
  assert.equal((await ask('POST', '/admin/dining/tickets/save', { body: { ...body, notes: 'تغيير', idempotency_key: first.json.operation.idempotency_key } })).status, 409,
    'The same operation number for a different request');
  // More items before the kitchen: priced again.
  const more = [...DINE_ITEMS, { product_id: 7, quantity: '1', quantity_mode: 'piece' }];
  const requote = (await ask('POST', '/admin/dining/quote', { body: { branch: 'f:100', items: more } })).json;
  const changed = await ask('POST', '/admin/dining/tickets/save', { body: { ...body, ticket_id: ticket.id, expected_revision: 1, items: more,
    quote_hash: requote.quote_hash, idempotency_key: key() } });
  assert.equal(changed.status, 200, JSON.stringify(changed.json));
  assert.deepEqual([changed.json.ticket.revision, changed.json.ticket.total], [2, requote.total]);
  assert.equal((await ask('POST', `/admin/dining/tickets/${ticket.id}/action`, { body: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'cancel' } })).status,
    422, 'A reason is required');
  const cancelled = await ask('POST', `/admin/dining/tickets/${ticket.id}/action`, { body: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'cancel', reason: 'العميل مشي' } });
  assert.deepEqual([cancelled.json.ticket.status, cancelled.json.ticket.cancel_reason], ['cancelled', 'العميل مشي']);
  assert.equal((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables[1].status, 'free');
  // Without a server that knows these operations, an order of the dashboard can only be shown offline.
  const order = (await ask('GET', '/admin/dining/tickets/1')).json.ticket;
  const bill = await ask('POST', '/admin/dining/tickets/1/action', { body: { branch: 'f:100', expected_revision: order.revision, idempotency_key: key(), action: 'request_bill' } });
  assert.equal(bill.status, 422);
});

// ---- the delivery page (الدليفري) answered from the device while offline ----
const PHONE = { board: DASHBOARD[11].json, show: DASHBOARD[12].json, quote: DASHBOARD[13].json, dispatch: DASHBOARD[14].json, bill: DASHBOARD[15].json,
  settle: DASHBOARD[17].json, finished: DASHBOARD[18].json };

/** The delivery page's checks on a write's answer (phone-orders.js validateWriteResult / applyWrite). */
function deliveryPageAccepts(result, operation) {
  const ticket = result.ticket;
  if (operation.type === 'dispatch_company') {
    assert.ok(result.success && result.operation && result.operation.idempotency_key === operation.payload.idempotency_key && result.operation.kind === 'phone.dispatch');
    assert.ok(result.branch === operation.payload.branch && Number(result.ticket_id) === Number(operation.ticketId) && Number(result.revision) > operation.payload.expected_revision);
    assert.ok(result.company && Number(result.company.id) === operation.payload.company_id);
    return;
  }
  assert.ok(ticket && Number(ticket.id) === Number(operation.ticketId) && ticket.channel === 'phone'
    && ['new', 'preparing', 'out_for_delivery', 'finished', 'cancelled'].includes(ticket.status) && ['unpaid', 'paid'].includes(ticket.payment_status)
    && ticket.branch.value === operation.payload.branch && Number(ticket.revision) > 0 && Array.isArray(ticket.items) && ticket.items.length);
  assert.ok(Number(ticket.revision) > Number(operation.payload.expected_revision));
  assert.ok(result.operation.idempotency_key === operation.payload.idempotency_key && Number(result.operation.ticket_id) === Number(ticket.id));
  if (operation.type === 'action' && operation.payload.action === 'request_bill') assert.ok(ticket.bill_locked);
  if (operation.type !== 'settle') return;
  assert.equal(ticket.payment_status, 'paid');
  const url = result.first_print_url || result.receipt_url;
  const path = url && new URL(url).pathname.match(/\/takeaway\/receipts\/([1-9][0-9]*)\/print\/?$/);
  assert.ok(result.receipt && (!url || (path && Number(path[1]) === Number(result.receipt.id))));
  assert.ok(result.receipt.branch.value === operation.payload.branch && result.receipt.idempotency_key === operation.payload.idempotency_key);
  assert.equal(scaled(result.receipt.total, 2), scaled(operation.total, 2));
}

function deliveryPage() {
  const cashier = cashierPage();
  const { store } = cashier;
  store.set('server_kinds', ['sale', 'ticket_settle', 'ticket_open', 'ticket_action', 'ticket_dispatch', 'ticket_batch']);
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h2',
    catalog: { ...MENU, delivery_companies: [{ id: 1, name: 'شركة سريع', phone: '01000000001' }, { id: 7, name: 'شركة سريع', phone: '01011112222' }] } }]);
  store.applyService([{ ...serviceCopy(), tickets: [DASHBOARD[3].json.ticket, { ...PHONE.show.ticket, courier_company: null }] }]);
  const page = new ServiceOffline({ store, origin: ORIGIN, now: () => store.now(), kick: () => {}, channel: 'phone' });
  const ask = async (method, path, { user = ACCOUNT, body = null } = {}) => {
    const url = new URL(ORIGIN + path);
    const route = page.match(method, url.pathname);
    assert.ok(route, `${method} ${path} is answered on the device`);
    return page.answer(route, { user, url, body });
  };
  let keys = 0;
  return { ...cashier, ask, key: () => `00000000-0000-4000-9000-${String(++keys).padStart(12, '0')}` };
}

test('the delivery board offline: hand an order to a courier, print its bill and collect it, as the dashboard does', async () => {
  const { store, ask, key } = deliveryPage();
  const id = PHONE.show.ticket.id;
  const board = (await ask('GET', '/admin/phone-orders/board?branch=f:100&preparing_page=1&courier_page=1&finished_page=1')).json;
  assert.deepEqual(shape(board), shape(PHONE.board));
  assert.deepEqual(board.columns.preparing.items.map((item) => item.id), [id]);
  assert.deepEqual(shape(board.columns.preparing.items[0]), shape(PHONE.board.columns.preparing.items[0]));
  assert.deepEqual(board.columns.preparing.pagination, { page: 1, last_page: 1, total: 1 });
  assert.deepEqual(board.companies, PHONE.finished.companies, 'The branch\'s delivery companies');
  assert.equal(board.columns.courier.items.length + board.columns.finished.items.length, 0);
  const shown = (await ask('GET', `/admin/phone-orders/tickets/${id}?branch=f:100`)).json;
  assert.deepEqual(shape(shown.ticket), shape(PHONE.show.ticket), 'The order exactly as the dashboard shows it');
  assert.equal((await ask('POST', '/admin/phone-orders/quote', { body: { branch: 'f:100', items: [{ product_id: 7, quantity: '1', quantity_mode: 'piece' }] } })).status, 422,
    'A new delivery order needs the map and the internet');

  const handoff = { type: 'dispatch_company', ticketId: id, payload: { branch: 'f:100', ticket_id: id, company_id: 7, expected_revision: 1, idempotency_key: key() } };
  const dispatched = await ask('POST', '/admin/phone-orders/dispatch-company', { body: handoff.payload });
  assert.equal(dispatched.status, 200, JSON.stringify(dispatched.json));
  assert.deepEqual(shape(dispatched.json), shape(PHONE.dispatch));
  deliveryPageAccepts(dispatched.json, handoff);
  assert.deepEqual(dispatched.json.company, PHONE.dispatch.company);
  assert.equal((await ask('POST', '/admin/phone-orders/dispatch-company', { body: handoff.payload })).json.replayed, true);
  const operation = store.pendingOperations()[0];
  assert.deepEqual([operation.kind, operation.data], ['ticket_dispatch', { ticket_id: id, company_id: 7 }]);
  const courier = (await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns.courier.items;
  assert.deepEqual([courier.length, courier[0].status, courier[0].courier_company.name, courier[0].revision], [1, 'out_for_delivery', 'شركة سريع', 2]);

  const bill = { type: 'action', ticketId: id, payload: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'request_bill', reason: '' } };
  const billed = await ask('POST', `/admin/phone-orders/tickets/${id}/action`, { body: bill.payload });
  assert.equal(billed.status, 200, JSON.stringify(billed.json));
  assert.deepEqual(shape(billed.json), shape(PHONE.bill));
  deliveryPageAccepts(billed.json, bill);
  assert.equal(billed.json.ticket.status, 'out_for_delivery', 'A delivery order keeps its stage when its bill is printed');
  const printed = await ask('GET', `/admin/phone-orders/tickets/${id}/print`);
  assert.match(printed.html, /كشف حساب غير مسدد/);
  assert.match(printed.html, /منى/);

  const saved = (await ask('POST', '/admin/phone-orders/quote', { body: { branch: 'f:100', ticket_id: id } })).json;
  assert.deepEqual(shape(saved), shape(PHONE.quote));
  assert.deepEqual([saved.quote_hash, saved.total, saved.revision], [PHONE.quote.quote_hash, '75.00', 3]);
  const collect = { type: 'settle', ticketId: id, total: saved.total, payload: { branch: 'f:100', expected_revision: 3, idempotency_key: key(),
    quote_hash: saved.quote_hash, payment_method: 'cash', cash_received: '100.00', payment_confirmed: true, payment_reference: '' } };
  assert.equal((await ask('POST', `/admin/phone-orders/tickets/${id}/settle`, { body: { ...collect.payload, payment_confirmed: false } })).status, 422);
  const paid = await ask('POST', `/admin/phone-orders/tickets/${id}/settle`, { body: collect.payload });
  assert.equal(paid.status, 200, JSON.stringify(paid.json));
  assert.deepEqual(shape(paid.json), shape(PHONE.settle));
  deliveryPageAccepts(paid.json, collect);
  assert.deepEqual([paid.json.ticket.status, paid.json.receipt.change, paid.json.receipt.channel], ['finished', '25.00', 'phone']);
  assert.deepEqual(paid.json.receipt.context, { ...PHONE.settle.receipt.context }, 'The receipt shows the delivery details like the dashboard\'s');
  const settle = store.pendingOperations().find((item) => item.kind === 'ticket_settle');
  assert.deepEqual(settle.data, { channel: 'phone', ticket_id: id, expected_revision: 1, quote_hash: saved.quote_hash, cash_received: '100.00',
    customer_phone: '+201112223334', service_consent: false });
  const finished = (await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns;
  assert.deepEqual([finished.courier.items.length, finished.finished.items.map((item) => item.id)], [0, [id]]);
  assert.ok(finished.finished.items[0].completed_at);
  assert.equal(PHONE.finished.columns.finished.items[0].status, finished.finished.items[0].status);
  assert.equal((await ask('POST', `/admin/phone-orders/tickets/${id}/action`, { body: { branch: 'f:100', expected_revision: 4, idempotency_key: key(), action: 'dispatch' } })).status,
    409, 'Closed');
});

test('orders a courier brought back are collected together offline: one batch, its receipt, then the dashboard\'s own batch', async () => {
  const { store, ask, key } = deliveryPage();
  const BATCH = DASHBOARD[21].json;
  const order = (id, name) => ({ ...PHONE.show.ticket, id, customer_name: name, details_url: `${ORIGIN}/admin/phone-orders/tickets/${id}/details`,
    bill_print_url: `${ORIGIN}/admin/phone-orders/tickets/${id}/print`, courier_company: null });
  // One order already with the courier on the dashboard, one handed over on the device.
  store.applyService([{ ...serviceCopy(), hash: 'service-batch', tickets: [{ ...order(41, 'عميل ١'), status: 'out_for_delivery', revision: 2,
    courier_company: PHONE.dispatch.company }, order(42, 'عميل ٢')] }]);
  const handoff = await ask('POST', '/admin/phone-orders/dispatch-company', { body: { branch: 'f:100', ticket_id: 42, company_id: 7, expected_revision: 1, idempotency_key: key() } });
  assert.equal(handoff.status, 200, JSON.stringify(handoff.json));
  const courier = (await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns.courier.items;
  assert.deepEqual(courier.map((item) => [item.id, item.revision, item.courier_company.id]), [[41, 2, 7], [42, 2, 7]]);
  const payload = { branch: 'f:100', idempotency_key: key(), items: courier.map((item) => ({ id: item.id, revision: item.revision, quote_hash: item.quote_hash })),
    courier_name: 'سيد', total: '150.00', payment_method: 'cash', cash_received: '200.00', payment_reference: '', payment_confirmed: true };
  assert.equal((await ask('POST', '/admin/phone-orders/finish-batch', { body: { ...payload, total: '140.00' } })).status, 409, 'A wrong total');
  assert.equal((await ask('POST', '/admin/phone-orders/finish-batch', { body: { ...payload, cash_received: '100.00' } })).status, 422, 'Not enough cash');
  const done = await ask('POST', '/admin/phone-orders/finish-batch', { body: payload });
  assert.equal(done.status, 200, JSON.stringify(done.json));
  assert.deepEqual(shape(done.json), shape(BATCH));
  assert.deepEqual(shape(done.json.batch), shape(BATCH.batch));
  // The page's own checks (phone-orders.js applyWrite).
  const { batch } = done.json;
  const ids = (items, field) => items.map((item) => Number(item[field])).sort((a, b) => a - b).join(',');
  assert.ok(done.json.success && done.json.operation.idempotency_key === payload.idempotency_key && done.json.operation.kind === 'phone.batch');
  assert.ok(batch.branch.value === 'f:100' && batch.idempotency_key === payload.idempotency_key && scaled(batch.total, 2) === scaled(payload.total, 2)
    && ids(batch.items, 'ticket_id') === ids(payload.items, 'id') && Number.isSafeInteger(batch.id)
    && new URL(batch.print_url).pathname.endsWith(`/phone-orders/batches/${batch.id}/print`));
  assert.deepEqual([batch.change, batch.company.name, batch.courier_name], ['50.00', 'شركة سريع', 'سيد']);
  const receipt = await ask('GET', new URL(batch.print_url).pathname);
  assert.equal(receipt.status, 200);
  assert.match(receipt.html, /data-dashboard-receipt="phone-batch"/);
  assert.match(receipt.html, /#41/);
  assert.equal((await ask('POST', '/admin/phone-orders/finish-batch', { body: payload })).json.replayed, true);
  const operations = store.pendingOperations();
  assert.deepEqual(operations.map((operation) => operation.kind), ['ticket_dispatch', 'ticket_batch'], 'One batch, no single payments');
  assert.deepEqual(operations[1].data, { items: [{ id: 41, quote_hash: PHONE.show.ticket.quote_hash }, { id: 42, quote_hash: PHONE.show.ticket.quote_hash }],
    courier_name: 'سيد', total: '150.00', cash_received: '200.00' });
  const finished = (await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns;
  assert.deepEqual([finished.courier.items.length, finished.finished.items.map((item) => item.id)], [0, [42, 41]]);
  assert.equal(store.dayTotals(['f:100'], '2026-10-30').count, 5, 'Both orders count in the day until the dashboard has them');
  // Synced: the dashboard's invoices for both orders.
  store.applyResults([{ id: operations[0].id, status: 'applied', ticket: { id: 42, revision: 2, status: 'out_for_delivery' } },
    { id: operations[1].id, status: 'applied', batch: { id: 9, total: '150.00', items: [{ ticket_id: 41, order_id: 301, total: '75.00' }, { ticket_id: 42, order_id: 302, total: '75.00' }] } }]);
  assert.deepEqual(store.db.prepare("SELECT server_id, sync_status FROM tickets WHERE json_extract(data, '$.batch_key') = ? ORDER BY server_id").all(payload.idempotency_key)
    .map((row) => [row.server_id, row.sync_status]), [[301, 'synced'], [302, 'synced']]);
  assert.equal(store.serviceOverlay(41), null);
});

test('items added offline to a table opened on the dashboard: shown, billed and paid on the device, then sent to it in order', async () => {
  const { store, ask, key } = diningPage({ kinds: ['sale', 'ticket_settle', 'ticket_open', 'ticket_action', 'ticket_save'] });
  const order = (await ask('GET', '/admin/dining/tickets/1')).json.ticket;
  const base = order.cart.items.map((item) => ({ product_id: item.product_id, option_id: item.option_id, quantity_mode: item.quantity_mode, quantity: item.quantity }));
  // Only the guests change: the order keeps its prices (the dashboard's quote).
  const guests = { kind: 'save', id: 1, expectedTotal: scaled(order.total, 2), payload: { branch: 'f:100', idempotency_key: key(), ticket_id: 1, expected_revision: 2, table_id: 1,
    items: base, discount: '0.00', discount_reason: '', quote_hash: order.quote_hash, waiter_name: order.waiter_name, guest_count: 3, customer_name: order.customer_name,
    customer_phone: null, service_consent: false, marketing_consent: false, notes: order.notes } };
  const kept = await ask('POST', '/admin/dining/tickets/save', { body: guests.payload });
  assert.equal(kept.status, 200, JSON.stringify(kept.json));
  pageAccepts(kept.json, guests);
  assert.deepEqual([kept.json.ticket.guest_count, kept.json.ticket.total, kept.json.ticket.quote_hash], [3, order.total, order.quote_hash]);
  // One more سلطة طحينة and half a kilo of cleaned بوري (as the page sends them: one line per item): priced with the device's menu.
  const more = [...base.map((item) => (item.product_id === 7 ? { ...item, quantity: '3' } : item)),
    { product_id: 1, option_id: 'f:0:extra_clean', quantity: '0.500', quantity_mode: 'weight' }];
  const quote = (await ask('POST', '/admin/dining/quote', { body: { branch: 'f:100', items: more, discount: '0.00', discount_reason: '' } })).json;
  const add = { kind: 'save', id: 1, expectedTotal: scaled(quote.total, 2), payload: { ...guests.payload, idempotency_key: key(), expected_revision: 3, items: more,
    quote_hash: quote.quote_hash, reprice: true } };
  const added = await ask('POST', '/admin/dining/tickets/save', { body: add.payload });
  assert.equal(added.status, 200, JSON.stringify(added.json));
  assert.deepEqual(shape(added.json), KEYS.save);
  assert.deepEqual(shape(added.json.ticket), shape(DASHBOARD[3].json.ticket));
  pageAccepts(added.json, add);
  assert.deepEqual([added.json.ticket.revision, added.json.ticket.total, added.json.ticket.kitchen_sent], [4, quote.total, true]);
  assert.equal(added.json.ticket.items.find((line) => line.product_id === 7).quantity, '3.000');
  assert.equal(added.json.ticket.items.find((line) => line.option_id === 'f:0:extra_clean').option_label, 'تنظيف');
  const floor = (await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables[0];
  assert.deepEqual([floor.status, floor.ticket.total], ['in_service', quote.total]);
  const bill = { kind: 'action', id: 1, expectedQuoteHash: quote.quote_hash, payload: { branch: 'f:100', expected_revision: 4, idempotency_key: key(), action: 'request_bill' } };
  const billed = await ask('POST', '/admin/dining/tickets/1/action', { body: bill.payload });
  pageAccepts(billed.json, bill);
  assert.match((await ask('GET', '/admin/dining/tickets/1/print')).html, new RegExp(quote.total.replace('.', '\\.')));
  const pay = { kind: 'settle', id: 1, expectedTotal: scaled(quote.total, 2), payload: { branch: 'f:100', expected_revision: 5, idempotency_key: key(),
    quote_hash: quote.quote_hash, customer_phone: '01012345678', service_consent: true, payment_method: 'cash', cash_received: '500.00', payment_confirmed: true } };
  const paid = await ask('POST', '/admin/dining/tickets/1/settle', { body: pay.payload });
  assert.equal(paid.status, 200, JSON.stringify(paid.json));
  pageAccepts(paid.json, pay);
  // Sent in the order they were done, each checked by the dashboard against the order as the device left it.
  const operations = store.pendingOperations();
  assert.deepEqual(operations.map((operation) => operation.kind), ['ticket_save', 'ticket_save', 'ticket_action', 'ticket_settle']);
  assert.deepEqual(operations[0].data.base, { items: base, discount: '0.00', discount_reason: '' });
  assert.deepEqual(operations[0].data.items, base);
  assert.deepEqual(operations[1].data.base.items, base);
  assert.deepEqual(operations[1].data.items, more.map((item) => ({ product_id: item.product_id, option_id: item.option_id || '', quantity_mode: item.quantity_mode,
    quantity: item.quantity })));
  const expect = { items: operations[1].data.items, discount: '0.00', discount_reason: '', total_cents: Number(scaled(quote.total, 2)) };
  assert.deepEqual(operations[2].data.expect, expect);
  assert.deepEqual([operations[3].data.quote_hash, operations[3].data.expected_revision, operations[3].data.expect], [order.quote_hash, 2, expect]);
  assert.equal(paid.json.receipt.total, quote.total);
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['free', 'free']);
});

test('a dashboard order cancelled offline frees its table or leaves the delivery board, and is cancelled there once', async () => {
  const { store, ask, key } = diningPage({ kinds: ['sale', 'ticket_settle', 'ticket_action', 'ticket_cancel'] });
  // Table 1 seated on the dashboard, not sent to the kitchen yet.
  const open = DASHBOARD[2].json.ticket;
  const copy = serviceCopy();
  store.applyService([{ ...copy, hash: 'service-open', tables: [{ ...copy.tables[0], ticket: { ...copy.tables[0].ticket, status: 'open', kitchen_sent: false } }, copy.tables[1]],
    tickets: [open] }]);
  const cancel = { kind: 'action', id: open.id, expectedQuoteHash: open.quote_hash, payload: { branch: 'f:100', expected_revision: 1, idempotency_key: key(), action: 'cancel',
    reason: 'العميل مشي' } };
  assert.equal((await ask('POST', `/admin/dining/tickets/${open.id}/action`, { body: { ...cancel.payload, reason: '' } })).status, 422, 'A reason is required');
  const cancelled = await ask('POST', `/admin/dining/tickets/${open.id}/action`, { body: cancel.payload });
  assert.equal(cancelled.status, 200, JSON.stringify(cancelled.json));
  pageAccepts(cancelled.json, cancel);
  assert.deepEqual([cancelled.json.ticket.status, cancelled.json.ticket.cancel_reason, cancelled.json.ticket.payment_status], ['cancelled', 'العميل مشي', 'unpaid']);
  assert.deepEqual((await ask('GET', '/admin/dining/tables?branch=f:100')).json.tables.map((table) => table.status), ['free', 'free']);
  const [operation] = store.pendingOperations();
  assert.deepEqual([operation.id, operation.kind, operation.data.action, operation.data.reason, operation.data.ticket_id],
    [cancel.payload.idempotency_key, 'ticket_cancel', 'cancel', 'العميل مشي', open.id]);
  // Sent to the kitchen already: not cancelled offline (the dashboard's rule).
  store.applyService([{ ...serviceCopy(), hash: 'service-kitchen' }]);
  store.applyResults([{ id: operation.id, status: 'applied', ticket: { id: open.id, revision: 2, status: 'cancelled' } }]);
  const sent = await ask('POST', '/admin/dining/tickets/1/action', { body: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'cancel', reason: 'غلط' } });
  assert.equal(sent.status, 409);

  // The delivery board: an order still being prepared is cancelled and moves to the finished column.
  const { store: phoneStore, ask: phoneAsk, key: phoneKey } = deliveryPage();
  phoneStore.set('server_kinds', ['sale', 'ticket_settle', 'ticket_action', 'ticket_dispatch', 'ticket_batch', 'ticket_cancel']);
  const id = PHONE.show.ticket.id;
  const dropped = await phoneAsk('POST', `/admin/phone-orders/tickets/${id}/action`, { body: { branch: 'f:100', expected_revision: 1, idempotency_key: phoneKey(),
    action: 'cancel', reason: 'العميل لغى' } });
  assert.equal(dropped.status, 200, JSON.stringify(dropped.json));
  assert.deepEqual([dropped.json.ticket.status, dropped.json.ticket.payment_status], ['cancelled', 'unpaid'], 'What the delivery page checks');
  const columns = (await phoneAsk('GET', '/admin/phone-orders/board?branch=f:100')).json.columns;
  assert.deepEqual([columns.preparing.items.length, columns.finished.items.map((item) => [item.id, item.status])], [0, [[id, 'cancelled']]]);
  assert.ok(columns.finished.items[0].completed_at);
  // Without a dashboard that knows offline cancellations, it needs the internet.
  const { ask: oldAsk, key: oldKey } = diningPage();
  const old = await oldAsk('POST', '/admin/dining/tickets/1/action', { body: { branch: 'f:100', expected_revision: 2, idempotency_key: oldKey(), action: 'cancel', reason: 'غلط' } });
  assert.equal(old.status, 422);
  assert.match(old.json.message, /اتصالًا بالإنترنت/);
});

// ---- new delivery orders on the delivery page offline ----
const { DeliveryOffline, distance: deliveryDistance, tariff } = require('../src/main/offline/delivery.cjs');

test('the delivery fee offline is the dashboard\'s own calculation (distance, tier, fee), case by case', () => {
  // Rendered by test/delivery-vectors.php from BranchDeliveryTariff.
  const { cases } = require('./fixtures/delivery-vectors.json');
  assert.ok(cases.length >= 30);
  for (const sample of cases) {
    const { settings, point: [latitude, longitude], delivery } = sample;
    const lat = Math.round(latitude * 1e7) / 1e7;
    const lng = Math.round(longitude * 1e7) / 1e7;
    const meters = deliveryDistance(settings.latitude, settings.longitude, lat, lng);
    const priced = tariff(meters, settings.pricing_rates);
    assert.deepEqual([Math.round(meters), (meters / 1000).toFixed(3), priced.delivery_fee, priced.pricing_rule, priced.pricing_tier, priced.pricing_distance_km],
      [delivery.distance_meters, delivery.distance_km, delivery.delivery_fee, delivery.pricing_rule, delivery.pricing_tier, delivery.pricing_distance_km],
      `${sample.name} ${latitude},${longitude}`);
  }
});

/** The delivery page's checks on a new order's answer (phone-orders.js validateWriteResult). */
function newOrderAccepted(result, payload) {
  const ticket = result.ticket;
  assert.ok(ticket && Number(ticket.id) > 0 && ticket.channel === 'phone' && ['new', 'preparing', 'out_for_delivery', 'finished', 'cancelled'].includes(ticket.status)
    && ticket.payment_status === 'unpaid' && ticket.branch.value === payload.branch && Number(ticket.revision) > 0 && ticket.items.length && scaled(ticket.total, 2) !== null);
  assert.ok(result.operation.idempotency_key === payload.idempotency_key && Number(result.operation.ticket_id) === Number(ticket.id));
  assert.equal(ticket.quote_hash, payload.quote_hash);
  assert.ok(!ticket.delivery_location || !ticket.delivery_location.delivery_origin_branch);
  if (payload.send_to_kitchen) assert.ok(ticket.kitchen_sent);
}

function newDeliveryPage() {
  const cashier = cashierPage();
  const { store } = cashier;
  store.set('server_kinds', ['sale', 'ticket_settle', 'ticket_open', 'ticket_action', 'ticket_dispatch', 'ticket_batch', 'customer_save']);
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h2',
    catalog: { ...MENU, delivery_companies: [{ id: 7, name: 'شركة سريع', phone: '01011112222' }] } }]);
  // The branch's pin and tariff: 15 / 20 / 25 by the nearest kilometre, 10 per kilometre beyond.
  store.applyService([{ ...serviceCopy(), hash: 'service-delivery', tickets: [], delivery: { branch: MENU.branch, latitude: 29.9602, longitude: 31.2569,
    km_price: '10.00', pricing_rates: { km_price: '10.00', default_0_1: '15.00', default_1_2: '20.00', default_2_3: '25.00', legacy: false }, ready: true } }]);
  store.applyCustomers({ items: [{ id: 501, branch: 'f:100', name: 'منى', phone: '01112223334', phone_key: '01112223334', address: 'شارع النصر، عمارة ٥',
    area: 'المعادي', delivery_notes: 'الدور التالت', latitude: 29.9702, longitude: 31.2569, revision: 3 }], cursor: '2026-10-30 10:00:00|501', more: false });
  const page = new DeliveryOffline({ store, origin: ORIGIN, now: () => store.now(), kick: () => {} });
  const ask = async (method, path, { user = ACCOUNT, body = null } = {}) => {
    const url = new URL(ORIGIN + path);
    const route = page.match(method, url.pathname);
    assert.ok(route, `${method} ${path} is answered on the device`);
    return page.answer(route, { user, url, body });
  };
  let keys = 0;
  return { ...cashier, page, ask, key: () => `00000000-0000-4000-a000-${String(++keys).padStart(12, '0')}` };
}

test('a new delivery order offline: the customer by number, their location, the delivery fee, the order and its kitchen ticket', async () => {
  const { store, ask, key } = newDeliveryPage();
  const settings = await ask('GET', '/admin/phone-orders/delivery-settings?branch=f:100');
  assert.deepEqual([settings.status, settings.json.settings.ready, settings.json.settings.branch.value], [200, true, 'f:100']);
  const companies = (await ask('GET', '/admin/delivery-companies/data?branch=f:100&active=1')).json.items;
  assert.deepEqual(companies.map((company) => [company.id, company.name]), [[7, 'شركة سريع']]);
  // A returning customer, typed any common way.
  for (const typed of ['01112223334', '+201112223334', '1112223334']) {
    const found = (await ask('GET', `/admin/phone-orders/customers?branch=f:100&phone=${encodeURIComponent(typed)}&prefix=0`)).json;
    assert.deepEqual(found.items.map((item) => [item.customer_id, item.customer_revision, item.name, item.latitude]), [[501, 3, 'منى', 29.9702]], typed);
    assert.equal(found.items, found.matches);
  }
  assert.equal((await ask('GET', '/admin/phone-orders/customers?branch=f:100&phone=0111&prefix=1')).json.items.length, 1, 'While typing');
  const saved = (await ask('POST', '/admin/phone-orders/address-suggestions', { body: { branch: 'f:100', query: 'النصر' } })).json;
  assert.deepEqual([saved.provider, saved.items.map((item) => [item.label, item.latitude, item.longitude])], ['saved', [['شارع النصر، عمارة ٥، المعادي', 29.9702, 31.2569]]]);
  // Her location (about 1.1 km): the 1–2 km tier, 20.00.
  const location = (await ask('POST', '/admin/phone-orders/delivery-quote', { body: { branch: 'f:100', latitude: '29.9702', longitude: '31.2569', location_confirmed: true } })).json.delivery;
  assert.deepEqual([location.delivery_fee, location.pricing_rule, location.pricing_tier, location.distance_km, location.method], ['20.00', 'app_branch_tier', 'default_1_2', '1.113', 'app_direct']);
  assert.match(location.delivery_quote_hash, /^[a-f0-9]{64}$/);
  assert.equal((await ask('POST', '/admin/phone-orders/delivery-quote', { body: { branch: 'f:100', latitude: '0', longitude: '0', location_confirmed: true } })).status, 422);
  // The customer step saves her (sent to the dashboard as customer_save).
  const customer = await ask('POST', '/admin/customers/save', { body: { branch: 'f:100', name: 'منى', phone: '01112223334', address: 'شارع النصر، عمارة ٥', area: 'المعادي',
    delivery_notes: 'الدور التالت', latitude: location.latitude, longitude: location.longitude, idempotency_key: key(), customer_id: 501, expected_revision: 3 } });
  assert.equal(customer.status, 200, JSON.stringify(customer.json));
  assert.deepEqual([customer.json.customer.id, customer.json.customer.revision, customer.json.customer.branch], [501, 4, 'f:100']);
  // The order: 2 سلطة طحينة (50.00) + 20.00 delivery, tax 14% of 70.00 = 9.80 → 79.80.
  const items = [{ product_id: 7, quantity: '2', quantity_mode: 'piece' }];
  const draft = { branch: 'f:100', items, discount: '0.00', discount_reason: '', delivery_fee_manual: false, delivery_fee: location.delivery_fee,
    latitude: location.latitude, longitude: location.longitude, location_confirmed: true, delivery_quote_hash: location.delivery_quote_hash, customer_id: 501,
    delivery_company_id: 7 };
  const quote = (await ask('POST', '/admin/phone-orders/quote', { body: draft })).json;
  assert.deepEqual([quote.subtotal, quote.delivery, quote.tax, quote.total], ['50.00', '20.00', '9.80', '79.80']);
  assert.equal((await ask('POST', '/admin/phone-orders/quote', { body: { ...draft, delivery_quote_hash: 'a'.repeat(64) } })).status, 409, 'A location changed since');
  const manual = (await ask('POST', '/admin/phone-orders/quote', { body: { ...draft, delivery_fee_manual: true, delivery_fee: '30.00' } })).json;
  assert.equal(manual.delivery, '30.00', 'A fee set by the cashier');
  const order = { ...draft, send_to_kitchen: true, idempotency_key: key(), quote_hash: quote.quote_hash, customer_name: 'منى', customer_phone: '01112223334',
    address: 'شارع النصر، عمارة ٥', area: 'المعادي', delivery_notes: 'الدور التالت', notes: 'حار', service_consent: true, marketing_consent: false };
  assert.equal((await ask('POST', '/admin/phone-orders/tickets/save', { body: { ...order, service_consent: false, idempotency_key: key() } })).status, 422);
  const placed = await ask('POST', '/admin/phone-orders/tickets/save', { body: order });
  assert.equal(placed.status, 200, JSON.stringify(placed.json));
  newOrderAccepted(placed.json, order);
  const ticket = placed.json.ticket;
  assert.ok(ticket.id > LOCAL_TICKET_BASE);
  assert.deepEqual([ticket.status, ticket.number, ticket.delivery_fee, ticket.total, ticket.address, ticket.delivery_company.name, placed.json.print_queued],
    ['new', 'PH-A1B-1', '20.00', '79.80', 'شارع النصر، عمارة ٥', 'شركة سريع', true]);
  assert.deepEqual(shape(ticket), shape({ ...PHONE.show.ticket, number: '' }), 'The delivery page\'s own order format');
  assert.equal((await ask('POST', '/admin/phone-orders/tickets/save', { body: order })).json.replayed, true);
  // The branch printer prints its kitchen ticket from the device.
  const jobs = (await ask('GET', '/admin/phone-orders/print-jobs?branch=f:100')).json;
  assert.deepEqual(jobs.jobs.map((job) => job.ticket_id), [ticket.id]);
  const token = '11111111-2222-4333-8444-555555555555';
  const claim = (await ask('POST', '/admin/phone-orders/print-jobs/claim', { body: { branch: 'f:100', job_id: jobs.jobs[0].id, claim_token: token } })).json;
  assert.equal(claim.print_url, `${ORIGIN}/admin/phone-orders/kitchen/${jobs.jobs[0].id}/print`);
  assert.equal((await ask('POST', '/admin/phone-orders/print-jobs/claim', { body: { branch: 'f:100', job_id: jobs.jobs[0].id, claim_token: token } })).status, 409, 'Once');
  const slip = await ask('GET', new URL(claim.print_url).pathname);
  assert.match(slip.html, /data-dashboard-receipt="pos-kitchen"/);
  assert.match(slip.html, /شارع النصر/);
  assert.equal((await ask('POST', '/admin/phone-orders/print-jobs/complete', { body: { branch: 'f:100', job_id: jobs.jobs[0].id, claim_token: token, result: 'invoked' } })).status, 200);
  assert.deepEqual((await ask('GET', '/admin/phone-orders/print-jobs?branch=f:100')).json.jobs, []);
  // On the board, then with the courier, then collected: one invoice for the dashboard.
  const board = (await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns;
  assert.deepEqual(board.preparing.items.map((item) => [item.id, item.courier_company.name]), [[ticket.id, 'شركة سريع']]);
  const handoff = await ask('POST', '/admin/phone-orders/dispatch-company', { body: { branch: 'f:100', ticket_id: ticket.id, company_id: 7, expected_revision: 1, idempotency_key: key() } });
  assert.equal(handoff.status, 200, JSON.stringify(handoff.json));
  assert.equal((await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns.courier.items[0].id, ticket.id);
  const saved_quote = (await ask('POST', '/admin/phone-orders/quote', { body: { branch: 'f:100', ticket_id: ticket.id } })).json;
  const collect = { type: 'settle', ticketId: ticket.id, total: saved_quote.total, payload: { branch: 'f:100', expected_revision: 2, idempotency_key: key(),
    quote_hash: saved_quote.quote_hash, payment_method: 'cash', cash_received: '100.00', payment_confirmed: true, payment_reference: '' } };
  const paid = await ask('POST', `/admin/phone-orders/tickets/${ticket.id}/settle`, { body: collect.payload });
  assert.equal(paid.status, 200, JSON.stringify(paid.json));
  deliveryPageAccepts(paid.json, collect);
  assert.deepEqual([paid.json.ticket.status, paid.json.receipt.change, paid.json.receipt.context.address], ['finished', '20.20', 'شارع النصر، عمارة ٥']);
  const operations = store.pendingOperations();
  assert.deepEqual(operations.map((operation) => operation.kind), ['customer_save', 'sale']);
  const sale = operations[1].data;
  assert.deepEqual([sale.channel, sale.total_cents, sale.delivery_cents, sale.address, sale.delivery_company_id, sale.customer_phone],
    ['phone', 7980, 2000, 'شارع النصر، عمارة ٥', 7, '+201112223334']);
  assert.deepEqual((await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns.finished.items.map((item) => item.id), [ticket.id]);
});

test('a delivery order placed offline and still out when the internet is back moves to the dashboard with what was done', async () => {
  const { store, ask, key } = newDeliveryPage();
  const location = (await ask('POST', '/admin/phone-orders/delivery-quote', { body: { branch: 'f:100', latitude: 29.9702, longitude: 31.2569, location_confirmed: true } })).json.delivery;
  const draft = { branch: 'f:100', items: [{ product_id: 7, quantity: '1', quantity_mode: 'piece' }], discount: '0.00', discount_reason: '', delivery_fee_manual: true,
    delivery_fee: '18.00', latitude: location.latitude, longitude: location.longitude, location_confirmed: true, delivery_quote_hash: location.delivery_quote_hash,
    customer_id: null, delivery_company_id: null };
  const quote = (await ask('POST', '/admin/phone-orders/quote', { body: draft })).json;
  const order = { ...draft, send_to_kitchen: true, idempotency_key: key(), quote_hash: quote.quote_hash, customer_name: 'عميل جديد', customer_phone: '01099998888',
    address: 'شارع ٩', area: '', delivery_notes: '', notes: '', service_consent: true, marketing_consent: true };
  const placed = (await ask('POST', '/admin/phone-orders/tickets/save', { body: order })).json.ticket;
  await ask('POST', '/admin/phone-orders/dispatch-company', { body: { branch: 'f:100', ticket_id: placed.id, company_id: 7, expected_revision: 1, idempotency_key: key() } });
  await ask('POST', `/admin/phone-orders/tickets/${placed.id}/action`, { body: { branch: 'f:100', expected_revision: 2, idempotency_key: key(), action: 'request_bill', reason: '' } });
  assert.equal(store.queueTicketMoves(), 1);
  const move = store.pendingOperations().find((operation) => operation.kind === 'ticket_open');
  assert.deepEqual(move.data, { channel: 'phone', items: [{ product_id: 7, option_id: '', quantity_mode: 'piece', quantity: '1' }], discount: '0.00', discount_reason: '',
    customer_name: 'عميل جديد', customer_phone: '+201099998888', address: 'شارع ٩', area: '', delivery_notes: '', notes: '', latitude: 29.9702, longitude: 31.2569,
    delivery_fee: '18.00', delivery_company_id: null, marketing_consent: true, kitchen_sent: true, kitchen_printed: false, courier_company_id: 7, bill_requested: true });
  store.applyResults([{ id: move.id, status: 'applied', ticket: { id: 88, revision: 4, status: 'out_for_delivery', number: '' } }]);
  const moved = await ask('GET', `/admin/phone-orders/tickets/${placed.id}`);
  assert.deepEqual([moved.status, moved.location], [302, `${ORIGIN}/admin/phone-orders/tickets/88`]);
  assert.deepEqual((await ask('GET', '/admin/phone-orders/board?branch=f:100')).json.columns.courier.items, [], 'It shows from the dashboard\'s copy now');
});
