<template>
  <!-- The dashboard's sign-in hologram (go-login-hologram.js): the GO portrait, brought to life by the dashboard's own
       engine, its eyes following the pointer. Without WebGL the portrait stays as a still picture. -->
  <section class="dashboard-login-identity" aria-label="هولوجرام GO">
    <div ref="host" class="go-login-hologram" aria-hidden="true">
      <img data-hologram-artwork :src="artwork" alt="" width="1454" height="1082" decoding="async" fetchpriority="high">
      <canvas ref="canvas"></canvas>
    </div>
  </section>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import '../vendor/go-hologram.js';
import artwork from '../assets/go-hologram-premium-20261110.png';

const host = ref(null);
const canvas = ref(null);
let robot = null;

function gaze(event) {
  if (!robot || document.hidden || event.pointerType === 'touch' || !robot.setGaze) return;
  const width = Math.max(1, document.documentElement.clientWidth);
  const height = Math.max(1, window.innerHeight);
  const x = (event.clientX - document.documentElement.getBoundingClientRect().left) / width * 2 - 1;
  robot.setGaze(Math.max(-1, Math.min(1, x)), Math.max(-1, Math.min(1, event.clientY / height * 2 - 1)));
}
function rest() {
  if (robot && robot.setGaze) robot.setGaze(0, 0);
}
function visibility() {
  if (robot && robot.setVisible) robot.setVisible(!document.hidden);
  if (document.hidden) rest();
}

onMounted(() => {
  try {
    robot = window.GoHologram ? window.GoHologram.mount(canvas.value, { artwork: true, compact: false, background: true, fullViewport: true, interactive: false }) : null;
  } catch {
    robot = null;
  }
  host.value.dataset.loginRenderer = robot ? 'living' : 'fallback';
  if (!robot) return;
  document.addEventListener('pointermove', gaze, { passive: true });
  document.addEventListener('pointerleave', rest);
  document.addEventListener('visibilitychange', visibility);
  visibility();
});

onUnmounted(() => {
  document.removeEventListener('pointermove', gaze);
  document.removeEventListener('pointerleave', rest);
  document.removeEventListener('visibilitychange', visibility);
  if (robot) robot.destroy();
  robot = null;
});
</script>
