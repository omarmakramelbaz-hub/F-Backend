<template>
  <div class="cash">
    <div class="toolbar">
      <label>يوم العمل<input type="date" v-model="day" :max="today" @change="load"></label>
      <button class="ghost" @click="load">تحديث</button>
      <span class="spacer"></span>
      <button v-if="can.approve" class="ghost" @click="openDashboard('expenses')" title="يحتاج إنترنت">مراجعة واعتماد المصروفات</button>
      <button v-if="can.shift" class="ghost strong" @click="openDashboard('shifts')" title="يحتاج إنترنت">تقفيل الوردية</button>
    </div>

    <div class="summary six" v-if="summary">
      <div class="stat"><span>مبيعات هذا الجهاز</span><b>{{ money(summary.sales_cents) }}</b><small>{{ summary.sales_count }} فاتورة</small></div>
      <div class="stat"><span>منها رسوم توصيل (مع المندوب)</span><b>{{ money(summary.delivery_cents) }}</b></div>
      <div class="stat"><span>إيداع في الخزنة</span><b>{{ money(summary.cash_in_cents) }}</b><small>سحب: {{ money(summary.cash_out_cents) }}</small></div>
      <div class="stat"><span>مصروفات معتمدة</span><b>{{ money(summary.approved_expenses_cents) }}</b></div>
      <div class="stat" :class="{ warn: summary.pending_expenses_count }"><span>مصروفات بانتظار الاعتماد</span><b>{{ money(summary.pending_expenses_cents) }}</b>
        <small>{{ summary.pending_expenses_count }} مصروف</small></div>
      <div class="stat total"><span>صافي حركة الدرج من الجهاز</span><b>{{ money(summary.drawer_movement_cents) }} جنيه</b></div>
    </div>
    <p class="muted small">الأرقام دي من هذا الجهاز بس. تقفيل الوردية الرسمي بيتم من الداشبورد، وبيجمع كل الأجهزة وطلبات التطبيق ورصيد بداية الوردية. المصروفات المعلقة مش بتتخصم من المتوقع إلا بعد اعتمادها، زي الداشبورد.</p>

    <div class="forms" v-if="day === today && (can.expense || can.till)">
      <form v-if="can.expense" class="card" @submit.prevent="saveExpense">
        <h2>تسجيل مصروف من الدرج</h2>
        <label>البند
          <select v-model="expense.category" required>
            <option value="" disabled>اختر البند</option>
            <option v-for="item in categories" :key="item.key" :value="item.key">{{ item.name }}</option>
          </select>
        </label>
        <div class="pair">
          <label>المبلغ<input v-model.trim="expense.amount" inputmode="decimal" required placeholder="0.00"></label>
          <label v-if="stockCategory">الكمية بالكيلو<input v-model.trim="expense.quantity_kg" inputmode="decimal" required placeholder="مثال: 5 أو 2.5"></label>
        </div>
        <label>الوصف<input v-model.trim="expense.description" maxlength="500" placeholder="مثال: فاتورة كهرباء شهر أكتوبر"></label>
        <div class="pair">
          <label>المورد<input v-model.trim="expense.supplier" maxlength="150" placeholder="اختياري"></label>
          <label>ملاحظات<input v-model.trim="expense.notes" maxlength="1000" placeholder="اختياري"></label>
        </div>
        <label v-if="can.approve" class="check"><input type="checkbox" v-model="expense.approve"> اعتماد المصروف الآن (يتخصم من الخزنة)</label>
        <p v-if="!categories.length" class="error small">لم يتم تنزيل بنود المصروفات بعد. اتصل بالإنترنت مرة للمزامنة.</p>
        <p v-if="expenseError" class="error">{{ expenseError }}</p>
        <button class="primary" :disabled="busy || !categories.length">حفظ المصروف</button>
      </form>

      <form v-if="can.till" class="card" @submit.prevent="saveTill">
        <h2>إيداع أو سحب من الخزنة</h2>
        <div class="channels two">
          <button type="button" :class="{ active: till.direction === 'in' }" @click="till.direction = 'in'">إيداع</button>
          <button type="button" :class="{ active: till.direction === 'out' }" @click="till.direction = 'out'">سحب</button>
        </div>
        <label>المبلغ<input v-model.trim="till.amount" inputmode="decimal" required placeholder="0.00"></label>
        <label>السبب<input v-model.trim="till.note" maxlength="500" required placeholder="مثال: فكة للدرج / توريد للمالك"></label>
        <p v-if="tillError" class="error">{{ tillError }}</p>
        <button class="primary" :disabled="busy">{{ till.direction === 'in' ? 'تسجيل الإيداع' : 'تسجيل السحب' }}</button>
      </form>
    </div>

    <p v-if="notice" class="notice">{{ notice }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <table class="list" v-if="items.length">
      <thead><tr><th>الوقت</th><th>النوع</th><th>التفاصيل</th><th>المبلغ</th><th>بواسطة</th><th>الداشبورد</th></tr></thead>
      <tbody>
        <tr v-for="item in items" :key="item.id" :class="{ rejected: item.sync_status === 'rejected' }">
          <td>{{ time(item.created_at) }}</td>
          <td>{{ kindLabel(item) }}</td>
          <td>{{ details(item) }}</td>
          <td><b>{{ money(item.amount_cents) }}</b></td>
          <td>{{ item.actor_name }}</td>
          <td>
            <span v-if="item.sync_status === 'synced'" class="pill ok">تم الإرسال<span v-if="item.server_ref"> · {{ item.server_ref }}</span></span>
            <span v-else-if="item.sync_status === 'rejected'" class="pill danger" :title="item.sync_error">مرفوض: {{ item.sync_error }}</span>
            <span v-else class="pill warn">في الانتظار</span>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="muted center">لا توجد مصروفات أو حركات خزنة في هذا اليوم على هذا الجهاز.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { call, money, time } from '../api.js';

defineProps({ session: Object });
const emit = defineEmits(['changed']);
const day = ref('');
const today = ref('');
const summary = ref(null);
const items = ref([]);
const categories = ref([]);
const can = ref({});
const busy = ref(false);
const notice = ref('');
const error = ref('');
const expenseError = ref('');
const tillError = ref('');
const expense = reactive(emptyExpense());
const till = reactive({ direction: 'in', amount: '', note: '' });

const stockCategory = computed(() => {
  const item = categories.value.find((category) => category.key === expense.category);
  return !!(item && item.stock);
});

function emptyExpense() {
  return { category: '', amount: '', quantity_kg: '', description: '', supplier: '', notes: '', approve: false };
}

function kindLabel(item) {
  if (item.kind === 'till') return item.data.direction === 'out' ? 'سحب من الخزنة' : 'إيداع في الخزنة';
  return item.data.approve ? 'مصروف (معتمد)' : 'مصروف';
}

function details(item) {
  if (item.kind === 'till') return item.data.note;
  return [item.data.category_name, item.data.description, item.data.quantity_kg ? item.data.quantity_kg + ' كجم' : '', item.data.supplier]
    .filter(Boolean).join(' · ');
}

async function load() {
  error.value = '';
  try {
    const result = await call('cash:summary', { day: day.value || undefined });
    day.value = result.day;
    today.value = result.today;
    summary.value = result.summary;
    items.value = result.items;
    categories.value = result.categories;
    can.value = result.can;
  } catch (problem) {
    error.value = problem.message;
  }
}

async function saveExpense() {
  expenseError.value = '';
  notice.value = '';
  busy.value = true;
  try {
    await call('cash:expense', { ...expense, quantity_kg: stockCategory.value ? expense.quantity_kg : '' });
    notice.value = 'تم حفظ المصروف على الجهاز وهيتبعت للداشبورد في المزامنة.';
    Object.assign(expense, emptyExpense());
    await load();
    emit('changed');
  } catch (problem) {
    expenseError.value = problem.message;
  } finally {
    busy.value = false;
  }
}

async function saveTill() {
  tillError.value = '';
  notice.value = '';
  busy.value = true;
  try {
    await call('cash:till', { ...till });
    notice.value = till.direction === 'in' ? 'تم تسجيل الإيداع.' : 'تم تسجيل السحب.';
    Object.assign(till, { direction: 'in', amount: '', note: '' });
    await load();
    emit('changed');
  } catch (problem) {
    tillError.value = problem.message;
  } finally {
    busy.value = false;
  }
}

function openDashboard(page) {
  call('dashboard:open', { page }).catch(() => {});
}

onMounted(load);
</script>
