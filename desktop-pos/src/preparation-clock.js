(() => {
    'use strict';
    if (window.top !== window || window.__goErpPreparationClock) return;
    const native = window.FasakhanstaDesktop;
    if (!native || typeof native.status !== 'function' || typeof native.onState !== 'function') return;
    window.__goErpPreparationClock = true;
    const phases = { enrolling: 'ربط الجهاز', downloading: 'تنزيل بيانات الحساب', starting: 'تجهيز التشغيل',
        verifying: 'فحص البيانات والصور', activating: 'حفظ النسخة المحلية' };
    let root = null, timer = null, output = null, sample = null, received = 0, generation = 0;
    const clock = () => performance.now();
    function format(ms) {
        const seconds = Math.floor(ms / 1000), hours = Math.floor(seconds / 3600);
        return (hours ? String(hours).padStart(2, '0') + ':' : '')
            + String(Math.floor(seconds / 60) % 60).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    }
    function draw() {
        if (!root?.isConnected || !output?.isConnected) return;
        if (!sample) { output.hidden = true; return; }
        const elapsed = Math.min(Number.MAX_SAFE_INTEGER, sample.elapsed + (sample.running ? Math.max(0, clock() - received) : 0));
        const elapsedText = format(elapsed);
        output.textContent = sample.running
            ? 'وقت الإعداد: ' + elapsedText + (phases[sample.phase] ? ' · ' + phases[sample.phase] : '') + ' · ' + sample.progress + '٪'
            : sample.phase === 'ready' ? 'اكتمل الإعداد خلال ' + elapsedText : 'مدة محاولة الإعداد: ' + elapsedText;
        output.hidden = false;
    }
    function update(value) {
        const timing = value?.setup_timing;
        if (!timing || !Number.isSafeInteger(timing.elapsed_ms) || timing.elapsed_ms < 0 || typeof timing.running !== 'boolean') {
            sample = null; draw(); return;
        }
        sample = { elapsed: timing.elapsed_ms, running: timing.running, phase: value.phase,
            progress: Math.floor(Math.max(0, Math.min(100, Number(value.progress) || 0))) };
        received = clock(); draw();
    }
    function unmount() {
        generation++; if (timer !== null) clearInterval(timer);
        timer = null; root = null; output = null; sample = null;
    }
    function mount() {
        const next = document.querySelector('[data-desktop-program]');
        if (root === next && root?.isConnected) return;
        unmount();
        if (!next) return;
        const controls = next.querySelector('[data-desktop-controls]');
        if (!controls) return;
        root = next;
        output = next.querySelector('[data-go-erp-preparation-time]');
        if (!output) {
            output = document.createElement('p'); output.setAttribute('data-go-erp-preparation-time', '');
            output.setAttribute('role', 'timer'); output.setAttribute('aria-live', 'off');
            output.className = 'text-muted mt-2'; output.hidden = true; controls.append(output);
        }
        const mountedGeneration = generation;
        Promise.resolve().then(() => native.status()).then(value => {
            if (generation === mountedGeneration && root === next) update(value);
        }).catch(() => {});
        timer = setInterval(draw, 1000);
    }
    native.onState(update);
    document.addEventListener('dashboard:page-mounted', mount);
    document.addEventListener('dashboard:before-unload', unmount);
    window.addEventListener('pageshow', mount);
    window.addEventListener('pagehide', unmount);
    mount();
})();
