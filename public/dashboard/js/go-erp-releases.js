/* A page visit reads status; only the owner's explicit click queues a build. */
(() => {
    const root = document.querySelector('[data-go-erp-release]');
    if (!root || root.dataset.goErpMounted) return;
    root.dataset.goErpMounted = '1';
    const button = root.querySelector('[data-go-erp-release-request]');
    const status = root.querySelector('[data-go-erp-release-status]');
    const error = root.querySelector('[data-go-erp-release-error]');
    const download = document.querySelector('[data-go-erp-release-download]');
    const activeStates = new Set(['queued', 'running', 'waiting-ci', 'publishing']);
    const labels = { idle: 'اضغط تحديث التطبيق عندما تريد تجهيز نسخة من آخر تعديلات السيرفر.',
        queued: 'تم استلام طلب التحديث وينتظر بدء التجهيز.', running: 'جارٍ تجهيز نسخة التطبيق.',
        'waiting-ci': 'جارٍ فحص النسخة وتجهيز ملف التثبيت.', publishing: 'جارٍ نشر النسخة بعد اكتمال الفحص.',
        ready: 'اكتمل تجهيز النسخة ونشرها.', failed: 'تعذر إتمام التحديث. يمكنك المحاولة مرة أخرى.' };
    const noncePattern = /^[a-f0-9]{32}$/;
    const versionPattern = /^0\.4\.4-preview\.[1-9][0-9]{0,14}$/;
    let closed = false, busy = false, timer = null, building = false, pending = null, waiting = 0;
    const controllers = new Set();
    function endpoint(value) {
        const url = new URL(value, location.href);
        if (url.origin !== location.origin) throw new Error('endpoint');
        return url.href;
    }
    function stored(value) {
        try {
            if (value === undefined) return sessionStorage.getItem('goErpManualReleaseNonce');
            if (value === null) sessionStorage.removeItem('goErpManualReleaseNonce');
            else sessionStorage.setItem('goErpManualReleaseNonce', value);
        } catch (_) { /* A blocked storage policy does not grant another action. */ }
        return null;
    }
    const previous = stored();
    if (typeof previous === 'string' && noncePattern.test(previous)) pending = previous;
    function showError(message) { if (!closed) { error.textContent = message; error.hidden = !message; } }
    function later() {
        if (!closed && (building || pending) && timer === null) timer = setTimeout(() => { timer = null; refresh(); }, 15000);
    }
    function renderDownload(value) {
        if (!download || !value || typeof value !== 'object') return;
        if (value.ready !== true) {
            const message = document.createElement('p'); message.className = 'text-muted mb-0'; message.setAttribute('role', 'status');
            message.textContent = 'ملف التنزيل يظهر بعد اكتمال تجهيز النسخة المتوافقة وفحصها.';
            download.replaceChildren(message); return;
        }
        if (!versionPattern.test(value.version) || typeof value.url !== 'string') throw new Error('download');
        const url = new URL(value.url, location.href);
        if (url.origin !== location.origin || url.pathname !== '/admin/desktop-dashboard/download' || url.search || url.hash) throw new Error('download');
        const text = document.createElement('p'), version = document.createElement('bdi');
        text.textContent = 'نسخة التثبيت للأجهزة الجديدة: '; version.textContent = value.version; text.appendChild(version);
        const link = document.createElement('a'); link.className = 'btn btn-warning'; link.href = url.href;
        link.setAttribute('data-spa-off', ''); link.setAttribute('download', ''); link.textContent = 'تحميل GO ERP لجهاز جديد';
        download.replaceChildren(text, link);
    }
    function render(value) {
        if (closed) return;
        const current = value && value.status;
        if (!current || current.format !== 1 || current.kind !== 'go-erp-manual-release-status'
                || !Object.hasOwn(labels, current.state) || !(current.nonce === null || noncePattern.test(current.nonce))) throw new Error('status');
        building = activeStates.has(current.state);
        if (pending && current.nonce === pending) {
            if (!building) { pending = null; stored(null); }
            waiting = 0;
        } else if (pending) {
            waiting++;
            if (waiting > 20) { pending = null; stored(null); showError('لم يتأكد بدء طلب التحديث. اضغط الزر لإعادة المحاولة.'); }
        }
        status.textContent = pending && !building ? labels.queued : labels[current.state];
        button.disabled = busy || building || Boolean(pending);
        renderDownload(value.download); later();
    }
    async function request(url, options = {}) {
        const controller = new AbortController(); controllers.add(controller);
        try {
            const response = await fetch(endpoint(url), { credentials: 'same-origin', cache: 'no-store', ...options, signal: controller.signal });
            if (!response.ok) {
                const failure = new Error('request'); failure.status = response.status; throw failure;
            }
            if (!/application\/json/i.test(response.headers.get('content-type') || '')) throw new Error('response');
            return await response.json();
        } finally { controllers.delete(controller); }
    }
    async function refresh() {
        if (closed) return;
        try { render(await request(root.dataset.statusUrl)); }
        catch (failure) {
            if (closed || failure.name === 'AbortError') return;
            showError('تعذر قراءة حالة التحديث حاليًا. أعد فتح الصفحة أو حاول بعد قليل.'); later();
        }
    }
    async function submit() {
        if (closed || busy || building) return;
        busy = true; button.disabled = true; showError('');
        try {
            if (!pending) {
                const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
                pending = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join(''); stored(pending);
            }
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            if (typeof token !== 'string' || !token) throw new Error('csrf');
            const value = await request(root.dataset.requestUrl, { method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ nonce: pending }) });
            if (value.accepted !== true || value.nonce !== pending) throw new Error('acceptance');
            busy = false; render(value); later();
        } catch (failure) {
            if (closed || failure.name === 'AbortError') return;
            busy = false;
            if (failure.status && failure.status < 500) { pending = null; stored(null); }
            button.disabled = false;
            showError(failure.status === 429 ? 'انتظر خمس دقائق قبل طلب تحديث آخر.'
                : failure.status === 409 ? 'يوجد تحديث قيد التنفيذ بالفعل. تابع حالته من الصفحة.'
                : 'لم يتأكد إرسال طلب التحديث. اضغط الزر لإعادة المحاولة.');
            if (failure.status === 409) refresh();
        }
    }
    function cleanup() {
        if (closed) return; closed = true;
        if (timer !== null) clearTimeout(timer);
        controllers.forEach(controller => controller.abort()); controllers.clear();
        button.removeEventListener('click', submit); delete root.dataset.goErpMounted;
    }
    button.addEventListener('click', submit);
    document.addEventListener('dashboard:before-unload', cleanup, { once: true });
    window.addEventListener('pagehide', cleanup, { once: true });
    refresh();
})();
