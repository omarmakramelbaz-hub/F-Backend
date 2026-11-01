<template>
  <div class="settings">
    <section class="card">
      <h2>المزامنة مع الداشبورد</h2>
      <p class="muted">البرنامج يحفظ كل شيء على هذا الجهاز أولًا، ويرسل العمليات للداشبورد ويستلم التحديثات كل ٣٠ ثانية طالما الإنترنت متاح.</p>
      <div class="facts">
        <div><span>الحالة</span><SyncBadge :status="sync" @sync-now="syncNow" /></div>
        <div><span>آخر مزامنة ناجحة</span><b>{{ since(sync.last_sync_at) }}</b></div>
        <div><span>عمليات في الانتظار</span><b>{{ sync.pending || 0 }}</b></div>
        <div><span>عمليات مرفوضة</span><b>{{ sync.rejected || 0 }}</b></div>
      </div>
      <p v-if="sync.last_error" class="error small">{{ sync.last_error }}</p>
      <div class="actions">
        <button class="primary" :disabled="busy" @click="syncNow">{{ busy ? 'جاري المزامنة…' : 'مزامنة الآن وتحديث المينيو والحسابات' }}</button>
        <button class="ghost" @click="openDashboard">فتح الداشبورد الكامل</button>
      </div>
      <div v-if="rejected.length" class="rejected">
        <h3>عمليات رفضها الداشبورد</h3>
        <p class="muted small">العمليات دي محفوظة على الجهاز ولم تُسجّل في الداشبورد. راجعها مع الإدارة.</p>
        <table class="list">
          <thead><tr><th>النوع</th><th>الفاتورة</th><th>المبلغ</th><th>السبب</th><th>الوقت</th></tr></thead>
          <tbody>
            <tr v-for="item in rejected" :key="item.id"><td>{{ item.kind }}</td><td>{{ item.local_number || '-' }}</td><td>{{ item.total }}</td><td>{{ item.error }}</td><td>{{ dateTime(item.at) }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <h2>الطباعة</h2>
      <div class="form-grid">
        <label>طابعة الفواتير
          <select v-model="print.printer">
            <option value="">اختيار الطابعة عند كل طباعة</option>
            <option v-for="printer in printers" :key="printer.name" :value="printer.name">{{ printer.label }}{{ printer.default ? ' (الافتراضية)' : '' }}</option>
          </select>
        </label>
        <label>طابعة المطبخ
          <select v-model="print.kitchen_printer">
            <option value="">نفس طابعة الفواتير</option>
            <option v-for="printer in printers" :key="printer.name" :value="printer.name">{{ printer.label }}</option>
          </select>
        </label>
        <label>عرض الورق
          <select v-model.number="print.width"><option :value="72">80 مم (عرض الطباعة 72)</option><option :value="58">58 مم</option></select>
        </label>
        <label class="check"><input type="checkbox" v-model="print.auto_print"> طباعة الفاتورة تلقائيًا بعد إنهاء البيع</label>
        <label class="check"><input type="checkbox" v-model="print.kitchen_auto"> طباعة طلب المطبخ تلقائيًا مع الفاتورة</label>
      </div>
      <p v-if="printNotice" :class="printOk ? 'notice' : 'error'">{{ printNotice }}</p>
      <div class="actions">
        <button class="primary" @click="savePrint">حفظ إعدادات الطباعة</button>
        <button class="ghost" @click="testPrint">طباعة تجريبية</button>
        <button class="ghost" @click="loadPrinters">تحديث قائمة الطابعات</button>
      </div>
    </section>

    <section class="card">
      <h2>هذا الجهاز</h2>
      <div class="facts">
        <div><span>اسم الجهاز</span><b>{{ info.device.name }}</b></div>
        <div><span>كود الجهاز في أرقام الفواتير</span><b>{{ info.device.code }}</b></div>
        <div><span>الفروع</span><b>{{ info.branches.map((branch) => branch.name).join('، ') }}</b></div>
        <div><span>السرفر</span><b class="ltr">{{ info.server }}</b></div>
        <div><span>إصدار البرنامج</span><b class="ltr">{{ info.version }}</b></div>
      </div>
      <p class="muted small">آخر تحديث للمينيو:
        <span v-for="branch in info.branches" :key="branch.value"> {{ branch.name }}: {{ branch.updated_at ? dateTime(branch.updated_at) : 'لم يتم بعد' }}{{ branch.available ? '' : ' (غير متاح)' }} ·</span>
      </p>
          <label class="check"><input type="checkbox" v-model="autostart" @change="setAutostart"> تشغيل البرنامج تلقائيًا مع فتح ويندوز</label>
      <p class="muted small">نسخة احتياطية من بيانات الجهاز بتتعمل كل يوم، وبيتحفظ آخر ٧ أيام.</p>
    </section>

    <section class="card">
      <h2>إعادة تجهيز الجهاز</h2>
      <p class="muted">لتجهيز الجهاز ده بحساب تاني (فرع تاني أو حساب الأونر): البرنامج بيبعت كل عمليات الجهاز للداشبورد الأول، وبعدها
        يلغي التجهيز الحالي ويفتح شاشة تجهيز الجهاز، وتسجّل فيها بأي حساب من الداشبورد. محتاج إنترنت، ومن غير فواتير أو طلبات مفتوحة على الجهاز.</p>
      <form v-if="releasing" class="form-grid" @submit.prevent="release">
        <label>كلمة مرور {{ info.session.user.name }} للتأكيد
          <input v-model="releasePassword" type="password" autocomplete="current-password" required :disabled="releaseBusy">
        </label>
        <div class="actions">
          <button class="danger-outline" :disabled="releaseBusy || !releasePassword">{{ releaseBusy ? 'جاري إرسال العمليات للداشبورد…' : 'إلغاء التجهيز الحالي' }}</button>
          <button type="button" class="ghost" :disabled="releaseBusy" @click="releasing = false; releaseError = ''">رجوع</button>
        </div>
      </form>
      <div v-else class="actions"><button type="button" class="ghost" @click="releasing = true">إعادة تجهيز الجهاز بحساب آخر</button></div>
      <p v-if="releaseError" class="error small" role="alert">{{ releaseError }}</p>
      <div v-if="openOrders.length" class="open-orders">
        <h3>طلبات مفتوحة على الجهاز</h3>
        <p class="muted small">كل طلب لازم يتحصّل من مكانه أو يتلغي من هنا قبل إعادة تجهيز الجهاز، عشان مفيش حاجة تضيع.</p>
        <table class="list">
          <thead><tr><th>الطلب</th><th>الفرع</th><th>الأصناف</th><th>الإجمالي</th><th>مكانه</th><th></th></tr></thead>
          <tbody>
            <tr v-for="order in openOrders" :key="order.id">
              <td><b>{{ CHANNELS[order.channel] || order.channel }}{{ order.label ? ' · ' + order.label : '' }}</b>
                <small class="muted">{{ dateTime(order.created_at) }}{{ order.actor_name ? ' · ' + order.actor_name : '' }}</small></td>
              <td>{{ order.branch_name }}</td>
              <td>{{ order.items.join('، ') || '—' }}</td>
              <td>{{ order.total || '—' }}</td>
              <td>{{ order.where }}<small v-if="order.problem" class="error">{{ order.problem }}</small></td>
              <td>
                <template v-if="confirming === order.id">
                  <button type="button" class="danger-outline" @click="cancelOrder(order.id)">تأكيد الإلغاء</button>
                  <button type="button" class="ghost" @click="confirming = null">رجوع</button>
                </template>
                <button v-else type="button" class="danger-outline" @click="confirming = order.id">إلغاء الطلب</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { call, since, dateTime, CHANNELS } from '../api.js';
import SyncBadge from '../components/SyncBadge.vue';

const props = defineProps({ info: Object, sync: Object, syncNow: Function });
const emit = defineEmits(['reset']);
const releasing = ref(false);
const releaseBusy = ref(false);
const releasePassword = ref('');
const releaseError = ref('');
const openOrders = ref([]);
const confirming = ref(null);

async function loadOpenOrders() {
  try { openOrders.value = await call('setup:open-orders'); } catch { openOrders.value = []; }
}

async function cancelOrder(id) {
  releaseError.value = '';
  try {
    await call('setup:cancel-order', { id });
    confirming.value = null;
  } catch (problem) {
    releaseError.value = problem.message;
  }
  await loadOpenOrders();
}

/** Drops this device's setup (after sending everything) so it can be set up again with another account. */
async function release() {
  releaseError.value = '';
  releaseBusy.value = true;
  try {
    await call('setup:release', { password: releasePassword.value });
    releasePassword.value = '';
    emit('reset');
  } catch (problem) {
    releaseError.value = problem.message;
    await loadOpenOrders();
  } finally {
    releaseBusy.value = false;
  }
}
const busy = ref(false);
const rejected = ref([]);
const printers = ref([]);
const print = reactive({ printer: '', kitchen_printer: '', width: 72, auto_print: true, kitchen_auto: false, ...props.info.print });
const autostart = ref(!!props.info.autostart);

async function setAutostart() {
  autostart.value = await call('app:autostart', { enabled: autostart.value });
}
const printNotice = ref('');
const printOk = ref(true);

async function syncNow() {
  busy.value = true;
  try {
    await props.syncNow();
  } finally {
    busy.value = false;
    await loadSync();
  }
}

async function loadSync() {
  const status = await call('sync:status');
  rejected.value = status.rejected_list || [];
}

async function loadPrinters() {
  printers.value = await call('print:printers');
}

async function savePrint() {
  Object.assign(print, await call('print:settings', { ...print }));
  printOk.value = true;
  printNotice.value = 'تم حفظ إعدادات الطباعة.';
}

async function testPrint() {
  await savePrint();
  try {
    const result = await call('print:test');
    printOk.value = !!result.ok;
    printNotice.value = result.ok ? 'تم إرسال الطباعة التجريبية.' : (result.error || 'تم إلغاء الطباعة.');
  } catch (problem) {
    printOk.value = false;
    printNotice.value = problem.message;
  }
}

function openDashboard() {
  call('dashboard:open').catch(() => {});
}

onMounted(async () => {
  await Promise.all([loadSync(), loadPrinters(), loadOpenOrders()]);
});
</script>
