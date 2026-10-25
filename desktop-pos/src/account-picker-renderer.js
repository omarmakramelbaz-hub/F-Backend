'use strict';
(() => {
  const list = document.getElementById('account-list'), status = document.getElementById('account-status');
  const select = document.getElementById('account-select'), add = document.getElementById('account-add'), cancel = document.getElementById('account-cancel');
  let profiles = [], selected = null, busy = false, loaded = false;
  const buttons = [];
  const update = () => {
    for (const row of buttons) { row.button.disabled = busy; row.button.setAttribute('aria-pressed', String(row.id === selected)); }
    select.disabled = busy || !loaded || !selected; add.disabled = busy || !loaded; cancel.disabled = busy;
  };
  const choose = async input => {
    if (busy) return;
    busy = true; update(); status.textContent = 'جارٍ تأكيد الاختيار…';
    try {
      if (await window.goAccounts.choose(input)) return;
    } catch { /* Show a fixed message without exposing native IPC details. */ }
    busy = false; update(); status.textContent = 'تعذر تأكيد الاختيار. حاول مرة أخرى.';
  };
  select.addEventListener('click', () => { if (loaded && selected) choose({ action: 'select', id: selected }); });
  add.addEventListener('click', () => { if (loaded) choose({ action: 'add' }); });
  cancel.addEventListener('click', () => choose({ action: 'cancel' }));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !busy) { event.preventDefault(); choose({ action: 'cancel' }); } });
  window.goAccounts.list().then(value => {
    if (busy) return;
    profiles = value.profiles; selected = profiles.find(profile => profile.selected)?.id || profiles[0]?.id || null;
    for (const profile of profiles) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'account-row';
      const dot = document.createElement('span'); dot.className = 'account-dot'; dot.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span'); label.className = 'account-label'; label.textContent = profile.label;
      button.append(dot, label);
      if (profile.selected) { const badge = document.createElement('span'); badge.className = 'account-current'; badge.textContent = 'الحالي'; button.append(badge); }
      button.addEventListener('click', () => { if (!busy) { selected = profile.id; update(); } });
      buttons.push({ id: profile.id, button }); list.append(button);
    }
    loaded = true; list.setAttribute('aria-busy', 'false');
    status.textContent = profiles.length ? 'يمكنك إضافة حساب آخر دون فقد بيانات الحساب الحالي.' : 'أضف حسابًا لتسجيل الدخول.';
    update();
  }).catch(() => { list.setAttribute('aria-busy', 'false'); status.textContent = 'تعذر عرض الحسابات. أغلق النافذة وحاول مرة أخرى.'; });
})();
