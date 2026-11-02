'use strict';
// The dashboard's own pages offline: receipts identical to the dashboard's, and the cashier page's answers.
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { renderReceipt, renderKitchen, renderBatch, ticketBill } = require('../src/main/offline/receipt.cjs');
const { renderShiftReceipt } = require('../src/main/offline/shifts.cjs');

const normalize = (html) => html.replace(/\s+/g, ' ').replace(/\s*([<>])\s*/g, '$1').trim();

test('offline receipts, bills and kitchen tickets are the dashboard print pages, case by case', () => {
  // Rendered by test/receipt-fixtures.php from the dashboard's own views (takeaway/receipt, pos_service/ticket and kitchen).
  const { cases } = require(path.join(__dirname, 'fixtures', 'receipts.json'));
  assert.ok(cases.length >= 13);
  const origin = 'https://fasakhaninja.com';
  for (const sample of cases) {
    const html = sample.kind === 'kitchen' ? renderKitchen(sample.kitchen, { origin, dashboardPrint: sample.dashboard_print })
      : sample.kind === 'batch' ? renderBatch(sample.batch, { origin })
      : sample.kind === 'shift' ? renderShiftReceipt(sample.report, { preview: sample.preview })
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
  // A branch this device was not set up for: said plainly (the page shows "no permission" for 403).
  const elsewhere = await ask('GET', '/admin/takeaway/catalog?branch=f:101');
  assert.equal(elsewhere.status, 422, 'Another branch');
  assert.match(elsewhere.json.message, /مش متجهز على الجهاز ده.*الجهاز متجهز لـ: فرع المعادي/);
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
  const review = { branch: 'f:100', expected_revision: 1, action: 'approve', reason: '', idempotency_key: '8b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f' };
  assert.equal((await ask('POST', `/admin/branch-expenses/${expense.id}/review`, { body: review })).status, 403, 'A cashier cannot approve');
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

test('expenses are approved, rejected or cancelled offline with the dashboard\'s rules; the list, the badge and the shift page follow', async () => {
  const { store, cache, ask, tick } = expensesPage();
  const OWNER = { ...ACCOUNT, id: 1 };
  store.set('server_kinds', ['sale', 'expense', 'till', 'shift_close', 'expense_review']);
  const listUrl = '/admin/branch-expenses/data?branch=f:100&from=2026-10-01&to=2026-10-30&page=1';
  const row = (id, extra) => ({ id, number: `EXP-0000${id}`, branch: 'f:100', amount: '30.00', status: 'pending', occurred_on: '2026-10-30', revision: 1,
    payment_method: 'cash', category: 'electricity', description: 'مصروف', actor_id: 4, ...extra });
  const server = { success: true, items: [row(41, { amount: '100.00', status: 'approved', occurred_on: '2026-10-29', revision: 2 }), row(42), row(43, { amount: '15.00', payment_method: 'bank' })],
    filters: { branch: 'f:100', from: '2026-10-01', to: '2026-10-30' }, actors: [], pagination: { page: 1, last_page: 1, total: 3 },
    summary: { today: '0.00', month: '100.00', period: '100.00', average: '3.33', count: 1, pending: 2, top_category: 'gas', top_amount: '100.00' } };
  tick(-60000);
  cache.put('u:1', 'data', ORIGIN + listUrl, 'application/json', Buffer.from(JSON.stringify(server)));
  cache.put('u:1', 'data', ORIGIN + '/admin/branch-expenses/pending-count', 'application/json', Buffer.from(JSON.stringify({ success: true, count: 2 })));
  tick(60000);
  let count = 0;
  const key = () => `50000000-0000-4000-8000-${String(++count).padStart(12, '0')}`;
  const review = (id, action, extra = {}) => ask('POST', `/admin/branch-expenses/${id}/review`, { user: OWNER,
    body: { branch: 'f:100', expected_revision: 1, action, reason: '', idempotency_key: key(), ...extra } });

  const approved = await review(42, 'approve');
  assert.equal(approved.status, 200, JSON.stringify(approved.json));
  assert.deepEqual([approved.json.expense.status, approved.json.expense.revision, approved.json.expense.reviewer_name], ['approved', 2, 'المالك']);
  const [operation] = store.pendingOperations(50, ['expense_review']);
  assert.deepEqual([operation.kind, operation.data], ['expense_review', { action: 'approve', reason: '', expense_id: 42, expected_revision: 1 }]);
  const again = await ask('POST', '/admin/branch-expenses/42/review', { user: OWNER, body: { branch: 'f:100', expected_revision: 1, action: 'approve', reason: '', idempotency_key: operation.id } });
  assert.deepEqual([again.json.replayed, store.pendingOperations(50, ['expense_review']).length], [true, 1], 'Never twice');
  // The dashboard's rules: cash only, a reason to reject or cancel, the state and revision the page showed.
  assert.equal((await review(43, 'approve')).json.message, 'لا يمكن اعتماد مصروف خارج خزنة الدرج.');
  assert.equal((await review(43, 'reject')).json.message, 'اكتب سبب الرفض أو الإلغاء.');
  assert.equal((await review(43, 'reject', { reason: 'مكرر' })).json.expense.status, 'rejected');
  assert.equal((await review(42, 'approve')).json.message, 'حالة المصروف تغيرت؛ حدّث الصفحة.');
  assert.equal((await review(41, 'void', { reason: 'خطأ', expected_revision: 2 })).json.expense.status, 'voided');
  assert.equal((await review(99, 'approve')).json.message, 'المصروف ده مش محفوظ على الجهاز. اعتماده أو رفضه محتاج إنترنت.');
  assert.equal((await ask('POST', '/admin/branch-expenses/42/review', { body: { branch: 'f:100', expected_revision: 2, action: 'void', reason: 'x', idempotency_key: key() } })).status, 403);

  // The list, its totals and the badge show them at once; so do the expense's details.
  const list = (await ask('GET', listUrl, { user: OWNER })).json;
  assert.deepEqual(list.items.map((item) => [item.id, item.status]), [[41, 'voided'], [42, 'approved'], [43, 'rejected']]);
  assert.deepEqual([list.summary.pending, list.summary.period, list.summary.count, list.summary.today, list.summary.month], [0, '30.00', 1, '30.00', '30.00']);
  assert.deepEqual((await ask('GET', listUrl + '&status=pending', { user: OWNER })).json.items, []);
  assert.equal((await ask('GET', '/admin/branch-expenses/pending-count', { user: OWNER })).json.count, 0);
  assert.equal((await ask('GET', '/admin/branch-expenses/42', { user: OWNER })).json.expense.status, 'approved');

  // An expense saved on this device is reviewed before it is even sent: the review follows its save.
  const saved = (await ask('POST', '/admin/branch-expenses/save', { body: expenseForm({ amount: '20.00' }) })).json.expense;
  const local = await ask('POST', `/admin/branch-expenses/${saved.id}/review`, { user: OWNER, body: { branch: 'f:100', expected_revision: 1, action: 'approve', reason: '', idempotency_key: key() } });
  assert.deepEqual([local.status, local.json.expense.status, local.json.expense.id], [200, 'approved', saved.id]);
  const queued = store.pendingOperations(50, ['expense', 'expense_review']);
  assert.deepEqual(queued.map((item) => item.kind), ['expense_review', 'expense_review', 'expense_review', 'expense', 'expense_review']);
  assert.deepEqual(queued[4].data, { action: 'approve', reason: '', expense_key: expenseForm().idempotency_key });
  assert.equal((await ask('GET', '/admin/branch-expenses/pending-count', { user: OWNER })).json.count, 0);

  // The shift page: what was approved or rejected here no longer holds the closing back; the drawer follows.
  const shifts = new ShiftsOffline({ store, cache, origin: ORIGIN, now: () => store.now() });
  store.applyService([{ value: 'f:100', hash: 's1', tables: [], policy: {}, tickets: [], delivery: null, shift: { previous_closing_id: 0, started_at: '2026-10-30 06:00:00',
    history: [], pending_expenses: [{ id: 42, number: 'EXP-000042' }, { id: 43, number: 'EXP-000043' }, { id: 44, number: 'EXP-000044' }] } }]);
  const owner = store.user(1);
  const state = shifts.state({ ...owner, can_close_shift: true }, 'f:100', new URL(ORIGIN + '/admin/branch-shifts/data?branch=f:100'));
  assert.deepEqual([state.report.pending_expenses, state.report.pending_expense_items.map((item) => item.number)], [1, ['EXP-000044']]);
  assert.deepEqual(store.shiftRecords('f:100', '2026-10-30T00:00:00.000Z', null).expenses.map((item) => item.amount_cents), [3000, -10000, 2000]);
  // Once at the dashboard, its number and state show on the device's record.
  store.applyResults([{ id: operation.id, status: 'applied', expense: { id: 42, number: 'EXP-000042', status: 'approved' } }]);
  assert.equal(store.journalEntry(operation.id).server_ref, 'EXP-000042 · معتمد');
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
  // A leave or absence needs a server that takes them from devices (see the next test).
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

test('the employees page records deductions, bonuses and advances on the device and shows them on the saved list', async () => {
  let clock = Date.parse('2026-10-30T09:00:00Z'); // 11:00 in Cairo
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  const OWNER = { ...ACCOUNT, id: 1, name: 'المالك', can_manage: true };
  store.replaceUsers([ACCOUNT, OWNER], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', attendance: [], catalog: { ...MENU,
    employees: [{ id: 50, name: 'سعيد', schedule_type: 'branch', shift: 'صباحي' }, { id: 51, name: 'كريم', schedule_type: 'branch', shift: 'صباحي' }],
    attendance_shifts: [{ shift: 'morning', starts_at: '10:00', ends_at: '18:00' }] } }]);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const page = new EmployeesOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock) });
  const ask = async (method, path, body = null, user = ACCOUNT) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user, url, body });
  };
  let count = 0;
  const key = () => `${String(++count).padStart(8, '0')}-2d2a-4c3b-9e8f-0a1b2c3d4e5f`;
  const form = (employee, kind, amount, reason, extra = {}) => ({ branch: 'f:100', employee_id: employee, day: '2026-10-30', kind, amount, reason,
    notes: '', idempotency_key: key(), ...extra });
  const add = (body, user = ACCOUNT) => ask('POST', '/admin/employees/entry', body, user);
  // The list seen online at 11:00: a deduction of 20.00 already on the dashboard.
  const listUrl = '/admin/employees/data?branch=f:100&day=2026-10-30&month=2026-10&page=1';
  const zero = { count: 0, amount: '0.00' };
  const saveList = (saeed, karim, summary) => cache.put('u:4', 'data', ORIGIN + listUrl, 'application/json', Buffer.from(JSON.stringify({ success: true,
    filters: { branch: 'f:100', day: '2026-10-30', month: '2026-10' }, summary,
    items: [{ id: 50, branch: 'f:100', name: 'سعيد', attendance: null, ...saeed }, { id: 51, branch: 'f:100', name: 'كريم', attendance: null, ...karim }] })));
  const month = (values) => ({ month: '2026-10', status: 'draft', bonus: '0.00', deduction: '0.00', advance: '0.00', ...values });
  saveList({ daily: { bonus: zero, deduction: { count: 1, amount: '20.00' }, advance: zero }, statement: month({ deduction: '20.00', net: '980.00', net_cents: 98000 }) },
    { daily: { bonus: zero, deduction: zero, advance: zero }, statement: month({ net: '1000.00', net_cents: 100000 }) },
    { deduction: '20.00', bonus: '0.00', advance: '0.00', net: '1980.00' });
  clock += 60000; // 11:01

  // Until the server takes them from devices, the page says what is needed.
  const early = await add(form(50, 'advance', '150', 'سلفة'));
  assert.equal(early.status, 422);
  assert.match(early.json.message, /تحديث السرفر/);
  store.set('server_kinds', ['employee_entry']);

  // An advance, a deduction, and the owner's bonus, with the dashboard's checks.
  const advanceForm = form(50, 'advance', '150', 'سلفة شخصية', { notes: 'سيتم الخصم من الراتب الشهري' });
  const advance = await add(advanceForm);
  assert.equal(advance.status, 200, JSON.stringify(advance.json));
  assert.deepEqual([advance.json.success, advance.json.entry.kind, advance.json.entry.amount, advance.json.entry.reason, advance.json.replayed],
    [true, 'advance', '150.00', 'سلفة شخصية', false]);
  assert.equal((await add(form(51, 'deduction', '25.50', 'تأخير'))).status, 200);
  assert.equal((await add(form(51, 'bonus', '100', 'مكافأة أداء'))).json.message, 'إضافة المكافآت متاحة للأونر فقط.');
  assert.equal((await add(form(51, 'bonus', '100', 'مكافأة أداء'), OWNER)).status, 200);
  assert.equal((await add(form(50, 'deduction', '0', 'تأخير'))).json.message, 'المبلغ خارج الحدود المسموحة.');
  assert.equal((await add(form(50, 'deduction', '10.555', 'تأخير'))).json.message, 'أدخل مبلغًا صحيحًا بمنزلتين عشريتين.');
  assert.equal((await add(form(50, 'deduction', '10', '  '))).json.message, 'اكتب سبب الحركة.');
  assert.equal((await add(form(50, 'deduction', '10', 'تأخير', { day: '2026-10-31' }))).json.message, 'لا يمكن التسجيل ليوم تشغيل مستقبلي.');
  assert.equal((await add(form(50, 'gift', '10', 'تأخير'))).json.message, 'نوع الحركة غير صالح.');
  assert.equal((await add(form(99, 'deduction', '10', 'تأخير'))).status, 422, 'Not an employee of this branch');

  // Each goes to the dashboard once, under the page's operation number.
  const operations = store.pendingOperations();
  assert.deepEqual(operations.map((op) => [op.id === advanceForm.idempotency_key, op.kind, op.actor_id, op.data.employee_id, op.data.kind, op.data.amount,
    op.data.reason, op.data.notes]), [[true, 'employee_entry', 4, 50, 'advance', '150', 'سلفة شخصية', 'سيتم الخصم من الراتب الشهري'],
    [false, 'employee_entry', 4, 51, 'deduction', '25.50', 'تأخير', ''], [false, 'employee_entry', 1, 51, 'bonus', '100', 'مكافأة أداء', '']]);
  assert.equal((await add(advanceForm)).json.replayed, true);
  assert.equal(store.pendingOperations().length, 3, 'Never twice');
  assert.equal((await ask('GET', `/admin/branch-operations/recover?branch=f:100&idempotency_key=${advanceForm.idempotency_key}`)).json.found, true);
  assert.deepEqual(store.cashSummary('f:100', '2026-10-30').drawer_movement_cents, 0, 'An advance is not a drawer movement, as on the dashboard');

  // The saved list with them: on the day, in the month's pay, and in the totals.
  const list = (await ask('GET', listUrl)).json;
  const [saeed, karim] = list.items;
  assert.deepEqual([saeed.daily.advance, saeed.daily.deduction], [{ count: 1, amount: '150.00' }, { count: 1, amount: '20.00' }]);
  assert.deepEqual([saeed.statement.advance, saeed.statement.net, saeed.statement.net_cents], ['150.00', '830.00', 83000]);
  assert.deepEqual([karim.daily.deduction, karim.daily.bonus], [{ count: 1, amount: '25.50' }, { count: 1, amount: '100.00' }]);
  assert.deepEqual([karim.statement.deduction, karim.statement.bonus, karim.statement.net], ['25.50', '100.00', '1074.50']);
  assert.deepEqual(list.summary, { deduction: '45.50', bonus: '100.00', advance: '150.00', net: '1904.50' });

  // The day's details: the dashboard's own in one line, and those recorded here.
  const deductions = (await ask('GET', '/admin/employees/entries?branch=f:100&employee_id=50&day=2026-10-30&kind=deduction')).json;
  assert.deepEqual([deductions.total, deductions.items.map((item) => [item.reason, item.amount])], ['20.00', [['مسجلة على الداشبورد', '20.00']]]);
  const advances = (await ask('GET', '/admin/employees/entries?branch=f:100&employee_id=50&day=2026-10-30&kind=advance')).json;
  assert.deepEqual([advances.total, advances.items.map((item) => [item.time, item.reason, item.amount, item.notes])],
    ['150.00', [['11:01', 'سلفة شخصية', '150.00', 'سيتم الخصم من الراتب الشهري']]]);

  // Sent after that list was saved: still added. A list saved after the dashboard has it already includes it.
  clock += 60000;
  store.applyResults([{ id: operations[0].id, status: 'applied', entry: { id: 7, employee_id: 50, day: '2026-10-30', kind: 'advance', amount_cents: 15000 } },
    { id: operations[1].id, status: 'rejected', error: 'تم إقفال هذا الشهر. لا يمكن تغيير حركاته.' }]);
  let again = (await ask('GET', listUrl)).json;
  assert.deepEqual(again.items[0].daily.advance, { count: 1, amount: '150.00' });
  assert.deepEqual(again.items[1].daily.deduction, zero, 'One the dashboard refused is not on it');
  clock += 60000;
  saveList({ daily: { bonus: zero, deduction: { count: 1, amount: '20.00' }, advance: { count: 1, amount: '150.00' } },
    statement: month({ deduction: '20.00', advance: '150.00', net: '830.00', net_cents: 83000 }) },
  { daily: { bonus: zero, deduction: zero, advance: zero }, statement: month({ net: '1000.00', net_cents: 100000, status: 'closed' }) },
  { deduction: '20.00', bonus: '0.00', advance: '150.00', net: '1830.00' });
  again = (await ask('GET', listUrl)).json;
  assert.deepEqual([again.items[0].daily.advance, again.items[0].statement.net], [{ count: 1, amount: '150.00' }, '830.00'], 'Not twice');
  // That list shows كريم's month closed: the dashboard refuses any change to it.
  assert.equal((await add(form(51, 'deduction', '5', 'تأخير'))).json.message, 'تم إقفال هذا الشهر. لا يمكن تغيير حركاته.');
});

test('the employees page records the day\'s leave or absence on the device, with the dashboard\'s rules', async () => {
  const clock = Date.parse('2026-10-30T09:00:00Z'); // 11:00 in Cairo
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  store.replaceUsers([ACCOUNT], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1',
    // مها checked in on another device this morning.
    attendance: [{ employee_id: 53, day: '2026-10-30', status: 'morning', checked_in_at: '2026-10-30T07:55:00+00:00', checked_out_at: null }],
    catalog: { ...MENU, employees: [
      { id: 50, name: 'سعيد', schedule_type: 'branch', shift: 'صباحي', has_salary: true },
      { id: 51, name: 'كريم', schedule_type: 'branch', shift: 'مسائي', has_salary: false },
      { id: 52, name: 'هاني', schedule_type: 'part_time', shift: '', attendance_shift: null, attendance_start: null, attendance_end: null, has_salary: true },
      { id: 53, name: 'مها', schedule_type: 'branch', shift: 'صباحي', has_salary: true },
      { id: 54, name: 'نادر', schedule_type: 'branch', shift: 'صباحي', has_salary: false }],
    attendance_shifts: [{ shift: 'morning', starts_at: '10:00', ends_at: '18:00' }] } }]);
  const page = new EmployeesOffline({ store, cache: new PageCache(':memory:', { now: () => new Date(clock) }), origin: ORIGIN, now: () => new Date(clock) });
  const ask = async (method, path, body = null) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user: ACCOUNT, url, body });
  };
  let count = 0;
  const key = () => `${String(++count).padStart(8, '0')}-1d2a-4c3b-9e8f-0a1b2c3d4e5f`;
  const send = (employee, status, action, extra = {}) => ask('POST', '/admin/employees/attendance', { branch: 'f:100', employee_id: employee,
    day: '2026-10-30', status, action, notes: '', expected_revision: null, idempotency_key: key(), ...extra });
  const KINDS = ['attendance', 'attendance_status'];

  // Until the server takes leave and absence from devices, the page says the device needs the internet once.
  const early = await send(50, 'preapproved_leave', 'set_status');
  assert.equal(early.status, 422);
  assert.match(early.json.message, /مرة واحدة/);
  store.set('server_kinds', KINDS);

  const leave = await send(50, 'preapproved_leave', 'set_status', { notes: 'إجازة متفق عليها' });
  assert.equal(leave.status, 200, JSON.stringify(leave.json));
  assert.deepEqual([leave.json.attendance.status, leave.json.attendance.check_in, leave.json.attendance.checked_in_at, leave.json.attendance.notes],
    ['preapproved_leave', null, null, 'إجازة متفق عليها']);
  const [operation] = store.pendingOperations(50, KINDS);
  assert.deepEqual([operation.kind, operation.data.action, operation.data.status, operation.data.day, operation.data.notes],
    ['attendance_status', 'set_status', 'preapproved_leave', '2026-10-30', 'إجازة متفق عليها']);
  assert.equal(store.pendingOperations(50, ['attendance']).length, 0, 'An older server never receives it');

  // The dashboard's rules: the day's first status stays; a leave is valued from the salary, an absence from the shift's
  // rule; a part-time employee needs their hours.
  assert.equal((await send(50, 'unauthorized_absence', 'set_status')).status, 409);
  assert.equal((await send(50, 'morning', 'check_in')).json.message, 'الموظف عليه إجازة مسجلة اليوم.');
  assert.equal((await send(53, 'unauthorized_absence', 'set_status')).status, 409, 'Checked in on another device');
  assert.equal((await send(54, 'morning', 'set_status')).json.message, 'اختر حالة الإجازة أو الغياب.');
  assert.equal((await send(54, 'preapproved_leave', 'set_status')).json.message, 'سجّل راتب الموظف لحساب قيمة يوم الإجازة.');
  assert.equal((await send(51, 'unauthorized_absence', 'set_status')).json.message, 'يجب أن يحدد الأونر مواعيد وخصومات هذا الفرع أولًا.', 'No evening rule here');
  assert.equal((await send(52, 'unauthorized_absence', 'set_status')).json.message, 'أكمل مواعيد الدوام الجزئي في بيانات الموظف.');
  assert.equal((await send(50, 'preapproved_leave', 'set_status', { day: '2026-10-29' })).status, 422, 'Only today offline');

  const absent = await send(54, 'unauthorized_absence', 'set_status');
  assert.equal(absent.status, 200, JSON.stringify(absent.json));
  assert.equal((await send(54, 'morning', 'check_in')).json.message, 'تم تثبيت غياب نادر اليوم؛ لا يمكن تغييره بعد التسجيل.');
  assert.equal(store.pendingOperations(50, KINDS).length, 2);

  // The dashboard's answers: the leave recorded; the absence refused (another device recorded a check-in first).
  const absence = store.pendingOperations(50, KINDS)[1];
  store.applyResults([{ id: operation.id, status: 'applied', attendance: { employee_id: 50, day: '2026-10-30', status: 'preapproved_leave', checked_in_at: null, checked_out_at: null } },
    { id: absence.id, status: 'rejected', error: 'تم تثبيت حالة الحضور لهذا اليوم؛ لا يمكن تغييرها بعد التسجيل.' }]);
  assert.equal(store.journalEntry(operation.id).sync_status, 'synced');
  const board = store.attendanceBoard('f:100', '2026-10-30');
  assert.deepEqual([board.find((item) => item.employee.id === 50).status, board.find((item) => item.employee.id === 54).status],
    ['preapproved_leave', 'unrecorded']);
  assert.match(board.find((item) => item.employee.id === 54).error, /تم تثبيت/);
  assert.deepEqual(store.rejectedOperations().map((item) => item.kind), ['غياب أو إجازة']);
});

// ---- the shift page (تقفيل الوردية): an owner or admin closes the shift on the device while offline ----
const { ShiftsOffline, LOCAL_SHIFT_BASE } = require('../src/main/offline/shifts.cjs');

test('offline, choosing another branch, a search or the new day still lists the employees, from the saved list and the sync', async () => {
  let clock = Date.parse('2026-10-30T09:00:00Z'); // 11:00 in Cairo, operating day 2026-10-30
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' },
    { value: 'f:200', kind: 'f', name: 'الدقي', address: '', phone: '' }]);
  const manager = { ...ACCOUNT, branches: ['f:100', 'f:200'] };
  store.replaceUsers([manager], 'h');
  const staff = (employees) => ({ ...MENU, employees, attendance_shifts: [{ shift: 'morning', starts_at: '10:00', ends_at: '18:00' }] });
  store.applyBranches([
    { value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', attendance: [],
      catalog: staff([{ id: 50, name: 'سعيد', job_title: 'كاشير', schedule_type: 'branch', shift: 'صباحي', has_salary: true }]) },
    // كريم checked in on another device this morning; نادر joined after the list below was saved.
    { value: 'f:200', available: true, snapshot_id: 'snap-2', hash: 'h2',
      attendance: [{ employee_id: 61, day: '2026-10-30', status: 'morning', checked_in_at: '2026-10-30T07:55:00+00:00', checked_out_at: null }],
      catalog: staff([{ id: 61, name: 'كريم', job_title: 'شيف', schedule_type: 'branch', shift: 'صباحي', has_salary: true },
        { id: 62, name: 'نادر', job_title: 'كاشير', schedule_type: 'branch', shift: 'مسائي', attendance_shift: 'evening', has_salary: false }]) }]);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const page = new EmployeesOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock) });
  const ask = async (method, path, body = null) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user: manager, url, body });
  };
  const row = (id, name, branch, extra = {}) => ({ id, name, branch, job_title: 'شيف', shift: 'صباحي', schedule_type: 'branch', active: true, phone: '01000000000',
    identity: { front: true, back: true }, regulation: { accepted: true, current: true }, attendance: null, day_closed: false,
    daily: { bonus: { count: 0, amount: '0.00' }, deduction: { count: 0, amount: '0.00' }, advance: { count: 0, amount: '0.00' } },
    statement: { month: '2026-10', net: '4200.00', net_cents: 420000, status: 'draft', eligible_days: 30, accrued_from: '2026-10-01', accrued_through: '2026-10-29' }, ...extra });
  // Seen online this morning: «all branches», page 1 (سعيد and كريم; a deduction for كريم today).
  const morning = { success: true, operating_day: { date: '2026-10-30', start_hour: 6 }, can_manage_attendance: true, branches: [], options: { job_title: ['شيف', 'كاشير'], shift: ['صباحي', 'مسائي'] },
    filters: { branch: 'all', day: '2026-10-30', month: '2026-10', page: 1 }, pagination: { page: 1, last_page: 1, total: 2 },
    items: [row(50, 'سعيد', 'f:100', { job_title: 'كاشير' }),
      row(61, 'كريم', 'f:200', { daily: { bonus: { count: 0, amount: '0.00' }, deduction: { count: 1, amount: '50.00' }, advance: { count: 0, amount: '0.00' } } })],
    summary: { employees: 2, present: 1, absent: 0, leave: 0, deduction: '50.00', bonus: '0.00', advance: '0.00', net: '8400.00' } };
  cache.put('u:4', 'data', ORIGIN + '/admin/employees/data?page=1&branch=all&day=2026-10-30&month=2026-10&search=&job_title=&shift=', 'application/json',
    Buffer.from(JSON.stringify(morning)));
  const list = async (query) => {
    const answer = await ask('GET', '/admin/employees/data?page=1&' + query);
    assert.equal(answer.status, 200, JSON.stringify(answer.json));
    return answer.json;
  };

  // The branch chosen for attendance: its employees from the saved list, the one the sync added, today's attendance.
  const dokki = await list('branch=f:200&day=2026-10-30&month=2026-10&search=&job_title=&shift=');
  assert.deepEqual(dokki.items.map((item) => [item.id, item.name, item.branch]), [[61, 'كريم', 'f:200'], [62, 'نادر', 'f:200']]);
  assert.deepEqual([dokki.items[0].attendance.status, dokki.items[0].attendance.check_in], ['morning', '09:55:00'], 'كريم checked in on another device');
  assert.equal(dokki.items[0].statement.net, '4200.00', 'The saved pay stays');
  assert.equal(dokki.items[0].daily.deduction.amount, '50.00');
  assert.deepEqual([dokki.items[1].attendance, dokki.items[1].statement.status, dokki.items[1].statement.net, dokki.items[1].job_title, dokki.items[1].phone],
    [null, 'draft', '0.00', 'كاشير', null], 'نادر, known from the sync only');
  assert.deepEqual(dokki.filters, { branch: 'f:200', day: '2026-10-30', month: '2026-10', search: null, job_title: null, shift: null, page: 1 });
  assert.deepEqual(dokki.pagination, { page: 1, last_page: 1, total: 2 });
  assert.deepEqual(dokki.summary, { employees: 2, present: 1, absent: 0, leave: 0, deduction: '50.00', bonus: '0.00', advance: '0.00', net: '4200.00' });
  assert.equal(dokki.can_manage_attendance, true, 'The page\'s own permissions stay');

  // Attendance taken from it on this device shows on it.
  const punch = await ask('POST', '/admin/employees/attendance', { branch: 'f:200', employee_id: 62, day: '2026-10-30', status: 'evening', action: 'check_in',
    notes: '', expected_revision: null, idempotency_key: '0a8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f' });
  assert.equal(punch.status, 200, JSON.stringify(punch.json));
  const after = await list('branch=f:200&day=2026-10-30&month=2026-10&search=&job_title=&shift=');
  assert.deepEqual([after.items[1].attendance.status, after.items[1].attendance.check_in, after.items[1].attendance.revision], ['evening', '11:00:00', 1]);
  assert.equal(after.summary.present, 2);

  // The page's search and its job and shift filters.
  assert.deepEqual((await list('branch=f:200&day=2026-10-30&month=2026-10&search=نا&job_title=&shift=')).items.map((item) => item.id), [62]);
  assert.deepEqual((await list('branch=all&day=2026-10-30&month=2026-10&search=&job_title=كاشير&shift=')).items.map((item) => item.id), [50, 62], 'By name, as the dashboard orders them');
  // «All branches» was saved as it was: the saved copy, with the device's punch on it.
  const all = await list('branch=all&day=2026-10-30&month=2026-10&search=&job_title=&shift=');
  assert.deepEqual(all.items.map((item) => item.id), [50, 61]);

  // A deduction recorded here for كريم today counts once, on top of the one the saved list has.
  store.set('server_kinds', ['employee_entry']);
  const entry = await ask('POST', '/admin/employees/entry', { branch: 'f:200', employee_id: 61, day: '2026-10-30', kind: 'deduction', amount: '25.00',
    reason: 'تأخير', notes: '', idempotency_key: '0b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f' });
  assert.equal(entry.status, 200, JSON.stringify(entry.json));
  const deducted = await list('branch=f:200&day=2026-10-30&month=2026-10&search=&job_title=&shift=');
  assert.deepEqual([deducted.items[0].daily.deduction, deducted.items[0].statement.net, deducted.summary.deduction], [{ count: 2, amount: '75.00' }, '4175.00', '75.00']);

  // The next operating day, still offline: the page moves to it and the day starts empty; the month's pay stays.
  clock = Date.parse('2026-10-31T05:00:00Z'); // 07:00 in Cairo, operating day 2026-10-31
  const moved = await list('branch=all&day=2026-10-30&month=2026-10&search=&job_title=&shift=');
  assert.equal(moved.operating_day.date, '2026-10-31', 'The page rolls over to the new day, as with the dashboard');
  const next = await list('branch=f:200&day=2026-10-31&month=2026-10&search=&job_title=&shift=');
  assert.deepEqual(next.items.map((item) => [item.id, item.attendance, item.daily.deduction.count]), [[61, null, 0], [62, null, 0]]);
  assert.equal(next.items[0].statement.net, '4175.00', 'The month keeps the saved pay and this device\'s deduction');
  assert.deepEqual([next.summary.present, next.summary.deduction], [0, '0.00']);
  // A day before the saved list, or another month's pay, needs the internet.
  assert.equal((await ask('GET', '/admin/employees/data?page=1&branch=f:200&day=2026-10-29&month=2026-10')).status, 503);
  assert.equal((await ask('GET', '/admin/employees/data?page=1&branch=f:200&day=2026-10-31&month=2026-09')).status, 503);
  // A branch this account has no access to on this device stays refused.
  assert.equal((await ask('GET', '/admin/employees/data?page=1&branch=f:300&day=2026-10-31&month=2026-10')).status, 503);
});

test('the shift page closes the shift on the device once nothing is open, and the closing goes alone, in its place', async () => {
  const dining = diningPage({ kinds: ['sale', 'expense', 'till', 'ticket_settle', 'ticket_open', 'ticket_action', 'shift_close'] });
  const { store } = dining;
  const OWNER = { ...ACCOUNT, id: 1, name: 'المالك', can_manage: true, can_close_shift: true, can_approve_expenses: true };
  store.replaceUsers([ACCOUNT, OWNER], 'h-shift');
  const copy = serviceCopy();
  const shift = { previous_closing_id: 5, started_at: '2026-10-30 06:00:00', history: [{ id: 5, number: 'SHIFT-000005', sequence: 5, branch: 'f:100',
    started_at: '2026-10-29 06:00:00', closed_at: '2026-10-30 06:00:00', print_url: ORIGIN + '/admin/branch-shifts/5/print' }] };
  store.applyService([{ ...copy, hash: 'service-shift', shift }]);
  const phone = new ServiceOffline({ store, origin: ORIGIN, now: () => store.now(), kick: () => {}, channel: 'phone' });
  const page = new ShiftsOffline({ store, cache: new PageCache(':memory:', { now: () => store.now() }), origin: ORIGIN, now: () => store.now(),
    services: [dining.page, phone] });
  const ask = async (method, path, body = null, user = OWNER) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user, url, body });
  };
  const address = '/admin/branch-shifts/data?branch=f:100';
  let count = 0;
  const key = () => `30000000-0000-4000-8000-${String(++count).padStart(12, '0')}`;
  const close = (extra = {}) => ask('POST', '/admin/branch-shifts/close', { branch: 'f:100', previous_closing_id: 5, review_token: 'x'.repeat(64),
    counted_cash: '1250.50', notes: 'قفلة الليل', idempotency_key: key(), ...extra });

  // Owners and admins only, as on the dashboard.
  assert.equal((await ask('GET', address, null, ACCOUNT)).json.message, 'تقفيل الوردية متاح للأونر والأدمن فقط.');
  // The shift as last synced, with this device's open orders: the dashboard's order on table 1.
  const state = (await ask('GET', address)).json;
  assert.deepEqual([state.previous_closing_id, state.report.started_at, state.history.map((closing) => closing.number)],
    [5, '2026-10-30 06:00:00', ['SHIFT-000005']]);
  assert.deepEqual([state.report.pending_invoices.total, state.report.pending_invoices.counts, state.report.pending_invoices.items[0].label],
    [1, { dine: 1, phone: 0, app: 0 }, 'الصالة']);
  assert.equal((await close()).json.message, 'برجاء إغلاق الفواتير أولًا.');
  // The order was paid; an expense on the dashboard still waits for review, then one saved here without approval.
  store.applyService([{ ...copy, hash: 'service-shift-2', tickets: [], shift: { ...shift, pending_expenses: [{ id: 3, number: 'EXP-000003',
    occurred_on: '2026-10-30', description: 'كهرباء', category: 'electricity' }] } }]);
  const waiting = (await ask('GET', address)).json.report;
  assert.deepEqual([waiting.pending_expenses, waiting.pending_expense_items[0].number], [1, 'EXP-000003']);
  assert.equal((await close()).json.message, 'لا يمكن إغلاق الوردية قبل تسوية المصروفات المعلقة باعتمادها أو رفضها.');
  store.applyService([{ ...copy, hash: 'service-shift-3', tickets: [], shift: { ...shift, pending_expenses: [] } }]);
  const owner = store.user(1);
  const expense = store.addJournal(store.user(4), 'f:100', 'expense', 5000, { category: 'gas', description: 'غاز', amount: '50.00', approve: false }, {}, null);
  assert.equal((await close()).json.message, 'لا يمكن إغلاق الوردية قبل تسوية المصروفات المعلقة باعتمادها أو رفضها.');
  store.applyResults([{ id: expense.id, status: 'rejected', error: 'مرفوض' }]);
  assert.equal((await close({ previous_closing_id: 4 })).json.message, 'تم إغلاق وردية من جهاز آخر. حدّث الصفحة وراجع الفترة الجديدة.');
  assert.equal((await close({ counted_cash: '12,5' })).json.message, 'أدخل الكاش الفعلي بقيمة صحيحة.');

  // Cash put in before the closing, the closing, cash taken out after it.
  const before = store.addJournal(owner, 'f:100', 'till', 10000, { direction: 'in', reason: 'فكة' }, {}, null);
  const closing = await close();
  assert.equal(closing.status, 200, JSON.stringify(closing.json));
  const local = closing.json.closing;
  assert.ok(local.id > LOCAL_SHIFT_BASE);
  assert.deepEqual([local.number, local.started_at, local.print_url], [`جهاز-${local.id - LOCAL_SHIFT_BASE}`, '2026-10-30 06:00:00',
    `${ORIGIN}/admin/branch-shifts/${local.id}/print`]);
  const after = store.addJournal(owner, 'f:100', 'till', 2000, { direction: 'out', reason: 'مشتريات' }, {}, null);
  assert.equal((await close()).json.message, 'فيه قفلة وردية للفرع ده محفوظة على الجهاز ولسه ما وصلتش للداشبورد. القفلة الجاية بعد ما توصل.');
  // The page's lists show it; its answer can be checked again; its report prints once the dashboard has it.
  assert.deepEqual((await ask('GET', address)).json.history.map((item) => item.number), [local.number, 'SHIFT-000005']);
  const recovered = (await ask('GET', `/admin/branch-shifts/recover?branch=f:100&idempotency_key=${closing.json.closing && store.journalBySeq(local.id - LOCAL_SHIFT_BASE).id}`)).json;
  assert.deepEqual([recovered.found, recovered.closing.id], [true, local.id]);
  // Its closing invoice opens in the dashboard's preview (without the shift's synced figures: this device's records only).
  const details = await ask('GET', `/admin/branch-shifts/${local.id}/details`);
  assert.match(details.html, /data-dashboard-invoice-details="1"/);
  assert.match(details.html, /1250\.50/);
  assert.match(details.html, /قفلة الليل/);
  assert.match(details.html, /<td>إيداعات \/ سحوبات أخرى<\/td><td><bdi>100\.00<\/bdi>/);
  assert.match(details.html, /<span>النقدية<\/span><strong><bdi>—<\/bdi> ج/, 'The expected cash is the dashboard\'s');
  assert.match(details.html, /تقرير الجهاز: عمليات الجهاز ده في الوردية بس/);
  assert.equal((await ask('POST', '/admin/branch-shifts/reprint', { closing_id: local.id, idempotency_key: key() })).status, 409, 'Without a printer here');
  assert.equal(await ask('POST', '/admin/branch-shifts/reprint', { closing_id: 5, idempotency_key: key() }), null, 'The dashboard prints its own closings');

  // Sent in order: what was recorded before it, then the closing alone, then what came after it.
  const kinds = ['sale', 'expense', 'till', 'shift_close'];
  const sent = () => store.pendingOperations(50, kinds).map((operation) => operation.kind + ':' + operation.id);
  const closeOperation = store.pendingOperations(50, ['shift_close'])[0];
  assert.deepEqual(closeOperation.data, { previous_closing_id: 5, counted_cash: '1250.50', notes: 'قفلة الليل' });
  assert.deepEqual(sent(), [`till:${before.id}`]);
  store.applyResults([{ id: before.id, status: 'applied' }]);
  assert.deepEqual(sent(), [`shift_close:${closeOperation.id}`]);
  store.applyResults([{ id: closeOperation.id, status: 'applied', closing: { id: 6, number: 'SHIFT-000006' } }]);
  assert.deepEqual(sent(), [`till:${after.id}`]);
  assert.equal(store.journalEntry(closeOperation.id).server_ref, 'SHIFT-000006');
  assert.deepEqual(store.rejectedOperations().map((item) => item.kind), ['مصروف']);
});

test('a shift closed offline shows and prints the dashboard\'s closing invoice: the last synced figures and this device\'s records after them', async () => {
  let clock = Date.parse('2026-10-30T09:00:00Z'); // 11:00 in Cairo
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  const OWNER = { ...ACCOUNT, id: 1, name: 'المالك', can_manage: true, can_close_shift: true, can_approve_expenses: true };
  store.replaceUsers([OWNER], 'h');
  store.set('server_kinds', ['sale', 'expense', 'till', 'shift_close']);
  store.applyService([{ value: 'f:100', hash: 's1', tables: [], policy: {}, tickets: [], delivery: null, shift: { previous_closing_id: 5,
    started_at: '2026-10-30 06:00:00', pending_expenses: [], history: [{ id: 5, number: 'SHIFT-000005', sequence: 5, branch: 'f:100',
      started_at: '2026-10-29 06:00:00', closed_at: '2026-10-30 06:00:00', print_url: ORIGIN + '/admin/branch-shifts/5/print' }] } }]);
  let seq = 0;
  const paid = (channel, total, delivery, at, status = 'pending', syncedAt = null) => store.db.prepare(`INSERT INTO tickets (id, branch, channel, status,
    actor_id, actor_name, data, local_number, business_date, snapshot_id, totals, created_at, updated_at, paid_at, sync_status, synced_at, seq)
    VALUES (?, 'f:100', ?, 'paid', 1, 'المالك', '{}', ?, '2026-10-30', 'snap', ?, ?, ?, ?, ?, ?, ?)`)
    .run(`t-${++seq}`, channel, `A1B-1030-${seq}`, JSON.stringify({ total_cents: total, delivery_cents: delivery }), at, at, at, status, syncedAt, 1000 + seq);
  paid('takeaway', 9999, 0, '2026-10-30T03:00:00.000Z'); // 05:00 in Cairo: the shift before
  paid('takeaway', 15000, 0, '2026-10-30T08:00:00.000Z', 'synced', '2026-10-30T08:01:00.000Z'); // in the synced figures
  // The dashboard's figures of the open shift, received at 11:00 Cairo.
  store.applyRunning([{ value: 'f:100', hash: 'r1', report: { previous_closing_id: 5, started_at: '2026-10-30 06:00:00',
    channels: { dine: { count: 2, gross: '400.00', delivery: '0.00', net: '400.00' }, takeaway: { count: 3, gross: '450.00', delivery: '0.00', net: '450.00' },
      phone: { count: 1, gross: '230.00', delivery: '30.00', net: '200.00' }, app: { count: 1, gross: '120.00', delivery: '20.00', net: '100.00' } },
    sales_total: '1200.00', delivery_total: '50.00', expenses_total: '100.00', net_sales: '1050.00', opening_cash: '500.00', cash_sales: '900.00',
    noncash_sales: '180.00', app_branch_cash: '0.00', app_outside_drawer: '100.00', cash_delivery: '30.00', cash_expenses: '100.00',
    other_cash_movements: '0.00', expected_cash: '1270.00', unsettled_app_cash: 0 } }]);
  assert.deepEqual(store.runningHashes(), { 'f:100': 'r1' });
  store.applyRunning([{ value: 'f:100', hash: 'r1' }]);
  assert.equal(store.running('f:100').received_at, '2026-10-30T09:00:00.000Z', 'Unchanged: the copy and its time stay');
  // After them, on this device: a sale not sent yet, a delivery order sent after the figures, an approved expense, cash
  // taken out; a rejected sale and an expense waiting for review count nowhere.
  clock = Date.parse('2026-10-30T09:30:00Z');
  paid('takeaway', 8000, 0, '2026-10-30T09:30:00.000Z');
  paid('phone', 13000, 3000, '2026-10-30T09:35:00.000Z', 'synced', '2026-10-30T09:40:00.000Z');
  paid('takeaway', 99900, 0, '2026-10-30T09:36:00.000Z', 'rejected');
  const owner = store.user(1);
  store.addJournal(owner, 'f:100', 'expense', 2500, { category: 'gas', description: 'غاز', amount: '25.00', approve: true }, {}, null);
  store.addJournal(owner, 'f:100', 'till', 5000, { direction: 'out', amount: '50.00', note: 'فكة للبنك' }, {}, null);
  const waiting = store.addJournal(owner, 'f:100', 'expense', 4000, { category: 'gas', description: 'غاز', amount: '40.00', approve: false }, {}, null);
  store.applyResults([{ id: waiting.id, status: 'rejected', error: 'مرفوض' }]);

  const printed = [];
  const page = new ShiftsOffline({ store, cache: new PageCache(':memory:', { now: () => new Date(clock) }), origin: ORIGIN, now: () => new Date(clock),
    print: async (html) => { printed.push(html); return { ok: true, error: null }; } });
  const ask = async (method, path, body = null) => {
    const url = new URL(ORIGIN + path);
    return page.answer(page.match(method, url.pathname), { user: OWNER, url, body });
  };
  clock = Date.parse('2026-10-30T12:00:00Z'); // closed at 14:00 Cairo
  const closing = await ask('POST', '/admin/branch-shifts/close', { branch: 'f:100', previous_closing_id: 5, review_token: 'x'.repeat(64),
    counted_cash: '1350.00', notes: '', idempotency_key: '40000000-0000-4000-8000-000000000001' });
  assert.equal(closing.status, 200, JSON.stringify(closing.json));
  const id = closing.json.closing.id;
  const entry = store.journalBySeq(id - LOCAL_SHIFT_BASE);
  const report = entry.data.report;
  assert.deepEqual(report.channels, { dine: { count: 2, gross: '400.00', delivery: '0.00', net: '400.00' },
    takeaway: { count: 4, gross: '530.00', delivery: '0.00', net: '530.00' }, phone: { count: 2, gross: '360.00', delivery: '60.00', net: '300.00' },
    app: { count: 1, gross: '120.00', delivery: '20.00', net: '100.00' } });
  assert.deepEqual([report.sales_total, report.delivery_total, report.expenses_total, report.net_sales], ['1410.00', '80.00', '125.00', '1205.00']);
  // BranchShiftClosing: expected = opening + drawer movement + the app's cash at the branch - delivery paid in cash.
  assert.deepEqual([report.opening_cash, report.cash_sales, report.cash_delivery, report.cash_expenses, report.other_cash_movements, report.expected_cash],
    ['500.00', '1110.00', '60.00', '125.00', '-50.00', '1375.00']);
  assert.deepEqual([report.counted_cash, report.variance, report.variance_label, report.sequence, report.cashier.name, report.complete],
    ['1350.00', '-25.00', 'عجز', 6, 'المالك', true]);
  assert.deepEqual(store.pendingOperations(50, ['shift_close'])[0].data, { previous_closing_id: 5, counted_cash: '1350.00', notes: '' },
    'The report stays on the device; the dashboard makes its own');

  const details = await ask('GET', `/admin/branch-shifts/${id}/details`);
  assert.match(details.html, /^<!doctype html><html lang="ar" dir="rtl"\n data-dashboard-invoice-details="1"\n>/);
  for (const shown of ['وردية 6', 'الكاشير: المالك', '<bdi>2026-10-30 06:00:00</bdi>', '<bdi>2026-10-30 14:00:00</bdi>', '<td>الدليفري</td><td>2</td><td><bdi>360.00</bdi>',
    '<span>النقدية</span><strong><bdi>1375.00</bdi> ج', '<span>الفرق — عجز</span><strong><bdi>-25.00</bdi> ج',
    'تقرير الجهاز: أرقام الوردية لحد آخر مزامنة مع الداشبورد (2026-10-30 11:00:00)']) assert.ok(details.html.includes(shown), shown);
  const print = await ask('GET', `/admin/branch-shifts/${id}/print`);
  assert.match(print.html, /data-dashboard-receipt="branch-shift"/);
  assert.match(print.html, /<meta name="dashboard-print-document" content="receipt">/);
  // Printed on this device's printer from the page's print button, while it waits for the dashboard.
  const reprint = await ask('POST', '/admin/branch-shifts/reprint', { closing_id: id, idempotency_key: '40000000-0000-4000-8000-000000000002' });
  assert.deepEqual([reprint.status, reprint.json.printed], [200, true]);
  assert.equal(printed.length, 1);
  assert.match(printed[0], /data-dashboard-receipt="branch-shift"/);
  // Once at the dashboard: the invoice says where its final report is.
  store.applyResults([{ id: entry.id, status: 'applied', closing: { id: 6, number: 'SHIFT-000006' } }]);
  assert.match((await ask('GET', `/admin/branch-shifts/${id}/details`)).html, /وصلت للداشبورد \(SHIFT-000006\)، وتقريرها النهائي هناك\./);

  // Figures of another shift (closed elsewhere since): this device's records of the shift alone, the drawer left to the dashboard.
  const alone = page.closingReport(owner, 'f:100', { previous_closing_id: 4, report: { started_at: '2026-10-30 06:00:00' }, history: [] }, 100000, '');
  assert.deepEqual([alone.complete, alone.sales_total, alone.cash_sales, alone.expected_cash, alone.opening_cash, alone.variance, alone.sequence],
    [false, '360.00', '360.00', null, null, null, null]);
});

// ---- the stock page (إضافة بضاعة): goods received on the device while offline ----
const { StockOffline } = require('../src/main/offline/stock.cjs');

test('the stock page records goods received on the device, shows them on the balances and recovers them', async () => {
  let clock = Date.parse('2026-10-30T07:15:00Z'); // 09:15 in Cairo
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock) });
  store.saveDevice({ id: 'device-1', code: 'A1B', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'المعادي', address: '', phone: '' }]);
  store.replaceUsers([ACCOUNT], 'h');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', attendance: [], catalog: MENU }]);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  const page = new StockOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock) });
  const employees = new EmployeesOffline({ store, cache, origin: ORIGIN, now: () => new Date(clock), recoverers: [(entry, user) => page.recovered(entry, user)] });
  const ask = async (method, path, body = null, handler = page) => {
    const url = new URL(ORIGIN + path);
    return handler.answer(handler.match(method, url.pathname), { user: ACCOUNT, url, body });
  };
  // The branch's list as the page last read it online (BranchInventory::listing).
  const onion = { id: 16, name: 'بصل', unit: 'kg', unit_label: 'كجم' };
  const bread = { id: 20, name: 'خبز بلدي', unit: 'piece', unit_label: 'قطعة' };
  const listing = (onionQuantity) => ({ success: true, branch: { value: 'f:100', kind: 'f', id: 100, name: 'المعادي' },
    items: [{ ...onion, stock: { ingredient_id: 16, unit: 'kg', unit_label: 'كجم', quantity: onionQuantity, negative: false, revision: 4, minimum: '5' } },
      { ...bread, stock: { ingredient_id: 20, unit: 'piece', unit_label: 'قطعة', quantity: '0', negative: false, revision: 0 } }],
    ingredients: [onion, bread], can_manage_recipes: false, unconfigured_count: 0, unmapped_sales: [], legacy: [],
    pagination: { page: 1, last_page: 1, total: 2 },
    history: [{ id: 9, name: 'بصل', unit_label: 'كجم', quantity: '3.25', balance: '3.25', source_type: 'receipt', source_id: '4:x', actor: 'كاشير المعادي',
      supplier: null, notes: null, created_at: '2026-10-29 20:00' }] });
  const address = '/admin/branch-stock/data?branch=f:100&search=&page=1';
  cache.put('u:4', 'data', ORIGIN + address, 'application/json', Buffer.from(JSON.stringify(listing('3.25'))));
  clock += 60000;
  const key = '5b8e6f1c-1d2a-4c3b-9e8f-0a1b2c3d4e5f';
  const receipt = { branch: 'f:100', ingredient_id: 16, quantity: '12.5', unit: 'kg', supplier: 'سوق العبور', notes: '', idempotency_key: key };

  // Until the server takes receipts from devices, the page says the device needs the internet once.
  const early = await ask('POST', '/admin/branch-stock/receive', receipt);
  assert.equal(early.status, 422);
  assert.match(early.json.message, /مرة واحدة/);
  store.set('server_kinds', ['stock_receive']);

  const saved = await ask('POST', '/admin/branch-stock/receive', receipt);
  assert.equal(saved.status, 200, JSON.stringify(saved.json));
  // The page checks the answer is its own receipt (branch, item, unit, quantity, operation number).
  assert.deepEqual([saved.json.receipt.branch, saved.json.receipt.ingredient_id, saved.json.receipt.unit, saved.json.receipt.quantity, saved.json.receipt.idempotency_key],
    ['f:100', 16, 'kg', '12.5', key]);
  assert.deepEqual([saved.json.stock.quantity, saved.json.stock.unit_label, saved.json.stock.minimum], ['15.75', 'كجم', '5']);
  const [operation] = store.pendingOperations(50, ['stock_receive']);
  assert.deepEqual([operation.id, operation.kind, operation.data], [key, 'stock_receive',
    { ingredient_id: 16, unit: 'kg', quantity: '12.5', supplier: 'سوق العبور', notes: '' }]);
  const again = await ask('POST', '/admin/branch-stock/receive', receipt);
  assert.deepEqual([again.json.replayed, again.json.receipt.id, store.pendingOperations(50, ['stock_receive']).length], [true, saved.json.receipt.id, 1], 'Never twice');

  // The dashboard's own checks.
  const refused = async (values) => (await ask('POST', '/admin/branch-stock/receive', { ...receipt, idempotency_key: crypto.randomUUID(), ...values })).json.message;
  assert.equal(await refused({ ingredient_id: 20, unit: 'piece', quantity: '2.5' }), 'توريد القطع يجب أن يكون عددًا صحيحًا.');
  assert.equal(await refused({ ingredient_id: 20, unit: 'kg', quantity: '3' }), 'وحدة البضاعة مختلفة.');
  assert.equal(await refused({ ingredient_id: 999 }), 'اختر صنفًا من قائمة البضاعة فقط.');
  assert.equal(await refused({ quantity: '1.2345' }), 'أدخل كمية حتى ثلاث منازل عشرية.');
  assert.equal(await refused({ quantity: '0' }), 'الكمية يجب أن تكون موجبة وحتى مليون.');
  assert.equal((await ask('POST', '/admin/branch-stock/receive', { ...receipt, branch: 'f:101', idempotency_key: crypto.randomUUID() })).status, 422,
    'A branch this device was not set up for');

  // The balances and the latest movements: the device's receipt on top, with the balance it left (Cairo time).
  const list = (await ask('GET', address)).json;
  assert.deepEqual(list.items.map((item) => item.stock.quantity), ['15.75', '0']);
  assert.deepEqual([list.history[0].name, list.history[0].quantity, list.history[0].balance, list.history[0].supplier, list.history[0].created_at, list.history[0].source_type],
    ['بصل', '12.5', '15.75', 'سوق العبور', '2026-10-30 09:16', 'receipt']);
  assert.equal(list.history[1].id, 9);
  // Another search uses the same list, filtered here.
  const searched = (await ask('GET', '/admin/branch-stock/data?branch=f:100&search=' + encodeURIComponent('خبز') + '&page=1')).json;
  assert.deepEqual(searched.items.map((item) => item.name), ['خبز بلدي']);

  // The page's check of a receipt whose answer was lost goes through the employees page's address.
  const recovered = (await ask('GET', `/admin/branch-operations/recover?branch=f:100&idempotency_key=${key}`, null, employees)).json;
  assert.deepEqual([recovered.found, recovered.receipt.idempotency_key, recovered.receipt.quantity, recovered.stock.quantity], [true, key, '12.5', '15.75']);

  // Sent: still added until the page reads the list from the dashboard again, then counted once.
  clock += 60000;
  store.applyResults([{ id: key, status: 'applied', receipt: { id: 77 }, stock: { quantity: '15.75' } }]);
  assert.equal((await ask('GET', address)).json.items[0].stock.quantity, '15.75');
  clock += 60000;
  cache.put('u:4', 'data', ORIGIN + address, 'application/json', Buffer.from(JSON.stringify(listing('15.75'))));
  assert.equal((await ask('GET', address)).json.items[0].stock.quantity, '15.75', 'Not counted twice');
  // A receipt the dashboard refused is not added.
  const second = { ...receipt, quantity: '2', idempotency_key: crypto.randomUUID() };
  assert.equal((await ask('POST', '/admin/branch-stock/receive', second)).json.stock.quantity, '17.75');
  store.applyResults([{ id: second.idempotency_key, status: 'rejected', error: 'وحدة البضاعة مختلفة.' }]);
  assert.equal((await ask('GET', address)).json.items[0].stock.quantity, '15.75');
  assert.deepEqual(store.rejectedOperations().map((item) => item.kind), ['إضافة بضاعة']);
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

test('the delivery board offline keeps the orders the dashboard finished: its finished column as last synced, with this device\'s own', async () => {
  const { store, ask } = deliveryPage();
  const done = PHONE.finished.columns.finished.items[0];
  const earlier = { ...done, id: 90, status: 'cancelled', payment_status: 'unpaid', courier_company: null };
  store.applyService([{ ...serviceCopy(), hash: 'service-finished', tickets: [DASHBOARD[3].json.ticket, { ...PHONE.show.ticket, courier_company: null }],
    finished: [{ ...done, id: 95 }, earlier] }]);
  const board = (await ask('GET', '/admin/phone-orders/board?branch=f:100&preparing_page=1&courier_page=1&finished_page=1')).json.columns;
  assert.deepEqual(board.preparing.items.map((item) => item.id), [PHONE.show.ticket.id], 'The open orders stay');
  assert.deepEqual(board.finished.items.map((item) => [item.id, item.status]), [[95, done.status], [90, 'cancelled']], 'Newest first, as on the dashboard');
  assert.deepEqual(shape(board.finished.items[0]), shape(done), 'In the dashboard\'s own format');
  assert.deepEqual(board.finished.pagination, { page: 1, last_page: 1, total: 2 });
  // Filtered by delivery company like the dashboard: the cancelled one had none.
  const company = done.courier_company && done.courier_company.id;
  if (company) {
    const only = (await ask('GET', `/admin/phone-orders/board?branch=f:100&delivery_company_id=${company}`)).json.columns.finished.items;
    assert.deepEqual(only.map((item) => item.id), [95]);
  }
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
