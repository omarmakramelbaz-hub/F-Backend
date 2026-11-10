'use strict';
// Store: The day's and the period's totals.
const money = require('../money.cjs');
const { presentTicket } = require('./shared.cjs');

module.exports = {
  /**
   * What went through the drawer on this device today. The shift is still closed on the dashboard,
   * which adds the other devices, app orders and the opening cash.
   */
  cashSummary(branch, day) {
    const sales = this.db.prepare(`SELECT COUNT(*) AS count, COALESCE(SUM(json_extract(totals, '$.total_cents')), 0) AS total,
      COALESCE(SUM(json_extract(totals, '$.delivery_cents')), 0) AS delivery
      FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ?`).get(branch, day);
    const sum = (where, ...args) => this.db.prepare(`SELECT COALESCE(SUM(amount_cents), 0) AS total, COUNT(*) AS count FROM journal
      WHERE branch = ? AND business_date = ? AND ${where}`).get(branch, day, ...args);
    const cashIn = sum("kind = 'till' AND json_extract(data, '$.direction') = 'in' AND sync_status != 'rejected'");
    const cashOut = sum("kind = 'till' AND json_extract(data, '$.direction') = 'out' AND sync_status != 'rejected'");
    const approved = sum("kind = 'expense' AND json_extract(data, '$.approve') = 1 AND sync_status != 'rejected'");
    const pending = sum("kind = 'expense' AND json_extract(data, '$.approve') = 0 AND sync_status != 'rejected'");
    const salesCash = sales.total - sales.delivery;
    return { day, sales_count: sales.count, sales_cents: sales.total, delivery_cents: sales.delivery, sales_cash_cents: salesCash,
      cash_in_cents: cashIn.total, cash_out_cents: cashOut.total, approved_expenses_cents: approved.total,
      pending_expenses_cents: pending.total, pending_expenses_count: pending.count,
      drawer_movement_cents: salesCash + cashIn.total - cashOut.total - approved.total };
  },

// ---- invoices and daily totals ----

  receipts(branch, day) {
    return this.db.prepare("SELECT * FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ? ORDER BY paid_at DESC").all(branch, day).map(presentTicket);
  },

daySummary(branch, day) {
    const row = this.db.prepare(`SELECT COUNT(*) AS count, COALESCE(SUM(json_extract(totals, '$.total_cents')), 0) AS total,
      SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN sync_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
      FROM tickets WHERE branch = ? AND status = 'paid' AND business_date = ?`).get(branch, day);
    return { day, count: row.count, total_cents: row.total, total: money.decimal(row.total), pending: row.pending || 0, rejected: row.rejected || 0 };
  },

// ---- reports from this device's own sales ----

  /** Sales of this device between two working days: totals, by channel, by cashier, by day and by item. */
  salesReport(branch, from, to) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || to < from) throw new Error('راجع الفترة المطلوبة.');
    const rows = this.db.prepare(`SELECT channel, actor_id, actor_name, business_date, totals FROM tickets
      WHERE branch = ? AND status = 'paid' AND business_date BETWEEN ? AND ? ORDER BY paid_at`).all(branch, from, to);
    const sums = { count: 0, subtotal_cents: 0, discount_cents: 0, service_cents: 0, delivery_cents: 0, tax_cents: 0, total_cents: 0 };
    const group = (map, key, make) => { if (!map.has(key)) map.set(key, make()); return map.get(key); };
    const channels = new Map();
    const cashiers = new Map();
    const days = new Map();
    const items = new Map();
    for (const row of rows) {
      const totals = JSON.parse(row.totals);
      sums.count++;
      for (const key of ['subtotal_cents', 'discount_cents', 'service_cents', 'delivery_cents', 'tax_cents', 'total_cents']) sums[key] += totals[key] || 0;
      for (const [map, key, extra] of [[channels, row.channel, { channel: row.channel }], [cashiers, row.actor_id, { actor_id: row.actor_id, name: row.actor_name }],
        [days, row.business_date, { day: row.business_date }]]) {
        const entry = group(map, key, () => ({ ...extra, count: 0, total_cents: 0 }));
        entry.count++;
        entry.total_cents += totals.total_cents;
      }
      for (const line of totals.items || []) {
        const key = [line.product_id, line.option_id || '', line.quantity_mode].join('|');
        const entry = group(items, key, () => ({ name: line.name, option_label: line.option_label === 'الأساسي' ? '' : (line.option_label || ''),
          quantity_mode: line.quantity_mode, unit_mode: line.unit_mode || '', quantity_millis: 0, total_cents: 0, invoices: 0 }));
        entry.quantity_millis += line.quantity_millis;
        entry.total_cents += line.total_cents;
        entry.invoices++;
      }
    }
    const byTotal = (a, b) => b.total_cents - a.total_cents;
    return { branch, from, to, ...sums, channels: [...channels.values()].sort(byTotal), cashiers: [...cashiers.values()].sort(byTotal),
      days: [...days.values()].sort((a, b) => (a.day < b.day ? -1 : 1)), items: [...items.values()].sort(byTotal) };
  },
};
