'use strict';
// The dashboard's employees page (الموظفون والرواتب) while the internet is down: an employee's check-in or check-out,
// or the day's leave or absence, is recorded on the device with the device's (server-corrected) time, under the page's
// own operation number, and goes to the dashboard with the 30-second sync, where the dashboard applies its late,
// early-leave, absence and leave rules. Deductions, bonuses (the owner's) and advances are recorded the same way. The
// list is the last one seen online with this device's records shown on it; another branch, a search or the new operating
// day is built from the saved lists and the employees the sync brought. Other changes on this page need the internet.
const rules = require('../rules.cjs');
const money = require('../money.cjs');
const { branchProblem } = require('./access.cjs');

const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const DAY = /^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/;
const KINDS = ['bonus', 'deduction', 'advance'];
/** BranchPayroll::canVoidEntries: the installation's owner (account 1) alone gives bonuses. */
const owner = (user) => user.id === 1 && user.account_type === 'admin';

class Reply extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
const json = (body, status = 200) => ({ status, json: body });
const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };

const CAIRO = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
/** The dashboard keeps punch times as Cairo wall time (check_in) and UTC (checked_in_at). */
const wall = (iso) => (iso ? CAIRO.format(new Date(iso)) : null);
const utc = (iso) => (iso ? new Date(iso).toISOString().slice(0, 19).replace('T', ' ') : null);

class EmployeesOffline {
  /** recoverers: other pages that check a lost answer through the same address (e.g. goods received). */
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {}, recoverers = [] }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
    this.recoverers = recoverers;
  }

  match(method, pathname) {
    if (method === 'POST' && pathname === '/admin/employees/attendance') return { handle: (request) => this.punch(request) };
    if (method === 'GET' && pathname === '/admin/employees/data') return { keepOnline: true, handle: (request) => this.data(request) };
    if (method === 'POST' && pathname === '/admin/employees/entry') return { handle: (request) => this.entry(request) };
    if (method === 'GET' && pathname === '/admin/employees/entries') return { keepOnline: true, handle: (request) => this.entries(request) };
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
    if (!BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (!UUID.test(String(values.idempotency_key || ''))) throw new Reply(422, 'رقم العملية غير صالح.');
    if (!['check_in', 'check_out', 'set_status'].includes(values.action)) throw new Reply(422, 'اختر تسجيل الحضور أو الانصراف.');
    if (values.action === 'set_status' && !(this.store.get('server_kinds', []) || []).includes('attendance_status')) {
      throw new Reply(422, 'تسجيل الغياب والإجازة من غير نت يحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.');
    }
    const today = rules.operatingDay(this.now());
    if (values.day && values.day !== today) throw new Reply(422, 'من غير نت الحضور بيتسجل ليوم التشغيل الحالي بس.');
    const entry = this.store.recordPunch(user, branch, { employee_id: values.employee_id, status: values.status, action: values.action, notes: values.notes },
      { id: values.idempotency_key });
    if (!entry.replayed) this.kick();
    const attendance = this.day(branch, values.employee_id, entry.data.day, { revision: values.expected_revision || 0 });
    return json({ success: true, attendance, replayed: !!entry.replayed });
  }

  // ---- POST /admin/employees/entry: a deduction, bonus or advance (BranchPayroll::entry) ----
  entry({ user: who, body }) {
    const user = this.user({ user: who });
    const values = body && typeof body === 'object' ? body : {};
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (!UUID.test(String(values.idempotency_key || ''))) throw new Reply(422, 'رقم العملية غير صالح.');
    // A resend of one saved here is the same entry.
    if (this.store.journalEntry(values.idempotency_key)) {
      const again = this.store.recordEmployeeEntry(user, branch, {}, { id: values.idempotency_key });
      return json({ success: true, entry: this.present(again), replayed: true });
    }
    if (!(this.store.get('server_kinds', []) || []).includes('employee_entry')) {
      throw new Reply(422, 'تسجيل الخصومات والمكافآت والسلف من غير نت يحتاج تحديث السرفر، واتصالًا بالإنترنت مرة واحدة بعده.');
    }
    const kind = String(values.kind || '');
    if (!KINDS.includes(kind)) throw new Reply(422, 'نوع الحركة غير صالح.');
    if (kind === 'bonus' && !owner(user)) throw new Reply(403, 'إضافة المكافآت متاحة للأونر فقط.');
    if (!user.can_checkout) throw new Reply(403, 'لا تملك صلاحية تنفيذ العملية.');
    const day = String(values.day || '');
    if (!DAY.test(day)) throw new Reply(422, 'التاريخ غير صالح.');
    if (day > rules.operatingDay(this.now())) throw new Reply(422, 'لا يمكن التسجيل ليوم تشغيل مستقبلي.');
    let cents;
    try { cents = money.minor(String(values.amount ?? '')); } catch { throw new Reply(422, 'أدخل مبلغًا صحيحًا بمنزلتين عشريتين.'); }
    if (cents < 1 || cents > 100000000) throw new Reply(422, 'المبلغ خارج الحدود المسموحة.');
    const reason = String(values.reason ?? '').trim();
    if (!reason) throw new Reply(422, 'اكتب سبب الحركة.');
    if (reason.length > 500) throw new Reply(422, 'السبب أطول من المسموح (٥٠٠ حرف).');
    const notes = String(values.notes ?? '');
    if (notes.length > 1000) throw new Reply(422, 'الملاحظات أطول من المسموح (١٠٠٠ حرف).');
    const state = this.store.attendanceBoard(branch, day).find((item) => item.employee.id === Number(values.employee_id));
    if (!state) throw new Reply(422, 'الموظف غير موجود في هذا الفرع. اتصل بالإنترنت لتحديث قائمة الموظفين.');
    if (this.monthClosed(user, branch, state.employee.id, day.slice(0, 7))) throw new Reply(409, 'تم إقفال هذا الشهر. لا يمكن تغيير حركاته.');
    const entry = this.store.recordEmployeeEntry(user, branch, { employee_id: state.employee.id, employee_name: state.employee.name, day, kind,
      amount: String(values.amount), reason, notes }, { id: values.idempotency_key });
    if (!entry.replayed) this.kick();
    return json({ success: true, entry: this.present(entry), replayed: !!entry.replayed });
  }

  /** An entry recorded here, as the dashboard returns its entries. */
  present(entry) {
    const data = entry.data;
    const cents = money.minor(data.amount);
    return { id: null, branch: entry.branch, employee_id: data.employee_id, day: data.day, kind: data.kind, amount_cents: cents,
      amount: money.decimal(cents), reason: data.reason, notes: data.notes || '', actor_id: entry.actor_id, revision: 1,
      created_at: utc(entry.created_at), device: { pending: entry.sync_status !== 'synced', error: entry.sync_error || null } };
  }

  /** Whether the saved lists show that employee's month as closed or paid (the dashboard then refuses any change to it). */
  monthClosed(user, branch, employeeId, month) {
    const scope = 'u:' + user.id;
    const latest = this.cache.get(scope, 'data', this.origin + '/admin/employees/data', { pathOnly: true });
    const lists = [latest && read(latest), (this.pageList(scope) || {}).list].filter(Boolean);
    return lists.some((list) => list.filters && list.filters.month === month && Array.isArray(list.items) && list.items.some((item) => item
      && item.branch === branch && Number(item.id) === employeeId && item.statement && ['closed', 'paid'].includes(item.statement.status)));
  }

  /** A record of this device that a list saved at storedAt does not have yet: not sent then, or sent after it. */
  fresh(entry, storedAt) {
    if (entry.sync_status !== 'synced' || !entry.synced_at || !storedAt) return true;
    return entry.synced_at > storedAt;
  }

  // ---- GET /admin/employees/entries: one day's deductions, bonuses or advances of an employee ----
  entries({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const branch = query.get('branch') || '';
    const employeeId = Number(query.get('employee_id'));
    const day = query.get('day') || '';
    const kind = query.get('kind') || '';
    if (!user.branches.includes(branch) || !KINDS.includes(kind) || !DAY.test(day) || !(employeeId > 0)) throw new Reply(422, 'البيانات غير صالحة.');
    const scope = 'u:' + user.id;
    const copy = this.cache.get(scope, 'data', url.href);
    const base = copy && read(copy);
    const items = base && Array.isArray(base.items) ? base.items.slice() : [];
    let storedAt = copy ? copy.storedAt : null;
    if (!base) {
      // Without this list saved: what the dashboard had that day, from the saved employees list, in one line.
      const list = this.listFor(scope, { day });
      const item = list && list.list.items.find((row) => row && row.branch === branch && Number(row.id) === employeeId);
      const listed = item && item.daily && item.daily[kind] ? money.minor(item.daily[kind].amount || '0') : 0;
      storedAt = list ? list.storedAt : null;
      if (listed > 0) items.push({ id: null, time: '—', amount: money.decimal(listed), reason: 'مسجلة على الداشبورد', notes: 'التفاصيل بتظهر لما النت يرجع' });
    }
    for (const entry of this.store.employeeEntries(branch, { day })) {
      if (entry.data.employee_id !== employeeId || entry.data.kind !== kind || !this.fresh(entry, storedAt)) continue;
      items.push({ id: null, time: wall(entry.created_at).slice(0, 5), amount: money.decimal(money.minor(entry.data.amount)), reason: entry.data.reason,
        notes: entry.data.notes || '', device: { pending: entry.sync_status !== 'synced' } });
    }
    const total = items.reduce((sum, item) => sum + money.minor(item.amount), 0);
    return json({ success: true, employee: (base && base.employee) || { id: employeeId, branch }, day, kind, count: items.length,
      total: money.decimal(total), items, device_copy: true });
  }

  // ---- GET /admin/branch-operations/recover: a punch whose answer was lost ----
  recover({ user: who, url }) {
    const user = this.user({ user: who });
    const key = url.searchParams.get('idempotency_key') || '';
    const entry = UUID.test(key) ? this.store.journalEntry(key) : null;
    if (entry && entry.kind === 'employee_entry' && entry.actor_id === user.id && entry.branch === (url.searchParams.get('branch') || '')) {
      return json({ success: true, found: true, replayed: true, entry: this.present(entry) });
    }
    if (entry && entry.kind !== 'attendance' && entry.branch === (url.searchParams.get('branch') || '')) {
      for (const recover of this.recoverers) {
        const answer = recover(entry, user);
        if (answer) return json(answer);
      }
    }
    if (!entry || entry.kind !== 'attendance' || entry.actor_id !== user.id || entry.branch !== (url.searchParams.get('branch') || '')) {
      // The delivery board's hand-off to a courier and its batch collection, done on this device, are recovered the same way.
      const done = UUID.test(key) ? this.store.pageCommand(key) : null;
      if (done && ['phone.dispatch', 'phone.batch'].includes(done.kind) && done.actor_id === user.id && done.branch === (url.searchParams.get('branch') || '')) {
        return json({ ...done.meta.result, found: true, replayed: true });
      }
      return json({ success: true, found: false });
    }
    return json({ success: true, found: true, replayed: true, attendance: this.day(entry.branch, entry.data.employee_id, entry.data.day) });
  }

  /** The list embedded in the saved copy of the employees page (the page shows it first, without asking), and when it was saved. */
  pageList(scope) {
    for (const kind of ['page', 'spa']) {
      const copy = this.cache.get(scope, kind, this.origin + '/admin/employees', { pathOnly: true });
      if (!copy) continue;
      const found = /<script type="application\/json" id="branch-operations-bootstrap">([\s\S]*?)<\/script>/.exec(copy.body.toString('utf8'));
      try {
        const boot = found && JSON.parse(found[1]);
        if (boot && boot.initial) return { list: boot.initial, storedAt: copy.storedAt };
      } catch { /* an unreadable copy is no base */ }
    }
    return null;
  }

  /**
   * A list for filters no saved copy has: another branch, a search, or the new operating day. Built from the newest saved
   * list holding that branch's employees (else the newest), cut to the branch and the search, with the day's attendance
   * from the sync and this device's punches, completed with the branch's employees the device knows from the sync, all on
   * one page. Another day than the saved list's is the current operating day only, and another month's pay is the
   * current day's own month only (it starts empty); anything else, and a device that never saved the list, stays null.
   */
  rebuilt(user, scope, filters) {
    const branch = filters.branch && filters.branch !== 'all' ? String(filters.branch) : '';
    if (branch && !user.branches.includes(branch)) return null;
    const branches = branch ? [branch] : user.branches;
    const lists = this.cache.allOf(scope, 'data', '/admin/employees/data').map((copy) => ({ list: read(copy), storedAt: copy.storedAt }));
    const page = this.pageList(scope);
    if (page) lists.push(page);
    const usable = lists.filter((found) => found.list && Array.isArray(found.list.items) && found.list.filters && DAY.test(String(found.list.filters.day || '')))
      .sort((a, b) => Date.parse(b.storedAt) - Date.parse(a.storedAt));
    const base = usable.find((found) => found.list.items.some((item) => item && branches.includes(item.branch))) || usable[0];
    if (!base) return null;
    const today = rules.operatingDay(this.now());
    const day = filters.day || today;
    const month = filters.month || day.slice(0, 7);
    const was = base.list.filters;
    const sameDay = was.day === day;
    const sameMonth = (was.month || String(was.day).slice(0, 7)) === month;
    if (!sameDay && day !== today) return null;
    if (!sameMonth && month !== day.slice(0, 7)) return null;
    const list = JSON.parse(JSON.stringify(base.list));
    const zero = () => ({ bonus: { count: 0, amount: '0.00' }, deduction: { count: 0, amount: '0.00' }, advance: { count: 0, amount: '0.00' } });
    // BranchPayroll::period of a month not closed yet («مفتوح»), before the dashboard computes it.
    const draft = () => ({ month, net: '0.00', status: 'draft', eligible_days: null, accrued_from: null, accrued_through: null });
    const closedSameMonth = String(was.day).slice(0, 7) === day.slice(0, 7);
    let items = list.items.filter((item) => item && branches.includes(item.branch)).map((item) => ({ ...item,
      ...(sameDay ? {} : { attendance: null, daily: zero(), day_closed: closedSameMonth ? !!item.day_closed : false }),
      ...(sameMonth ? {} : { statement: draft() }) }));
    const template = list.items.find(Boolean) || null;
    for (const value of branches) {
      for (const employee of this.store.employees(value)) {
        if (items.some((item) => item.branch === value && Number(item.id) === employee.id)) continue;
        const blank = {};
        if (template) for (const key of Object.keys(template)) blank[key] = null;
        items.push({ ...blank, id: employee.id, name: employee.name, branch: value, job_title: employee.job_title || '', shift: employee.shift || '',
          schedule_type: employee.schedule_type || 'branch', attendance_shift: employee.attendance_shift ?? null,
          attendance_start: employee.attendance_start || null, attendance_end: employee.attendance_end || null, active: true,
          attendance: null, day_closed: false, daily: zero(), statement: draft() });
      }
    }
    // BranchPayroll::listing's own filters (name, job, shift) and order (active first, then by name).
    const search = String(filters.search || '').trim().toLowerCase();
    if (search) items = items.filter((item) => String(item.name || '').toLowerCase().includes(search));
    for (const key of ['job_title', 'shift']) if (filters[key]) items = items.filter((item) => String(item[key] || '') === String(filters[key]));
    items.sort((a, b) => (b.active ? 1 : 0) - (a.active ? 1 : 0) || String(a.name || '').localeCompare(String(b.name || ''), 'ar'));
    // The day's attendance the sync brought (an employee punched on this device gets it in data(), with the punches).
    items = items.map((item) => {
      if (this.store.journal(item.branch, day, ['attendance']).some((entry) => entry.data.employee_id === Number(item.id))) return item;
      return { ...item, attendance: this.day(item.branch, item.id, day, item.attendance || {}) || item.attendance };
    });
    const counts = { present: 0, absent: 0, leave: 0 };
    const sums = { deduction: 0, bonus: 0, advance: 0 };
    let net = 0;
    for (const item of items) {
      const state = this.day(item.branch, item.id, day, {}) || item.attendance || {};
      if (['present', 'morning', 'evening', 'part_time'].includes(state.status)) counts.present += 1;
      else if (['absent', 'unauthorized_absence'].includes(state.status)) counts.absent += 1;
      else if (['paid_leave', 'unpaid_leave', 'preapproved_leave'].includes(state.status)) counts.leave += 1;
      for (const kind of KINDS) sums[kind] += money.minor((item.daily && item.daily[kind] && item.daily[kind].amount) || '0');
      net += money.minor((item.statement && item.statement.net) || '0');
    }
    list.items = items;
    list.filters = { ...was, branch: branch || 'all', day, month, search: filters.search || null, job_title: filters.job_title || null,
      shift: filters.shift || null, page: 1 };
    list.pagination = { page: 1, last_page: 1, total: items.length };
    list.summary = { ...(list.summary || {}), employees: items.length, ...counts, deduction: money.decimal(sums.deduction),
      bonus: money.decimal(sums.bonus), advance: money.decimal(sums.advance), net: money.decimal(net) };
    // data() adds the device's records the copy cannot hold: all of them on a day or month it starts empty.
    const empty = new Date(0).toISOString();
    return { list, storedAt: sameMonth ? base.storedAt : empty, dayStoredAt: sameDay ? base.storedAt : empty };
  }

  /** The saved list for these filters: the last one seen online, or the one in the saved page. */
  listFor(scope, filters, exactUrl = null) {
    const same = (seen) => seen && seen.filters && Array.isArray(seen.items)
      && Object.keys(filters).every((key) => !filters[key] || String(seen.filters[key] || '') === String(filters[key]));
    if (exactUrl) {
      const exact = this.cache.get(scope, 'data', exactUrl);
      const list = exact && read(exact);
      if (list && Array.isArray(list.items)) return { list, storedAt: exact.storedAt };
    }
    const latest = this.cache.get(scope, 'data', this.origin + '/admin/employees/data', { pathOnly: true });
    const seen = latest && read(latest);
    if (same(seen)) return { list: seen, storedAt: latest.storedAt };
    const page = this.pageList(scope);
    if (page && same(page.list)) return page;
    return null;
  }

  // ---- GET /admin/employees/data: the last list seen online, with this device's punches on it ----
  data({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const scope = 'u:' + user.id;
    const filters = { branch: query.get('branch'), day: query.get('day'), month: query.get('month'), search: query.get('search'),
      job_title: query.get('job_title'), shift: query.get('shift') };
    const found = this.listFor(scope, filters, url.href) || this.rebuilt(user, scope, filters);
    if (!found) throw new Reply(503, 'القايمة دي محتاجة إنترنت. قايمة اليوم المحفوظة على الجهاز شغالة، والحضور والانصراف بيتسجلوا عادي.');
    const base = found.list;
    const day = (base.filters && base.filters.day) || rules.operatingDay(this.now());
    const month = (base.filters && base.filters.month) || day.slice(0, 7);
    // Deductions, bonuses and advances recorded here and not in that list yet: on the day, and in the month's pay.
    const added = { bonus: 0, deduction: 0, advance: 0 };
    let netChange = 0;
    base.items = base.items.map((item) => {
      if (!item || !user.branches.includes(item.branch)) return item;
      let next = item;
      const punches = this.store.journal(item.branch, day, ['attendance']).filter((entry) => entry.data.employee_id === Number(item.id));
      if (punches.length) next = { ...next, attendance: this.day(item.branch, item.id, day, item.attendance || {}) };
      for (const entry of this.store.employeeEntries(item.branch)) {
        if (entry.data.employee_id !== Number(item.id)) continue;
        const { kind } = entry.data;
        const cents = money.minor(entry.data.amount);
        if (entry.data.day === day && this.fresh(entry, found.dayStoredAt || found.storedAt)) {
          const before = (next.daily && next.daily[kind]) || { count: 0, amount: '0.00' };
          next = { ...next, daily: { ...(next.daily || {}), [kind]: { count: Number(before.count || 0) + 1, amount: money.decimal(money.minor(before.amount || '0') + cents) } } };
          added[kind] += cents;
        }
        if (next.statement && String(entry.data.day).slice(0, 7) === (next.statement.month || month) && this.fresh(entry, found.storedAt)) {
          const change = kind === 'bonus' ? cents : -cents;
          const statement = { ...next.statement, [kind]: money.decimal(money.minor(next.statement[kind] || '0') + cents),
            net: money.decimal(money.minor(next.statement.net || '0') + change) };
          if (Number.isInteger(statement.net_cents)) statement.net_cents += change;
          next = { ...next, statement };
          netChange += change;
        }
      }
      return next;
    });
    if (base.summary) {
      const summary = { ...base.summary };
      for (const kind of KINDS) if (added[kind]) summary[kind] = money.decimal(money.minor(summary[kind] || '0') + added[kind]);
      if (netChange) summary.net = money.decimal(money.minor(summary.net || '0') + netChange);
      base.summary = summary;
    }
    // The device's operating day: a saved list of an earlier day moves the page to the new day, as the dashboard does.
    const today = rules.operatingDay(this.now());
    if (!base.operating_day || !base.operating_day.date || base.operating_day.date < today) base.operating_day = { ...(base.operating_day || {}), date: today };
    base.device_copy = true;
    return json(base);
  }
}

module.exports = { EmployeesOffline };
