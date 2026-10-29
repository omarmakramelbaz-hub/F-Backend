'use strict';
// Everything the app does with its own database: accounts, menus, invoices and the queue of operations to send.
const crypto = require('node:crypto');
const { transaction } = require('./db.cjs');
const money = require('./money.cjs');
const rules = require('./rules.cjs');

const CHANNELS = ['takeaway', 'dine', 'phone'];

class Store {
  constructor(db, { now = () => new Date(), uuid = () => crypto.randomUUID() } = {}) {
    this.db = db;
    this.now = now;
    this.uuid = uuid;
  }

  get(key, fallback = null) {
    const row = this.db.prepare('SELECT value FROM meta WHERE key = ?').get(key);
    return row ? JSON.parse(row.value) : fallback;
  }

  set(key, value) {
    this.db.prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
      .run(key, JSON.stringify(value));
  }

  // ---- device and accounts ----

  saveDevice(device, branches) {
    transaction(this.db, () => {
      this.set('device', device);
      this.db.prepare('DELETE FROM branches').run();
      const insert = this.db.prepare('INSERT INTO branches (value, kind, name, address, phone) VALUES (?, ?, ?, ?, ?)');
      for (const branch of branches) insert.run(branch.value, branch.kind || 'f', branch.name || '', branch.address || '', branch.phone || '');
    });
  }

  device() { return this.get('device'); }

  /** Drops the device's sign-up and downloaded accounts; invoices and unsent operations stay. */
  forgetDevice() {
    transaction(this.db, () => {
      this.db.prepare("DELETE FROM meta WHERE key IN ('device', 'users_hash')").run();
      this.db.prepare('DELETE FROM users').run();
    });
  }

  replaceUsers(users, hash) {
    transaction(this.db, () => {
      this.db.prepare('DELETE FROM users').run();
      const insert = this.db.prepare(`INSERT INTO users (id, name, mobile, email, account_type, password_hash, branches, can_checkout, can_manage,
        can_reprint, can_approve_expenses, can_close_shift) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
      for (const user of users) {
        insert.run(user.id, user.name, String(user.mobile || ''), String(user.email || ''), user.account_type, user.password_hash,
          JSON.stringify(user.branches || []), user.can_checkout ? 1 : 0, user.can_manage ? 1 : 0, user.can_reprint ? 1 : 0,
          user.can_approve_expenses ? 1 : 0, user.can_close_shift ? 1 : 0);
      }
      this.set('users_hash', hash);
    });
  }

  usersHash() { return this.get('users_hash'); }

  userCount() { return this.db.prepare('SELECT COUNT(*) AS count FROM users').get().count; }

  findUserByLogin(login) {
    const key = rules.loginKey(login);
    if (!key) return null;
    const rows = this.db.prepare('SELECT * FROM users').all();
    const row = rows.find((user) => rules.loginKey(user.mobile) === key || user.email.toLowerCase() === key);
    return row ? this.presentUser(row, true) : null;
  }

  user(id) {
    const row = this.db.prepare('SELECT * FROM users WHERE id = ?').get(id);
    return row ? this.presentUser(row, false) : null;
  }

  presentUser(row, withHash) {
    const user = { id: row.id, name: row.name, mobile: row.mobile, email: row.email, account_type: row.account_type,
      branches: JSON.parse(row.branches), can_checkout: !!row.can_checkout, can_manage: !!row.can_manage, can_reprint: !!row.can_reprint,
      can_approve_expenses: !!row.can_approve_expenses, can_close_shift: !!row.can_close_shift };
    if (withHash) user.password_hash = row.password_hash;
    return user;
  }

  // ---- branches and menus ----

  branches() {
    return this.db.prepare('SELECT value, kind, name, address, phone, available, snapshot_id, updated_at, catalog IS NOT NULL AS has_catalog FROM branches ORDER BY value').all()
      .map((row) => ({ ...row, available: !!row.available, has_catalog: !!row.has_catalog }));
  }

  catalogHashes() {
    const hashes = {};
    for (const row of this.db.prepare('SELECT value, snapshot_hash FROM branches WHERE catalog IS NOT NULL').all()) hashes[row.value] = row.snapshot_hash;
    return hashes;
  }

  applyBranches(entries) {
    transaction(this.db, () => {
      for (const entry of entries || []) {
        if (!entry.available) {
          this.db.prepare('UPDATE branches SET available = 0 WHERE value = ?').run(entry.value);
          continue;
        }
        if (entry.catalog) {
          const branch = entry.catalog.branch || {};
          this.db.prepare(`UPDATE branches SET available = 1, snapshot_id = ?, snapshot_hash = ?, catalog = ?, updated_at = ?,
            name = COALESCE(?, name), address = COALESCE(?, address), phone = COALESCE(?, phone) WHERE value = ?`)
            .run(entry.snapshot_id, entry.hash, JSON.stringify(entry.catalog), this.now().toISOString(),
              branch.name ?? null, branch.address ?? null, branch.phone ?? null, entry.value);
        } else {
          this.db.prepare('UPDATE branches SET available = 1, snapshot_id = ?, snapshot_hash = ? WHERE value = ? AND snapshot_hash = ?')
            .run(entry.snapshot_id, entry.hash, entry.value, entry.hash);
        }
      }
    });
  }

  catalog(branch) {
    const row = this.db.prepare('SELECT snapshot_id, catalog FROM branches WHERE value = ? AND available = 1').get(branch);
    if (!row || !row.catalog) return null;
    return { snapshot_id: row.snapshot_id, ...JSON.parse(row.catalog) };
  }

  // ---- open invoices ----

  openTickets(branch) {
    return this.db.prepare("SELECT * FROM tickets WHERE branch = ? AND status = 'open' ORDER BY created_at").all(branch).map(presentTicket);
  }

  ticket(id) {
    const row = this.db.prepare('SELECT * FROM tickets WHERE id = ?').get(id);
    return row ? presentTicket(row) : null;
  }

  saveTicket(actor, values) {
    const branch = String(values.branch || '');
    this.requireBranch(actor, branch, 'can_checkout');
    const channel = CHANNELS.includes(values.channel) ? values.channel : 'takeaway';
    const data = cleanTicketData(values.data || {});
    const now = this.now().toISOString();
    const label = String(values.label || '').slice(0, 60);
    if (values.id) {
      const existing = this.ticket(values.id);
      if (!existing || existing.status !== 'open') throw new Error('الفاتورة لم تعد مفتوحة.');
      this.db.prepare('UPDATE tickets SET channel = ?, label = ?, data = ?, updated_at = ? WHERE id = ?')
        .run(channel, label, JSON.stringify(data), now, values.id);
      return this.ticket(values.id);
    }
    const id = this.uuid();
    this.db.prepare(`INSERT INTO tickets (id, branch, channel, status, actor_id, actor_name, label, data, created_at, updated_at)
      VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?)`).run(id, branch, channel, actor.id, actor.name, label, JSON.stringify(data), now, now);
    return this.ticket(id);
  }

  deleteTicket(actor, id) {
    const ticket = this.ticket(id);
    if (!ticket || ticket.status !== 'open') return false;
    this.requireBranch(actor, ticket.branch, 'can_checkout');
    this.db.prepare("DELETE FROM tickets WHERE id = ? AND status = 'open'").run(id);
    return true;
  }

  /** Prices the invoice exactly as the server will, with the menu currently on the device. */
  quoteTicket(branch, channel, data) {
    const catalog = this.catalog(branch);
    if (!catalog) throw new Error('لم يتم تنزيل مينيو هذا الفرع بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    const discount = data.discount ? money.minor(data.discount) : 0;
    const delivery = channel === 'phone' && data.delivery ? money.minor(data.delivery) : 0;
    return { catalog, quote: money.quote(catalog, { channel, items: data.items || [], discount_cents: discount, delivery_cents: delivery }) };
  }

  /**
   * Finishes the sale: the paid invoice and the operation that sends it to the dashboard are
   * written in one transaction, so a sale is never lost or sent twice.
   */
  payTicket(actor, id, payment) {
    return transaction(this.db, () => {
      const ticket = this.ticket(id);
      if (!ticket || ticket.status !== 'open') throw new Error('الفاتورة لم تعد مفتوحة.');
      this.requireBranch(actor, ticket.branch, 'can_checkout');
      const data = cleanTicketData({ ...ticket.data, ...(payment.data || {}) });
      const { catalog, quote } = this.quoteTicket(ticket.branch, ticket.channel, data);
      if (quote.discount_cents > 0) {
        if (!actor.can_manage) throw new Error('الخصم متاح للمالك أو الإدارة المخولة فقط.');
        if (!data.discount_reason.trim()) throw new Error('اكتب سبب الخصم.');
      }
      const phone = rules.normalizePhone(data.customer_phone);
      if (!phone) throw new Error('اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
      if (ticket.channel === 'dine' && !data.table_name.trim()) throw new Error('أدخل رقم الطاولة.');
      if (ticket.channel === 'phone' && (!data.customer_name.trim() || !data.address.trim())) throw new Error('أكمل اسم العميل وعنوانه.');
      const cash = money.minor(payment.cash_received ?? '0');
      if (cash < quote.total_cents) throw new Error('المبلغ المستلم أقل من إجمالي الفاتورة.');
      const now = this.now();
      const day = rules.operatingDay(now);
      const number = this.nextNumber(ticket.branch, day);
      const operationId = this.uuid();
      const totals = { ...quote, cash_received_cents: cash, change_cents: cash - quote.total_cents };
      const operation = { id: operationId, kind: 'sale', actor_id: actor.id, branch: ticket.branch, occurred_at: now.toISOString(),
        data: { snapshot_id: catalog.snapshot_id, channel: ticket.channel,
          items: quote.items.map((line) => ({ product_id: line.product_id, option_id: line.option_id, quantity_mode: line.quantity_mode, quantity: line.quantity })),
          customer_phone: data.customer_phone.trim(), customer_name: data.customer_name.trim(), address: data.address.trim(),
          table_name: data.table_name.trim(), notes: data.notes.trim(), delivery_cents: quote.delivery_cents,
          discount: money.decimal(quote.discount_cents), discount_reason: data.discount_reason.trim(), cash_received: money.decimal(cash),
          total_cents: quote.total_cents, local_number: number, service_consent: !!data.service_consent, marketing_consent: !!data.marketing_consent } };
      const stamp = now.toISOString();
      this.db.prepare(`UPDATE tickets SET status = 'paid', actor_id = ?, actor_name = ?, data = ?, local_number = ?, business_date = ?,
        snapshot_id = ?, totals = ?, paid_at = ?, updated_at = ?, sync_status = 'pending' WHERE id = ?`)
        .run(actor.id, actor.name, JSON.stringify(data), number, day, catalog.snapshot_id, JSON.stringify(totals), stamp, stamp, id);
      this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'sale', ?, ?, 'pending', ?, ?)`)
        .run(operationId, id, JSON.stringify(operation), stamp, stamp);
      return this.ticket(id);
    });
  }

  nextNumber(branch, day) {
    const name = `number:${branch}:${day}`;
    this.db.prepare('INSERT INTO counters (name, value) VALUES (?, 1) ON CONFLICT(name) DO UPDATE SET value = value + 1').run(name);
    const value = this.db.prepare('SELECT value FROM counters WHERE name = ?').get(name).value;
    const device = this.device() || {};
    const code = String(device.code || 'D').toUpperCase();
    return `${code}-${day.slice(5).replace('-', '')}-${String(value).padStart(4, '0')}`;
  }

  requireBranch(actor, branch, permission) {
    if (!actor || !actor.branches.includes(branch)) throw new Error('الفرع غير متاح لهذا الحساب.');
    if (permission && !actor[permission]) throw new Error('الحساب غير مسموح له بهذه العملية.');
  }

  // ---- expenses and cash put in or taken out of the drawer ----

  /** The branch's active expense categories, as last synced with its menu. */
  expenseCategories(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.expense_categories) ? catalog.expense_categories : [];
  }

  /** Same checks as the dashboard's expenses page, so the expense is not refused later. */
  recordExpense(actor, branch, values) {
    this.requireBranch(actor, branch, 'can_checkout');
    const category = this.expenseCategories(branch).find((item) => item.key === values.category);
    if (!category) throw new Error('اختر بند المصروف.');
    const amount = money.minor(String(values.amount ?? '').trim());
    if (amount <= 0 || amount > 100000000) throw new Error('قيمة المصروف أكبر من صفر وبحد أقصى مليون جنيه.');
    const text = (value, max) => String(value ?? '').trim().slice(0, max);
    const description = text(values.description, 500);
    let quantity = text(values.quantity_kg, 14).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace('٫', '.');
    if (category.stock) {
      if (!/^[0-9]{1,7}(\.[0-9]{1,3})?$/.test(quantity) || Number(quantity) <= 0 || Number(quantity) > 1000000) {
        throw new Error('أدخل عدد الكيلوات لهذا البند (حتى ٣ منازل عشرية).');
      }
    } else {
      if (quantity) throw new Error('عدد الكيلوات متاح لبنود مشتريات البضاعة فقط.');
      quantity = '';
      const material = rules.stockMaterial(description);
      if (branch.startsWith('f:') && ['purchases', 'other'].includes(category.key) && material) {
        throw new Error(`اختر بند مشتريات ${material} وأدخل الكمية بالكيلو لإضافتها إلى البضاعة.`);
      }
    }
    const approve = !!values.approve;
    if (approve && !actor.can_approve_expenses) throw new Error('اعتماد المصروفات للمالك والإدارة فقط.');
    const data = { category: category.key, description, amount: money.decimal(amount), supplier: text(values.supplier, 150),
      notes: text(values.notes, 1000), quantity_kg: quantity, approve };
    return this.addJournal(actor, branch, 'expense', amount, data, { category_name: category.name });
  }

  /** Cash a manager puts in or takes out of the drawer, with its reason. */
  recordTill(actor, branch, values) {
    this.requireBranch(actor, branch, 'can_manage');
    const direction = values.direction === 'out' ? 'out' : 'in';
    const amount = money.minor(String(values.amount ?? '').trim());
    if (amount <= 0 || amount > 100000000) throw new Error('المبلغ أكبر من صفر وبحد أقصى مليون جنيه.');
    const note = String(values.note ?? '').trim().slice(0, 500);
    if (!note) throw new Error('اكتب سبب العملية.');
    return this.addJournal(actor, branch, 'till', amount, { direction, amount: money.decimal(amount), note }, {});
  }

  /** The record and the operation that sends it to the dashboard are saved together. */
  addJournal(actor, branch, kind, amount, data, extra) {
    return transaction(this.db, () => {
      const now = this.now();
      const stamp = now.toISOString();
      const id = this.uuid();
      const operation = { id, kind, actor_id: actor.id, branch, occurred_at: stamp, data };
      this.db.prepare(`INSERT INTO journal (id, kind, branch, actor_id, actor_name, business_date, amount_cents, data, created_at, sync_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')`)
        .run(id, kind, branch, actor.id, actor.name, rules.operatingDay(now), amount, JSON.stringify({ ...data, ...extra }), stamp);
      this.db.prepare(`INSERT INTO outbox (id, kind, journal_id, payload, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?)`)
        .run(id, kind, id, JSON.stringify(operation), stamp, stamp);
      return this.journalEntry(id);
    });
  }

  journalEntry(id) {
    const row = this.db.prepare('SELECT * FROM journal WHERE id = ?').get(id);
    return row ? { ...row, data: JSON.parse(row.data) } : null;
  }

  journal(branch, day) {
    return this.db.prepare('SELECT * FROM journal WHERE branch = ? AND business_date = ? ORDER BY created_at DESC').all(branch, day)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }));
  }

  /**
   * What went through the drawer on this device today. The shift is still closed on the dashboard,
   * which adds the other devices, app orders and the opening cash.
   */
  cashSummary(branch, day) {
    const sales = this.db.prepare(`SELECT COUNT(*) AS count, COALESCE(SUM(json_extract(totals, '$.total_cents')), 0) AS total,
      COALESCE(SUM(json_extract(totals, '$.delivery_cents')), 0) AS delivery
      FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ?`).get(branch, day);
    const sum = (where, ...args) => this.db.prepare(`SELECT COALESCE(SUM(amount_cents), 0) AS total, COUNT(*) AS count FROM journal
      WHERE branch = ? AND business_date = ? AND ${where}`).get(branch, day, ...args);
    const cashIn = sum("kind = 'till' AND json_extract(data, '$.direction') = 'in' AND sync_status != 'rejected'");
    const cashOut = sum("kind = 'till' AND json_extract(data, '$.direction') = 'out' AND sync_status != 'rejected'");
    const approved = sum("kind = 'expense' AND json_extract(data, '$.approve') = 1 AND sync_status != 'rejected'");
    const pending = sum("kind = 'expense' AND json_extract(data, '$.approve') = 0 AND sync_status != 'rejected'");
    const salesCash = sales.total - sales.delivery;
    return { day, sales_count: sales.count, sales_cents: sales.total, delivery_cents: sales.delivery, sales_cash_cents: salesCash,
      cash_in_cents: cashIn.total, cash_out_cents: cashOut.total, approved_expenses_cents: approved.total,
      pending_expenses_cents: pending.total, pending_expenses_count: pending.count,
      drawer_movement_cents: salesCash + cashIn.total - cashOut.total - approved.total };
  }

  // ---- invoices and daily totals ----

  receipts(branch, day) {
    return this.db.prepare("SELECT * FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ? ORDER BY paid_at DESC").all(branch, day).map(presentTicket);
  }

  daySummary(branch, day) {
    const row = this.db.prepare(`SELECT COUNT(*) AS count, COALESCE(SUM(json_extract(totals, '$.total_cents')), 0) AS total,
      SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN sync_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
      FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ?`).get(branch, day);
    return { day, count: row.count, total_cents: row.total, total: money.decimal(row.total), pending: row.pending || 0, rejected: row.rejected || 0 };
  }

  // ---- reports from this device's own sales ----

  /** Sales of this device between two working days: totals, by channel, by cashier, by day and by item. */
  salesReport(branch, from, to) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || to < from) throw new Error('راجع الفترة المطلوبة.');
    const rows = this.db.prepare(`SELECT channel, actor_id, actor_name, business_date, totals FROM tickets
      WHERE branch = ? AND status = 'paid' AND business_date BETWEEN ? AND ? ORDER BY paid_at`).all(branch, from, to);
    const sums = { count: 0, subtotal_cents: 0, discount_cents: 0, service_cents: 0, delivery_cents: 0, tax_cents: 0, total_cents: 0 };
    const group = (map, key, make) => { if (!map.has(key)) map.set(key, make()); return map.get(key); };
    const channels = new Map();
    const cashiers = new Map();
    const days = new Map();
    const items = new Map();
    for (const row of rows) {
      const totals = JSON.parse(row.totals);
      sums.count++;
      for (const key of ['subtotal_cents', 'discount_cents', 'service_cents', 'delivery_cents', 'tax_cents', 'total_cents']) sums[key] += totals[key] || 0;
      for (const [map, key, extra] of [[channels, row.channel, { channel: row.channel }], [cashiers, row.actor_id, { actor_id: row.actor_id, name: row.actor_name }],
        [days, row.business_date, { day: row.business_date }]]) {
        const entry = group(map, key, () => ({ ...extra, count: 0, total_cents: 0 }));
        entry.count++;
        entry.total_cents += totals.total_cents;
      }
      for (const line of totals.items || []) {
        const key = [line.product_id, line.option_id || '', line.quantity_mode].join('|');
        const entry = group(items, key, () => ({ name: line.name, option_label: line.option_label === 'الأساسي' ? '' : (line.option_label || ''),
          quantity_mode: line.quantity_mode, unit_mode: line.unit_mode || '', quantity_millis: 0, total_cents: 0, invoices: 0 }));
        entry.quantity_millis += line.quantity_millis;
        entry.total_cents += line.total_cents;
        entry.invoices++;
      }
    }
    const byTotal = (a, b) => b.total_cents - a.total_cents;
    return { branch, from, to, ...sums, channels: [...channels.values()].sort(byTotal), cashiers: [...cashiers.values()].sort(byTotal),
      days: [...days.values()].sort((a, b) => (a.day < b.day ? -1 : 1)), items: [...items.values()].sort(byTotal) };
  }

  // ---- the queue of operations for the dashboard ----

  /** Only operations the server said it knows; others wait on the device until the server is updated. */
  pendingOperations(limit = 50, kinds = null) {
    const known = Array.isArray(kinds) ? kinds.filter((kind) => typeof kind === 'string') : null;
    if (known && !known.length) return [];
    const filter = known ? ` AND kind IN (${known.map(() => '?').join(', ')})` : '';
    return this.db.prepare(`SELECT id, payload FROM outbox WHERE status = 'pending'${filter} ORDER BY created_at LIMIT ?`).all(...(known || []), limit)
      .map((row) => JSON.parse(row.payload));
  }

  applyResults(results) {
    transaction(this.db, () => {
      const now = this.now().toISOString();
      for (const result of results || []) {
        const row = this.db.prepare('SELECT id, ticket_id, journal_id FROM outbox WHERE id = ?').get(result.id);
        if (!row) continue;
        if (result.status === 'applied') {
          this.db.prepare("UPDATE outbox SET status = 'done', result = ?, last_error = NULL, updated_at = ? WHERE id = ?").run(JSON.stringify(result), now, row.id);
          const receipt = result.receipt || {};
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, server_number = ? WHERE id = ?")
            .run(receipt.id ?? null, receipt.number ?? null, row.ticket_id);
          if (row.journal_id) {
            const reference = result.expense ? `${result.expense.number} · ${result.expense.status === 'approved' ? 'معتمد' : 'بانتظار الاعتماد'}` : null;
            this.db.prepare("UPDATE journal SET sync_status = 'synced', sync_error = NULL, server_ref = ? WHERE id = ?").run(reference, row.journal_id);
          }
        } else if (result.status === 'rejected') {
          this.db.prepare("UPDATE outbox SET status = 'rejected', result = ?, last_error = ?, updated_at = ? WHERE id = ?")
            .run(JSON.stringify(result), result.error || 'تم رفض العملية.', now, row.id);
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.ticket_id);
          if (row.journal_id) this.db.prepare("UPDATE journal SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.journal_id);
        } else {
          this.db.prepare('UPDATE outbox SET attempts = attempts + 1, updated_at = ? WHERE id = ?').run(now, row.id);
        }
      }
    });
  }

  outboxStats() {
    const row = this.db.prepare(`SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected FROM outbox`).get();
    return { pending: row.pending || 0, rejected: row.rejected || 0 };
  }

  rejectedOperations() {
    const labels = { sale: 'فاتورة', expense: 'مصروف', till: 'خزنة' };
    return this.db.prepare(`SELECT o.id, o.kind, o.last_error, o.updated_at, t.local_number, t.branch, t.totals, j.amount_cents, j.branch AS journal_branch
      FROM outbox o LEFT JOIN tickets t ON t.id = o.ticket_id LEFT JOIN journal j ON j.id = o.journal_id
      WHERE o.status = 'rejected' ORDER BY o.updated_at DESC LIMIT 200`).all()
      .map((row) => ({ id: row.id, kind: labels[row.kind] || row.kind, error: row.last_error, at: row.updated_at,
        local_number: row.local_number, branch: row.branch || row.journal_branch,
        total: row.totals ? money.decimal(JSON.parse(row.totals).total_cents) : (row.amount_cents != null ? money.decimal(row.amount_cents) : null) }));
  }
}

function cleanTicketData(data) {
  const text = (value, max) => String(value ?? '').slice(0, max);
  const items = Array.isArray(data.items) ? data.items.slice(0, 100).map((item) => ({
    product_id: Number(item.product_id), option_id: String(item.option_id || ''),
    quantity_mode: item.quantity_mode === 'weight' ? 'weight' : 'piece', quantity: String(item.quantity || ''),
  })) : [];
  return { items, customer_phone: text(data.customer_phone, 30), customer_name: text(data.customer_name, 100), address: text(data.address, 500),
    table_name: text(data.table_name, 100), notes: text(data.notes, 500), discount: text(data.discount, 14), discount_reason: text(data.discount_reason, 500),
    delivery: text(data.delivery, 14), service_consent: !!data.service_consent, marketing_consent: !!data.marketing_consent };
}

function presentTicket(row) {
  return { ...row, data: JSON.parse(row.data), totals: row.totals ? JSON.parse(row.totals) : null };
}

module.exports = { Store, cleanTicketData };
