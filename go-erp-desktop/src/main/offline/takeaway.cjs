'use strict';
// The dashboard's cashier page (تيك أواي) while the internet is down: the same requests, answered from the device's
// database in the same shape as App\Http\Controllers\Dashboard\TakeawayController / TakeawayService. Prices come from
// the branch's synced menu version, so the dashboard accepts every sale when the device sends it (30-second sync).
const crypto = require('node:crypto');
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { renderReceipt } = require('./receipt.cjs');

// Invoices made on this device get receipt ids far above the dashboard's own, so both never mix.
const LOCAL_RECEIPT_BASE = 9000000000000;
const BRANCH = /^(f|gs):[1-9][0-9]{0,18}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const BASE_LABEL = 'الأساسي';

class Reply extends Error {
  constructor(status, message, errors = null) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

const json = (body, status = 200) => ({ status, json: body });
const invalid = (field, message) => new Reply(422, message, { [field]: [message] });
const sha256 = (value) => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');

/** TakeawayAccess::permissions, from the account as last synced. */
function permissions(user) {
  return { can_checkout: !!user.can_checkout, can_manage_tables: !!user.can_checkout, can_manage: !!user.can_manage, can_reprint: !!user.can_reprint };
}

function policy(catalog, user) {
  return { tax_rate: money.decimal(Number(catalog.tax_bps || 0)), service_rate: '0.00', can_discount: !!user.can_manage,
    payment_methods: ['cash'], currency: 'EGP' };
}

function register(catalog, branch) {
  return { id: null, branch, revision: 1, tax_rate: money.decimal(Number(catalog.tax_bps || 0)), currency: 'EGP' };
}

/** Cards for a menu synced before the dashboard sent its own cards: built from the priced variants. */
function cardsFromVariants(catalog) {
  return (catalog.products || []).map((product) => {
    const base = product.variants.find((variant) => !variant.option_id) || product.variants[0];
    return { id: product.id, name: product.name, price: money.decimal(base.unit_price_cents), unit_price: money.decimal(base.unit_price_cents),
      available: true, image_url: product.image || '', unit: product.unit || '', quantity_mode: base.quantity_mode || 'select',
      category_id: product.category_id ?? null, revision: null,
      options: product.variants.filter((variant) => variant.option_id).map((variant) => ({ id: variant.option_id, label: variant.label,
        price: money.decimal(variant.unit_price_cents) })) };
  });
}

class TakeawayOffline {
  /** store: the device database; origin: the dashboard's address; now(): the server-corrected time; kick(): sync soon. */
  constructor({ store, origin, now = () => new Date(), kick = () => {} }) {
    this.store = store;
    this.origin = origin;
    this.now = now;
    this.kick = kick;
  }

  /** The requests this page sends; `always` ones are answered here online too (receipts made on this device). */
  match(method, pathname) {
    if (method === 'GET' && pathname === '/admin/takeaway/catalog') return { handle: (request) => this.catalog(request) };
    if (method === 'POST' && pathname === '/admin/takeaway/quote') return { handle: (request) => this.quote(request) };
    if (method === 'POST' && pathname === '/admin/takeaway/checkout') return { handle: (request) => this.checkout(request) };
    if (method === 'GET' && pathname === '/admin/takeaway/receipts') return { handle: (request) => this.receipts(request) };
    const receipt = /^\/admin\/takeaway\/receipts\/([1-9][0-9]{0,15})\/(print|details)$/.exec(pathname);
    if (method === 'GET' && receipt) {
      const id = Number(receipt[1]);
      return { always: id > LOCAL_RECEIPT_BASE, local: id > LOCAL_RECEIPT_BASE,
        handle: (request) => (id > LOCAL_RECEIPT_BASE ? this.receiptPage(request, id - LOCAL_RECEIPT_BASE, receipt[2]) : null) };
    }
    return null;
  }

  /** Runs one request; request: { user, url (URL), body (parsed JSON or null) }. */
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

  branch(user, value) {
    if (typeof value !== 'string' || !BRANCH.test(value)) throw invalid('branch', 'اختر الفرع.');
    if (!user.branches.includes(value)) throw new Reply(403, 'الفرع غير متاح لهذا الحساب.');
    const catalog = this.store.catalog(value);
    if (!catalog) throw new Reply(503, 'لم يتم تنزيل مينيو هذا الفرع على الجهاز بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    return catalog;
  }

  /** TakeawayService::readBranches: one branch, or every branch of this device the account has ("all"). */
  readBranches(user, value) {
    if (value && value !== 'all') {
      const catalog = this.branch(user, value);
      return { branch: catalog.branch || { value }, values: [value] };
    }
    if (user.account_type !== 'admin') throw new Reply(403, 'اختر الفرع.');
    const values = this.store.branches().map((branch) => branch.value).filter((branch) => user.branches.includes(branch));
    return { branch: { value: '', name: 'جميع الفروع' }, values };
  }

  today(values, day = rules.operatingDay(this.now())) {
    return this.store.dayTotals(values, day);
  }

  // ---- GET /admin/takeaway/catalog ----
  catalog({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const value = query.get('branch') || '';
    const catalog = this.branch(user, value);
    let items = Array.isArray(catalog.listing) ? catalog.listing : cardsFromVariants(catalog);
    const search = (query.get('search') || '').trim().toLowerCase();
    if (search) items = items.filter((item) => String(item.name).toLowerCase().includes(search));
    const category = query.get('category_id');
    if (category) items = items.filter((item) => String(item.category_id) === String(Number(category)));
    const perPage = Math.min(100, Math.max(1, Number(query.get('per_page')) || 40));
    const total = items.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const page = Math.min(lastPage, Math.max(1, Number(query.get('page')) || 1));
    const slice = items.slice((page - 1) * perPage, page * perPage);
    const result = { success: true, ready: true, branch: catalog.branch, items: slice, products: slice, categories: catalog.categories || [],
      pagination: { page, last_page: lastPage, per_page: perPage, total }, permissions: permissions(user), policy: policy(catalog, user) };
    if (!['1', 'true'].includes(query.get('catalog_only'))) {
      result.register = register(catalog, value);
      result.today = this.today([value]);
    }
    return json(result);
  }

  /** TakeawayService::cart: the page's lines checked the same way. */
  cart(body) {
    if (!body || typeof body !== 'object') throw invalid('items', 'الفاتورة فارغة.');
    if (!Array.isArray(body.items) || !body.items.length || body.items.length > 100) throw invalid('items', 'الفاتورة فارغة.');
    const items = body.items.map((item) => {
      const quantity = String(item && item.quantity !== undefined ? item.quantity : '');
      if (!/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/.test(quantity) || !['piece', 'weight'].includes(item.quantity_mode)
        || !Number.isSafeInteger(Number(item.product_id)) || Number(item.product_id) < 1) {
        throw invalid('items', 'العدد يجب أن يكون صحيحًا، والوزن بحد أقصى ٣ منازل عشرية، والكمية من ٠٫٠٠١ إلى ١٠٠٠.');
      }
      const millis = money.millis(quantity);
      if (millis < 1 || millis > 1000000 || (item.quantity_mode === 'piece' && millis % 1000 !== 0)) {
        throw invalid('items', 'العدد يجب أن يكون صحيحًا، والوزن بحد أقصى ٣ منازل عشرية، والكمية من ٠٫٠٠١ إلى ١٠٠٠.');
      }
      return { product_id: Number(item.product_id), option_id: item.option_id === undefined || item.option_id === null ? '' : String(item.option_id),
        quantity_mode: item.quantity_mode, quantity };
    });
    let discount = 0;
    try { discount = money.minor(body.discount === undefined || body.discount === null || body.discount === '' ? '0' : String(body.discount)); }
    catch { throw invalid('discount', 'اكتب مبلغًا صحيحًا بحد أقصى منزلتين عشريتين.'); }
    if (discount < 0 || discount > 100000000) throw invalid('discount', 'المبلغ خارج الحد المسموح.');
    return { branch: body.branch, items, discount_cents: discount, discount_reason: String(body.discount_reason || '').trim() };
  }

  /** TakeawayService::price, with the device's menu version (DesktopPosQuote: what the dashboard checks on sync). */
  price(user, catalog, cart) {
    if (cart.discount_cents > 0 && !user.can_manage) throw new Reply(403, 'الخصم متاح للمالك أو الإدارة المخولة فقط.');
    if (cart.discount_cents > 0 && !cart.discount_reason) throw invalid('discount_reason', 'اكتب سبب الخصم.');
    let quote;
    try {
      quote = money.quote(catalog, { channel: 'takeaway', items: cart.items, discount_cents: cart.discount_cents });
    } catch (error) {
      throw new Reply(422, error.message);
    }
    const items = quote.items.map((line) => {
      const label = line.option_label === BASE_LABEL ? '' : (line.option_label || '');
      return { product_id: line.product_id, name: line.name, option_id: line.option_id, option_label: label, unit: line.unit || '',
        quantity_mode: line.quantity_mode, quantity_millis: line.quantity_millis, quantity: line.quantity, unit_price_cents: line.unit_price_cents,
        unit_price: money.decimal(line.unit_price_cents), total_cents: line.total_cents, total: money.decimal(line.total_cents) };
    });
    const hash = sha256(['device', catalog.snapshot_id, cart.branch, items.map((line) => [line.product_id, line.option_id, line.quantity_mode,
      line.quantity_millis, line.unit_price_cents, line.total_cents]), quote.discount_cents, cart.discount_reason, quote.tax_bps, quote.tax_cents, quote.total_cents]);
    return { quote, result: { success: true, quote_hash: hash, branch: catalog.branch, items,
      subtotal_cents: quote.subtotal_cents, discount_cents: quote.discount_cents, tax_cents: quote.tax_cents, total_cents: quote.total_cents,
      subtotal: money.decimal(quote.subtotal_cents), discount: money.decimal(quote.discount_cents), tax_bps: quote.tax_bps,
      tax_rate: money.decimal(quote.tax_bps), tax: money.decimal(quote.tax_cents), service_bps: 0, service_cents: 0, delivery_cents: 0,
      service_rate: '0.00', service: '0.00', delivery: '0.00', total: money.decimal(quote.total_cents) } };
  }

  // ---- POST /admin/takeaway/quote ----
  quote({ user: who, body }) {
    const user = this.user({ user: who });
    const cart = this.cart(body);
    const catalog = this.branch(user, cart.branch);
    return json(this.price(user, catalog, cart).result);
  }

  // ---- POST /admin/takeaway/checkout ----
  checkout({ user: who, body }) {
    const user = this.user({ user: who });
    const cart = this.cart(body);
    const catalog = this.branch(user, cart.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (!UUID.test(String(body.idempotency_key || ''))) throw invalid('idempotency_key', 'رقم العملية غير صالح.');
    if (!/^[a-f0-9]{64}$/.test(String(body.quote_hash || ''))) throw invalid('quote_hash', 'راجع الإجمالي وأعد المحاولة.');
    const method = body.payment_method === 'wallet' ? 'mobile_wallet' : body.payment_method;
    if (!['cash', 'card', 'mobile_wallet', 'other', 'mixed'].includes(method)) throw invalid('payment_method', 'اختر طريقة الدفع.');
    let cash;
    try { cash = money.minor(String(body.cash_received ?? '0')); } catch { throw invalid('cash_received', 'اكتب مبلغًا صحيحًا بحد أقصى منزلتين عشريتين.'); }
    if (cash < 0 || cash > 100000000) throw invalid('cash_received', 'المبلغ خارج الحد المسموح.');
    const phone = rules.normalizePhone(body.customer_phone);
    const customer = { customer_phone: phone || '', customer_name: String(body.customer_name || '').trim().slice(0, 100),
      service_consent: [true, 1, '1'].includes(body.service_consent), marketing_consent: [true, 1, '1'].includes(body.marketing_consent) };
    const notes = String(body.notes || '').trim().slice(0, 500);
    const requestHash = sha256([cart, body.quote_hash, method, cash, String(body.payment_reference || '').trim(), notes, customer]);
    const existing = this.store.ticket(body.idempotency_key);
    if (existing) {
      // A retry of a sale already saved here: the same invoice again, never a second one.
      const replay = this.store.pageSale(user, { id: body.idempotency_key, branch: cart.branch, request_hash: requestHash });
      return json(this.checkoutResult(user, replay.ticket, true));
    }
    if (!phone) throw invalid('customer_phone', 'اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
    if (!customer.service_consent) throw invalid('service_consent', 'يجب تأكيد موافقة العميل على متابعة الخدمة قبل إنهاء الفاتورة.');
    if (method !== 'cash') throw new Reply(422, 'الدفع متاح كاش فقط.');
    const { quote, result } = this.price(user, catalog, cart);
    if (result.quote_hash !== body.quote_hash) throw new Reply(409, 'تغير سعر أو ضريبة أو وصفة الفاتورة. راجع الإجمالي وأعد المحاولة.');
    if (cash < quote.total_cents) throw invalid('cash_received', 'المبلغ المستلم أقل من الجزء النقدي للفاتورة.');
    const sale = this.store.pageSale(user, { id: body.idempotency_key, branch: cart.branch, request_hash: requestHash, catalog, quote, cash_cents: cash,
      data: { items: cart.items, customer_phone: phone, customer_name: customer.customer_name, notes, discount: money.decimal(cart.discount_cents),
        discount_reason: cart.discount_reason, service_consent: customer.service_consent, marketing_consent: customer.marketing_consent } });
    this.kick();
    return json(this.checkoutResult(user, sale.ticket, sale.replayed));
  }

  checkoutResult(user, ticket, replayed) {
    const receipt = this.presentReceipt(user, ticket);
    const catalog = this.store.catalog(ticket.branch) || {};
    return { success: true, replayed, receipt, receipt_url: receipt.receipt_url, first_print_url: this.firstPrintUrl(user, ticket),
      stock_balances: {}, register: register(catalog, ticket.branch), today: this.today([ticket.branch]) };
  }

  /** PosReceiptPrinting::firstPrintUrl: the cashier's own one-time first print (ten minutes). */
  firstPrintUrl(user, ticket) {
    if (ticket.actor_id !== user.id || !user.can_checkout || !ticket.first_print || ticket.first_print === 'used') return null;
    if (this.now().getTime() - Date.parse(ticket.paid_at) > 10 * 60 * 1000) return null;
    return `${this.origin}/admin/takeaway/receipts/${LOCAL_RECEIPT_BASE + ticket.seq}/print?initial_print=${ticket.first_print}`;
  }

  /** TakeawayService::presentReceipt for an invoice saved on this device. */
  presentReceipt(user, ticket) {
    const totals = ticket.totals;
    const catalog = this.store.catalog(ticket.branch);
    const known = this.store.branches().find((branch) => branch.value === ticket.branch) || {};
    const branch = (catalog && catalog.branch) || { value: ticket.branch, name: known.name || '', address: known.address || '', phone: known.phone || '' };
    const id = LOCAL_RECEIPT_BASE + ticket.seq;
    const data = ticket.data;
    const context = { customer_name: data.customer_name || '', customer_phone: rules.normalizePhone(data.customer_phone) || data.customer_phone || '' };
    if (ticket.channel === 'phone') {
      context.address = data.address || '';
      const company = data.delivery_company_id && catalog ? (catalog.delivery_companies || []).find((item) => item.id === data.delivery_company_id) : null;
      if (company) context.delivery_company = { id: company.id, name: company.name, phone: company.phone || '' };
      if (data.driver) context.driver = data.driver;
    }
    if (ticket.channel === 'dine' && data.table_name) context.table = { name: data.table_name };
    const canReprint = !!user.can_reprint;
    const items = (totals.items || []).map((line) => ({ product_id: line.product_id, name: line.name, option_id: line.option_id || null,
      option_label: line.option_label === BASE_LABEL ? null : (line.option_label || null), unit: line.unit || '', quantity_mode: line.quantity_mode,
      quantity: line.quantity, unit_price: money.decimal(line.unit_price_cents), total: money.decimal(line.total_cents) }));
    return { id, number: ticket.server_number || ticket.local_number, idempotency_key: ticket.id, branch,
      cashier: { id: ticket.actor_id, name: ticket.actor_name }, business_date: ticket.business_date, created_at: cairoIso(ticket.paid_at), items,
      payment_method: 'cash', payment_confirmed: true, payment_reference: '', notes: data.notes || '', tax_rate: money.decimal(totals.tax_bps || 0),
      discount_reason: data.discount_reason || '', currency: 'EGP', service: money.decimal(totals.service_cents || 0),
      service_rate: money.decimal(totals.service_bps || 0), delivery: money.decimal(totals.delivery_cents || 0), channel: ticket.channel,
      ticket_id: null, context, payment_breakdown: [{ method: 'cash', amount: money.decimal(totals.total_cents), confirmed: true, reference: '' }],
      status: 'completed', details_url: `${this.origin}/admin/takeaway/receipts/${id}/details`, can_reprint: canReprint,
      receipt_url: canReprint ? `${this.origin}/admin/takeaway/receipts/${id}/print` : null,
      subtotal: money.decimal(totals.subtotal_cents), discount: money.decimal(totals.discount_cents || 0), tax: money.decimal(totals.tax_cents),
      total: money.decimal(totals.total_cents), cash_received: money.decimal(totals.cash_received_cents), change: money.decimal(totals.change_cents),
      device: { local_number: ticket.local_number, sync_status: ticket.sync_status } };
  }

  // ---- GET /admin/takeaway/receipts (the day's invoices, or one sale's recovery by its operation number) ----
  receipts({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const selection = this.readBranches(user, query.get('branch') || '');
    const key = query.get('idempotency_key') || '';
    const day = /^\d{4}-\d{2}-\d{2}$/.test(query.get('date') || '') ? query.get('date') : rules.operatingDay(this.now());
    if (key) {
      if (!selection.branch.value) throw new Reply(422, 'اختر الفرع لتأكيد نتيجة عملية البيع.');
      const ticket = this.store.ticket(key);
      const found = ticket && ticket.status === 'paid' && ticket.actor_id === user.id && selection.values.includes(ticket.branch) ? ticket : null;
      const items = found ? [this.presentReceipt(user, found)] : [];
      return json({ success: true, branch: selection.branch, today: this.today(selection.values, day), items, receipt: items[0] || null,
        replayed: items.length > 0, first_print_url: found ? this.firstPrintUrl(user, found) : null,
        pagination: { page: 1, last_page: 1, per_page: 20, total: items.length } });
    }
    const perPage = Math.min(50, Math.max(1, Number(query.get('per_page')) || 20));
    const { total } = this.store.paidTickets(selection.values, day, 1, 1);
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const page = Math.min(lastPage, Math.max(1, Number(query.get('page')) || 1));
    const { items } = this.store.paidTickets(selection.values, day, page, perPage);
    return json({ success: true, branch: selection.branch, today: this.today(selection.values, day), items: items.map((ticket) => this.presentReceipt(user, ticket)),
      receipt: null, replayed: false, first_print_url: null, pagination: { page, last_page: lastPage, per_page: perPage, total } });
  }

  // ---- GET /admin/takeaway/receipts/{id}/print and /details for invoices saved on this device ----
  receiptPage({ user: who, url }, seq, kind) {
    const user = this.user({ user: who });
    const ticket = this.store.ticketBySeq(seq);
    if (!ticket || ticket.status !== 'paid' || !user.branches.includes(ticket.branch)) return { status: 404, html: notFound() };
    if (kind === 'print' && !user.can_reprint) {
      const token = url.searchParams.get('initial_print') || '';
      if (!/^[0-9a-f]{64}$/.test(token) || !this.store.useFirstPrint(ticket.id, user.id, token)) {
        return { status: 403, html: notFound('إعادة طباعة الفاتورة متاحة للمالك والإدارة فقط.') };
      }
    }
    const receipt = this.presentReceipt(user, ticket);
    return { status: 200, html: renderReceipt(receipt, { origin: this.origin, preview: kind === 'details',
      dashboardPrint: ['1', 'true'].includes(url.searchParams.get('dashboard_print')) }) };
  }
}

function notFound(message = 'الفاتورة غير موجودة على هذا الجهاز.') {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>${message}</title></head><body><p>${message}</p></body></html>`;
}

const CAIRO_PARTS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });

/** Carbon's toIso8601String() in the dashboard's time zone (Africa/Cairo), e.g. 2026-10-30T21:45:09+02:00. */
function cairoIso(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const parts = Object.fromEntries(CAIRO_PARTS.formatToParts(date).map((part) => [part.type, part.value]));
  const wall = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
  const offset = Math.round((wall - Math.floor(date.getTime() / 1000) * 1000) / 60000);
  const sign = offset < 0 ? '-' : '+';
  const hours = String(Math.floor(Math.abs(offset) / 60)).padStart(2, '0');
  const minutes = String(Math.abs(offset) % 60).padStart(2, '0');
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}${sign}${hours}:${minutes}`;
}

module.exports = { TakeawayOffline, LOCAL_RECEIPT_BASE, cairoIso, permissions };
