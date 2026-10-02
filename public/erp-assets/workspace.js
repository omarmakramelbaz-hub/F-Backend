'use strict';
document.addEventListener('submit', function (event) {
  const form = event.target;
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
  if (!form.checkValidity()) return;
  const button = event.submitter;
  if (button && form.method.toLowerCase() === 'post') { button.dataset.originalText = button.textContent; button.disabled = true; button.textContent = 'جاري الحفظ…'; }
});
window.addEventListener('pageshow', () => document.querySelectorAll('[data-original-text]').forEach(button => {
  button.disabled = false; button.textContent = button.dataset.originalText; delete button.dataset.originalText;
}));
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelectorAll('[data-role-picker]').forEach(picker => {
  const form = picker.closest('form');
  const apply = reset => {
    form.querySelectorAll('input[name="permissions[]"]').forEach(box => {
      if (reset) box.checked = JSON.parse(picker.selectedOptions[0].dataset.permissions || '[]').includes(box.value);
      box.disabled = picker.value === 'branch_manager' && ['branches.manage','payroll.manage','purchasing.manage','finance.manage'].includes(box.value);
      if (box.disabled) box.checked = false;
    });
    const branch = form.querySelector('[name="branch_id"]');
    branch.required = picker.value === 'branch_manager';
    branch.disabled = !branch.required;
  };
  picker.addEventListener('change', () => apply(true));
  apply(false);
});
document.querySelectorAll('form[data-stock-form]').forEach(form => {
  const picker = form.querySelector('[name="type"]');
  const apply = () => {
    const visibility = {destination_id: picker.value === 'transfer', expected_quantity: picker.value === 'count', unit_cost: ['opening','receipt','count'].includes(picker.value)};
    Object.entries(visibility).forEach(([name, visible]) => {
      const input = form.querySelector(`[name="${name}"]`);
      input.closest('.field').hidden = !visible;
      input.disabled = !visible;
      input.required = visible && (name !== 'unit_cost' || picker.value !== 'count');
    });
  };
  picker.addEventListener('change', apply); apply();
});

document.querySelectorAll('[data-line-editor]').forEach(editor => {
  const preview = () => {
    const output = editor.querySelector('[data-line-total]');
    if (!output) return;
    const scaled = (value, places) => {
      if (!new RegExp('^\\d+(?:\\.\\d{1,' + places + '})?$').test(value)) return null;
      const parts = value.split('.');
      return BigInt(parts[0]) * (10n ** BigInt(places)) + BigInt((parts[1] || '').padEnd(places, '0'));
    };
    let total = 0n;
    for (const row of editor.querySelectorAll('[data-line-rows] [data-line-row]')) {
      const quantity = scaled(row.querySelector('[name$="[quantity]"]').value, 3);
      const cost = scaled(row.querySelector('[name$="[unit_cost]"]').value, 2);
      if (quantity === null || cost === null) { output.textContent = 'أكمل الكميات والتكلفة'; return; }
      total += (quantity * cost + 500n) / 1000n;
    }
    output.textContent = `${total / 100n}.${String(total % 100n).padStart(2, '0')} ج.م`;
  };
  editor.addEventListener('input', preview);
  editor.addEventListener('click', event => {
    const rows = editor.querySelector('[data-line-rows]');
    if (event.target.closest('[data-add-line]')) {
      if (rows.children.length >= 30) return;
      const index = Number(editor.dataset.nextIndex);
      rows.insertAdjacentHTML('beforeend', editor.querySelector('template').innerHTML.replaceAll('__INDEX__', String(index)));
      editor.dataset.nextIndex = String(index + 1);
    }
    if (event.target.closest('[data-remove-line]') && rows.children.length > 1) {
      event.target.closest('[data-line-row]').remove();
    }
    preview();
  });
  preview();
});
document.querySelectorAll('[data-cash-form]').forEach(form => {
  const picker = form.querySelector('[name="type"]');
  const apply = () => {
    const fields = {supplier_id: 'supplier_payment', payroll_id: 'payroll_payment', branch_id: 'expense'};
    Object.entries(fields).forEach(([name, type]) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (!input) return;
      input.disabled = picker.value !== type;
      input.closest('.field').hidden = input.disabled;
      input.required = !input.disabled && name !== 'branch_id';
    });
  };
  picker.addEventListener('change', apply); apply();
});

document.querySelectorAll('[data-supplier-picker]').forEach(picker => picker.addEventListener('change', () => {
  const supplier = JSON.parse(picker.selectedOptions[0].dataset.supplier || '{}');
  const form = picker.closest('form');
  ['name','phone','address','active'].forEach(field => {
    form.querySelector(`[name="${field}"]`).value = supplier[field] ?? (field === 'active' ? '1' : '');
  });
}));


// Unified order center
(() => {
  const drawer = () => document.querySelector('[data-orders-drawer]');

  const closeDrawer = () => {
    const panel = drawer();
    if (!panel) return;
    panel.hidden = true;
    document.body.classList.remove('orders-drawer-open');
    const content = panel.querySelector('[data-orders-drawer-content]');
    if (content) content.innerHTML = '';
  };

  document.addEventListener('click', event => {
    const openButton = event.target.closest('[data-order-open]');
    if (openButton) {
      const card = openButton.closest('[data-order-card]');
      const source = card?.querySelector('[data-order-detail-source]');
      const panel = drawer();
      const content = panel?.querySelector('[data-orders-drawer-content]');
      if (panel && content && source) {
        content.innerHTML = source.innerHTML;
        panel.hidden = false;
        document.body.classList.add('orders-drawer-open');
        panel.querySelector('[data-order-close]')?.focus();
      }
      return;
    }

    if (event.target.closest('[data-order-close]')) {
      closeDrawer();
      return;
    }

    const panel = drawer();
    if (panel && !panel.hidden && event.target === panel) {
      closeDrawer();
      return;
    }

    if (event.target.closest('[data-orders-refresh]')) {
      refreshOrders(true);
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeDrawer();
  });

  const chime = () => {
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
      gain.gain.exponentialRampToValueAtTime(0.08, context.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.35);
      oscillator.start();
      oscillator.stop(context.currentTime + 0.36);
      oscillator.addEventListener('ended', () => context.close());
    } catch (_) {}
  };

  let refreshing = false;
  async function refreshOrders(force = false) {
    const live = document.querySelector('[data-orders-live]');
    if (!live || refreshing) return;
    const panel = drawer();
    if (!force && (document.visibilityState !== 'visible' || (panel && !panel.hidden))) return;

    refreshing = true;
    live.classList.add('orders-syncing');
    try {
      const response = await fetch(window.location.href, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'X-ERP-Live': '1'}
      });
      if (!response.ok) return;
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const next = parsed.querySelector('[data-orders-live]');
      if (!next) return;

      const previousNew = Number(live.dataset.liveNew || 0);
      const nextNew = Number(next.dataset.liveNew || 0);
      const changed = next.dataset.ordersVersion !== live.dataset.ordersVersion;

      if (changed) {
        live.replaceWith(next);
        next.classList.add('orders-updated');
        if (nextNew > previousNew) chime();
      } else {
        live.classList.remove('orders-syncing');
      }

      const sync = document.querySelector('[data-orders-last-sync]');
      if (sync) {
        sync.textContent = 'آخر مزامنة: ' + new Intl.DateTimeFormat('ar-EG', {hour:'2-digit', minute:'2-digit', second:'2-digit'}).format(new Date());
      }
    } catch (_) {
      live.classList.remove('orders-syncing');
    } finally {
      refreshing = false;
    }
  }

  if (document.querySelector('[data-orders-live]')) {
    window.setInterval(() => refreshOrders(false), 12000);
  }
})();
