'use strict';
// What the store's parts share: the sale channels and how tickets and board commands are shown.


const CHANNELS = ['takeaway', 'dine', 'phone'];

// The app orders board's actions, as its buttons name them.
const BOARD_ACTIONS = { accept: 'قبول وإرسال للمطبخ', reject: 'رفض الطلب', prepare: 'مندوب الفرع', dispatch: 'طلب مندوب', ready: 'جاهز',
  complete: 'تم التسليم', cancel: 'إلغاء الطلب' };

function presentBoardCommand(row) {
  return { id: row.id, actor_id: row.actor_id, actor_name: row.actor_name, source: row.source, order_id: row.order_id, order_number: row.order_number,
    action: row.action, fields: JSON.parse(row.fields), card: row.card, from_group: row.from_group, to_group: row.to_group, status: row.status,
    error: row.error, attempts: row.attempts, created_at: row.created_at, updated_at: row.updated_at, done_at: row.done_at };
}

function cleanTicketData(data) {
  const text = (value, max) => String(value ?? '').slice(0, max);
  const items = Array.isArray(data.items) ? data.items.slice(0, 100).map((item) => ({
    product_id: Number(item.product_id), option_id: String(item.option_id || ''),
    quantity_mode: item.quantity_mode === 'weight' ? 'weight' : 'piece', quantity: String(item.quantity || ''),
  })) : [];
  return { items, customer_phone: text(data.customer_phone, 30), customer_name: text(data.customer_name, 100), address: text(data.address, 500),
    table_name: text(data.table_name, 100), notes: text(data.notes, 500), discount: text(data.discount, 14), discount_reason: text(data.discount_reason, 500),
    delivery: text(data.delivery, 14), service_consent: !!data.service_consent, marketing_consent: !!data.marketing_consent,
    // Delivery orders: who takes it, and when it left with the driver (paid when the driver returns).
    delivery_company_id: Number.isInteger(Number(data.delivery_company_id)) && Number(data.delivery_company_id) > 0 ? Number(data.delivery_company_id) : null,
    driver: text(data.driver, 100), out_at: typeof data.out_at === 'string' && !Number.isNaN(Date.parse(data.out_at)) ? data.out_at : '' };
}

function presentTicket(row) {
  return { ...row, data: JSON.parse(row.data), totals: row.totals ? JSON.parse(row.totals) : null };
}

module.exports = { CHANNELS, BOARD_ACTIONS, presentBoardCommand, cleanTicketData, presentTicket };
