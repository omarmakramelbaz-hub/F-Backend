'use strict';
const { branchProblem } = require('./access.cjs');
// The dashboard's "إضافة بضاعة" page (branch stock) while the internet is down: goods received are saved in the
// device's journal under the page's own operation number and go to the dashboard with the 30-second sync, where the
// dashboard's own receipt applies (BranchInventory::receive). The balances and the latest movements are the last copy
// the page saw online, with this device's receipts added. Recipes and minimum alerts need the internet.

// Receipts saved on this device get numbers far above the dashboard's own, so both never mix.
const LOCAL_STOCK_BASE = 3000000000000;
const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NEEDS_UPDATE = 'إضافة البضاعة من غير نت تحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.';
const NO_COPY = 'صفحة إضافة البضاعة لهذا الفرع محتاجة تتفتح مرة والنت شغال عشان الجهاز يحفظ قايمة البضاعة وأرصدتها.';
const CAIRO = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

class Reply extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
const json = (body, status = 200) => ({ status, json: body });

/** BranchInventory::units: up to seven digits and three decimals, in millionths. */
function units(value) {
  const text = String(value ?? '');
  if (!/^[0-9]{1,7}(?:\.[0-9]{1,3})?$/.test(text)) throw new Reply(422, 'أدخل كمية حتى ثلاث منازل عشرية.');
  const [whole, fraction = ''] = text.split('.');
  const result = Number(whole) * 1000000 + Number(fraction.padEnd(6, '0'));
  if (!(result > 0 && result <= 1000000000000)) throw new Reply(422, 'الكمية يجب أن تكون موجبة وحتى مليون.');
  return result;
}

/** BranchInventory::quantity: millionths back to the dashboard's text (no trailing zeros). */
function quantity(value) {
  const n = Math.abs(value);
  const text = `${Math.floor(n / 1000000)}.${String(n % 1000000).padStart(6, '0')}`.replace(/0+$/, '').replace(/\.$/, '');
  return (value < 0 ? '-' : '') + text;
}

/** A balance the dashboard wrote (e.g. "12.5", "-3") in millionths. */
function parsed(text) {
  const found = /^(-?)([0-9]+)(?:\.([0-9]{1,6}))?$/.exec(String(text ?? '0'));
  if (!found) return 0;
  const value = Number(found[2]) * 1000000 + Number((found[3] || '').padEnd(6, '0'));
  return found[1] ? -value : value;
}

const cairo = (iso) => {
  const parts = Object.fromEntries(CAIRO.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
};

class StockOffline {
  /** store: the device database; cache: the device's copy of the dashboard (the last list seen online). */
  constructor({ store, cache, origin, now = () => new Date(), kick = () => {} }) {
    this.store = store;
    this.cache = cache;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
  }

  match(method, pathname) {
    if (method === 'POST' && pathname === '/admin/branch-stock/receive') return { handle: (request) => this.receive(request) };
    // keepOnline: the dashboard's own answer is kept online, as the base this device adds its receipts to offline.
    if (method === 'GET' && pathname === '/admin/branch-stock/data') return { keepOnline: true, handle: (request) => this.data(request) };
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

  supports(kind) {
    return (this.store.get('server_kinds', []) || []).includes(kind);
  }

  /**
   * The branch's list the page last saw online: the same address, the latest one for the branch (filtered here by
   * the search), or the list the page itself came with. savedAt: when it was read from the dashboard.
   */
  listing(user, branch, url = null) {
    const scope = 'u:' + user.id;
    const read = (copy) => { try { return JSON.parse(copy.body.toString('utf8')); } catch { return null; } };
    const mine = (value) => value && value.branch && value.branch.value === branch && Array.isArray(value.items);
    if (url) {
      const exact = this.cache.get(scope, 'data', url.href);
      const value = exact && read(exact);
      if (mine(value)) return { base: value, savedAt: Date.parse(exact.storedAt), exact: true };
    }
    const latest = this.cache.get(scope, 'data', this.origin + '/admin/branch-stock/data', { pathOnly: true });
    const seen = latest && read(latest);
    if (mine(seen)) return { base: seen, savedAt: Date.parse(latest.storedAt), exact: false };
    for (const kind of ['page', 'spa']) {
      const copy = this.cache.get(scope, kind, this.origin + '/admin/branch-stock', { pathOnly: true });
      if (!copy) continue;
      const found = /<script type="application\/json" id="branch-stock-bootstrap">([\s\S]*?)<\/script>/.exec(copy.body.toString('utf8'));
      try {
        const boot = found && JSON.parse(found[1]);
        if (boot && mine(boot.initial)) return { base: boot.initial, savedAt: Date.parse(copy.storedAt), exact: false };
      } catch { /* an unreadable copy is no base */ }
    }
    return null;
  }

  /** This device's receipts the list from the dashboard does not have yet, oldest first. */
  unseen(branch, savedAt) {
    return this.store.deviceStockReceipts(branch).filter((entry) => entry.sync_status !== 'rejected'
      && !(entry.sync_status === 'synced' && entry.synced_at && Date.parse(entry.synced_at) <= savedAt));
  }

  /** The item's stock (BranchInventory::stock, with its minimum alert fields) with this device's receipts added. */
  stock(item, receipts) {
    const base = item.stock || { ingredient_id: item.id, unit: item.unit, unit_label: item.unit_label, quantity: '0', negative: false, revision: 0 };
    const added = receipts.filter((entry) => Number(entry.data.ingredient_id) === Number(item.id)).reduce((sum, entry) => sum + Number(entry.data.units), 0);
    if (!added) return base;
    const balance = parsed(base.quantity) + added;
    return { ...base, quantity: quantity(balance), negative: balance < 0 };
  }

  /** A receipt saved here as a row of the latest movements, with the balance it left. */
  movement(entry, balance) {
    const data = entry.data;
    return { id: LOCAL_STOCK_BASE + entry.seq, name: data.name, unit_label: data.unit_label, quantity: data.quantity, balance: quantity(balance),
      source_type: 'receipt', source_id: `${entry.actor_id}:${entry.id}`, actor: entry.actor_name, supplier: data.supplier || null,
      notes: data.notes || null, created_at: cairo(entry.created_at), device: { sync_status: entry.sync_status, sync_error: entry.sync_error } };
  }

  /** The answer BranchInventory::receive gives, for a receipt saved on this device. */
  present(entry, found) {
    const data = entry.data;
    const item = found && found.base.items.find((value) => Number(value.id) === Number(data.ingredient_id));
    const receipts = found ? this.unseen(entry.branch, found.savedAt) : [entry];
    const stock = item ? this.stock(item, receipts) : { ingredient_id: Number(data.ingredient_id), unit: data.unit, unit_label: data.unit_label,
      quantity: quantity(receipts.filter((value) => Number(value.data.ingredient_id) === Number(data.ingredient_id)).reduce((sum, value) => sum + Number(value.data.units), 0)),
      negative: false, revision: 0 };
    return { success: true, receipt: { id: LOCAL_STOCK_BASE + entry.seq, branch: entry.branch, ingredient_id: Number(data.ingredient_id), unit: data.unit,
      quantity: data.quantity, idempotency_key: entry.id }, stock };
  }

  // ---- POST /admin/branch-stock/receive ----
  receive({ user: who, body }) {
    const user = this.user({ user: who });
    const values = body && typeof body === 'object' ? body : {};
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    const key = String(values.idempotency_key || '');
    if (!UUID.test(key)) throw new Reply(422, 'رقم العملية غير صالح.');
    const done = this.store.journalEntry(key);
    if (done) {
      if (done.kind !== 'stock' || done.actor_id !== user.id || done.branch !== branch) throw new Reply(409, 'رقم العملية مستخدم لعملية مختلفة.');
      return json({ ...this.present(done, this.listing(user, branch)), replayed: true });
    }
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بتسجيل البضاعة.');
    if (!this.supports('stock_receive')) throw new Reply(422, NEEDS_UPDATE);
    const id = Number(values.ingredient_id);
    if (!Number.isInteger(id) || id < 1) throw new Reply(422, 'اختر صنفًا من قائمة البضاعة فقط.');
    if (!['kg', 'piece'].includes(values.unit)) throw new Reply(422, 'وحدة البضاعة مختلفة.');
    const text = (value) => String(value ?? '').trim();
    if (typeof values.quantity !== 'string' || values.quantity.length > 16) throw new Reply(422, 'أدخل كمية حتى ثلاث منازل عشرية.');
    if (text(values.supplier).length > 150) throw new Reply(422, 'اسم المورد طويل جدًا.');
    if (text(values.notes).length > 500) throw new Reply(422, 'الملاحظات طويلة جدًا.');
    const amount = units(values.quantity);
    if (values.unit === 'piece' && amount % 1000000) throw new Reply(422, 'توريد القطع يجب أن يكون عددًا صحيحًا.');
    if (!branch.startsWith('f:')) throw new Reply(404, 'غير موجود.');
    const found = this.listing(user, branch);
    if (!found) throw new Reply(503, NO_COPY);
    const ingredient = (found.base.ingredients || found.base.items).find((item) => Number(item.id) === id);
    if (!ingredient) throw new Reply(404, 'اختر صنفًا من قائمة البضاعة فقط.');
    if (ingredient.unit !== values.unit) throw new Reply(422, 'وحدة البضاعة مختلفة.');
    const entry = this.store.recordStockReceipt(user, branch, { ingredient_id: id, unit: values.unit, quantity: values.quantity, units: amount,
      supplier: text(values.supplier), notes: text(values.notes), name: ingredient.name, unit_label: ingredient.unit_label || (values.unit === 'kg' ? 'كجم' : 'قطعة') },
    { id: key });
    if (!entry.replayed) this.kick();
    return json({ ...this.present(entry, found), replayed: !!entry.replayed });
  }

  // ---- GET /admin/branch-stock/data: the last list seen online plus this device's receipts ----
  data({ user: who, url }) {
    const user = this.user({ user: who });
    const branch = url.searchParams.get('branch') || '';
    if (branch !== 'all' && !BRANCH.test(branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    if (branch !== 'all') { const problem = branchProblem(this.store, user, branch); if (problem) throw new Reply(problem.status, problem.message); }
    if (branch === 'all') {
      // Every branch at once is the dashboard's own view; offline the last copy is shown as it was.
      const copy = this.cache.get('u:' + user.id, 'data', url.href);
      if (!copy) throw new Reply(503, 'عرض كل الفروع محتاج إنترنت. اختر الفرع لعرض أرصدته المحفوظة على الجهاز.');
      return json({ ...JSON.parse(copy.body.toString('utf8')), device_copy: true });
    }
    const found = this.listing(user, branch, url);
    if (!found) throw new Reply(503, NO_COPY);
    const base = JSON.parse(JSON.stringify(found.base));
    const search = (url.searchParams.get('search') || '').trim().toLowerCase();
    if (!found.exact) {
      base.items = base.items.filter((item) => !search || String(item.name || '').toLowerCase().includes(search));
      base.pagination = { ...(base.pagination || {}), page: 1, last_page: 1, total: base.items.length };
    }
    const receipts = this.unseen(branch, found.savedAt);
    base.items = base.items.map((item) => ({ ...item, stock: this.stock(item, receipts) }));
    // The latest movements: this device's receipts first, each with the balance it left.
    const every = found.base.items;
    const running = new Map();
    const rows = [];
    for (const entry of receipts) {
      const ingredient = Number(entry.data.ingredient_id);
      if (!running.has(ingredient)) {
        const item = every.find((value) => Number(value.id) === ingredient);
        running.set(ingredient, parsed(item && item.stock ? item.stock.quantity : '0'));
      }
      running.set(ingredient, running.get(ingredient) + Number(entry.data.units));
      rows.push(this.movement(entry, running.get(ingredient)));
    }
    base.history = rows.reverse().concat(Array.isArray(base.history) ? base.history : []).slice(0, 30);
    base.device_copy = true;
    return json(base);
  }

  /** The page's check of a receipt whose answer was lost (GET /admin/branch-operations/recover). */
  recovered(entry, user) {
    if (!entry || entry.kind !== 'stock' || entry.actor_id !== user.id) return null;
    return { ...this.present(entry, this.listing(user, entry.branch)), found: true, replayed: true };
  }
}

module.exports = { StockOffline, LOCAL_STOCK_BASE, units, quantity };
