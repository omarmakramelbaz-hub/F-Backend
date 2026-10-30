<template>
  <div class="login-page dashboard-login-page">
    <main class="dashboard-login" aria-labelledby="dashboard-login-title">
      <section class="dashboard-login-identity" aria-label="فسخانستا وجو">
        <div class="dashboard-login-brand" v-html="logo"></div>
        <p class="dashboard-login-brand-title go-erp-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
        <p class="dashboard-login-brand-subtitle">لوحة واحدة لإدارة التطبيقات والطلبات</p>
      </section>

      <section class="dashboard-login-form-panel">
        <span class="dashboard-login-eyebrow">لوحة التحكم</span>
        <h1 id="dashboard-login-title">الدخول إلى لوحة التحكم</h1>
        <p class="dashboard-login-description">أدخل بيانات حسابك للمتابعة</p>

        <div v-if="error" class="dashboard-login-alert dashboard-login-alert-error" role="alert">
          <i class="fas fa-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span>
        </div>
        <div v-if="sync.device_revoked" class="dashboard-login-alert dashboard-login-alert-error" role="alert">
          <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
          <span>تم إيقاف هذا الجهاز من السرفر. الفواتير المحفوظة على الجهاز لن تُحذف.
            <button type="button" class="login-link" @click="reset">تجهيز الجهاز من جديد</button></span>
        </div>

        <form class="dashboard-login-form" autocomplete="off" @submit.prevent="submit">
          <div class="dashboard-login-field">
            <label for="dashboard-login-email">البريد الإلكتروني أو رقم الموبايل</label>
            <div class="dashboard-login-input-wrap">
              <i class="fas fa-user" aria-hidden="true"></i>
              <input id="dashboard-login-email" v-model.trim="login" type="text" autocapitalize="none" spellcheck="false"
                placeholder="أدخل بريدك الإلكتروني أو رقم الموبايل" required autofocus>
            </div>
          </div>
          <div class="dashboard-login-field">
            <label for="dashboard-login-password">كلمة المرور</label>
            <div class="dashboard-login-input-wrap">
              <i class="fas fa-lock" aria-hidden="true"></i>
              <input id="dashboard-login-password" v-model="password" :type="showPassword ? 'text' : 'password'"
                placeholder="ادخل كلمة المرور" required>
              <button type="button" class="dashboard-password-toggle" :aria-label="showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"
                @click="showPassword = !showPassword"><i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i></button>
            </div>
          </div>
          <button class="dashboard-login-submit" :disabled="busy">
            <span>{{ busy ? 'جاري التحقق…' : 'تسجيل دخول' }}</span><i class="fas fa-arrow-left" aria-hidden="true"></i>
          </button>
        </form>

        <div class="login-device">
          <SyncBadge :status="sync" @sync-now="$emit('sync-now')" />
          <span>{{ info.device.name }} · {{ branchNames }}</span>
        </div>
      </section>
    </main>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { call } from '../api.js';
import SyncBadge from '../components/SyncBadge.vue';
import logo from '../assets/go-erp-login.svg?raw';

const props = defineProps({ info: Object, sync: Object });
const emit = defineEmits(['signed-in', 'sync-now', 'reset']);
const login = ref('');
const password = ref('');
const showPassword = ref(false);
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
