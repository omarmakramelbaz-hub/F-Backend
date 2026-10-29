<template>
  <div class="center-page">
    <form class="card narrow" @submit.prevent="submit">
      <h1>تسجيل الدخول</h1>
      <p class="muted">يعمل بدون إنترنت بحسابات الداشبورد الموجودة على هذا الجهاز.</p>
      <label>رقم الموبايل أو البريد<input v-model.trim="login" autocomplete="username" required autofocus></label>
      <label>كلمة المرور<input v-model="password" type="password" autocomplete="current-password" required></label>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="primary wide" :disabled="busy">{{ busy ? 'جاري التحقق…' : 'دخول' }}</button>
      <div class="login-foot">
        <SyncBadge :status="sync" @sync-now="$emit('sync-now')" />
        <span class="muted small">{{ info.device.name }} · {{ branchNames }}</span>
      </div>
      <template v-if="sync.device_revoked">
        <p class="error">تم إيقاف هذا الجهاز من السرفر. الفواتير المحفوظة على الجهاز لن تُحذف، ويمكنك تجهيزه من جديد بحساب مسموح له.</p>
        <button type="button" class="ghost" @click="reset">تجهيز الجهاز من جديد</button>
      </template>
    </form>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { call } from '../api.js';
import SyncBadge from '../components/SyncBadge.vue';

const props = defineProps({ info: Object, sync: Object });
const emit = defineEmits(['signed-in', 'sync-now', 'reset']);
const login = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);
const branchNames = computed(() => props.info.branches.map((branch) => branch.name).join('، '));

async function reset() {
  if (!confirm('تجهيز الجهاز من جديد يحتاج إنترنت وحساب من الداشبورد. متابعة؟')) return;
  try {
    await call('setup:reset');
    emit('reset');
  } catch (problem) {
    error.value = problem.message;
  }
}

async function submit() {
  error.value = '';
  busy.value = true;
  try {
    await call('auth:login', { login: login.value, password: password.value });
    password.value = '';
    emit('signed-in');
  } catch (problem) {
    error.value = problem.message;
  } finally {
    busy.value = false;
  }
}
</script>
