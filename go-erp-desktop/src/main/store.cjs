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
  }

  /** What dropping this device's setup now would leave behind: operations not sent yet, and orders still open here. */
  releaseBlockers() {
    return { pending: this.db.prepare("SELECT COUNT(*) AS c FROM outbox WHERE status = 'pending'").get().c
      + this.db.prepare("SELECT COUNT(*) AS c FROM board_commands WHERE status = 'pending'").get().c,
      open: this.db.prepare("SELECT COUNT(*) AS c FROM tickets WHERE status = 'open'").get().c };
  }

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
  }

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
  }

  /** The downloaded dashboard password hash of an account on this device (to confirm it is really that person). */
  passwordHash(userId) {
    const row = this.db.prepare('SELECT password_hash FROM users WHERE id = ?').get(userId);
    return row ? row.password_hash : null;
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

  /** A delivery order leaves with the driver: it stays open until the driver returns with the money. */
  markOut(actor, id, values = {}) {
    const ticket = this.ticket(id);
    if (!ticket || ticket.status !== 'open') throw new Error('الفاتورة لم تعد مفتوحة.');
    this.requireBranch(actor, ticket.branch, 'can_checkout');
    if (ticket.channel !== 'phone') throw new Error('خروج المندوب للطلبات الدليفري فقط.');
    const data = cleanTicketData({ ...ticket.data, ...values, out_at: this.now().toISOString() });
    if (!data.customer_name.trim() || !data.address.trim()) throw new Error('أكمل اسم العميل وعنوانه.');
    if (!rules.normalizePhone(data.customer_phone)) throw new Error('اكتب رقم هاتف صحيح للعميل.');
    if (!data.delivery_company_id && !data.driver.trim()) throw new Error('اختر شركة التوصيل أو اكتب اسم المندوب.');
    this.quoteTicket(ticket.branch, ticket.channel, data);
    this.db.prepare('UPDATE tickets SET data = ?, updated_at = ? WHERE id = ?').run(JSON.stringify(data), this.now().toISOString(), id);
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
      this.completeSale(actor, ticket, data, catalog, quote, cash, phone, { operationId: this.uuid(), insert: false });
      return this.ticket(id);
    });
  }

  /**
   * A sale finished on the dashboard's own cashier page while offline (checked by the page's local answers).
   * The page's operation number (idempotency_key) is the invoice's and the operation's id: a retry returns
   * the same invoice, and the dashboard recognises an invoice that reached it just before the connection dropped.
   */
  pageSale(actor, values) {
    return transaction(this.db, () => {
      const existing = this.ticket(values.id);
      if (existing) {
        if (existing.actor_id !== actor.id || existing.branch !== values.branch || existing.data.request_hash !== values.request_hash) {
          const error = new Error('تم استخدام رقم العملية لفاتورة مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ticket: existing, replayed: true };
      }
      this.requireBranch(actor, values.branch, 'can_checkout');
      const phone = rules.normalizePhone(values.data.customer_phone);
      if (!phone) throw new Error('اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
      const data = { ...cleanTicketData(values.data), request_hash: values.request_hash, source: 'dashboard' };
      const ticket = { id: values.id, branch: values.branch, channel: 'takeaway' };
      this.completeSale(actor, ticket, data, values.catalog, values.quote, values.cash_cents, phone, { operationId: values.id, insert: true });
      return { ticket: this.ticket(values.id), replayed: false };
    });
  }

  /** The paid invoice and the operation that sends it to the dashboard, written together. */
  completeSale(actor, ticket, data, catalog, quote, cash, phone, { operationId, insert }) {
    // Used by the device's screens and by the dashboard pages' offline answers.
    const now = this.now();
    const day = rules.operatingDay(now);
    const number = this.nextNumber(ticket.branch, day);
    const seq = ticket.seq || this.nextCounter('receipt_seq');
    const firstPrint = crypto.randomBytes(32).toString('hex');
    const totals = { ...quote, cash_received_cents: cash, change_cents: cash - quote.total_cents };
    const operation = { id: operationId, kind: 'sale', actor_id: actor.id, branch: ticket.branch, occurred_at: now.toISOString(),
      data: { snapshot_id: catalog.snapshot_id, channel: ticket.channel,
        items: quote.items.map((line) => ({ product_id: line.product_id, option_id: line.option_id, quantity_mode: line.quantity_mode, quantity: line.quantity })),
        customer_phone: data.customer_phone.trim(), customer_name: data.customer_name.trim(), address: data.address.trim(),
        table_name: data.table_name.trim(), notes: data.notes.trim(), delivery_cents: quote.delivery_cents,
        discount: money.decimal(quote.discount_cents), discount_reason: data.discount_reason.trim(), cash_received: money.decimal(cash),
        total_cents: quote.total_cents, local_number: number, service_consent: !!data.service_consent, marketing_consent: !!data.marketing_consent,
        ...(ticket.channel === 'phone' ? { delivery_company_id: data.delivery_company_id, driver: data.driver.trim() } : {}) } };
    const stamp = now.toISOString();
    if (insert) {
      this.db.prepare(`INSERT INTO tickets (id, branch, channel, status, actor_id, actor_name, label, data, local_number, business_date,
        snapshot_id, totals, created_at, updated_at, paid_at, sync_status, seq, first_print) VALUES (?, ?, ?, 'paid', ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)`)
        .run(ticket.id, ticket.branch, ticket.channel, actor.id, actor.name, JSON.stringify(data), number, day, catalog.snapshot_id,
          JSON.stringify(totals), stamp, stamp, stamp, seq, firstPrint);
    } else {
      this.db.prepare(`UPDATE tickets SET status = 'paid', actor_id = ?, actor_name = ?, data = ?, local_number = ?, business_date = ?,
        snapshot_id = ?, totals = ?, paid_at = ?, updated_at = ?, sync_status = 'pending', seq = COALESCE(seq, ?), first_print = ? WHERE id = ?`)
        .run(actor.id, actor.name, JSON.stringify(data), number, day, catalog.snapshot_id, JSON.stringify(totals), stamp, stamp, seq, firstPrint, ticket.id);
    }
    this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'sale', ?, ?, 'pending', ?, ?)`)
      .run(operationId, ticket.id, JSON.stringify(operation), stamp, stamp);
    this.rememberCustomer(phone, data, quote.total_cents, stamp);
  }

  // ---- the dashboard's dine-in and delivery pages offline ----

  /** Runs work in one transaction; inside another one it simply joins it. */
  atomically(work) {
    if (this.depth) return work();
    this.depth = 1;
    try { return transaction(this.db, work); } finally { this.depth = 0; }
  }

  /** The branches' tables and open orders as the server last sent them (only when they changed). */
  applyService(entries) {
    transaction(this.db, () => {
      for (const entry of entries || []) {
        if (!entry || !entry.value || !Array.isArray(entry.tickets)) continue;
        this.db.prepare('UPDATE branches SET service = ?, service_hash = ? WHERE value = ?')
          .run(JSON.stringify({ tables: entry.tables || [], policy: entry.policy || {}, tickets: entry.tickets, delivery: entry.delivery ?? null,
            shift: entry.shift ?? null, received_at: this.now().toISOString() }),
            entry.hash, entry.value);
      }
    });
  }

  serviceHashes() {
    const hashes = {};
    for (const row of this.db.prepare('SELECT value, service_hash FROM branches WHERE service IS NOT NULL').all()) hashes[row.value] = row.service_hash;
    return hashes;
  }

  service(branch) {
    const row = this.db.prepare('SELECT service FROM branches WHERE value = ?').get(branch);
    return row && row.service ? JSON.parse(row.service) : null;
  }

  /**
   * What this device did to an order open on the dashboard (sent to the kitchen, bill printed, paid), shown on top of
   * the server's copy until the dashboard has it: once none of its operations waits to be sent, the copy is the truth.
   */
  serviceOverlay(ticketId) {
    const row = this.db.prepare('SELECT data FROM service_overlays WHERE ticket_id = ?').get(ticketId);
    if (!row) return null;
    const overlay = JSON.parse(row.data);
    const ops = overlay.ops || [];
    const waiting = ops.length && this.db.prepare(`SELECT COUNT(*) AS count FROM outbox WHERE status = 'pending' AND id IN (${ops.map(() => '?').join(', ')})`)
      .get(...ops).count;
    if (waiting) return overlay;
    this.db.prepare('DELETE FROM service_overlays WHERE ticket_id = ?').run(ticketId);
    return null;
  }

  /** Saves the overlay of a dashboard order together with the operation that tells the dashboard. */
  overlayServerTicket(ticketId, overlay, operation) {
    return this.atomically(() => {
      const stamp = this.now().toISOString();
      const payload = { ...operation, occurred_at: stamp };
      this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, ?, NULL, ?, 'pending', ?, ?)`)
        .run(operation.id, operation.kind, JSON.stringify(payload), stamp, stamp);
      const data = { ...overlay, ops: [...(overlay.ops || []), operation.id] };
      this.db.prepare('INSERT INTO service_overlays (ticket_id, data) VALUES (?, ?) ON CONFLICT(ticket_id) DO UPDATE SET data = excluded.data')
        .run(ticketId, JSON.stringify(data));
      return data;
    });
  }

  pageCommand(key) {
    const row = this.db.prepare('SELECT rowid AS seq, * FROM page_commands WHERE key = ?').get(key);
    return row ? { ...row, meta: row.meta ? JSON.parse(row.meta) : {} } : null;
  }

  pageCommandBySeq(seq) {
    const row = this.db.prepare('SELECT rowid AS seq, * FROM page_commands WHERE rowid = ?').get(seq);
    return row ? { ...row, meta: row.meta ? JSON.parse(row.meta) : {} } : null;
  }

  setPageCommandMeta(key, meta) {
    this.db.prepare('UPDATE page_commands SET meta = ? WHERE key = ?').run(JSON.stringify(meta), key);
  }

  /** An operation the dashboard pages' offline answers send (other than invoices), queued as it is. */
  queueOperation(operation) {
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, ?, NULL, ?, 'pending', ?, ?)`)
      .run(operation.id, operation.kind, JSON.stringify({ ...operation, occurred_at: stamp }), stamp, stamp);
  }

  /** The page's own operation number (idempotency_key) with what it did, so a retry gets the same answer. */
  recordPageCommand(key, kind, ticketRef, revision, actor, branch, meta = {}) {
    const result = this.db.prepare('INSERT INTO page_commands (key, kind, ticket_ref, revision, actor_id, branch, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      .run(key, kind, String(ticketRef), revision, actor.id, branch, JSON.stringify(meta), this.now().toISOString());
    return this.pageCommand(key) || { seq: Number(result.lastInsertRowid) };
  }

  /** A kitchen ticket printed here; job: the branch printer prints it (delivery orders, like the dashboard's print queue). */
  addKitchenPrint(ticketRef, revision, snapshot, { job = false, branch = null } = {}) {
    const result = this.db.prepare('INSERT INTO kitchen_prints (ticket_ref, revision, snapshot, created_at, branch, job_status) VALUES (?, ?, ?, ?, ?, ?)')
      .run(String(ticketRef), revision, JSON.stringify(snapshot), this.now().toISOString(), branch, job ? 'pending' : null);
    return Number(result.lastInsertRowid);
  }

  /** The branch printer's waiting kitchen tickets of orders placed here (PosBranchPrinting::listing). */
  kitchenJobs(branch) {
    return this.db.prepare("SELECT seq, ticket_ref, job_status FROM kitchen_prints WHERE branch = ? AND job_status IS NOT NULL ORDER BY seq").all(branch);
  }

  claimKitchenJob(seq, token) {
    return this.db.prepare("UPDATE kitchen_prints SET job_status = 'claimed', claim_token = ? WHERE seq = ? AND job_status = 'pending'").run(token, seq).changes > 0;
  }

  completeKitchenJob(seq, token, result) {
    const row = this.db.prepare('SELECT job_status, claim_token FROM kitchen_prints WHERE seq = ?').get(seq);
    if (!row || row.claim_token !== token || !['claimed', result].includes(row.job_status)) return false;
    this.db.prepare('UPDATE kitchen_prints SET job_status = ? WHERE seq = ?').run(result, seq);
    return true;
  }

  // ---- the branches' saved delivery customers (new delivery orders offline) ----

  customersCursor() {
    return this.get('customers_cursor', null);
  }

  /** A page of the dashboard's saved customers changed since the device's cursor. Returns whether more are waiting. */
  applyCustomers(page) {
    if (!page || !Array.isArray(page.items)) return false;
    transaction(this.db, () => {
      const upsert = this.db.prepare(`INSERT INTO directory (id, branch, name, phone, phone_key, address, area, delivery_notes, latitude, longitude, revision,
        local, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?) ON CONFLICT(id) DO UPDATE SET branch = excluded.branch, name = excluded.name,
        phone = excluded.phone, phone_key = excluded.phone_key, address = excluded.address, area = excluded.area, delivery_notes = excluded.delivery_notes,
        latitude = excluded.latitude, longitude = excluded.longitude, revision = excluded.revision, local = 0, updated_at = excluded.updated_at`);
      const stamp = this.now().toISOString();
      for (const item of page.items) {
        upsert.run(item.id, item.branch, item.name || '', item.phone || '', item.phone_key || '', item.address || '', item.area || '', item.delivery_notes || '',
          item.latitude ?? null, item.longitude ?? null, item.revision || 1, stamp);
        // Saved here before: the dashboard's record (same number) now stands for it.
        this.db.prepare('DELETE FROM directory WHERE local = 1 AND branch = ? AND phone_key = ?').run(item.branch, item.phone_key || '');
      }
      if (page.cursor) this.set('customers_cursor', page.cursor);
    });
    return !!page.more;
  }

  /** Saved customers of the branch with one of these numbers (or starting with them). */
  directoryByPhone(branch, keys, prefix) {
    if (!keys.length) return [];
    const where = keys.map(() => (prefix ? 'phone_key LIKE ?' : 'phone_key = ?')).join(' OR ');
    return this.db.prepare(`SELECT * FROM directory WHERE branch = ? AND (${where}) ORDER BY local DESC, updated_at DESC, id DESC LIMIT 20`)
      .all(branch, ...keys.map((key) => (prefix ? key.replace(/[%_]/g, '') + '%' : key)));
  }

  /** The branch's saved addresses with a location (the delivery page's saved-address search). */
  directoryAddresses(branch) {
    return this.db.prepare('SELECT address, area, latitude, longitude FROM directory WHERE branch = ? AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id')
      .all(branch);
  }

  /** A delivery customer saved on the dashboard's page offline: kept here and sent (customer_save). */
  saveDirectoryCustomer(actor, values) {
    return this.atomically(() => {
      const stamp = this.now().toISOString();
      const existing = values.customer_id ? this.db.prepare('SELECT * FROM directory WHERE id = ? AND branch = ?').get(values.customer_id, values.branch)
        : this.db.prepare('SELECT * FROM directory WHERE branch = ? AND phone_key = ? ORDER BY local DESC LIMIT 1').get(values.branch, values.phone_key);
      const id = existing ? existing.id : 4000000000000 + this.nextCounter('directory_seq');
      const revision = existing ? existing.revision + 1 : 1;
      this.db.prepare(`INSERT INTO directory (id, branch, name, phone, phone_key, address, area, delivery_notes, latitude, longitude, revision, local, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?) ON CONFLICT(id) DO UPDATE SET name = excluded.name, phone = excluded.phone, phone_key = excluded.phone_key,
        address = excluded.address, area = excluded.area, delivery_notes = excluded.delivery_notes, latitude = excluded.latitude, longitude = excluded.longitude,
        revision = excluded.revision, local = 1, updated_at = excluded.updated_at`)
        .run(id, values.branch, values.name, values.phone, values.phone_key, values.address, values.area, values.delivery_notes, values.latitude ?? null,
          values.longitude ?? null, revision, stamp);
      this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'customer_save', NULL, ?, 'pending', ?, ?)`)
        .run(values.key, JSON.stringify({ id: values.key, kind: 'customer_save', actor_id: actor.id, branch: values.branch, occurred_at: stamp,
          data: { name: values.name, phone: values.phone, address: values.address, area: values.area, delivery_notes: values.delivery_notes,
            latitude: values.latitude ?? null, longitude: values.longitude ?? null } }), stamp, stamp);
      return this.db.prepare('SELECT * FROM directory WHERE id = ?').get(id);
    });
  }

  kitchenPrint(seq) {
    const row = this.db.prepare('SELECT * FROM kitchen_prints WHERE seq = ?').get(seq);
    return row ? { ...row, snapshot: JSON.parse(row.snapshot) } : null;
  }

  /** Orders opened on the dashboard's page while offline, and payments made here for orders open on the dashboard. */
  pageTickets(branch, channel) {
    return this.db.prepare(`SELECT * FROM tickets WHERE branch = ? AND channel = ? AND json_extract(data, '$.source') = 'dashboard' ORDER BY seq`)
      .all(branch, channel).map(presentTicket);
  }

  /** The payment made here for an order open on the dashboard, if any. */
  paymentFor(channel, serverTicketId) {
    const row = this.db.prepare(`SELECT * FROM tickets WHERE status = 'paid' AND channel = ? AND json_extract(data, '$.kind') = 'payment'
      AND json_extract(data, '$.server_ticket_id') = ? ORDER BY seq DESC LIMIT 1`).get(channel, serverTicketId);
    return row ? presentTicket(row) : null;
  }

  /** A new order opened on the dashboard's page while offline; it gets its number now (it is shown before payment). */
  createPageTicket(actor, values) {
    return this.atomically(() => {
      const now = this.now().toISOString();
      const seq = this.nextCounter('receipt_seq');
      this.db.prepare(`INSERT INTO tickets (id, branch, channel, status, actor_id, actor_name, label, data, created_at, updated_at, seq)
        VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?)`)
        .run(values.id, values.branch, values.channel, actor.id, actor.name, String(values.label || '').slice(0, 60), JSON.stringify(values.data), now, now, seq);
      return this.ticket(values.id);
    });
  }

  updatePageTicket(id, data, status = 'open') {
    this.db.prepare('UPDATE tickets SET data = ?, status = ?, updated_at = ? WHERE id = ?').run(JSON.stringify(data), status, this.now().toISOString(), id);
    return this.ticket(id);
  }

  /** An order open on the dashboard, paid here: kept as a paid invoice, sent with its own operation (ticket_settle). */
  recordServerPayment(actor, values) {
    return this.atomically(() => {
      const now = this.now();
      const stamp = now.toISOString();
      const day = rules.operatingDay(now);
      const seq = this.nextCounter('receipt_seq');
      const number = this.nextNumber(values.branch, day);
      const firstPrint = crypto.randomBytes(32).toString('hex');
      this.db.prepare(`INSERT INTO tickets (id, branch, channel, status, actor_id, actor_name, label, data, local_number, business_date, snapshot_id, totals,
        created_at, updated_at, paid_at, sync_status, seq, first_print) VALUES (?, ?, ?, 'paid', ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'pending', ?, ?)`)
        .run(values.id, values.branch, values.channel, actor.id, actor.name, String(values.label || '').slice(0, 60), JSON.stringify(values.data), number, day,
          JSON.stringify(values.totals), stamp, stamp, stamp, seq, firstPrint);
      // Its own operation (ticket_settle), or none when it is part of a batch the courier brought back (ticket_batch).
      if (values.operation) {
        const operation = { id: values.id, kind: 'ticket_settle', actor_id: actor.id, branch: values.branch, occurred_at: stamp, data: values.operation };
        this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'ticket_settle', ?, ?, 'pending', ?, ?)`)
          .run(values.id, values.id, JSON.stringify(operation), stamp, stamp);
      }
      const overlay = { ...(values.overlay || {}), ops: [...((values.overlay && values.overlay.ops) || []), ...(values.operation ? [values.id] : [])] };
      this.db.prepare('INSERT INTO service_overlays (ticket_id, data) VALUES (?, ?) ON CONFLICT(ticket_id) DO UPDATE SET data = excluded.data')
        .run(values.server_ticket_id, JSON.stringify(overlay));
      if (values.phone) this.rememberCustomer(values.phone, { customer_name: values.data.customer_name || '', address: values.data.address || '' }, values.totals.total_cents, stamp);
      return this.ticket(values.id);
    });
  }

  /**
   * Back online: dine-in orders opened offline and still unpaid move to the dashboard (ticket_open) and continue there,
   * with what was done here (sent to the kitchen, bill printed). Returns how many were queued.
   */
  queueTicketMoves() {
    if (!(this.get('server_kinds', []) || []).includes('ticket_open')) return 0;
    return transaction(this.db, () => {
      let queued = 0;
      for (const row of this.db.prepare(`SELECT * FROM tickets WHERE status = 'open' AND channel IN ('dine', 'phone') AND json_extract(data, '$.source') = 'dashboard'
        AND json_extract(data, '$.kind') = 'local' AND json_extract(data, '$.move_op') IS NULL`).all()) {
        const ticket = presentTicket(row);
        const data = ticket.data;
        if (!data.items || !data.items.length || (ticket.channel === 'dine' && !data.table) || (ticket.channel === 'phone' && !data.location)) continue;
        const stamp = this.now().toISOString();
        const kitchen = data.kitchen ? this.db.prepare('SELECT job_status FROM kitchen_prints WHERE seq = ?').get(data.kitchen) : null;
        const moved = ticket.channel === 'dine' ? {
          table_id: data.table.id, waiter_name: data.waiter_name, guest_count: data.guest_count, customer_name: data.customer_name || '',
          customer_phone: data.customer_phone || '', items: data.items, discount: data.discount || '0.00', discount_reason: data.discount_reason || '',
          notes: data.notes || '', kitchen_sent: !!data.kitchen, bill_requested: !!data.bill } : {
          // A delivery order: where it goes, the delivery fee charged, and what was done (kitchen ticket printed, courier, bill).
          channel: 'phone', items: data.items, discount: data.discount || '0.00', discount_reason: data.discount_reason || '', customer_name: data.customer_name,
          customer_phone: data.customer_phone, address: data.address, area: data.area || '', delivery_notes: data.delivery_notes || '', notes: data.notes || '',
          latitude: data.location.latitude, longitude: data.location.longitude, delivery_fee: data.delivery_fee, delivery_company_id: data.delivery_company_id || null,
          marketing_consent: !!data.marketing_consent, kitchen_sent: !!data.kitchen, kitchen_printed: !!kitchen && kitchen.job_status === 'invoked',
          courier_company_id: data.courier ? data.courier.id : null, bill_requested: !!data.bill };
        const operation = { id: ticket.id, kind: 'ticket_open', actor_id: ticket.actor_id, branch: ticket.branch, occurred_at: stamp, data: moved };
        this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'ticket_open', ?, ?, 'pending', ?, ?)
          ON CONFLICT(id) DO UPDATE SET payload = excluded.payload, status = 'pending', last_error = NULL, updated_at = excluded.updated_at`)
          .run(ticket.id, ticket.id, JSON.stringify(operation), stamp, stamp);
        this.db.prepare("UPDATE tickets SET data = json_set(data, '$.move_op', ?), sync_status = 'pending' WHERE id = ?").run(ticket.id, ticket.id);
        queued++;
      }
      return queued;
    });
  }

  /** Moves of dine-in orders to the dashboard that are still waiting to be sent. */
  waitingMoves() {
    return this.db.prepare("SELECT COUNT(*) AS count FROM outbox WHERE kind = 'ticket_open' AND status = 'pending'").get().count;
  }

  /**
   * The order is changed or paid here: a move to the dashboard not sent yet is called off (queued again later).
   * false: it is being sent right now, or the dashboard already has it.
   */
  cancelTicketMove(ticketId) {
    const op = this.db.prepare("SELECT status FROM outbox WHERE id = ? AND kind = 'ticket_open'").get(ticketId);
    if (!op) return true;
    if (op.status === 'done' || (this.sending && this.sending.has(ticketId))) return false;
    this.db.prepare("DELETE FROM outbox WHERE id = ? AND kind = 'ticket_open' AND status IN ('pending', 'rejected')").run(ticketId);
    this.db.prepare("UPDATE tickets SET data = json_remove(data, '$.move_op'), sync_status = NULL, sync_error = NULL WHERE id = ?").run(ticketId);
    return true;
  }

  nextCounter(name) {
    this.db.prepare('INSERT INTO counters (name, value) VALUES (?, 1) ON CONFLICT(name) DO UPDATE SET value = value + 1').run(name);
    return this.db.prepare('SELECT value FROM counters WHERE name = ?').get(name).value;
  }

  ticketBySeq(seq) {
    const row = this.db.prepare('SELECT * FROM tickets WHERE seq = ?').get(seq);
    return row ? presentTicket(row) : null;
  }

  /** Paid invoices of the given branches and working day, newest first, a page at a time. */
  paidTickets(branches, day, page = 1, perPage = 20) {
    if (!branches.length) return { total: 0, items: [] };
    const marks = branches.map(() => '?').join(', ');
    const total = this.db.prepare(`SELECT COUNT(*) AS count FROM tickets WHERE status = 'paid' AND business_date = ? AND branch IN (${marks})`)
      .get(day, ...branches).count;
    const items = this.db.prepare(`SELECT * FROM tickets WHERE status = 'paid' AND business_date = ? AND branch IN (${marks})
      ORDER BY seq DESC LIMIT ? OFFSET ?`).all(day, ...branches, perPage, (page - 1) * perPage).map(presentTicket);
    return { total, items };
  }

  /**
   * The branches' invoices of the working day as the dashboard counts them: the server's count from the last
   * sync, plus this device's invoices the server did not have yet at that moment.
   */
  dayTotals(branches, day) {
    let count = 0;
    let total = 0;
    let cash = 0;
    const methods = { card: 0, mobile_wallet: 0, other: 0, mixed: 0 };
    for (const branch of branches) {
      const row = this.db.prepare('SELECT today FROM branches WHERE value = ?').get(branch);
      const server = row && row.today ? JSON.parse(row.today) : null;
      const known = server && server.date === day ? server : null;
      if (known) {
        count += Number(known.count) || 0;
        total += money.minor(known.total || '0');
        cash += money.minor(known.cash || '0');
        for (const method of Object.keys(methods)) methods[method] += money.minor(known[method] || '0');
      }
      const since = known ? Date.parse(known.received_at) : null;
      const local = this.db.prepare(`SELECT sync_status, synced_at, totals FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ?`)
        .all(branch, day);
      for (const ticket of local) {
        const counted = known && ticket.sync_status === 'synced' && ticket.synced_at && Date.parse(ticket.synced_at) <= since;
        if (counted) continue;
        const amount = JSON.parse(ticket.totals).total_cents;
        count++;
        total += amount;
        cash += amount;
      }
    }
    return { date: day, count, total: money.decimal(total), cash: money.decimal(cash),
      ...Object.fromEntries(Object.entries(methods).map(([method, cents]) => [method, money.decimal(cents)])) };
  }

  /** The one first print of an account that may not reprint: once, by its cashier, within ten minutes. */
  useFirstPrint(ticketId, actorId, token) {
    const ticket = this.ticket(ticketId);
    if (!ticket || ticket.actor_id !== actorId || !ticket.first_print || ticket.first_print === 'used' || ticket.first_print !== token) return false;
    if (this.now().getTime() - Date.parse(ticket.paid_at) > 10 * 60 * 1000) return false;
    this.db.prepare("UPDATE tickets SET first_print = 'used' WHERE id = ? AND first_print = ?").run(ticketId, token);
    return true;
  }

  rememberCustomer(phone, data, total, stamp) {
    this.db.prepare(`INSERT INTO customers (phone, name, address, orders, total_cents, last_order_at) VALUES (?, ?, ?, 1, ?, ?)
      ON CONFLICT(phone) DO UPDATE SET name = CASE WHEN excluded.name != '' THEN excluded.name ELSE name END,
        address = CASE WHEN excluded.address != '' THEN excluded.address ELSE address END,
        orders = orders + 1, total_cents = total_cents + excluded.total_cents, last_order_at = excluded.last_order_at`)
      .run(phone, data.customer_name.trim(), data.address.trim(), total, stamp);
  }

  /** A customer this device served before, found by any way of writing the same phone number. */
  customer(phoneText) {
    const phone = rules.normalizePhone(phoneText);
    if (!phone) return null;
    const row = this.db.prepare('SELECT * FROM customers WHERE phone = ?').get(phone);
    return row ? { phone: row.phone, name: row.name, address: row.address, orders: row.orders, total_cents: row.total_cents, last_order_at: row.last_order_at } : null;
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

  /**
   * Same checks as the dashboard's expenses page, so the expense is not refused later. options.id: the dashboard
   * page's operation number; saving it again returns the same expense.
   */
  recordExpense(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'expense' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
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
    return this.addJournal(actor, branch, 'expense', amount, data, { category_name: category.name }, options.id);
  }

  /**
   * Goods received on the dashboard's stock page offline (BranchInventory::receive; the page checked the item and the
   * quantity), sent to the dashboard as stock_receive under the page's operation number.
   */
  recordStockReceipt(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'stock' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const data = { ingredient_id: Number(values.ingredient_id), unit: values.unit, quantity: String(values.quantity),
      supplier: String(values.supplier || ''), notes: String(values.notes || '') };
    return this.addJournal(actor, branch, 'stock', 0, data, { units: values.units, name: values.name, unit_label: values.unit_label },
      options.id, 'stock_receive');
  }

  /**
   * A deduction, bonus or advance recorded on the dashboard's employees page offline (the page checked it), sent to the
   * dashboard as employee_entry under the page's operation number.
   */
  recordEmployeeEntry(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'employee_entry' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const data = { employee_id: Number(values.employee_id), day: String(values.day), kind: values.kind, amount: String(values.amount),
      reason: String(values.reason), notes: String(values.notes || '') };
    return this.addJournal(actor, branch, 'employee_entry', money.minor(values.amount), data, { employee_name: String(values.employee_name || '') },
      options.id, 'employee_entry');
  }

  /**
   * Deductions, bonuses and advances recorded on this device for a branch, on one day or in one month (YYYY-MM), oldest
   * first. One the dashboard refused is left out: it is not on the dashboard.
   */
  employeeEntries(branch, { day = null, month = null } = {}) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'employee_entry' AND branch = ? AND sync_status != 'rejected' ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }))
      .filter((entry) => (!day || entry.data.day === day) && (!month || String(entry.data.day).slice(0, 7) === month));
  }

  /**
   * A shift an owner or admin closed on the dashboard's shift page offline (the page checked it), sent to the dashboard
   * as shift_close: alone, after everything recorded before it and before anything recorded after it.
   */
  recordShiftClose(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'shift' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم الإغلاق مستخدم لبيانات مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_close_shift');
    const data = { previous_closing_id: Number(values.previous_closing_id), counted_cash: String(values.counted_cash), notes: String(values.notes || '') };
    return this.addJournal(actor, branch, 'shift', 0, data, { started_at: values.started_at || '' }, options.id, 'shift_close');
  }

  /** Shifts closed on this device for a branch, oldest first. */
  deviceShiftClosings(branch) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'shift' AND branch = ? ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }));
  }

  /** Shift closings waiting to be sent. */
  waitingShiftClose() {
    return this.db.prepare("SELECT COUNT(*) AS c FROM outbox WHERE kind = 'shift_close' AND status = 'pending'").get().c;
  }

  /** Goods received on this device for a branch, oldest first. */
  deviceStockReceipts(branch) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'stock' AND branch = ? ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }));
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
  /** operationKind: what the server applies, when it differs from the journal's kind (a leave is an attendance entry). */
  addJournal(actor, branch, kind, amount, data, extra, givenId = null, operationKind = kind) {
    return transaction(this.db, () => {
      const now = this.now();
      const stamp = now.toISOString();
      const id = givenId || this.uuid();
      const operation = { id, kind: operationKind, actor_id: actor.id, branch, occurred_at: stamp, data };
      this.db.prepare(`INSERT INTO journal (id, kind, branch, actor_id, actor_name, business_date, amount_cents, data, created_at, sync_status, seq)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)`)
        .run(id, kind, branch, actor.id, actor.name, rules.operatingDay(now), amount, JSON.stringify({ ...data, ...extra }), stamp, this.nextCounter('journal_seq'));
      this.db.prepare(`INSERT INTO outbox (id, kind, journal_id, payload, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?)`)
        .run(id, operationKind, id, JSON.stringify(operation), stamp, stamp);
      return this.journalEntry(id);
    });
  }

  journalEntry(id) {
    const row = this.db.prepare('SELECT * FROM journal WHERE id = ?').get(id);
    return row ? { ...row, data: JSON.parse(row.data) } : null;
  }

  journalBySeq(seq) {
    const row = this.db.prepare('SELECT * FROM journal WHERE seq = ?').get(seq);
    return row ? { ...row, data: JSON.parse(row.data) } : null;
  }

  /** Expenses saved on this device for the given branches between two working days, newest first. */
  deviceExpenses(branches, from, to) {
    if (!branches.length) return [];
    const marks = branches.map(() => '?').join(', ');
    return this.db.prepare(`SELECT * FROM journal WHERE kind = 'expense' AND branch IN (${marks}) AND business_date BETWEEN ? AND ?
      ORDER BY business_date DESC, seq DESC`).all(...branches, from, to).map((row) => ({ ...row, data: JSON.parse(row.data) }));
  }

  journal(branch, day, kinds = ['expense', 'till']) {
    return this.db.prepare(`SELECT * FROM journal WHERE branch = ? AND business_date = ? AND kind IN (${kinds.map(() => '?').join(', ')})
      ORDER BY created_at DESC`).all(branch, day, ...kinds).map((row) => ({ ...row, data: JSON.parse(row.data) }));
  }

  // ---- employee attendance, recorded with the device's (server-corrected) time, also offline ----

  employees(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.employees) ? catalog.employees : [];
  }

  attendanceShifts(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.attendance_shifts) ? catalog.attendance_shifts : [];
  }

  /** Each employee's day: what the server last said, overlaid with this device's own punches. */
  attendanceBoard(branch, day) {
    const row = this.db.prepare('SELECT attendance FROM branches WHERE value = ?').get(branch);
    const server = new Map((row && row.attendance ? JSON.parse(row.attendance) : []).filter((item) => item.day === day)
      .map((item) => [item.employee_id, item]));
    const punches = this.journal(branch, day, ['attendance']).reverse();
    return this.employees(branch).map((employee) => {
      const known = server.get(employee.id) || {};
      const state = { employee, status: known.status || 'unrecorded', checked_in_at: known.checked_in_at || null,
        checked_out_at: known.checked_out_at || null, pending: false, error: null };
      for (const punch of punches.filter((item) => item.data.employee_id === employee.id)) {
        if (punch.sync_status === 'rejected') { state.error = punch.sync_error; continue; }
        if (punch.sync_status === 'pending') state.pending = true;
        state.status = punch.data.status;
        if (punch.data.action === 'check_in' && !state.checked_in_at) state.checked_in_at = punch.created_at;
        if (punch.data.action === 'check_out' && !state.checked_out_at) state.checked_out_at = punch.created_at;
      }
      return state;
    });
  }

  /**
   * Same order and shift rules as the dashboard; the server applies its deductions from these times. A leave or an
   * absence for the day (set_status) follows the dashboard's rules too: the first status of the day stays. options.id:
   * the dashboard page's operation number; saving it again returns the same punch.
   */
  recordPunch(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'attendance' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const day = rules.operatingDay(this.now());
    const action = ['check_out', 'set_status'].includes(values.action) ? values.action : 'check_in';
    const state = this.attendanceBoard(branch, day).find((item) => item.employee.id === Number(values.employee_id));
    if (!state) throw new Error('الموظف غير موجود في هذا الفرع. اتصل بالإنترنت لتحديث قائمة الموظفين.');
    const partTime = state.employee.schedule_type === 'part_time';
    let status = values.status;
    if (action === 'set_status') {
      // BranchPayroll::attendance: the shift whose rule prices an absence, and the salary a leave is valued from.
      const employee = state.employee;
      if (!['preapproved_leave', 'unauthorized_absence'].includes(status)) throw new Error('اختر حالة الإجازة أو الغياب.');
      if (state.status !== 'unrecorded') {
        const error = new Error('تم تثبيت حالة الحضور لهذا اليوم؛ لا يمكن تغييرها بعد التسجيل.');
        error.status = 409;
        throw error;
      }
      if (partTime && !(employee.attendance_start && employee.attendance_end && ['morning', 'evening'].includes(employee.attendance_shift))) {
        throw new Error('أكمل مواعيد الدوام الجزئي في بيانات الموظف.');
      }
      const shift = partTime ? employee.attendance_shift : ['evening', 'مسائي', 'مساء'].includes(employee.shift) ? 'evening' : 'morning';
      if (status === 'unauthorized_absence' && !this.attendanceShifts(branch).some((rule) => rule.shift === shift)) {
        throw new Error('يجب أن يحدد الأونر مواعيد وخصومات هذا الفرع أولًا.');
      }
      if (status === 'preapproved_leave' && employee.has_salary === false) throw new Error('سجّل راتب الموظف لحساب قيمة يوم الإجازة.');
    } else if (action === 'check_in') {
      if (state.checked_in_at) throw new Error('تم تسجيل حضور ' + state.employee.name + ' اليوم بالفعل.');
      if (['preapproved_leave'].includes(state.status)) throw new Error('الموظف عليه إجازة مسجلة اليوم.');
      if (state.status === 'unauthorized_absence') throw new Error('تم تثبيت غياب ' + state.employee.name + ' اليوم؛ لا يمكن تغييره بعد التسجيل.');
      if (partTime) status = 'part_time';
      else if (!['morning', 'evening'].includes(status)) throw new Error('اختر حضور صباحي أو مسائي.');
    } else {
      if (!state.checked_in_at) throw new Error('سجّل حضور ' + state.employee.name + ' أولًا.');
      if (state.checked_out_at) throw new Error('تم تسجيل انصراف ' + state.employee.name + ' اليوم بالفعل.');
      status = state.status;
    }
    const data = { employee_id: state.employee.id, day, status, action };
    const notes = String(values.notes ?? '').trim().slice(0, 1000);
    if (notes) data.notes = notes;
    return this.addJournal(actor, branch, 'attendance', 0, data, { employee_name: state.employee.name }, options.id,
      action === 'set_status' ? 'attendance_status' : 'attendance');
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
    // In the order they were done (an order changed, then its bill printed, then paid).
    const operations = this.db.prepare(`SELECT id, payload FROM outbox WHERE status = 'pending'${filter} ORDER BY created_at, rowid LIMIT ?`)
      .all(...(known || []), limit).map((row) => JSON.parse(row.payload));
    // A shift closing counts what the dashboard has when it arrives: it goes alone, once everything recorded before it
    // is in, and before anything recorded after it.
    const closing = operations.findIndex((operation) => operation.kind === 'shift_close');
    if (closing > 0) return operations.slice(0, closing);
    if (closing === 0) return operations.slice(0, 1);
    return operations;
  }

  applyResults(results) {
    transaction(this.db, () => {
      const now = this.now().toISOString();
      for (const result of results || []) {
        const row = this.db.prepare('SELECT id, kind, ticket_id, journal_id FROM outbox WHERE id = ?').get(result.id);
        if (!row) continue;
        if (result.status === 'applied') {
          this.db.prepare("UPDATE outbox SET status = 'done', result = ?, last_error = NULL, updated_at = ? WHERE id = ?").run(JSON.stringify(result), now, row.id);
          const receipt = result.receipt || {};
          if (row.kind === 'ticket_batch' && result.batch && Array.isArray(result.batch.items)) {
            for (const line of result.batch.items) {
              this.db.prepare(`UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, synced_at = ? WHERE json_extract(data, '$.batch_key') = ?
                AND json_extract(data, '$.server_ticket_id') = ?`).run(line.order_id ?? null, now, row.id, line.ticket_id);
            }
          }
          if (row.ticket_id && row.kind === 'ticket_open') {
            // Moved to the dashboard: the order now lives there.
            this.db.prepare("UPDATE tickets SET status = 'moved', sync_status = 'synced', sync_error = NULL, server_id = ?, synced_at = ? WHERE id = ?")
              .run(result.ticket.id ?? null, now, row.ticket_id);
          } else if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, server_number = ?, synced_at = ? WHERE id = ?")
            .run(receipt.id ?? null, receipt.number ?? null, now, row.ticket_id);
          if (row.journal_id) {
            const reference = result.expense ? `${result.expense.number} · ${result.expense.status === 'approved' ? 'معتمد' : 'بانتظار الاعتماد'}`
              : result.closing && result.closing.number ? String(result.closing.number) : null;
            if (result.attendance) {
              // The server keeps the first punch of the day; show the time it recorded.
              this.db.prepare(`UPDATE journal SET data = json_set(data, '$.server_checked_in_at', ?, '$.server_checked_out_at', ?) WHERE id = ?`)
                .run(result.attendance.checked_in_at || null, result.attendance.checked_out_at || null, row.journal_id);
            }
            this.db.prepare("UPDATE journal SET sync_status = 'synced', sync_error = NULL, server_ref = ?, synced_at = ? WHERE id = ?").run(reference, now, row.journal_id);
          }
        } else if (result.status === 'rejected') {
          this.db.prepare("UPDATE outbox SET status = 'rejected', result = ?, last_error = ?, updated_at = ? WHERE id = ?")
            .run(JSON.stringify(result), result.error || 'تم رفض العملية.', now, row.id);
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.ticket_id);
          if (row.kind === 'ticket_batch') {
            this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE json_extract(data, '$.batch_key') = ?").run(result.error || 'تم رفض العملية.', row.id);
          }
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
    // Actions on the app orders board taken offline wait with them.
    const board = this.db.prepare(`SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected FROM board_commands`).get();
    return { pending: (row.pending || 0) + (board.pending || 0), rejected: (row.rejected || 0) + (board.rejected || 0) };
  }

  // ---- the app orders board offline: actions taken without the internet, sent to the board's action once online ----

  /** An action on an app order taken on this device; `card` is the order's card as the board shows it now. */
  recordBoardCommand(actor, values) {
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO board_commands (id, actor_id, actor_name, source, order_id, order_number, action, fields, card, from_group, to_group,
      status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)`)
      .run(values.id, actor.id, actor.name, values.source, values.order_id, values.order_number || '', values.action, JSON.stringify(values.fields),
        values.card, values.from_group, values.to_group, stamp, stamp);
    return this.boardCommand(values.id);
  }

  boardCommand(id) {
    const row = this.db.prepare('SELECT * FROM board_commands WHERE id = ?').get(id);
    return row ? presentBoardCommand(row) : null;
  }

  /** The actions not yet on the dashboard, oldest first (one account's, or every account's). */
  pendingBoardCommands(actorId = null) {
    const rows = actorId === null
      ? this.db.prepare("SELECT * FROM board_commands WHERE status = 'pending' ORDER BY created_at, rowid").all()
      : this.db.prepare("SELECT * FROM board_commands WHERE status = 'pending' AND actor_id = ? ORDER BY created_at, rowid").all(actorId);
    return rows.map(presentBoardCommand);
  }

  /** An account's actions the dashboard took since a time (a copy saved before then does not show them yet). */
  recentBoardCommands(actorId, since) {
    return this.db.prepare("SELECT * FROM board_commands WHERE status = 'done' AND actor_id = ? AND done_at > ? ORDER BY created_at, rowid")
      .all(actorId, String(since)).map(presentBoardCommand);
  }

  /** The newest action on each order still shown on this device (pending), for the board's cards. */
  boardOverlay(actorId) {
    const latest = new Map();
    for (const command of this.pendingBoardCommands(actorId)) latest.set(command.source + ':' + command.order_id, command);
    return latest;
  }

  /** The dashboard took the action (done) or refused it (rejected, with its reason); a refusal also stops the ones after it. */
  finishBoardCommand(id, status, error = null) {
    const stamp = this.now().toISOString();
    this.atomically(() => {
      const row = this.db.prepare('SELECT * FROM board_commands WHERE id = ?').get(id);
      if (!row || row.status !== 'pending') return;
      this.db.prepare('UPDATE board_commands SET status = ?, error = ?, attempts = attempts + 1, updated_at = ?, done_at = ? WHERE id = ?')
        .run(status, error, stamp, stamp, id);
      if (status !== 'rejected') return;
      this.db.prepare(`UPDATE board_commands SET status = 'rejected', error = ?, updated_at = ?, done_at = ?
        WHERE status = 'pending' AND source = ? AND order_id = ? AND actor_id = ?`)
        .run('ما اتبعتش: الإجراء اللي قبله على نفس الطلب اترفض.', stamp, stamp, row.source, row.order_id, row.actor_id);
    });
  }

  /** Tried and not answered (no internet, a session to renew): tried again with the next sync. */
  retryBoardCommand(id, error = null) {
    this.db.prepare('UPDATE board_commands SET attempts = attempts + 1, error = ?, updated_at = ? WHERE id = ? AND status = ?')
      .run(error, this.now().toISOString(), id, 'pending');
  }

  rejectedOperations() {
    const labels = { sale: 'فاتورة', expense: 'مصروف', till: 'خزنة', attendance: 'حضور وانصراف', attendance_status: 'غياب أو إجازة',
      ticket_settle: 'تحصيل طلب', ticket_open: 'نقل طلب للداشبورد', ticket_action: 'إجراء على طلب', ticket_dispatch: 'تسليم طلب للمندوب',
      ticket_batch: 'تحصيل طلبات المندوب', ticket_save: 'تعديل طلب', ticket_cancel: 'إلغاء طلب', customer_save: 'بيانات عميل',
      stock_receive: 'إضافة بضاعة', shift_close: 'تقفيل وردية', employee_entry: 'خصم أو مكافأة أو سلفة موظف' };
    return this.db.prepare(`SELECT o.id, o.kind, o.last_error, o.updated_at, t.local_number, t.branch, t.totals, j.amount_cents, j.branch AS journal_branch
      FROM outbox o LEFT JOIN tickets t ON t.id = o.ticket_id LEFT JOIN journal j ON j.id = o.journal_id
      WHERE o.status = 'rejected' ORDER BY o.updated_at DESC LIMIT 200`).all()
      .map((row) => ({ id: row.id, kind: labels[row.kind] || row.kind, error: row.last_error, at: row.updated_at,
        local_number: row.local_number, branch: row.branch || row.journal_branch,
        total: row.totals ? money.decimal(JSON.parse(row.totals).total_cents) : (row.amount_cents != null ? money.decimal(row.amount_cents) : null) }))
      .concat(this.db.prepare("SELECT * FROM board_commands WHERE status = 'rejected' ORDER BY updated_at DESC LIMIT 100").all()
        .map((row) => ({ id: row.id, kind: 'طلب تطبيق: ' + (BOARD_ACTIONS[row.action] || row.action), error: row.error, at: row.updated_at,
          local_number: row.order_number || ('#' + row.order_id), branch: null, total: null })))
      .sort((a, b) => String(b.at).localeCompare(String(a.at)));
  }
}

// The app orders board's actions, as its buttons name them.
const BOARD_ACTIONS = { accept: 'قبول وإرسال للمطبخ', reject: 'رفض الطلب', prepare: 'مندوب الفرع', dispatch: 'طلب مندوب', ready: 'جاهز',
  complete: 'تم التسليم', cancel: 'إلغاء الطلب' };

function presentBoardCommand(row) {
  return { id: row.id, actor_id: row.actor_id, actor_name: row.actor_name, source: row.source, order_id: row.order_id, order_number: row.order_number,
    action: row.action, fields: JSON.parse(row.fields), card: row.card, from_group: row.from_group, to_group: row.to_group, status: row.status,
    error: row.error, attempts: row.attempts, created_at: row.created_at, updated_at: row.updated_at, done_at: row.done_at };
}

function cleanTicketData(data) {
  const text = (value, max) => String(value ?? '').slice(0, max);
  const items = Array.isArray(data.items) ? data.items.slice(0, 100).map((item) => ({
    product_id: Number(item.product_id), option_id: String(item.option_id || ''),
    quantity_mode: item.quantity_mode === 'weight' ? 'weight' : 'piece', quantity: String(item.quantity || ''),
  })) : [];
  return { items, customer_phone: text(data.customer_phone, 30), customer_name: text(data.customer_name, 100), address: text(data.address, 500),
    table_name: text(data.table_name, 100), notes: text(data.notes, 500), discount: text(data.discount, 14), discount_reason: text(data.discount_reason, 500),
    delivery: text(data.delivery, 14), service_consent: !!data.service_consent, marketing_consent: !!data.marketing_consent,
    // Delivery orders: who takes it, and when it left with the driver (paid when the driver returns).
    delivery_company_id: Number.isInteger(Number(data.delivery_company_id)) && Number(data.delivery_company_id) > 0 ? Number(data.delivery_company_id) : null,
    driver: text(data.driver, 100), out_at: typeof data.out_at === 'string' && !Number.isNaN(Date.parse(data.out_at)) ? data.out_at : '' };
}

function presentTicket(row) {
  return { ...row, data: JSON.parse(row.data), totals: row.totals ? JSON.parse(row.totals) : null };
}

module.exports = { Store, cleanTicketData };
