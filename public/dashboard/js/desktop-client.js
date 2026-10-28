/* Device setup lives in Program for computer; native actions keep their original trusted IPC gate. */
(() => {
    const native = window.FasakhanstaDesktop;
    if (!native || typeof native.status !== 'function' || window.FasakhanstaDesktopUI) return;
    window.FasakhanstaDesktopUI = true;
    const labels = { idle: 'الجهاز يحتاج تجهيزًا للعمل بدون إنترنت', enrolling: 'ربط الجهاز بحسابك', downloading: 'تنزيل بيانات الحساب',
        starting: 'تجهيز التشغيل على الجهاز', verifying: 'تنزيل الصور وفحص البيانات', activating: 'حفظ النسخة المحلية',
        ready: 'الجهاز مجهّز للعمل بدون إنترنت', failed: 'تعذر إكمال تجهيز الجهاز' };
    const updateLabels = { idle: 'فحص التحديثات تلقائي', checking: 'جارٍ فحص تحديث البرنامج', current: 'البرنامج محدّث',
        downloading: 'جارٍ تنزيل تحديث البرنامج', staged: 'التحديث جاهز وينتظر انتهاء العمليات الجارية والمزامنة',
        installing: 'جارٍ تثبيت تحديث البرنامج', unavailable: 'لم تُنشر نسخة متوافقة جديدة بعد', error: 'تعذر فحص التحديث حاليًا؛ سيحاول البرنامج تلقائيًا' };
    const incomingActive = new Set(['checking', 'capturing', 'downloading', 'staging', 'activating']);
    let mounted = null, cleanup = () => {};
    function mount() {
        const root = document.querySelector('[data-desktop-program]');
        if (root === mounted) return;
        cleanup(); mounted = root;
        if (!root) return;
        const controls = root.querySelector('[data-desktop-controls]'), note = root.querySelector('[data-desktop-browser-note]');
        const description = root.querySelector('[data-desktop-description]'), connection = root.querySelector('[data-desktop-connection]');
        const refreshed = root.querySelector('[data-desktop-refreshed]');
        const progress = root.querySelector('[data-desktop-progress]'), error = root.querySelector('[data-desktop-error]');
        const action = root.querySelector('[data-desktop-action]'), review = root.querySelector('[data-desktop-review]');
        const updateStatus = root.querySelector('[data-desktop-update]');
        let state = {}, active = true, busy = false;
        const text = value => typeof value === 'string' ? value.slice(0, 4000) : '';
        const count = value => Number.isSafeInteger(value) && value > 0 ? value : 0;
        function render(value) {
            if (!active || !value || typeof value !== 'object') return;
            state = value; controls.hidden = state.available !== true; note.hidden = state.available === true;
            if (controls.hidden) return;
            const waiting = count(state.pending), conflicts = count(state.conflicts);
            description.textContent = (Object.hasOwn(labels, state.phase) ? labels[state.phase] : labels.idle)
                + (state.phase === 'verifying' && state.media && count(state.media.total) ? ' · تم تنزيل ' + count(state.media.done) + ' من ' + count(state.media.total) + ' صورة' : '')
                + (state.prepared ? (state.mode === 'local' ? ' · العمل على الجهاز' : ' · الداشبورد المتصلة') + ' · المزامنة تلقائية كل ٣٠ ثانية' : '')
                + (waiting ? ' · ' + waiting + ' عملية تنتظر المزامنة' : '')
                + (conflicts ? ' · ' + conflicts + ' عملية تحتاج مراجعة' : '')
                + (state.sync_queued ? ' · طلب المزامنة في الدورة القادمة' : '');
            connection.textContent = typeof state.online === 'boolean' ? (state.online ? 'آخر اتصال لمزامنة العمليات بالسيرفر: متصل' : 'آخر اتصال لمزامنة العمليات بالسيرفر: لم يتأكد الاتصال بعد') : '';
            const at = typeof state.refreshedAt === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(state.refreshedAt) ? new Date(state.refreshedAt) : null;
            const confirmedAt = at && Number.isFinite(at.getTime()) && at.toISOString() === state.refreshedAt ? at : null;
            const incoming = state.data_refresh && typeof state.data_refresh === 'object' ? state.data_refresh : {};
            const incomingError = state.prepared === true ? text(incoming.error) : '';
            const freshness = [];
            if (state.prepared === true) {
                if (incomingError || incoming.phase === 'failed') freshness.push('تعذر تحديث بيانات الجهاز من السيرفر');
                else if (incoming.deferred === true || ['waiting', 'deferred'].includes(incoming.phase)) freshness.push('تحديث البيانات ينتظر جاهزية الصفحة أو انتهاء العمليات');
                else if (incomingActive.has(incoming.phase) || state.refresh_running === true) freshness.push('جارٍ تحديث بيانات الجهاز من السيرفر');
                else if (state.refresh_deferred === true || waiting || conflicts) freshness.push('تحديث البيانات ينتظر جاهزية الصفحة أو انتهاء العمليات');
                else if (!confirmedAt) freshness.push('لم يتأكد تحديث بيانات الجهاز من السيرفر بعد');
            }
            if (confirmedAt) freshness.push('آخر تحديث ناجح لبيانات الجهاز من السيرفر: ' + confirmedAt.toLocaleString('ar-EG'));
            refreshed.textContent = freshness.join(' · ');
            progress.value = Math.max(0, Math.min(100, Number(state.progress) || 0));
            progress.hidden = state.prepared === true || ['idle', 'failed'].includes(state.phase);
            error.textContent = [...new Set([text(state.error), incomingError].filter(Boolean))].join(' · ');
            action.disabled = busy || !['idle', 'failed', 'ready'].includes(state.phase) || Boolean(state.sync_queued || state.sync_running);
            action.textContent = state.prepared ? (state.sync_running ? 'جارٍ المزامنة' : state.sync_queued ? 'بانتظار الدورة القادمة' : 'طلب مزامنة') : 'تجهيز الجهاز أول مرة';
            review.hidden = state.prepared !== true || typeof native.reviewSync !== 'function'; review.disabled = busy;
            const update = state.update && typeof state.update === 'object' ? state.update : {};
            const version = typeof update.version === 'string' && update.version.length <= 100
                && /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/.test(update.version) ? update.version : '';
            updateStatus.textContent = 'تحديث البرنامج: ' + (Object.hasOwn(updateLabels, update.status) ? updateLabels[update.status] : 'يبدأ الفحص التلقائي بعد تجهيز الجهاز') + (version ? ' · ' + version : '');
        }
        async function update() { try { render(await native.status()); } catch { /* A transitioning/closed page cannot reopen another window. */ } }
        const prepare = async () => {
            if (busy || action.disabled) return;
            busy = true; action.disabled = true; review.disabled = true; error.textContent = '';
            try {
                const result = state.prepared ? await native.synchronize() : await native.prepare(document.querySelector('meta[name="csrf-token"]')?.content || '');
                busy = false; render(result);
            } catch (failure) { busy = false; if (active) { render(state); error.textContent = text(failure.message); } }
        };
        const reviewSync = async () => {
            if (busy || review.hidden) return;
            busy = true; action.disabled = true; review.disabled = true; error.textContent = '';
            try { await native.reviewSync(); busy = false; await update(); }
            catch (failure) { busy = false; if (active) { render(state); error.textContent = text(failure.message); } }
        };
        action.addEventListener('click', prepare); review.addEventListener('click', reviewSync);
        const unsubscribe = typeof native.onState === 'function' ? native.onState(render) : null;
        const timer = setInterval(update, 5000); update();
        cleanup = () => { if (!active) return; active = false; clearInterval(timer); if (typeof unsubscribe === 'function') unsubscribe(); action.removeEventListener('click', prepare); review.removeEventListener('click', reviewSync); };
    }
    document.addEventListener('DOMContentLoaded', mount);
    document.addEventListener('dashboard:page-mounted', mount);
    document.addEventListener('dashboard:before-unload', () => { cleanup(); mounted = null; });
    window.addEventListener('pageshow', mount);
    window.addEventListener('pagehide', () => { cleanup(); mounted = null; });
    mount();
})();
