'use strict';
// The dashboard's dine-in page (الصالة) and delivery page (الدليفري) while the internet is down: the same requests,
// answered from the device's database in the same shape as App\Services\Dashboard\PosServiceTicket / PosServiceTable.
//  - A branch's tables and open orders come from the device's copy, refreshed by every sync (30 seconds online).
//  - A dine-in order opened here offline lives on the device (ids from 7000000000000). Paid here, it is sent as an
//    invoice; still unpaid when the internet is back, it moves to the dashboard (ticket_open) and continues there.
//  - An order open on the dashboard can be sent to the kitchen, have its bill printed and be paid here. Each of these is
//    sent with the page's own operation number (ticket_action, ticket_settle): the dashboard applies it to that same
//    order once, even if the page's request had reached it just before the connection dropped.
const crypto = require('node:crypto');
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { renderReceipt, renderKitchen, renderBatch, ticketBill } = require('./receipt.cjs');
const { TakeawayOffline, LOCAL_RECEIPT_BASE, cairoIso, permissions, register, Reply, json, invalid, sha256, notFound, UUID, BRANCH } = require('./takeaway.cjs');

// Orders opened on this device and kitchen tickets printed here get ids far above the dashboard's own.
const LOCAL_TICKET_BASE = 7000000000000;
const LOCAL_KITCHEN_BASE = 6000000000000;
const LOCAL_BATCH_BASE = 5000000000000;
const ALL_BRANCHES = 'جميع الفروع';
const CLOSED = 'الفاتورة أُغلقت.';
const CHANGED = 'تغيرت الفاتورة.';
const BILL_ISSUED = 'صدرت فاتورة الدفع؛ المتاح فقط إنهاء الفاتورة وتحصيل قيمتها.';
const KITCHEN_SENT = 'تم إرسال الأوردر للمطبخ بالفعل. تُحفظ التعديلات عند طباعة طلب الحساب.';
const MOVED = 'انتقل هذا الطلب للداشبورد بعد رجوع الإنترنت. افتح الطاولة من جديد.';
const MOVING = 'جاري نقل هذا الطلب للداشبورد الآن. افتح الطاولة من جديد بعد ثوانٍ.';
const EDIT_NEEDS_INTERNET = 'تعديل طلب مفتوح على الداشبورد يحتاج اتصالًا بالإنترنت. تقدر تطبع الحساب وتحصّل الطلب كما هو، وتسجّل الأصناف الإضافية من صفحة الكاشير.';
const ACTION_NEEDS_INTERNET = 'هذا الإجراء على طلب مفتوح من الداشبورد يحتاج اتصالًا بالإنترنت.';
const NEW_DELIVERY_NEEDS_INTERNET = 'إنشاء أو تعديل طلب دليفري يحتاج اتصالًا بالإنترنت (تأكيد موقع العميل وسعر التوصيل). تقدر تحصّل الطلبات المفتوحة الآن.';
const NEEDS_UPDATE = 'هذه العملية تحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.';
const NOT_HERE = 'الطلب غير موجود على هذا الجهاز. افتحه بعد رجوع الإنترنت.';

const given = (value) => value !== undefined && value !== null && value !== '';
const yes = (value) => [true, 1, '1'].includes(value);
const text = (value, max) => (given(value) ? String(value) : '').trim().slice(0, max);
const cents = (value) => money.minor(String(value ?? '0'));

/** Laravel's integer|min:1 (and max). */
function positiveInt(value, max = Number.MAX_SAFE_INTEGER) {
  if (typeof value === 'boolean' || !given(value) || !/^[0-9]+$/.test(String(value).trim())) return null;
  const number = Number(value);
  return Number.isSafeInteger(number) && number >= 1 && number <= max ? number : null;
}

/** The same cart (lines merged, quantities, discount): the saved prices stay. */
function sameCart(items, discountCents, reason, data) {
  const lines = (list) => money.mergeItems(list).map((item) => [item.product_id, item.option_id, item.quantity_mode, item.quantity_millis])
    .sort((a, b) => String(a).localeCompare(String(b)));
  try {
    return JSON.stringify([lines(items), discountCents, reason]) === JSON.stringify([lines(data.items), cents(data.discount), data.discount_reason || '']);
  } catch {
    return false;
  }
}

class ServiceOffline {
  /** channel: dine (the dine-in page) or phone (the delivery page). */
  constructor({ store, origin, now = () => new Date(), kick = () => {}, channel = 'dine' }) {
    this.store = store;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
    this.channel = channel;
    this.prefix = channel === 'dine' ? 'dining' : 'phone-orders';
    this.takeaway = new TakeawayOffline({ store, origin, now, kick });
  }

  url(path) {
    return `${this.origin}/admin/${this.prefix}${path}`;
  }

  /** The requests this page sends; `always` ones (this device's own orders and kitchen tickets) are answered here online too. */
  match(method, pathname) {
    const base = `/admin/${this.prefix}`;
    if (!pathname.startsWith(base + '/')) return null;
    const rest = pathname.slice(base.length);
    const routes = { 'GET /catalog': 'catalog', 'POST /quote': 'quote', 'GET /tickets': 'tickets', 'POST /tickets/save': 'save', 'GET /recover': 'recover' };
    if (this.channel === 'dine') routes['GET /tables'] = 'tables';
    else Object.assign(routes, { 'GET /board': 'board', 'POST /dispatch-company': 'dispatch', 'POST /finish-batch': 'finishBatch' });
    const name = routes[`${method} ${rest}`];
    if (name) return { handle: (request) => this[name](request) };
    const ticket = /^\/tickets\/([1-9][0-9]{0,15})(?:\/(action|settle|details|print))?$/.exec(rest);
    if (ticket) {
      const kind = ticket[2] || 'show';
      if (method !== (['action', 'settle'].includes(kind) ? 'POST' : 'GET')) return null;
      const id = Number(ticket[1]);
      return { always: id > LOCAL_TICKET_BASE, handle: (request) => this[kind](request, id) };
    }
    const batch = this.channel === 'phone' && /^\/batches\/([1-9][0-9]{0,15})\/print$/.exec(rest);
    if (batch && method === 'GET') {
      const id = Number(batch[1]);
      return { always: id > LOCAL_BATCH_BASE, handle: (request) => this.batchPrint(request, id) };
    }
    const kitchen = /^\/kitchen\/([1-9][0-9]{0,15})\/print$/.exec(rest);
    if (kitchen && method === 'GET') {
      const id = Number(kitchen[1]);
      return { always: id > LOCAL_KITCHEN_BASE, handle: (request) => this.kitchen(request, id) };
    }
    return null;
  }

  answer(route, request) {
    return this.takeaway.answer(route, request);
  }

  user(request) {
    return this.takeaway.user(request);
  }

  supports(kind) {
    return (this.store.get('server_kinds', []) || []).includes(kind);
  }

  /** The branch's tables and open orders as last synced. */
  copy(branch) {
    const copy = this.store.service(branch);
    if (!copy) throw new Reply(503, 'لم يتم تنزيل طاولات وطلبات هذا الفرع على الجهاز بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    return copy;
  }

  /** PosServiceTable::policy, with the service charge of the menu version the device prices with. */
  policy(branch, copy) {
    const catalog = this.store.catalog(branch) || {};
    const bps = Number(catalog.service_bps || 0);
    return { service_bps: bps, service_rate: money.decimal(bps), settings_revision: Number((copy && copy.policy && copy.policy.settings_revision) || 1) };
  }

  /** A write's branch and operation number. */
  command(body) {
    if (!body || typeof body !== 'object') throw invalid('branch', 'اختر الفرع.');
    const branch = String(body.branch || '');
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    const key = String(body.idempotency_key || '');
    if (!UUID.test(key)) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    return { branch, key };
  }

  /** A retry of a write already done here: the same answer, never a second write. */
  replay(user, command, hash) {
    const done = this.store.pageCommand(command.key);
    if (!done) return null;
    if (done.actor_id !== user.id || done.branch !== command.branch || done.meta.hash !== hash) throw new Reply(409, 'رقم العملية مستخدم لطلب مختلف.');
    return this.result(user, done, true);
  }

  // ---- finding and presenting orders ----

  /** An order by the id the page knows: opened on this device (local) or open on the dashboard (server). */
  find(user, id) {
    if (id > LOCAL_TICKET_BASE) {
      const local = this.store.ticketBySeq(id - LOCAL_TICKET_BASE);
      if (!local || local.channel !== this.channel || !local.data || local.data.source !== 'dashboard' || local.data.kind !== 'local') throw new Reply(404, NOT_HERE);
      if (!user.branches.includes(local.branch)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
      return { id, branch: local.branch, local };
    }
    for (const branch of user.branches) {
      const copy = this.store.service(branch);
      const server = copy && (copy.tickets || []).find((item) => Number(item.id) === id && item.channel === this.channel);
      if (server) return { id, branch, server, copy, overlay: this.store.serviceOverlay(id) };
    }
    throw new Reply(404, NOT_HERE);
  }

  present(user, found, full = true) {
    return found.local ? this.presentLocal(user, found.local, full) : this.presentServer(user, found.server, found.overlay, full);
  }

  /** PosServiceTicket::present for an order open on the dashboard, with what this device did to it since. */
  presentServer(user, server, overlay, full = true) {
    const value = { ...server };
    delete value.courier_company; // the delivery board's own field, not part of the order
    if (overlay) {
      value.revision = overlay.revision;
      if (overlay.updated_at) value.updated_at = overlay.updated_at;
      if (overlay.status) value.status = overlay.status;
      if (overlay.kitchen) {
        value.kitchen_sent = true;
        value.kitchen_print_url = this.url(`/kitchen/${LOCAL_KITCHEN_BASE + overlay.kitchen}/print`);
        if (this.channel === 'dine') value.status = 'preparing';
        else if (value.status === 'new') value.status = 'preparing';
      }
      if (overlay.bill) {
        Object.assign(value, { bill_locked: true, bill_issued_at: overlay.bill.at, bill_issued_by: overlay.bill.by, bill_issued_revision: overlay.bill.revision });
        if (this.channel === 'dine') value.status = 'awaiting_bill';
      }
      if (overlay.paid) {
        const record = this.store.ticket(overlay.paid);
        const canReprint = !!user.can_reprint;
        Object.assign(value, { status: this.channel === 'dine' ? 'paid' : 'finished', payment_status: 'paid', customer_phone: overlay.phone || value.customer_phone,
          can_reprint: canReprint, bill_print_url: canReprint ? this.url(`/tickets/${value.id}/print`) : null,
          receipt_url: canReprint && record ? `${this.origin}/admin/takeaway/receipts/${LOCAL_RECEIPT_BASE + record.seq}/print` : null });
      }
    }
    if (!full) {
      delete value.items;
      delete value.cart;
    }
    return value;
  }

  /** The number shown for an order opened on this device (the dashboard's own orders keep theirs). */
  localNumber(ticket) {
    const code = String((this.store.device() || {}).code || 'D').toUpperCase();
    return `DI-${code}-${ticket.seq}`;
  }

  /** PosServiceTicket::present for an order opened on this device. */
  presentLocal(user, ticket, full = true) {
    const data = ticket.data;
    const id = LOCAL_TICKET_BASE + ticket.seq;
    const paid = ticket.status === 'paid';
    const canReprint = paid && !!user.can_reprint;
    const quote = data.quote;
    const value = { id, channel: ticket.channel, branch: quote.branch, revision: data.revision, status: data.status, payment_status: paid ? 'paid' : 'unpaid',
      table: data.table, waiter_name: data.waiter_name || '', guest_count: data.guest_count ?? null, customer_name: data.customer_name || '',
      customer_phone: data.customer_phone || '', address: '', area: '', delivery_notes: '', customer_id: null, customer_revision: null,
      delivery_company: null, delivery_location: null, delivery_fee: '0.00', notes: data.notes || '', cancel_reason: data.cancel_reason || '',
      created_at: cairoIso(ticket.created_at), updated_at: cairoIso(ticket.updated_at), cashier: { id: ticket.actor_id, name: ticket.actor_name },
      kitchen_sent: !!data.kitchen, bill_locked: !!data.bill, bill_issued_at: data.bill ? cairoIso(data.bill.at) : null,
      bill_issued_by: data.bill ? data.bill.by : null, bill_issued_revision: data.bill ? data.bill.revision : null,
      details_url: this.url(`/tickets/${id}/details`), quote_hash: quote.quote_hash, discount_reason: data.discount_reason || '',
      bill_print_url: !paid || canReprint ? this.url(`/tickets/${id}/print`) : null,
      kitchen_print_url: data.kitchen ? this.url(`/kitchen/${LOCAL_KITCHEN_BASE + data.kitchen}/print`) : null,
      can_reprint: canReprint, receipt_url: canReprint ? `${this.origin}/admin/takeaway/receipts/${LOCAL_RECEIPT_BASE + ticket.seq}/print` : null,
      number: this.localNumber(ticket) };
    for (const key of ['subtotal', 'discount', 'tax', 'tax_rate', 'service', 'service_rate', 'delivery', 'total']) value[key] = quote[key] ?? '0.00';
    if (full) {
      value.items = quote.items;
      value.cart = { branch: ticket.branch, items: quote.items.map((line) => ({ product_id: line.product_id, quantity: line.quantity,
        quantity_mode: line.quantity_mode, option_id: line.option_id })), discount: quote.discount, discount_reason: data.discount_reason || '', delivery_fee: '0.00' };
    }
    return value;
  }

  /** The order's saved quote (quote_snapshot): what its bill and its payment are checked against. */
  savedQuote(found, ticket) {
    if (found.local) return found.local.data.quote;
    return { success: true, quote_hash: ticket.quote_hash, branch: ticket.branch, items: ticket.items, subtotal_cents: cents(ticket.subtotal),
      discount_cents: cents(ticket.discount), tax_cents: cents(ticket.tax), total_cents: cents(ticket.total), subtotal: ticket.subtotal,
      discount: ticket.discount, tax_bps: cents(ticket.tax_rate), tax_rate: ticket.tax_rate, tax: ticket.tax, service_bps: cents(ticket.service_rate),
      service_cents: cents(ticket.service), delivery_cents: cents(ticket.delivery), service_rate: ticket.service_rate, service: ticket.service,
      delivery: ticket.delivery, total: ticket.total };
  }

  /** The order's details on its receipt (PosServiceTicket::contextSnapshot). */
  context(ticket, phone, consent) {
    return { delivery_company: ticket.delivery_company ?? null, delivery_location: ticket.delivery_location ?? null, table: ticket.table ?? null,
      table_name: ticket.table ? ticket.table.name : '', waiter_name: ticket.waiter_name || '', guest_count: ticket.guest_count ?? null,
      customer_name: ticket.customer_name || '', customer_phone: phone || '', address: ticket.address || '', area: ticket.area || '',
      delivery_notes: ticket.delivery_notes || '', ...(this.channel === 'dine' ? { service_consent: consent } : {}) };
  }

  /** A payment made here for an order of the dashboard (the paid invoice). */
  paymentFor(serverId) {
    return this.store.paymentFor(this.channel, serverId);
  }

  /** PosServiceTicket::result: the operation, the order as it is now, and its receipt once paid. */
  result(user, done, replayed) {
    let ticket = null;
    let paid = null;
    if (done.ticket_ref.startsWith('S:')) {
      const serverId = Number(done.ticket_ref.slice(2));
      paid = this.paymentFor(serverId);
      let found = null;
      try { found = this.find(user, serverId); } catch { found = null; }
      if (found) ticket = this.present(user, found, true);
      else if (paid) ticket = this.presentServer(user, paid.data.server_ticket, { revision: paid.data.revision, paid: paid.id, phone: paid.data.customer_phone }, true);
    } else {
      const local = this.store.ticket(done.ticket_ref);
      if (local && local.data.kind === 'local') {
        ticket = this.presentLocal(user, local, true);
        paid = local.status === 'paid' ? local : null;
      }
    }
    if (!ticket) throw new Reply(404, NOT_HERE);
    const out = { success: true, replayed, operation: { id: done.seq, kind: done.kind, idempotency_key: done.key, ticket_id: ticket.id, revision: done.revision },
      ticket, receipt: null, receipt_url: null, first_print_url: null };
    if (done.meta.kitchen) out.kitchen_print_url = this.url(`/kitchen/${LOCAL_KITCHEN_BASE + done.meta.kitchen}/print`);
    if (this.channel === 'phone' && ticket.kitchen_sent) out.print_queued = true;
    if (paid) {
      out.receipt = this.takeaway.presentReceipt(user, paid);
      out.receipt_url = out.receipt.receipt_url;
      if (done.kind === 'settle' && done.actor_id === user.id) out.first_print_url = this.takeaway.firstPrintUrl(user, paid);
    }
    return out;
  }

  // ---- GET catalog: the menu, with the page's service charge and rules ----
  catalog(request) {
    const reply = this.takeaway.catalog(request);
    const branch = request.url.searchParams.get('branch');
    const policy = this.policy(branch, this.store.service(branch));
    reply.json.policy = { ...reply.json.policy, service_rate: this.channel === 'dine' ? policy.service_rate : '0.00',
      settings_revision: policy.settings_revision, channel: this.channel };
    return reply;
  }

  // ---- POST quote: a cart priced with the device's menu version, or an order's saved quote ----
  quote({ user: who, body }) {
    const user = this.user({ user: who });
    if (body && given(body.ticket_id) && !Object.prototype.hasOwnProperty.call(body, 'items')) {
      if (!BRANCH.test(String(body.branch || ''))) throw invalid('branch', 'اختر الفرع.');
      this.takeaway.branch(user, body.branch);
      const id = positiveInt(body.ticket_id);
      if (!id) throw invalid('ticket_id', 'الطلب غير صالح.');
      const found = this.find(user, id);
      if (found.branch !== body.branch) throw new Reply(404, NOT_HERE);
      const ticket = this.present(user, found, true);
      if (ticket.status === 'cancelled') throw new Reply(409, CLOSED);
      return json({ ...this.savedQuote(found, ticket), ticket_id: ticket.id, revision: ticket.revision });
    }
    if (this.channel !== 'dine') throw new Reply(422, NEW_DELIVERY_NEEDS_INTERNET);
    const cart = this.takeaway.cart(body);
    const catalog = this.takeaway.branch(user, cart.branch);
    return json(this.takeaway.price(user, catalog, cart, this.channel).result);
  }

  /** The floor: the copy's tables with what this device did on them (orders opened here, orders paid here). */
  floor(user, branch, copy) {
    const local = this.store.pageTickets(branch, 'dine').filter((ticket) => ticket.status === 'open' && ticket.data.kind === 'local');
    return (copy.tables || []).map((table) => {
      const value = { ...table };
      if (value.ticket_id) {
        const overlay = this.store.serviceOverlay(Number(value.ticket_id));
        const server = (copy.tickets || []).find((item) => Number(item.id) === Number(value.ticket_id));
        if (overlay && overlay.paid) Object.assign(value, { status: 'free', ticket_id: null, ticket: null, revision: Number(value.revision) + 1 });
        else if (overlay && server) {
          value.ticket = this.presentServer(user, server, overlay, false);
          value.status = value.ticket.status === 'awaiting_bill' ? 'awaiting_bill' : 'in_service';
        }
      }
      const mine = !value.ticket_id && local.find((ticket) => Number(ticket.data.table.id) === Number(value.id));
      if (mine) {
        const ticket = this.presentLocal(user, mine, false);
        Object.assign(value, { ticket_id: ticket.id, ticket, status: ticket.status === 'awaiting_bill' ? 'awaiting_bill' : 'in_service',
          revision: Number(value.revision) + 1 });
      }
      return value;
    });
  }

  // ---- GET tables (dine-in): PosServiceTable::listing ----
  tables({ user: who, url }) {
    const user = this.user({ user: who });
    const value = url.searchParams.get('branch') || '';
    if (value === '' || value === 'all') {
      if (user.account_type !== 'admin') throw new Reply(403, 'اختر الفرع.');
      const tables = [];
      for (const branch of this.store.branches()) {
        const copy = user.branches.includes(branch.value) ? this.store.service(branch.value) : null;
        if (copy) tables.push(...this.floor(user, branch.value, copy));
      }
      return json({ success: true, branch: { value: '', name: ALL_BRANCHES }, tables, policy: [], permissions: permissions(user) });
    }
    if (!BRANCH.test(value)) throw invalid('branch', 'اختر الفرع.');
    const catalog = this.takeaway.branch(user, value);
    const copy = this.copy(value);
    return json({ success: true, branch: catalog.branch, tables: this.floor(user, value, copy), policy: this.policy(value, copy), permissions: permissions(user) });
  }

  /** PosServiceTicket::summary: the page's day, drawer and rules. */
  summary(user, branch, catalog, copy) {
    const policy = this.policy(branch, copy);
    return { ready: true, register: register(catalog, branch), today: this.takeaway.today([branch]), permissions: permissions(user),
      policy: { tax_rate: money.decimal(Number(catalog.tax_bps || 0)), service_rate: this.channel === 'dine' ? policy.service_rate : '0.00',
        can_discount: !!user.can_manage, payment_methods: ['cash'], currency: 'EGP', settings_revision: policy.settings_revision, channel: this.channel } };
  }

  // ---- GET tickets: the branch's orders (open on the dashboard, and those of this device) ----
  tickets({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const value = query.get('branch') || '';
    if (!BRANCH.test(value)) throw invalid('branch', 'اختر الفرع.');
    const catalog = this.takeaway.branch(user, value);
    const copy = this.copy(value);
    let items = this.store.pageTickets(value, this.channel).filter((ticket) => ticket.data.kind === 'local' && ticket.status !== 'moved')
      .map((ticket) => this.presentLocal(user, ticket, false));
    for (const server of copy.tickets || []) {
      if (server.channel === this.channel) items.push(this.presentServer(user, server, this.store.serviceOverlay(Number(server.id)), false));
    }
    const company = positiveInt(query.get('delivery_company_id'));
    if (company) items = items.filter((item) => item.delivery_company && Number(item.delivery_company.id) === company);
    const status = query.get('status') || '';
    if (status && status !== 'all') items = items.filter((item) => item.status === status);
    const search = (query.get('search') || '').trim();
    if (search) items = items.filter((item) => [item.customer_name, item.customer_phone, item.waiter_name].some((field) => String(field || '').includes(search)));
    items.sort((a, b) => b.id - a.id);
    const perPage = Math.min(40, Math.max(1, positiveInt(query.get('per_page')) || 20));
    const total = items.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const page = Math.min(lastPage, Math.max(1, positiveInt(query.get('page')) || 1));
    const slice = items.slice((page - 1) * perPage, page * perPage);
    return json({ success: true, branch: catalog.branch, items: slice, tickets: slice, pagination: { page, last_page: lastPage, per_page: perPage, total },
      ...this.summary(user, value, catalog, copy) });
  }

  // ---- GET tickets/{id} ----
  show({ user: who }, id) {
    const user = this.user({ user: who });
    const found = this.find(user, id);
    if (found.local && found.local.status === 'moved') {
      // Back online it moved to the dashboard: open it there.
      if (found.local.server_id) return { status: 302, location: this.url(`/tickets/${found.local.server_id}`) };
      throw new Reply(409, MOVED);
    }
    return json({ success: true, ticket: this.present(user, found, true) });
  }

  /** An order of this device that can still change (PosServiceTicket::mutable). */
  mutable(local) {
    if (local.status === 'moved') throw new Reply(409, MOVED);
    if (local.status !== 'open') throw new Reply(409, CLOSED);
    if (local.data.bill) throw new Reply(409, BILL_ISSUED);
  }

  // ---- POST tickets/save: open a table, or change an order opened on this device ----
  save({ user: who, body }) {
    const user = this.user({ user: who });
    if (this.channel !== 'dine') throw new Reply(422, NEW_DELIVERY_NEEDS_INTERNET);
    const command = this.command(body);
    const ticketId = given(body.ticket_id) ? positiveInt(body.ticket_id) : null;
    if (given(body.ticket_id) && !ticketId) throw invalid('ticket_id', 'الطلب غير صالح.');
    const waiter = text(body.waiter_name, 100);
    if (!waiter) throw invalid('waiter_name', 'هذا الحقل مطلوب.');
    const guests = positiveInt(body.guest_count, 200);
    if (!guests) throw invalid('guest_count', 'عدد الضيوف من ١ إلى ٢٠٠.');
    const customer = text(body.customer_name, 100);
    const notes = text(body.notes, 500);
    const consent = yes(body.service_consent);
    const marketing = yes(body.marketing_consent);
    let phone = '';
    if (text(body.customer_phone, 30) || consent || marketing) {
      phone = rules.normalizePhone(body.customer_phone);
      if (!phone) throw invalid('customer_phone', 'اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
    }
    const cart = this.takeaway.cart({ ...body, branch: command.branch });
    const tableId = positiveInt(body.table_id);
    const hash = sha256([this.channel, 'save', command, ticketId, tableId, cart, String(body.quote_hash || ''), yes(body.reprice), waiter, guests, customer,
      text(body.customer_phone, 30), notes, consent, marketing, positiveInt(body.expected_revision)]);
    const replay = this.replay(user, command, hash);
    if (replay) return json(replay);
    const catalog = this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (!ticketId) {
      const copy = this.copy(command.branch);
      const table = tableId && (copy.tables || []).find((item) => Number(item.id) === tableId);
      if (!table || !table.active) throw new Reply(422, 'اختر طاولة فعلية متاحة.');
      if (this.floor(user, command.branch, copy).find((item) => Number(item.id) === tableId).ticket_id) throw new Reply(409, 'الطاولة مشغولة.');
      if (guests > Number(table.capacity)) throw new Reply(422, 'عدد الضيوف أكبر من سعة الطاولة.');
      const { quote, result } = this.takeaway.price(user, catalog, cart, 'dine');
      if (result.quote_hash !== String(body.quote_hash || '')) throw new Reply(409, 'تغير السعر أو الإعداد. راجع الإجمالي.');
      const data = { source: 'dashboard', kind: 'local', revision: 1, status: 'open', table: { id: Number(table.id), name: table.name, capacity: Number(table.capacity) },
        waiter_name: waiter, guest_count: guests, customer_name: customer, customer_phone: phone, notes, items: cart.items,
        discount: money.decimal(cart.discount_cents), discount_reason: cart.discount_reason, quote: result, priced: quote, snapshot_id: catalog.snapshot_id,
        service_consent: consent, marketing_consent: marketing, kitchen: null, bill: null, cancel_reason: '' };
      this.store.atomically(() => {
        const created = this.store.createPageTicket(user, { id: command.key, branch: command.branch, channel: 'dine', label: table.name, data });
        this.store.recordPageCommand(command.key, 'save', created.id, 1, user, command.branch, { hash });
      });
      return json(this.result(user, this.store.pageCommand(command.key), false));
    }
    const found = this.find(user, ticketId);
    if (found.branch !== command.branch) throw new Reply(404, NOT_HERE);
    if (!found.local) throw new Reply(422, EDIT_NEEDS_INTERNET);
    const local = found.local;
    this.mutable(local);
    if (positiveInt(body.expected_revision) !== local.data.revision) throw new Reply(409, CHANGED);
    if (guests > Number(local.data.table.capacity)) throw new Reply(422, 'عدد الضيوف أكبر من سعة الطاولة.');
    const data = { ...local.data, revision: local.data.revision + 1, waiter_name: waiter, guest_count: guests, customer_name: customer, customer_phone: phone,
      notes, service_consent: consent, marketing_consent: marketing };
    delete data.move_op;
    if (yes(body.reprice) || !sameCart(cart.items, cart.discount_cents, cart.discount_reason, local.data)) {
      const { quote, result } = this.takeaway.price(user, catalog, cart, 'dine');
      if (result.quote_hash !== String(body.quote_hash || '')) throw new Reply(409, 'تغير السعر أو الإعداد. راجع الإجمالي.');
      Object.assign(data, { items: cart.items, discount: money.decimal(cart.discount_cents), discount_reason: cart.discount_reason, quote: result, priced: quote,
        snapshot_id: catalog.snapshot_id });
    }
    this.store.atomically(() => {
      if (!this.store.cancelTicketMove(local.id)) throw new Reply(409, MOVING);
      this.store.updatePageTicket(local.id, data);
      this.store.recordPageCommand(command.key, 'save', local.id, data.revision, user, command.branch, { hash });
    });
    return json(this.result(user, this.store.pageCommand(command.key), false));
  }

  // ---- POST tickets/{id}/action: send to the kitchen, print the bill, cancel ----
  action({ user: who, body }, id) {
    const user = this.user({ user: who });
    const command = this.command(body);
    const allowed = this.channel === 'dine' ? ['send_kitchen', 'request_bill', 'cancel'] : ['send_kitchen', 'request_bill', 'prepare', 'dispatch', 'finish', 'cancel'];
    const expected = positiveInt(body.expected_revision);
    if (!expected) throw invalid('expected_revision', 'رقم إصدار الطلب غير صالح.');
    if (!allowed.includes(body.action)) throw invalid('action', 'الإجراء غير معروف.');
    const reason = text(body.reason, 500);
    if (body.action === 'cancel' && !reason) throw invalid('reason', 'اكتب سبب الإلغاء.');
    const hash = sha256([this.channel, id, 'action', command, expected, body.action, reason]);
    const replay = this.replay(user, command, hash);
    if (replay) return json(replay);
    this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    const found = this.find(user, id);
    if (found.branch !== command.branch) throw new Reply(404, NOT_HERE);
    if (found.local) this.localAction(user, command, found.local, body.action, expected, reason, hash);
    else this.serverAction(user, command, found, body.action, expected, hash);
    this.kick();
    return json(this.result(user, this.store.pageCommand(command.key), false));
  }

  localAction(user, command, local, action, expected, reason, hash) {
    this.mutable(local);
    if (expected !== local.data.revision) throw new Reply(409, CHANGED);
    if (action === 'send_kitchen' && local.data.kitchen) throw new Reply(409, KITCHEN_SENT);
    if (action === 'cancel' && local.data.kitchen) throw new Reply(409, 'لا يمكن إلغاء فاتورة أُرسلت للمطبخ.');
    if (action !== 'cancel' && !(local.data.items || []).length) throw new Reply(422, 'الفاتورة فارغة.');
    const revision = local.data.revision + 1;
    const data = { ...local.data, revision };
    delete data.move_op;
    const meta = { hash };
    let status = 'open';
    if (action === 'cancel') {
      Object.assign(data, { status: 'cancelled', cancel_reason: reason });
      status = 'cancelled';
    } else if (action === 'send_kitchen') {
      data.status = 'preparing';
    } else {
      Object.assign(data, { status: 'awaiting_bill', bill: { at: this.now().toISOString(), by: user.id, revision } });
    }
    this.store.atomically(() => {
      if (!this.store.cancelTicketMove(local.id)) throw new Reply(409, MOVING);
      const updated = this.store.updatePageTicket(local.id, data, status);
      if (action === 'send_kitchen') {
        meta.kitchen = this.store.addKitchenPrint(local.id, revision, this.presentLocal(user, updated, true));
        this.store.updatePageTicket(local.id, { ...data, kitchen: meta.kitchen }, status);
      }
      this.store.recordPageCommand(command.key, 'action', local.id, revision, user, command.branch, meta);
    });
  }

  /** An order open on the dashboard: done here, and told to the dashboard with the page's operation number. */
  serverAction(user, command, found, action, expected, hash) {
    const { server, overlay } = found;
    const ticket = this.presentServer(user, server, overlay, true);
    if (ticket.payment_status !== 'unpaid' || ticket.status === 'cancelled') throw new Reply(409, CLOSED);
    if (ticket.bill_locked) throw new Reply(409, BILL_ISSUED);
    if (expected !== Number(ticket.revision)) throw new Reply(409, CHANGED);
    const possible = action === 'request_bill' || (action === 'send_kitchen' && this.channel === 'dine');
    if (!possible) throw new Reply(422, ACTION_NEEDS_INTERNET);
    if (!this.supports('ticket_action')) throw new Reply(422, NEEDS_UPDATE);
    if (action === 'send_kitchen' && ticket.kitchen_sent) throw new Reply(409, KITCHEN_SENT);
    if (!(ticket.items || []).length) throw new Reply(422, 'الفاتورة فارغة.');
    const revision = Number(ticket.revision) + 1;
    const stamp = cairoIso(this.now().toISOString());
    const next = { ...(overlay || {}), base_revision: overlay ? overlay.base_revision : Number(server.revision), revision, updated_at: stamp };
    const meta = { hash };
    this.store.atomically(() => {
      if (action === 'request_bill') next.bill = { at: stamp, by: user.id, revision };
      else {
        const snapshot = { ...this.presentServer(user, server, { ...next, kitchen: null }, true), status: 'preparing' };
        next.kitchen = this.store.addKitchenPrint(`S:${server.id}`, revision, snapshot);
        meta.kitchen = next.kitchen;
      }
      this.store.overlayServerTicket(Number(server.id), next, { id: command.key, kind: 'ticket_action', actor_id: user.id, branch: command.branch,
        data: { channel: this.channel, ticket_id: Number(server.id), action, quote_hash: server.quote_hash } });
      this.store.recordPageCommand(command.key, 'action', `S:${server.id}`, revision, user, command.branch, meta);
    });
  }

  // ---- POST tickets/{id}/settle: the payment, in cash ----
  settle({ user: who, body }, id) {
    const user = this.user({ user: who });
    const command = this.command(body);
    const expected = positiveInt(body.expected_revision);
    if (!expected) throw invalid('expected_revision', 'رقم إصدار الطلب غير صالح.');
    if (!/^[a-f0-9]{64}$/i.test(String(body.quote_hash || ''))) throw invalid('quote_hash', 'راجع الإجمالي وأعد المحاولة.');
    if (typeof body.payment_method !== 'string' || !body.payment_method) throw invalid('payment_method', 'اختر طريقة الدفع.');
    if (this.channel === 'phone' && body.payment_confirmed !== true) throw new Reply(422, 'أكد تحصيل قيمة الطلب فعليًا قبل إنهائه.');
    const consent = yes(body.service_consent);
    const hash = sha256([this.channel, id, 'settle', command, expected, body.quote_hash, body.payment_method, String(body.cash_received ?? ''),
      text(body.payment_reference, 150), this.channel === 'dine' ? [text(body.customer_phone, 30), consent, yes(body.marketing_consent)] : null]);
    const replay = this.replay(user, command, hash);
    if (replay) return json(replay);
    const catalog = this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (this.channel === 'dine' && !consent) throw invalid('service_consent', 'يجب تأكيد موافقة العميل على متابعة الخدمة قبل إنهاء الفاتورة.');
    if (body.payment_method !== 'cash' || (Array.isArray(body.tenders) && body.tenders.length)) throw new Reply(422, 'الدفع متاح كاش فقط.');
    const found = this.find(user, id);
    if (found.branch !== command.branch) throw new Reply(404, NOT_HERE);
    if (found.local && found.local.status === 'moved') throw new Reply(409, MOVED);
    const ticket = this.present(user, found, true);
    if (ticket.payment_status !== 'unpaid' || ticket.status === 'cancelled') throw new Reply(409, CLOSED);
    if (expected !== Number(ticket.revision)) throw new Reply(409, CHANGED);
    let phone = rules.normalizePhone(ticket.customer_phone) || '';
    if (this.channel === 'dine') {
      phone = rules.normalizePhone(given(body.customer_phone) ? body.customer_phone : ticket.customer_phone);
      if (!phone) throw invalid('customer_phone', 'اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
    }
    if (ticket.quote_hash !== body.quote_hash) throw new Reply(409, 'راجع الفاتورة المحفوظة.');
    let cash;
    try { cash = money.minor(String(body.cash_received ?? '0')); } catch { throw invalid('cash_received', 'اكتب مبلغًا صحيحًا بحد أقصى منزلتين عشريتين.'); }
    if (cash < 0 || cash > 100000000) throw invalid('cash_received', 'المبلغ خارج الحد المسموح.');
    if (cash < cents(ticket.total)) throw invalid('cash_received', 'المبلغ المستلم أقل من الجزء النقدي للفاتورة.');
    const revision = Number(ticket.revision) + 1;
    const context = this.context(ticket, phone, consent);
    if (found.local) {
      const local = found.local;
      const data = { ...local.data, status: 'paid', revision, customer_phone: phone, service_consent: true,
        marketing_consent: yes(body.marketing_consent) || !!local.data.marketing_consent, settle_key: command.key, page_ticket_id: ticket.id,
        table_name: local.data.table.name, address: '', context, delivery_company_id: null, driver: '' };
      delete data.move_op;
      this.store.atomically(() => {
        if (!this.store.cancelTicketMove(local.id)) throw new Reply(409, MOVING);
        this.store.completeSale(user, local, data, { snapshot_id: local.data.snapshot_id }, local.data.priced, cash, phone,
          { operationId: command.key, insert: false });
        this.store.recordPageCommand(command.key, 'settle', local.id, revision, user, command.branch, { hash });
      });
    } else {
      if (!this.supports('ticket_settle')) throw new Reply(422, NEEDS_UPDATE);
      const { server, overlay } = found;
      const data = { source: 'dashboard', kind: 'payment', server_ticket_id: Number(server.id), server_ticket: ticket, page_ticket_id: ticket.id,
        settle_key: command.key, revision, customer_name: ticket.customer_name || '', customer_phone: phone, address: ticket.address || '',
        notes: ticket.notes || '', discount_reason: ticket.discount_reason || '', table_name: ticket.table ? ticket.table.name : '', context,
        service_consent: consent };
      const next = { ...(overlay || {}), base_revision: overlay ? overlay.base_revision : Number(server.revision), revision, paid: command.key, phone,
        updated_at: cairoIso(this.now().toISOString()) };
      this.store.atomically(() => {
        this.store.recordServerPayment(user, { id: command.key, branch: command.branch, channel: this.channel, label: ticket.table ? ticket.table.name : '',
          data, totals: this.totals(ticket, cash), server_ticket_id: Number(server.id), overlay: next, phone,
          operation: { channel: this.channel, ticket_id: Number(server.id), expected_revision: next.base_revision, quote_hash: server.quote_hash,
            cash_received: money.decimal(cash), customer_phone: phone || null, service_consent: consent } });
        this.store.recordPageCommand(command.key, 'settle', `S:${server.id}`, revision, user, command.branch, { hash });
      });
    }
    this.kick();
    return json({ ...this.result(user, this.store.pageCommand(command.key), false), register: register(catalog, command.branch),
      today: this.takeaway.today([command.branch]) });
  }

  /** The paid invoice's amounts, from the order's saved quote. */
  totals(ticket, cash) {
    const items = (ticket.items || []).map((line) => ({ product_id: line.product_id, name: line.name, option_id: line.option_id || '',
      option_label: line.option_label || '', unit: line.unit || '', quantity_mode: line.quantity_mode, quantity_millis: line.quantity_millis,
      quantity: line.quantity, unit_price_cents: line.unit_price_cents ?? cents(line.unit_price), total_cents: line.total_cents ?? cents(line.total) }));
    const total = cents(ticket.total);
    return { items, subtotal_cents: cents(ticket.subtotal), discount_cents: cents(ticket.discount), service_bps: cents(ticket.service_rate),
      service_cents: cents(ticket.service), delivery_cents: cents(ticket.delivery), tax_bps: cents(ticket.tax_rate), tax_cents: cents(ticket.tax),
      total_cents: total, cash_received_cents: cash, change_cents: cash - total };
  }

  // ---- GET board (delivery): PhoneDeliveryBoard::listing, the orders by stage ----
  board({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const scope = query.get('branch') || 'all';
    if (scope !== 'all' && !BRANCH.test(scope)) throw invalid('branch', 'اختر الفرع.');
    let values = [scope];
    if (scope === 'all') {
      if (user.account_type !== 'admin') throw new Reply(403, 'اختر الفرع.');
      values = this.store.branches().map((branch) => branch.value).filter((value) => user.branches.includes(value));
    } else {
      this.takeaway.branch(user, scope);
      this.copy(scope);
    }
    const company = positiveInt(query.get('delivery_company_id'));
    const orders = [];
    const companies = [];
    for (const value of values) {
      for (const item of ((this.store.catalog(value) || {}).delivery_companies || [])) companies.push({ id: Number(item.id), name: item.name, branch: value });
      const copy = this.store.service(value);
      for (const server of (copy && copy.tickets) || []) {
        if (server.channel !== 'phone') continue;
        const overlay = this.store.serviceOverlay(Number(server.id));
        const record = overlay && overlay.paid ? this.store.ticket(overlay.paid) : null;
        const courier = (overlay && overlay.courier_company) || server.courier_company || null;
        if (company && !(courier && Number(courier.id) === company)) continue;
        orders.push({ ...this.presentServer(user, server, overlay, false), courier_company: courier, batch_print_url: null,
          completed_at: record ? cairoIso(record.paid_at) : null });
      }
    }
    const columns = {};
    for (const stage of ['preparing', 'courier', 'finished']) {
      const items = orders.filter((order) => (stage === 'finished' ? order.payment_status === 'paid' || order.status === 'cancelled'
        : order.payment_status === 'unpaid' && (stage === 'preparing' ? ['new', 'preparing'] : ['out_for_delivery', 'finished']).includes(order.status)))
        .sort((a, b) => (stage === 'finished' ? b.id - a.id : a.id - b.id));
      const lastPage = Math.max(1, Math.ceil(items.length / 20));
      const page = Math.min(positiveInt(query.get(`${stage}_page`)) || 1, lastPage);
      columns[stage] = { items: items.slice((page - 1) * 20, page * 20), pagination: { page, last_page: lastPage, total: items.length } };
    }
    companies.sort((a, b) => String(a.name).localeCompare(String(b.name), 'ar'));
    return json({ success: true, branch: scope, columns, companies });
  }

  // ---- POST dispatch-company (delivery): an order handed to a delivery company, told to the dashboard (ticket_dispatch) ----
  dispatch({ user: who, body }) {
    const user = this.user({ user: who });
    const command = this.command(body);
    const ticketId = positiveInt(body.ticket_id);
    if (!ticketId) throw invalid('ticket_id', 'الطلب غير صالح.');
    const companyId = positiveInt(body.company_id);
    if (!companyId) throw invalid('company_id', 'اختر شركة التوصيل.');
    const expected = given(body.expected_revision) ? positiveInt(body.expected_revision) : null;
    const hash = sha256(['phone.dispatch', command, ticketId, companyId, expected]);
    const done = this.store.pageCommand(command.key);
    if (done) {
      if (done.actor_id !== user.id || done.branch !== command.branch || done.meta.hash !== hash) throw new Reply(409, 'رقم العملية مستخدم لبيانات مختلفة.');
      return json({ ...done.meta.result, replayed: true });
    }
    const catalog = this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (!this.supports('ticket_dispatch')) throw new Reply(422, NEEDS_UPDATE);
    const found = this.find(user, ticketId);
    if (found.branch !== command.branch || !found.server) throw new Reply(404, NOT_HERE);
    const ticket = this.presentServer(user, found.server, found.overlay, true);
    if (expected !== Number(ticket.revision)) throw new Reply(409, 'البيانات تغيرت. حدّث الصفحة قبل الحفظ.');
    if (ticket.payment_status !== 'unpaid' || !['new', 'preparing'].includes(ticket.status)) throw new Reply(409, 'الطلب انتقل من مرحلة التجهيز. حدّث القائمة.');
    if (!(ticket.items || []).length) throw new Reply(422, 'الفاتورة فارغة.');
    const company = (catalog.delivery_companies || []).find((item) => Number(item.id) === companyId);
    if (!company) throw new Reply(422, 'اختر شركة توصيل مفعّلة في الفرع المحدد.');
    const snapshot = { id: Number(company.id), name: company.name, phone: company.phone || '', contact_name: company.contact_name || '' };
    const revision = Number(ticket.revision) + 1;
    const result = { operation: { kind: 'phone.dispatch', idempotency_key: command.key }, branch: command.branch, ticket_id: ticketId, revision, company: snapshot,
      success: true };
    const next = { ...(found.overlay || {}), base_revision: found.overlay ? found.overlay.base_revision : Number(found.server.revision), revision,
      status: 'out_for_delivery', courier_company: snapshot, updated_at: cairoIso(this.now().toISOString()) };
    this.store.atomically(() => {
      this.store.overlayServerTicket(ticketId, next, { id: command.key, kind: 'ticket_dispatch', actor_id: user.id, branch: command.branch,
        data: { ticket_id: ticketId, company_id: companyId } });
      this.store.recordPageCommand(command.key, 'phone.dispatch', `S:${ticketId}`, revision, user, command.branch, { hash, result });
    });
    this.kick();
    return json({ ...result, replayed: false });
  }

  // ---- POST finish-batch (delivery): the orders a courier brought the money for, collected together (ticket_batch) ----
  finishBatch({ user: who, body }) {
    const user = this.user({ user: who });
    const command = this.command(body);
    const items = Array.isArray(body.items) ? body.items : null;
    if (!items || !items.length || items.length > 50) throw invalid('items', 'اختر الطلبات.');
    const lines = items.map((item) => ({ id: positiveInt(item && item.id), revision: positiveInt(item && item.revision),
      quote_hash: String((item && item.quote_hash) || '') }));
    if (lines.some((line) => !line.id || !line.revision || !/^[a-f0-9]{64}$/i.test(line.quote_hash))
      || new Set(lines.map((line) => line.id)).size !== lines.length) throw invalid('items', 'اختر الطلبات.');
    const courier = text(body.courier_name, 100);
    if (!['cash', 'card', 'mobile_wallet', 'other'].includes(body.payment_method)) throw invalid('payment_method', 'اختر طريقة الدفع.');
    if (!yes(body.payment_confirmed) && !['yes', 'on'].includes(body.payment_confirmed)) throw invalid('payment_confirmed', 'أكد تحصيل قيمة الطلبات.');
    const amount = (value) => {
      let cents;
      try { cents = money.minor(String(value ?? '')); } catch { throw new Reply(422, 'أدخل مبلغًا صحيحًا بمنزلتين عشريتين.'); }
      if (cents < 0 || cents > 100000000) throw new Reply(422, 'المبلغ خارج الحدود المسموحة.');
      return cents;
    };
    const expected = amount(body.total);
    const received = body.payment_method === 'cash' ? amount(body.cash_received) : 0;
    const hash = sha256(['phone.batch', command, lines, courier, expected, body.payment_method, received, text(body.payment_reference, 150)]);
    const done = this.store.pageCommand(command.key);
    if (done) {
      if (done.actor_id !== user.id || done.branch !== command.branch || done.meta.hash !== hash) throw new Reply(409, 'رقم العملية مستخدم لبيانات مختلفة.');
      return json({ ...done.meta.result, replayed: true });
    }
    const catalog = this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (body.payment_method !== 'cash') throw new Reply(422, 'الدفع متاح كاش فقط.');
    if (!this.supports('ticket_batch')) throw new Reply(422, NEEDS_UPDATE);
    // PhoneDeliveryBoard::finish, order by order.
    let total = 0;
    let company = null;
    const orders = [];
    for (const line of [...lines].sort((a, b) => a.id - b.id)) {
      const found = this.find(user, line.id);
      if (found.branch !== command.branch || !found.server) throw new Reply(404, NOT_HERE);
      const ticket = this.presentServer(user, found.server, found.overlay, true);
      if (ticket.payment_status !== 'unpaid' || !['out_for_delivery', 'finished'].includes(ticket.status)) throw new Reply(409, 'أحد الطلبات تم تحصيله أو تغيرت مرحلته. حدّث القائمة.');
      if (line.revision !== Number(ticket.revision)) throw new Reply(409, 'البيانات تغيرت. حدّث الصفحة قبل الحفظ.');
      if (ticket.quote_hash !== line.quote_hash) throw new Reply(409, 'تغيرت قيمة أحد الطلبات.');
      const assigned = (found.overlay && found.overlay.courier_company) || found.server.courier_company || ticket.delivery_company;
      if (!assigned || !assigned.id) throw new Reply(422, 'يجب إسناد الطلب إلى شركة توصيل أولًا.');
      if (company && Number(company.id) !== Number(assigned.id)) throw new Reply(422, 'اختر طلبات شركة واحدة لمندوب واحد.');
      company = company || assigned;
      total += cents(ticket.total);
      orders.push({ found, ticket });
    }
    if (total !== expected) throw new Reply(409, 'راجع إجمالي الطلبات المحددة.');
    if (received < total) throw new Reply(422, 'النقد المستلم أقل من إجمالي الطلبات.');
    const stamp = cairoIso(this.now().toISOString());
    let result;
    this.store.atomically(() => {
      this.store.queueOperation({ id: command.key, kind: 'ticket_batch', actor_id: user.id, branch: command.branch, data: { items: orders.map(({ ticket }) => ({
        id: ticket.id, quote_hash: ticket.quote_hash })), courier_name: courier, total: money.decimal(total), cash_received: money.decimal(received) } });
      const paid = orders.map(({ found, ticket }) => {
        // Each order: a paid invoice on the device under the batch (the dashboard pays them all with the batch).
        const id = derivedKey(command.key, String(ticket.id));
        const revision = Number(ticket.revision) + 1;
        const phone = rules.normalizePhone(ticket.customer_phone) || '';
        const record = this.store.recordServerPayment(user, { id, branch: command.branch, channel: 'phone', label: '', phone, operation: null,
          totals: this.totals(ticket, cents(ticket.total)), server_ticket_id: Number(ticket.id),
          data: { source: 'dashboard', kind: 'payment', server_ticket_id: Number(ticket.id), server_ticket: ticket, page_ticket_id: ticket.id, settle_key: id,
            batch_key: command.key, revision, customer_name: ticket.customer_name || '', customer_phone: phone, address: ticket.address || '',
            notes: ticket.notes || '', discount_reason: ticket.discount_reason || '', table_name: '', context: this.context(ticket, phone, false), service_consent: false },
          overlay: { ...(found.overlay || {}), base_revision: found.overlay ? found.overlay.base_revision : Number(found.server.revision), revision, paid: id, phone,
            updated_at: stamp, ops: [...((found.overlay && found.overlay.ops) || []), command.key] } });
        return { ticket_id: Number(ticket.id), order_id: LOCAL_RECEIPT_BASE + record.seq, total: ticket.total };
      });
      const recorded = this.store.recordPageCommand(command.key, 'phone.batch', 'B:' + command.key, 0, user, command.branch, { hash });
      const id = LOCAL_BATCH_BASE + recorded.seq;
      const snapshot = { branch: catalog.branch, cashier: { id: user.id, name: user.name }, company, courier_name: courier, created_at: stamp, items: paid,
        total: money.decimal(total), payment_method: 'cash', cash_received: money.decimal(received), change: money.decimal(received - total),
        payment_reference: text(body.payment_reference, 150), idempotency_key: command.key };
      result = { operation: { kind: 'phone.batch', idempotency_key: command.key }, batch: { ...snapshot, id, print_url: this.url(`/batches/${id}/print`) }, success: true };
      this.store.setPageCommandMeta(command.key, { hash, result });
    });
    this.kick();
    return json({ ...result, replayed: false });
  }

  // ---- GET batches/{id}/print (delivery): the courier's receipt of a batch collected on this device ----
  batchPrint({ user: who, url }, id) {
    const user = this.user({ user: who });
    const done = id > LOCAL_BATCH_BASE ? this.store.pageCommandBySeq(id - LOCAL_BATCH_BASE) : null;
    const batch = done && done.kind === 'phone.batch' && done.meta.result && done.meta.result.batch;
    if (!batch || !user.branches.includes(done.branch)) return { status: 404, html: notFound('الإيصال غير محفوظ على هذا الجهاز.') };
    return { status: 200, html: renderBatch(batch, { origin: this.origin }) };
  }

  // ---- GET recover: a write the page is unsure about ----
  recover({ user: who, url }) {
    const user = this.user({ user: who });
    const branch = url.searchParams.get('branch') || '';
    const key = url.searchParams.get('idempotency_key') || '';
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    if (!UUID.test(key)) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    this.takeaway.branch(user, branch);
    const done = this.store.pageCommand(key);
    if (!done || done.actor_id !== user.id || done.branch !== branch) return json({ success: true, found: false, ticket: null, receipt: null });
    return json({ ...this.result(user, done, true), found: true });
  }

  // ---- GET tickets/{id}/print and /details: the bill before payment, the receipt after ----
  print(request, id) {
    return this.page(request, id, false);
  }

  details(request, id) {
    return this.page(request, id, true);
  }

  page({ user: who, url }, id, preview) {
    const user = this.user({ user: who });
    let found;
    try {
      found = this.find(user, id);
    } catch (error) {
      if (error instanceof Reply) return { status: error.status, html: notFound(error.message) };
      throw error;
    }
    const ticket = this.present(user, found, true);
    if (ticket.payment_status === 'paid') {
      const record = found.local || this.store.ticket(found.overlay.paid);
      return this.takeaway.receiptPage({ user: who, url }, record.seq, preview ? 'details' : 'print');
    }
    if (!preview && (!ticket.bill_locked || ticket.status === 'cancelled')) return { status: 409, html: notFound('أصدر فاتورة الدفع أولًا.') };
    return { status: 200, html: renderReceipt(ticketBill(ticket), { origin: this.origin, preview,
      dashboardPrint: ['1', 'true'].includes(url.searchParams.get('dashboard_print')) }) };
  }

  // ---- GET kitchen/{id}/print: a kitchen ticket printed on this device ----
  kitchen({ user: who, url }, id) {
    const user = this.user({ user: who });
    const print = id > LOCAL_KITCHEN_BASE ? this.store.kitchenPrint(id - LOCAL_KITCHEN_BASE) : null;
    const ticket = print && print.snapshot;
    if (!ticket || ticket.channel !== this.channel || !ticket.branch || !user.branches.includes(ticket.branch.value)) {
      return { status: 404, html: notFound('طلب المطبخ غير محفوظ على هذا الجهاز. اطبعه بعد رجوع الإنترنت.') };
    }
    return { status: 200, html: renderKitchen({ id, ticket_id: ticket.id, revision: print.revision, created_at: cairoIso(print.created_at), ticket },
      { origin: this.origin, dashboardPrint: ['1', 'true'].includes(url.searchParams.get('dashboard_print')) }) };
  }
}

/** A fixed operation number for one part of an operation (the dashboard's DesktopSyncOperations::derivedKey). */
function derivedKey(id, step) {
  const hex = crypto.createHash('sha256').update(`${id}:${step}`).digest('hex').slice(0, 32);
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-5${hex.slice(13, 16)}-a${hex.slice(17, 20)}-${hex.slice(20, 32)}`;
}

module.exports = { ServiceOffline, LOCAL_TICKET_BASE, LOCAL_KITCHEN_BASE, LOCAL_BATCH_BASE };
