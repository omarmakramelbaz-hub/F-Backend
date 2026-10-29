<template>
  <div class="reports">
    <div class="toolbar">
      <label>من يوم<input type="date" v-model="from" :max="today" @change="load"></label>
      <label>إلى يوم<input type="date" v-model="to" :max="today" @change="load"></label>
      <button class="ghost" @click="setRange(0)">اليوم</button>
      <button class="ghost" @click="setRange(6)">آخر ٧ أيام</button>
      <button class="ghost" @click="setRange(29)">آخر ٣٠ يوم</button>
      <span class="spacer"></span>
      <button class="primary" :disabled="!report || !report.count" @click="printReport">طباعة التقرير</button>
    </div>
    <p class="muted small">مبيعات هذا الجهاز فقط، وبتشتغل من غير إنترنت. تقارير كل الفروع والأجهزة موجودة في الداشبورد.</p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <template v-if="report">
      <div class="summary six">
        <div class="stat"><span>عدد الفواتير</span><b>{{ report.count }}</b></div>
        <div class="stat"><span>الإجمالي قبل الخصم</span><b>{{ money(report.subtotal_cents) }}</b></div>
        <div class="stat"><span>الخصومات</span><b>{{ money(report.discount_cents) }}</b></div>
        <div class="stat"><span>التوصيل</span><b>{{ money(report.delivery_cents) }}</b></div>
        <div class="stat"><span>الخدمة والضريبة</span><b>{{ money(report.service_cents + report.tax_cents) }}</b></div>
        <div class="stat total"><span>إجمالي المبيعات</span><b>{{ money(report.total_cents) }} جنيه</b></div>
      </div>

      <div class="report-grid">
        <section class="card">
          <h2>حسب نوع الطلب</h2>
          <table class="list compact">
            <tbody>
              <tr v-for="entry in report.channels" :key="entry.channel"><td>{{ CHANNELS[entry.channel] }}</td><td>{{ entry.count }} فاتورة</td><td><b>{{ money(entry.total_cents) }}</b></td></tr>
            </tbody>
          </table>
          <p v-if="!report.channels.length" class="muted">لا توجد مبيعات.</p>
        </section>
        <section class="card">
          <h2>حسب الكاشير</h2>
          <table class="list compact">
            <tbody>
              <tr v-for="entry in report.cashiers" :key="entry.actor_id"><td>{{ entry.name }}</td><td>{{ entry.count }} فاتورة</td><td><b>{{ money(entry.total_cents) }}</b></td></tr>
            </tbody>
          </table>
          <p v-if="!report.cashiers.length" class="muted">لا توجد مبيعات.</p>
        </section>
        <section class="card" v-if="report.days.length > 1">
          <h2>حسب اليوم</h2>
          <table class="list compact">
            <tbody>
              <tr v-for="entry in report.days" :key="entry.day"><td>{{ entry.day }}</td><td>{{ entry.count }} فاتورة</td><td><b>{{ money(entry.total_cents) }}</b></td></tr>
            </tbody>
          </table>
        </section>
        <section class="card" v-if="report.cash">
          <h2>حركة الدرج من الجهاز</h2>
          <table class="list compact">
            <tbody>
              <tr><td>نقدية المبيعات (بدون التوصيل)</td><td><b>{{ money(report.cash.sales_cash_cents) }}</b></td></tr>
              <tr><td>إيداع</td><td><b>{{ money(report.cash.cash_in_cents) }}</b></td></tr>
              <tr><td>سحب</td><td><b>{{ money(report.cash.cash_out_cents) }}</b></td></tr>
              <tr><td>مصروفات معتمدة</td><td><b>{{ money(report.cash.approved_expenses_cents) }}</b></td></tr>
              <tr><td>مصروفات بانتظار الاعتماد</td><td><b>{{ money(report.cash.pending_expenses_cents) }}</b></td></tr>
              <tr><td>صافي حركة الدرج</td><td><b>{{ money(report.cash.drawer_movement_cents) }}</b></td></tr>
            </tbody>
          </table>
        </section>
      </div>

      <section class="card">
        <h2>الأصناف</h2>
        <table class="list" v-if="report.items.length">
          <thead><tr><th>الصنف</th><th>الكمية</th><th>عدد الفواتير</th><th>الإجمالي</th></tr></thead>
          <tbody>
            <tr v-for="line in report.items" :key="line.name + line.option_label + line.quantity_mode">
              <td>{{ line.name }}<span v-if="line.option_label"> - {{ line.option_label }}</span></td>
              <td>{{ quantity(line) }}</td>
              <td>{{ line.invoices }}</td>
              <td><b>{{ money(line.total_cents) }}</b></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">لا توجد مبيعات في الفترة دي على هذا الجهاز.</p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { call, money, CHANNELS } from '../api.js';

const from = ref('');
const to = ref('');
const today = ref('');
const report = ref(null);
const error = ref('');
const notice = ref('');

function quantity(line) {
  const whole = Math.floor(line.quantity_millis / 1000);
  const part = String(line.quantity_millis % 1000).padStart(3, '0');
  if (line.quantity_mode === 'piece') return whole + ' قطعة';
  const amount = (whole + '.' + part).replace(/\.?0+$/, '');
  return line.unit_mode === 'select' ? amount : amount + ' كجم';
}

function shift(day, days) {
  const date = new Date(day + 'T12:00:00Z');
  date.setUTCDate(date.getUTCDate() - days);
  return date.toISOString().slice(0, 10);
}

async function setRange(days) {
  to.value = today.value;
  from.value = shift(today.value, days);
  await load();
}

async function load() {
  error.value = '';
  notice.value = '';
  try {
    if (from.value && to.value && to.value < from.value) to.value = from.value;
    const result = await call('reports:sales', { from: from.value || undefined, to: to.value || undefined });
    today.value = result.today;
    report.value = result.report;
    from.value = result.report.from;
    to.value = result.report.to;
  } catch (problem) {
    error.value = problem.message;
  }
}

async function printReport() {
  error.value = '';
  try {
    const result = await call('reports:print', { from: from.value, to: to.value });
    if (result.ok) notice.value = 'تم إرسال التقرير للطابعة.';
    else if (result.error) error.value = result.error;
  } catch (problem) {
    error.value = problem.message;
  }
}

onMounted(load);
</script>
