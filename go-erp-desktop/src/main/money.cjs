'use strict';
// Same integer rules as the dashboard (App\Services\GoServices\Money and DesktopPosQuote):
// EGP piastres, basis points, quantities in thousandths, and no floating-point money.

const MAX_TOTAL = 100000000;
const MAX_MILLIS = 1000000;

function minor(value) {
  const text = String(value ?? '').trim();
  const match = /^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/.exec(text);
  if (!match) throw new Error('أدخل مبلغًا صحيحًا بحد أقصى منزلتين عشريتين.');
  const cents = Number(match[2]) * 100 + Number((match[3] || '').padEnd(2, '0'));
  return match[1] === '-' ? -cents : cents;
}

function decimal(cents) {
  const sign = cents < 0 ? '-' : '';
  const value = Math.abs(cents);
  return sign + Math.floor(value / 100) + '.' + String(value % 100).padStart(2, '0');
}

function commission(amount, bps) {
  if (!Number.isInteger(amount) || !Number.isInteger(bps) || amount < 0 || amount > MAX_TOTAL || bps < 0 || bps > 10000) {
    throw new Error('قيمة غير صالحة لحساب النسبة.');
  }
  return Math.floor((amount * bps + 5000) / 10000);
}

function millis(quantity) {
  const match = /^([0-9]{1,4})(?:\.([0-9]{1,3}))?$/.exec(String(quantity ?? '').trim());
  if (!match) throw new Error('أدخل الكمية أو الوزن بحد أقصى ٣ منازل عشرية.');
  return Number(match[1]) * 1000 + Number((match[2] || '').padEnd(3, '0'));
}

function quantityText(value) {
  return Math.floor(value / 1000) + '.' + String(value % 1000).padStart(3, '0');
}

function lineTotal(unitPriceCents, quantityMillis) {
  return Math.floor((unitPriceCents * quantityMillis + 500) / 1000);
}

/** Identical lines are merged exactly like the server's cart, so rounding matches to the piastre. */
function mergeItems(items) {
  const merged = new Map();
  for (const item of items) {
    const quantity = millis(item.quantity);
    if (quantity < 1 || quantity > MAX_MILLIS || (item.quantity_mode === 'piece' && quantity % 1000 !== 0)) {
      throw new Error('العدد يجب أن يكون صحيحًا، والوزن بحد أقصى ٣ منازل عشرية.');
    }
    const key = [item.product_id, item.option_id || '', item.quantity_mode].join('|');
    const previous = merged.get(key);
    const total = (previous ? previous.quantity_millis : 0) + quantity;
    if (total > MAX_MILLIS) throw new Error('كمية الصنف أكبر من الحد المسموح.');
    merged.set(key, { product_id: Number(item.product_id), option_id: item.option_id || '', quantity_mode: item.quantity_mode, quantity_millis: total });
  }
  return [...merged.values()];
}

function variantIndex(catalog) {
  const variants = new Map();
  for (const product of catalog.products || []) {
    for (const variant of product.variants || []) {
      variants.set(product.id + '|' + (variant.option_id || ''), { ...variant, name: product.name, product_id: product.id, unit: product.unit || '' });
    }
  }
  return variants;
}

/**
 * The invoice as the server will recompute it from the same menu version (DesktopPosQuote::build).
 * channel: takeaway | dine | phone. Discount and delivery are piastres.
 */
function quote(catalog, cart) {
  const channel = cart.channel || 'takeaway';
  if (!['takeaway', 'dine', 'phone'].includes(channel)) throw new Error('نوع الطلب غير معروف.');
  const delivery = channel === 'phone' ? Number(cart.delivery_cents || 0) : 0;
  if (!Number.isInteger(delivery) || delivery < 0 || delivery > MAX_TOTAL) throw new Error('رسوم التوصيل غير صحيحة.');
  const variants = variantIndex(catalog);
  const lines = [];
  let subtotal = 0;
  for (const item of mergeItems(cart.items || [])) {
    const variant = variants.get(item.product_id + '|' + item.option_id);
    if (!variant) throw new Error('الصنف غير موجود في المينيو الحالي على الجهاز.');
    if (!(variant.unit_price_cents > 0)) throw new Error('سعر الصنف غير محدد.');
    if (variant.quantity_mode !== 'select' && variant.quantity_mode !== item.quantity_mode) throw new Error('وحدة الصنف غير صحيحة.');
    const total = lineTotal(variant.unit_price_cents, item.quantity_millis);
    subtotal += total;
    lines.push({ product_id: item.product_id, name: variant.name, option_id: item.option_id, option_label: variant.label,
      unit: variant.unit, unit_mode: variant.quantity_mode, quantity_mode: item.quantity_mode, quantity_millis: item.quantity_millis,
      quantity: quantityText(item.quantity_millis), unit_price_cents: variant.unit_price_cents, total_cents: total });
  }
  const discount = Number(cart.discount_cents || 0);
  if (!lines.length) throw new Error('الفاتورة فارغة.');
  if (subtotal > MAX_TOTAL || !Number.isInteger(discount) || discount < 0 || discount > subtotal) throw new Error('الخصم أو إجمالي الفاتورة غير صالح.');
  const serviceBps = channel === 'dine' ? Number(catalog.service_bps || 0) : 0;
  const net = subtotal - discount;
  const service = commission(net, serviceBps);
  if (net + service + delivery > MAX_TOTAL) throw new Error('إجمالي الفاتورة أكبر من الحد المسموح.');
  const tax = commission(net + service + delivery, Number(catalog.tax_bps || 0));
  const total = net + service + delivery + tax;
  if (total > MAX_TOTAL) throw new Error('إجمالي الفاتورة أكبر من الحد المسموح.');
  return { items: lines, subtotal_cents: subtotal, discount_cents: discount, service_bps: serviceBps, service_cents: service,
    delivery_cents: delivery, tax_bps: Number(catalog.tax_bps || 0), tax_cents: tax, total_cents: total };
}

module.exports = { minor, decimal, commission, millis, quantityText, lineTotal, mergeItems, quote, MAX_TOTAL };
