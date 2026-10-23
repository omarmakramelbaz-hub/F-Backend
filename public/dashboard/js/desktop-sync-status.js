/* A persistent local conflict notice exposes counts and fixed guidance, never account records. */
(() => {
    const native = window.FasakhanstaDesktop;
    if (!native || typeof native.status !== 'function' || window.FasakhanstaDesktopSyncStatus) return;
    window.FasakhanstaDesktopSyncStatus = true;
    let banner, description, action, error, timer, unsubscribe, active = false, busy = false, epoch = 0;
    const setText = (element, value) => { if (element.textContent !== value) element.textContent = value; };
    function create() {
        if (banner) return;
        banner = document.createElement('div'); banner.className = 'alert alert-warning mb-0';
        banner.setAttribute('data-desktop-sync-status', ''); banner.setAttribute('role', 'alert');
        banner.setAttribute('aria-live', 'assertive'); banner.setAttribute('aria-atomic', 'true'); banner.dir = 'rtl';
        banner.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:1040;width:calc(100% - 32px);max-width:720px;box-shadow:0 4px 24px #0003;padding:14px 18px';
        description = document.createElement('p'); description.className = 'mb-2'; banner.append(description);
        action = document.createElement('button'); action.type = 'button'; action.className = 'btn btn-warning btn-sm';
        action.textContent = 'مراجعة المزامنة'; banner.append(action);
        error = document.createElement('p'); error.className = 'mb-0 mt-2'; error.setAttribute('role', 'status'); banner.append(error);
        action.addEventListener('click', async () => {
            if (busy || banner.hidden || typeof native.reviewSync !== 'function') return;
            busy = true; action.disabled = true; setText(error, '');
            try { await native.reviewSync(); await update(); }
            catch { if (active) setText(error, 'تعذر فتح مراجعة المزامنة. استخدم «مراجعة سجل المزامنة» من قائمة البرنامج.'); }
            finally { busy = false; if (active) action.disabled = false; }
        });
        banner.hidden = true; document.body.append(banner);
    }
    function render(state) {
        if (!active || !document.body) return;
        const conflicts = Number.isSafeInteger(state?.conflicts) && state.conflicts > 0 ? state.conflicts : 0;
        const show = document.body.dataset.dashboardLocal === '1' && state?.available === true && state.prepared === true && state.mode === 'local' && conflicts > 0;
        if (!show) { if (banner) banner.hidden = true; return; }
        create();
        setText(description, 'توجد عمليات متعارضة تحتاج مراجعة (العدد: ' + conflicts + '). تحديث بيانات الجهاز من السيرفر متوقف حتى تسوية التعارض؛ العمليات محفوظة على الجهاز، وتثبيت تحديث البرنامج ينتظر المراجعة أيضًا.');
        action.hidden = typeof native.reviewSync !== 'function'; action.disabled = busy;
        // Native/server error text can contain context. This notice uses fixed guidance only.
        if (!busy) setText(error, typeof state.error === 'string' && state.error.length > 0 ? 'راجع العملية المتعارضة قبل متابعة تحديث البيانات.' : '');
        banner.hidden = false;
    }
    async function update() {
        const version = epoch;
        try { const state = await native.status(); if (active && epoch === version) render(state); }
        catch { /* Keep the last proven conflict visible until a successful state clears it. */ }
    }
    function start() {
        if (active) return; active = true;
        unsubscribe = typeof native.onState === 'function' ? native.onState(state => { epoch++; render(state); }) : null;
        timer = setInterval(update, 5000); update();
    }
    function stop() {
        if (!active) return; active = false; epoch++; clearInterval(timer);
        if (typeof unsubscribe === 'function') unsubscribe(); unsubscribe = null;
        banner?.remove(); banner = null; busy = false;
    }
    document.addEventListener('DOMContentLoaded', start);
    window.addEventListener('pageshow', start); window.addEventListener('pagehide', stop);
    start();
})();
