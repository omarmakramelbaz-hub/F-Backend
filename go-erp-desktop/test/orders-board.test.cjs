'use strict';
// The app orders board («استقبال طلبات التطبيقات») offline: actions recorded on the device, the board as they leave
// it, and their way to the dashboard once online.
const test = require('node:test');
const assert = require('node:assert/strict');
const db = require('../src/main/db.cjs');
const { Store } = require('../src/main/store.cjs');
const { PageCache } = require('../src/main/offline/cache.cjs');
const { OrdersBoardOffline, replayScript, findCard, cardState } = require('../src/main/offline/orders.cjs');

const ORIGIN = 'https://fasakhaninja.com';
const GROUPS = ['new', 'preparing', 'courier', 'completed'];
const USER = { id: 4, name: 'كاشير المعادي', account_type: 'admin', can_checkout: 1 };

/** A card as resources/views/admin/orders/board_card.blade.php renders it. */
function card({ id, status = 'pending', label = 'طلب جديد', actions = ['accept', 'reject'], notify = '', source = 'legacy' }) {
  const key = `${source}:${id}`;
  const form = actions.length ? `<form class="ob-order-actions" action="${ORIGIN}/admin/order-board/${source}/${id}/action" method="post" data-order-action data-desktop-board-generation="server"${
    actions.includes('prepare') ? ` data-print-after="prepare" data-print-url="${ORIGIN}/admin/order-board/${source}/${id}/print"` : ''}>
            <input type="hidden" name="_token" value="csrf-token">            <input type="hidden" name="expected_status" value="${status}">
            <input type="hidden" name="expected_accepted_notify" value="${notify}">
            <input type="hidden" name="expected_revision" value="">
${actions.map((name) => `                    <button type="submit" name="action" value="${name}" class="ob-action ob-action-${name}"><i class="fas fa-check" aria-hidden="true"></i><span>${name}</span></button>`).join('\n')}
        </form>` : '';
  return `<article class="ob-order" data-order-key="${key}" data-order-status="${status}" data-order-alert-eligible="1" aria-label="#R100-${id}">
    <div class="ob-order-top"><div class="ob-order-identity"><strong class="ob-order-number" title="#R100-${id}"><bdi>#R100-${id}</bdi></strong></div></div>
    <div class="ob-order-tags">
            <span class="ob-store" title="فسخانستا"><i class="fas fa-store" aria-hidden="true"></i><span>فسخانستا</span></span>
            <span class="ob-order-status">${label}</span>
    </div>
    <div class="ob-order-tools">
            <a href="${ORIGIN}/admin/order-board/${source}/${id}" target="_blank" rel="noopener"><i class="far fa-file-alt" aria-hidden="true"></i>عرض التفاصيل</a>
        </div>
    ${form}
</article>`;
}

function columns(groups) {
  return GROUPS.map((group) => `    <section class="ob-column ob-stage-${group}" data-column="${group}" aria-labelledby="ob-column-${group}">
        <header class="ob-column-header"><h2 id="ob-column-${group}">${group}</h2><span class="ob-column-count" data-board-count="${group}">${(groups[group] || []).length}</span></header>
        <div class="ob-card-list" data-column-list="${group}" tabindex="0" aria-label="${group}">
${(groups[group] || []).join('\n') || '<div class="ob-empty"><i class="fas fa-shopping-cart" aria-hidden="true"></i><p>لا توجد طلبات</p></div>'}
        </div>
        <footer class="ob-column-footer" data-current-page="1" hidden></footer>
    </section>`).join('\n');
}

function setup(start = '2026-11-02T10:00:00Z') {
  let clock = Date.parse(start);
  const now = () => new Date(clock);
  const store = new Store(db.open(':memory:'), { now });
  store.replaceUsers([{ ...USER, mobile: '1', email: 'c@example.test', password_hash: 'x', branches: ['f:100'], permissions: [] }], 'h');
  const cache = new PageCache(':memory:', { now });
  let next = 0;
  const board = new OrdersBoardOffline({ store, cache, origin: ORIGIN, now, uuid: () => `00000000-0000-4000-8000-${String(++next).padStart(12, '0')}` });
  const groups = { new: [card({ id: 1 }), card({ id: 2 })], preparing: [card({ id: 3, label: 'قيد التجهيز', actions: ['prepare', 'dispatch', 'cancel'], notify: 'yes' })],
    courier: [], completed: [] };
  const html = columns(groups);
  const feed = ORIGIN + '/admin/getOrders?search=&app=&branch=';
  cache.put('u:4', 'data', feed, 'application/json', Buffer.from(JSON.stringify({ html, view: html, count: 3, counts: { new: 2, preparing: 1, courier: 0, completed: 0 },
    updated_at: '2026-11-02T11:59:00+02:00', pending_alert_keys: ['legacy:1', 'legacy:2'] })));
  cache.put('u:4', 'data', ORIGIN + '/admin/getOrders?alerts_only=1', 'application/json', Buffer.from(JSON.stringify({ pending_alert_keys: ['legacy:1', 'legacy:2'] })));
  const page = `<body data-dashboard-actor="4:admin"><main id="order-board" class="ob-board" data-feed-url="${ORIGIN}/admin/getOrders">
<div class="ob-summary"><div><strong data-board-count="new">2</strong></div><div><strong data-board-count="preparing">1</strong></div><div><strong data-board-count="courier">0</strong></div></div>
<div class="ob-columns">
${html}
</div></main></body>`;
  cache.put('u:4', 'page', ORIGIN + '/admin/applies-orders', 'text/html', Buffer.from(page));
  const act = (id, action, extra = {}, source = 'legacy') => {
    const found = findCard(currentCard(id, source), `${source}:${id}`);
    const state = cardState(found.html);
    return board.answer(board.match('POST', `/admin/order-board/${source}/${id}/action`), { user: USER,
      body: { _token: 'csrf-token', ...state.fields, action, ...extra } });
  };
  const currentCard = (id, source) => {
    const pending = store.boardOverlay(USER.id).get(`${source}:${id}`);
    return pending ? pending.card : html;
  };
  const ask = (address) => board.answer(board.match('GET', new URL(address).pathname), { user: USER, url: new URL(address) });
  return { store, cache, board, act, ask, feed, page, tick: (ms) => { clock += ms; } };
}

test('an app order accepted offline moves to «قيد التجهيز» with its next actions, and is sent as the page sent it', async () => {
  const { store, act } = setup();
  const reply = await act(1, 'accept');
  assert.equal(reply.status, 200);
  assert.equal(reply.json.success, true);
  assert.match(reply.json.message, /اتسجل على الجهاز/);
  const moved = reply.json.card;
  assert.deepEqual([moved.key, moved.from_group, moved.group], ['legacy:1', 'new', 'preparing']);
  const state = cardState(moved.html);
  assert.deepEqual(state.actions, ['prepare', 'dispatch'], '«مندوب الفرع» and «طلب مندوب»');
  assert.deepEqual([state.status, state.fields.expected_status, state.fields.expected_accepted_notify], ['pending', 'pending', 'yes'],
    'The next action checks the order as the dashboard will have it');
  assert.match(moved.html, /<span class="ob-order-status">قيد التجهيز<\/span>/);
  assert.match(moved.html, /data-device-pending/);
  assert.match(moved.html, /data-print-after="prepare" data-print-url="\/admin\/order-board\/legacy\/1\/print"/, '«مندوب الفرع» prints it');
  assert.match(moved.html, /name="_token" value="csrf-token"/);
  const [command] = store.pendingBoardCommands(USER.id);
  assert.deepEqual([command.source, command.order_id, command.order_number, command.action, command.from_group, command.to_group],
    ['legacy', 1, 'R100-1', 'accept', 'new', 'preparing']);
  assert.deepEqual(command.fields, { expected_status: 'pending', expected_accepted_notify: '', expected_revision: '', action: 'accept',
    idempotency_key: command.id }, 'Exactly the page\'s fields (no session token)');
  assert.equal(store.outboxStats().pending, 1, 'Waits with the sync');
});

test('the next action on the same order follows from the device, in order: «مندوب الفرع» then delivered', async () => {
  const { store, act } = setup();
  await act(1, 'accept');
  const courier = await act(1, 'prepare');
  assert.deepEqual([courier.json.card.from_group, courier.json.card.group], ['preparing', 'courier']);
  const state = cardState(courier.json.card.html);
  assert.deepEqual([state.actions, state.fields.expected_status], [['complete'], 'accepted']);
  assert.match(courier.json.card.html, /<span class="ob-order-status">مع المندوب<\/span>/);
  assert.equal((courier.json.card.html.match(/data-device-pending/g) || []).length, 1, 'One badge');
  const done = await act(1, 'complete');
  assert.deepEqual([done.json.card.group, cardState(done.json.card.html).actions], ['completed', []]);
  assert.deepEqual(store.pendingBoardCommands(USER.id).map((command) => command.action), ['accept', 'prepare', 'complete']);
  // An action the order does not offer now, or an order not on the device's board, is refused as the dashboard would.
  const again = await act(1, 'complete');
  assert.equal(again.status, 409);
  const unknown = await setup().board.answer(setup().board.match('POST', '/admin/order-board/legacy/99/action'),
    { user: USER, body: { action: 'accept', expected_status: 'pending' } });
  assert.equal(unknown.status, 409);
});

test('the board offline shows the orders as the device left them: its feed, its counts and its alerts', async () => {
  const { act, ask, feed } = setup();
  await act(1, 'accept');
  await act(3, 'prepare');
  const reply = await ask(feed);
  assert.equal(reply.status, 200);
  const { html, counts, pending_alert_keys: keys } = reply.json;
  assert.deepEqual(counts, { new: 1, preparing: 1, courier: 1, completed: 0 });
  const one = findCard(html, 'legacy:1');
  const three = findCard(html, 'legacy:3');
  assert.deepEqual([one.group, three.group, findCard(html, 'legacy:2').group], ['preparing', 'courier', 'new']);
  assert.ok(!/data-column-list="courier"[^>]*>\s*<div class="ob-empty">/.test(html), 'No "no orders" note over the card');
  assert.deepEqual(keys, ['legacy:2'], 'Orders acted on here stop ringing');
  const alerts = await ask(ORIGIN + '/admin/getOrders?alerts_only=1');
  assert.deepEqual(alerts.json.pending_alert_keys, ['legacy:2']);
});

test('the board page from the device shows the same, counts included', async () => {
  const { act, board, page } = setup();
  await act(1, 'accept');
  const shown = board.overlay(USER, new URL(ORIGIN + '/admin/applies-orders'), Buffer.from(page), '2026-11-02T09:00:00Z').toString('utf8');
  assert.equal(findCard(shown, 'legacy:1').group, 'preparing');
  assert.match(shown, /<strong data-board-count="new">1<\/strong>/);
  assert.match(shown, /<strong data-board-count="preparing">2<\/strong>/);
  assert.match(shown, /data-board-count="preparing">2<\/span>/);
  const other = Buffer.from('<body>another page</body>');
  assert.equal(board.overlay(USER, new URL(ORIGIN + '/admin/takeaway'), other), other);
});

test('sent: a copy saved before shows the order as sent until it is read again; a refusal stops what followed', async () => {
  const { store, cache, act, ask, feed, tick } = setup();
  await act(1, 'accept');
  await act(1, 'prepare');
  await act(2, 'accept');
  const [accept, prepare, second] = store.pendingBoardCommands(USER.id);
  tick(60000);
  store.finishBoardCommand(accept.id, 'done');
  store.finishBoardCommand(prepare.id, 'done');
  assert.equal(findCard((await ask(feed)).json.html, 'legacy:1').group, 'courier', 'The saved copy predates them');
  // Read again from the dashboard: the copy shows the dashboard's own state.
  tick(1000);
  cache.put('u:4', 'data', feed, 'application/json', Buffer.from(JSON.stringify({ html: '<div data-column-list="new"></div>', counts: { new: 0 } })));
  assert.equal(findCard((await ask(feed)).json.html, 'legacy:1'), null);
  // The dashboard refused the second order's acceptance (changed there meanwhile).
  store.finishBoardCommand(second.id, 'rejected', 'تغيرت حالة الطلب. حدّث الصفحة.');
  const rejected = store.rejectedOperations();
  assert.equal(rejected[0].kind, 'طلب تطبيق: قبول وإرسال للمطبخ');
  assert.deepEqual([rejected[0].local_number, rejected[0].error], ['R100-2', 'تغيرت حالة الطلب. حدّث الصفحة.']);
  assert.deepEqual(store.outboxStats(), { pending: 0, rejected: 1 });
  // A refusal also stops the actions recorded after it on the same order.
  const fresh = setup();
  await fresh.act(1, 'accept');
  await fresh.act(1, 'prepare');
  const [first] = fresh.store.pendingBoardCommands(USER.id);
  fresh.store.finishBoardCommand(first.id, 'rejected', 'ملغي من العميل');
  assert.deepEqual(fresh.store.pendingBoardCommands(USER.id), []);
  assert.equal(fresh.store.releaseBlockers().pending, 0);
});

test('the print pages of the board\'s orders are known to the device, and the sending script is the page\'s own request', () => {
  const { board, store } = setup();
  assert.deepEqual(board.printPages(USER), [ORIGIN + '/admin/order-board/legacy/3/print']);
  const script = replayScript([{ id: 'a', source: 'legacy', order_id: 1, fields: { action: 'accept', expected_status: 'pending' } }]);
  assert.doesNotThrow(() => new Function(script));
  assert.match(script, /'\/admin\/order-board\/' \+ command\.source/);
  assert.match(script, /X-CSRF-TOKEN/);
  assert.equal(store.pendingBoardCommands().length, 0);
});

test('on the dashboard\'s own board markup: accept, «مندوب الفرع» and delivered move each order as the dashboard does', async () => {
  const fs = require('node:fs');
  const path = require('node:path');
  // Rendered by tests/Feature/DashboardOrderBoardTest.php (test_the_desktop_app_reads_the_board_markup_of_this_fixture_offline).
  const html = fs.readFileSync(path.join(__dirname, 'fixtures', 'board-columns.html'), 'utf8');
  const origin = 'http://localhost';
  const now = () => new Date('2026-11-02T10:00:00Z');
  const store = new Store(db.open(':memory:'), { now });
  const cache = new PageCache(':memory:', { now });
  let next = 0;
  const board = new OrdersBoardOffline({ store, cache, origin, now, uuid: () => `00000000-0000-4000-8000-${String(++next).padStart(12, '0')}` });
  const counts = { new: 1, preparing: 1, courier: 1, completed: 0 };
  cache.put('u:4', 'data', origin + '/admin/getOrders?page_new=1', 'application/json', Buffer.from(JSON.stringify({ html, view: html, counts })));
  const act = (id, action) => {
    const shown = store.boardOverlay(USER.id).get('legacy:' + id);
    const state = cardState(shown ? shown.card : findCard(html, 'legacy:' + id).html);
    return board.answer(board.match('POST', `/admin/order-board/legacy/${id}/action`), { user: USER, body: { ...state.fields, action } });
  };
  const accepted = await act(1, 'accept');
  assert.deepEqual([accepted.json.card.from_group, accepted.json.card.group], ['new', 'preparing']);
  const afterAccept = cardState(accepted.json.card.html);
  assert.deepEqual([afterAccept.actions, afterAccept.fields.expected_status, afterAccept.fields.expected_accepted_notify], [['prepare', 'dispatch'], 'pending', 'yes']);
  assert.equal(afterAccept.fields._token, 'csrf-token', 'The page\'s own form, token included');
  assert.match(accepted.json.card.html, /data-print-after="prepare" data-print-url="\/admin\/order-board\/legacy\/1\/print"/);
  const branchCourier = await act(2, 'prepare');
  assert.deepEqual([branchCourier.json.card.from_group, branchCourier.json.card.group], ['preparing', 'courier']);
  assert.deepEqual(cardState(branchCourier.json.card.html).actions, ['complete']);
  assert.ok(!/data-print-after/.test(branchCourier.json.card.html), 'Delivered prints nothing');
  const delivered = await act(3, 'complete');
  assert.deepEqual([delivered.json.card.from_group, delivered.json.card.group, cardState(delivered.json.card.html).actions], ['courier', 'completed', []]);
  // The same board read offline: every card where the device left it, and the counts with them.
  const feed = await board.answer(board.match('GET', '/admin/getOrders'), { user: USER, url: new URL(origin + '/admin/getOrders?page_new=1') });
  assert.deepEqual(feed.json.counts, { new: 0, preparing: 1, courier: 1, completed: 1 });
  assert.deepEqual(['legacy:1', 'legacy:2', 'legacy:3'].map((key) => findCard(feed.json.html, key).group), ['preparing', 'courier', 'completed']);
  assert.match(feed.json.html, /data-column-list="new"[^>]*>\s*\n?/, 'The emptied column keeps its list');
  assert.deepEqual(store.pendingBoardCommands(USER.id).map((command) => [command.order_id, command.action, command.fields.expected_status]),
    [[1, 'accept', 'pending'], [2, 'prepare', 'pending'], [3, 'complete', 'accepted']]);
});
