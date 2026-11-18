/* Silent login avatar. Authentication remains owned by the existing login form. */
(function () {
    'use strict';
    var host = document.querySelector('[data-go-login-hologram]');
    var canvas = host && host.querySelector('[data-go-login-canvas]');
    if (!host || !canvas || host.goLoginHologram) return;
    var robot = null, listening = false, destroyed = false;
    function gaze(event) {
        if (!robot || document.hidden || event.pointerType === 'touch' || !robot.setGaze) return;
        var bounds = document.documentElement.getBoundingClientRect();
        var width = Math.max(1, document.documentElement.clientWidth), height = Math.max(1, window.innerHeight);
        robot.setGaze(Math.max(-1, Math.min(1, (event.clientX - bounds.left) / width * 2 - 1)), Math.max(-1, Math.min(1, event.clientY / height * 2 - 1)));
    }
    function rest() { if (robot && robot.setGaze) robot.setGaze(0, 0); }
    function visibility() { if (robot && robot.setVisible) robot.setVisible(!document.hidden); if (document.hidden) rest(); }
    function start() {
        if (destroyed || robot || !canvas.isConnected || !window.GoHologram) return;
        try { robot = window.GoHologram.mount(canvas, {artwork: true, compact: false, background: true, fullViewport: true, interactive: false}); } catch (_) { robot = null; }
        if (!robot) { host.dataset.loginRenderer = 'fallback'; return; }
        host.dataset.loginRenderer = 'living';
        document.addEventListener('pointermove', gaze, {passive: true});
        document.addEventListener('pointerleave', rest);
        document.addEventListener('visibilitychange', visibility);
        listening = true; visibility();
    }
    function stop() {
        if (listening) {
            document.removeEventListener('pointermove', gaze);
            document.removeEventListener('pointerleave', rest);
            document.removeEventListener('visibilitychange', visibility);
            listening = false;
        }
        if (robot) robot.destroy(); robot = null;
        delete host.dataset.portraitGpu; host.dataset.loginRenderer = 'paused';
    }
    function pageshow(event) { if (event.persisted) start(); }
    function destroy() {
        if (destroyed) return;
        stop(); destroyed = true;
        window.removeEventListener('pagehide', stop); window.removeEventListener('pageshow', pageshow);
        delete host.goLoginHologram;
    }
    host.goLoginHologram = {start: start, stop: stop, destroy: destroy, diagnostics: function () { return robot && robot.diagnostics ? robot.diagnostics() : null; }};
    window.addEventListener('pagehide', stop); window.addEventListener('pageshow', pageshow);
    start();
})();
