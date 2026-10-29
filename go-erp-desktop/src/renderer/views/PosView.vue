<template>
  <div class="pos">
    <section class="catalog">
      <div class="catalog-tools">
        <input ref="searchInput" v-model="search" class="search" placeholder="ابحث عن صنف… (F2)">
        <label class="quantity-box" title="أدخل الوزن بالكيلو أو عدد القطع ثم اختر الصنف">
          الوزن / العدد
          <input v-model.trim="pendingQuantity" inputmode="decimal" placeholder="مثال: 1.250 أو 3" @keydown.enter.prevent>
        </label>
      </div>
      <div class="categories">
        <button :class="{ active: category === null }" @click="category = null">الكل</button>
        <button v-for="item in categories" :key="item.id" :class="{ active: category === item.id }" @click="category = item.id">{{ item.name }}</button>
      </div>
      <div v-if="loadError" class="error block">{{ loadError }}</div>
      <div class="products">
        <button v-for="product in visibleProducts" :key="product.id" class="product" @click="choose(product)">
          <img v-if="product.image" :src="product.image" alt="" loading="lazy" @error="$event.target.remove()">
          <span class="name">{{ product.name }}</span>
          <span class="price">{{ money(product.variants[0].unit_price_cents) }}<small v-if="product.variants.length > 1"> +خيارات</small></span>
        </button>
        <p v-if="catalog && !visibleProducts.length" class="muted">لا توجد أصناف مطابقة.</p>
      </div>
    </section>

    <section class="invoice">
      <div class="open-tickets">
        <button v-for="item in openTickets" :key="item.id" :class="{ active: item.id === ticket.id }" @click="switchTo(item)">
          {{ ticketLabel(item) }}
        </button>
        <button class="new" @click="newTicket">+ فاتورة جديدة</button>
      </div>

      <div class="channels">
        <button v-for="(label, key) in CHANNELS" :key="key" :class="{ active: ticket.channel === key }" @click="setChannel(key)">{{ label }}</button>
      </div>

      <div class="invoice-body">
      <div class="lines">
        <p v-if="!ticket.data.items.length" class="muted center">اختر الأصناف لإضافتها إلى الفاتورة</p>
        <div v-for="(item, index) in ticket.data.items" :key="index" class="line">
          <div class="line-name">{{ lineName(item) }}</div>
          <input class="line-qty" :value="item.quantity" @change="setQuantity(index, $event.target.value)" :title="item.quantity_mode === 'weight' ? 'الوزن بالكيلو' : 'العدد'">
          <span class="line-unit">{{ unitLabel(item) }}</span>
          <span class="line-total">{{ quote && quote.items[index] ? money(quote.items[index].total_cents) : '' }}</span>
          <button class="icon" title="حذف الصنف" @click="removeLine(index)">✕</button>
        </div>
      </div>

      <div class="customer">
        <label>هاتف العميل<input v-model.trim="ticket.data.customer_phone" inputmode="tel" @input="changed" @change="lookupCustomer" placeholder="01xxxxxxxxx"></label>
        <p v-if="known" class="known wide">عميل سابق: {{ known.orders }} طلب بإجمالي {{ money(known.total_cents) }} جنيه</p>
        <label>اسم العميل<input v-model.trim="ticket.data.customer_name" @input="changed" :placeholder="ticket.channel === 'phone' ? 'مطلوب' : 'اختياري'"></label>
        <label v-if="ticket.channel === 'dine'">رقم الطاولة<input v-model.trim="ticket.data.table_name" @input="changed"></label>
        <template v-if="ticket.channel === 'phone'">
          <label class="wide">العنوان<input v-model.trim="ticket.data.address" @input="changed"></label>
          <label>رسوم التوصيل<input v-model.trim="ticket.data.delivery" inputmode="decimal" @input="changed" placeholder="0.00"></label>
        </template>
        <label class="wide">ملاحظة للطلب<input v-model="ticket.data.notes" @input="changed" maxlength="500"></label>
        <template v-if="session.user.can_manage">
          <label>خصم<input v-model.trim="ticket.data.discount" inputmode="decimal" @input="changed" placeholder="0.00"></label>
          <label>سبب الخصم<input v-model.trim="ticket.data.discount_reason" @input="changed"></label>
        </template>
        <label class="check wide"><input type="checkbox" v-model="ticket.data.service_consent" @change="changed"> العميل وافق على رسالة واتساب لمتابعة رأيه بعد الشراء</label>
        <label class="check wide"><input type="checkbox" v-model="ticket.data.marketing_consent" @change="changed"> العميل وافق على استقبال العروض عبر واتساب</label>
      </div>
      </div>

      <div class="invoice-foot">

      <div class="totals" v-if="quote">
        <div><span>الإجمالي قبل الخصم</span><b>{{ money(quote.subtotal_cents) }}</b></div>
        <div v-if="quote.discount_cents"><span>الخصم</span><b>-{{ money(quote.discount_cents) }}</b></div>
        <div v-if="quote.service_cents"><span>الخدمة</span><b>{{ money(quote.service_cents) }}</b></div>
        <div v-if="quote.delivery_cents"><span>التوصيل</span><b>{{ money(quote.delivery_cents) }}</b></div>
        <div v-if="quote.tax_cents"><span>الضريبة</span><b>{{ money(quote.tax_cents) }}</b></div>
        <div class="grand"><span>الإجمالي</span><b>{{ money(quote.total_cents) }} جنيه</b></div>
      </div>
      <p v-if="quoteError" class="error">{{ quoteError }}</p>

      <div class="pay">
        <label>المبلغ المستلم<input v-model.trim="cash" inputmode="decimal" placeholder="0.00" @keydown.enter.prevent="pay"></label>
        <div class="change" v-if="changeCents !== null">الباقي: <b>{{ money(changeCents) }}</b></div>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="notice" class="notice">{{ notice }}</p>
      <div class="actions">
        <button class="primary big" :disabled="busy || !quote" @click="pay">إنهاء البيع وطباعة الفاتورة <small>(F9)</small></button>
        <button :disabled="busy || !ticket.data.items.length" @click="kitchen">طباعة للمطبخ</button>
        <button class="danger-outline" :disabled="busy || !ticket.id" @click="discard">حذف الفاتورة</button>
      </div>
      </div>
    </section>

    <div v-if="variantProduct" class="modal" @click.self="variantProduct = null">
      <div class="card">
        <h2>{{ variantProduct.name }}</h2>
        <div class="variant-list">
          <button v-for="variant in variantProduct.variants" :key="variant.option_id" @click="pickVariant(variant)">
            {{ variant.label }} <b>{{ money(variant.unit_price_cents) }}</b>
          </button>
        </div>
        <button class="ghost" @click="variantProduct = null">إلغاء</button>
      </div>
    </div>

  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { call, money, CHANNELS } from '../api.js';

defineProps({ session: Object, branch: Object });
const emit = defineEmits(['changed']);

const catalog = ref(null);
const loadError = ref('');
const search = ref('');
const category = ref(null);
const pendingQuantity = ref('');
const openTickets = ref([]);
const ticket = reactive(emptyTicket());
const quote = ref(null);
const quoteError = ref('');
const cash = ref('');
const error = ref('');
const notice = ref('');
const busy = ref(false);
const searchInput = ref(null);
const known = ref(null);
const variantProduct = ref(null);
let saveTimer = null;
let quoteTimer = null;
let quoteSequence = 0;
let saving = Promise.resolve();

const categories = computed(() => (catalog.value ? catalog.value.categories || [] : []).filter((item) =>
  catalog.value.products.some((product) => product.category_id === item.id)));
const visibleProducts = computed(() => {
  if (!catalog.value) return [];
  const words = search.value.trim();
  return catalog.value.products.filter((product) => (category.value === null || product.category_id === category.value)
    && (!words || product.name.includes(words)));
});
const productById = computed(() => new Map((catalog.value ? catalog.value.products : []).map((product) => [product.id, product])));
const changeCents = computed(() => {
  if (!quote.value || !/^\d{1,10}(\.\d{1,2})?$/.test(cash.value)) return null;
  const [whole, fraction = ''] = cash.value.split('.');
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0')) - quote.value.total_cents;
});

function emptyTicket() {
  return { id: null, channel: 'takeaway', label: '', data: { items: [], customer_phone: '', customer_name: '', address: '', table_name: '',
    notes: '', discount: '', discount_reason: '', delivery: '', service_consent: false, marketing_consent: false } };
}

function ticketLabel(item) {
  const data = item.data || {};
  const name = item.channel === 'dine' && data.table_name ? 'طاولة ' + data.table_name : (data.customer_name || data.customer_phone || 'فاتورة');
  return CHANNELS[item.channel] + ' · ' + name;
}

function lineName(item) {
  const product = productById.value.get(item.product_id);
  if (!product) return 'صنف غير موجود في المينيو الحالي';
  const variant = product.variants.find((value) => (value.option_id || '') === (item.option_id || ''));
  return product.name + (variant && variant.label !== 'الأساسي' ? ' - ' + variant.label : '');
}

function choose(product) {
  error.value = '';
  notice.value = '';
  if (product.variants.length > 1) { variantProduct.value = product; return; }
  addLine(product, product.variants[0]);
}

function pickVariant(variant) {
  const product = variantProduct.value;
  variantProduct.value = null;
  addLine(product, variant);
}

/**
 * Same rule as the dashboard cashier: type the weight or count first (default 1), then pick the item.
 * Items without a stock unit are sold by decimal amount, like the dashboard.
 */
function addLine(product, variant) {
  const text = String(pendingQuantity.value || '1').trim().replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace('٫', '.');
  const mode = variant.quantity_mode === 'piece' ? 'piece' : 'weight';
  const amount = millis(text);
  if (amount === null || amount <= 0 || (mode === 'piece' && amount % 1000 !== 0)) {
    error.value = mode === 'piece' ? 'العدد يجب أن يكون رقمًا صحيحًا لهذا الصنف.' : 'أدخل الوزن أو الكمية بحد أقصى ٣ منازل عشرية.';
    return;
  }
  const optionId = variant.option_id || '';
  const existing = ticket.data.items.find((item) => item.product_id === product.id && item.option_id === optionId && item.quantity_mode === mode);
  const total = (existing ? millis(existing.quantity) : 0) + amount;
  if (total > 1000000) { error.value = 'كمية الصنف أكبر من الحد المسموح.'; return; }
  if (existing) existing.quantity = quantityText(total, mode);
  else if (ticket.data.items.length >= 100) { error.value = 'الفاتورة وصلت للحد الأقصى من الأصناف.'; return; }
  else ticket.data.items.push({ product_id: product.id, option_id: optionId, quantity_mode: mode, quantity: quantityText(amount, mode) });
  pendingQuantity.value = '';
  changed();
}

function millis(text) {
  const match = /^(\d{1,4})(?:\.(\d{1,3}))?$/.exec(String(text).trim());
  return match ? Number(match[1]) * 1000 + Number((match[2] || '').padEnd(3, '0')) : null;
}

function quantityText(value, mode) {
  return mode === 'piece' ? String(value / 1000) : Math.floor(value / 1000) + '.' + String(value % 1000).padStart(3, '0');
}

function unitLabel(item) {
  const product = productById.value.get(item.product_id);
  const variant = product && product.variants.find((value) => (value.option_id || '') === (item.option_id || ''));
  if (variant && variant.quantity_mode === 'select') return 'حسب الصنف';
  return item.quantity_mode === 'weight' ? 'كجم' : 'قطعة';
}

function setQuantity(index, value) {
  const text = String(value).trim();
  if (!text || /^0+(\.0+)?$/.test(text)) { removeLine(index); return; }
  ticket.data.items[index].quantity = text;
  changed();
}

function removeLine(index) {
  ticket.data.items.splice(index, 1);
  changed();
}

function setChannel(channel) {
  ticket.channel = channel;
  changed();
}

/** A returning customer's name and address are filled in from this device's earlier orders. */
async function lookupCustomer() {
  known.value = null;
  const phone = ticket.data.customer_phone;
  if (!phone) return;
  try {
    const customer = await call('customers:lookup', { phone });
    if (!customer || phone !== ticket.data.customer_phone) return;
    known.value = customer;
    let filled = false;
    if (!ticket.data.customer_name && customer.name) { ticket.data.customer_name = customer.name; filled = true; }
    if (ticket.channel === 'phone' && !ticket.data.address && customer.address) { ticket.data.address = customer.address; filled = true; }
    if (filled) changed();
  } catch { /* lookup is only a help */ }
}

// Cashier shortcuts: F2 search, F4 new invoice, F9 finish the sale.
function onKey(event) {
  if (variantProduct.value) { if (event.key === 'Escape') variantProduct.value = null; return; }
  if (event.key === 'F2') { event.preventDefault(); searchInput.value && searchInput.value.focus(); }
  else if (event.key === 'F4') { event.preventDefault(); newTicket(); }
  else if (event.key === 'F9') { event.preventDefault(); pay(); }
}

function changed() {
  error.value = '';
  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(refreshQuote, 120);
  if (busy.value) return;
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => save().catch((problem) => { error.value = problem.message; }), 500);
}

async function refreshQuote() {
  const sequence = ++quoteSequence;
  if (!ticket.data.items.length) { quote.value = null; quoteError.value = ''; return; }
  try {
    const result = await call('tickets:quote', { channel: ticket.channel, data: plain(ticket.data) });
    if (sequence !== quoteSequence) return;
    quote.value = result;
    quoteError.value = '';
  } catch (problem) {
    if (sequence !== quoteSequence) return;
    quote.value = null;
    quoteError.value = problem.message;
  }
}

/**
 * Open invoices live in the device database, so they survive a restart or a power cut.
 * Saves run one after another, so a new invoice is never created twice.
 */
function save() {
  clearTimeout(saveTimer);
  const run = async () => {
    if (!ticket.id && !ticket.data.items.length) return null;
    const saved = await call('tickets:save', { id: ticket.id, channel: ticket.channel, label: ticket.label, data: plain(ticket.data) });
    ticket.id = saved.id;
    await loadOpen();
    return saved;
  };
  saving = saving.then(run, run);
  return saving;
}

async function loadOpen() {
  openTickets.value = await call('tickets:open');
}

async function switchTo(item) {
  await save();
  known.value = null;
  Object.assign(ticket, { id: item.id, channel: item.channel, label: item.label, data: { ...emptyTicket().data, ...item.data } });
  cash.value = '';
  await refreshQuote();
}

async function newTicket() {
  await save();
  known.value = null;
  Object.assign(ticket, emptyTicket());
  cash.value = '';
  quote.value = null;
  quoteError.value = '';
}

async function pay() {
  if (busy.value || !quote.value) return;
  error.value = '';
  notice.value = '';
  if (!cash.value) cash.value = Math.floor(quote.value.total_cents / 100) + '.' + String(quote.value.total_cents % 100).padStart(2, '0');
  busy.value = true;
  try {
    await save();
    const result = await call('tickets:pay', { id: ticket.id, cash_received: cash.value, data: plain(ticket.data) });
    notice.value = `تم حفظ الفاتورة ${result.ticket.local_number}. الباقي ${money(result.ticket.totals.change_cents)} جنيه.`
      + (result.printed && !result.printed.ok && result.printed.error ? ' ' + result.printed.error + ' يمكنك إعادة الطباعة من فواتير اليوم.' : '');
    Object.assign(ticket, emptyTicket());
    cash.value = '';
    quote.value = null;
    known.value = null;
    await loadOpen();
    emit('changed');
  } catch (problem) {
    error.value = problem.message;
  } finally {
    busy.value = false;
  }
}

async function kitchen() {
  error.value = '';
  try {
    await save();
    const result = await call('tickets:kitchen', { id: ticket.id });
    notice.value = result.ok ? 'تم إرسال الطلب لطابعة المطبخ.' : '';
    if (!result.ok && result.error) error.value = result.error;
  } catch (problem) {
    error.value = problem.message;
  }
}

async function discard() {
  if (!ticket.id || !confirm('حذف الفاتورة المفتوحة؟')) return;
  clearTimeout(saveTimer);
  await saving.catch(() => {});
  await call('tickets:delete', { id: ticket.id });
  Object.assign(ticket, emptyTicket());
  cash.value = '';
  quote.value = null;
  quoteError.value = '';
  await loadOpen();
}

function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

onUnmounted(() => window.removeEventListener('keydown', onKey));

onMounted(async () => {
  window.addEventListener('keydown', onKey);
  try {
    catalog.value = await call('catalog:get');
  } catch (problem) {
    loadError.value = problem.message;
  }
  await loadOpen();
  if (openTickets.value.length) await switchTo(openTickets.value[openTickets.value.length - 1]);
});
</script>
