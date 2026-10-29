<template>
  <div class="center-page">
    <form class="card narrow" @submit.prevent="submit">
      <h1>تجهيز الجهاز</h1>
      <p class="muted">مرة واحدة فقط وبإنترنت: سجّل بأي حساب من الداشبورد (الأونر أو الأدمن أو حساب الفرع). بعدها تعمل كل حسابات الفرع على هذا الجهاز بدون إنترنت.</p>
      <template v-if="!branches">
        <label>رقم الموبايل أو البريد<input v-model.trim="login" autocomplete="username" required autofocus></label>
        <label>كلمة المرور<input v-model="password" type="password" autocomplete="current-password" required></label>
        <label>اسم الجهاز<input v-model.trim="deviceName" placeholder="مثال: كاشير فرع المعادي" maxlength="100"></label>
      </template>
      <template v-else>
        <p><b>اختر فروع هذا الجهاز:</b></p>
        <label v-for="branch in branches" :key="branch.value" class="check">
          <input type="checkbox" :value="branch.value" v-model="chosen"> {{ branch.name }}
        </label>
      </template>
      <p v-if="error" class="error">{{ error }}</p>
      <button class="primary wide" :disabled="busy">{{ busy ? 'جاري التجهيز وتنزيل البيانات…' : (branches ? 'تأكيد الفروع' : 'تجهيز الجهاز') }}</button>
      <p class="muted small">السرفر: {{ server }}</p>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { call } from '../api.js';

defineProps({ server: String });
const emit = defineEmits(['done']);
const login = ref('');
const password = ref('');
const deviceName = ref('');
const branches = ref(null);
const chosen = ref([]);
const error = ref('');
const busy = ref(false);

async function submit() {
  error.value = '';
  if (branches.value && !chosen.value.length) { error.value = 'اختر فرعًا واحدًا على الأقل.'; return; }
  busy.value = true;
  try {
    const result = await call('setup:enroll', { login: login.value, password: password.value, device_name: deviceName.value,
      branches: branches.value ? [...chosen.value] : null });
    if (result.choose) {
      branches.value = result.choose;
      chosen.value = result.choose.length === 1 ? [result.choose[0].value] : [];
    } else {
      emit('done');
    }
  } catch (problem) {
    error.value = problem.message;
  } finally {
    busy.value = false;
  }
}
</script>
