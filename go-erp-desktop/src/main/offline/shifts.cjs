'use strict';
// The dashboard's shift page (تقفيل الوردية) while the internet is down: an owner or admin counts the drawer and closes
// the shift on the device with the page's own form. The closing is saved in the device's journal under the page's
// operation number and goes to the dashboard alone, after everything recorded before it and before anything recorded
// after it; there the dashboard's own close applies (its checks, its report, the branch printer), dated when it was
// closed here. The page shows the shift as last synced, with this device's open orders and unreviewed expenses. Its
// report (the dashboard's closing invoice) is shown and printed from the device until the dashboard's own arrives: the
// open shift's figures from the last sync, with this device's sales, expenses and drawer moves after them.
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
const DEVICE_REPORT = 'تقرير الجهاز: أرقام الوردية لحد آخر مزامنة مع الداشبورد (%s)، ومعاها عمليات الجهاز ده بعدها. التقرير النهائي بيتحسب على الداشبورد ويتطبع على طابعة الفرع أول ما القفلة توصل.';
const DEVICE_ONLY = 'تقرير الجهاز: عمليات الجهاز ده في الوردية بس. النقدية أول الوردية والمتوقع والفرق بيظهروا في التقرير النهائي على الداشبورد أول ما القفلة توصل.';
const CHANNELS = [['dine', 'الصالة'], ['takeaway', 'التيك أواي'], ['phone', 'الدليفري'], ['app', 'التطبيق']];
const CASH_ROWS = [['opening_cash', 'النقدية أول الوردية'], ['cash_sales', 'تحصيل نقدي من نقاط البيع'], ['app_branch_cash', 'تحصيل التطبيق النقدي بالفرع'],
  ['cash_delivery', 'يخصم: توصيل محصل نقدًا'], ['cash_expenses', 'يخصم: مصروفات نقدية'], ['other_cash_movements', 'إيداعات / سحوبات أخرى']];
// resources/views/admin/branch_shifts/receipt.blade.php
const RECEIPT_CSS = '*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#000;font:10px/1.35 Tahoma,Arial,sans-serif}.shift-paper{width:100%;max-width:80mm;padding:2mm;margin:0 auto;overflow-wrap:anywhere}h1{font-size:15px;text-align:center;margin:3px 0 6px}h2{font-size:11px;margin:8px 0 3px}p{margin:3px 0;line-height:1.45}bdi{direction:ltr;unicode-bidi:isolate;white-space:nowrap;font-variant-numeric:tabular-nums}table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:9px;margin:7px 0}th,td{padding:3px 1px;border-bottom:1px dashed #999;text-align:right;vertical-align:top}td:last-child,th:last-child{text-align:left;white-space:nowrap}tr{break-inside:avoid;page-break-inside:avoid}.total td{font-weight:bold;border-top:1px solid #000}.cash-box{border:1px solid #000;padding:5px;margin-top:7px;break-inside:avoid;page-break-inside:avoid}.cash-box div{display:flex;justify-content:space-between;align-items:baseline;gap:4px;padding:4px 0}.cash-box strong{white-space:nowrap}.cash-box div:last-child{font-size:11px;font-weight:bold;border-top:1px dashed #777}.muted{font-size:8px;color:#222}.screen-notice{text-align:center;margin:30px}@page{size:auto;margin:0}@media screen{.shift-paper{display:none}}@media print{html,body{width:100%}.screen-notice{display:none}.shift-paper{display:block}}';
const PREVIEW_CSS = '@media screen{html,body{background:#f5f6f8}.screen-notice{font-size:12px;margin:12px;color:#4b5563}.shift-paper{display:block;background:#fff;max-width:95mm;padding:5mm;margin:0 auto 12px;box-shadow:0 1px 8px #00000014}}';
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
// Blade's e(): the closing invoice is the dashboard's page, character for character.
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));

function cairo(iso) {
  const parts = Object.fromEntries(CAIRO.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
}

/** The moment of a Cairo wall time ('Y-m-d H:i:s', as the dashboard shows times), in milliseconds. */
function cairoMoment(wall) {
  const match = /^([0-9]{4}-[0-9]{2}-[0-9]{2}) ([0-9]{2}:[0-9]{2}:[0-9]{2})$/.exec(String(wall || ''));
  if (!match) return null;
  const asUtc = Date.parse(`${match[1]}T${match[2]}Z`);
  for (const hours of [2, 3]) {
    const moment = asUtc - hours * 3600 * 1000;
    if (cairo(new Date(moment).toISOString()) === wall) return moment;
  }
  return asUtc - 2 * 3600 * 1000;
}

const cents = (value) => { try { return money.minor(String(value ?? '0')); } catch { return 0; } };

/** The dashboard's closing invoice (receipt.blade.php): its preview, or its print page. Unknown figures show as «—». */
function renderShiftReceipt(report, { preview = false } = {}) {
  const value = (amount) => `<bdi>${escape(amount === null || amount === undefined ? '—' : amount)}</bdi>`;
  const channels = report.channels || {};
  let html = `<!doctype html><html lang="ar" dir="rtl"\n${preview ? ' data-dashboard-invoice-details="1"' : ' data-dashboard-receipt="branch-shift"'}\n><head><meta charset="utf-8">\n`;
  if (!preview) html += '<meta name="dashboard-print-document" content="receipt">\n';
  html += `<title>قفلة الوردية ${escape(report.number)}</title><style>\n${RECEIPT_CSS}\n${preview ? PREVIEW_CSS + '\n' : ''}</style></head><body><p class="screen-notice">\n`;
  html += preview ? 'معاينة فاتورة الإغلاق المحفوظة — للأونر والأدمن فقط.\n' : ' القفلة محفوظة وجاهزة للطباعة. تظهر الأرصدة في الورقة المطبوعة فقط.\n';
  html += `</p><article class="shift-paper"><h1>قفلة الوردية</h1><p><strong>${escape(report.branch && report.branch.name)}</strong></p>`;
  html += `<p>${escape(report.number)} · وردية ${escape(report.sequence ?? '—')}</p><p>الكاشير: ${escape(report.cashier && report.cashier.name)}</p>`;
  html += `<p>من: <bdi>${escape(report.started_at)}</bdi></p><p>إلى: <bdi>${escape(report.closed_at)}</bdi></p>\n`;
  html += '<table><colgroup><col style="width:42%"><col style="width:16%"><col style="width:42%"></colgroup><thead><tr><th>القناة</th><th>عدد</th><th>المبيعات</th></tr></thead><tbody>\n';
  for (const [key, label] of CHANNELS) {
    const channel = channels[key] || { count: 0, gross: '0.00' };
    html += `<tr><td>${label}</td><td>${escape(channel.count)}</td><td>${value(channel.gross)}</td></tr>\n`;
  }
  html += `<tr class="total"><td colspan="2">إجمالي المبيعات</td><td>${value(report.sales_total)}</td></tr><tr><td colspan="2">يخصم: خدمة التوصيل</td><td>${value(report.delivery_total)}</td></tr>`;
  html += `<tr><td colspan="2">يخصم: صافي المصروفات المعتمدة</td><td>${value(report.expenses_total)}</td></tr><tr class="total"><td colspan="2">صافي المبيعات بعد الخصم</td><td>${value(report.net_sales)}</td></tr></tbody></table>\n`;
  html += '<h2>حساب نقدية الفرع</h2><table><colgroup><col style="width:60%"><col style="width:40%"></colgroup><tbody>\n';
  for (const [key, label] of CASH_ROWS) html += `<tr><td>${label}</td><td>${value(report[key])}</td></tr>\n`;
  html += '</tbody></table>\n';
  html += `<div class="cash-box"><div><span>الكاش الفعلي</span><strong>${value(report.counted_cash)} ج</strong></div><div><span>النقدية</span><strong>${value(report.expected_cash)} ج</strong></div>`;
  html += `<div><span>الفرق — ${escape(report.variance_label || '—')}</span><strong>${value(report.variance)} ج</strong></div></div>\n`;
  html += `<p class="muted">مدفوعات نقاط البيع غير النقدية: ${value(report.noncash_sales)} ج</p><p class="muted">مبيعات التطبيق المحصلة خارج الدرج بعد التوصيل: ${value(report.app_outside_drawer)} ج</p>\n`;
  if (report.pending_expenses) html += `<p>مصروفات غير معتمدة لم تخصم: ${escape(report.pending_expenses)}</p>\n`;
  if (report.unsettled_app_cash) html += `<p>طلبات تطبيق نقدية لم تثبت تسويتها: ${escape(report.unsettled_app_cash)}</p>\n`;
  if (report.notes) html += `<p>ملاحظات: ${escape(report.notes)}</p>\n`;
  if (report.drawer_preserved) html += '<p>إغلاق للمراجعة فقط — رصيد الدرج مستمر دون تصفير.</p>\n';
  if (report.device_note) html += `<p class="muted">${escape(report.device_note)}</p>\n`;
  html += '<p class="muted">القفلة سجل ثابت للعدّ, وليست إيصال سحب أو تحويل. جميع القيم بالجنيه المصري.</p><p>توقيع الكاشير: ........................</p></article></body></html>';
  return html;
}

class ShiftsOffline {
  /** services: the offline dine-in and delivery pages (their open orders block a closing, as on the dashboard). */
  /** print(html): prints a page on this device's receipt printer (or with the print window), resolving to { ok, error }. */
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {}, services = [], print = null }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
    this.services = services;
    this.print = print;
  }

  match(method, pathname) {
    if (method === 'GET' && pathname === '/admin/branch-shifts/data') return { keepOnline: true, handle: (request) => this.data(request) };
    if (method === 'POST' && pathname === '/admin/branch-shifts/close') return { handle: (request) => this.close(request) };
    if (method === 'GET' && pathname === '/admin/branch-shifts/recover') return { handle: (request) => this.recover(request) };
    // A closing saved here exists only here: answered by the device, online too.
    if (method === 'POST' && pathname === '/admin/branch-shifts/reprint') return { always: true, handle: (request) => this.reprint(request) };
    const page = /^\/admin\/branch-shifts\/([1-9][0-9]{0,15})\/(details|print)$/.exec(pathname);
    if (method === 'GET' && page && Number(page[1]) > LOCAL_SHIFT_BASE) return { always: true, handle: (request) => this.page(request, Number(page[1]) - LOCAL_SHIFT_BASE, page[2]) };
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
      started_at: state.report.started_at, report: this.closingReport(user, branch, state, counted, notes) }, { id: key });
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

  /**
   * BranchShiftClosing::build for a closing made here: the open shift's figures from the last sync (the dashboard's own
   * report up to then) with this device's sales (cash offline), approved expenses and drawer moves the dashboard did not
   * count yet. Without those figures (a server before them, or a shift closed elsewhere since) it counts this device's
   * records alone and leaves the drawer's figures to the dashboard's report.
   */
  closingReport(user, branch, state, counted, notes) {
    const copy = this.store.running(branch);
    const figures = copy && copy.report && Number(copy.report.previous_closing_id) === Number(state.previous_closing_id) ? copy.report : null;
    const startedAt = String(state.report.started_at || (figures && figures.started_at) || '');
    const start = cairoMoment(startedAt);
    const records = this.store.shiftRecords(branch, new Date(start === null ? 0 : start).toISOString(), figures ? copy.received_at : null);
    const channels = {};
    for (const [key] of CHANNELS) {
      const known = figures && figures.channels && figures.channels[key];
      channels[key] = { count: known ? Number(known.count) || 0 : 0, gross: known ? cents(known.gross) : 0, delivery: known ? cents(known.delivery) : 0 };
    }
    const base = (key) => (figures ? cents(figures[key]) : 0);
    let cashSales = base('cash_sales');
    let cashDelivery = base('cash_delivery');
    let expenses = base('expenses_total');
    let cashExpenses = base('cash_expenses');
    let other = base('other_cash_movements');
    let expected = figures ? cents(figures.expected_cash) : null;
    for (const sale of records.sales) {
      const kind = ['dine', 'phone'].includes(sale.channel) ? sale.channel : 'takeaway';
      const total = Number(sale.totals.total_cents) || 0;
      const delivery = Number(sale.totals.delivery_cents) || 0;
      channels[kind].count += 1;
      channels[kind].gross += total;
      channels[kind].delivery += delivery;
      cashSales += total;
      cashDelivery += delivery;
      if (expected !== null) expected += total - delivery;
    }
    for (const expense of records.expenses) {
      expenses += expense.amount_cents;
      cashExpenses += expense.amount_cents;
      if (expected !== null) expected -= expense.amount_cents;
    }
    for (const move of records.till) {
      const signed = move.data.direction === 'out' ? -move.amount_cents : move.amount_cents;
      other += signed;
      if (expected !== null) expected += signed;
    }
    const gross = Object.values(channels).reduce((sum, item) => sum + item.gross, 0);
    const delivery = Object.values(channels).reduce((sum, item) => sum + item.delivery, 0);
    const previous = (state.history || []).find((item) => Number(item.id) === Number(state.previous_closing_id) && Number(item.sequence) > 0);
    const known = (field, fallback = null) => (figures && figures[field] !== undefined ? figures[field] : fallback);
    return { complete: !!figures, figures_at: figures ? cairo(copy.received_at) : null,
      sequence: previous ? Number(previous.sequence) + 1 : Number(state.previous_closing_id) === 0 ? 1 : null,
      branch: { value: branch, name: this.branchName(branch) }, cashier: { id: user.id, name: user.name }, started_at: startedAt,
      channels: Object.fromEntries(Object.entries(channels).map(([key, item]) => [key, { count: item.count, gross: money.decimal(item.gross),
        delivery: money.decimal(item.delivery), net: money.decimal(item.gross - item.delivery) }])),
      sales_total: money.decimal(gross), delivery_total: money.decimal(delivery), expenses_total: money.decimal(expenses),
      net_sales: money.decimal(gross - delivery - expenses), opening_cash: known('opening_cash'), cash_sales: money.decimal(cashSales),
      noncash_sales: known('noncash_sales', '0.00'), app_branch_cash: known('app_branch_cash'), app_outside_drawer: known('app_outside_drawer'),
      cash_delivery: money.decimal(cashDelivery), cash_expenses: money.decimal(cashExpenses), other_cash_movements: money.decimal(other),
      expected_cash: expected === null ? null : money.decimal(expected), counted_cash: money.decimal(counted),
      variance: expected === null ? null : money.decimal(counted - expected),
      variance_label: expected === null ? '' : counted === expected ? 'مطابق' : counted > expected ? 'زيادة' : 'عجز',
      pending_expenses: 0, unsettled_app_cash: Number(known('unsettled_app_cash', state.report.unsettled_app_cash)) || 0, notes, drawer_preserved: true };
  }

  /** The report of a closing saved here, with its number and times. */
  receiptOf(entry) {
    const closing = this.stub(entry);
    const report = entry.data.report || { branch: { value: entry.branch, name: closing.branch_name }, cashier: { id: entry.actor_id, name: entry.actor_name },
      counted_cash: entry.data.counted_cash, notes: entry.data.notes || '', complete: false, drawer_preserved: true };
    const state = entry.sync_status === 'synced' ? `وصلت للداشبورد${entry.server_ref ? ` (${entry.server_ref})` : ''}، وتقريرها النهائي هناك.`
      : entry.sync_status === 'rejected' ? `الداشبورد رفضها: ${entry.sync_error || ''}` : '';
    const note = report.complete ? DEVICE_REPORT.replace('%s', report.figures_at || '') : DEVICE_ONLY;
    return { ...report, number: closing.number, started_at: closing.started_at || report.started_at || '', closed_at: closing.closed_at,
      device_note: [note, state].filter(Boolean).join(' ') };
  }

  // ---- POST /admin/branch-shifts/reprint: a closing saved here prints its device report on this device's printer ----
  async reprint({ user: who, body }) {
    const id = Number(body && body.closing_id);
    if (!(id > LOCAL_SHIFT_BASE)) return null;
    const user = this.user({ user: who });
    const entry = this.store.journalBySeq(id - LOCAL_SHIFT_BASE);
    if (!entry || entry.kind !== 'shift' || !user.branches.includes(entry.branch)) throw new Reply(404, 'القفلة غير موجودة على هذا الجهاز.');
    if (!this.print) throw new Reply(409, PRINTS_LATER);
    const printed = await this.print(renderShiftReceipt(this.receiptOf(entry), { preview: false }));
    if (printed && !printed.ok && printed.error) throw new Reply(422, printed.error);
    return json({ success: true, printed: !!(printed && printed.ok), message: 'اتطبع تقرير الجهاز للقفلة على طابعة الجهاز.' });
  }

  // ---- GET /admin/branch-shifts/{id}/details|print for a closing saved here: the dashboard's closing invoice ----
  page({ user: who }, seq, kind = 'details') {
    const user = this.user({ user: who });
    const entry = this.store.journalBySeq(seq);
    if (!entry || entry.kind !== 'shift' || !user.branches.includes(entry.branch)) throw new Reply(404, 'القفلة غير موجودة على هذا الجهاز.');
    return { status: 200, html: renderShiftReceipt(this.receiptOf(entry), { preview: kind === 'details' }) };
  }
}

module.exports = { ShiftsOffline, LOCAL_SHIFT_BASE, renderShiftReceipt, cairoMoment };
