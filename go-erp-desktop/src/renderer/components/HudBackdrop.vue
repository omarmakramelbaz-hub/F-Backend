<template>
  <!-- The dashboard's GO opening behind the app's sign-in and setup screens: its live background, the same hologram
       (the dashboard's own engine), and the GO mark. -->
  <div class="hud-backdrop" aria-hidden="true">
    <canvas ref="lines" class="hud-backdrop-lines"></canvas>
    <div class="hud-backdrop-figure">
      <canvas v-show="live" ref="figure"></canvas>
      <span v-if="!live" class="hud-backdrop-still" v-html="mark"></span>
    </div>
    <span class="hud-backdrop-brand" v-html="mark"></span>
    <p class="hud-backdrop-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import '../vendor/go-hologram.js';
import mark from '../assets/go-hud-mark.svg?raw';

const lines = ref(null);
const figure = ref(null);
const live = ref(true);
let robot = null;
let frame = 0;
let last = 0;
let stopped = false;
const reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

// The opening's moving background (home-hud.js): light lines flowing to the centre, with a mote on each.
function draw(now) {
  frame = 0;
  const canvas = lines.value;
  if (stopped || !canvas || document.hidden) return;
  if (!last || now - last > 50) {
    last = now;
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    const t = reduce && reduce.matches ? 0 : now / 1000;
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
    }
    const c = canvas.getContext('2d');
    c.clearRect(0, 0, width, height);
    c.lineWidth = 0.7;
    for (let i = 0; i < 18; i++) {
      const fromLeft = i % 2 === 0;
      const x0 = fromLeft ? -20 : width + 20;
      const y0 = (i / 18) * height;
      const y1 = height * 0.54 + Math.sin(t * 0.25 + i) * height * 0.03;
      const x1 = width * (fromLeft ? 0.36 : 0.64);
      const x2 = width * (fromLeft ? 0.25 : 0.75);
      const y2 = y0 + Math.sin(i * 2 + t * 0.14) * height * 0.16;
      c.strokeStyle = 'rgba(37,189,225,.15)';
      c.beginPath();
      c.moveTo(x0, y0);
      c.bezierCurveTo(x1, y2, x2, y1, width * 0.5, height * 0.7);
      c.stroke();
      const p = (t * 0.025 + i / 18) % 1;
      const q = 1 - p;
      const x = q * q * q * x0 + 3 * q * q * p * x1 + 3 * q * p * p * x2 + p * p * p * width * 0.5;
      const y = q * q * q * y0 + 3 * q * q * p * y2 + 3 * q * p * p * y1 + p * p * p * height * 0.7;
      c.fillStyle = i % 7 === 0 ? '#ffb63a' : '#55dff3';
      c.globalAlpha = 0.45;
      c.beginPath();
      c.arc(x, y, 1.4, 0, 6.283);
      c.fill();
      c.globalAlpha = 1;
    }
  }
  if (!reduce || !reduce.matches) frame = requestAnimationFrame(draw);
}

function wake() {
  if (!frame && !stopped && !document.hidden) {
    last = 0;
    frame = requestAnimationFrame(draw);
  }
}

onMounted(() => {
  try {
    robot = window.GoHologram ? window.GoHologram.mount(figure.value, { background: false, interactive: false }) : null;
  } catch {
    robot = null;
  }
  // Without WebGL on this computer: the GO mark stands in for the hologram.
  live.value = !!robot;
  document.addEventListener('visibilitychange', wake);
  wake();
});

onUnmounted(() => {
  stopped = true;
  document.removeEventListener('visibilitychange', wake);
  if (frame) cancelAnimationFrame(frame);
  if (robot) robot.destroy();
  robot = null;
});
</script>
