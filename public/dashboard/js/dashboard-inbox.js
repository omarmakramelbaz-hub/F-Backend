(function () {
    'use strict';
    if (window.DashboardInbox) return;
    var shell = document.querySelector('[data-dashboard-inbox]');
    if (!shell) return;
    var pendingRead = false, noteBusy = false, supportBusy = false, supportCount = 0;
    var roomUnsubscribe = [], refreshTimer = null, fallbackTimer = null, noteGeneration = 0;
    function request(url, data) {
        var options = {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
        if (data) {
            options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
            var csrf = document.querySelector('meta[name="csrf-token"]');
            options.headers['X-CSRF-TOKEN'] = csrf ? csrf.content : '';
            options.body = JSON.stringify(data);
        }
        return fetch(url, options).then(function (response) {
            if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('Inbox unavailable');
            return response.json();
        }).then(function (result) { if (!result.success) throw new Error('Inbox unavailable'); return result; });
    }
    function notificationCount(count) {
        shell.querySelectorAll('[data-notification-count]').forEach(function (badge) { badge.textContent = String(count); badge.hidden = count < 1; });
        shell.querySelectorAll('[data-notification-header-count]').forEach(function (el) { el.textContent = String(count); });
    }
    function supportBadges(result) {
        supportCount = Number(result.count) || 0;
        document.querySelectorAll('[data-support-unread]').forEach(function (badge) {
            badge.textContent = String(supportCount); badge.hidden = !supportCount;
            badge.style.backgroundColor = '#fd7201'; badge.style.color = '#fff';
        });
        document.dispatchEvent(new CustomEvent('dashboard:support-unread', {detail: result}));
    }
    function refreshNotifications() {
        if (noteBusy || pendingRead || document.hidden) return Promise.resolve();
        noteBusy = true;
        var generation = noteGeneration;
        return request(shell.dataset.notificationsUrl).then(function (result) {
            if (generation !== noteGeneration) return;
            notificationCount(result.count);
            var list = shell.querySelector('#dashboard-notification-list');
            if (!list) return;
            list.textContent = '';
            result.notifications.forEach(function (note) {
                var a = document.createElement('a'); a.className = 'dropdown-item';
                a.href = note.url; a.dataset.notificationId = note.id;
                var icon = document.createElement('i'); icon.className = 'fas fa-envelope me-2';
                var title = document.createElement('span'); title.textContent = note.title;
                var time = document.createElement('small'); time.className = 'd-block text-muted';
                var date = new Date(note.created_at); time.textContent = isNaN(date.getTime()) ? '' : date.toLocaleString(document.documentElement.lang || 'ar');
                a.append(icon, title, time); list.appendChild(a);
            });
        }).catch(function () {}).finally(function () { noteBusy = false; });
    }
    function readNotifications() {
        if (pendingRead) return;
        var ids = Array.from(shell.querySelectorAll('[data-notification-id]')).map(function (item) { return item.dataset.notificationId; });
        if (!ids.length) return;
        pendingRead = true;
        noteGeneration++;
        var error = shell.querySelector('[data-inbox-read-error]');
        if (error) error.hidden = true;
        request(shell.dataset.notificationsReadUrl, {ids: ids}).then(function (result) {
            var read = new Set(ids);
            shell.querySelectorAll('[data-notification-id]').forEach(function (item) { if (read.has(item.dataset.notificationId)) item.remove(); });
            notificationCount(result.count);
            // Leave the dropdown open; read notifications remain in the history page.
        }).catch(function () {
            if (error) { error.textContent = shell.dataset.inboxReadFailed; error.hidden = false; }
        }).finally(function () { pendingRead = false; refreshNotifications(); });
    }
    function refreshSupport(fresh) {
        if (!shell.dataset.supportUrl || supportBusy || document.hidden) return Promise.resolve();
        supportBusy = true;
        return request(shell.dataset.supportUrl + (fresh ? '?fresh=1' : '')).then(supportBadges).catch(function () {})
            .finally(function () { supportBusy = false; });
    }
    function queueSupport() {
        clearTimeout(refreshTimer); refreshTimer = setTimeout(function () { refreshSupport(true); }, 600);
    }
    // The existing app updates each room's lastMessageTimestamp for every message.
    // Listen only to this account's inbox, then ask the authenticated server for its actual unread count.
    function subscribeSupport() {
        if (!shell.dataset.supportUrl || !window.firebase || !firebase.firestore || roomUnsubscribe.length) return;
        try {
            String(shell.dataset.inboxIds || '').split(',').filter(Boolean).forEach(function (id) {
                roomUnsubscribe.push(firebase.firestore().collection('DashboardChat')
                    .where('users', 'array-contains', id).onSnapshot(queueSupport, function () {}));
            });
        } catch (error) {}
    }
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('#dropdownMenuLink');
        if (toggle && toggle.getAttribute('aria-expanded') !== 'true') readNotifications();
    });
    document.addEventListener('shown.bs.dropdown', function (event) {
        if (event.target.closest('[data-notification-dropdown]')) readNotifications();
    });
    if (window.jQuery) jQuery(document).on('shown.bs.dropdown.dashboardInbox', '[data-notification-dropdown]', readNotifications);
    document.addEventListener('dashboard:page-mounted', function () { refreshNotifications(); refreshSupport(); supportBadges({count: supportCount}); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshNotifications(); refreshSupport(); } });
    window.DashboardInbox = {refreshNotifications: refreshNotifications, refreshSupport: refreshSupport, request: request};
    refreshNotifications(); refreshSupport(); subscribeSupport();
    fallbackTimer = setInterval(function () { refreshNotifications(); refreshSupport(); subscribeSupport(); }, 15000);
    window.addEventListener('pagehide', function () {
        clearInterval(fallbackTimer); clearTimeout(refreshTimer);
        roomUnsubscribe.forEach(function (off) { off(); });
    });
}());
