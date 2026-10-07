(function () {
    'use strict';
    if (window.DashboardExpenseBadge) return;
    var badge = document.querySelector('[data-expense-pending-count]');
    if (!badge) return;
    var address = new URL(badge.dataset.countUrl, location.href);
    if (address.origin !== location.origin) return;
    var timer, controller, queued = false, stopped = false;
    window.DashboardExpenseBadge = true;
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(refresh, 15000);
    }
    async function refresh() {
        clearTimeout(timer);
        if (stopped || document.hidden) return;
        if (controller) { queued = true; return; }
        controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
            var response = await fetch(address.href, {credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            if ([401,403,419].includes(response.status)) { stopped = true; badge.hidden = true; return; }
            if (!response.ok) return;
            var data = await response.json();
            if (!data.success || !Number.isSafeInteger(data.count) || data.count < 0) return;
            badge.textContent = String(data.count);
            badge.setAttribute('aria-label', data.count + ' ' + badge.dataset.countLabel);
            badge.hidden = data.count === 0;
        } catch (_) {
            // Keep the last confirmed count through a temporary connection failure.
        } finally {
            clearTimeout(timeout); controller = null;
            if (queued) { queued = false; refresh(); } else schedule();
        }
    }
    window.addEventListener('dashboard:expenses-changed', refresh);
    document.addEventListener('dashboard:page-loaded', refresh);
    document.addEventListener('visibilitychange', function () { if (document.hidden) clearTimeout(timer); else refresh(); });
    window.addEventListener('focus', refresh);
    window.addEventListener('pagehide', function () { clearTimeout(timer); if (controller) controller.abort(); });
    window.addEventListener('pageshow', refresh);
    refresh();
}());
