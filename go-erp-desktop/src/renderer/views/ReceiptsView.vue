<template>
  <div class="receipts">
    <div class="toolbar">
      <label>يوم العمل<input type="date" v-model="day" :max="today" @change="load"></label>
      <button class="ghost" @click="load">تحديث</button>
      <span class="muted small">يوم العمل يبدأ الساعة ٦ صباحًا بتوقيت القاهرة.</span>
    </div>

    <div class="summary" v-if="summary">
      <div class="stat"><span>عدد الفواتير</span><b>{{ summary.count }}</b></div>
      <div class="stat"><span>إجمالي المبيعات</span><b>{{ money(summary.total_cents) }} جنيه</b></div>
      <div class="stat" :class="{ warn: summary.pending }"><span>لم تُرسل للداشبورد بعد</span><b>{{ summary.pending }}</b></div>
      <div class="stat" :class="{ danger: summary.rejected }"><span>مرفوضة من الداشبورد</span><b>{{ summary.rejected }}</b></div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <table class="list" v-if="items.length">
      <thead>
        <tr><th>رقم الفاتورة</th><th>الوقت</th><th>النوع</th><th>العميل</th><th>الكاشير</th><th>الإجمالي</th><th>الداشبورد</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.id" :class="{ rejected: item.sync_status === 'rejected' }">
          <td><b>{{ item.local_number }}</b></td>
          <td>{{ time(item.paid_at) }}</td>
          <td>{{ CHANNELS[item.channel] }}<span v-if="item.channel === 'dine' && item.data.table_name"> · طاولة {{ item.data.table_name }}</span></td>
          <td>{{ item.data.customer_name || item.data.customer_phone }}</td>
          <td>{{ item.actor_name }}</td>
          <td><b>{{ money(item.totals.total_cents) }}</b></td>
          <td>
            <span v-if="item.sync_status === 'synced'" class="pill ok">تم الإرسال<span v-if="item.server_number"> · {{ item.server_number }}</span></span>
            <span v-else-if="item.sync_status === 'rejected'" class="pill danger" :title="item.sync_error">مرفوضة: {{ item.sync_error }}</span>
            <span v-else class="pill warn">في الانتظار</span>
          </td>
          <td><button v-if="session.user.can_reprint" class="ghost small" @click="reprint(item)">إعادة طباعة</button></td>
        </tr>
      </tbody>
    </table>
    <p v-else class="muted center">لا توجد فواتير في هذا اليوم على هذا الجهاز.</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { call, money, time, CHANNELS } from '../api.js';

defineProps({ session: Object, branch: Object });
const day = ref('');
const today = ref('');
const summary = ref(null);
const items = ref([]);
const error = ref('');
const notice = ref('');

async function load() {
  error.value = '';
  try {
    const result = await call('receipts:list', { day: day.value || undefined });
    day.value = result.day;
    today.value = result.today;
    summary.value = result.summary;
    items.value = result.items;
  } catch (problem) {
    error.value = problem.message;
  }
}

async function reprint(item) {
  error.value = '';
  notice.value = '';
  try {
    const result = await call('receipts:print', { id: item.id });
    if (result.ok) notice.value = `تمت إعادة طباعة الفاتورة ${item.local_number}.`;
    else if (result.error) error.value = result.error;
  } catch (problem) {
    error.value = problem.message;
  }
}

onMounted(load);
</script>
