<template>
  <div v-if="!info" class="splash" v-html="loginLogo"></div>
  <SetupView v-else-if="!info.device" :server="info.server" @done="refresh" />
  <LoginView v-else-if="!info.session" :info="info" :sync="sync" @signed-in="afterSignIn" @sync-now="syncNow" @reset="refresh" />

  <!-- Under the live dashboard (same pages and permissions as the server): a status bar -->
  <footer v-else-if="dashboardShown" class="status-bar">
    <span class="status-brand">GO ERP</span>
    <SyncBadge :status="sync" @sync-now="syncNow" />
    <span v-if="offlineMode" class="status-offline" role="status" title="النت مقطوع: صفحات الداشبورد المحفوظة والكاشير شغالين من الجهاز، وكل حاجة هتتزامن لما النت يرجع."><i class="fas fa-plug" aria-hidden="true"></i> بدون إنترنت — شغال من الجهاز</span>
    <span class="status-who">{{ currentBranch.name }} · {{ info.session.user.name }}</span>
    <span v-if="statusNotice" class="status-notice" role="status" :title="statusNotice">{{ statusNotice }}</span>
    <span v-if="printProblem" class="status-error" role="alert" :title="printProblem">{{ printProblem }}</span>
    <span class="spacer"></span>
    <button type="button" @click="openSettings" title="الطابعة والمزامنة وإعادة تجهيز الجهاز"><i class="fas fa-cog" aria-hidden="true"></i> إعدادات البرنامج</button>
    <button type="button" @click="logout"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> خروج</button>
  </footer>

  <!-- The dashboard's own sign-out: this covers it until the app's sign-in screen opens (the dashboard's never shows) -->
  <div v-else-if="signingOut" class="splash opening" role="status">
    <div v-html="loginLogo"></div>
    <p>جاري تسجيل الخروج…</p>
  </div>

  <!-- The dashboard opening after signing in: shown once its first page is there -->
  <div v-else-if="opening" class="splash opening" role="status">
    <div v-html="loginLogo"></div>
    <p>جاري فتح الداشبورد…</p>
  </div>

  <!-- The app's own settings (printer, sync, setting the device up again), opened from the bar under the dashboard -->
  <div v-else-if="settingsOnly" class="app-settings">
    <header class="app-settings-head">
      <h1><i class="fas fa-cog" aria-hidden="true"></i> إعدادات البرنامج</h1>
      <span class="spacer"></span>
      <span class="status-who">{{ currentBranch.name }} · {{ info.session.user.name }}</span>
      <button type="button" class="primary" @click="backToDashboard"><i class="fas fa-arrow-right" aria-hidden="true"></i> رجوع للداشبورد</button>
    </header>
    <main class="content">
      <SettingsView :info="info" :sync="sync" :sync-now="syncNow" @reset="refresh" />
    </main>
  </div>

  <!-- A stand-in cashier in the dashboard's look: only when the dashboard cannot open at all (no internet, and this
       account's pages were never saved on this device) -->
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
      <SettingsView v-else :info="info" :sync="sync" :sync-now="syncNow" @reset="refresh" />
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
// The GO mark of the dashboard's opening, on the app's loading screens.
import loginLogo from './assets/go-hud-mark.svg?raw';
import sidebarLogo from './assets/go-erp-sidebar.svg?raw';

const info = ref(null);
const sync = ref({});
const view = ref('pos');
const dashboardShown = ref(false);
const settingsOnly = ref(false);
const opening = ref(false);
const signingOut = ref(false);
const banner = ref(null);
const printProblem = ref('');
const offlineMode = ref(false);
const statusNotice = ref('');
let printTimer = null;
let noticeTimer = null;
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
  offlineMode.value = !!info.value.offline_mode;
}

function notice(text) {
  statusNotice.value = text;
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(() => { statusNotice.value = ''; }, 9000);
}

async function refreshSync() {
  sync.value = await call('sync:status');
}

async function syncNow() {
  try { sync.value = await call('sync:now'); } catch (error) { sync.value = { ...sync.value, last_error: error.message }; }
  info.value = await call('app:info');
}

/**
 * After signing in the dashboard opens: live with the internet, and from this account's pages saved on the device
 * without it. Only an account that never opened the dashboard online on this device starts on the device's screens.
 */
async function afterSignIn() {
  await refresh();
  settingsOnly.value = false;
  view.value = 'pos';
  if (!info.value.embed) return;
  if (!(await openDashboard())) banner.value = noCopyBanner();
}

async function openDashboard(page) {
  banner.value = null;
  try { return await call('dashboard:open', page ? { page } : {}); } catch { return false; /* signed out meanwhile */ }
}

function noCopyBanner() {
  return { kind: 'offline', text: 'مفيش إنترنت، وصفحات الداشبورد لسه متحفظتش على الجهاز للحساب ده (بتتحفظ أول مرة الحساب يفتح والنت شغال). لحد ما النت يرجع اشتغل من الكاشير البديل ده، وكل حاجة هتتزامن مع الداشبورد لوحدها.', back: false };
}

/**
 * The app's settings (this device's setup, open orders, printers) live on the dashboard's «برنامج الكمبيوتر» page;
 * only when that page cannot open (no internet, never saved) the app shows its own copy of them.
 */
async function openSettings() {
  banner.value = null;
  if (await openDashboard('program')) return;
  await call('dashboard:hide');
  view.value = 'settings';
  settingsOnly.value = true;
}

async function backToDashboard() {
  settingsOnly.value = false;
  view.value = 'pos';
  if (!(await openDashboard())) banner.value = info.value.dashboard_copy ? offlineBanner() : noCopyBanner();
}

function offlineBanner() {
  return { kind: 'offline', text: 'مفيش إنترنت دلوقتي، والصفحة دي مش محفوظة على الجهاز — كمّل من الكاشير البديل ده، وكل حاجة هتتزامن مع الداشبورد لوحدها لما النت يرجع.', back: false };
}

/** The internet dropped: the dashboard keeps working from the device; the device's screens show a notice. */
async function goOffline() {
  offlineMode.value = true;
  if (!info.value || !info.value.session) return;
  call('app:network', { online: false }).catch(() => {});
  if (!dashboardShown.value) banner.value = offlineBanner();
}

/** The dashboard window could not show its page at all: the device's own screens take over. */
async function dashboardUnavailable() {
  opening.value = false;
  if (dashboardShown.value) await call('dashboard:hide');
  settingsOnly.value = false;
  view.value = 'pos';
  banner.value = info.value && info.value.session && !info.value.dashboard_copy ? noCopyBanner() : offlineBanner();
}

async function changeBranch(value) {
  await call('auth:branch', { branch: value });
  await refresh();
}

async function logout() {
  await call('auth:logout');
  banner.value = null;
  settingsOnly.value = false;
  opening.value = false;
  await refresh();
  signingOut.value = false;
  view.value = 'pos';
}

onMounted(async () => {
  await refresh();
  stops.push(onSyncStatus((status) => {
    const wasOnline = sync.value.online;
    sync.value = status;
    if (!info.value || !info.value.session) return;
    if (status.online === true && wasOnline === false && !dashboardShown.value && info.value.embed) {
      banner.value = { kind: 'online', text: 'النت رجع وكل العمليات اتبعتت للداشبورد.', back: true };
    }
  }));
  stops.push(onDashboardEvent((event) => {
    if (event.type === 'opening') opening.value = true;
    else if (event.type === 'signing-out') {
      signingOut.value = true;
      dashboardShown.value = false;
      opening.value = false;
    }
    else if (event.type === 'shown') {
      dashboardShown.value = true;
      settingsOnly.value = false;
      opening.value = false;
      signingOut.value = false;
    }
    else if (event.type === 'hidden') dashboardShown.value = false;
    else if (event.type === 'connection') {
      offlineMode.value = !event.online;
      if (event.online && dashboardShown.value) notice('النت رجع — الداشبورد بيتحدث، والعمليات اللي اتعملت من غير نت بتتبعت.');
    }
    else if (event.type === 'unavailable') dashboardUnavailable();
    else if (event.type === 'missing-page') notice('الصفحة دي محتاجة إنترنت أول مرة، ففتحنا صفحة محفوظة على الجهاز.');
    else if (event.type === 'signed-out') logout();
    else if (event.type === 'device-reset') {
      // Set up again from the «برنامج الكمبيوتر» page: the setup screen opens.
      settingsOnly.value = false;
      banner.value = null;
      refresh();
    }
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
