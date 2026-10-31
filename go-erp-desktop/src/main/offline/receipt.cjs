'use strict';
// The dashboard's receipt page (resources/views/admin/takeaway/receipt.blade.php) for invoices saved on this device
// while offline: the same layout, texts, style and print sizes. test/receipt-fixtures.php renders the dashboard's own
// page for sample invoices and the tests compare both.
const fs = require('node:fs');
const path = require('node:path');
const labels = require('./labels.json');

const STYLE = fs.readFileSync(path.join(__dirname, 'receipt.css'), 'utf8');
const CAIRO = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

/** Blade's {{ }}: htmlspecialchars with ENT_QUOTES. */
function e(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
}

const t = (key) => {
  const [file, name] = key.split('.');
  return (labels[file] && labels[file][name]) || key;
};

function money(value) {
  const parts = /^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/.exec(String(value ?? ''));
  if (!parts) return '—';
  return parts[1] + parts[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (parts[3] || '').padEnd(2, '0');
}

/** Carbon::parse(...)->timezone('Africa/Cairo')->format('d/m/Y H:i') */
function cairoTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const parts = Object.fromEntries(CAIRO.formatToParts(date).map((part) => [part.type, part.value]));
  return `${parts.day}/${parts.month}/${parts.year} ${parts.hour}:${parts.minute}`;
}

const positive = (value) => Number.parseFloat(String(value ?? '0')) > 0;
const filled = (value) => value !== undefined && value !== null && value !== '' && value !== false && value !== '0' && value !== 0
  && !(Array.isArray(value) && value.length === 0);

/** admin.pos_service.context: the invoice's dine-in or delivery details. */
function contextRows(channel, context) {
  let html = `<div><dt>${e(t('pos_service.channel'))}</dt><dd>${e(t('pos_service.' + (channel || 'takeaway')))}</dd></div>`;
  if (channel === 'dine') {
    if (filled(context.customer_name)) html += `<div><dt>${e(t('pos_service.customer'))}</dt><dd>${e(context.customer_name)}</dd></div>`;
    if (context.table && filled(context.table.name)) html += `<div><dt>${e(t('pos_service.table'))}</dt><dd>${e(context.table.name)}</dd></div>`;
    if (filled(context.waiter_name)) html += `<div><dt>${e(t('pos_service.waiter'))}</dt><dd>${e(context.waiter_name)}</dd></div>`;
    if (filled(context.guest_count)) html += `<div><dt>${e(t('pos_service.guests'))}</dt><dd><bdi>${e(context.guest_count)}</bdi></dd></div>`;
  } else if (channel === 'phone') {
    if (filled(context.customer_name)) html += `<div><dt>${e(t('pos_service.customer'))}</dt><dd>${e(context.customer_name)}</dd></div>`;
    if (filled(context.customer_phone)) html += `<div><dt>${e(t('pos_service.phone_number'))}</dt><dd><bdi>${e(context.customer_phone)}</bdi></dd></div>`;
    if (filled(context.area)) html += `<div><dt>${e(t('pos_service.area'))}</dt><dd>${e(context.area)}</dd></div>`;
    if (filled(context.address)) html += `<div class="full-width"><dt>${e(t('pos_service.address'))}</dt><dd>${e(context.address)}</dd></div>`;
    if (filled(context.delivery_notes)) html += `<div class="full-width"><dt>${e(t('pos_service.delivery_notes'))}</dt><dd>${e(context.delivery_notes)}</dd></div>`;
  }
  if (filled(context.delivery_company)) {
    html += `<div><dt>شركة التوصيل</dt><dd>${e(context.delivery_company.name)} · <bdi>${e(context.delivery_company.phone)}</bdi></dd></div>`;
  }
  return html;
}

function summaryRow(label, value, extraClass = '') {
  return `<div class="summary-row${extraClass}"><span>${label}</span><span class="summary-value"><bdi>${e(money(value))}</bdi><small class="currency">${e(t('takeaway.currency'))}</small></span></div>`;
}

/**
 * receipt: the dashboard's receipt array (TakeawayService::presentReceipt). origin: the dashboard's address, for the
 * logo. preview: the details view; dashboardPrint: printed from the dashboard's hidden frame (no automatic print).
 */
function renderReceipt(receipt, { origin, preview = false, dashboardPrint = false }) {
  const channel = receipt.channel || 'takeaway';
  const context = receipt.context && typeof receipt.context === 'object' && !Array.isArray(receipt.context) ? receipt.context : {};
  const unpaid = filled(receipt.unpaid);
  const cancelled = (receipt.status || '') === 'cancelled';
  const channelTitle = channel === 'dine' ? t('dining.title') : (channel === 'phone' ? t('phone_orders.title') : t('takeaway.title'));
  const paymentLabels = { cash: t('takeaway.cash'), card: t('takeaway.card'), mobile_wallet: t('takeaway.wallet'), other: t('takeaway.other'),
    mixed: t('pos_service.mixed'), unpaid: t('pos_service.unpaid') };
  const paymentLabel = paymentLabels[receipt.payment_method] ?? receipt.payment_method;
  const branch = receipt.branch || {};
  const currency = e(t('takeaway.currency'));
  let html = '<!doctype html>\n';
  html += `<html lang="ar" dir="rtl" ${preview ? 'data-dashboard-invoice-details="1"' : `data-dashboard-receipt="${e(receipt.print_marker ?? 'takeaway')}"`}>\n`;
  html += '<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n';
  html += `<title>${e(t('takeaway.current_invoice'))} ${e(receipt.number)}</title>\n<style>\n${STYLE}</style>\n</head>\n<body>\n<main class="receipt">\n`;
  if (preview) html += `<h2 class="preview-title">${e(t('pos_service.details'))}</h2>`;
  html += '<header class="heading">\n';
  html += `<img class="logo" src="${e(origin + '/dashboard/branding/fasakhansta-logo-transparent.png')}" alt="فسخانستا" width="1254" height="1254">\n`;
  html += `<h2>${e(branch.name)}</h2>\n`;
  if (filled(branch.address)) html += `<p class="branch-contact">${e(branch.address)}</p>`;
  if (filled(branch.phone)) html += `<p class="branch-contact"><bdi class="number">${e(branch.phone)}</bdi></p>`;
  html += `<div class="receipt-channel">${e(t('pos_service.' + channel))}</div>\n`;
  html += `<div class="receipt-identity">\n<h1>${e(unpaid ? t('pos_service.unpaid_bill') : t('takeaway.invoice_number'))}</h1>\n`;
  html += `<bdi class="receipt-number">${e(receipt.number)}</bdi>\n</div>\n`;
  if (cancelled) html += `<p class="receipt-state">${e(t('pos_service.cancelled'))}</p>`;
  html += '</header>\n<dl class="information">\n';
  html += `<div><dt>${e(t('takeaway.date'))}</dt><dd><bdi>${e(cairoTime(receipt.created_at))}</bdi></dd></div>\n`;
  html += `<div><dt>${e(t('takeaway.cashier'))}</dt><dd>${e(receipt.cashier && receipt.cashier.name)}</dd></div>\n`;
  if (channel !== 'takeaway') {
    html += contextRows(channel, context);
  } else {
    if (filled(context.customer_name)) html += `<div><dt>${e(t('pos_service.customer'))}</dt><dd>${e(context.customer_name)}</dd></div>`;
    if (filled(context.customer_phone)) html += `<div><dt>${e(t('pos_service.phone_number'))}</dt><dd><bdi>${e(context.customer_phone)}</bdi></dd></div>`;
  }
  html += `<div><dt>${e(t('takeaway.payment_method'))}</dt><dd>${e(paymentLabel)}</dd></div>\n`;
  if (filled(receipt.payment_reference)) html += `<div class="full-width"><dt>${e(t('takeaway.payment_reference'))}</dt><dd>${e(receipt.payment_reference)}</dd></div>`;
  html += '</dl>\n<table>\n<colgroup><col class="item-col"><col class="quantity-col"><col class="price-col"><col class="amount-col"></colgroup>\n';
  html += `<thead><tr><th scope="col">${e(t('takeaway.item'))}</th><th scope="col" class="item-quantity">${e(t('takeaway.quantity_short'))}</th>`
    + `<th scope="col" class="item-price">${e(t('takeaway.price'))}</th><th scope="col" class="item-total">${e(t('takeaway.line_total'))}</th></tr></thead>\n<tbody>\n`;
  for (const item of receipt.items || []) {
    const quantity = item.quantity_mode === 'piece' ? String(item.quantity).split('.')[0] : item.quantity;
    html += '<tr>\n';
    html += `<td class="item-name">${e(item.name)}${filled(item.option_label) ? `<span class="item-option">${e(item.option_label)}</span>` : ''}</td>\n`;
    html += `<td class="item-quantity"><bdi class="number">${e(quantity)}</bdi><span class="item-unit">${e(t('takeaway.' + (item.quantity_mode === 'weight' ? 'kg' : 'piece')))}</span></td>\n`;
    html += `<td class="item-price"><bdi class="number">${e(money(item.unit_price))}</bdi></td>\n`;
    html += `<td class="item-total"><bdi class="number">${e(money(item.total))}</bdi></td>\n`;
    html += '</tr>\n';
  }
  html += '</tbody>\n</table>\n<div class="summary">\n';
  html += summaryRow(e(t('takeaway.subtotal')), receipt.subtotal) + '\n';
  if (positive(receipt.discount)) html += summaryRow(e(t('takeaway.discount')), receipt.discount);
  html += summaryRow(`${e(t('takeaway.tax'))} (<bdi>${e(receipt.tax_rate)}%</bdi>)`, receipt.tax) + '\n';
  if (positive(receipt.service)) {
    const rate = receipt.service_rate !== undefined && receipt.service_rate !== null ? ` (<bdi>${e(receipt.service_rate)}%</bdi>)` : '';
    html += summaryRow(e(t('takeaway.service')) + rate, receipt.service);
  }
  if (positive(receipt.delivery ?? '0')) html += summaryRow(e(t('pos_service.delivery')), receipt.delivery);
  html += summaryRow(e(t('takeaway.total')), receipt.total, ' grand-total') + '\n';
  if (receipt.payment_method === 'mixed') {
    for (const part of receipt.payment_breakdown || []) {
      const name = { cash: t('takeaway.cash'), card: t('takeaway.card'), mobile_wallet: t('takeaway.wallet') }[part.method] ?? part.method;
      html += summaryRow(e(name), part.amount);
      if (filled(part.reference)) html += `<div class="notes">${e(t('takeaway.payment_reference'))}: ${e(part.reference)}</div>`;
    }
  }
  if (['cash', 'mixed'].includes(receipt.payment_method)) {
    html += summaryRow(e(t('takeaway.cash_paid')), receipt.cash_received, ' payment-total') + '\n';
    html += summaryRow(e(t('takeaway.change')), receipt.change, ' change-total') + '\n';
  }
  html += '</div>\n';
  if (filled(receipt.discount_reason)) html += `<div class="notes"><strong>${e(t('takeaway.discount_reason'))}</strong>${e(receipt.discount_reason)}</div>`;
  if (filled(receipt.notes)) html += `<div class="notes"><strong>${e(t('takeaway.order_notes'))}</strong>${e(receipt.notes)}</div>`;
  html += '\n<footer class="footer">\n';
  html += `<strong>${e(t('takeaway.receipt_thanks'))}</strong>\n<p>${e(t('takeaway.receipt_welcome'))}</p>\n`;
  html += `<p>${e(channelTitle)}${unpaid ? ` · ${e(t('pos_service.unpaid'))}` : ''}</p>\n</footer>\n`;
  if (!preview && (receipt.can_reprint ?? true)) {
    html += `<div class="controls"><button type="button" onclick="window.print()">${e(t('takeaway.print_invoice'))}</button></div>`;
  }
  html += '\n</main>\n';
  if (!preview && !dashboardPrint) html += "<script>window.addEventListener('load',function(){window.print();});</script>";
  html += '\n</body>\n</html>\n';
  return html;
}

module.exports = { renderReceipt, money, cairoTime };
