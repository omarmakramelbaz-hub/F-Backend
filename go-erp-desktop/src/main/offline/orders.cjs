'use strict';
// The dashboard's app orders board («استقبال طلبات التطبيقات») while the internet is down. An action on an order
// (accept, «مندوب الفرع», «طلب مندوب», delivered, cancel...) is recorded on the device and the card moves on the board
// as it would; once online the app sends it to the board's own action, so the dashboard does all it does for a click
// (the customer's notifications, the courier, the wallet) with its own checks. Until then the board, its feed and the
// order alerts show the saved copy with these actions on it.
const crypto = require('node:crypto');

const ACTION = /^\/admin\/order-board\/(legacy|store|service|partner_service|website)\/([1-9][0-9]{0,18})\/action$/;
const FEED = '/admin/getOrders';
const BOARD = '/admin/applies-orders';
const ACTIONS = ['accept', 'reject', 'cancel', 'prepare', 'ready', 'dispatch', 'complete'];
const GROUPS = ['new', 'preparing', 'courier', 'completed'];
// The board's own button words (resources/lang/ar/order_board.php, OrderBoardService action_labels).
const LABELS = { accept: 'قبول وإرسال للمطبخ', reject: 'رفض الطلب', prepare: 'مندوب الفرع', dispatch: 'طلب مندوب', complete: 'تم التسليم',
  cancel: 'إلغاء الطلب', ready: 'جاهز للتسليم' };
// An app order (legacy) after each action, as the dashboard shows it (OrderBoardService::legacyActions, group, labels):
// its column, status, label, and the actions it offers next.
const LEGACY = {
  accept: { group: 'preparing', status: 'pending', notify: 'yes', label: 'قيد التجهيز', next: ['prepare', 'dispatch'] },
  prepare: { group: 'courier', status: 'accepted', notify: 'yes', label: 'مع المندوب', next: ['complete'] },
  dispatch: { label: 'بانتظار مندوب التطبيق', next: [] },
  complete: { group: 'completed', status: 'completed', label: 'تم التسليم', next: [] },
  cancel: { group: 'completed', status: 'cancelled', label: 'ملغي', next: [] },
  reject: { group: 'completed', status: 'declined', label: 'مرفوض', next: [] },
};
const PENDING_BADGE = '<span class="ob-order-status" data-device-pending style="background:rgba(253,114,1,.14);color:#fd7201">على الجهاز · هيتبعت مع التزامن</span>';

class Reply extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
const attribute = (html, name) => {
  const found = new RegExp(`\\b${name}="([^"]*)"`).exec(html);
  return found ? found[1].replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') : null;
};

/** Where the element opening at `start` ends (after its closing tag), counting the same elements inside it. */
function elementEnd(html, start, tag) {
  const pattern = new RegExp(`<(/?)${tag}\\b[^>]*>`, 'gi');
  pattern.lastIndex = start;
  let depth = 0;
  for (let found = pattern.exec(html); found; found = pattern.exec(html)) {
    if (found[1]) depth--;
    else if (!found[0].endsWith('/>')) depth++;
    if (depth === 0) return pattern.lastIndex;
  }
  return -1;
}

/** The order's card in a board's HTML: where it is, its markup and its column. */
function findCard(html, key) {
  const start = html.indexOf(`data-order-key="${escapeHtml(key)}"`);
  if (start < 0) return null;
  const open = html.lastIndexOf('<article', start);
  if (open < 0) return null;
  const end = elementEnd(html, open, 'article');
  if (end < 0) return null;
  const before = html.slice(0, open);
  const list = before.lastIndexOf('data-column-list="');
  const group = list >= 0 ? /data-column-list="([a-z]+)"/.exec(before.slice(list))[1] : null;
  return { start: open, end, html: html.slice(open, end), group };
}

/** What a card offers: its status, its actions (the buttons of its form), and the form's fields. */
function cardState(card) {
  const form = /<form\b[^>]*\bdata-order-action\b[^>]*>[\s\S]*?<\/form>/.exec(card);
  const actions = form ? Array.from(form[0].matchAll(/<button\b[^>]*\bname="action"[^>]*\bvalue="([a-z_]+)"/g)).map((found) => found[1]) : [];
  const fields = {};
  if (form) {
    for (const found of form[0].matchAll(/<input\b[^>]*>/g)) {
      const name = attribute(found[0], 'name');
      if (name && /\btype="hidden"/.test(found[0])) fields[name] = attribute(found[0], 'value') || '';
    }
  }
  return { status: attribute(card, 'data-order-status'), actions, fields, form: form ? form[0] : null,
    number: (attribute(card, 'aria-label') || '').replace(/^#/, '') };
}

/** The card after an action taken here: its column, status and next actions as the dashboard would show them. */
function nextCard(card, source, action, orderId) {
  const state = cardState(card);
  const legacy = source === 'legacy' ? LEGACY[action] : null;
  let html = card;
  const status = legacy && legacy.status ? legacy.status : state.status;
  if (legacy && legacy.status) html = html.replace(/(\bdata-order-status=")[^"]*(")/, (all, a, b) => a + escapeHtml(legacy.status) + b);
  if (legacy && legacy.label) {
    html = html.replace(/(<span class="ob-order-status">)[^<]*(<\/span>)/, (all, a, b) => a + escapeHtml(legacy.label) + b);
  }
  if (!html.includes('data-device-pending')) html = html.replace(/(<span class="ob-order-status">[^<]*<\/span>)/, (all) => all + PENDING_BADGE);
  // The next actions, with the state the dashboard will check them against once this one reached it.
  const next = legacy ? legacy.next : [];
  let form = '';
  if (next.length && state.form) {
    const open = /^<form\b[^>]*>/.exec(state.form)[0].replace(/\s+data-print-after="[^"]*"/, '').replace(/\s+data-print-url="[^"]*"/, '');
    const print = `/admin/order-board/legacy/${orderId}/print`;
    const opening = next.includes('prepare') ? open.replace(/>$/, ` data-print-after="prepare" data-print-url="${escapeHtml(print)}">`) : open;
    const token = /<input\b[^>]*\bname="_token"[^>]*>/.exec(state.form);
    const fields = { expected_status: status, expected_accepted_notify: legacy.notify ?? state.fields.expected_accepted_notify ?? '',
      expected_revision: state.fields.expected_revision ?? '' };
    form = opening + (token ? token[0] : '')
      + Object.entries(fields).map(([name, value]) => `<input type="hidden" name="${name}" value="${escapeHtml(value)}">`).join('')
      + next.map((name) => `<button type="submit" name="action" value="${name}" class="ob-action ob-action-${name}"><i class="fas ${
        ['reject', 'cancel'].includes(name) ? 'fa-times' : ['dispatch', 'prepare'].includes(name) ? 'fa-motorcycle' : 'fa-check'}" aria-hidden="true"></i><span>${
        escapeHtml(LABELS[name] || name)}</span></button>`).join('') + '</form>';
  }
  html = state.form ? html.replace(state.form, () => form) : html;
  return { html, group: legacy && legacy.group ? legacy.group : null };
}

/**
 * The board's columns (page or feed HTML) with the actions recorded here: each order's card as it shows it now, in
 * its column. Returns the HTML and how many cards each column gained or lost.
 */
function applyCommands(html, commands) {
  const moved = Object.fromEntries(GROUPS.map((group) => [group, 0]));
  let out = html;
  for (const command of commands) {
    const key = command.source + ':' + command.order_id;
    const found = findCard(out, key);
    const from = found ? found.group : command.from_group;
    if (found) out = out.slice(0, found.start) + out.slice(found.end);
    const list = out.indexOf(`data-column-list="${command.to_group}"`);
    if (list < 0) continue;
    const at = out.indexOf('>', list) + 1;
    let rest = out.slice(at);
    // The column's "no orders" note goes once it has a card.
    rest = rest.replace(/^\s*<div class="ob-empty">[\s\S]*?<\/div>/, '');
    out = out.slice(0, at) + command.card + rest;
    if (from !== command.to_group) {
      if (found && moved[from] !== undefined) moved[from]--;
      if (moved[command.to_group] !== undefined) moved[command.to_group]++;
    }
  }
  return { html: out, moved };
}

class OrdersBoardOffline {
  constructor({ store, cache, origin, now = () => new Date(), uuid = () => crypto.randomUUID() }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.uuid = uuid;
  }

  match(method, pathname) {
    const action = ACTION.exec(pathname);
    if (method === 'POST' && action) return { handle: (request) => this.action(request, action[1], Number(action[2])) };
    // keepOnline: the dashboard's own feed is kept online, as the board offline starts from it.
    if (method === 'GET' && pathname === FEED) return { keepOnline: true, handle: (request) => this.feed(request) };
    return null;
  }

  async answer(route, request) {
    try {
      return await route.handle(request);
    } catch (error) {
      if (error instanceof Reply) return { status: error.status, json: { success: false, message: error.message } };
      return { status: 422, json: { success: false, message: error && error.message ? error.message : 'حدث خطأ غير متوقع.' } };
    }
  }

  /** The actions recorded here that the saved copy (read online at storedAt) does not show yet, oldest first. */
  commands(user, storedAt = null) {
    const latest = new Map();
    const add = (command) => latest.set(command.source + ':' + command.order_id, command);
    // Sent since the copy was saved (the copy still shows the order before them), then those not sent yet: the newest
    // action on each order decides its card.
    if (storedAt) this.store.recentBoardCommands(user.id, storedAt).forEach(add);
    this.store.pendingBoardCommands(user.id).forEach(add);
    return Array.from(latest.values()).sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
  }

  /**
   * The board as last saved: its columns' HTML and counts. For the feed, its own answer for these filters; otherwise
   * the newest of its feed and the page it came with.
   */
  savedBoard(user, url = null) {
    const scope = 'u:' + user.id;
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    const feed = url ? this.cache.get(scope, 'data', url.href) : this.cache.get(scope, 'data', this.origin + FEED, { pathOnly: true });
    const value = feed && read(feed);
    const fromFeed = value && typeof (value.html ?? value.view) === 'string'
      ? { html: value.html ?? value.view, counts: value.counts || {}, payload: value, storedAt: feed.storedAt } : null;
    if (url && fromFeed) return fromFeed;
    const page = this.savedPage(scope);
    if (!fromFeed) return page;
    return page && Date.parse(page.storedAt) > Date.parse(fromFeed.storedAt) ? page : fromFeed;
  }

  savedPage(scope) {
    for (const kind of ['page', 'spa']) {
      const copy = this.cache.get(scope, kind, this.origin + BOARD, { pathOnly: true });
      if (!copy) continue;
      const page = copy.body.toString('utf8');
      const first = page.indexOf('<section class="ob-column');
      if (first < 0) continue;
      let end = first;
      for (let at = first; at >= 0 && at < page.length;) {
        const close = elementEnd(page, at, 'section');
        if (close < 0) break;
        end = close;
        const next = page.slice(close).search(/\S/);
        if (next < 0 || !page.startsWith('<section class="ob-column', close + next)) break;
        at = close + next;
      }
      const counts = {};
      for (const found of page.slice(first, end).matchAll(/data-board-count="([a-z]+)">(\d+)</g)) counts[found[1]] = Number(found[2]);
      return { html: page.slice(first, end), counts, payload: null, storedAt: copy.storedAt };
    }
    return null;
  }

  /** GET /admin/getOrders offline: the saved feed with this device's actions on it; alerts stop for orders acted on here. */
  feed({ user, url }) {
    const acted = new Set(this.store.pendingBoardCommands(user.id).map((command) => command.source + ':' + command.order_id));
    if (url.searchParams.get('alerts_only') === '1' || url.searchParams.get('alerts_only') === 'true') {
      const copy = this.cache.get('u:' + user.id, 'data', url.href);
      if (!copy) return null;
      let value;
      try { value = JSON.parse(copy.body.toString('utf8')); } catch { return null; }
      if (!value || !Array.isArray(value.pending_alert_keys)) return null;
      return { status: 200, json: { ...value, pending_alert_keys: value.pending_alert_keys.filter((key) => !acted.has(key)) } };
    }
    const saved = this.savedBoard(user, url);
    if (!saved) return null;
    const { html, moved } = applyCommands(saved.html, this.commands(user, saved.storedAt));
    const counts = { ...saved.counts };
    for (const group of GROUPS) if (Number.isFinite(counts[group])) counts[group] = Math.max(0, counts[group] + moved[group]);
    const payload = saved.payload || {};
    const keys = Array.isArray(payload.pending_alert_keys) ? payload.pending_alert_keys.filter((key) => !acted.has(key)) : undefined;
    return { status: 200, json: { ...payload, html, view: html, counts, device_copy: true, ...(keys ? { pending_alert_keys: keys } : {}) } };
  }

  /** The board's page from the device's copy (saved at storedAt), with this device's actions on its cards. */
  overlay(user, url, body, storedAt = null) {
    if (url.pathname.replace(/\/+$/, '') !== BOARD) return body;
    const page = body.toString('utf8');
    const commands = this.commands(user, storedAt);
    if (!commands.length || !page.includes('data-column-list=')) return body;
    const { html, moved } = applyCommands(page, commands);
    const counted = html.replace(/(data-board-count="([a-z]+)">)(\d+)(<)/g, (all, open, group, value, close) =>
      open + Math.max(0, Number(value) + (moved[group] || 0)) + close);
    return Buffer.from(counted);
  }

  /** POST /admin/order-board/{source}/{id}/action offline: recorded here, sent to the dashboard with the sync. */
  action({ user, body }, source, orderId) {
    const values = body || {};
    const action = String(values.action || '');
    if (!ACTIONS.includes(action)) throw new Reply(422, 'الإجراء غير معروف.');
    const key = source + ':' + orderId;
    const earlier = this.store.boardOverlay(user.id).get(key);
    const saved = earlier ? null : this.savedBoard(user);
    const found = earlier ? { html: earlier.card, group: earlier.to_group } : saved && findCard(saved.html, key);
    if (!found) throw new Reply(409, 'الطلب ده مش في نسخة الجهاز من الصفحة. هيظهر أول ما النت يرجع.');
    const state = cardState(found.html);
    if (!state.actions.includes(action)) throw new Reply(409, 'تغيرت حالة الطلب أو الإجراء غير متاح. حدّث الصفحة.');
    // Exactly what the page sent (its checks of the order's state included), as the dashboard will receive it.
    const fields = {};
    for (const [name, value] of Object.entries(values)) {
      if (name === '_token' || name === '__files' || name === '_desktop_command' || typeof value !== 'string') continue;
      fields[name] = value;
    }
    fields.action = action;
    const id = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(fields.idempotency_key || '') ? fields.idempotency_key : this.uuid();
    fields.idempotency_key = id;
    const next = nextCard(found.html, source, action, orderId);
    const group = next.group || found.group;
    this.store.recordBoardCommand(user, { id, source, order_id: orderId, order_number: state.number, action, fields, card: next.html,
      from_group: found.group, to_group: group });
    return { status: 200, json: { success: true, device_copy: true,
      message: action === 'cancel' ? 'اتلغى على الجهاز، وهيتبعت للداشبورد أول ما النت يرجع.' : 'اتسجل على الجهاز، وهيتبعت للداشبورد أول ما النت يرجع.',
      card: { key, from_group: found.group, group, html: next.html } } };
  }

  /** The print pages of the orders on the saved board that «مندوب الفرع» may print offline (saved while online). */
  printPages(user, limit = 30) {
    const saved = this.savedBoard(user);
    if (!saved) return [];
    const found = new Set();
    for (const match of saved.html.matchAll(/\/admin\/order-board\/legacy\/([1-9][0-9]{0,18})\/print/g)) {
      found.add(this.origin + '/admin/order-board/legacy/' + match[1] + '/print');
      if (found.size >= limit) break;
    }
    return Array.from(found);
  }
}

/**
 * Runs in the signed-in dashboard: sends the actions taken offline to the board's action, one after another, as the
 * page itself does; stops at the first that cannot be answered now (no internet, an expired session) to keep their order.
 */
function replayScript(commands) {
  return `(async () => {
    const commands = ${JSON.stringify(commands.map((command) => ({ id: command.id, source: command.source, order_id: command.order_id, fields: command.fields })))};
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta || !meta.content) return [{ retry: true, error: 'session' }];
    const results = [];
    for (const command of commands) {
      const data = new FormData();
      Object.entries(command.fields).forEach(([name, value]) => data.append(name, String(value)));
      let response;
      try {
        response = await fetch('/admin/order-board/' + command.source + '/' + command.order_id + '/action', { method: 'POST', credentials: 'same-origin',
          body: data, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': meta.content } });
      } catch (_) { results.push({ id: command.id, retry: true, error: 'network' }); break; }
      let payload = null;
      try { payload = await response.json(); } catch (_) {}
      if (response.ok && payload && payload.success) { results.push({ id: command.id, done: true }); continue; }
      if ([401, 419, 429].includes(response.status) || response.status >= 500 || response.redirected) {
        results.push({ id: command.id, retry: true, error: 'status ' + response.status }); break;
      }
      results.push({ id: command.id, rejected: true, status: response.status, message: (payload && payload.message) || '' });
    }
    return results;
  })()`;
}

module.exports = { OrdersBoardOffline, replayScript, findCard, cardState, nextCard, applyCommands, LEGACY, BOARD, FEED };
