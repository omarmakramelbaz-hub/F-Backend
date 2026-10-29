<template>
  <div v-if="!info" class="splash">GO ERP</div>
  <SetupView v-else-if="!info.device" :server="info.server" @done="refresh" />
  <LoginView v-else-if="!info.session" :info="info" :sync="sync" @signed-in="refresh" @sync-now="syncNow" @reset="refresh" />
  <div v-else class="shell">
    <header class="topbar">
      <div class="brand">GO ERP</div>
      <nav class="tabs">
        <button v-for="tab in tabs" :key="tab.id" :class="{ active: view === tab.id }" @click="view = tab.id">{{ tab.label }}</button>
        <button class="online-dashboard" @click="openDashboard" title="يفتح الداشبورد على الإنترنت داخل البرنامج">الداشبورد الكامل</button>
      </nav>
      <div class="session">
        <select v-if="userBranches.length > 1" :value="info.session.branch" @change="changeBranch($event.target.value)">
          <option v-for="branch in userBranches" :key="branch.value" :value="branch.value">{{ branch.name }}</option>
        </select>
        <span v-else class="branch-name">{{ currentBranch.name }}</span>
        <span class="user">{{ info.session.user.name }}</span>
        <SyncBadge :status="sync" @sync-now="syncNow" />
        <button class="ghost" @click="logout">خروج</button>
      </div>
    </header>
    <main class="content">
      <PosView v-if="view === 'pos'" :key="info.session.branch" :session="info.session" :branch="currentBranch" @changed="refreshSync" />
      <ReceiptsView v-else-if="view === 'receipts'" :key="'r' + info.session.branch" :session="info.session" :branch="currentBranch" />
      <SettingsView v-else :info="info" :sync="sync" :sync-now="syncNow" />
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { call, onSyncStatus } from './api.js';
import SetupView from './views/SetupView.vue';
import LoginView from './views/LoginView.vue';
import PosView from './views/PosView.vue';
import ReceiptsView from './views/ReceiptsView.vue';
import SettingsView from './views/SettingsView.vue';
import SyncBadge from './components/SyncBadge.vue';

const info = ref(null);
const sync = ref({});
const view = ref('pos');
const tabs = [{ id: 'pos', label: 'الكاشير' }, { id: 'receipts', label: 'فواتير اليوم' }, { id: 'settings', label: 'الإعدادات والمزامنة' }];
let stop = null;

const userBranches = computed(() => info.value.branches.filter((branch) => info.value.session.user.branches.includes(branch.value)));
const currentBranch = computed(() => info.value.branches.find((branch) => branch.value === info.value.session.branch) || { name: '' });

async function refresh() {
  info.value = await call('app:info');
  sync.value = info.value.sync;
}

async function refreshSync() {
  sync.value = await call('sync:status');
}

async function syncNow() {
  try { sync.value = await call('sync:now'); } catch (error) { sync.value = { ...sync.value, last_error: error.message }; }
  info.value = await call('app:info');
}

async function changeBranch(value) {
  await call('auth:branch', { branch: value });
  await refresh();
}

async function logout() {
  await call('auth:logout');
  await refresh();
  view.value = 'pos';
}

function openDashboard() {
  call('dashboard:open').catch(() => {});
}

onMounted(async () => {
  await refresh();
  stop = onSyncStatus((status) => { sync.value = status; });
});
onUnmounted(() => stop && stop());
</script>
