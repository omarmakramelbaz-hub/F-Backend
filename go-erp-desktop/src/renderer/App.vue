<template>
  <div v-if="!info" class="splash" v-html="loginLogo"></div>
  <SetupView v-else-if="!info.device" :server="info.server" @done="refresh" />
  <LoginView v-else-if="!info.session" :info="info" :sync="sync" @signed-in="afterSignIn" @sync-now="syncNow" @reset="refresh" />

  <!-- Under the live dashboard (same pages and permissions as the server): a status bar -->
  <footer v-else-if="dashboardShown" class="status-bar">
    <span class="status-brand">GO ERP</span>
    <SyncBadge :status="sync" @sync-now="syncNow" />
    <span class="status-who">{{ currentBranch.name }} · {{ info.session.user.name }}</span>
    <span v-if="printProblem" class="status-error" role="alert">{{ printProblem }}</span>
    <span class="spacer"></span>
    <button type="button" @click="useDevice"><i class="fas fa-desktop" aria-hidden="true"></i> شاشات الجهاز (بدون إنترنت)</button>
    <button type="button" @click="logout"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> خروج</button>
  </footer>

  <!-- The device's own screens, in the dashboard's look: used offline, or when picked -->
  <div v-else class="shell">
    <aside class="side">
      <div class="side-brand" v-html="sidebarLogo"></div>
      <div class="side-user">
        <i class="fas fa-user" aria-hidden="true"></i>
        <div><b>{{ info.session.user.name }}</b><small>{{ currentBranch.name }}</small></div>
      </div>
      <select v-if="userBranches.length > 1" class="side-branch" :value="info.session.branch" @change="changeBranch($event.target.value)">
        <option v-for="branch in userBranches" :key="branch.value" :value="branch.value">{{ branch.name }}</option>
      </select>
      <nav class="side-nav">
        <button v-for="tab in tabs" :key="tab.id" type="button" :class="{ active: view === tab.id }" @click="view = tab.id">
          <i class="fas" :class="tab.icon" aria-hidden="true"></i><span>{{ tab.label }}</span>
        </button>
        <button v-if="info.embed" type="button" class="side-dashboard" :disabled="sync.online === false" @click="openDashboard()"
          :title="sync.online === false ? 'الداشبورد يحتاج إنترنت' : 'كل صفحات الداشبورد بنفس الصلاحيات'">
          <i class="fas fa-tachometer-alt" aria-hidden="true"></i><span>الداشبورد</span>
        </button>
      </nav>
      <div class="side-foot">
        <SyncBadge :status="sync" @sync-now="syncNow" />
        <button type="button" class="side-logout" @click="logout"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> خروج</button>
      </div>
    </aside>
    <main class="content">
      <div v-if="banner" class="mode-banner" :class="banner.kind" role="status">
        <i class="fas" :class="banner.kind === 'offline' ? 'fa-wifi' : 'fa-sync-alt'" aria-hidden="true"></i>
        <span>{{ banner.text }}</span>
        <button v-if="banner.back" type="button" @click="openDashboard()">رجوع للداشبورد</button>
      </div>
      <PosView v-if="view === 'pos'" :key="info.session.branch" :session="info.session" :branch="currentBranch" @changed="refreshSync" />
      <ReceiptsView v-else-if="view === 'receipts'" :key="'r' + info.session.branch" :session="info.session" :branch="currentBranch" />
      <CashView v-else-if="view === 'cash'" :key="'c' + info.session.branch" :session="info.session" @changed="refreshSync" />
      <AttendanceView v-else-if="view === 'attendance'" :key="'a' + info.session.branch" @changed="refreshSync" />
      <ReportsView v-else-if="view === 'reports'" :key="'t' + info.session.branch" />
      <SettingsView v-else :info="info" :sync="sync" :sync-now="syncNow" />
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { call, onSyncStatus, onDashboardEvent } from './api.js';
import SetupView from './views/SetupView.vue';
import LoginView from './views/LoginView.vue';
import PosView from './views/PosView.vue';
import ReceiptsView from './views/ReceiptsView.vue';
import CashView from './views/CashView.vue';
import AttendanceView from './views/AttendanceView.vue';
import ReportsView from './views/ReportsView.vue';
import SettingsView from './views/SettingsView.vue';
import SyncBadge from './components/SyncBadge.vue';
import loginLogo from './assets/go-erp-login.svg?raw';
import sidebarLogo from './assets/go-erp-sidebar.svg?raw';

const info = ref(null);
const sync = ref({});
const view = ref('pos');
const dashboardShown = ref(false);
const banner = ref(null);
const printProblem = ref('');
let printTimer = null;
const stops = [];

// Reports are for the owner and managers, like the dashboard's reports.
const tabs = computed(() => [
  { id: 'pos', label: 'الكاشير', icon: 'fa-cash-register' },
  { id: 'receipts', label: 'فواتير اليوم', icon: 'fa-receipt' },
  { id: 'cash', label: 'الخزنة والمصروفات', icon: 'fa-wallet' },
  { id: 'attendance', label: 'الحضور', icon: 'fa-user-clock' },
  ...(info.value && info.value.session && info.value.session.user.can_manage ? [{ id: 'reports', label: 'التقارير', icon: 'fa-chart-bar' }] : []),
  { id: 'settings', label: 'الإعدادات والمزامنة', icon: 'fa-cog' },
]);
const userBranches = computed(() => info.value.branches.filter((branch) => info.value.session.user.branches.includes(branch.value)));
const currentBranch = computed(() => info.value.branches.find((branch) => branch.value === info.value.session.branch) || { name: '' });

async function refresh() {
  info.value = await call('app:info');
  sync.value = info.value.sync;
  dashboardShown.value = !!info.value.dashboard_shown;
}

async function refreshSync() {
  sync.value = await call('sync:status');
}

async function syncNow() {
  try { sync.value = await call('sync:now'); } catch (error) { sync.value = { ...sync.value, last_error: error.message }; }
  info.value = await call('app:info');
}

/** After signing in, the live dashboard opens when the internet is there; otherwise the device's screens. */
async function afterSignIn() {
  await refresh();
  view.value = 'pos';
  if (info.value.embed && sync.value.online !== false && navigator.onLine) await openDashboard();
  else if (info.value.embed) banner.value = offlineBanner();
}

async function openDashboard(page) {
  banner.value = null;
  try { await call('dashboard:open', page ? { page } : {}); } catch { /* signed out meanwhile */ }
}

async function useDevice() {
  await call('dashboard:hide');
  banner.value = { kind: 'info', text: 'شاشات الجهاز شغالة بإنترنت أو من غيره، وكل اللي بيتسجل هنا بيتزامن مع الداشبورد.', back: true };
}

function offlineBanner() {
  return { kind: 'offline', text: 'مفيش إنترنت دلوقتي — كمّل شغلك من شاشات الجهاز، وكل حاجة هتتزامن مع الداشبورد لوحدها لما النت يرجع.', back: false };
}

/** The internet dropped: keep working on the device's screens. */
async function goOffline() {
  if (!info.value || !info.value.session) return;
  if (dashboardShown.value) await call('dashboard:hide');
  view.value = 'pos';
  banner.value = offlineBanner();
}

async function changeBranch(value) {
  await call('auth:branch', { branch: value });
  await refresh();
}

async function logout() {
  await call('auth:logout');
  banner.value = null;
  await refresh();
  view.value = 'pos';
}

onMounted(async () => {
  await refresh();
  stops.push(onSyncStatus((status) => {
    const wasOnline = sync.value.online;
    sync.value = status;
    if (!info.value || !info.value.session) return;
    if (status.online === false && dashboardShown.value) goOffline();
    else if (status.online === true && wasOnline === false && !dashboardShown.value && info.value.embed) {
      banner.value = { kind: 'online', text: 'النت رجع وكل العمليات اتبعتت للداشبورد.', back: true };
    }
  }));
  stops.push(onDashboardEvent((event) => {
    if (event.type === 'shown') dashboardShown.value = true;
    else if (event.type === 'hidden') dashboardShown.value = false;
    else if (event.type === 'offline') goOffline();
    else if (event.type === 'signed-out') logout();
    else if (event.type === 'print-error') {
      printProblem.value = event.error;
      clearTimeout(printTimer);
      printTimer = setTimeout(() => { printProblem.value = ''; }, 12000);
    }
  }));
  const offline = () => goOffline();
  window.addEventListener('offline', offline);
  stops.push(() => window.removeEventListener('offline', offline));
});
onUnmounted(() => stops.forEach((stop) => stop()));
</script>
