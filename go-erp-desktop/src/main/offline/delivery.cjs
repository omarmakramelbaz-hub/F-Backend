'use strict';
// The dashboard's delivery page (الدليفري) placing a new order while the internet is down, as the dashboard's own page
// does in its no-map mode: the customer by number (the branch's saved customers, synced), their saved address or the
// coordinates of their location (e.g. shared on WhatsApp), the delivery fee from the branch's pin and tariff
// (BranchDeliveryTariff, the same calculation), then the order. It lives on the device until it is paid, or moves to the
// dashboard once the internet is back (ticket_open). Its kitchen ticket goes to the branch printer as on the dashboard.
const crypto = require('node:crypto');
const money = require('../money.cjs');
const rules = require('../rules.cjs');
const { ServiceOffline, LOCAL_TICKET_BASE, LOCAL_KITCHEN_BASE } = require('./service.cjs');
const { Reply, json, invalid, sha256, UUID, BRANCH } = require('./takeaway.cjs');

const EARTH_RADIUS = 6378137.0;
const NOT_READY = 'حدد دبوس الفرع وسعر الكيلومتر في إعدادات المطعم أولًا.';
const CHANGED_LOCATION = 'موقع العميل أو سعر التوصيل تغير. أكد الدبوس وراجع الخدمة.';
const ORIGIN_NEEDS_INTERNET = 'طلبات الأستور تحتاج اتصالًا بالإنترنت.';

const given = (value) => value !== undefined && value !== null && value !== '';
const yes = (value) => [true, 1, '1', 'yes', 'on'].includes(value);
const text = (value, max) => (given(value) ? String(value) : '').trim().slice(0, max);

/** PosServicePhone::key: Arabic digits as digits, then only digits and +. */
function phoneDigits(value) {
  return String(value ?? '').replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[^0-9+]/g, '');
}

/** BranchCustomers::phoneKey: Egyptian mobiles as 01xxxxxxxxx. */
function phoneKey(value) {
  let key = phoneDigits(value).replace(/^(?:\+20|0020|20)(1[0125][0-9]*)$/, '0$1');
  if (/^1[0125][0-9]{8}$/.test(key)) key = '0' + key;
  return key;
}

/** PosServicePhone::customers: the number as typed, and the same number written the other common ways. */
function phoneVariants(value) {
  const key = phoneDigits(value);
  let keys = [key];
  let found;
  if (key.startsWith('0') && !key.startsWith('00')) keys.push('+20' + key.slice(1), '20' + key.slice(1), '0020' + key.slice(1));
  else if ((found = /^(?:\+20|0020|20)([1-9][0-9]*)$/.exec(key))) keys.push('0' + found[1], '+20' + found[1], '20' + found[1], '0020' + found[1]);
  for (const variant of [...keys]) if ((found = /^0(1[0125][0-9]*)$/.exec(variant))) keys.push(found[1]);
  if (/^1[0125][0-9]*$/.test(key)) keys.push('0' + key, '+20' + key, '20' + key, '0020' + key);
  keys = [...new Set(keys)];
  return { key, keys };
}

/** BranchDeliveryTariff::distance (PHP's deg2rad is degrees / 180 × π). */
function distance(lat1, lng1, lat2, lng2) {
  const rad = (degrees) => (degrees / 180) * Math.PI;
  const latDelta = rad(lat2 - lat1);
  const lngDelta = rad(lng2 - lng1);
  const a = Math.sin(latDelta / 2) * Math.sin(latDelta / 2)
    + Math.sin(lngDelta / 2) * Math.sin(lngDelta / 2) * Math.cos(rad(lat1)) * Math.cos(rad(lat2));
  return EARTH_RADIUS * 2 * Math.asin(Math.sqrt(Math.max(0, Math.min(1, a))));
}

/** BranchDeliveryTariff::price: the branch's tiers by nearest kilometre, or its price per kilometre. */
function tariff(meters, rates) {
  if (!Number.isFinite(meters) || meters < 0) throw new Reply(422, 'مسافة التوصيل غير صالحة.');
  if (!given(rates.km_price)) throw new Reply(422, 'حدد سعر التوصيل في بيانات الفرع أولًا.');
  const km = meters / 1000;
  const perKm = money.minor(rates.km_price);
  let cents;
  let rule;
  let tier = null;
  if (perKm === 0) {
    cents = 0;
    rule = 'app_zero_rate';
  } else if (rates.legacy) {
    cents = Math.round(km * perKm);
    rule = 'branch_per_km_legacy';
  } else {
    tier = { 0: 'default_0_1', 1: 'default_1_2', 2: 'default_2_3', 3: 'default_2_3' }[Math.floor(km + 0.5)] ?? null;
    if (tier !== null) {
      if (!given(rates[tier])) throw new Reply(422, 'أكمل شرائح سعر التوصيل في بيانات الفرع أولًا.');
      cents = money.minor(rates[tier]);
      rule = 'app_branch_tier';
    } else {
      cents = Math.round(km * perKm);
      rule = 'app_per_km';
    }
  }
  if (cents > 100000000) throw new Reply(422, 'قيمة التوصيل أكبر من الحد المسموح.');
  return { delivery_fee: money.decimal(cents), pricing_rule: rule, pricing_tier: tier, pricing_distance_meters: meters,
    pricing_distance_km: km.toFixed(6), pricing_rates: rates };
}

/** PosServiceTicket::fingerprint: sha256 of the value with its keys sorted, as PHP encodes it. */
function fingerprint(value) {
  const sort = (item) => {
    if (Array.isArray(item)) return item.map(sort);
    if (item && typeof item === 'object') return Object.fromEntries(Object.keys(item).sort().map((key) => [key, sort(item[key])]));
    return item;
  };
  return crypto.createHash('sha256').update(JSON.stringify(sort(value))).digest('hex');
}

const coordinate = (value, limit) => {
  const number = Number(value);
  return given(value) && Number.isFinite(number) && Math.abs(number) <= limit ? number : null;
};

class DeliveryOffline extends ServiceOffline {
  constructor(options) {
    super({ ...options, channel: 'phone' });
  }

  match(method, pathname) {
    // Shared with the customers and delivery companies pages: the delivery page's customer step and its companies.
    if (method === 'POST' && pathname === '/admin/customers/save') return { handle: (request) => this.customerSave(request) };
    if (method === 'GET' && pathname === '/admin/delivery-companies/data') return { keepOnline: true, handle: (request) => this.companies(request) };
    const base = '/admin/phone-orders';
    if (pathname.startsWith(base + '/')) {
      const routes = { 'GET /customers': 'customers', 'GET /delivery-settings': 'deliverySettings', 'POST /delivery-quote': 'deliveryQuote',
        'POST /address-suggestions': 'addressSuggestions', 'GET /print-jobs': 'printJobs', 'POST /print-jobs/claim': 'printClaim',
        'POST /print-jobs/complete': 'printComplete' };
      const name = routes[`${method} ${pathname.slice(base.length)}`];
      if (name) return { handle: (request) => this[name](request) };
    }
    return super.match(method, pathname);
  }

  /** The branch's delivery pin and tariff as last synced. */
  settings(branch) {
    const copy = this.store.service(branch);
    return copy && copy.delivery ? copy.delivery : null;
  }

  // ---- GET delivery-settings: PhoneDelivery::settings ----
  deliverySettings({ user: who, url }) {
    const user = this.user({ user: who });
    const branch = url.searchParams.get('branch') || '';
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    if (given(url.searchParams.get('delivery_origin_branch'))) throw new Reply(422, ORIGIN_NEEDS_INTERNET);
    this.takeaway.branch(user, branch);
    const settings = this.settings(branch);
    if (!settings) throw new Reply(503, 'لم يتم تنزيل إعدادات التوصيل لهذا الفرع على الجهاز بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    return json({ success: true, settings });
  }

  // ---- GET /admin/delivery-companies/data?branch&active=1: the branch's active delivery companies ----
  companies({ user: who, url }) {
    const query = url.searchParams;
    // Other uses of this list (the companies page) open from its last saved copy.
    if (!['1', 'true'].includes(query.get('active')) || !BRANCH.test(query.get('branch') || '')) return null;
    const user = this.user({ user: who });
    const catalog = this.takeaway.branch(user, query.get('branch'));
    const items = (catalog.delivery_companies || []).map((company) => ({ id: Number(company.id), branch: query.get('branch'), name: company.name,
      phone: company.phone || '', active: true }));
    return json({ success: true, items, pagination: { page: 1, last_page: 1, total: items.length } });
  }

  // ---- GET customers: PosServicePhone::customers, from the branch's saved customers and those this device served ----
  customers({ user: who, url }) {
    const user = this.user({ user: who });
    const query = url.searchParams;
    const branch = query.get('branch') || '';
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    const catalog = this.takeaway.branch(user, branch);
    const { key, keys } = phoneVariants(query.get('phone') || '');
    if (!/^\+?[0-9]{3,20}$/.test(key)) throw new Reply(422, 'اكتب ٣ أرقام على الأقل.');
    const prefix = ['1', 'true'].includes(query.get('prefix'));
    const items = [];
    const seen = new Set();
    const add = (item) => {
      const signature = JSON.stringify([phoneKey(item.phone), item.address.trim(), item.area.trim()]);
      if (seen.has(signature) || items.length >= 8) return;
      seen.add(signature);
      items.push({ branch, branch_name: (catalog.branch && catalog.branch.name) || '', customer_id: item.customer_id ?? null,
        customer_revision: item.customer_revision ?? null, name: item.name, phone: phoneKey(item.phone), address: item.address, area: item.area,
        delivery_notes: item.delivery_notes || '', latitude: item.latitude ?? null, longitude: item.longitude ?? null });
    };
    for (const row of this.store.directoryByPhone(branch, keys.map(phoneKey).concat(keys), prefix)) {
      add({ customer_id: row.id, customer_revision: row.revision, name: row.name, phone: row.phone, address: row.address, area: row.area,
        delivery_notes: row.delivery_notes, latitude: row.latitude, longitude: row.longitude });
    }
    // Customers this device served (name and address, without a location).
    for (const row of this.store.db.prepare('SELECT * FROM customers ORDER BY last_order_at DESC LIMIT 200').all()) {
      const stored = phoneKey(row.phone);
      if (!keys.map(phoneKey).some((wanted) => (prefix ? stored.startsWith(wanted) : stored === wanted))) continue;
      if (row.address) add({ name: row.name, phone: row.phone, address: row.address, area: '', delivery_notes: '' });
    }
    return json({ success: true, branch: catalog.branch, items, customers: items, matches: items });
  }

  // ---- POST /admin/customers/save: BranchCustomers::save, kept here and sent (customer_save) ----
  customerSave({ user: who, body }) {
    const user = this.user({ user: who });
    const command = this.command(body);
    const name = text(body.name, 100);
    const address = text(body.address, 500);
    if (!name || !address) throw new Reply(422, 'أدخل اسم العميل وعنوانه.');
    const phone = text(body.phone, 30);
    const key = phoneKey(phone);
    if (!/^\+?[0-9]{6,20}$/.test(key)) throw new Reply(422, 'رقم الهاتف غير صالح.');
    const latitude = coordinate(body.latitude, 90);
    const longitude = coordinate(body.longitude, 180);
    if ((latitude === null) !== (longitude === null)) throw new Reply(422, 'أدخل إحداثيات الموقع كاملة.');
    const values = { branch: command.branch, key: command.key, customer_id: given(body.customer_id) ? Number(body.customer_id) : null, name, phone, phone_key: key,
      address, area: text(body.area, 150), delivery_notes: text(body.delivery_notes, 500), latitude, longitude };
    const hash = sha256(['customer.save', values, given(body.expected_revision) ? Number(body.expected_revision) : null]);
    const done = this.store.pageCommand(command.key);
    if (done) {
      if (done.actor_id !== user.id || done.branch !== command.branch || done.meta.hash !== hash) throw new Reply(409, 'رقم العملية مستخدم لبيانات مختلفة.');
      return json({ ...done.meta.result, replayed: true });
    }
    this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (!this.supports('customer_save')) throw new Reply(422, 'حفظ العميل يحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.');
    if (values.customer_id) {
      const row = this.store.db.prepare('SELECT revision FROM directory WHERE id = ? AND branch = ?').get(values.customer_id, command.branch);
      if (!row) throw new Reply(404, 'العميل غير موجود على هذا الجهاز.');
      if (given(body.expected_revision) && Number(body.expected_revision) !== row.revision) throw new Reply(409, 'البيانات تغيرت. حدّث الصفحة قبل الحفظ.');
    }
    let result;
    this.store.atomically(() => {
      const saved = this.store.saveDirectoryCustomer(user, values);
      result = { customer: { id: saved.id, branch: saved.branch, name: saved.name, phone: saved.phone, phone_key: saved.phone_key, address: saved.address,
        area: saved.area, delivery_notes: saved.delivery_notes, latitude: saved.latitude, longitude: saved.longitude, revision: saved.revision, actor_id: user.id },
      operation: { kind: 'customer.save', idempotency_key: command.key }, success: true };
      this.store.recordPageCommand(command.key, 'customer.save', 'C:' + saved.id, saved.revision, user, command.branch, { hash, result });
    });
    this.kick();
    return json({ ...result, replayed: false });
  }

  // ---- POST address-suggestions: the branch's saved addresses with a location (PhoneMapProvider::savedSuggestions) ----
  addressSuggestions({ user: who, body }) {
    const user = this.user({ user: who });
    const branch = String((body && body.branch) || '');
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    this.takeaway.branch(user, branch);
    const query = text(body.query, 240).replace(/\s+/gu, ' ');
    if (query.length < 2) throw new Reply(422, 'اكتب حرفين على الأقل للبحث في العناوين المحفوظة.');
    const wanted = query.toLocaleLowerCase();
    const items = [];
    const seen = new Set();
    for (const row of this.store.directoryAddresses(branch)) {
      const address = String(row.address || '').replace(/\s+/gu, ' ').trim();
      const area = String(row.area || '').replace(/\s+/gu, ' ').trim();
      if (!address || !Number.isFinite(row.latitude) || !Number.isFinite(row.longitude) || (row.latitude === 0 && row.longitude === 0)) continue;
      if (!address.toLocaleLowerCase().includes(wanted) && !area.toLocaleLowerCase().includes(wanted)) continue;
      const label = [...new Set([address, area].filter(Boolean))].join('، ');
      const id = crypto.createHash('sha256').update(JSON.stringify([label, [row.longitude, row.latitude]])).digest('hex');
      if (seen.has(id)) continue;
      seen.add(id);
      items.push({ id, label: label.slice(0, 400), latitude: row.latitude, longitude: row.longitude });
      if (items.length === 6) break;
    }
    return json({ success: true, provider: 'saved', items });
  }

  /** PhoneDelivery::quote: the delivery fee to these coordinates from the branch's pin, with its tariff. */
  delivery(user, values) {
    const branch = String(values.branch || '');
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    if (given(values.delivery_origin_branch)) throw new Reply(422, ORIGIN_NEEDS_INTERNET);
    this.takeaway.branch(user, branch);
    const latitude = coordinate(values.latitude, 90);
    const longitude = coordinate(values.longitude, 180);
    if (latitude === null || longitude === null) throw new Reply(422, 'أدخل إحداثيات صحيحة.');
    if (!yes(values.location_confirmed)) throw new Reply(422, 'أكد موقع العميل.');
    const settings = this.settings(branch);
    if (!settings) throw new Reply(503, 'لم يتم تنزيل إعدادات التوصيل لهذا الفرع على الجهاز بعد. اتصل بالإنترنت مرة واحدة للمزامنة.');
    if (!settings.ready) throw new Reply(422, NOT_READY);
    const lat = Math.round(latitude * 1e7) / 1e7;
    const lng = Math.round(longitude * 1e7) / 1e7;
    if (lat === 0 && lng === 0) throw new Reply(422, 'أكد موقع العميل الصحيح.');
    const meters = distance(settings.latitude, settings.longitude, lat, lng);
    const data = { branch, branch_latitude: settings.latitude, branch_longitude: settings.longitude, latitude: lat, longitude: lng,
      distance_meters: Math.round(meters), distance_km: (meters / 1000).toFixed(3), km_price: settings.km_price, method: 'app_direct',
      ...tariff(meters, settings.pricing_rates || {}) };
    data.delivery_quote_hash = fingerprint(data);
    return data;
  }

  // ---- POST delivery-quote ----
  deliveryQuote({ user: who, body }) {
    const user = this.user({ user: who });
    return json({ success: true, delivery: this.delivery(user, body || {}) });
  }

  /** The order's delivery: where it goes and its fee (the branch tariff, or a fee the cashier set). */
  orderDelivery(user, body) {
    const location = this.delivery(user, body);
    if (String(body.delivery_quote_hash || '') !== location.delivery_quote_hash) throw new Reply(409, CHANGED_LOCATION);
    let fee = location.delivery_fee;
    let manual = false;
    if (yes(body.delivery_fee_manual)) {
      if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
      try { fee = money.decimal(money.minor(String(body.delivery_fee ?? ''))); } catch { throw invalid('delivery_fee', 'المبلغ بمنزلتين عشريتين.'); }
      if (money.minor(fee) > 100000000) throw invalid('delivery_fee', 'المبلغ خارج الحد المسموح.');
      manual = true;
    }
    return { location: manual ? { ...location, calculated_delivery_fee: location.delivery_fee, delivery_fee: fee, fee_mode: 'manual', fee_actor_id: user.id } : location,
      fee, manual };
  }

  // ---- POST quote: a delivery order's cart and delivery, priced with the device's menu ----
  quote(request) {
    const body = request.body || {};
    if (given(body.ticket_id) && !Object.prototype.hasOwnProperty.call(body, 'items')) return super.quote(request);
    const user = this.user({ user: request.user });
    const cart = this.takeaway.cart(body);
    const catalog = this.takeaway.branch(user, cart.branch);
    const { fee } = this.orderDelivery(user, body);
    return json(this.takeaway.price(user, catalog, cart, 'phone', money.minor(fee)).result);
  }

  // ---- POST tickets/save: a new delivery order on the device, or a change to one placed here ----
  save(request) {
    const user = this.user({ user: request.user });
    const body = request.body || {};
    const command = this.command(body);
    const ticketId = given(body.ticket_id) ? Number(body.ticket_id) : null;
    if (ticketId && !(ticketId > LOCAL_TICKET_BASE)) throw new Reply(422, 'تعديل طلب دليفري مفتوح على الداشبورد يحتاج اتصالًا بالإنترنت. تقدر تسلّمه للمندوب وتطبع حسابه وتحصّله.');
    const name = text(body.customer_name, 100);
    const address = text(body.address, 500);
    if (!name) throw invalid('customer_name', 'هذا الحقل مطلوب.');
    if (!address) throw invalid('address', 'هذا الحقل مطلوب.');
    const phone = rules.normalizePhone(body.customer_phone);
    if (!phone) throw invalid('customer_phone', 'اكتب رقم هاتف صحيح للعميل قبل إنهاء الفاتورة.');
    if (!yes(body.service_consent)) throw invalid('service_consent', 'يجب تأكيد موافقة العميل على المتابعة قبل إرسال الطلب للفرع.');
    const cart = this.takeaway.cart({ ...body, branch: command.branch });
    const hash = sha256(['phone', 'save', command, ticketId, cart, String(body.quote_hash || ''), name, phone, address, text(body.area, 150),
      text(body.delivery_notes, 500), text(body.notes, 500), body.latitude, body.longitude, String(body.delivery_quote_hash || ''), yes(body.delivery_fee_manual),
      String(body.delivery_fee ?? ''), given(body.delivery_company_id) ? Number(body.delivery_company_id) : null, yes(body.send_to_kitchen), yes(body.marketing_consent),
      given(body.expected_revision) ? Number(body.expected_revision) : null]);
    const replay = this.replay(user, command, hash);
    if (replay) return json(replay);
    const catalog = this.takeaway.branch(user, command.branch);
    if (!user.can_checkout) throw new Reply(403, 'الحساب غير مسموح له بالبيع.');
    if (!this.supports('ticket_open')) throw new Reply(422, 'طلب دليفري جديد من غير نت يحتاج اتصالًا بالإنترنت مرة واحدة لتحديث بيانات الداشبورد على الجهاز.');
    const { location, fee, manual } = this.orderDelivery(user, { ...body, branch: command.branch });
    const companyId = given(body.delivery_company_id) ? Number(body.delivery_company_id) : null;
    const company = companyId ? (catalog.delivery_companies || []).find((item) => Number(item.id) === companyId) : null;
    if (companyId && !company) throw new Reply(422, 'اختر شركة توصيل مفعّلة في الفرع المحدد.');
    const { quote, result } = this.takeaway.price(user, catalog, cart, 'phone', money.minor(fee));
    if (result.quote_hash !== String(body.quote_hash || '')) throw new Reply(409, 'تغير السعر أو الإعداد. راجع الإجمالي.');
    const customerId = given(body.customer_id) ? Number(body.customer_id) : null;
    const customer = customerId ? this.store.db.prepare('SELECT id, revision FROM directory WHERE id = ? AND branch = ?').get(customerId, command.branch) : null;
    const fields = { customer_name: name, customer_phone: phone, address, area: text(body.area, 150), delivery_notes: text(body.delivery_notes, 500),
      notes: text(body.notes, 500), customer_id: customer ? customer.id : null, customer_revision: customer ? customer.revision : null, location, delivery_fee: fee,
      delivery_fee_manual: manual, delivery_company_id: company ? Number(company.id) : null,
      delivery_company: company ? { id: Number(company.id), name: company.name, phone: company.phone || '', contact_name: company.contact_name || '' } : null,
      items: cart.items, discount: money.decimal(cart.discount_cents), discount_reason: cart.discount_reason, quote: result, priced: quote,
      snapshot_id: catalog.snapshot_id, service_consent: true, marketing_consent: yes(body.marketing_consent) };
    const kitchen = yes(body.send_to_kitchen);
    let local;
    this.store.atomically(() => {
      if (!ticketId) {
        local = this.store.createPageTicket(user, { id: command.key, branch: command.branch, channel: 'phone', label: name,
          data: { source: 'dashboard', kind: 'local', revision: 1, status: 'new', ...fields, kitchen: null, courier: null, bill: null, cancel_reason: '' } });
      } else {
        const found = this.find(user, ticketId);
        if (found.branch !== command.branch) throw new Reply(404, 'الطلب غير موجود على هذا الجهاز.');
        this.mutable(found.local);
        if (Number(body.expected_revision) !== found.local.data.revision) throw new Reply(409, 'تغيرت الفاتورة.');
        if (!this.store.cancelTicketMove(found.local.id)) throw new Reply(409, 'جاري نقل هذا الطلب للداشبورد الآن. افتحه من جديد بعد ثوانٍ.');
        const data = { ...found.local.data, ...fields, revision: found.local.data.revision + 1 };
        delete data.move_op;
        local = this.store.updatePageTicket(found.local.id, data);
      }
      if (kitchen) {
        // Sent to the kitchen on save, as on the dashboard: the branch printer prints it.
        const seq = this.store.addKitchenPrint(local.id, local.data.revision, this.presentLocal(user, local, true), { job: true, branch: command.branch });
        local = this.store.updatePageTicket(local.id, { ...local.data, kitchen: seq });
      }
      this.store.recordPageCommand(command.key, 'save', local.id, local.data.revision, user, command.branch, { hash });
    });
    this.kick();
    return json(this.result(user, this.store.pageCommand(command.key), false));
  }

  // ---- The branch printer (branch-print-receiver.js): kitchen tickets of orders placed here (PosBranchPrinting) ----
  printJobs({ user: who, url }) {
    const user = this.user({ user: who });
    const branch = url.searchParams.get('branch') || '';
    if (!BRANCH.test(branch)) throw invalid('branch', 'اختر الفرع.');
    if (!user.branches.includes(branch)) throw new Reply(403, 'الطباعة التلقائية من حساب الفرع فقط.');
    const jobs = this.store.kitchenJobs(branch);
    const ticketId = (job) => {
      const local = this.store.ticket(job.ticket_ref);
      return local && local.seq ? LOCAL_TICKET_BASE + local.seq : null;
    };
    const pending = jobs.filter((job) => job.job_status === 'pending').slice(0, 10).map((job) => ({ id: LOCAL_KITCHEN_BASE + job.seq, ticket_id: ticketId(job) }))
      .filter((job) => job.ticket_id);
    const latest = jobs.reduce((max, job) => Math.max(max, ticketId(job) || 0), 0);
    return json({ success: true, branch, jobs: pending, attention: jobs.filter((job) => job.job_status === 'failed').length, latest_ticket_id: latest,
      incoming_ticket_ids: [] });
  }

  printClaim({ user: who, body }) {
    const user = this.user({ user: who });
    const branch = String((body && body.branch) || '');
    const id = Number(body && body.job_id);
    if (!BRANCH.test(branch) || !user.branches.includes(branch)) throw new Reply(403, 'الطباعة التلقائية من حساب الفرع فقط.');
    if (!UUID.test(String(body.claim_token || ''))) throw invalid('claim_token', 'رقم العملية غير صالح.');
    if (!(id > LOCAL_KITCHEN_BASE) || !this.store.claimKitchenJob(id - LOCAL_KITCHEN_BASE, body.claim_token)) {
      throw new Reply(409, 'أمر الطباعة التقطه جهاز آخر أو يحتاج مراجعة.');
    }
    const print = this.store.kitchenPrint(id - LOCAL_KITCHEN_BASE);
    const local = this.store.ticket(print.ticket_ref);
    return json({ success: true, job_id: id, ticket_id: local ? LOCAL_TICKET_BASE + local.seq : null, claim_token: body.claim_token,
      print_url: this.url(`/kitchen/${id}/print`) });
  }

  printComplete({ user: who, body }) {
    const user = this.user({ user: who });
    const branch = String((body && body.branch) || '');
    const id = Number(body && body.job_id);
    if (!BRANCH.test(branch) || !user.branches.includes(branch)) throw new Reply(403, 'الطباعة التلقائية من حساب الفرع فقط.');
    if (!['invoked', 'failed'].includes(body.result)) throw invalid('result', 'نتيجة الطباعة غير معروفة.');
    if (!(id > LOCAL_KITCHEN_BASE) || !this.store.completeKitchenJob(id - LOCAL_KITCHEN_BASE, String(body.claim_token || ''), body.result)) {
      throw new Reply(409, 'أمر الطباعة يحتاج مراجعة.');
    }
    return json({ success: true, status: body.result });
  }
}

module.exports = { DeliveryOffline, distance, tariff, fingerprint, phoneKey, phoneVariants };
