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
      const insert = this.db.prepare(`INSERT INTO users (id, name, mobile, email, account_type, password_hash, branches, can_checkout, can_manage, can_reprint)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`);
      for (const user of users) {
        insert.run(user.id, user.name, String(user.mobile || ''), String(user.email || ''), user.account_type, user.password_hash,
          JSON.stringify(user.branches || []), user.can_checkout ? 1 : 0, user.can_manage ? 1 : 0, user.can_reprint ? 1 : 0);
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
      branches: JSON.parse(row.branches), can_checkout: !!row.can_checkout, can_manage: !!row.can_manage, can_reprint: !!row.can_reprint };
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

  // ---- the queue of operations for the dashboard ----

  pendingOperations(limit = 50) {
    return this.db.prepare("SELECT id, payload FROM outbox WHERE status = 'pending' ORDER BY created_at LIMIT ?").all(limit)
      .map((row) => JSON.parse(row.payload));
  }

  applyResults(results) {
    transaction(this.db, () => {
      const now = this.now().toISOString();
      for (const result of results || []) {
        const row = this.db.prepare('SELECT id, ticket_id FROM outbox WHERE id = ?').get(result.id);
        if (!row) continue;
        if (result.status === 'applied') {
          this.db.prepare("UPDATE outbox SET status = 'done', result = ?, last_error = NULL, updated_at = ? WHERE id = ?").run(JSON.stringify(result), now, row.id);
          const receipt = result.receipt || {};
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, server_number = ? WHERE id = ?")
            .run(receipt.id ?? null, receipt.number ?? null, row.ticket_id);
        } else if (result.status === 'rejected') {
          this.db.prepare("UPDATE outbox SET status = 'rejected', result = ?, last_error = ?, updated_at = ? WHERE id = ?")
            .run(JSON.stringify(result), result.error || 'تم رفض العملية.', now, row.id);
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.ticket_id);
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
    return this.db.prepare(`SELECT o.id, o.last_error, o.updated_at, t.local_number, t.branch, t.totals FROM outbox o
      LEFT JOIN tickets t ON t.id = o.ticket_id WHERE o.status = 'rejected' ORDER BY o.updated_at DESC LIMIT 200`).all()
      .map((row) => ({ id: row.id, error: row.last_error, at: row.updated_at, local_number: row.local_number, branch: row.branch,
        total: row.totals ? money.decimal(JSON.parse(row.totals).total_cents) : null }));
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
