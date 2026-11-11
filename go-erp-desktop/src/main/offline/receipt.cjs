'use strict';
// The dashboard's print pages for what this device saved while offline: the receipt (admin/printing/receipt.blade.php,
// shared by admin/takeaway/receipt), an order's bill before payment (admin/pos_service/ticket.blade.php), the kitchen ticket
// (admin/pos_service/kitchen.blade.php, the receipt's kitchen copy)
// and the courier's receipt for delivery orders collected together (admin/phone_orders/batch_receipt.blade.php),
// with the same layout, texts, style and print sizes. test/receipt-fixtures.php renders the dashboard's own pages for
// sample invoices and the tests compare both.
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

const filled = (value) => value !== undefined && value !== null && value !== '' && value !== false && value !== '0' && value !== 0
  && !(Array.isArray(value) && value.length === 0);

const compactMoney = (value) => money(value).replace(/0+$/, '').replace(/\.+$/, '');
const isset = (value) => value !== undefined && value !== null;
const numberOf = (value) => Number.parseFloat(String(value ?? '0')) || 0;

/** admin.printing.receipt_context: who the order is for and how it goes out. */
function contextRows(channel, context) {
  let html = '';
  if (filled(context.customer_name)) html += `<div><dt>${e(t('pos_service.customer'))}</dt><dd>${e(context.customer_name)}</dd></div>`;
  if (filled(context.customer_phone)) html += `<div><dt>${e(t('receipt.mobile_short'))}</dt><dd><bdi>${e(context.customer_phone)}</bdi></dd></div>`;
  if (channel === 'dine') {
    if (context.table && filled(context.table.name)) html += `<div><dt>${e(t('pos_service.table'))}</dt><dd>${e(context.table.name)}</dd></div>`;
    if (filled(context.waiter_name)) html += `<div><dt>${e(t('pos_service.waiter'))}</dt><dd>${e(context.waiter_name)}</dd></div>`;
    if (filled(context.guest_count)) html += `<div><dt>${e(t('pos_service.guests'))}</dt><dd><bdi>${e(context.guest_count)}</bdi></dd></div>`;
  }
  if (filled(context.area)) html += `<div><dt>${e(t('pos_service.area'))}</dt><dd>${e(context.area)}</dd></div>`;
  if (filled(context.address)) html += `<div><dt>${e(t('receipt.address_short'))}</dt><dd>${e(context.address)}</dd></div>`;
  if (filled(context.delivery_notes)) html += `<div><dt>${e(t('pos_service.delivery_notes'))}</dt><dd>${e(context.delivery_notes)}</dd></div>`;
  for (const [key, label] of [['delivery_company', 'receipt.delivery_company'], ['courier', 'receipt.courier']]) {
    const party = context[key];
    if (party && typeof party === 'object' && filled(party.name)) {
      html += `<div><dt>${e(t(label))}</dt><dd>${e(party.name)}${filled(party.phone) ? ` · <bdi>${e(party.phone)}</bdi>` : ''}</dd></div>`;
    }
  }
  return html;
}

/** admin.printing.receipt_items */
function itemRows(receipt, kitchenCopy, showUnitPrices) {
  let html = `<table class="ft-items ${!kitchenCopy && !showUnitPrices ? 'ft-no-price ' : ''}">`;
  html += '<colgroup><col class="ft-name-col"><col class="ft-qty-col">';
  if (!kitchenCopy) html += `${showUnitPrices ? '<col class="ft-price-col">' : ''}<col class="ft-amount-col">`;
  html += `</colgroup><thead><tr><th scope="col">${e(t('takeaway.item'))}</th><th scope="col" class="ft-qty">${e(t('takeaway.quantity_short'))}</th>`;
  if (!kitchenCopy) {
    if (showUnitPrices) html += `<th scope="col" class="ft-price">${e(t('takeaway.price'))}</th>`;
    html += `<th scope="col" class="ft-amount">${e(t('receipt.line_total_short'))}</th>`;
  }
  html += '</tr></thead><tbody>';
  for (const item of receipt.items) {
    let quantity = item.display_quantity ?? (item.quantity_mode === 'piece' ? String(item.quantity).split('.')[0] : item.quantity);
    if (String(quantity).includes('.')) quantity = String(quantity).replace(/0+$/, '').replace(/\.+$/, '');
    const unit = item.quantity_unit ?? (isset(item.quantity_mode) ? t('takeaway.' + (item.quantity_mode === 'weight' ? 'kg' : 'piece')) : '');
    html += `<tr><td><span class="ft-item-label"><strong class="ft-item-name">${e(item.name)}</strong>`;
    if (filled(item.option_label)) html += `<span class="ft-option"> · ${e(item.option_label)}</span>`;
    html += `</span></td><td class="ft-qty"><span class="ft-cell-value"><bdi>${e(quantity)}</bdi>${unit !== '' ? ` <span class="ft-unit">${e(unit)}</span>` : ''}</span></td>`;
    if (!kitchenCopy) {
      if (showUnitPrices) html += `<td class="ft-price"><span class="ft-cell-value"><bdi>${e(compactMoney(item.unit_price ?? null))}</bdi></span></td>`;
      html += `<td class="ft-amount"><span class="ft-cell-value"><bdi>${e(compactMoney(item.total ?? item.line_total ?? null))}</bdi></span></td>`;
    }
    html += '</tr>';
  }
  return html + '</tbody></table>';
}

const sumRow = (label, amount) => `<div class="ft-sum"><span>${label}</span><span class="ft-value"><bdi>${e(money(amount))}</bdi></span></div>`;

/**
 * admin.printing.receipt (the receipt, an order's bill and the kitchen ticket). receipt: the dashboard's receipt array
 * (TakeawayService::presentReceipt). origin: the dashboard's address, for the logo. preview: the details view;
 * dashboardPrint: printed from the dashboard's hidden frame (no automatic print); kitchenCopy: the kitchen's copy.
 */
function renderReceipt(receipt, { origin, preview = false, dashboardPrint = false, kitchenCopy = false }) {
  const channel = receipt.channel ?? 'takeaway';
  const context = receipt.context && typeof receipt.context === 'object' && !Array.isArray(receipt.context) ? receipt.context : {};
  const unpaid = filled(receipt.unpaid);
  const cancelled = (receipt.status ?? '') === 'cancelled';
  const showUnitPrices = receipt.show_unit_prices ?? true;
  const channelTitle = receipt.channel_label ?? (channel === 'phone' ? t('receipt.phone_short') : (channel === 'app' ? t('receipt.app') : t('pos_service.' + channel)));
  const method = receipt.payment_method ?? 'unpaid';
  const paymentLabels = { cash: t('takeaway.cash'), card: t('takeaway.card'), mobile_wallet: t('takeaway.wallet'), other: t('takeaway.other'),
    mixed: t('pos_service.mixed'), unpaid: t('pos_service.unpaid') };
  const paymentLabel = receipt.payment_label ?? paymentLabels[method] ?? method;
  const branch = receipt.branch || {};
  const number = String(receipt.number ?? '');
  let html = '<!doctype html>\n';
  html += `<html lang="ar" dir="rtl" ${preview ? 'data-dashboard-invoice-details="1"' : `data-dashboard-receipt="${e(receipt.print_marker ?? 'takeaway')}"`}>\n`;
  html += '<head>\n<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">\n';
  html += `<title>${e(kitchenCopy ? t('pos_service.kitchen_ticket') : t('takeaway.current_invoice'))} ${e(number)}</title>\n<style>\n${STYLE}</style>\n</head>\n<body>\n`;
  if (preview) html += `<h2 class="preview-title">${e(t('pos_service.details'))}</h2>`;
  html += `<main class="receipt ${kitchenCopy ? 'ft-kitchen ' : ''}" dir="rtl">\n<header class="ft-brand">\n`;
  html += `<img class="ft-logo" src="${e(receipt.logo_url ?? origin + '/dashboard/branding/fasakhansta-logo-transparent.png')}" alt="فسخانستا" width="1254" height="1254">\n`;
  html += `<p class="ft-branch">${e(branch.name)}</p>\n`;
  if (filled(branch.address)) html += `<p class="ft-contact-line">${e(branch.address)}</p>`;
  if (filled(branch.phone)) html += `<p class="ft-contact-line"><bdi>${e(branch.phone)}</bdi></p>`;
  html += '</header>\n<div class="ft-identity">\n';
  html += `<div class="ft-type">${kitchenCopy ? `${e(t('receipt.kitchen_short'))} · ` : ''}${e(channelTitle)}</div>\n`;
  html += `<bdi class="ft-number ${Buffer.byteLength(number) > 24 ? 'ft-number-long ' : ''}">${e(number)}</bdi>\n</div>\n`;
  html += `<div class="ft-date"><bdi>${e(cairoTime(receipt.created_at))}</bdi>`;
  if (kitchenCopy) html += `<span>${e(t('pos_service.revision'))} <bdi>${e(receipt.revision)}</bdi></span>`;
  else if (receipt.cashier && filled(receipt.cashier.name)) html += `<span>${e(t('takeaway.cashier'))}: ${e(receipt.cashier.name)}</span>`;
  html += '</div>\n';
  if (cancelled) html += `<p class="ft-state">${e(t('pos_service.cancelled'))}</p>`;
  if (unpaid) html += `<p class="ft-state">${e(t('pos_service.unpaid_bill'))}</p>`;
  if (filled(receipt.order_state)) html += `<p class="ft-state">${e(receipt.order_state)}</p>`;
  if (!kitchenCopy && filled(receipt.payment_failed)) html += `<p class="ft-state">${e(t('order_board.payment_failed'))}</p>`;
  html += `<dl class="ft-meta">${contextRows(channel, context)}</dl>\n`;
  if (filled(receipt.items)) html += itemRows(receipt, kitchenCopy, showUnitPrices);
  if (!kitchenCopy) {
    html += '<div class="ft-summary">';
    if (Object.prototype.hasOwnProperty.call(receipt, 'summary_lines')) {
      for (const line of receipt.summary_lines || []) html += sumRow(e(line.label), line.amount);
    } else {
      html += sumRow(e(t('takeaway.subtotal')), receipt.subtotal);
      if (numberOf(receipt.discount) > 0) html += sumRow(e(t('takeaway.discount')), receipt.discount);
      if (numberOf(receipt.tax) !== 0 || numberOf(receipt.tax_rate ?? 0) !== 0) {
        html += sumRow(`${e(t('takeaway.tax'))}${isset(receipt.tax_rate) ? ` (<bdi>${e(receipt.tax_rate)}%</bdi>)` : ''}`, receipt.tax);
      }
      if (numberOf(receipt.service) > 0) {
        html += sumRow(`${e(t('takeaway.service'))}${isset(receipt.service_rate) ? ` (<bdi>${e(receipt.service_rate)}%</bdi>)` : ''}`, receipt.service);
      }
      if (numberOf(receipt.delivery ?? 0) > 0) html += sumRow(e(t('pos_service.delivery')), receipt.delivery);
    }
    html += `<div class="ft-sum ft-grand"><span>${e(t('receipt.total_short'))}</span><span class="ft-value">`;
    html += isset(receipt.total) ? `<bdi>${e(money(receipt.total))}</bdi> <small class="ft-currency">${e(t('receipt.currency_short'))}</small>` : e(t('receipt.amount_pending'));
    html += `</span></div><div class="ft-pay">${e(t('takeaway.payment_method'))}: <strong>${e(paymentLabel)}</strong></div>`;
    if (filled(receipt.payment_paid)) html += `<p class="ft-state">${e(t('order_board.payment_paid'))}</p>`;
    if (method === 'mixed') {
      for (const part of receipt.payment_breakdown || []) {
        const name = { cash: t('takeaway.cash'), card: t('takeaway.card'), mobile_wallet: t('takeaway.wallet') }[part.method] ?? part.method;
        html += sumRow(e(name), part.amount);
        if (filled(part.reference)) html += `<p class="ft-note"><strong>${e(t('takeaway.payment_reference'))}:</strong> ${e(part.reference)}</p>`;
      }
    }
    if ((receipt.show_cash_tender ?? true) && ['cash', 'mixed'].includes(method)) {
      html += `<div class="ft-tender"><span>${e(t('receipt.paid_short'))} <bdi>${e(money(receipt.cash_received))}</bdi></span>`
        + `<strong>${e(t('takeaway.change'))} <bdi>${e(money(receipt.change))}</bdi></strong></div>`;
    }
    if (filled(receipt.payment_reference)) html += `<p class="ft-note"><strong>${e(t('takeaway.payment_reference'))}:</strong> ${e(receipt.payment_reference)}</p>`;
    html += '</div>';
    if (filled(receipt.discount_reason)) html += `<p class="ft-note"><strong>${e(t('takeaway.discount_reason'))}:</strong> ${e(receipt.discount_reason)}</p>`;
  }
  if (filled(receipt.notes)) html += `<p class="ft-note"><strong>${e(kitchenCopy ? t('receipt.preparation_notes') : t('takeaway.order_notes'))}:</strong> ${e(receipt.notes)}</p>`;
  html += '<footer class="ft-footer">';
  html += kitchenCopy ? e(t('pos_service.kitchen_copy')) : `<strong>${e(t('takeaway.receipt_thanks'))}</strong>${unpaid ? `<p>${e(t('pos_service.unpaid'))}</p>` : ''}`;
  html += '</footer>\n</main>\n';
  if (!preview && (receipt.can_reprint ?? true)) html += `<div class="controls"><button type="button" onclick="window.print()">${e(t('takeaway.print_invoice'))}</button></div>`;
  if (!preview && !dashboardPrint) html += "<script>window.addEventListener('load',function(){window.print();});</script>";
  html += '\n</body>\n</html>\n';
  return html;
}

/** admin.pos_service.ticket: an open dine-in or delivery order's bill, printed before payment (the receipt page, unpaid). */
function ticketBill(ticket) {
  return { ...ticket, number: ticket.number ?? `${String(ticket.channel).toUpperCase()}-${ticket.id}`,
    cashier: ticket.cashier ?? { name: ticket.waiter_name ?? '' }, payment_method: 'unpaid', payment_reference: '', cash_received: '0.00',
    change: '0.00', unpaid: true, print_marker: 'pos-ticket', context: ticket };
}

/**
 * admin.pos_service.kitchen: what the kitchen prepares, as the receipt's kitchen copy. kitchen: { id, ticket_id, revision,
 * created_at, ticket } where ticket is the order as it was sent.
 */
function renderKitchen(kitchen, { origin, dashboardPrint = false }) {
  const ticket = kitchen.ticket;
  const receipt = { ...ticket, number: ticket.number ?? ticket.id, created_at: kitchen.created_at, revision: kitchen.revision, context: ticket,
    print_marker: 'pos-kitchen' };
  return renderReceipt(receipt, { origin, preview: false, dashboardPrint, kitchenCopy: true });
}

const BATCH_STYLE = `
        @page{size:auto;margin:0}
        *{box-sizing:border-box}
        /* The same size and margins as the sale invoice, which prints right on the branches' 80 mm printers. */
        body{font:11px/1.4 Tahoma,Arial,sans-serif;color:#000;width:100%;max-width:80mm;margin:0 auto;padding:3mm 2mm;overflow-wrap:anywhere}
        header,footer{text-align:center}header{padding-bottom:9px;border-bottom:1px dashed #000}header img{width:28mm;height:28mm;object-fit:contain}
        h1{font-size:14px;margin:2px 0}p{margin:5px 0}header p{margin:3px 0}
        table{width:100%;table-layout:fixed;border-collapse:collapse;margin:10px 0}
        th,td{padding:6px 2px;border-bottom:1px solid #888;text-align:center;vertical-align:middle}
        th{font-size:10px;line-height:1.4;border-color:#000}td{font-size:10.5px}th:first-child{width:19%}
        th:nth-child(2){width:28%}th:nth-child(3){width:25%}th:last-child{width:28%}
        tr{break-inside:avoid}bdi{font-variant-numeric:tabular-nums}td bdi{white-space:nowrap}td:first-child bdi{white-space:normal;overflow-wrap:anywhere}
        .batch-summary{border-top:1px solid #000;padding-top:4px;margin:0}
        .batch-summary div{display:flex;justify-content:space-between;gap:6px;padding:3px 0;font-size:11px;break-inside:avoid}
        .batch-summary dt{flex:1}.batch-summary dd{margin:0;white-space:nowrap}
        .batch-delivery{font-weight:bold}.batch-grand-total{border-top:1px solid #000;margin-top:4px;padding-top:6px!important;font-size:16px!important;font-weight:bold}
        .batch-note{font-size:9.5px;line-height:1.5}footer{margin-top:13px;padding-top:9px;border-top:1px solid #000;font-size:9px}
    `;

/** Carbon's format('Y-m-d H:i') in Cairo time. */
function cairoDay(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const parts = Object.fromEntries(CAIRO.formatToParts(date).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
}

/**
 * admin.phone_orders.batch_receipt: the courier's receipt for delivery orders collected together, with each order's
 * delivery fee apart when the original receipts give it (otherwise «—» and the note, as on the dashboard).
 */
function renderBatch(batch, { origin }) {
  const dash = (value) => (value === undefined || value === null ? '—' : value);
  let html = '<!doctype html>\n<html lang="ar" dir="rtl" data-dashboard-receipt="phone-batch">\n<head>\n    <meta charset="utf-8">\n';
  html += `    <title>${e(t('phone_orders.batch_receipt'))} #${e(batch.id)}</title>\n    <style>${BATCH_STYLE}</style>\n</head>\n<body>\n<header>\n`;
  html += `    <img src="${e(origin + '/dashboard/branding/fasakhansta-logo-transparent.png')}" alt="فسخانستا">\n    <h1>${e(t('phone_orders.batch_receipt'))}</h1>\n`;
  html += `    <p>${e(batch.branch && batch.branch.name)}</p><bdi>#${e(batch.id)}</bdi>\n    <p><bdi>${e(cairoDay(batch.created_at))}</bdi></p>\n</header>\n`;
  html += `<p>${e(t('phone_orders.courier_company'))}: ${e(batch.company && batch.company.name)}</p>\n`;
  if (filled(batch.courier_name)) html += `<p>${e(t('phone_orders.courier_name'))}: ${e(batch.courier_name)}</p>`;
  html += `<p>${e(t('phone_orders.cashier'))}: ${e(batch.cashier && batch.cashier.name)}</p>\n<table>\n    <thead><tr>\n`;
  html += `        <th>${e(t('phone_orders.saved_number'))}</th>\n        <th>${e(t('phone_batch.orders_column'))}</th>\n`;
  html += `        <th>${e(t('phone_batch.delivery_column'))}</th>\n        <th>${e(t('phone_batch.total_column'))}</th>\n    </tr></thead>\n    <tbody>\n    `;
  for (const line of batch.items || []) {
    html += `        <tr>\n            <td><bdi>#${e(line.ticket_id)}</bdi></td>\n            <td><bdi>${e(dash(line.total_without_delivery))}</bdi></td>\n`;
    html += `            <td><bdi>${e(dash(line.delivery))}</bdi></td>\n            <td><bdi>${e(line.total)}</bdi></td>\n        </tr>\n    `;
  }
  html += '    </tbody>\n</table>\n<dl class="batch-summary">\n';
  html += `    <div><dt>${e(t('phone_batch.orders_total'))}</dt><dd><bdi>${e(dash(batch.orders_total))}</bdi> ${e(t('phone_orders.currency'))}</dd></div>\n`;
  html += `    <div class="batch-delivery"><dt>${e(t('phone_batch.delivery_total'))}</dt><dd><bdi>${e(dash(batch.delivery_total))}</bdi> ${e(t('phone_orders.currency'))}</dd></div>\n`;
  html += `    <div class="batch-grand-total"><dt>${e(t('phone_batch.grand_total'))}</dt><dd><bdi>${e(batch.total)}</bdi> ${e(t('phone_orders.currency'))}</dd></div>\n</dl>\n`;
  if (!batch.delivery_breakdown_complete) html += `<p class="batch-note">${e(t('phone_batch.breakdown_unavailable'))}</p>`;
  html += `<p>${e(t('phone_orders.payment_method'))}: ${e(t('phone_orders.' + batch.payment_method))}</p>\n`;
  if (batch.payment_method === 'cash') {
    html += `<p>${e(t('phone_orders.cash_received'))}: <bdi>${e(batch.cash_received)}</bdi></p><p>${e(t('phone_orders.change'))}: <bdi>${e(batch.change)}</bdi></p>`;
  }
  if (filled(batch.payment_reference)) html += `<p>${e(t('phone_orders.payment_reference'))}: <bdi>${e(batch.payment_reference)}</bdi></p>`;
  html += `<footer>${e(t('phone_orders.batch_paid_note'))}</footer>\n</body>\n</html>\n`;
  return html;
}

module.exports = { renderReceipt, renderKitchen, renderBatch, ticketBill, money, cairoTime };
