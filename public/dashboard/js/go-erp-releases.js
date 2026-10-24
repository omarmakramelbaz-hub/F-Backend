/* A page visit reads status; only the owner's explicit click queues a build. */
(() => {
    const root = document.querySelector('[data-go-erp-release]');
    if (!root || root.dataset.goErpMounted) return;
    root.dataset.goErpMounted = '1';
    const button = root.querySelector('[data-go-erp-release-request]');
    const status = root.querySelector('[data-go-erp-release-status]');
    const error = root.querySelector('[data-go-erp-release-error]');
    const downloadLinks = { current: root.querySelector('[data-go-erp-published-current]'),
        previous: root.querySelector('[data-go-erp-published-previous]') };
    const downloadVersions = { current: root.querySelector('[data-go-erp-current-version]'),
        previous: root.querySelector('[data-go-erp-previous-version]') };
    const downloadEmpty = root.querySelector('[data-go-erp-published-empty]');
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
    function checkedDownloads(value) {
        if (!value || typeof value !== 'object') throw new Error('downloads');
        const next = {};
        for (const slot of ['current', 'previous']) {
            const item = value[slot];
            if (!item || typeof item !== 'object' || typeof item.ready !== 'boolean') throw new Error('downloads');
            if (!item.ready) { next[slot] = null; continue; }
            if (typeof item.version !== 'string' || !versionPattern.test(item.version) || typeof item.url !== 'string') throw new Error('download');
            const url = new URL(item.url, location.href);
            if (url.origin !== location.origin || url.username || url.password
                    || !/^\/admin\/desktop-dashboard\/published\/[a-f0-9]{64}$/.test(url.pathname)
                    || url.search || url.hash) throw new Error('download');
            next[slot] = { url: url.href, version: item.version };
        }
        return next;
    }
    function renderDownloads(published, current) {
        const updating = activeStates.has(current.state)
            && (current.version === null || published.current?.version !== current.version);
        const next = updating ? { current: null, previous: published.current || published.previous } : published;
        for (const slot of ['current', 'previous']) {
            const link = downloadLinks[slot], version = downloadVersions[slot];
            if (!link || !version) throw new Error('download-controls');
            const disabled = slot === 'current' && updating;
            link.hidden = !disabled && next[slot] === null;
            link.classList.toggle('disabled', disabled);
            if (disabled) {
                link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true');
                link.setAttribute('aria-busy', 'true'); link.setAttribute('tabindex', '-1');
                version.textContent = 'جارٍ التحديث';
            } else {
                link.removeAttribute('aria-disabled'); link.removeAttribute('aria-busy'); link.removeAttribute('tabindex');
                if (next[slot]) { link.href = next[slot].url; version.textContent = next[slot].version; }
                else { link.removeAttribute('href'); version.textContent = ''; }
            }
        }
        if (downloadEmpty) downloadEmpty.hidden = updating || next.current !== null;
    }
    function render(value) {
        if (closed) return;
        const current = value && value.status;
        if (!current || current.format !== 1 || current.kind !== 'go-erp-manual-release-status'
                || !Object.hasOwn(labels, current.state)
                || !(current.nonce === null || typeof current.nonce === 'string' && noncePattern.test(current.nonce))
                || !(current.version === null || typeof current.version === 'string' && versionPattern.test(current.version))
                || (activeStates.has(current.state) && current.nonce === null)) throw new Error('status');
        const published = checkedDownloads(value.published_downloads);
        showError('');
        building = activeStates.has(current.state);
        if (pending && current.nonce === pending) {
            if (!building) { pending = null; stored(null); }
            waiting = 0;
        } else if (pending) {
            waiting++;
            if (waiting > 20) { pending = null; stored(null); showError('لم يتأكد بدء طلب التحديث. اضغط الزر لإعادة المحاولة.'); }
        }
        const message = current.state === 'failed' && published.current
            ? 'تعذر إتمام التحديث. النسخة ' + published.current.version + ' ما زالت متاحة للتنزيل.' : labels[current.state];
        status.textContent = pending && !building ? labels.queued : message;
        button.disabled = busy || building || Boolean(pending);
        renderDownloads(published, current); later();
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
