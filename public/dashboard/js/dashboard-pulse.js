/* The dashboard's repeated checks (new orders, print jobs, late deliveries, messages, pending expenses) travel together:
   GoPulse.fetch(url, options) is fetch() for every script, but a GET to one of those checks waits for the next pulse
   (at most one every 8 s, never more than 4 s after it was asked) and goes in one request with the checks due then. Each check gets back a
   Response with its own status and body, as if it had gone alone (DashboardPulseController runs it exactly so).
   A lone check goes alone. Whenever the pulse fails (offline in the desktop program, an older server, a session
   that ended), its checks go as their own requests at once and the pulse rests for a few minutes. */
(function () {
    'use strict';
    if (window.GoPulse) return;
    var script = document.currentScript;
    var endpoint = script && script.dataset.pulseUrl;
    var CHECKS = [/^\/admin\/getOrders$/, /^\/admin\/phone-orders\/print-jobs$/, /^\/admin\/branch-shift-print\/jobs$/,
        /^\/admin\/app-order-print\/jobs$/, /^\/admin\/phone-orders\/late$/, /^\/admin\/dashboard-inbox\/notifications$/,
        /^\/admin\/branch-expenses\/pending-count$/, /^\/admin\/dashboard\/overview$/];
    // A check waits at most WAIT for the next pulse (the pages' own time limits are 10 s or more).
    var TICK = 8000, WAIT = 4000, GATHER = 60, MAX = 8, REST = 5 * 60 * 1000, TIMEOUT = 20000;
    var NULL_BODY = [101, 204, 205, 304];
    var queue = [], timer = 0, sending = false, lastSent = 0, restUntil = 0;
    var nativeFetch = window.fetch ? window.fetch.bind(window) : null;

    function header(options, name) {
        var headers = options && options.headers;
        if (!headers) return '';
        if (typeof headers.get === 'function') return headers.get(name) || '';
        var key = Object.keys(headers).find(function (item) { return item.toLowerCase() === name.toLowerCase(); });
        return key ? String(headers[key]) : '';
    }

    function check(input, options) {
        if (!endpoint || !nativeFetch || typeof window.Response !== 'function' || Date.now() < restUntil) return null;
        if (typeof input !== 'string' && !(input instanceof URL)) return null;
        if (options && ((options.method && String(options.method).toUpperCase() !== 'GET') || options.body)) return null;
        var url;
        try { url = new URL(String(input), location.href); } catch (_) { return null; }
        if (url.origin !== location.origin || !CHECKS.some(function (pattern) { return pattern.test(url.pathname); })) return null;
        return url;
    }

    function abortError() {
        try { return new DOMException('The operation was aborted.', 'AbortError'); } catch (_) { var error = new Error('Aborted'); error.name = 'AbortError'; return error; }
    }

    function settle(item, ok, value) {
        if (item.done) return;
        item.done = true;
        if (item.signal) item.signal.removeEventListener('abort', item.onAbort);
        (ok ? item.resolve : item.reject)(value);
    }

    /** The check as its own request (a lone check, a pulse that failed, or an answer only its own request gives). */
    function alone(item) {
        if (item.done) return;
        nativeFetch(item.input, item.options).then(function (response) { settle(item, true, response); }, function (error) { settle(item, false, error); });
    }

    function answered(item, answer) {
        if (item.done) return;
        var status = answer && Number(answer.status);
        // Refused by the pulse, or a redirect (a sign-in page): its own request answers exactly as before.
        if (!status || status < 200 || status > 599 || (status >= 300 && status < 400) || status === 400 || typeof answer.body !== 'string') { alone(item); return; }
        var body = NULL_BODY.indexOf(status) >= 0 ? null : answer.body;
        settle(item, true, new Response(body, { status: status, headers: { 'Content-Type': answer.type || 'text/plain' } }));
    }

    function schedule() {
        if (timer || sending || !queue.length) return;
        var oldest = queue.reduce(function (first, item) { return Math.min(first, item.at); }, Infinity);
        timer = setTimeout(flush, Math.max(GATHER, Math.min(lastSent + TICK, oldest + WAIT) - Date.now()));
    }

    function flush() {
        timer = 0;
        var batch = queue.splice(0, MAX).filter(function (item) { return !item.done; });
        if (!batch.length) { schedule(); return; }
        lastSent = Date.now();
        if (batch.length === 1 || Date.now() < restUntil) { batch.forEach(alone); schedule(); return; }
        sending = true;
        var address = new URL(endpoint, location.href);
        batch.forEach(function (item, index) {
            address.searchParams.append('r[' + index + '][u]', item.url.pathname + item.url.search);
            if (item.accept) address.searchParams.append('r[' + index + '][a]', item.accept);
            if (item.ajax) address.searchParams.append('r[' + index + '][x]', '1');
        });
        var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, TIMEOUT);
        nativeFetch(address.href, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                // The answers come as plain text (never kept by the desktop program's offline copy): see DashboardPulseController.
                if (!response.ok || !/^text\/plain/i.test(response.headers.get('Content-Type') || '')) throw new Error('pulse');
                return response.text().then(function (text) { return JSON.parse(text); });
            })
            .then(function (data) {
                if (!data || !Array.isArray(data.answers) || data.answers.length !== batch.length) throw new Error('pulse');
                batch.forEach(function (item, index) { answered(item, data.answers[index]); });
            })
            .catch(function () {
                restUntil = Date.now() + REST;
                batch.forEach(alone);
            })
            .finally(function () { clearTimeout(timeout); sending = false; schedule(); });
    }

    function pulseFetch(input, options) {
        var url = check(input, options);
        if (!url) return nativeFetch(input, options);
        return new Promise(function (resolve, reject) {
            var signal = options && options.signal;
            if (signal && signal.aborted) { reject(abortError()); return; }
            var item = { input: input, options: options, url: url, resolve: resolve, reject: reject, signal: signal, done: false, at: Date.now(),
                accept: header(options, 'Accept'), ajax: header(options, 'X-Requested-With') === 'XMLHttpRequest' };
            if (signal) {
                item.onAbort = function () { settle(item, false, abortError()); };
                signal.addEventListener('abort', item.onAbort);
            }
            queue.push(item);
            schedule();
        });
    }

    window.GoPulse = { fetch: pulseFetch, CHECKS: CHECKS, TICK: TICK, WAIT: WAIT };
})();
