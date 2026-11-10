'use strict';
// Store: Invoices: open tickets, quotes, payments, the dashboard pages' tickets, numbers and the day's paid invoices.
const crypto = require('node:crypto');
const { transaction } = require('../db.cjs');
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { CHANNELS, cleanTicketData, presentTicket } = require('./shared.cjs');

module.exports = {
  // ---- open invoices ----

  openTickets(branch) {
    return this.db.prepare("SELECT * FROM tickets WHERE branch = ? AND status = 'open' ORDER BY created_at").all(branch).map(presentTicket);
  },

ticket(id) {
    const row = this.db.prepare('SELECT * FROM tickets WHERE id = ?').get(id);
    return row ? presentTicket(row) : null;
  },

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
  },

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
  },

deleteTicket(actor, id) {
    const ticket = this.ticket(id);
    if (!ticket || ticket.status !== 'open') return false;
    this.requireBranch(actor, ticket.branch, 'can_checkout');
    this.db.prepare("DELETE FROM tickets WHERE id = ? AND status = 'open'").run(id);
    return true;
  },

/** Prices the invoice exactly as the server will, with the menu currently on the device. */
  quoteTicket(branch, channel, data) {
    const catalog = this.catalog(branch);
    if (!catalog) throw new Error('لم يتم تنزيل مينيو هذا الفرع بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    const discount = data.discount ? money.minor(data.discount) : 0;
    const delivery = channel === 'phone' && data.delivery ? money.minor(data.delivery) : 0;
    return { catalog, quote: money.quote(catalog, { channel, items: data.items || [], discount_cents: discount, delivery_cents: delivery }) };
  },

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
  },

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
  },

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
  },

// ---- the dashboard's dine-in and delivery pages offline ----

  /** Runs work in one transaction; inside another one it simply joins it. */
  atomically(work) {
    if (this.depth) return work();
    this.depth = 1;
    try { return transaction(this.db, work); } finally { this.depth = 0; }
  },

/** Orders opened on the dashboard's page while offline, and payments made here for orders open on the dashboard. */
  pageTickets(branch, channel) {
    return this.db.prepare(`SELECT * FROM tickets WHERE branch = ? AND channel = ? AND json_extract(data, '$.source') = 'dashboard' ORDER BY seq`)
      .all(branch, channel).map(presentTicket);
  },

/** The payment made here for an order open on the dashboard, if any. */
  paymentFor(channel, serverTicketId) {
    const row = this.db.prepare(`SELECT * FROM tickets WHERE status = 'paid' AND channel = ? AND json_extract(data, '$.kind') = 'payment'
      AND json_extract(data, '$.server_ticket_id') = ? ORDER BY seq DESC LIMIT 1`).get(channel, serverTicketId);
    return row ? presentTicket(row) : null;
  },

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
  },

updatePageTicket(id, data, status = 'open') {
    this.db.prepare('UPDATE tickets SET data = ?, status = ?, updated_at = ? WHERE id = ?').run(JSON.stringify(data), status, this.now().toISOString(), id);
    return this.ticket(id);
  },

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
  },

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
  },

/** Moves of dine-in orders to the dashboard that are still waiting to be sent. */
  waitingMoves() {
    return this.db.prepare("SELECT COUNT(*) AS count FROM outbox WHERE kind = 'ticket_open' AND status = 'pending'").get().count;
  },

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
  },

nextCounter(name) {
    this.db.prepare('INSERT INTO counters (name, value) VALUES (?, 1) ON CONFLICT(name) DO UPDATE SET value = value + 1').run(name);
    return this.db.prepare('SELECT value FROM counters WHERE name = ?').get(name).value;
  },

ticketBySeq(seq) {
    const row = this.db.prepare('SELECT * FROM tickets WHERE seq = ?').get(seq);
    return row ? presentTicket(row) : null;
  },

/** Paid invoices of the given branches and working day, newest first, a page at a time. */
  paidTickets(branches, day, page = 1, perPage = 20) {
    if (!branches.length) return { total: 0, items: [] };
    const marks = branches.map(() => '?').join(', ');
    const total = this.db.prepare(`SELECT COUNT(*) AS count FROM tickets WHERE status = 'paid' AND business_date = ? AND branch IN (${marks})`)
      .get(day, ...branches).count;
    const items = this.db.prepare(`SELECT * FROM tickets WHERE status = 'paid' AND business_date = ? AND branch IN (${marks})
      ORDER BY seq DESC LIMIT ? OFFSET ?`).all(day, ...branches, perPage, (page - 1) * perPage).map(presentTicket);
    return { total, items };
  },

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
  },

/** The one first print of an account that may not reprint: once, by its cashier, within ten minutes. */
  useFirstPrint(ticketId, actorId, token) {
    const ticket = this.ticket(ticketId);
    if (!ticket || ticket.actor_id !== actorId || !ticket.first_print || ticket.first_print === 'used' || ticket.first_print !== token) return false;
    if (this.now().getTime() - Date.parse(ticket.paid_at) > 10 * 60 * 1000) return false;
    this.db.prepare("UPDATE tickets SET first_print = 'used' WHERE id = ? AND first_print = ?").run(ticketId, token);
    return true;
  },

rememberCustomer(phone, data, total, stamp) {
    this.db.prepare(`INSERT INTO customers (phone, name, address, orders, total_cents, last_order_at) VALUES (?, ?, ?, 1, ?, ?)
      ON CONFLICT(phone) DO UPDATE SET name = CASE WHEN excluded.name != '' THEN excluded.name ELSE name END,
        address = CASE WHEN excluded.address != '' THEN excluded.address ELSE address END,
        orders = orders + 1, total_cents = total_cents + excluded.total_cents, last_order_at = excluded.last_order_at`)
      .run(phone, data.customer_name.trim(), data.address.trim(), total, stamp);
  },

/** A customer this device served before, found by any way of writing the same phone number. */
  customer(phoneText) {
    const phone = rules.normalizePhone(phoneText);
    if (!phone) return null;
    const row = this.db.prepare('SELECT * FROM customers WHERE phone = ?').get(phone);
    return row ? { phone: row.phone, name: row.name, address: row.address, orders: row.orders, total_cents: row.total_cents, last_order_at: row.last_order_at } : null;
  },

nextNumber(branch, day) {
    const name = `number:${branch}:${day}`;
    this.db.prepare('INSERT INTO counters (name, value) VALUES (?, 1) ON CONFLICT(name) DO UPDATE SET value = value + 1').run(name);
    const value = this.db.prepare('SELECT value FROM counters WHERE name = ?').get(name).value;
    const device = this.device() || {};
    const code = String(device.code || 'D').toUpperCase();
    return `${code}-${day.slice(5).replace('-', '')}-${String(value).padStart(4, '0')}`;
  },
};
