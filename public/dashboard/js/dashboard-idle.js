(function () {
    'use strict';
    if (window.GODashboardIdle) return;
    // Server load: the last touch on this device, kept across page loads and windows. Untouched for half an hour, the
    // background requests (prints, alerts, home numbers, notifications) slow down; the first touch brings them back.
    var INPUT_KEY = 'go-dashboard-last-input', QUIET_AFTER = 30 * 60 * 1000, loadedAt = Date.now(), noted = 0, wasQuiet = false;
    function lastInput() {
        try { var value = Number(localStorage.getItem(INPUT_KEY)); if (value > 0 && value <= Date.now() + 60000) return Math.max(value, loadedAt - QUIET_AFTER); } catch (_) {}
        return loadedAt;
    }
    function quiet() { return Date.now() - lastInput() >= QUIET_AFTER; }
    function touched() {
        var now = Date.now(), slept = wasQuiet || quiet();
        if (now - noted > 15000) { noted = now; try { localStorage.setItem(INPUT_KEY, String(now)); } catch (_) {} }
        wasQuiet = false;
        if (slept) { try { window.dispatchEvent(new Event('go:dashboard-awake')); } catch (_) {} }
    }
    try { if (!Number(localStorage.getItem(INPUT_KEY))) localStorage.setItem(INPUT_KEY, String(loadedAt)); } catch (_) {}
    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (event) { document.addEventListener(event, touched, {passive: true, capture: true}); });
    setInterval(function () { if (quiet()) wasQuiet = true; }, 60000);
    var api = { quiet: quiet, every: function (normal, slow) { return quiet() ? slow : normal; } };
    window.GODashboardIdle = api;
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
    api.timeoutMilliseconds = delay;
    schedule();
})();
