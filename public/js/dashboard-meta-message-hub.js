(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardMetaMessageHub) { window.DashboardMetaMessageHub.mount(); return; }
    var active = null;
    function mount() {
        var host = document.getElementById('meta-message-hub');
        var bootstrap = document.getElementById('meta-message-hub-bootstrap');
        if (!host || !bootstrap || active && active.host === host) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        var labels = config.labels || {}, sources = new Map(), selected = null, closed = false, denied = false, busy = false;
        var channel = ['messenger','instagram','whatsapp'].indexOf(config.initial_channel) !== -1 ? config.initial_channel : '';
        var list = host.querySelector('[data-hub-threads]'), status = host.querySelector('[data-hub-status]');
        var count = host.querySelector('[data-hub-count]'), more = host.querySelector('[data-hub-more]');
        var unread = host.querySelector('[data-hub-unread]'), empty = host.querySelector('[data-hub-empty]');
        var panes = host.querySelectorAll('[data-hub-pane]'), tabs = host.querySelectorAll('[data-hub-channel]');
        function engine(source) { return source === 'meta' ? window.DashboardMetaInbox : window.DashboardWhatsAppInbox; }
        function node(tag, className, value) {
            var el = document.createElement(tag); if (className) el.className = className;
            if (value !== undefined) el.textContent = String(value); return el;
        }
        function key(source, id) { return source + ':' + Number(id); }
        function threadChannel(source, thread) { return source === 'whatsapp' ? 'whatsapp' : thread.channel; }
        function rows() {
            var result = [];
            sources.forEach(function (source, name) {
                (source.conversations || []).forEach(function (thread) {
                    if (!Number.isSafeInteger(Number(thread.id)) || Number(thread.id) <= 0) return;
                    var kind = threadChannel(name, thread);
                    if (['messenger','instagram','whatsapp'].indexOf(kind) === -1 || channel && kind !== channel) return;
                    result.push({source:name, channel:kind, thread:thread});
                });
            });
            return result.sort(function (a, b) {
                return String(b.thread.last_message_at || '').localeCompare(String(a.thread.last_message_at || ''))
                    || key(a.source, a.thread.id).localeCompare(key(b.source, b.thread.id));
            });
        }
        function updateTabs() { tabs.forEach(function (tab) { tab.setAttribute('aria-pressed', (tab.dataset.hubChannel || '') === channel ? 'true' : 'false'); }); }
        function render() {
            if (closed) return;
            if (denied) { list.textContent = ''; count.textContent = ''; unread.textContent = ''; unread.hidden = true; more.hidden = true; status.textContent = labels.denied || ''; return; }
            var fragment = document.createDocumentFragment(), visible = rows(), totalUnread = 0;
            visible.forEach(function (row) {
                var thread = row.thread, id = key(row.source, thread.id), source = sources.get(row.source);
                var name = thread.name || thread.phone || (labels.customer || 'Customer') + ' #' + thread.id;
                var item = node('li'), button = node('button','wa-inbox-thread'); button.type = 'button';
                button.dataset.hubThread = id; button.setAttribute('aria-current', selected && selected.key === id ? 'true' : 'false');
                var avatar = node('span','wa-inbox-avatar',String(name).trim().charAt(0) || '?'); avatar.setAttribute('aria-hidden','true');
                var body = node('span','wa-inbox-thread-body');
                body.appendChild(node('span','wa-inbox-channel-label', {messenger:'Messenger',instagram:'Instagram',whatsapp:'WhatsApp'}[row.channel]
                    + (thread.account_label ? ' · ' + thread.account_label : '')));
                var heading = node('span','wa-inbox-thread-heading'); heading.appendChild(node('strong','',name));
                var amount = Number((source.unread || {})[thread.id]) || 0; totalUnread += amount;
                if (amount > 0) heading.appendChild(node('span','wa-inbox-unread',amount > 99 ? '99+' : amount));
                var date = new Date(thread.last_message_at), time = node('time','',thread.last_message_at && !isNaN(date.getTime())
                    ? date.toLocaleString(document.documentElement.lang || 'ar', {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : '');
                if (thread.last_message_at) time.dateTime = thread.last_message_at;
                heading.appendChild(time); body.appendChild(heading);
                var preview = thread.preview, types = labels.types || {};
                body.appendChild(node('span','wa-inbox-preview', preview ? (preview.unavailable ? labels.message_unavailable || ''
                    : (preview.direction === 'outbound' ? (labels.business || '') + ': ' : '') + (preview.text || types[preview.type] || types.other || '')) : ''));
                if (thread.name && thread.phone) body.appendChild(node('bdi','wa-inbox-phone',thread.phone));
                button.appendChild(avatar); button.appendChild(body); item.appendChild(button); fragment.appendChild(item);
            });
            list.textContent = ''; list.appendChild(fragment);
            count.textContent = String(Array.from(sources.entries()).reduce(function(total, entry) {
                if (channel && (channel === 'whatsapp') !== (entry[0] === 'whatsapp')) return total;
                return total + (Number(entry[1].total) || 0);
            }, 0));
            status.textContent = visible.length ? '' : sources.size ? labels.empty || '' : labels.loading || '';
            unread.textContent = (document.documentElement.lang === 'ar' ? 'غير مقروء' : 'Unread') + ': ' + totalUnread; unread.hidden = !totalUnread;
            more.hidden = !Array.from(sources.entries()).some(function (entry) {
                return entry[1].has_more && (!channel || (channel === 'whatsapp') === (entry[0] === 'whatsapp'));
            });
        }
        function reset() {
            selected = null;
            ['meta','whatsapp'].forEach(function (source) { var module = engine(source); if (module && module.resetSelection) module.resetSelection(); });
            panes.forEach(function(pane) { pane.hidden = true; }); empty.hidden = false;
            host.classList.remove('has-selection'); render();
        }
        function select(id) {
            var row = rows().find(function(row) { return key(row.source,row.thread.id) === id; });
            if (!row || closed || denied) return;
            if (selected && selected.key === id) { host.classList.add('has-selection'); return; }
            // Release previous drafts and players before publishing the new selection.
            reset(); var module = engine(row.source);
            if (!module || !module.openConversation || !module.openConversation(Number(row.thread.id))) return;
            selected = {key:id,source:row.source}; panes.forEach(function(pane) { pane.hidden = pane.dataset.hubPane !== row.source; });
            empty.hidden = true; host.classList.add('has-selection'); render();
        }
        function snapshot(event) {
            var detail = event.detail || {};
            if (closed || denied || ['meta','whatsapp'].indexOf(detail.source) === -1 || !Array.isArray(detail.conversations)) return;
            sources.set(detail.source, detail); render();
        }
        function conversationReset(event) { if (selected && selected.source === (event.detail || {}).source) reset(); }
        function privateReset(event) {
            if ((event.detail || {}).reason === 'denied') { denied = true; sources.clear(); reset(); }
        }
        function click(event) {
            if (closed || denied) return;
            var tab = event.target.closest('[data-hub-channel]');
            if (tab && host.contains(tab)) { channel = tab.dataset.hubChannel || ''; reset();
                if (window.DashboardMetaInbox && window.DashboardMetaInbox.setChannel) window.DashboardMetaInbox.setChannel(channel === 'whatsapp' ? '' : channel);
                updateTabs(); render(); return; }
            var thread = event.target.closest('[data-hub-thread]');
            if (thread && host.contains(thread)) { select(thread.dataset.hubThread); return; }
            if (event.target.closest('[data-hub-more]') && !busy) {
                busy = true; more.disabled = true;
                Promise.all(Array.from(sources.entries()).filter(function(entry) {
                    return entry[1].has_more && (!channel || (channel === 'whatsapp') === (entry[0] === 'whatsapp'));
                }).map(function(entry) { var module = engine(entry[0]); return module && module.loadMore ? module.loadMore() : Promise.resolve(); }))
                    .finally(function() { busy = false; if (!closed) more.disabled = false; });
            }
            if (event.target.closest('[data-hub-refresh]')) panes.forEach(function(pane) {
                var button = pane.querySelector('[data-wa-refresh]'); if (button) button.click();
            });
        }
        host.addEventListener('click',click); document.addEventListener('meta-hub:threads',snapshot);
        document.addEventListener('meta-hub:conversation-reset',conversationReset);
        document.addEventListener('meta:private-reset',privateReset); document.addEventListener('whatsapp:private-reset',privateReset);
        var session = {host:host,destroy:function() {
            if (closed) return; closed = true;
            host.removeEventListener('click',click); document.removeEventListener('meta-hub:threads',snapshot);
            document.removeEventListener('meta-hub:conversation-reset',conversationReset);
            document.removeEventListener('meta:private-reset',privateReset); document.removeEventListener('whatsapp:private-reset',privateReset);
            sources.clear(); selected = null; list.textContent = ''; count.textContent = ''; unread.textContent = ''; unread.hidden = true;
        }};
        active = session; updateTabs();
        ['meta','whatsapp'].forEach(function(source) { var module = engine(source); if (module && module.snapshot) snapshot({detail:module.snapshot()}); });
        if (window.DashboardMetaInbox && window.DashboardMetaInbox.setChannel) window.DashboardMetaInbox.setChannel(channel === 'whatsapp' ? '' : channel);
        render();
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function() { if (active === session) destroy(); else session.destroy(); });
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardMetaMessageHub = {mount:mount,destroy:destroy};
    document.addEventListener('dashboard:before-unload',destroy); document.addEventListener('dashboard:page-mounted',mount); mount();
}());
