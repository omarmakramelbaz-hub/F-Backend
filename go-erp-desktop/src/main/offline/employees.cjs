'use strict';
// The dashboard's employees page (الموظفون والرواتب) while the internet is down: an employee's check-in or check-out
// is recorded on the device with the device's (server-corrected) time, under the page's own operation number, and goes
// to the dashboard with the 30-second sync, where the dashboard applies its late and early-leave rules. The list is the
// last one seen online with this device's punches shown on it. Other changes on this page need the internet.
const rules = require('../rules.cjs');

const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

class Reply extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
const json = (body, status = 200) => ({ status, json: body });

const CAIRO = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
/** The dashboard keeps punch times as Cairo wall time (check_in) and UTC (checked_in_at). */
const wall = (iso) => (iso ? CAIRO.format(new Date(iso)) : null);
const utc = (iso) => (iso ? new Date(iso).toISOString().slice(0, 19).replace('T', ' ') : null);

class EmployeesOffline {
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {} }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
  }

  match(method, pathname) {
    if (method === 'POST' && pathname === '/admin/employees/attendance') return { handle: (request) => this.punch(request) };
    if (method === 'GET' && pathname === '/admin/employees/data') return { keepOnline: true, handle: (request) => this.data(request) };
    if (method === 'GET' && pathname === '/admin/branch-operations/recover') return { handle: (request) => this.recover(request) };
    return null;
  }

  async answer(route, request) {
    try {
      return await route.handle(request);
    } catch (error) {
      return json({ message: error && error.message ? error.message : 'حدث خطأ غير متوقع.' }, error && error.status ? error.status : 422);
    }
  }

  user(request) {
    const user = request.user && this.store.user(request.user.id);
    if (!user) throw new Reply(401, 'سجّل الدخول أولًا.');
    return user;
  }

  /** The employee's day as the dashboard shows it (branch_employee_days), from the last sync and this device's punches. */
  day(branch, employeeId, day, base = {}) {
    const state = this.store.attendanceBoard(branch, day).find((item) => item.employee.id === Number(employeeId));
    if (!state || !state.checked_in_at && state.status === 'unrecorded') return null;
    const punches = this.store.journal(branch, day, ['attendance']).filter((entry) => entry.data.employee_id === Number(employeeId));
    const notes = punches.map((entry) => entry.data.notes).filter(Boolean).pop();
    return { ...base, employee_id: Number(employeeId), branch, day, status: state.status, check_in: wall(state.checked_in_at), check_out: wall(state.checked_out_at),
      checked_in_at: utc(state.checked_in_at), checked_out_at: utc(state.checked_out_at), notes: notes ?? base.notes ?? '',
      revision: Number(base.revision || 0) + punches.filter((entry) => entry.sync_status !== 'synced').length,
      device: { pending: state.pending, error: state.error } };
  }

  // ---- POST /admin/employees/attendance ----
  punch({ user: who, body }) {
    const user = this.user({ user: who });
    const values = body && typeof body === 'object' ? body : {};
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch) || !user.branches.includes(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    if (!UUID.test(String(values.idempotency_key || ''))) throw new Reply(422, 'رقم العملية غير صالح.');
    if (!['check_in', 'check_out'].includes(values.action)) {
      throw new Reply(422, 'من غير نت البرنامج بيسجل الحضور والانصراف بس. الغياب والإجازات بيتسجلوا من الداشبورد لما النت يرجع.');
    }
    const today = rules.operatingDay(this.now());
    if (values.day && values.day !== today) throw new Reply(422, 'من غير نت الحضور بيتسجل ليوم التشغيل الحالي بس.');
    const entry = this.store.recordPunch(user, branch, { employee_id: values.employee_id, status: values.status, action: values.action, notes: values.notes },
      { id: values.idempotency_key });
    if (!entry.replayed) this.kick();
    const attendance = this.day(branch, values.employee_id, entry.data.day, { revision: values.expected_revision || 0 });
    return json({ success: true, attendance, replayed: !!entry.replayed });
  }

  // ---- GET /admin/branch-operations/recover: a punch whose answer was lost ----
  recover({ user: who, url }) {
    const user = this.user({ user: who });
    const key = url.searchParams.get('idempotency_key') || '';
    const entry = UUID.test(key) ? this.store.journalEntry(key) : null;
    if (!entry || entry.kind !== 'attendance' || entry.actor_id !== user.id || entry.branch !== (url.searchParams.get('branch') || '')) {
      return json({ success: true, found: false });
    }
    return json({ success: true, found: true, replayed: true, attendance: this.day(entry.branch, entry.data.employee_id, entry.data.day) });
  }

  /** The list embedded in the saved copy of the employees page (the page shows it first, without asking). */
  pageList(scope) {
    for (const kind of ['page', 'spa']) {
      const copy = this.cache.get(scope, kind, this.origin + '/admin/employees', { pathOnly: true });
      if (!copy) continue;
      const found = /<script type="application\/json" id="branch-operations-bootstrap">([\s\S]*?)<\/script>/.exec(copy.body.toString('utf8'));
      try {
        const boot = found && JSON.parse(found[1]);
        if (boot && boot.initial) return boot.initial;
      } catch { /* an unreadable copy is no base */ }
    }
    return null;
  }

  // ---- GET /admin/employees/data: the last list seen online, with this device's punches on it ----
  data({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const scope = 'u:' + user.id;
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    const same = (filters) => ['branch', 'day', 'month'].every((key) => !query.get(key) || String(filters[key] || '') === query.get(key));
    let base = null;
    const exact = this.cache.get(scope, 'data', url.href);
    if (exact) base = read(exact);
    if (!base) {
      const latest = this.cache.get(scope, 'data', url.href, { pathOnly: true });
      const seen = latest && read(latest);
      if (seen && seen.filters && same(seen.filters)) base = seen;
    }
    if (!base) {
      const initial = this.pageList(scope);
      if (initial && initial.filters && same(initial.filters)) base = initial;
    }
    if (!base || !Array.isArray(base.items)) throw new Reply(503, 'القايمة دي محتاجة إنترنت. قايمة اليوم المحفوظة على الجهاز شغالة، والحضور والانصراف بيتسجلوا عادي.');
    const day = (base.filters && base.filters.day) || rules.operatingDay(this.now());
    base.items = base.items.map((item) => {
      if (!item || !user.branches.includes(item.branch)) return item;
      const punches = this.store.journal(item.branch, day, ['attendance']).filter((entry) => entry.data.employee_id === Number(item.id));
      if (!punches.length) return item;
      return { ...item, attendance: this.day(item.branch, item.id, day, item.attendance || {}) };
    });
    base.device_copy = true;
    return json(base);
  }
}

module.exports = { EmployeesOffline };
