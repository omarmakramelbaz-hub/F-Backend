'use strict';
// Store: The device, its accounts and branches, and their menus.
const { transaction } = require('../db.cjs');
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { presentTicket } = require('./shared.cjs');

module.exports = {
  get(key, fallback = null) {
    const row = this.db.prepare('SELECT value FROM meta WHERE key = ?').get(key);
    return row ? JSON.parse(row.value) : fallback;
  },

set(key, value) {
    this.db.prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
      .run(key, JSON.stringify(value));
  },

// ---- device and accounts ----

  saveDevice(device, branches) {
    transaction(this.db, () => {
      this.set('device', device);
      this.db.prepare('DELETE FROM branches').run();
      const insert = this.db.prepare('INSERT INTO branches (value, kind, name, address, phone) VALUES (?, ?, ?, ?, ?)');
      for (const branch of branches) insert.run(branch.value, branch.kind || 'f', branch.name || '', branch.address || '', branch.phone || '');
    });
  },

device() { return this.get('device'); },

/**
   * Drops the device's sign-up and downloaded accounts; invoices and unsent operations stay. The saved delivery
   * customers are read again from the start by the next setup (it may cover other branches).
   */
  forgetDevice() {
    transaction(this.db, () => {
      this.db.prepare("DELETE FROM meta WHERE key IN ('device', 'users_hash', 'customers_cursor', 'remembered_sign_in')").run();
      this.db.prepare('DELETE FROM users').run();
      this.db.prepare('DELETE FROM directory').run();
    });
  },

/** What dropping this device's setup now would leave behind: operations not sent yet, and orders still open here. */
  releaseBlockers() {
    return { pending: this.db.prepare("SELECT COUNT(*) AS c FROM outbox WHERE status = 'pending'").get().c
      + this.db.prepare("SELECT COUNT(*) AS c FROM board_commands WHERE status = 'pending'").get().c,
      open: this.db.prepare("SELECT COUNT(*) AS c FROM tickets WHERE status = 'open'").get().c };
  },

/**
   * The orders still open on this device, as the person sees them before setting it up again: what it is, where it was
   * opened, and why it did not move to the dashboard yet.
   */
  openOrders() {
    const branches = new Map(this.branches().map((branch) => [branch.value, branch.name]));
    const moves = new Map(this.db.prepare("SELECT id, status FROM outbox WHERE kind = 'ticket_open'").all().map((row) => [row.id, row.status]));
    return this.db.prepare("SELECT * FROM tickets WHERE status = 'open' ORDER BY created_at").all().map((row) => {
      const ticket = presentTicket(row);
      const data = ticket.data || {};
      const page = data.source === 'dashboard';
      let priced = page ? data.priced : null;
      if (!priced) {
        try { priced = this.quoteTicket(ticket.branch, ticket.channel, data).quote; } catch { priced = null; }
      }
      // 2.000 → 2 and 1.500 → 1.5, as people write them.
      const amount = (value) => { const text = String(value ?? ''); return /^\d+\.\d+$/.test(text) ? text.replace(/0+$/, '').replace(/\.$/, '') : text; };
      const items = priced && Array.isArray(priced.items)
        ? priced.items.map((line) => `${line.name}${line.option_label ? ` (${line.option_label})` : ''} × ${amount(line.quantity)}`)
        : (data.items || []).map(() => 'صنف');
      const move = moves.get(ticket.id);
      const where = !page ? `الكاشير البديل في البرنامج${data.out_at ? ' — خرج مع المندوب' : ''}`
        : `${ticket.channel === 'phone' ? 'صفحة طلبات الدليفري' : 'صفحة طلبات الصالة'} (اتفتح من غير نت)`;
      const problem = move === 'rejected' ? `الداشبورد رفض نقله: ${ticket.sync_error || 'تم رفض العملية.'}`
        : move === 'pending' ? 'لسه بيتنقل للداشبورد.' : '';
      return { id: ticket.id, branch: ticket.branch, branch_name: branches.get(ticket.branch) || ticket.branch, channel: ticket.channel,
        label: ticket.label || (data.table && data.table.name) || data.customer_name || '', created_at: ticket.created_at, actor_name: ticket.actor_name,
        items, total: priced && Number.isFinite(priced.total_cents) ? money.decimal(priced.total_cents) : '', where, problem };
    });
  },

/**
   * The person cancels an order still open here (to set the device up again): an order of the device's cashier is
   * deleted, as its own delete button does; one opened on the dashboard's page is cancelled and never sent.
   */
  dropOpenOrder(actor, id) {
    const ticket = this.ticket(id);
    if (!ticket || ticket.status !== 'open') return false;
    this.requireBranch(actor, ticket.branch, 'can_checkout');
    return transaction(this.db, () => {
      if (ticket.data && ticket.data.source === 'dashboard') {
        if (!this.cancelTicketMove(id)) throw new Error('الطلب ده بيتنقل للداشبورد دلوقتي. استنى ثواني وحاول تاني.');
        const data = { ...ticket.data, status: 'cancelled', cancel_reason: 'اتلغى من الجهاز قبل إعادة تجهيزه' };
        delete data.move_op;
        this.updatePageTicket(id, data, 'cancelled');
      } else {
        this.db.prepare("DELETE FROM tickets WHERE id = ? AND status = 'open'").run(id);
      }
      return true;
    });
  },

/** The downloaded dashboard password hash of an account on this device (to confirm it is really that person). */
  passwordHash(userId) {
    const row = this.db.prepare('SELECT password_hash FROM users WHERE id = ?').get(userId);
    return row ? row.password_hash : null;
  },

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
  },

usersHash() { return this.get('users_hash'); },

userCount() { return this.db.prepare('SELECT COUNT(*) AS count FROM users').get().count; },

/**
   * Same as the dashboard: the typed value without its first zero must equal the stored mobile as it is
   * (so a mobile stored as 0502353511 is typed 00502353511), or the email. The number typed exactly as
   * stored is also accepted, unless the dashboard's own rule already finds an account.
   */
  findUserByLogin(login) {
    const typed = rules.latinDigits(login).trim();
    const key = rules.loginKey(typed);
    if (!key) return null;
    const rows = this.db.prepare('SELECT * FROM users').all();
    const row = rows.find((user) => String(user.mobile).trim() === key || String(user.email).trim().toLowerCase() === key)
      || rows.find((user) => String(user.mobile).trim() === typed);
    return row ? this.presentUser(row, true) : null;
  },

user(id) {
    const row = this.db.prepare('SELECT * FROM users WHERE id = ?').get(id);
    return row ? this.presentUser(row, false) : null;
  },

presentUser(row, withHash) {
    const user = { id: row.id, name: row.name, mobile: row.mobile, email: row.email, account_type: row.account_type,
      branches: JSON.parse(row.branches), can_checkout: !!row.can_checkout, can_manage: !!row.can_manage, can_reprint: !!row.can_reprint,
      can_approve_expenses: !!row.can_approve_expenses, can_close_shift: !!row.can_close_shift };
    if (withHash) user.password_hash = row.password_hash;
    return user;
  },

// ---- branches and menus ----

  branches() {
    return this.db.prepare('SELECT value, kind, name, address, phone, available, snapshot_id, updated_at, catalog IS NOT NULL AS has_catalog FROM branches ORDER BY value').all()
      .map((row) => ({ ...row, available: !!row.available, has_catalog: !!row.has_catalog }));
  },

catalogHashes() {
    const hashes = {};
    for (const row of this.db.prepare('SELECT value, snapshot_hash FROM branches WHERE catalog IS NOT NULL').all()) hashes[row.value] = row.snapshot_hash;
    return hashes;
  },

applyBranches(entries) {
    transaction(this.db, () => {
      for (const entry of entries || []) {
        if (Array.isArray(entry.attendance)) {
          this.db.prepare('UPDATE branches SET attendance = ? WHERE value = ?').run(JSON.stringify(entry.attendance), entry.value);
        }
        if (entry.today && typeof entry.today === 'object') {
          // received_at: invoices this device sent before this moment are already in the server's count.
          this.db.prepare('UPDATE branches SET today = ? WHERE value = ?')
            .run(JSON.stringify({ ...entry.today, received_at: this.now().toISOString() }), entry.value);
        }
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
  },

catalog(branch) {
    const row = this.db.prepare('SELECT snapshot_id, catalog FROM branches WHERE value = ? AND available = 1').get(branch);
    if (!row || !row.catalog) return null;
    return { snapshot_id: row.snapshot_id, ...JSON.parse(row.catalog) };
  },

requireBranch(actor, branch, permission) {
    if (!actor || !actor.branches.includes(branch)) throw new Error('الفرع غير متاح لهذا الحساب.');
    if (permission && !actor[permission]) throw new Error('الحساب غير مسموح له بهذه العملية.');
  },
};
