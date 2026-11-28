(function () {
    'use strict';
    // «Analyze and send the order to the branch» beside the reply box: opens this chat's order drawer
    // and runs its analysis, so the employee only reviews the filled order and presses send.
    if (window.DashboardChatOrderButton) return;
    function drawerFor(button) {
        for (var node = button.parentElement; node; node = node.parentElement) {
            var drawer = node.querySelector('[data-wa-order-drawer]');
            if (drawer) return drawer;
        }
        return null;
    }
    function click(event) {
        var button = event.target.closest && event.target.closest('[data-chat-order-analyze]');
        if (!button || button.disabled) return;
        var drawer = drawerFor(button); if (!drawer) return;
        drawer.open = true;
        if (typeof drawer.scrollIntoView === 'function') drawer.scrollIntoView({block: 'start', behavior: 'smooth'});
        button.disabled = true;
        var started = Date.now();
        // The drawer loads this conversation's order state first; analyze as soon as its button is ready.
        (function attempt() {
            var orders = drawer.querySelector('[data-wa-orders]'), analyze = drawer.querySelector('[data-wa-order-analyze]');
            if (orders && !orders.hidden && analyze && !analyze.disabled) { analyze.click(); button.disabled = false; return; }
            if (Date.now() - started > 8000) { button.disabled = false; return; }
            setTimeout(attempt, 250);
        }());
    }
    document.addEventListener('click', click);
    window.DashboardChatOrderButton = {click: click};
}());
