'use strict';
// The dashboard's shift page (تقفيل الوردية) while the internet is down: an owner or admin counts the drawer and closes
// the shift on the device with the page's own form. The closing is saved in the device's journal under the page's
// operation number and goes to the dashboard alone, after everything recorded before it and before anything recorded
// after it; there the dashboard's own close applies (its checks, its report, the branch printer), dated when it was
// closed here. The page shows the shift as last synced, with this device's open orders and unreviewed expenses.
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { branchProblem } = require('./access.cjs');
const { LOCAL_EXPENSE_BASE } = require('./expenses.cjs');

// Closings saved on this device get numbers far above the dashboard's own, so both never mix.
const LOCAL_SHIFT_BASE = 2000000000000;
const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const ONLY_MANAGERS = 'تقفيل الوردية متاح للأونر والأدمن فقط.';
const PENDING_INVOICES = 'برجاء إغلاق الفواتير أولًا.';
const PENDING_EXPENSES = 'لا يمكن إغلاق الوردية قبل تسوية المصروفات المعلقة باعتمادها أو رفضها.';
const NEEDS_UPDATE = 'تقفيل الوردية من غير نت يحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.';
const NO_COPY = 'بيانات الوردية للفرع ده لسه ما اتنزلتش على الجهاز. اتصل بالإنترنت مرة واحدة للمزامنة.';
const WAITING = 'فيه قفلة وردية للفرع ده محفوظة على الجهاز ولسه ما وصلتش للداشبورد. القفلة الجاية بعد ما توصل.';
const PRINTS_LATER = 'القفلة محفوظة على الجهاز، وتقريرها الكامل هيتطبع على طابعة الفرع أول ما توصل للداشبورد.';
const CAIRO = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
// BranchShiftClosing::pendingStatus for dine-in and delivery orders.
const STATUS = { open: 'مفتوحة', new: 'جديد', preparing: 'قيد التجهيز', awaiting_bill: 'بانتظار التحصيل', out_for_delivery: 'مع المندوب',
  finished: 'بانتظار التحصيل' };

class Reply extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
const json = (body, status = 200) => ({ status, json: body });
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function cairo(iso) {
  const parts = Object.fromEntries(CAIRO.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
}

class ShiftsOffline {
  /** services: the offline dine-in and delivery pages (their open orders block a closing, as on the dashboard). */
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {}, services = [] }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
    this.services = services;
  }

  match(method, pathname) {
    if (method === 'GET' && pathname === '/admin/branch-shifts/data') return { keepOnline: true, handle: (request) => this.data(request) };
    if (method === 'POST' && pathname === '/admin/branch-shifts/close') return { handle: (request) => this.close(request) };
    if (method === 'GET' && pathname === '/admin/branch-shifts/recover') return { handle: (request) => this.recover(request) };
    // A closing saved here exists only here: answered by the device, online too.
    if (method === 'POST' && pathname === '/admin/branch-shifts/reprint') return { always: true, handle: (request) => this.reprint(request) };
    const page = /^\/admin\/branch-shifts\/([1-9][0-9]{0,15})\/(details|print)$/.exec(pathname);
    if (method === 'GET' && page && Number(page[1]) > LOCAL_SHIFT_BASE) return { always: true, handle: (request) => this.page(request, Number(page[1]) - LOCAL_SHIFT_BASE) };
    return null;
  }

  async answer(route, request) {
    try {
      return await route.handle(request);
    } catch (error) {
      return json({ success: false, message: error && error.message ? error.message : 'حدث خطأ غير متوقع.' }, error && error.status ? error.status : 422);
    }
  }

  user(request) {
    const user = request.user && this.store.user(request.user.id);
    if (!user) throw new Reply(401, 'سجّل الدخول أولًا.');
    if (!user.can_close_shift) throw new Reply(403, ONLY_MANAGERS);
    return user;
  }

  supports(kind) {
    return (this.store.get('server_kinds', []) || []).includes(kind);
  }

  branchName(value) {
    const known = this.store.branches().find((branch) => branch.value === value);
    return (known && known.name) || value;
  }

  /** The page's data as last read online for this branch, if any. */
  saved(user, branch, url) {
    const scope = 'u:' + user.id;
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    const mine = (value) => value && value.report && value.report.branch && value.report.branch.value === branch;
    for (const copy of [this.cache.get(scope, 'data', url.href), this.cache.get(scope, 'data', this.origin + '/admin/branch-shifts/data', { pathOnly: true })]) {
      const value = copy && read(copy);
      if (mine(value)) return { data: value, savedAt: Date.parse(copy.storedAt) };
    }
    return null;
  }

  /** The device's dine-in and delivery orders still open, as the shift page lists pending invoices. */
  openOrders(user, branch) {
    const name = this.branchName(branch);
    return this.services.flatMap((page) => page.openOrders(user, branch).map((order) => ({ source: page.channel, id: order.id,
      number: order.number || '#' + order.id, label: page.channel === 'dine' ? 'الصالة' : 'الدليفري', status: String(order.status || ''),
      status_label: STATUS[order.status] || 'فاتورة غير مغلقة', table_name: (order.table && order.table.name) || order.table_name || '', branch_name: name })));
  }

  /** Expenses saved here without approval that the dashboard list read online does not have yet. */
  unreviewedExpenses(branch, savedAt) {
    const today = rules.operatingDay(this.now());
    return this.store.deviceExpenses([branch], '2000-01-01', today).filter((entry) => !entry.data.approve && entry.sync_status !== 'rejected'
      && !(entry.sync_status === 'synced' && entry.synced_at && Date.parse(entry.synced_at) <= savedAt))
      .map((entry) => ({ id: LOCAL_EXPENSE_BASE + entry.seq, number: entry.server_ref ? String(entry.server_ref).split(' · ')[0] : `جهاز-${entry.seq}`,
        occurred_on: entry.business_date, description: entry.data.description || '', category: entry.data.category, branch_name: this.branchName(branch) }));
  }

  /** A closing saved here, in the page's list shape (BranchShiftClosing::stub). */
  stub(entry) {
    const id = LOCAL_SHIFT_BASE + entry.seq;
    return { id, number: entry.server_ref || `جهاز-${entry.seq}`, sequence: 0, branch: entry.branch, branch_name: this.branchName(entry.branch),
      started_at: entry.data.started_at || '', closed_at: cairo(entry.created_at), print_url: `${this.origin}/admin/branch-shifts/${id}/print` };
  }

  /** The closings of this device the dashboard does not show yet. */
  waiting(branch, savedAt = 0) {
    return this.store.deviceShiftClosings(branch).filter((entry) => entry.sync_status === 'pending'
      || (entry.sync_status === 'synced' && entry.synced_at && Date.parse(entry.synced_at) > savedAt));
  }

  /** BranchShiftClosing::data for one branch: the synced shift, this device's open orders and unreviewed expenses. */
  state(user, branch, url) {
    if (!BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (!branch.startsWith('f:')) throw new Reply(404, 'الفرع غير موجود.');
    const copy = this.store.service(branch);
    const saved = this.saved(user, branch, url);
    if (!saved && !(copy && copy.shift)) throw new Reply(503, NO_COPY);
    const shift = copy && copy.shift;
    const base = saved ? JSON.parse(JSON.stringify(saved.data)) : { success: true, previous_closing_id: 0, review_token: '0'.repeat(64),
      report: { branch: { value: branch, name: this.branchName(branch) }, pending_expenses: 0, unsettled_app_cash: 0, pending_expense_items: [],
        pending_expenses_url: `${this.origin}/admin/branch-expenses?branch=${encodeURIComponent(branch)}&status=pending` }, history: [] };
    // What the device's own records are added to: the synced shift, or the page read online after it.
    let asOf = saved ? saved.savedAt : 0;
    if (shift && (!saved || Date.parse(copy.received_at || 0) >= asOf)) {
      asOf = Date.parse(copy.received_at || 0) || 0;
      base.previous_closing_id = shift.previous_closing_id;
      base.report.started_at = shift.started_at;
      base.history = shift.history.map((closing) => ({ ...closing, branch_name: this.branchName(branch) }));
      if (Array.isArray(shift.pending_expenses)) {
        base.report.pending_expenses = shift.pending_expenses.length;
        base.report.pending_expense_items = shift.pending_expenses.map((item) => ({ ...item, branch_name: this.branchName(branch) }));
      }
    }
    base.report.closed_at = cairo(this.now().toISOString());
    // Open invoices: this device's dine-in and delivery orders; the app's orders as last read online.
    const app = ((base.report.pending_invoices && base.report.pending_invoices.items) || []).filter((item) => item.source === 'app');
    const orders = this.openOrders(user, branch);
    const counts = { dine: orders.filter((item) => item.source === 'dine').length, phone: orders.filter((item) => item.source === 'phone').length,
      app: app.length };
    base.report.pending_invoices = { total: counts.dine + counts.phone + counts.app, counts, items: orders.concat(app) };
    const expenses = this.unreviewedExpenses(branch, asOf);
    base.report.pending_expenses = Number(base.report.pending_expenses || 0) + expenses.length;
    base.report.pending_expense_items = expenses.concat(base.report.pending_expense_items || []);
    base.history = this.waiting(branch, asOf).reverse().map((entry) => this.stub(entry)).concat(base.history || []).slice(0, 30);
    base.device_copy = true;
    return base;
  }

  // ---- GET /admin/branch-shifts/data ----
  data({ user: who, url }) {
    const user = this.user({ user: who });
    const branch = url.searchParams.get('branch') || '';
    if (branch === 'all') {
      // Every branch at once is the dashboard's own view; offline the last copy is shown as it was.
      const copy = this.cache.get('u:' + user.id, 'data', url.href);
      if (!copy) throw new Reply(503, 'عرض كل الفروع محتاج إنترنت. اختر الفرع لإغلاق ورديته من الجهاز.');
      return json({ ...JSON.parse(copy.body.toString('utf8')), device_copy: true });
    }
    return json(this.state(user, branch, url));
  }

  // ---- POST /admin/branch-shifts/close ----
  close({ user: who, body, url }) {
    const user = this.user({ user: who });
    const values = body && typeof body === 'object' ? body : {};
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    const key = String(values.idempotency_key || '');
    if (!UUID.test(key)) throw new Reply(422, 'رقم العملية غير صالح.');
    const done = this.store.journalEntry(key);
    if (done) {
      if (done.kind !== 'shift' || done.actor_id !== user.id || done.branch !== branch) throw new Reply(409, 'رقم الإغلاق مستخدم لبيانات مختلفة.');
      return json({ success: true, replayed: true, closing: this.stub(done) });
    }
    if (!this.supports('shift_close')) throw new Reply(422, NEEDS_UPDATE);
    const text = String(values.counted_cash ?? '').trim();
    if (!/^[0-9]{1,9}(\.[0-9]{1,2})?$/.test(text)) throw new Reply(422, 'أدخل الكاش الفعلي بقيمة صحيحة.');
    const counted = money.minor(text);
    if (counted < 0 || counted > 100000000000) throw new Reply(422, 'أدخل الكاش الفعلي بقيمة صحيحة.');
    const notes = String(values.notes ?? '').trim();
    if (notes.length > 1000) throw new Reply(422, 'الملاحظات طويلة جدًا.');
    const state = this.state(user, branch, url || new URL(this.origin + '/admin/branch-shifts/data?branch=' + encodeURIComponent(branch)));
    if (this.waiting(branch, Infinity).some((entry) => entry.sync_status === 'pending')) throw new Reply(409, WAITING);
    if (Number(values.previous_closing_id) !== Number(state.previous_closing_id)) {
      throw new Reply(409, 'تم إغلاق وردية من جهاز آخر. حدّث الصفحة وراجع الفترة الجديدة.');
    }
    if (state.report.pending_invoices.total > 0) throw new Reply(409, PENDING_INVOICES);
    if (state.report.pending_expenses > 0) throw new Reply(409, PENDING_EXPENSES);
    const entry = this.store.recordShiftClose(user, branch, { previous_closing_id: state.previous_closing_id, counted_cash: money.decimal(counted), notes,
      started_at: state.report.started_at }, { id: key });
    if (!entry.replayed) this.kick();
    return json({ success: true, replayed: !!entry.replayed, closing: this.stub(entry) });
  }

  // ---- GET /admin/branch-shifts/recover: a closing whose answer was lost ----
  recover({ user: who, url }) {
    const user = this.user({ user: who });
    const key = url.searchParams.get('idempotency_key') || '';
    const entry = UUID.test(key) ? this.store.journalEntry(key) : null;
    if (!entry || entry.kind !== 'shift' || entry.actor_id !== user.id || entry.branch !== (url.searchParams.get('branch') || '')) {
      return json({ success: true, found: false, closing: null });
    }
    return json({ success: true, found: true, closing: this.stub(entry) });
  }

  // ---- POST /admin/branch-shifts/reprint: a closing saved here is printed once it reaches the dashboard ----
  reprint({ user: who, body }) {
    const id = Number(body && body.closing_id);
    if (!(id > LOCAL_SHIFT_BASE)) return null;
    this.user({ user: who });
    throw new Reply(409, PRINTS_LATER);
  }

  // ---- GET /admin/branch-shifts/{id}/details|print for a closing saved here ----
  page({ user: who }, seq) {
    const user = this.user({ user: who });
    const entry = this.store.journalBySeq(seq);
    if (!entry || entry.kind !== 'shift' || !user.branches.includes(entry.branch)) throw new Reply(404, 'القفلة غير موجودة على هذا الجهاز.');
    const closing = this.stub(entry);
    const state = entry.sync_status === 'synced' ? 'وصلت للداشبورد' + (entry.server_ref ? ` (${escape(entry.server_ref)})` : '')
      : entry.sync_status === 'rejected' ? 'رفضها الداشبورد: ' + escape(entry.sync_error || '') : 'محفوظة على الجهاز ولسه ما وصلتش للداشبورد';
    const rows = [['الفرع', closing.branch_name], ['بداية الوردية', closing.started_at], ['القفلة', closing.closed_at],
      ['الكاش الفعلي', entry.data.counted_cash], ['ملاحظات', entry.data.notes || '—'], ['بواسطة', entry.actor_name]];
    return { status: 200, html: `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قفلة الوردية ${escape(closing.number)}</title>
<style>body{font-family:Tahoma,Arial,sans-serif;margin:16px;color:#1f2937}h1{font-size:18px;margin:0 0 12px}table{border-collapse:collapse;width:100%;max-width:420px}
td{border-bottom:1px solid #e5e7eb;padding:6px 4px}td:first-child{color:#6b7280;width:40%}.note{margin-top:12px;color:#92400e;background:#fffbeb;padding:8px;border-radius:6px}</style>
</head><body><h1>قفلة الوردية ${escape(closing.number)}</h1><table>${rows.map(([label, value]) => `<tr><td>${escape(label)}</td><td>${escape(value)}</td></tr>`).join('')}</table>
<p class="note">${state}. ${escape(PRINTS_LATER)}</p></body></html>` };
  }
}

module.exports = { ShiftsOffline, LOCAL_SHIFT_BASE };
