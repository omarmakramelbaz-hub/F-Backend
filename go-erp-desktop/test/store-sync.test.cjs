'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const db = require('../src/main/db.cjs');
const { Store } = require('../src/main/store.cjs');
const { SyncEngine } = require('../src/main/sync.cjs');
const rules = require('../src/main/rules.cjs');

const CATALOG = {
  branch: { value: 'f:100', name: 'فرع المعادي', address: 'المعادي', phone: '0225250000' }, tax_bps: 1400, service_bps: 1200, can_discount: true,
  categories: [{ id: 1, name: 'أسماك' }],
  expense_categories: [{ key: 'electricity', name: 'كهرباء', stock: null }, { key: 'purchases', name: 'مشتريات', stock: null },
    { key: 'purchase_tomato', name: 'طماطم', stock: 'purchase_tomato' }],
  products: [
    { id: 1, name: 'بلطي', unit: '', category_id: 1, variants: [{ option_id: '', label: 'الأساسي', unit_price_cents: 12000, quantity_mode: 'select' }] },
    { id: 2, name: 'أرز', unit: '', category_id: 1, variants: [{ option_id: '', label: 'الأساسي', unit_price_cents: 2500, quantity_mode: 'piece' }] },
  ],
};
const CASHIER = { id: 4, name: 'كاشير', mobile: '1000000004', email: 'c@example.test', account_type: 'admin',
  password_hash: '$2y$10$abcdefghijklmnopqrstuuJ2t1b1o6Wm9F1VQ5i0x9Oq5s0e8xQm', branches: ['f:100'], can_checkout: true, can_manage: false, can_reprint: false };

function fixture(start = '2026-10-30T09:00:00Z') {
  let clock = Date.parse(start);
  let ids = 0;
  const store = new Store(db.open(':memory:'), { now: () => new Date(clock), uuid: () => `00000000-0000-4000-8000-${String(++ids).padStart(12, '0')}` });
  store.saveDevice({ id: 'device-1', code: 'K7', name: 'كاشير 1' }, [{ value: 'f:100', kind: 'f', name: 'فرع المعادي', address: '', phone: '' }]);
  store.replaceUsers([CASHIER, { ...CASHIER, id: 1, name: 'المالك', mobile: '1000000001', can_manage: true, can_approve_expenses: true,
    can_close_shift: true }], 'hash-1');
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1', catalog: CATALOG }]);
  return { store, tick: (ms) => { clock += ms; }, setClock: (iso) => { clock = Date.parse(iso); } };
}

function sell(store, actor, extra = {}) {
  const ticket = store.saveTicket(actor, { branch: 'f:100', channel: extra.channel || 'takeaway', data: {
    items: [{ product_id: 1, quantity_mode: 'weight', quantity: '1.5' }, { product_id: 2, quantity_mode: 'piece', quantity: '2' }],
    customer_phone: '01012345678', ...(extra.data || {}) } });
  return store.payTicket(actor, ticket.id, { cash_received: extra.cash || '500' });
}

test('a sale is saved with its invoice number and queued for the dashboard in one step', () => {
  const { store } = fixture();
  const actor = store.user(4);
  const paid = sell(store, actor);
  assert.equal(paid.status, 'paid');
  assert.equal(paid.local_number, 'K7-1030-0001');
  assert.equal(paid.business_date, '2026-10-30');
  // 18000 + 5000 = 23000; tax 14% = 3220.
  assert.equal(paid.totals.total_cents, 26220);
  assert.equal(paid.totals.change_cents, 50000 - 26220);
  const [operation] = store.pendingOperations();
  assert.equal(operation.kind, 'sale');
  assert.equal(operation.actor_id, 4);
  assert.equal(operation.data.snapshot_id, 'snap-1');
  assert.equal(operation.data.total_cents, 26220);
  assert.deepEqual(operation.data.items.map((item) => item.quantity), ['1.500', '2.000']);
  assert.equal(store.daySummary('f:100', '2026-10-30').total, '262.20');
});

test('dashboard rules are enforced before a sale is accepted offline', () => {
  const { store } = fixture();
  const actor = store.user(4);
  assert.throws(() => sell(store, actor, { data: { customer_phone: '123' } }), /هاتف/);
  assert.throws(() => sell(store, actor, { cash: '10' }), /أقل من/);
  assert.throws(() => sell(store, actor, { data: { discount: '5', discount_reason: 'عميل' } }), /الخصم/);
  assert.throws(() => sell(store, actor, { channel: 'dine' }), /الطاولة/);
  assert.throws(() => sell(store, actor, { channel: 'phone' }), /العميل وعنوانه/);
  const owner = store.user(1);
  assert.throws(() => sell(store, owner, { data: { discount: '5' } }), /سبب الخصم/);
  const discounted = sell(store, owner, { data: { discount: '5', discount_reason: 'عميل دائم' } });
  assert.equal(discounted.totals.discount_cents, 500);
  assert.equal(store.pendingOperations().length, 1, 'Refused sales queue nothing');
});

test('operating day starts at 6 in the morning, Cairo time', () => {
  assert.equal(rules.operatingDay(new Date('2026-07-15T03:30:00Z')), '2026-07-15'); // 06:30 Cairo summer time (UTC+3)
  assert.equal(rules.operatingDay(new Date('2026-07-15T02:30:00Z')), '2026-07-14'); // 05:30 Cairo
  assert.equal(rules.operatingDay(new Date('2026-12-15T04:30:00Z')), '2026-12-15'); // 06:30 Cairo winter time (UTC+2)
  assert.equal(rules.operatingDay(new Date('2026-12-15T03:30:00Z')), '2026-12-14'); // 05:30 Cairo
  assert.equal(rules.normalizePhone('٠١٠١٢٣٤٥٦٧٨'), '+201012345678');
  assert.equal(rules.normalizePhone('0101234567'), null);
  assert.equal(rules.loginKey('01000000004'), '1000000004');
});

test('the 30-second sync sends queued sales, applies server numbers and keeps rejected ones for review', async () => {
  const { store } = fixture();
  const actor = store.user(4);
  const first = sell(store, actor);
  const second = sell(store, actor);
  const calls = [];
  const api = { sync: async (device, token, body) => {
    calls.push(body);
    return { server_time: '2026-10-30T09:00:05Z', results: [
      { id: body.operations[0].id, status: 'applied', receipt: { id: 77, number: 'TA100-S9-0001' } },
      { id: body.operations[1].id, status: 'rejected', error: 'إجمالي الفاتورة لا يطابق المينيو المحفوظ على الجهاز.' },
    ] };
  } };
  const engine = new SyncEngine({ store, api, token: () => 'token', intervalMs: 60000 });
  await engine.run();
  engine.stop();
  assert.equal(calls[0].operations.length, 2);
  assert.equal(calls[0].want_users, true, 'The first sync after start refreshes accounts and menus');
  assert.equal(store.ticket(first.id).server_number, 'TA100-S9-0001');
  assert.equal(store.ticket(first.id).sync_status, 'synced');
  assert.equal(store.ticket(second.id).sync_status, 'rejected');
  assert.deepEqual(store.outboxStats(), { pending: 0, rejected: 1 });
  assert.match(store.rejectedOperations()[0].error, /لا يطابق/);
  assert.equal(engine.status().online, true);
});

test('without internet the sale stays queued and is sent on a later sync; retry keeps it pending', async () => {
  const { store } = fixture();
  const actor = store.user(4);
  sell(store, actor);
  let online = false;
  let retry = true;
  const api = { sync: async (device, token, body) => {
    if (!online) { const error = new Error('لا يوجد اتصال بالسرفر الآن.'); error.status = 0; throw error; }
    return { results: body.operations.map((operation) => ({ id: operation.id, status: retry ? 'retry' : 'applied', receipt: { id: 1, number: 'N1' } })) };
  } };
  const engine = new SyncEngine({ store, api, token: () => 'token', intervalMs: 60000 });
  await engine.run();
  assert.equal(engine.status().online, false);
  assert.equal(store.outboxStats().pending, 1);
  online = true;
  await engine.run();
  assert.equal(store.outboxStats().pending, 1, 'A temporary server error keeps the sale queued');
  retry = false;
  await engine.run();
  engine.stop();
  assert.equal(store.outboxStats().pending, 0);
});

test('accounts and menus from the server replace the old ones; unchanged menus keep the stored copy', () => {
  const { store } = fixture();
  store.replaceUsers([{ ...CASHIER, name: 'كاشير جديد' }], 'hash-2');
  assert.equal(store.user(4).name, 'كاشير جديد');
  assert.equal(store.user(1), null);
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-1', hash: 'h1' }]);
  assert.equal(store.catalog('f:100').products.length, 2);
  store.applyBranches([{ value: 'f:100', available: true, snapshot_id: 'snap-2', hash: 'h2', catalog: { ...CATALOG, products: [CATALOG.products[0]] } }]);
  assert.equal(store.catalog('f:100').snapshot_id, 'snap-2');
  assert.equal(store.catalog('f:100').products.length, 1);
  assert.deepEqual(store.catalogHashes(), { 'f:100': 'h2' });
  assert.equal(store.findUserByLogin('01000000004').id, 4);
  assert.equal(store.findUserByLogin('C@example.test').id, 4);
});

test('setting up a stopped device again keeps its invoices and unsent sales', () => {
  const { store } = fixture();
  sell(store, store.user(4));
  store.forgetDevice();
  assert.equal(store.device(), null);
  assert.equal(store.userCount(), 0);
  assert.equal(store.usersHash(), null);
  assert.equal(store.receipts('f:100', '2026-10-30').length, 1);
  assert.equal(store.pendingOperations().length, 1);
});

test('receipts print amounts of items without a stock unit without a kilogram label', () => {
  const printing = require('../src/main/printing.cjs');
  const money = require('../src/main/money.cjs');
  const quote = money.quote(CATALOG, { items: [{ product_id: 1, quantity_mode: 'weight', quantity: '1.250' }, { product_id: 2, quantity_mode: 'piece', quantity: '3' }] });
  assert.deepEqual(quote.items.map(printing.quantityLabel), ['1.25', '3']);
  const weighed = money.quote({ ...CATALOG, products: [{ ...CATALOG.products[0], variants: [{ ...CATALOG.products[0].variants[0], quantity_mode: 'weight' }] }] },
    { items: [{ product_id: 1, quantity_mode: 'weight', quantity: '0.5' }] });
  assert.equal(printing.quantityLabel(weighed.items[0]), '0.500 كجم');
});

test('expenses follow the dashboard rules and are queued with the day they were paid', () => {
  const { store } = fixture('2026-10-30T02:30:00Z'); // 04:30 in Cairo: still the working day of 29 October
  const cashier = store.user(4);
  const owner = store.user(1);
  assert.equal(owner.can_approve_expenses, true);
  assert.equal(owner.can_close_shift, true);
  assert.equal(cashier.can_approve_expenses, false);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'gas', amount: '10' }), /بند المصروف/);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'electricity', amount: '0' }), /أكبر من صفر/);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'purchase_tomato', amount: '60' }), /الكيلوات/);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'electricity', amount: '5', quantity_kg: '2' }), /البضاعة فقط/);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'purchases', amount: '60', description: 'شراء طماطم' }), /مشتريات طماطم/);
  assert.throws(() => store.recordExpense(cashier, 'f:100', { category: 'electricity', amount: '5', approve: true }), /اعتماد/);
  const pending = store.recordExpense(cashier, 'f:100', { category: 'electricity', amount: '350.5', description: 'فاتورة كهرباء' });
  assert.equal(pending.business_date, '2026-10-29');
  assert.equal(pending.amount_cents, 35050);
  const tomato = store.recordExpense(owner, 'f:100', { category: 'purchase_tomato', amount: '60', quantity_kg: '٢٫٥', approve: true });
  const [first, second] = store.pendingOperations();
  assert.deepEqual({ kind: first.kind, actor_id: first.actor_id, data: first.data }, { kind: 'expense', actor_id: 4,
    data: { category: 'electricity', description: 'فاتورة كهرباء', amount: '350.50', supplier: '', notes: '', quantity_kg: '', approve: false } });
  assert.equal(second.id, tomato.id);
  assert.equal(second.data.quantity_kg, '2.5');
  assert.equal(second.data.approve, true);
});

test('only managers move cash, and the drawer summary counts what this device did', () => {
  const { store } = fixture();
  const cashier = store.user(4);
  const owner = store.user(1);
  sell(store, cashier); // 262.20
  assert.throws(() => store.recordTill(cashier, 'f:100', { direction: 'in', amount: '100', note: 'فكة' }), /غير مسموح/);
  assert.throws(() => store.recordTill(owner, 'f:100', { direction: 'in', amount: '100', note: ' ' }), /سبب/);
  store.recordTill(owner, 'f:100', { direction: 'in', amount: '500', note: 'فكة' });
  const out = store.recordTill(owner, 'f:100', { direction: 'out', amount: '200', note: 'توريد للمالك' });
  store.recordExpense(owner, 'f:100', { category: 'electricity', amount: '50', approve: true });
  store.recordExpense(cashier, 'f:100', { category: 'electricity', amount: '30' });
  const summary = store.cashSummary('f:100', '2026-10-30');
  assert.equal(summary.sales_cents, 26220);
  assert.equal(summary.cash_in_cents, 50000);
  assert.equal(summary.cash_out_cents, 20000);
  assert.equal(summary.approved_expenses_cents, 5000);
  assert.equal(summary.pending_expenses_cents, 3000);
  assert.equal(summary.drawer_movement_cents, 26220 + 50000 - 20000 - 5000);
  // A movement the dashboard refused no longer counts, and shows its reason.
  store.applyResults([{ id: out.id, status: 'rejected', error: 'الرصيد النقدي غير كافٍ أو المبلغ غير مسموح.' }]);
  assert.equal(store.cashSummary('f:100', '2026-10-30').cash_out_cents, 0);
  assert.equal(store.journalEntry(out.id).sync_status, 'rejected');
  assert.deepEqual(store.rejectedOperations().map((row) => [row.kind, row.total]), [['خزنة', '200.00']]);
  assert.equal(store.journal('f:100', '2026-10-30').length, 4);
});

test('a synced expense shows the dashboard number and status', () => {
  const { store } = fixture();
  const entry = store.recordExpense(store.user(4), 'f:100', { category: 'electricity', amount: '20' });
  store.applyResults([{ id: entry.id, status: 'applied', expense: { id: 9, number: 'EXP-000009', status: 'pending' } }]);
  const synced = store.journalEntry(entry.id);
  assert.equal(synced.sync_status, 'synced');
  assert.equal(synced.server_ref, 'EXP-000009 · بانتظار الاعتماد');
  assert.equal(store.pendingOperations().length, 0);
});

test('an older server only receives sales; expenses and cash moves wait until the server lists them', async () => {
  const { store } = fixture();
  const owner = store.user(1);
  sell(store, owner);
  const expense = store.recordExpense(owner, 'f:100', { category: 'electricity', amount: '20' });
  const sent = [];
  let kinds;
  const api = { sync: async (device, token, body) => {
    sent.push(body.operations.map((operation) => operation.kind));
    return { ...(kinds ? { kinds } : {}), results: body.operations.map((operation) => ({ id: operation.id, status: 'applied',
      receipt: { id: 1, number: 'N1' }, expense: { id: 3, number: 'EXP-000003', status: 'pending' } })) };
  } };
  const engine = new SyncEngine({ store, api, token: () => 'token', intervalMs: 60000 });
  await engine.run();
  assert.deepEqual(sent[0], ['sale'], 'A server that does not list its operations gets sales only');
  assert.equal(store.journalEntry(expense.id).sync_status, 'pending');
  kinds = ['sale', 'expense', 'till'];
  await engine.run(); // learns what the updated server knows
  await engine.run();
  engine.stop();
  assert.deepEqual(sent.slice(1), [[], ['expense']]);
  assert.equal(store.journalEntry(expense.id).sync_status, 'synced');
});

test('the device sales report adds up invoices by channel, cashier, day and item', () => {
  const { store, setClock } = fixture();
  const cashier = store.user(4);
  const owner = store.user(1);
  sell(store, cashier); // takeaway: 23000 + 14% tax = 26220
  sell(store, owner, { channel: 'dine', data: { table_name: '3' } }); // dine: 23000 + 12% service 2760 + tax 3606 = 29366
  setClock('2026-10-31T09:00:00Z');
  sell(store, cashier);
  const day = store.salesReport('f:100', '2026-10-30', '2026-10-30');
  assert.equal(day.count, 2);
  assert.equal(day.total_cents, 26220 + 29366);
  assert.equal(day.service_cents, 2760);
  assert.deepEqual(day.channels.map((entry) => [entry.channel, entry.count, entry.total_cents]), [['dine', 1, 29366], ['takeaway', 1, 26220]]);
  assert.deepEqual(day.cashiers.map((entry) => [entry.name, entry.total_cents]), [['المالك', 29366], ['كاشير', 26220]]);
  const range = store.salesReport('f:100', '2026-10-30', '2026-10-31');
  assert.equal(range.count, 3);
  assert.deepEqual(range.days.map((entry) => entry.day), ['2026-10-30', '2026-10-31']);
  assert.deepEqual(range.items.map((line) => [line.name, line.quantity_millis, line.invoices]), [['بلطي', 4500, 3], ['أرز', 6000, 3]]);
  assert.throws(() => store.salesReport('f:100', '2026-10-31', '2026-10-30'), /الفترة/);
  const printing = require('../src/main/printing.cjs');
  const html = printing.report({ ...day, cash: store.cashSummary('f:100', '2026-10-30') }, { name: 'فرع المعادي' }, { width: 72 });
  assert.match(html, /إجمالي المبيعات/);
  assert.match(html, /555\.86/);
  assert.match(html, /حركة الدرج من الجهاز/);
});
