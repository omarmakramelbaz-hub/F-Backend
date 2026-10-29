'use strict';
// Receipts and kitchen tickets are plain HTML printed silently on the chosen Windows printer.
const money = require('./money.cjs');

const CHANNEL_LABELS = { takeaway: 'تيك أواي', dine: 'صالة', phone: 'دليفري' };

function escape(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
}

function quantityLabel(line) {
  if (line.quantity_mode === 'piece') return String(Math.round(line.quantity_millis / 1000));
  // Items without a stock unit (like the dashboard's "automatic unit") print the amount only.
  if (line.unit_mode === 'select') return money.quantityText(line.quantity_millis).replace(/\.?0+$/, '');
  return money.quantityText(line.quantity_millis) + ' كجم';
}

function when(iso) {
  return new Intl.DateTimeFormat('ar-EG-u-nu-latn', { timeZone: 'Africa/Cairo', dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}

function page(width, body) {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
    @page { size: ${width}mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 2mm 3mm; width: ${width}mm; font-family: Tahoma, "Segoe UI", Arial, sans-serif; font-size: 12px; color: #000; }
    h1 { font-size: 16px; margin: 0 0 2px; text-align: center; }
    .center { text-align: center; } .small { font-size: 11px; } .big { font-size: 15px; font-weight: bold; }
    hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
    table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
    td.n { text-align: left; white-space: nowrap; direction: ltr; }
    .row { display: flex; justify-content: space-between; gap: 6px; } .row span:last-child { unicode-bidi: isolate; text-align: left; }
    .num { direction: ltr; unicode-bidi: isolate; }
  </style></head><body>${body}</body></html>`;
}

function receipt(ticket, branch, options = {}) {
  const totals = ticket.totals;
  const data = ticket.data;
  const lines = totals.items.map((line) => `<tr><td>${escape(line.name)}${line.option_label && line.option_label !== 'الأساسي' ? ' - ' + escape(line.option_label) : ''}
      <div class="small">${escape(quantityLabel(line))} × ${money.decimal(line.unit_price_cents)}</div></td><td class="n">${money.decimal(line.total_cents)}</td></tr>`).join('');
  // Numbers are kept left-to-right so signs and separators print in the right place.
  const row = (label, value, text = false) => `<div class="row"><span>${label}</span><span${text ? '' : ' class="num"'}>${value}</span></div>`;
  const body = `
    <h1>${escape(branch.name)}</h1>
    ${branch.address ? `<div class="center small">${escape(branch.address)}</div>` : ''}
    ${branch.phone ? `<div class="center small">${escape(branch.phone)}</div>` : ''}
    <hr>
    ${row('فاتورة رقم', escape(ticket.local_number))}
    ${ticket.server_number ? row('رقم الداشبورد', escape(ticket.server_number)) : ''}
    ${row('التاريخ', escape(when(ticket.paid_at)), true)}
    ${row('الكاشير', escape(ticket.actor_name), true)}
    ${row('نوع الطلب', CHANNEL_LABELS[ticket.channel] + (ticket.channel === 'dine' ? ' - طاولة ' + escape(data.table_name) : ''), true)}
    ${data.customer_name ? row('العميل', escape(data.customer_name), true) : ''}
    ${row('الهاتف', escape(data.customer_phone))}
    ${data.address ? `<div class="small">العنوان: ${escape(data.address)}</div>` : ''}
    <hr><table>${lines}</table><hr>
    ${row('الإجمالي قبل الخصم', money.decimal(totals.subtotal_cents))}
    ${totals.discount_cents ? row('الخصم', money.decimal(totals.discount_cents)) : ''}
    ${totals.service_cents ? row(`الخدمة ${money.decimal(totals.service_bps)}٪`, money.decimal(totals.service_cents)) : ''}
    ${totals.delivery_cents ? row('التوصيل', money.decimal(totals.delivery_cents)) : ''}
    ${totals.tax_cents ? row(`الضريبة ${money.decimal(totals.tax_bps)}٪`, money.decimal(totals.tax_cents)) : ''}
    <div class="row big"><span>الإجمالي</span><span>${money.decimal(totals.total_cents)} جنيه</span></div>
    ${row('المدفوع', money.decimal(totals.cash_received_cents))}
    ${row('الباقي', money.decimal(totals.change_cents))}
    ${data.notes ? `<hr><div class="small">ملاحظات: ${escape(data.notes)}</div>` : ''}
    ${options.copy ? '<div class="center small">نسخة</div>' : ''}
    <hr><div class="center">شكرًا لزيارتكم — نسعد بخدمتكم مجددًا</div>`;
  return page(options.width || 72, body);
}

function kitchen(ticket, quote, branch, options = {}) {
  const data = ticket.data;
  const lines = quote.items.map((line) => `<tr><td class="big">${escape(line.name)}${line.option_label && line.option_label !== 'الأساسي' ? ' - ' + escape(line.option_label) : ''}</td>
      <td class="n big">${escape(quantityLabel(line))}</td></tr>`).join('');
  const body = `
    <h1>المطبخ - ${CHANNEL_LABELS[ticket.channel]}</h1>
    <div class="center">${escape(branch.name)}</div><hr>
    ${ticket.channel === 'dine' ? `<div class="big">طاولة ${escape(data.table_name)}</div>` : ''}
    ${data.customer_name ? `<div>العميل: ${escape(data.customer_name)}</div>` : ''}
    <div class="small">${escape(when(new Date().toISOString()))}</div><hr>
    <table>${lines}</table>
    ${data.notes ? `<hr><div class="big">ملاحظات: ${escape(data.notes)}</div>` : ''}`;
  return page(options.width || 72, body);
}

/** Prints through a hidden window; without a chosen printer Windows shows its print dialog. */
function print(BrowserWindow, html, settings = {}) {
  return new Promise((resolve) => {
    const window = new BrowserWindow({ show: false, webPreferences: { sandbox: true, javascript: false } });
    const done = (ok, error) => { resolve({ ok, error: ok ? null : (error || 'تعذرت الطباعة.') }); if (!window.isDestroyed()) window.close(); };
    window.webContents.once('did-finish-load', () => {
      const silent = !!settings.printer;
      window.webContents.print({ silent, deviceName: settings.printer || undefined, printBackground: true, margins: { marginType: 'none' } },
        (success, reason) => done(success, reason && reason !== 'cancelled' ? 'تعذرت الطباعة: ' + reason : null));
    });
    window.webContents.once('did-fail-load', () => done(false, 'تعذر تجهيز الفاتورة للطباعة.'));
    window.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
  });
}

module.exports = { receipt, kitchen, print, quantityLabel };
