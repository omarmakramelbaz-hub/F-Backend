'use strict';
// The dashboard's expenses page (مصروفات الفروع) while the internet is down: a new cash expense is saved in the
// device's journal under the page's own operation number and goes to the dashboard with the 30-second sync. The list
// is the last copy the page saw online, with this device's expenses added on top. An expense is approved, rejected or
// cancelled on the device too, with the dashboard's rules, and the review goes to the dashboard the same way; the lists,
// the badge and the shift page show it at once. Answers have the shape of
// App\Http\Controllers\Dashboard\BranchExpensesController / App\Services\Dashboard\BranchExpenses.
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { branchProblem } = require('./access.cjs');

// Expenses saved on this device get numbers far above the dashboard's own, so both never mix.
const LOCAL_EXPENSE_BASE = 8000000000000;
const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const DATE = /^\d{4}-\d{2}-\d{2}$/;
// BranchExpenses::review: what each action needs and leaves.
const AFTER = { approve: 'approved', reject: 'rejected', void: 'voided' };
const BEFORE = { approve: 'pending', reject: 'pending', void: 'approved' };
const CHANGED = 'حالة المصروف تغيرت؛ حدّث الصفحة.';

class Reply extends Error {
  constructor(status, message, errors = null) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}
const json = (body, status = 200) => ({ status, json: body });
const invalid = (field, message) => new Reply(422, message, { [field]: [message] });

class ExpensesOffline {
  /** store: the device database; cache: the device's copy of the dashboard (for the last list seen online). */
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {} }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
  }

  match(method, pathname) {
    if (method === 'POST' && pathname === '/admin/branch-expenses/save') return { handle: (request) => this.save(request) };
    // keepOnline: the dashboard's own answer is kept online, as the base this device adds its expenses to offline.
    if (method === 'GET' && pathname === '/admin/branch-expenses/data') return { keepOnline: true, handle: (request) => this.data(request) };
    if (method === 'GET' && pathname === '/admin/branch-expenses/recover') return { handle: (request) => this.recover(request) };
    if (method === 'GET' && pathname === '/admin/branch-expenses/pending-count') return { keepOnline: true, handle: (request) => this.pendingCount(request) };
    const one = /^\/admin\/branch-expenses\/([1-9][0-9]{0,15})(\/review)?$/.exec(pathname);
    if (one && Number(one[1]) > LOCAL_EXPENSE_BASE) {
      // Saved on this device: it exists only here until the dashboard has it, so the device answers, online too.
      const seq = Number(one[1]) - LOCAL_EXPENSE_BASE;
      if (method === 'GET' && !one[2]) return { always: true, handle: (request) => this.show(request, seq) };
      if (method === 'POST' && one[2]) return { always: true, handle: (request) => this.review(request, { seq }) };
    } else if (one) {
      const id = Number(one[1]);
      if (method === 'GET' && !one[2]) return { keepOnline: true, handle: (request) => this.showSaved(request, id) };
      if (method === 'POST' && one[2]) return { handle: (request) => this.review(request, { id }) };
    }
    return null;
  }

  async answer(route, request) {
    try {
      return await route.handle(request);
    } catch (error) {
      if (error instanceof Reply) return json(error.errors ? { message: error.message, errors: error.errors } : { message: error.message }, error.status);
      if (error && error.status) return json({ message: error.message }, error.status);
      return json({ message: error && error.message ? error.message : 'حدث خطأ غير متوقع.' }, 422);
    }
  }

  user(request) {
    const user = request.user && this.store.user(request.user.id);
    if (!user) throw new Reply(401, 'سجّل الدخول أولًا.');
    return user;
  }

  branchName(value) {
    const catalog = this.store.catalog(value);
    const known = this.store.branches().find((branch) => branch.value === value);
    return (catalog && catalog.branch && catalog.branch.name) || (known && known.name) || value;
  }

  /** BranchExpenses::present for an expense saved on this device. */
  present(entry, user) {
    const data = entry.data;
    const categories = this.store.expenseCategories(entry.branch);
    const category = categories.find((item) => item.key === data.category);
    const baseName = data.category_name || (category && category.name) || data.category;
    const branchName = this.branchName(entry.branch);
    let label = String(branchName || '').trim() || entry.branch;
    if (entry.branch.startsWith('f:') && !/^فرع(?:\s|$)/u.test(label)) label = 'فرع ' + label;
    const serverNumber = entry.server_ref ? String(entry.server_ref).split(' · ')[0] : '';
    const status = entry.sync_status === 'rejected' ? 'rejected' : (data.approve ? 'approved' : 'pending');
    const item = { id: LOCAL_EXPENSE_BASE + entry.seq, revision: 1, actor_id: entry.actor_id,
      number: serverNumber || `جهاز-${entry.seq}`, branch: entry.branch, branch_name: branchName, occurred_on: entry.business_date,
      category: data.category, category_base_name: baseName, category_name: `${baseName} — ${label}`, description: data.description || '',
      amount: money.decimal(entry.amount_cents), payment_method: 'cash', payment_reference: '', supplier: data.supplier || '', cost_center: '',
      notes: data.notes || '', status, actor_name: entry.actor_name, reviewer_id: data.approve ? entry.actor_id : null,
      reviewer_name: data.approve ? entry.actor_name : '', review_reason: entry.sync_status === 'rejected' ? (entry.sync_error || '') : '',
      attachment_name: null, attachment_url: null, can_edit: false, print_url: null, created_at: entry.created_at, updated_at: entry.created_at,
      history: [], device: { sync_status: entry.sync_status, sync_error: entry.sync_error } };
    if (data.quantity_kg) {
      item.stock_quantity_kg = data.quantity_kg;
      item.stock_item_name = baseName;
      item.stock_reversed = false;
    }
    if (status === 'rejected') return item;
    return this.reviewed(item, this.store.expenseReviews([entry.branch]).filter((review) => review.data.expense_key === entry.id), null);
  }

  /**
   * An expense with the reviews made here that its copy (saved at savedAt) does not show yet: not sent, or sent after it.
   * savedAt null: every review made here (an expense saved here is shown as saved). Reviews the dashboard refused do not count.
   */
  reviewed(item, reviews, savedAt) {
    let next = item;
    for (const review of reviews) {
      if (review.sync_status === 'rejected') continue;
      if (savedAt !== null && review.sync_status === 'synced' && review.synced_at && Date.parse(review.synced_at) <= savedAt) continue;
      const status = AFTER[review.data.action];
      if (!status) continue;
      next = { ...next, status, revision: Number(next.revision || 1) + 1, reviewer_id: review.actor_id, reviewer_name: review.actor_name,
        review_reason: review.data.reason || '', can_edit: false, ...(status !== 'approved' && next.stock_quantity_kg ? { stock_reversed: true } : {}),
        device: { ...(next.device || {}), review: { action: review.data.action, sync_status: review.sync_status, sync_error: review.sync_error } } };
    }
    return next;
  }

  /** The reviews made here of a dashboard expense (also one saved here, once the dashboard has it). */
  reviewsOf(id, branches) {
    return this.store.expenseReviews(branches).filter((review) => this.copyTarget(review, Infinity) === Number(id));
  }

  /**
   * The dashboard expense a review made here is about, as a copy saved at savedAt holds it: a dashboard expense, or one
   * saved here that reached the dashboard before that copy (its number is the dashboard's). Null otherwise.
   */
  copyTarget(review, savedAt) {
    if (review.data.expense_id) return Number(review.data.expense_id);
    const saved = review.data.expense_key ? this.store.journalEntry(review.data.expense_key) : null;
    if (!saved || saved.sync_status !== 'synced' || !saved.synced_at || Date.parse(saved.synced_at) > savedAt) return null;
    const number = /^EXP-0*([1-9][0-9]*)/.exec(String(saved.server_ref || ''));
    return number ? Number(number[1]) : null;
  }

  /** The newest saved copy holding a dashboard expense (its details, or a list), with when it was saved. */
  savedExpense(user, id) {
    const scope = 'u:' + user.id;
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    const found = [];
    const own = this.cache.get(scope, 'data', `${this.origin}/admin/branch-expenses/${id}`);
    const detail = own && read(own);
    if (detail && detail.expense && Number(detail.expense.id) === id) found.push({ item: detail.expense, savedAt: Date.parse(own.storedAt) });
    for (const copy of this.cache.allOf(scope, 'data', '/admin/branch-expenses/data', 30)) {
      const list = read(copy);
      const item = list && Array.isArray(list.items) && list.items.find((row) => row && Number(row.id) === id);
      if (item) found.push({ item, savedAt: Date.parse(copy.storedAt) });
    }
    const initial = this.pageList(scope);
    const item = initial && Array.isArray(initial.data.items) && initial.data.items.find((row) => row && Number(row.id) === id);
    if (item) found.push({ item, savedAt: initial.savedAt });
    found.sort((a, b) => b.savedAt - a.savedAt);
    return found[0] || null;
  }

  // ---- GET /admin/branch-expenses/{id} for a dashboard expense: its saved details, with the reviews made here ----
  showSaved({ user: who }, id) {
    const user = this.user({ user: who });
    const found = this.savedExpense(user, id);
    if (!found || !user.branches.includes(found.item.branch)) throw new Reply(503, 'تفاصيل المصروف ده محتاجة إنترنت. اعتماده أو رفضه شغال من القايمة.');
    return json({ success: true, expense: { history: [], ...this.reviewed(found.item, this.reviewsOf(id, [found.item.branch]), found.savedAt) }, device_copy: true });
  }

  // ---- POST /admin/branch-expenses/{id}/review: approve, reject or cancel, with BranchExpenses::review's rules ----
  review({ user: who, body }, { id = null, seq = null }) {
    const user = this.user({ user: who });
    const values = body && typeof body === 'object' ? body : {};
    const key = String(values.idempotency_key || '');
    if (!UUID.test(key)) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    const done = this.store.journalEntry(key);
    if (done) {
      if (done.kind !== 'expense_review' || done.actor_id !== user.id) throw new Reply(409, 'رقم العملية مستخدم لبيانات مختلفة.');
      return json({ success: true, expense: this.afterReview(user, done), replayed: true });
    }
    const action = String(values.action || '');
    if (!AFTER[action]) throw invalid('action', 'الإجراء غير صالح.');
    const reason = String(values.reason ?? '').trim();
    if (reason.length > 500) throw invalid('reason', 'السبب أطول من المسموح.');
    if (action !== 'approve' && !reason) throw invalid('reason', 'اكتب سبب الرفض أو الإلغاء.');
    if (!user.can_approve_expenses) throw new Reply(403, 'اعتماد المصروفات للمالك والإدارة فقط.');
    if (!(this.store.get('server_kinds', []) || []).includes('expense_review')) {
      throw new Reply(422, 'اعتماد المصروفات ورفضها من غير نت محتاج تحديث السرفر، واتصال بالإنترنت مرة واحدة بعده.');
    }
    let current;
    let target;
    if (seq !== null) {
      const entry = this.store.journalBySeq(seq);
      if (!entry || entry.kind !== 'expense' || !user.branches.includes(entry.branch)) throw new Reply(404, 'المصروف غير موجود على هذا الجهاز.');
      current = this.present(entry, user);
      target = { expense_key: entry.id };
    } else {
      const found = this.savedExpense(user, id);
      if (!found) throw new Reply(409, 'المصروف ده مش محفوظ على الجهاز. اعتماده أو رفضه محتاج إنترنت.');
      current = this.reviewed(found.item, this.reviewsOf(id, [found.item.branch]), found.savedAt);
      target = { expense_id: id, expected_revision: Number(values.expected_revision) };
    }
    const branch = String(current.branch || '');
    if (String(values.branch || branch) !== branch) throw new Reply(404, 'المصروف غير موجود في هذا الفرع.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (Number(values.expected_revision || 0) !== Number(current.revision || 0) || current.status !== BEFORE[action]) throw new Reply(409, CHANGED);
    if (action === 'approve' && current.payment_method !== 'cash') throw new Reply(422, 'لا يمكن اعتماد مصروف خارج خزنة الدرج.');
    let amount;
    try { amount = money.minor(String(current.amount || '0').replace(/,/g, '')); } catch { amount = 0; }
    const entry = this.store.recordExpenseReview(user, branch, { ...target, action, reason, amount_cents: amount,
      shown: { number: current.number, occurred_on: current.occurred_on, status_before: current.status, payment_method: current.payment_method } }, { id: key });
    if (!entry.replayed) this.kick();
    return json({ success: true, expense: this.afterReview(user, entry), replayed: !!entry.replayed });
  }

  /** The expense as the review made here leaves it. */
  afterReview(user, review) {
    if (review.data.expense_key) {
      const saved = this.store.journalEntry(review.data.expense_key);
      if (saved) return this.present(saved, user);
    }
    const found = this.savedExpense(user, Number(review.data.expense_id));
    if (!found) return { id: Number(review.data.expense_id), branch: review.branch, number: review.data.number || '', status: AFTER[review.data.action] };
    return this.reviewed(found.item, this.reviewsOf(review.data.expense_id, [review.branch]), found.savedAt);
  }

  /**
   * What the reviews made here change in a saved list's totals: a pending one leaves the pending count, an approved one
   * counts as spent, a cancelled one no longer does. Only dashboard expenses of the list's period and branches.
   */
  reviewTotals(summary, reviews, { from, to, today }) {
    const add = (value, cents) => money.decimal(money.minor(String(value || '0').replace(/,/g, '')) + cents);
    const result = { ...summary };
    for (const review of reviews) {
      const day = String(review.data.occurred_on || '');
      if (!DATE.test(day) || day < from || day > to) continue;
      const before = review.data.status_before;
      const after = AFTER[review.data.action];
      let sign = 0;
      if (after === 'approved') sign = 1;
      else if (before === 'approved') sign = -1;
      if (before === 'pending') result.pending = Math.max(0, Number(result.pending || 0) - 1);
      if (!sign) continue;
      const cents = sign * review.amount_cents;
      result.period = add(result.period, cents);
      result.count = Math.max(0, Number(result.count || 0) + sign);
      if (day === today) result.today = add(result.today, cents);
      if (day.slice(0, 7) === today.slice(0, 7)) result.month = add(result.month, cents);
    }
    return result;
  }

  /** The page's form (multipart FormData). */
  fields(body) {
    if (!body || typeof body !== 'object') throw invalid('amount', 'بيانات المصروف ناقصة.');
    if (Array.isArray(body.__files) && body.__files.length) {
      throw new Reply(422, 'المرفقات محتاجة إنترنت. احفظ المصروف من غير مرفق دلوقتي، وضيف المرفق من الداشبورد لما النت يرجع.');
    }
    return body;
  }

  // ---- POST /admin/branch-expenses/save ----
  save({ user: who, body }) {
    const user = this.user({ user: who });
    const values = this.fields(body);
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (!UUID.test(String(values.idempotency_key || ''))) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    if (values.expense_id) throw new Reply(403, 'تعديل المصروفات المسجلة غير متاح.');
    if (values.payment_method && values.payment_method !== 'cash') throw invalid('payment_method', 'المصروف من غير نت بيتسجل كاش من الدرج بس.');
    const today = rules.operatingDay(this.now());
    if (values.occurred_on && values.occurred_on !== today) {
      throw invalid('occurred_on', 'من غير نت المصروف بيتسجل بتاريخ يوم التشغيل الحالي بس. تسجيل مصروف بتاريخ يوم سابق محتاج إنترنت.');
    }
    const approve = ['1', 'true', true, 1].includes(values.approve);
    const entry = this.store.recordExpense(user, branch, { category: values.category, amount: values.amount, description: values.description,
      quantity_kg: values.quantity_kg, supplier: values.supplier, notes: values.notes, approve }, { id: values.idempotency_key });
    if (!entry.replayed) this.kick();
    return json({ success: true, expense: this.present(entry, user), replayed: !!entry.replayed });
  }

  /** The branches a list covers: one, or every branch of this device the account has. */
  scope(user, value) {
    if (value && value !== 'all') {
      if (!BRANCH.test(value)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
      { const problem = branchProblem(this.store, user, value); if (problem) throw new Reply(problem.status, problem.message); }
      return [value];
    }
    return this.store.branches().map((branch) => branch.value).filter((branch) => user.branches.includes(branch));
  }

  // ---- GET /admin/branch-expenses/data: the last list seen online plus this device's expenses ----
  data({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const branches = this.scope(user, query.get('branch') || '');
    const today = rules.operatingDay(this.now());
    const from = DATE.test(query.get('from') || '') ? query.get('from') : today;
    const to = DATE.test(query.get('to') || '') ? query.get('to') : today;
    const scope = 'u:' + user.id;
    const search = (query.get('search') || '').trim().toLowerCase();
    const category = query.get('category') || '';
    const status = query.get('status') || '';
    const actor = query.get('actor_id') || '';
    const samePeriod = (filters) => String(filters.from || '') === from && String(filters.to || '') === to
      && String(filters.branch || '') === String(query.get('branch') || filters.branch || '');
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    let base = null;
    let savedAt = 0;
    let exact = false;
    const copy = this.cache.get(scope, 'data', url.href);
    if (copy && (base = read(copy))) { savedAt = Date.parse(copy.storedAt); exact = true; }
    if (!base) {
      // The same period's list with other filters is still the right base; another period is not.
      const latest = this.cache.get(scope, 'data', url.href, { pathOnly: true });
      const seen = latest && read(latest);
      if (seen && seen.filters && samePeriod(seen.filters)) { base = seen; savedAt = Date.parse(latest.storedAt); }
    }
    if (!base) {
      // The list the page itself came with (its default period), from the saved copy of the page.
      const initial = this.pageList(scope);
      if (initial && initial.data.filters && samePeriod(initial.data.filters)) { base = initial.data; savedAt = initial.savedAt; }
    }
    const wanted = (item) => (!search || `${item.description || ''} ${item.number || ''}`.toLowerCase().includes(search))
      && (!category || item.category === category) && (!status || item.status === status) && (!actor || String(item.actor_id) === actor);
    // Dashboard expenses reviewed here that the list does not show reviewed yet: shown reviewed, and its totals follow.
    const reviews = this.store.expenseReviews(branches).filter((review) => review.sync_status !== 'rejected'
      && !(review.sync_status === 'synced' && review.synced_at && Date.parse(review.synced_at) <= savedAt))
      .map((review) => ({ review, id: this.copyTarget(review, savedAt) })).filter((pair) => pair.id);
    if (base && Array.isArray(base.items) && reviews.length) {
      const of = (id) => reviews.filter((pair) => pair.id === Number(id)).map((pair) => pair.review);
      base.items = base.items.map((item) => (item && of(item.id).length ? this.reviewed(item, of(item.id), savedAt) : item));
      if (status) base.items = base.items.filter((item) => !item || item.status === status);
      base.summary = this.reviewTotals(base.summary || {}, reviews.map((pair) => pair.review), { from, to, today });
    }
    if (base && !exact && Array.isArray(base.items)) {
      base.items = base.items.filter(wanted);
      base.pagination = { ...(base.pagination || {}), page: 1, last_page: 1, total: base.items.length };
    }
    // Device expenses the list from the dashboard does not have yet.
    const mine = this.store.deviceExpenses(branches, from, to).filter((entry) => !(entry.sync_status === 'synced' && entry.synced_at && Date.parse(entry.synced_at) <= savedAt))
      .map((entry) => this.present(entry, user)).filter(wanted);
    const page = Number(query.get('page')) || 1;
    if (base && base.items) {
      base.items = page === 1 ? mine.concat(base.items) : base.items;
      base.pagination = { ...base.pagination, total: Number(base.pagination && base.pagination.total || 0) + mine.length };
      base.summary = this.adjust(base.summary || {}, mine, today);
      base.device_copy = true;
      return json(base);
    }
    // Nothing saved for this list: this device's expenses, with the categories of the synced menu.
    const categories = {};
    for (const branch of branches) for (const item of this.store.expenseCategories(branch)) categories[item.key] = item.name;
    return json({ success: true, items: page === 1 ? mine : [], filters: { branch: query.get('branch') || '', from, to }, branches: [],
      permissions: { can_manage_categories: false, can_create: !!user.can_checkout, can_approve: !!user.can_approve_expenses },
      actors: [...new Map(mine.map((item) => [item.actor_id, { id: item.actor_id, name: item.actor_name }])).values()],
      categories, active_categories: categories, stock_categories: {}, category_scope: query.get('branch') || '',
      pagination: { page: 1, last_page: 1, total: mine.length },
      summary: this.adjust({ today: '0.00', month: '0.00', period: '0.00', average: '0.00', count: 0, pending: 0, top_category: null, top_amount: '0.00' }, mine, today),
      device_copy: true });
  }

  /** The list embedded in the saved copy of the expenses page (the page shows it first, without asking). */
  pageList(scope) {
    for (const kind of ['page', 'spa']) {
      const copy = this.cache.get(scope, kind, this.origin + '/admin/branch-expenses', { pathOnly: true });
      if (!copy) continue;
      const found = /<script type="application\/json" id="branch-expenses-bootstrap">([\s\S]*?)<\/script>/.exec(copy.body.toString('utf8'));
      try {
        const boot = found && JSON.parse(found[1]);
        if (boot && boot.initial) return { data: boot.initial, savedAt: Date.parse(copy.storedAt) };
      } catch { /* an unreadable copy is no base */ }
    }
    return null;
  }

  /** The list's totals with this device's expenses added (approved ones count as spent, the rest as pending). */
  adjust(summary, items, today) {
    const add = (value, cents) => money.decimal(money.minor(String(value || '0').replace(/,/g, '')) + cents);
    const result = { ...summary };
    for (const item of items) {
      const cents = money.minor(item.amount);
      if (item.status === 'pending') result.pending = Number(result.pending || 0) + 1;
      if (item.status !== 'approved') continue;
      result.period = add(result.period, cents);
      result.count = Number(result.count || 0) + 1;
      if (item.occurred_on === today) result.today = add(result.today, cents);
      if (item.occurred_on.slice(0, 7) === today.slice(0, 7)) result.month = add(result.month, cents);
    }
    return result;
  }

  // ---- GET /admin/branch-expenses/recover: an expense whose answer was lost ----
  recover({ user: who, url }) {
    const user = this.user({ user: who });
    const key = url.searchParams.get('idempotency_key') || '';
    const branch = url.searchParams.get('branch') || '';
    if (!UUID.test(key)) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    const entry = this.store.journalEntry(key);
    if (entry && entry.kind === 'expense_review' && entry.actor_id === user.id && entry.branch === branch) {
      return json({ success: true, expense: this.afterReview(user, entry), found: true });
    }
    if (!entry || entry.kind !== 'expense' || entry.actor_id !== user.id || entry.branch !== branch) return json({ success: true, found: false });
    return json({ success: true, expense: this.present(entry, user), found: true });
  }

  // ---- GET /admin/branch-expenses/{id} for an expense saved on this device ----
  show({ user: who }, seq) {
    const user = this.user({ user: who });
    const entry = this.store.journalBySeq(seq);
    if (!entry || entry.kind !== 'expense' || !user.branches.includes(entry.branch)) throw new Reply(404, 'المصروف غير موجود على هذا الجهاز.');
    return json({ success: true, expense: this.present(entry, user) });
  }

  // ---- GET /admin/branch-expenses/pending-count (the menu badge) ----
  pendingCount({ user: who }) {
    const user = this.user({ user: who });
    const scope = 'u:' + user.id;
    const copy = this.cache.get(scope, 'data', this.origin + '/admin/branch-expenses/pending-count');
    let count = 0;
    let savedAt = 0;
    try {
      if (copy) { count = Number(JSON.parse(copy.body.toString('utf8')).count) || 0; savedAt = Date.parse(copy.storedAt); }
    } catch { count = 0; }
    const branches = this.scope(user, 'all');
    const today = rules.operatingDay(this.now());
    const reviews = this.store.expenseReviews(branches).filter((review) => review.sync_status !== 'rejected');
    for (const entry of this.store.deviceExpenses(branches, '2000-01-01', today)) {
      if (entry.data.approve || entry.sync_status === 'rejected') continue;
      if (entry.sync_status === 'synced' && entry.synced_at && Date.parse(entry.synced_at) <= savedAt) continue;
      if (reviews.some((review) => review.data.expense_key === entry.id && BEFORE[review.data.action] === 'pending')) continue;
      count++;
    }
    // Dashboard expenses approved or rejected here that the saved count still has.
    for (const review of reviews) {
      if (review.data.status_before !== 'pending' || BEFORE[review.data.action] !== 'pending' || !this.copyTarget(review, savedAt)) continue;
      if (review.sync_status === 'synced' && review.synced_at && Date.parse(review.synced_at) <= savedAt) continue;
      count = Math.max(0, count - 1);
    }
    return json({ success: true, count });
  }
}

module.exports = { ExpensesOffline, LOCAL_EXPENSE_BASE };
