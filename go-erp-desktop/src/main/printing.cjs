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

/** Paper and printable widths: 80 mm rolls print 72 mm, 58 mm rolls print 48 mm. */
function paper(width) {
  return Number(width) === 58 ? { paper: 58, content: 48 } : { paper: 80, content: 72 };
}

function page(width, body) {
  const size = paper(width);
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">
  <style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    html { margin: 0; }
    body { margin: 0 auto; padding: 2mm 1mm; width: ${size.content}mm; font-family: Tahoma, "Segoe UI", Arial, sans-serif; font-size: 12px; color: #000; }
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

/**
 * The bill before payment: the table's bill in the hall, or the driver's copy of a delivery order with the
 * amount to collect, the customer's address and phone.
 */
function bill(ticket, quote, branch, options = {}) {
  const data = ticket.data;
  const delivery = ticket.channel === 'phone';
  const company = delivery && data.delivery_company_id ? (options.companies || []).find((item) => item.id === data.delivery_company_id) : null;
  const lines = quote.items.map((line) => `<tr><td>${escape(line.name)}${line.option_label && line.option_label !== 'الأساسي' ? ' - ' + escape(line.option_label) : ''}
      <div class="small">${escape(quantityLabel(line))} × ${money.decimal(line.unit_price_cents)}</div></td><td class="n">${money.decimal(line.total_cents)}</td></tr>`).join('');
  const row = (label, value) => `<div class="row"><span>${label}</span><span class="num">${value}</span></div>`;
  const body = `
    <h1>${escape(branch.name)}</h1>
    ${branch.phone ? `<div class="center small">${escape(branch.phone)}</div>` : ''}
    <div class="center big">${delivery ? 'طلب توصيل — المطلوب تحصيله' : 'الحساب' + (data.table_name ? ' — طاولة ' + escape(data.table_name) : '')}</div>
    <hr>
    ${data.customer_name ? `<div>العميل: ${escape(data.customer_name)}</div>` : ''}
    ${data.customer_phone ? `<div>الهاتف: <span class="num">${escape(data.customer_phone)}</span></div>` : ''}
    ${data.address ? `<div class="big">العنوان: ${escape(data.address)}</div>` : ''}
    ${company ? `<div>شركة التوصيل: ${escape(company.name)}</div>` : ''}
    ${data.driver ? `<div>المندوب: ${escape(data.driver)}</div>` : ''}
    <div class="small">${escape(when(new Date().toISOString()))}</div>
    <hr><table>${lines}</table><hr>
    ${row('الإجمالي قبل الخصم', money.decimal(quote.subtotal_cents))}
    ${quote.discount_cents ? row('الخصم', money.decimal(quote.discount_cents)) : ''}
    ${quote.service_cents ? row('الخدمة', money.decimal(quote.service_cents)) : ''}
    ${quote.delivery_cents ? row('التوصيل', money.decimal(quote.delivery_cents)) : ''}
    ${quote.tax_cents ? row('الضريبة', money.decimal(quote.tax_cents)) : ''}
    <div class="row big"><span>${delivery ? 'المطلوب تحصيله' : 'الإجمالي'}</span><span>${money.decimal(quote.total_cents)} جنيه</span></div>
    ${data.notes ? `<hr><div class="small">ملاحظات: ${escape(data.notes)}</div>` : ''}
    <hr><div class="center small">لم يتم الدفع بعد — ليست فاتورة نهائية</div>`;
  return page(options.width || 72, body);
}

/** End-of-day style summary of this device's sales, printed on the receipt roll. */
function report(data, branch, options = {}) {
  const row = (label, value) => `<div class="row"><span>${label}</span><span class="num">${value}</span></div>`;
  const section = (title, entries) => entries.length ? `<hr><div class="big">${title}</div>${entries.join('')}` : '';
  const items = data.items.map((line) => `<tr><td>${escape(line.name)}${line.option_label ? ' - ' + escape(line.option_label) : ''}
      <div class="small">${escape(quantityLabel(line))}</div></td><td class="n">${money.decimal(line.total_cents)}</td></tr>`).join('');
  const cash = data.cash;
  const body = `
    <h1>${escape(branch.name)}</h1>
    <div class="center">تقرير مبيعات هذا الجهاز</div>
    <div class="center small">${data.from === data.to ? 'يوم العمل ' + escape(data.from) : 'من ' + escape(data.from) + ' إلى ' + escape(data.to)}</div>
    <hr>
    ${row('عدد الفواتير', data.count)}
    ${row('الإجمالي قبل الخصم', money.decimal(data.subtotal_cents))}
    ${data.discount_cents ? row('الخصومات', money.decimal(data.discount_cents)) : ''}
    ${data.service_cents ? row('الخدمة', money.decimal(data.service_cents)) : ''}
    ${data.delivery_cents ? row('التوصيل', money.decimal(data.delivery_cents)) : ''}
    ${data.tax_cents ? row('الضريبة', money.decimal(data.tax_cents)) : ''}
    <div class="row big"><span>إجمالي المبيعات</span><span>${money.decimal(data.total_cents)} جنيه</span></div>
    ${section('حسب نوع الطلب', data.channels.map((entry) => row(`${CHANNEL_LABELS[entry.channel] || entry.channel} (${entry.count})`, money.decimal(entry.total_cents))))}
    ${section('حسب الكاشير', data.cashiers.map((entry) => row(`${escape(entry.name)} (${entry.count})`, money.decimal(entry.total_cents))))}
    ${data.days.length > 1 ? section('حسب اليوم', data.days.map((entry) => row(`${escape(entry.day)} (${entry.count})`, money.decimal(entry.total_cents)))) : ''}
    ${cash ? section('حركة الدرج من الجهاز', [row('نقدية المبيعات (بدون التوصيل)', money.decimal(cash.sales_cash_cents)),
      row('إيداع', money.decimal(cash.cash_in_cents)), row('سحب', money.decimal(cash.cash_out_cents)),
      row('مصروفات معتمدة', money.decimal(cash.approved_expenses_cents)), row('مصروفات بانتظار الاعتماد', money.decimal(cash.pending_expenses_cents)),
      `<div class="row big"><span>صافي حركة الدرج</span><span class="num">${money.decimal(cash.drawer_movement_cents)}</span></div>`]) : ''}
    ${items ? `<hr><div class="big">الأصناف</div><table>${items}</table>` : ''}
    <hr><div class="center small">طُبع ${escape(when(new Date().toISOString()))} — الأرقام من هذا الجهاز فقط</div>`;
  return page(options.width || 72, body);
}

/**
 * Loads the receipt in a hidden window and measures it, so the roll is cut right after the last line
 * instead of feeding a whole page. The page itself cannot run scripts (see its security policy).
 */
function render(BrowserWindow, html, settings = {}) {
  return new Promise((resolve, reject) => {
    const window = new BrowserWindow({ show: false, webPreferences: { sandbox: true, contextIsolation: true } });
    window.webContents.once('did-fail-load', () => { if (!window.isDestroyed()) window.close(); reject(new Error('تعذر تجهيز الفاتورة للطباعة.')); });
    window.webContents.once('did-finish-load', async () => {
      let pageSize;
      try {
        const height = Number(await window.webContents.executeJavaScript('Math.ceil(document.body.getBoundingClientRect().bottom)'));
        // Screen pixels are 1/96 inch; 8 mm more so the cutter never clips the last line.
        if (height > 0) pageSize = { width: paper(settings.width).paper * 1000, height: Math.max(Math.ceil(height * 25400 / 96) + 8000, 50000) };
      } catch { pageSize = undefined; }
      resolve({ window, pageSize });
    });
    window.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
  });
}

/** Windows reports print failures in English; the cashier sees what to do in Arabic. */
function printError(reason) {
  const text = String(reason || '').toLowerCase();
  if (!text || text.includes('cancel')) return null; // closed the print dialog on purpose
  if (text.includes('devicename')) return 'الطابعة المختارة مش موجودة على الجهاز. اختار الطابعة من الإعدادات.';
  if (text.includes('failed')) return 'فشلت الطباعة. اتأكد إن الطابعة شغالة وفيها ورق.';
  return 'تعذرت الطباعة: ' + reason;
}

/** Prints on the chosen Windows printer; without one, Windows shows its print dialog. */
async function print(BrowserWindow, html, settings = {}) {
  let rendered;
  try {
    rendered = await render(BrowserWindow, html, settings);
  } catch (error) {
    return { ok: false, error: error.message };
  }
  const { window, pageSize } = rendered;
  return new Promise((resolve) => {
    const options = { silent: !!settings.printer, deviceName: settings.printer || undefined, printBackground: true, margins: { marginType: 'none' } };
    if (pageSize) options.pageSize = pageSize;
    window.webContents.print(options, (success, reason) => {
      resolve({ ok: success, error: success ? null : printError(reason) });
      if (!window.isDestroyed()) window.close();
    });
  });
}

module.exports = { receipt, kitchen, bill, report, print, render, paper, quantityLabel, printError };
