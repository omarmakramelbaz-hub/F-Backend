(function () {
    'use strict';
    if (window.GODashboardIdle) return;
    var tag = document.currentScript, target;
    try { target = new URL(tag && tag.dataset.homeUrl || '/admin/dashboard', location.href); }
    catch (_) { return; }
    if (target.origin !== location.origin || target.pathname !== '/admin/dashboard') return;
    var delay = 5 * 60 * 1000, lastActivity = Date.now(), timer = 0, leaving = false;
    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(check, Math.max(1, delay - (Date.now() - lastActivity)));
    }
    function activity() {
        if (leaving) return;
        lastActivity = Date.now();
        schedule();
    }
    function check() {
        if (Date.now() - lastActivity < delay) { schedule(); return; }
        var hud = document.getElementById('go-hud');
        if (location.pathname === target.pathname && hud && !hud.hidden) {
            lastActivity = Date.now(); schedule(); return;
        }
        leaving = true;
        location.assign(target.href);
    }
    ['pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (event) {
        document.addEventListener(event, activity, {passive: true, capture: true});
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.GODashboardIdle = {timeoutMilliseconds: delay};
    schedule();
})();
