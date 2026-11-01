(function () {
    'use strict';
    if (window.GoHologramShell || !window.GoHologram) return;
    var canvas = document.querySelector('[data-go-sidebar-robot]');
    var robot = canvas ? window.GoHologram.mount(canvas, {compact: true, background: false, imageSrc: canvas.dataset.goHologramSrc}) : null;
    function alertState(event) { if (robot) robot.setAlert(!!(event.detail && event.detail.pending && event.detail.pending.length)); }
    document.addEventListener('dashboard:order-alert-state', alertState);
    if (window.DashboardOrderAlerts) alertState({detail: window.DashboardOrderAlerts.state()});
    // This instance lives with the shell across in-app navigation, never speaks.
    window.GoHologramShell = {robot: robot};
    window.addEventListener('pagehide', function (event) { if (!event.persisted && robot) { robot.destroy(); robot = null; } });
})();
