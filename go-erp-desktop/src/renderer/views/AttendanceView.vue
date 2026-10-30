<template>
  <div class="attendance">
    <div class="toolbar">
      <div>
        <h2>حضور وانصراف الموظفين</h2>
        <p class="muted small">يوم العمل {{ day }} · الوقت بساعة الجهاز (مصححة بفرق السرفر) ويشتغل من غير إنترنت. الخصومات بتتحسب في الداشبورد بنفس القواعد.</p>
      </div>
      <span class="spacer"></span>
      <span v-for="shift in shifts" :key="shift.shift" class="pill shift-pill">{{ shiftName(shift.shift) }} {{ shift.starts_at }} - {{ shift.ends_at }}</span>
      <button class="ghost" @click="load">تحديث</button>
    </div>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="notice" class="notice">{{ notice }}</p>

    <table class="list" v-if="items.length">
      <thead><tr><th>الموظف</th><th>الدوام</th><th>الحالة</th><th>الحضور</th><th>الانصراف</th><th>الداشبورد</th><th></th></tr></thead>
      <tbody>
        <tr v-for="item in items" :key="item.employee.id" :class="{ rejected: item.error }">
          <td><b>{{ item.employee.name }}</b><div class="muted small">{{ item.employee.job_title }}</div></td>
          <td>{{ scheduleLabel(item.employee) }}</td>
          <td>{{ statusLabel(item) }}</td>
          <td>{{ item.checked_in_at ? time(item.checked_in_at) : '-' }}</td>
          <td>{{ item.checked_out_at ? time(item.checked_out_at) : '-' }}</td>
          <td>
            <span v-if="item.error" class="pill danger" :title="item.error">مرفوض: {{ item.error }}</span>
            <span v-else-if="item.pending" class="pill warn">في الانتظار</span>
            <span v-else-if="item.checked_in_at" class="pill ok">تم الإرسال</span>
          </td>
          <td class="punch">
            <template v-if="!item.checked_in_at && item.status !== 'preapproved_leave'">
              <template v-if="item.employee.schedule_type === 'part_time'">
                <button class="primary small" :disabled="busy" @click="punch(item, 'check_in', 'part_time')">حضور</button>
              </template>
              <template v-else>
                <button class="primary small" :disabled="busy" @click="punch(item, 'check_in', 'morning')">حضور صباحي</button>
                <button class="primary small" :disabled="busy" @click="punch(item, 'check_in', 'evening')">حضور مسائي</button>
              </template>
            </template>
            <button v-else-if="item.checked_in_at && !item.checked_out_at" class="strong small" :disabled="busy" @click="punch(item, 'check_out')">انصراف</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="muted center">لا يوجد موظفين لهذا الفرع على الجهاز. اتصل بالإنترنت مرة لتحديث القائمة، أو أضف الموظفين من الداشبورد.</p>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { call, time } from '../api.js';

const emit = defineEmits(['changed']);
const day = ref('');
const shifts = ref([]);
const items = ref([]);
const busy = ref(false);
const error = ref('');
const notice = ref('');
let timer = null;

const shiftName = (shift) => ({ morning: 'صباحي', evening: 'مسائي' }[shift] || shift);

function scheduleLabel(employee) {
  if (employee.schedule_type === 'part_time') return `دوام جزئي ${employee.attendance_start || ''} - ${employee.attendance_end || ''}`;
  return 'مواعيد الفرع';
}

function statusLabel(item) {
  return { unrecorded: 'لم يسجل', morning: 'صباحي', evening: 'مسائي', part_time: 'دوام جزئي', unauthorized_absence: 'غياب',
    preapproved_leave: 'إجازة' }[item.status] || item.status;
}

async function load() {
  error.value = '';
  try {
    const result = await call('attendance:board');
    day.value = result.day;
    shifts.value = result.shifts;
    items.value = result.items;
  } catch (problem) {
    error.value = problem.message;
  }
}

async function punch(item, action, status) {
  const what = action === 'check_in' ? 'حضور' : 'انصراف';
  if (!confirm(`تسجيل ${what} ${item.employee.name} الآن؟ لا يمكن تعديل التسجيل بعد كده.`)) return;
  busy.value = true;
  error.value = '';
  notice.value = '';
  try {
    const entry = await call('attendance:punch', { employee_id: item.employee.id, action, status });
    notice.value = `تم تسجيل ${what} ${item.employee.name} الساعة ${time(entry.created_at)}.`;
    await load();
    emit('changed');
  } catch (problem) {
    error.value = problem.message;
  } finally {
    busy.value = false;
  }
}

onMounted(() => { load(); timer = setInterval(load, 30000); });
onUnmounted(() => clearInterval(timer));
</script>
