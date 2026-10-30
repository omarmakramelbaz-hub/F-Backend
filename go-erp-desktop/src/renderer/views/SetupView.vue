<template>
  <div class="login-page dashboard-login-page">
    <main class="dashboard-login" aria-labelledby="setup-title">
      <section class="dashboard-login-identity" aria-label="فسخانستا وجو">
        <div class="dashboard-login-brand" v-html="logo"></div>
        <p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
        <p class="dashboard-login-brand-subtitle">برنامج الكمبيوتر — يشتغل من غير إنترنت ويتزامن مع الداشبورد كل ٣٠ ثانية</p>
      </section>

      <section class="dashboard-login-form-panel">
        <span class="dashboard-login-eyebrow">برنامج الكمبيوتر</span>
        <h1 id="setup-title">تجهيز الجهاز</h1>
        <p class="dashboard-login-description">مرة واحدة فقط وبإنترنت: سجّل بأي حساب من الداشبورد (الأونر أو الأدمن أو حساب الفرع). بعدها تدخل كل حسابات الفرع على الجهاز ده من غير إنترنت.</p>

        <div v-if="error" class="dashboard-login-alert dashboard-login-alert-error" role="alert">
          <i class="fas fa-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span>
        </div>

        <form class="dashboard-login-form" autocomplete="off" @submit.prevent="submit">
          <template v-if="!branches">
            <div class="dashboard-login-field">
              <label for="setup-login">البريد الإلكتروني أو رقم الموبايل</label>
              <div class="dashboard-login-input-wrap">
                <i class="fas fa-user" aria-hidden="true"></i>
                <input id="setup-login" v-model.trim="login" type="text" autocapitalize="none" spellcheck="false"
                  placeholder="أدخل بريدك الإلكتروني أو رقم الموبايل" required autofocus>
              </div>
            </div>
            <div class="dashboard-login-field">
              <label for="setup-password">كلمة المرور</label>
              <div class="dashboard-login-input-wrap">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <input id="setup-password" v-model="password" :type="showPassword ? 'text' : 'password'" placeholder="ادخل كلمة المرور" required>
                <button type="button" class="dashboard-password-toggle" @click="showPassword = !showPassword"
                  :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"><i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i></button>
              </div>
            </div>
            <div class="dashboard-login-field">
              <label for="setup-name">اسم الجهاز</label>
              <div class="dashboard-login-input-wrap">
                <i class="fas fa-desktop" aria-hidden="true"></i>
                <input id="setup-name" v-model.trim="deviceName" maxlength="100" placeholder="مثال: كاشير فرع المنصورة">
              </div>
            </div>
          </template>
          <template v-else>
            <p class="setup-choose">اختر فروع هذا الجهاز:</p>
            <label v-for="branch in branches" :key="branch.value" class="dashboard-login-remember setup-branch">
              <input type="checkbox" :value="branch.value" v-model="chosen"> {{ branch.name }}
            </label>
          </template>
          <button class="dashboard-login-submit" :disabled="busy">
            <span>{{ busy ? 'جاري التجهيز وتنزيل البيانات…' : (branches ? 'تأكيد الفروع' : 'تجهيز الجهاز') }}</span>
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
          </button>
        </form>
        <p class="login-device"><span>السرفر: <bdi>{{ server }}</bdi></span></p>
      </section>
    </main>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { call } from '../api.js';
import logo from '../assets/go-erp-login.svg?raw';

defineProps({ server: String });
const emit = defineEmits(['done']);
const login = ref('');
const password = ref('');
const showPassword = ref(false);
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
