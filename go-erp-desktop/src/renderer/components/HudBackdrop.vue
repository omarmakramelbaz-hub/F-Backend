<template>
  <!-- The dashboard's sign-in page around the form (resources/views/admin/login.blade.php): its orbit lines, the GO mark
       and the tagline. The hologram itself is LoginHologram, beside the form. -->
  <svg class="go-login-orbits" viewBox="0 0 1600 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false"><g fill="none" stroke="currentColor" stroke-width=".7"><path d="M-100 30C500 80 250 770 1700 940M-100 130C400 80 380 780 1700 840M-100 240C440 100 380 770 1700 730M-100 350C500 190 440 750 1700 620M-100 470C520 360 440 720 1700 510M-100 590C570 590 600 150 1700 120M-100 730C500 830 800 300 1700 240M-100 850C650 1000 850 390 1700 360M-100 940C760 1120 960 500 1700 470"/></g></svg>
  <div class="go-login-mark" aria-hidden="true" v-html="mark"></div>
  <p class="go-login-tagline" lang="en" dir="ltr">THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR</p>
</template>

<script setup>
import mark from '../assets/go-hud-mark.svg?raw';
</script>
