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
    // «إرسال المنيو»: sends this chat's customer a message with a «اطلب من المنيو» button that opens the menu.
    function sendMenu(event) {
        var button = event.target.closest && event.target.closest('[data-chat-menu-send]');
        if (!button || button.disabled) return;
        var meta = Boolean(button.closest('#meta-social-inbox'));
        var inbox = meta ? window.DashboardMetaInbox : window.DashboardWhatsAppInbox;
        var conversation = inbox && typeof inbox.selection === 'function' ? inbox.selection() : 0;
        var replies = button.closest('section, form, [data-wa-replies]') || document;
        var status = replies.querySelector('[data-wa-reply-status]');
        function say(text) { if (status) { status.hidden = false; status.textContent = text; } else button.title = text; }
        if (!conversation) return;
        var token = document.querySelector('meta[name="csrf-token"]');
        button.disabled = true;
        fetch(button.getAttribute('data-chat-menu-url'), {method: 'POST', credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token ? token.content : ''},
            body: JSON.stringify({channel: meta ? 'meta' : 'whatsapp', conversation: conversation})})
            .then(function (response) { return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || data.success !== true) throw new Error(data.message || 'معرفناش نبعت المنيو، جرّب تاني.');
                return data;
            }); })
            .then(function (data) { say(data.message || 'اتبعت المنيو للعميل ✓'); })
            .catch(function (error) { say(error.message); })
            .finally(function () { button.disabled = false; });
    }
    document.addEventListener('click', click);
    document.addEventListener('click', sendMenu);
    window.DashboardChatOrderButton = {click: click, sendMenu: sendMenu};
}());
