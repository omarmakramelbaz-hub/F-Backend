<template>
  <button class="sync-badge" :class="state" @click="$emit('sync-now')" :title="title">
    <span class="dot"></span>
    <span>{{ label }}</span>
    <span v-if="status.pending" class="count">{{ status.pending }} في الانتظار</span>
    <span v-if="status.rejected" class="count danger">{{ status.rejected }} مرفوضة</span>
  </button>
</template>

<script setup>
import { computed } from 'vue';
import { since } from '../api.js';

const props = defineProps({ status: { type: Object, default: () => ({}) } });
defineEmits(['sync-now']);

const state = computed(() => {
  if (props.status.device_revoked) return 'revoked';
  if (props.status.running) return 'running';
  if (props.status.online === false) return 'offline';
  return props.status.online ? 'online' : 'unknown';
});
const label = computed(() => ({
  revoked: 'الجهاز موقوف', running: 'جاري المزامنة…', offline: 'بدون إنترنت', online: 'متصل', unknown: 'جاري الاتصال…',
}[state.value]));
const title = computed(() => `آخر مزامنة: ${since(props.status.last_sync_at)}${props.status.last_error ? '\n' + props.status.last_error : ''}\nاضغط للمزامنة الآن`);
</script>
