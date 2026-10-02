'use strict';
(() => {
  const drawer = () => document.querySelector('[data-admin-orders-drawer]');

  function closeDrawer() {
    const panel = drawer();
    if (!panel) return;
    panel.hidden = true;
    document.body.classList.remove('admin-orders-drawer-open');
    const content = panel.querySelector('[data-admin-orders-drawer-content]');
    if (content) content.innerHTML = '';
  }

  document.addEventListener('click', event => {
    const open = event.target.closest('[data-admin-order-open]');
    if (open) {
      const card = open.closest('[data-admin-order-card]');
      const source = card?.querySelector('[data-admin-order-detail-source]');
      const panel = drawer();
      const content = panel?.querySelector('[data-admin-orders-drawer-content]');
      if (source && panel && content) {
        content.innerHTML = source.innerHTML;
        panel.hidden = false;
        document.body.classList.add('admin-orders-drawer-open');
      }
      return;
    }

    if (event.target.closest('[data-admin-order-close]')) {
      closeDrawer();
      return;
    }

    const panel = drawer();
    if (panel && !panel.hidden && event.target === panel) closeDrawer();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeDrawer();
  });

  document.addEventListener('input', event => {
    const input = event.target.closest('[data-admin-menu-search]');
    if (!input) return;
    const term = input.value.trim().toLocaleLowerCase('ar');
    document.querySelectorAll('[data-admin-menu-item]').forEach(item => {
      const name = (item.dataset.menuName || '').toLocaleLowerCase('ar');
      item.hidden = term !== '' && !name.includes(term);
    });
  });

  function chime() {
    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      const context = new AudioContext();
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.frequency.setValueAtTime(740, context.currentTime);
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.07, context.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.32);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.34);
      oscillator.addEventListener('ended', () => context.close());
    } catch (_) {}
  }

  let refreshing = false;
  async function refreshOrders() {
    const live = document.querySelector('[data-admin-orders-live]');
    if (!live || refreshing || document.visibilityState !== 'visible') return;
    const panel = drawer();
    if (panel && !panel.hidden) return;

    refreshing = true;
    try {
      const response = await fetch(window.location.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'X-App-Orders-Live': '1'}
      });
      if (!response.ok) return;

      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const next = parsed.querySelector('[data-admin-orders-live]');
      if (!next) return;

      const previousNew = Number(live.dataset.liveNew || 0);
      const nextNew = Number(next.dataset.liveNew || 0);

      if (next.dataset.ordersVersion !== live.dataset.ordersVersion) {
        live.replaceWith(next);
        if (nextNew > previousNew) chime();
      }

      const sync = document.querySelector('[data-admin-orders-last-sync]');
      if (sync) {
        sync.textContent = 'آخر تحديث ' + new Intl.DateTimeFormat('ar-EG', {
          hour: '2-digit', minute: '2-digit', second: '2-digit'
        }).format(new Date());
      }
    } catch (_) {
    } finally {
      refreshing = false;
    }
  }

  if (document.querySelector('[data-admin-orders-live]')) {
    window.setInterval(refreshOrders, 12000);
  }
})();