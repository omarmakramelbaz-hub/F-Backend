(function () {
    'use strict';
    if (window.DashboardPrint) return;

    var jobs = new Map();
    var queue = [];
    var running = false;
    var active = null;
    var readyTimeout = 25000;
    var dialogTimeout = 120000;
    var messages = {
        ar: {
            invalid: 'تعذّرت الطباعة: رابط الفاتورة غير صالح.',
            session: 'انتهت جلسة تسجيل الدخول. سجّل الدخول ثم أعد الطباعة.',
            forbidden: 'لا تملك صلاحية طباعة هذه الفاتورة.',
            receipt: 'تعذّر تحميل فاتورة صالحة للطباعة. أعد المحاولة.',
            resources: 'تعذّر تحميل شعار الفاتورة أو خطها. أعد الطباعة.',
            timeout: 'استغرق تحميل الفاتورة وقتًا طويلًا. أعد الطباعة.',
            unavailable: 'تعذّر فتح الطباعة في هذا المتصفح.',
            busy: 'انتظر انتهاء طباعة الفواتير الحالية ثم أعد المحاولة.',
            cancelled: 'أُلغيت الطباعة عند مغادرة الصفحة.'
        },
        en: {
            invalid: 'Unable to print: the receipt link is invalid.',
            session: 'Your session has expired. Sign in and print again.',
            forbidden: 'You do not have permission to print this receipt.',
            receipt: 'Unable to load a valid receipt. Please try again.',
            resources: 'The receipt logo or font could not be loaded. Please print again.',
            timeout: 'The receipt took too long to load. Please print again.',
            unavailable: 'Printing could not be opened in this browser.',
            busy: 'Wait for the current receipts to finish printing, then try again.',
            cancelled: 'Printing was cancelled when leaving the page.'
        }
    };

    function failure(key) {
        var language = /^ar(?:-|$)/i.test(document.documentElement.lang) ? 'ar' : 'en';
        var error = new Error(messages[language][key] || messages[language].receipt);
        error.code = 'dashboard-print-' + key;
        return error;
    }

    function receiptURL(value) {
        if (typeof value !== 'string' || !value.trim()) throw failure('invalid');
        var url;
        try { url = new URL(value, location.href); } catch (_) { throw failure('invalid'); }
        if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) {
            throw failure('invalid');
        }
        url.hash = '';
        url.searchParams.set('dashboard_print', '1');
        url.searchParams.sort();
        return url;
    }

    function print(value) {
        var url;
        try { url = receiptURL(value); } catch (error) { return Promise.reject(error); }
        if (jobs.has(url.href)) return jobs.get(url.href).promise;
        if (jobs.size >= 8) return Promise.reject(failure('busy'));
        var job = { url: url, settled: false };
        job.promise = new Promise(function (resolve, reject) {
            job.resolve = function () { if (!job.settled) { job.settled = true; resolve(); } };
            job.reject = function (error) { if (!job.settled) { job.settled = true; reject(error); } };
        });
        jobs.set(url.href, job);
        queue.push(job);
        // Start outside the caller's SPA page scope. The temporary receipt lives
        // beside the shared shell and does not dispose its scripts or polling.
        Promise.resolve().then(pump);
        return job.promise;
    }

    async function pump() {
        if (running) return;
        running = true;
        while (queue.length) {
            var job = queue.shift();
            active = job;
            await run(job);
            jobs.delete(job.url.href);
            active = null;
        }
        running = false;
    }

    async function run(job) {
        if (window.FasakhanstaDesktop && typeof window.FasakhanstaDesktop.printReceipt === 'function') {
            try { await window.FasakhanstaDesktop.printReceipt(job.url.href); job.resolve(); }
            catch (error) { job.reject(error); }
            return;
        }
        var controller = new AbortController();
        var frame = null;
        var disposed = false;
        var cleanupTasks = [];
        var readyTimer;
        var dialogTimer;
        var focusTimer;
        var printed = false;
        var invocationComplete = false;
        var finishedEarly = false;
        var leftWindow = false;
        var finish;
        var completion = new Promise(function (resolve) { finish = resolve; });

        function cleanup() {
            if (disposed) return;
            disposed = true;
            controller.abort();
            clearTimeout(readyTimer);
            clearTimeout(dialogTimer);
            clearTimeout(focusTimer);
            cleanupTasks.reverse().forEach(function (callback) { try { callback(); } catch (_) {} });
            if (frame) frame.remove();
            finish();
        }
        job.cancel = function () { job.reject(failure('cancelled')); cleanup(); };

        function listen(target, name, callback) {
            target.addEventListener(name, callback);
            cleanupTasks.push(function () { target.removeEventListener(name, callback); });
        }
        function ensureActive() { if (disposed) throw failure('cancelled'); }
        function dialogFinished() {
            if (!printed) return;
            if (!invocationComplete) { finishedEarly = true; return; }
            clearTimeout(focusTimer);
            // Keep the document until the browser has captured the print layout.
            focusTimer = setTimeout(cleanup, 250);
        }

        async function prepare() {
            var response = await fetch(job.url.href, {
                method: 'GET', credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal, headers: { Accept: 'text/html' }
            });
            ensureActive();
            if (response.status === 401 || response.status === 419) throw failure('session');
            if (response.status === 403) throw failure('forbidden');
            var finalURL;
            try { finalURL = new URL(response.url || job.url.href); } catch (_) { throw failure('receipt'); }
            if (finalURL.origin !== location.origin || !/^https?:$/.test(finalURL.protocol)) throw failure('invalid');
            if (/\/(?:login|signin|logout|signout)(?:\/|$)/i.test(finalURL.pathname)) throw failure('session');
            if (!response.ok || !/^(?:text\/html|application\/xhtml\+xml)(?:\s*;|$)/i.test(response.headers.get('Content-Type') || '')) {
                throw failure('receipt');
            }
            var source = await response.text();
            ensureActive();
            if (source.length > 3 * 1024 * 1024) throw failure('receipt');
            var receipt = new DOMParser().parseFromString(source, 'text/html');
            if (!(receipt.documentElement.getAttribute('data-dashboard-receipt') || '').trim()) throw failure('receipt');

            // Receipts are server-rendered snapshots. No receipt script, refresh,
            // nested frame or inline handler needs to execute while printing.
            receipt.querySelectorAll('script, base, iframe, object, embed, meta[http-equiv="refresh" i]').forEach(function (node) { node.remove(); });
            receipt.querySelectorAll('*').forEach(function (node) {
                Array.from(node.attributes).forEach(function (attribute) {
                    if (/^on/i.test(attribute.name) || /^(?:href|src|action)$/i.test(attribute.name) && /^\s*javascript:/i.test(attribute.value)) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });
            receipt.querySelectorAll('img').forEach(function (image) { image.loading = 'eager'; });
            var base = receipt.createElement('base');
            base.href = finalURL.href;
            receipt.head.prepend(base);

            frame = document.createElement('iframe');
            frame.title = /^ar(?:-|$)/i.test(document.documentElement.lang) ? 'فاتورة الطباعة' : 'Print receipt';
            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            frame.setAttribute('data-dashboard-print-frame', '');
            frame.setAttribute('sandbox', 'allow-same-origin allow-modals');
            // Offscreen, rather than display:none, so font and image layout is
            // available to thermal printers without moving the dashboard.
            frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0;opacity:0;pointer-events:none;';
            var loaded = new Promise(function (resolve, reject) {
                listen(frame, 'load', resolve);
                listen(frame, 'error', function () { reject(failure('receipt')); });
            });
            frame.srcdoc = '<!doctype html>\n' + receipt.documentElement.outerHTML;
            document.body.appendChild(frame);
            await loaded;
            ensureActive();
            var content = frame.contentDocument;
            if (!content || !(content.documentElement.getAttribute('data-dashboard-receipt') || '').trim()) throw failure('receipt');
            // Force layout before reading fonts.ready so used web fonts load.
            void content.body.offsetWidth;
            var imagesReady = Array.from(content.images).map(function (image) {
                return new Promise(function (resolve, reject) {
                    function ready() {
                        if (!image.naturalWidth || !image.naturalHeight) { reject(failure('resources')); return; }
                        if (typeof image.decode === 'function') image.decode().then(resolve, function () { reject(failure('resources')); });
                        else resolve();
                    }
                    if (image.complete) ready();
                    else {
                        listen(image, 'load', ready);
                        listen(image, 'error', function () { reject(failure('resources')); });
                    }
                });
            });
            var fontsReady = content.fonts ? content.fonts.ready.then(function () {
                if (Array.from(content.fonts).some(function (font) { return font.status === 'error'; })) throw failure('resources');
            }, function () { throw failure('resources'); }) : Promise.resolve();
            await Promise.all(imagesReady.concat(fontsReady));
            ensureActive();
        }

        try {
            var timedOut = new Promise(function (_, reject) {
                readyTimer = setTimeout(function () { reject(failure('timeout')); }, readyTimeout);
            });
            await Promise.race([prepare(), timedOut]);
            clearTimeout(readyTimer);
            ensureActive();
            var child = frame.contentWindow;
            if (!child || typeof child.print !== 'function') throw failure('unavailable');
            listen(child, 'afterprint', dialogFinished);
            listen(window, 'afterprint', dialogFinished);
            listen(window, 'blur', function () { if (printed) leftWindow = true; });
            listen(window, 'focus', function () { if (printed && leftWindow) dialogFinished(); });
            dialogTimer = setTimeout(cleanup, dialogTimeout);
            printed = true;
            try { child.print(); } catch (_) { printed = false; throw failure('unavailable'); }
            invocationComplete = true;
            // Resolution means the browser print method was invoked. It cannot
            // confirm that a user selected a printer or printed physical paper.
            job.resolve();
            if (finishedEarly) dialogFinished();
            await completion;
        } catch (error) {
            job.reject(error && /^dashboard-print-/.test(error.code || '') ? error : failure('receipt'));
            cleanup();
        }
        job.cancel = null;
    }

    window.addEventListener('pagehide', function () {
        queue.splice(0).forEach(function (job) { job.reject(failure('cancelled')); jobs.delete(job.url.href); });
        if (active && active.cancel) active.cancel();
    });
    window.DashboardPrint = { print: print };
}());
