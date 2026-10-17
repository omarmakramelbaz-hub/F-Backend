(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardSupportChat) { window.DashboardSupportChat.mount(); return; }
    var active = null;
    function mount() {
        var panel = document.getElementById('dashboard-support-chat');
        if (!panel || (active && active.panel === panel)) return;
        destroy();
        document.body.classList.add('dashboard-support-open');
        var messages = panel.querySelector('#support-messages'), scroll = panel.querySelector('[data-support-scroll]');
        var status = panel.querySelector('[data-support-status]'), form = panel.querySelector('[data-support-send]');
        var input = panel.querySelector('#support-message-input'), older = panel.querySelector('[data-support-older]');
        var chosen = 0, inbox = Number(panel.dataset.inboxId), nextPage = '', generation = 0;
        var getController = null, unsubscribe = null, interval = null, readTimer = null, readBusy = false, sending = false, readRetryAt = 0;
        var map = new Map(), visible = new Set(), pendingIds = new Set(), closed = false, sendDraft = null;
        var observer = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.5) visible.add(entry.target.dataset.messageId);
                else visible.delete(entry.target.dataset.messageId);
            });
            scheduleRead();
        }, {root: scroll, threshold: [0, 0.5, 1]}) : null;
        function error(text) { status.textContent = text || ''; status.classList.toggle('ds-error', Boolean(text)); }
        function request(url, data, signal) {
            var options = {credentials: 'same-origin', signal: signal, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
            if (data) {
                options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-TOKEN'] = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                options.body = JSON.stringify(data);
            }
            return fetch(url, options).then(function (response) {
                if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('Support unavailable');
                return response.json();
            }).then(function (data) { if (!data.success) throw new Error('Support unavailable'); return data; });
        }
        function messageElement(message) {
            var item = document.createElement('li'); item.className = 'ds-message' + (message.incoming ? ' ds-incoming' : '');
            item.dataset.messageId = message.id;
            var text = document.createElement('p'); text.textContent = message.message;
            var time = document.createElement('time'), date = new Date(message.timestamp);
            time.textContent = isNaN(date.getTime()) ? '' : date.toLocaleString(document.documentElement.lang || 'ar');
            if (message.timestamp) time.dateTime = message.timestamp;
            item.append(text, time); return item;
        }
        function render(rows, prepend, toBottom) {
            var oldHeight = scroll.scrollHeight, oldTop = scroll.scrollTop;
            rows.forEach(function (message) { if (!map.has(message.id) || message.read) map.set(message.id, message); });
            var sorted = Array.from(map.values()).sort(function (a, b) { return String(a.timestamp || '').localeCompare(String(b.timestamp || '')) || a.id.localeCompare(b.id); });
            if (observer) observer.disconnect(); visible.clear(); messages.textContent = '';
            sorted.forEach(function (message) { var item = messageElement(message); messages.appendChild(item); if (observer) observer.observe(item); });
            if (prepend) scroll.scrollTop = oldTop + (scroll.scrollHeight - oldHeight);
            else if (toBottom) scroll.scrollTop = scroll.scrollHeight;
            else scroll.scrollTop = oldTop;
            error(''); status.textContent = sorted.length ? '' : panel.dataset.empty;
            if (!observer) measureVisible();
        }
        function measureVisible() {
            visible.clear();
            var boundary = scroll.getBoundingClientRect();
            messages.querySelectorAll('[data-message-id]').forEach(function (item) {
                var rect = item.getBoundingClientRect();
                if (rect.bottom > boundary.top && rect.top < boundary.bottom) visible.add(item.dataset.messageId);
            }); scheduleRead();
        }
        function scheduleRead() {
            if (document.hidden || closed) return;
            clearTimeout(readTimer); readTimer = setTimeout(markVisible, Math.max(350, readRetryAt - Date.now()));
        }
        function markVisible() {
            if (closed || readBusy || document.hidden || !chosen) return;
            var ids = Array.from(visible).filter(function (id) { var msg = map.get(id); return msg && msg.incoming && !msg.read && !pendingIds.has(id); }).slice(0, 100);
            if (!ids.length) return;
            var partner = chosen, scope = inbox, current = generation; readBusy = true;
            ids.forEach(function (id) { pendingIds.add(id); });
            request(panel.dataset.baseUrl + '/' + partner + '/read', {ids: ids, inbox_id: scope}).then(function () {
                if (closed || current !== generation || partner !== chosen || scope !== inbox) return;
                ids.forEach(function (id) { if (map.has(id)) map.get(id).read = true; });
                readRetryAt = 0;
                if (window.DashboardInbox) window.DashboardInbox.refreshSupport(true);
            }).catch(function () { readRetryAt = Date.now() + 15000; }).finally(function () {
                readBusy = false; ids.forEach(function (id) { pendingIds.delete(id); });
                if (!closed) scheduleRead();
            });
        }
        function load(previous) {
            if (closed || !chosen) return;
            if (getController) getController.abort();
            getController = new AbortController();
            var current = generation, partner = chosen, scope = inbox, page = previous ? nextPage : '';
            var url = panel.dataset.baseUrl + '/' + partner + '/messages?inbox_id=' + scope;
            if (page) url += '&page_token=' + encodeURIComponent(page);
            var nearBottom = scroll.scrollHeight - scroll.clientHeight - scroll.scrollTop < 90;
            older.disabled = true;
            request(url, null, getController.signal).then(function (data) {
                if (closed || generation !== current || chosen !== partner || inbox !== scope) return;
                render(data.messages || [], previous, !previous && nearBottom);
                if (previous || !nextPage) nextPage = data.next_page_token || '';
                older.hidden = !nextPage;
                subscribe(data.room, current, partner, scope);
            }).catch(function (reason) {
                if (!closed && current === generation && reason.name !== 'AbortError') error(panel.dataset.error);
            }).finally(function () { if (!closed && current === generation) older.disabled = false; });
        }
        function subscribe(room, current, partner, scope) {
            if (unsubscribe || !window.firebase || !firebase.firestore) return;
            try {
                unsubscribe = firebase.firestore().collection('DashboardChat').doc(room).collection('messages')
                    .orderBy('timestamp', 'desc').limit(100).onSnapshot(function (snapshot) {
                        if (closed || current !== generation || partner !== chosen || scope !== inbox) return;
                        var rows = [], nearBottom = scroll.scrollHeight - scroll.clientHeight - scroll.scrollTop < 90;
                        snapshot.forEach(function (doc) {
                            var msg = doc.data(), from = Number(msg.sender_id), to = Number(msg.user_id);
                            if (from === to || ![scope, partner].includes(from) || ![scope, partner].includes(to)) return;
                            rows.push({id: doc.id, message: String(msg.message || ''), sender_id: from, recipient_id: to,
                                timestamp: msg.timestamp && msg.timestamp.toDate ? msg.timestamp.toDate().toISOString() : null,
                                incoming: to === scope, read: Boolean(msg.dashboard_support_read_at)});
                        });
                        render(rows.reverse(), false, nearBottom);
                        if (window.DashboardInbox) window.DashboardInbox.refreshSupport(true);
                    }, function () {});
            } catch (reason) {}
        }
        function choose(partner, name) {
            if (!partner || closed) return;
            generation++; chosen = Number(partner); nextPage = ''; map.clear(); visible.clear(); pendingIds.clear();
            if (getController) getController.abort(); if (unsubscribe) { unsubscribe(); unsubscribe = null; }
            messages.textContent = ''; older.hidden = true; form.hidden = false; input.value = '';
            panel.querySelector('[data-support-title]').textContent = name || '';
            panel.querySelectorAll('[data-support-partner]').forEach(function (button) { button.setAttribute('aria-current', Number(button.dataset.supportPartner) === chosen ? 'true' : 'false'); });
            error(''); status.textContent = panel.dataset.loading;
            var url = new URL(window.location.href);
            if (/\/admin\/chat\/?$/.test(url.pathname)) { url.searchParams.set('user_id', chosen); url.searchParams.set('inbox_id', inbox); history.replaceState(history.state, '', url); }
            load(false);
        }
        function click(event) {
            var user = event.target.closest('[data-support-partner]');
            if (user && panel.contains(user)) choose(user.dataset.supportPartner, user.dataset.supportName);
            if (event.target.closest('[data-support-older]') && nextPage) load(true);
        }
        function search(event) {
            if (event.target.id !== 'support-user-search') return;
            var term = event.target.value.toLocaleLowerCase();
            panel.querySelectorAll('[data-support-partner]').forEach(function (button) { button.parentElement.hidden = !button.textContent.toLocaleLowerCase().includes(term); });
        }
        function change(event) {
            if (!event.target.matches('[data-support-inbox]')) return;
            inbox = Number(event.target.value);
            var user = panel.querySelector('[data-support-partner][aria-current="true"]');
            if (user) choose(user.dataset.supportPartner, user.dataset.supportName);
        }
        function send(event) {
            event.preventDefault(); if (sending || !chosen || !input.value.trim()) return;
            var text = input.value, current = generation, partner = chosen, scope = inbox;
            if (!sendDraft || sendDraft.text !== text || sendDraft.partner !== partner || sendDraft.scope !== scope) {
                var key = window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (letter) {
                    var random = Math.floor(Math.random() * 16); return (letter === 'x' ? random : (random & 3) | 8).toString(16);
                });
                sendDraft = {text: text, partner: partner, scope: scope, key: key};
            }
            sending = true; form.querySelector('button').disabled = true; error('');
            request(panel.dataset.baseUrl + '/' + partner + '/messages', {message: text, inbox_id: scope, request_key: sendDraft.key}).then(function (data) {
                if (closed || current !== generation || partner !== chosen || scope !== inbox) return;
                if (input.value === text) input.value = '';
                sendDraft = null;
                render([data.message], false, true); load(false);
            }).catch(function () { if (!closed && current === generation) error(panel.dataset.sendError); })
                .finally(function () { sending = false; if (!closed) form.querySelector('button').disabled = false; });
        }
        function badges(event) {
            var counts = (event.detail || {}).conversations || {};
            panel.querySelectorAll('[data-support-partner-count]').forEach(function (badge) { var count = counts[badge.dataset.supportPartnerCount] || 0; badge.textContent = String(count); badge.hidden = !count; });
        }
        function visibility() { if (!document.hidden) { load(false); scheduleRead(); } }
        panel.addEventListener('click', click); panel.addEventListener('input', search); panel.addEventListener('change', change); form.addEventListener('submit', send);
        if (!observer) scroll.addEventListener('scroll', measureVisible);
        document.addEventListener('dashboard:support-unread', badges); document.addEventListener('visibilitychange', visibility);
        interval = setInterval(function () { if (!document.hidden) load(false); }, 15000);
        var session = {panel: panel, destroy: function () {
            if (closed) return; closed = true; generation++; clearInterval(interval); clearTimeout(readTimer);
            document.body.classList.remove('dashboard-support-open');
            if (getController) getController.abort(); if (unsubscribe) unsubscribe(); if (observer) observer.disconnect();
            panel.removeEventListener('click', click); panel.removeEventListener('input', search); panel.removeEventListener('change', change); form.removeEventListener('submit', send);
            scroll.removeEventListener('scroll', measureVisible); document.removeEventListener('dashboard:support-unread', badges); document.removeEventListener('visibilitychange', visibility);
        }};
        active = session;
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function () { if (active === session) destroy(); else session.destroy(); });
        var selected = panel.querySelector('[data-support-partner="' + panel.dataset.selected + '"]');
        if (selected) choose(selected.dataset.supportPartner, selected.dataset.supportName);
        if (window.DashboardInbox) window.DashboardInbox.refreshSupport();
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardSupportChat = {mount: mount, destroy: destroy};
    document.addEventListener('dashboard:before-unload', destroy);
    document.addEventListener('dashboard:page-mounted', mount);
    mount();
}());
