/* Native desktop preparation/status inside the original dashboard. Normal browsers have no bridge. */
(() => {
    const native = window.FasakhanstaDesktop;
    if (!native || typeof native.status !== 'function' || window.FasakhanstaDesktopUI) return;
    window.FasakhanstaDesktopUI = true;
    const labels = { idle: 'الجهاز يحتاج تجهيزًا للعمل بدون إنترنت', enrolling: 'ربط الجهاز بحسابك', downloading: 'تنزيل بيانات الحساب',
        starting: 'تجهيز التشغيل على الجهاز', verifying: 'تنزيل الصور وفحص البيانات', activating: 'حفظ النسخة المحلية',
        ready: 'الجهاز مجهّز للعمل بدون إنترنت', failed: 'تعذر إكمال تجهيز الجهاز' };
    let state = {}, button, panel, description, progress, error, action, timer;
    function render(value) {
        state = value;
        if (!button) return;
        button.hidden = !state.available;
        button.textContent = state.mode === 'local' ? 'العمل على الجهاز' : state.prepared ? 'السيرفر · الجهاز مجهّز' : 'تجهيز بدون إنترنت';
        const waiting = Number(state.pending || 0), conflicts = Number(state.conflicts || 0);
        description.textContent = (labels[state.phase] || labels.idle)
            + (waiting ? ' · ' + waiting + ' عملية تنتظر المزامنة' : '') + (conflicts ? ' · ' + conflicts + ' عملية تحتاج مراجعة' : '');
        progress.value = Math.max(0, Math.min(100, Number(state.progress || 0)));
        progress.hidden = state.prepared || ['idle', 'failed'].includes(state.phase);
        error.textContent = state.error || '';
        action.disabled = !['idle', 'failed', 'ready'].includes(state.phase);
        action.textContent = state.prepared ? 'مزامنة الآن' : 'تجهيز الجهاز';
    }
    async function update() { try { render(await native.status()); } catch { /* A transition/closed page cannot reopen another window. */ } }
    function mount() {
        const navbar = document.querySelector('.main-header .navbar-nav');
        if (!navbar || button) return;
        const item = document.createElement('li'); item.className = 'nav-item';
        button = document.createElement('button'); button.type = 'button'; button.hidden = true;
        button.className = 'btn btn-sm btn-outline-warning mx-2'; item.append(button); navbar.append(item);
        panel = document.createElement('dialog'); panel.dir = 'rtl';
        panel.style.cssText = 'border:0;border-radius:16px;padding:24px;max-width:480px;width:calc(100% - 32px);color:#24324b';
        panel.innerHTML = '<h5>العمل بدون إنترنت</h5><p data-description></p><progress max="100" style="width:100%"></progress>'
            + '<p data-error class="text-danger" role="alert"></p><div class="d-flex justify-content-between mt-3">'
            + '<button type="button" class="btn btn-warning" data-action>تجهيز الجهاز</button>'
            + '<button type="button" class="btn btn-outline-secondary" data-close>إغلاق</button></div>';
        document.body.append(panel); description = panel.querySelector('[data-description]'); progress = panel.querySelector('progress');
        error = panel.querySelector('[data-error]'); action = panel.querySelector('[data-action]');
        panel.querySelector('[data-close]').addEventListener('click', () => panel.close());
        button.addEventListener('click', () => { panel.showModal(); update(); });
        action.addEventListener('click', async () => {
            action.disabled = true; error.textContent = '';
            try {
                render(state.prepared ? await native.synchronize() : await native.prepare(document.querySelector('meta[name="csrf-token"]')?.content || ''));
            } catch (failure) { error.textContent = failure.message; action.disabled = false; }
        });
        native.onState(render); update(); timer = setInterval(update, 5000);
    }
    document.addEventListener('DOMContentLoaded', mount); mount();
    window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
})();
