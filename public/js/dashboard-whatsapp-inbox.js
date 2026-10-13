(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardWhatsAppInbox) { window.DashboardWhatsAppInbox.mount(); return; }

    var active = null;
    function mount() {
        var panel = document.getElementById('whatsapp-inbox');
        var bootstrap = document.getElementById('whatsapp-inbox-bootstrap');
        if (!panel || !bootstrap || (active && active.panel === panel)) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        var namespace = '', legacyWhatsApp = false;
        try {
            var messagesRoute = new URL(config.messages_base_url, window.location.href);
            namespace = messagesRoute.origin + messagesRoute.pathname.replace(/\/+$/, '');
            legacyWhatsApp = /\/whatsapp(?:\/|$)/.test(messagesRoute.pathname);
        } catch (error) {}
        var labels = config.labels || {};
        var threads = panel.querySelector('[data-wa-thread-list]');
        var listStatus = panel.querySelector('[data-wa-list-status]');
        var count = panel.querySelector('[data-wa-count]');
        var messages = panel.querySelector('[data-wa-messages]');
        var chatStatus = panel.querySelector('[data-wa-chat-status]');
        var title = panel.querySelector('[data-wa-title]');
        var customer = panel.querySelector('[data-wa-customer]');
        var alert = panel.querySelector('[data-wa-alert]');
        var connection = panel.querySelector('[data-wa-connection]');
        var scroll = panel.querySelector('[data-wa-scroll]');
        var older = panel.querySelector('[data-wa-older]');
        var more = panel.querySelector('[data-wa-more-threads]');
        var refresh = panel.querySelector('[data-wa-refresh]');
        var back = panel.querySelector('[data-wa-back]');
        var unreadTotal = panel.querySelector('[data-wa-unread-total]');
        var threadScroll = panel.querySelector('[data-wa-thread-scroll]') || threads;
        var threadSentinel = panel.querySelector('[data-wa-conversation-sentinel]');
        var messageSentinel = panel.querySelector('[data-wa-message-sentinel]');
        var newMessagesButton = panel.querySelector('[data-wa-new-messages]');
        var selectionToolbar = panel.querySelector('[data-wa-selection-toolbar]');
        var selectionCount = panel.querySelector('[data-wa-selection-count]');
        var selectAllButton = panel.querySelector('[data-wa-select-all]');
        var deleteSelected = panel.querySelector('[data-wa-delete-selected]');
        var selectModeButton = panel.querySelector('[data-wa-select-mode]');
        var selectionHint = panel.querySelector('[data-wa-selection-hint]');
        var threadMap = new Map(), messageMap = new Map(), apiReplyMap = new Map(), controllers = new Map();
        var unreadMap = new Map(), readSeen = new Map(), readPending = new Map(), readTimer = null;
        var channel = '', selectedChannel = '', threadEpoch = 0, chosen = 0, generation = 0, nextCursor = null, beforeId = null, lastId = 0;
        var pollDelay = Math.max(1500, Math.min(5000, Number(config.poll_interval_ms) || 2000));
        var closed = false, pollBusy = false, pollAgain = false, timer = null, retryDelay = pollDelay, denied = false;
        var threadLoading = false, messageLoading = false, messageStarted = false, projectionWarning = false;
        var hasPagedThreads = false;
        var threadJob = null, messageJob = null, unreadJob = null, edgeTimer = null, observers = [];
        var renderedMap = new Map(), scrollAnchor = null, newMessagesCount = 0;
        var pendingOlder = false;
        var apiHistoryKeys = new Set(), apiRecentKeys = new Set(), apiHistoryBefore = null, apiHistoryStarted = false, historyJob = null, updatesJob = null, historyEpoch = 0, historyFillTimer = null;
        var hiddenMessages = new Set(), hiddenReplies = new Set(), hiddenCutoffs = null;
        var actionMenu = null, forwardPicker = null, actionBusy = false;
        var actionNotice = null;
        var selecting = false, selectedKeys = new Set(), selectionSnapshot = null, selectionAll = false, selectionBusy = false, selectionConfirm = null, selectionEpoch = 0;

        function label(key, english, arabic) {
            return labels[key] || (/^ar(?:-|$)/i.test(document.documentElement.lang || 'ar') ? arabic : english);
        }
        function nearBottom() { return scroll.clientHeight > 0 ? scroll.scrollHeight - scroll.clientHeight - scroll.scrollTop < 90 : Boolean(scrollAnchor && scrollAnchor.bottom); }
        function rememberAnchor() {
            var bounds = typeof scroll.getBoundingClientRect === 'function' ? scroll.getBoundingClientRect() : null;
            if (bounds && bounds.bottom <= bounds.top) return;
            scrollAnchor = {bottom: nearBottom(), key: null, top: 0};
            if (!bounds) return;
            Array.from(messages.children).some(function (item) {
                if (typeof item.getBoundingClientRect !== 'function') return false;
                var rectangle = item.getBoundingClientRect();
                if (rectangle.bottom > bounds.top) { scrollAnchor.key = item.dataset.waMessageId || item.dataset.waApiReplyId; scrollAnchor.top = rectangle.top; return true; }
                return false;
            });
        }
        function restoreAnchor() {
            if (!scrollAnchor) return;
            if (typeof scroll.getBoundingClientRect === 'function') { var bounds = scroll.getBoundingClientRect(); if (bounds.bottom <= bounds.top) return; }
            if (scrollAnchor.bottom) { scroll.scrollTop = scroll.scrollHeight; return; }
            Array.from(messages.children).some(function (item) {
                if ((item.dataset.waMessageId || item.dataset.waApiReplyId) !== scrollAnchor.key || typeof item.getBoundingClientRect !== 'function') return false;
                scroll.scrollTop += item.getBoundingClientRect().top - scrollAnchor.top; return true;
            });
        }
        function newMessagesIndicator() {
            if (!newMessagesButton) return;
            newMessagesButton.hidden = !newMessagesCount;
            newMessagesButton.textContent = label('new_messages', 'New messages', 'رسائل جديدة') + (newMessagesCount ? ' (' + newMessagesCount + ')' : '');
        }
        function closeActions() {
            if (actionMenu && actionMenu.parentNode) actionMenu.parentNode.removeChild(actionMenu);
            if (forwardPicker && forwardPicker.parentNode) forwardPicker.parentNode.removeChild(forwardPicker);
            actionMenu = null; forwardPicker = null;
        }
        function resetSelection() {
            selectionEpoch++; selectionBusy = false;
            selecting = false; selectedKeys.clear(); selectionSnapshot = null; selectionAll = false;
            if (selectionConfirm && selectionConfirm.parentNode) selectionConfirm.parentNode.removeChild(selectionConfirm);
            selectionConfirm = null; if (selectionToolbar) selectionToolbar.hidden = true;
            updateSelection();
        }
        function selectionSize() { return selectionAll && selectionSnapshot ? selectionSnapshot.total_count : selectedKeys.size; }
        function updateSelection() {
            if (selectionToolbar) selectionToolbar.hidden = !selecting;
            if (!selectionHint && selectionToolbar) { selectionHint = node('span', 'wa-selection-hint'); selectionToolbar.appendChild(selectionHint); }
            if (selectionHint) selectionHint.textContent = selectionAll
                ? label('selection_all_hint', 'All messages are selected. Cancel Select all to choose individual messages.', 'كل الرسائل محددة. ألغِ تحديد الكل لاختيار رسائل معينة.')
                : selectedKeys.size >= 100 ? label('selection_limit', 'Select up to 100 messages, or use Select all.', 'اختر حتى 100 رسالة أو استخدم اختيار الكل.')
                    : label('selection_hint', 'Choose individual messages or all messages in this conversation.', 'اختر رسائل معينة أو كل رسائل المحادثة.');
            if (selectModeButton) selectModeButton.disabled = closed || denied || !chosen || selectedChannel !== 'whatsapp' || !config.actions_base_url;
            if (selectionCount) selectionCount.textContent = String(selectionSize());
            if (deleteSelected) deleteSelected.disabled = selectionBusy || !selectionSnapshot || !selectionSize();
            if (selectAllButton) selectAllButton.disabled = selectionBusy || !selectionSnapshot || selectionSnapshot.select_all_available !== true;
        }
        function snapshotIncludes(key) {
            if (!selectionSnapshot) return false;
            if (key.indexOf('api-reply:') === 0) {
                var id = Number(key.slice(10)), reply = apiReplyMap.get(key), projection = reply && reply.projection_id;
                if (!Number.isSafeInteger(projection) || projection < 1) messageMap.forEach(function (message) {
                    if (message.api_reply_id === id && Number.isSafeInteger(message.api_projection_id) && message.api_projection_id > 0) projection = message.api_projection_id;
                });
                return Number.isSafeInteger(projection) && projection > 0 && projection <= selectionSnapshot.max_projection_id;
            }
            return Number(key) > 0 && Number(key) <= selectionSnapshot.max_message_id;
        }
        function isSelected(key) { return selectionAll ? snapshotIncludes(key) : selectedKeys.has(key); }
        function selectionKey(message, api) {
            if (api) return 'api-reply:' + message.id;
            var reply = Number.isSafeInteger(message.api_reply_id) && message.api_reply_id > 0 ? message.api_reply_id : null;
            if (!reply) apiReplyMap.forEach(function (audit) { if (audit.projected_message_id === message.id) reply = audit.id; });
            return reply && snapshotIncludes('api-reply:' + reply) ? 'api-reply:' + reply : String(message.id);
        }
        function selectionControl(item, message, api) {
            if (!selecting || message.deleted || message.unavailable || (message.actions || {}).delete !== true) return;
            var key = selectionKey(message, api);
            var control = node('input', 'wa-message-selection-control'); control.type = 'checkbox';
            control.dataset.waMessageSelect = key; control.checked = isSelected(key);
            control.disabled = selectionBusy || selectionAll || !snapshotIncludes(key);
            control.setAttribute('aria-label', label('select_message', 'Select message', 'اختيار الرسالة'));
            item.classList.add('is-selectable'); item.classList.toggle('is-selected', control.checked); item.appendChild(control);
        }
        function selectionEndpoint() { return config.actions_base_url.replace(/\/+$/, '') + '/' + chosen + '/messages'; }
        function startSelection(all) {
            if (closed || denied || document.hidden || navigator.onLine === false || selectedChannel !== 'whatsapp' || !chosen || !config.actions_base_url || selectionBusy) return;
            if (!selecting) { selecting = true; selectedKeys.clear(); selectionSnapshot = null; selectionAll = false; }
            var current = generation, selection = chosen, epoch = ++selectionEpoch; selectionBusy = true; updateSelection();
            request(selectionEndpoint() + '/selection', 'selection').then(function (data) {
                if (closed || denied || current !== generation || chosen !== selection || !selecting) return;
                if (typeof data.snapshot_token !== 'string' || !data.snapshot_token || !Number.isSafeInteger(data.total_count)
                    || data.total_count < 0 || !Number.isSafeInteger(data.max_message_id) || data.max_message_id < 0
                    || !Number.isSafeInteger(data.max_reply_id) || data.max_reply_id < 0
                    || !Number.isSafeInteger(data.max_projection_id) || data.max_projection_id < 0) throw new Error('Unavailable');
                selectionSnapshot = data; selectionAll = all && data.select_all_available === true;
                if (selectionAll) selectedKeys.clear();
                else selectedKeys.forEach(function (key) { if (!snapshotIncludes(key)) selectedKeys.delete(key); });
                selectionBusy = false;
                renderMessages(false, false);
            }).catch(function (error) { if (current === generation && epoch === selectionEpoch) unavailable(error); })
                .finally(function () { if (epoch === selectionEpoch) { selectionBusy = false; updateSelection(); } });
        }
        function deleteSelection(confirm) {
            if (!selecting || !selectionSnapshot || !selectionSize() || selectionBusy || closed || denied) return;
            if (!confirm) {
                if (selectionConfirm && selectionConfirm.parentNode) selectionConfirm.parentNode.removeChild(selectionConfirm);
                selectionConfirm = node('div', 'wa-selection-confirm');
                selectionConfirm.appendChild(node('span', '', label('delete_selected_confirm', 'Hide selected messages from your dashboard only?', 'حذف الرسائل المختارة من داشبورد حسابك فقط؟') + ' (' + selectionSize() + ')'));
                var button = node('button', 'wa-bubble-action', label('delete_dashboard', 'Delete from dashboard', 'حذف من الداشبورد')); button.type = 'button'; button.dataset.waBulkConfirm = 'true';
                selectionConfirm.appendChild(button); if (selectionToolbar) selectionToolbar.appendChild(selectionConfirm); return;
            }
            var current = generation, conversation = chosen, epoch = selectionEpoch, snapshot = selectionSnapshot, all = selectionAll, keys = Array.from(selectedKeys);
            var body = {snapshot_token: snapshot.snapshot_token, select_all: all, message_ids: [], reply_ids: []};
            keys.forEach(function (key) { if (key.indexOf('api-reply:') === 0) body.reply_ids.push(Number(key.slice(10))); else body.message_ids.push(Number(key)); });
            if (!all && keys.length > 100) { notice(label('selection_limit', 'Select up to 100 messages, or use Select all.', 'اختر حتى 100 رسالة أو استخدم اختيار الكل.'), true); return; }
            selectionBusy = true; updateSelection();
            request(selectionEndpoint() + '/delete', 'selection-delete', body).then(function (data) {
                if (closed || denied || current !== generation || conversation !== chosen) return;
                if (data.dashboard_only !== true || !Number.isSafeInteger(data.hidden_count) || data.hidden_count < 0) throw new Error('Unavailable');
                messageMap.forEach(function (message, id) {
                    var key = selectionKey(message, false);
                    if (all ? snapshotIncludes(key) : body.message_ids.indexOf(id) !== -1
                        || key.indexOf('api-reply:') === 0 && body.reply_ids.indexOf(Number(key.slice(10))) !== -1) {
                        hiddenMessages.add(id); messageMap.set(id, tombstone(message));
                    }
                });
                apiReplyMap.forEach(function (reply, key) {
                    if (all ? snapshotIncludes(key) : body.reply_ids.indexOf(reply.id) !== -1) {
                        hiddenReplies.add(reply.id);
                        apiReplyMap.delete(key);
                        var echo = messageMap.get(reply.projected_message_id);
                        if (echo) messageMap.set(echo.id, {id: echo.id, direction: echo.direction, type: echo.type, sent_at: echo.sent_at, received_at: echo.received_at, deleted: true});
                    }
                });
                if (all) hiddenCutoffs = {max_message_id: Math.max(hiddenCutoffs && hiddenCutoffs.max_message_id || 0, snapshot.max_message_id),
                    max_projection_id: Math.max(hiddenCutoffs && hiddenCutoffs.max_projection_id || 0, snapshot.max_projection_id)};
                resetSelection(); renderMessages(false, false);
                notice(label('deleted_dashboard', 'Hidden from your dashboard only.', 'تم الحذف من داشبورد حسابك فقط.') + ' (' + data.hidden_count + ')', false);
                emit('whatsapp:conversation-updated', {conversation: conversation}); wake();
            }).catch(function (error) { if (current === generation && conversation === chosen) unavailable(error); })
                .finally(function () { if (epoch === selectionEpoch) { selectionBusy = false; updateSelection(); } });
        }

        function emit(name, detail) {
            if (document.dispatchEvent && typeof CustomEvent !== 'undefined') {
                document.dispatchEvent(new CustomEvent(name, {detail: detail || {}}));
            }
        }

        function node(tag, className, text) {
            var element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== undefined && text !== null) element.textContent = String(text);
            return element;
        }
        function dateText(value, full) {
            var date = new Date(value);
            if (!value || isNaN(date.getTime())) return '';
            return full ? date.toLocaleString(document.documentElement.lang || 'ar')
                : date.toLocaleDateString(document.documentElement.lang || 'ar', {day: 'numeric', month: 'short'})
                    + ' · ' + date.toLocaleTimeString(document.documentElement.lang || 'ar', {hour: '2-digit', minute: '2-digit'});
        }
        function messageStamp(value) {
            return typeof value === 'string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(value)
                ? value.replace(' ', 'T') + 'Z' : value;
        }
        function typeText(type) { return (labels.types || {})[type] || (labels.types || {}).other || ''; }
        function cartNode(cart) {
            if (!cart || typeof cart !== 'object' || !Array.isArray(cart.product_items)
                || !cart.product_items.length || cart.product_items.length > 30
                || typeof cart.total_price !== 'string' || typeof cart.currency !== 'string') return null;
            var section = node('section', 'wa-inbox-cart');
            section.appendChild(node('strong', 'wa-inbox-cart-title', labels.cart_title || typeText('order')));
            var list = node('ul', 'wa-inbox-cart-items');
            cart.product_items.forEach(function (line) {
                var item = node('li', 'wa-inbox-cart-item');
                var product = typeof line.name === 'string' && line.name ? line.name : String(line.product_retailer_id || '');
                item.appendChild(node('span', 'wa-inbox-cart-product', (labels.cart_product || '') + ': ' + product));
                if (typeof line.name === 'string' && line.name) item.appendChild(node('span', 'wa-inbox-cart-details', String(line.product_retailer_id || '')));
                item.appendChild(node('span', 'wa-inbox-cart-details', (labels.cart_quantity || '') + ': ' + String(line.quantity || '')
                    + ' · ' + (labels.cart_unit_price || '') + ': ' + String(line.item_price || '') + ' ' + String(line.currency || '')));
                list.appendChild(item);
            });
            section.appendChild(list);
            section.appendChild(node('p', 'wa-inbox-cart-total', (labels.cart_total || '') + ': ' + cart.total_price + ' ' + cart.currency));
            section.appendChild(node('p', 'wa-inbox-cart-note', labels.cart_note || ''));
            if (typeof cart.text === 'string' && cart.text) section.appendChild(node('p', 'wa-inbox-message-text', cart.text));
            return section;
        }
        function displayName(thread) { return thread.name || thread.phone || labels.customer + ' #' + thread.id; }
        function actionButton(action, text, id) {
            var button = node('button', 'wa-bubble-action', text); button.type = 'button';
            button.dataset.waAction = action; if (id) button.dataset.waActionMessage = String(id);
            return button;
        }
        function reaction(value) {
            if (!value || value.state !== 'ACCEPTED' || value.source !== 'dashboard_api'
                || ['👍', '❤️', '😂', '😮', '😢', '🙏', '✅', ''].indexOf(value.emoji) === -1
                || !Number.isSafeInteger(value.actor_id) || value.actor_id < 1 || !Number.isSafeInteger(value.reply_id) || value.reply_id < 1
                || typeof value.accepted_at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/.test(value.accepted_at)
                || !Number.isFinite(new Date(value.accepted_at).getTime())) return null;
            return {emoji: value.emoji, actor_id: value.actor_id, accepted_at: value.accepted_at, source: 'dashboard_api', state: 'ACCEPTED', reply_id: value.reply_id};
        }
        function reactionPill(message) {
            var accepted = reaction(message.reaction); if (!accepted || !accepted.emoji) return null;
            var pill = node('span', 'wa-message-reaction', accepted.emoji);
            pill.title = label('reaction_accepted', 'Reaction accepted by WhatsApp', 'تفاعل قبله واتساب');
            pill.setAttribute('aria-label', accepted.emoji + ' · ' + pill.title); return pill;
        }
        function newestReaction(previous, value) {
            previous = reaction(previous); var next = reaction(value);
            if (previous && (!next || new Date(next.accepted_at).getTime() < new Date(previous.accepted_at).getTime()
                || next.accepted_at === previous.accepted_at && next.reply_id < previous.reply_id)) return previous;
            return next;
        }
        function tombstone(message) {
            return {id: message.id, direction: message.direction, type: message.type, sent_at: message.sent_at, received_at: message.received_at, deleted: true};
        }
        function replyHidden(reply) {
            return hiddenReplies.has(reply.id) || hiddenCutoffs && Number.isSafeInteger(reply.projection_id) && reply.projection_id > 0 && reply.projection_id <= hiddenCutoffs.max_projection_id;
        }
        function messageHidden(message) {
            return hiddenMessages.has(message.id) || hiddenReplies.has(message.api_reply_id) || hiddenCutoffs && (message.id <= hiddenCutoffs.max_message_id
                || Number.isSafeInteger(message.api_projection_id) && message.api_projection_id > 0 && message.api_projection_id <= hiddenCutoffs.max_projection_id);
        }
        function clearHistory() {
            historyEpoch++;
            clearTimeout(historyFillTimer); historyFillTimer = null;
            apiHistoryKeys.clear(); apiRecentKeys.clear(); apiHistoryBefore = null; apiHistoryStarted = false; historyJob = null; updatesJob = null;
        }
        function clearHidden() { hiddenMessages.clear(); hiddenReplies.clear(); hiddenCutoffs = null; }
        function positionMenu() {
            if (!actionMenu || typeof actionMenu.getBoundingClientRect !== 'function' || typeof scroll.getBoundingClientRect !== 'function') return;
            var bounds = scroll.getBoundingClientRect(), rectangle = actionMenu.getBoundingClientRect();
            actionMenu.classList.toggle('is-above', rectangle.bottom > Math.min(Number(window.innerHeight) || bounds.bottom, bounds.bottom));
        }
        function addActionTrigger(bubble, message, api) {
            if (message.deleted || message.unavailable || api && message.kind !== 'text' && !Object.keys(message.actions || {}).some(function (key) { return message.actions[key] === true; })) return;
            var trigger = node('button', 'wa-bubble-action-trigger', '⋮'); trigger.type = 'button';
            trigger.dataset.waActionMenu = api ? 'api-reply:' + message.id : String(message.id);
            trigger.setAttribute('aria-label', label('message_actions', 'Message actions', 'أدوات الرسالة'));
            trigger.setAttribute('aria-haspopup', 'menu'); bubble.appendChild(trigger);
        }
        function actionContext(key) {
            key = String(key); var api = key.indexOf('api-reply:') === 0, id = Number(api ? key.slice(10) : key);
            if (closed || denied || document.hidden || navigator.onLine === false || selectedChannel !== 'whatsapp'
                || !Number.isSafeInteger(id) || id < 1 || !chosen) return null;
            var message = api ? apiReplyMap.get(key) : messageMap.get(id);
            if (!message || message.deleted || message.unavailable) return null;
            return {message: message, conversation: chosen, generation: generation, api: api};
        }
        function currentAction(context) {
            return context && !closed && !denied && !document.hidden && navigator.onLine !== false
                && chosen === context.conversation && generation === context.generation && selectedChannel === 'whatsapp';
        }
        function actionResult(context, text, error) {
            actionNotice = {conversation: context.conversation, generation: context.generation, text: text, error: error}; notice(text, error);
        }
        function menu(key, trigger) {
            closeActions();
            var api = key.indexOf('api-reply:') === 0, message = api ? apiReplyMap.get(key) : messageMap.get(Number(key));
            if (!message || message.deleted || message.unavailable) return;
            actionMenu = node('div', 'wa-bubble-actions-menu'); actionMenu.setAttribute('role', 'menu');
            if (typeof message.text === 'string' && message.text) actionMenu.appendChild(actionButton('copy', label('copy', 'Copy', 'نسخ'), key));
            if (selectedChannel === 'whatsapp') {
                var actions = message.actions || {};
                if (actions.reply === true) actionMenu.appendChild(actionButton('reply', label('reply', 'Reply', 'رد'), key));
                if (actions.forward === true) actionMenu.appendChild(actionButton('forward', label('forward', 'Forward', 'إعادة توجيه'), key));
                if (actions.react === true) actionMenu.appendChild(actionButton('react', label('react', 'React', 'تفاعل'), key));
                if (actions.delete === true) actionMenu.appendChild(actionButton('delete', label('delete_dashboard', 'Hide from my dashboard', 'إخفاء من داشبورد حسابي'), key));
            }
            if (trigger.parentNode) { trigger.parentNode.appendChild(actionMenu); positionMenu(); }
        }
        function copyText(key) {
            var message = key.indexOf('api-reply:') === 0 ? apiReplyMap.get(key) : messageMap.get(Number(key));
            if (!message || message.deleted || typeof message.text !== 'string' || !message.text
                || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                notice(label('copy_unavailable', 'Copy is unavailable in this browser.', 'النسخ غير متاح في المتصفح الحالي.'), true); return;
            }
            var current = generation, selection = chosen;
            navigator.clipboard.writeText(message.text).then(function () {
                if (current === generation && selection === chosen && !closed && !denied) notice(label('copied', 'Copied.', 'تم النسخ.'), false);
            }).catch(function () { if (current === generation && !closed) notice(label('copy_unavailable', 'Copy is unavailable in this browser.', 'النسخ غير متاح في المتصفح الحالي.'), true); });
            closeActions();
        }
        function quoted(message) {
            var quote = message.quote || message.reply_to;
            if (!quote || typeof quote.text !== 'string' || !quote.text) return null;
            var block = node('blockquote', 'wa-quoted-message');
            block.appendChild(node('span', 'wa-quoted-author', quote.direction === 'outbound' ? labels.business : labels.customer));
            block.appendChild(node('p', '', Array.from(quote.text).slice(0, 500).join(''))); return block;
        }
        function imageNode(message, api) {
            var media = message.media;
            if (!media || media.type !== 'image' || typeof media.url !== 'string' || message.deleted || message.unavailable) return null;
            try {
                var source = new URL(media.url, window.location.href), base = new URL(config.messages_base_url, window.location.href);
                var expected = base.pathname.replace(/\/+$/, '') + '/' + chosen + (api ? '/replies/' : '/messages/') + message.id + '/media';
                if (source.origin !== new URL(window.location.href).origin || base.origin !== source.origin
                    || source.pathname !== expected || source.search || source.hash || source.username || source.password) return null;
                var wrapper = node('div', 'wa-media'), image = node('img', 'wa-media-image');
                image.alt = message.text || typeText('image'); image.loading = 'lazy'; image.decoding = 'async'; image.referrerPolicy = 'same-origin';
                var current = generation, selection = chosen;
                image.addEventListener('load', function () {
                    if (closed || current !== generation || selection !== chosen) return;
                    restoreAnchor(); rememberAnchor(); acknowledgeVisible();
                });
                image.addEventListener('error', function () { if (current === generation && !closed) { image.hidden = true; wrapper.appendChild(node('span', 'wa-media-unavailable', labels.message_unavailable)); } });
                image.src = source.href; wrapper.appendChild(image); return wrapper;
            } catch (error) { return null; }
        }
        function documentNode(message, api) {
            var media = message.media, replyId = api ? message.id : message.api_reply_id;
            if (!media || media.type !== 'document' || message.deleted || message.unavailable
                || !Number.isSafeInteger(replyId) || replyId < 1
                || typeof media.url !== 'string' || media.url.length > 2048 || ['application/pdf', 'text/plain'].indexOf(media.mime_type) === -1
                || typeof media.filename !== 'string' || !media.filename || Array.from(media.filename).length > 120
                || /[\u0000-\u001f\u007f/\\]/.test(media.filename)
                || !Number.isSafeInteger(media.size) || media.size < 1 || media.size > 8388608) return null;
            try {
                var source = new URL(media.url, window.location.href), base = new URL(config.messages_base_url, window.location.href);
                var expected = base.pathname.replace(/\/+$/, '') + '/' + chosen + '/replies/' + replyId + '/attachment';
                if (source.origin !== new URL(window.location.href).origin || base.origin !== source.origin
                    || source.pathname !== expected || source.search || source.hash || source.username || source.password) return null;
                var wrapper = node('div', 'wa-media'), link = node('a', 'wa-document-link');
                link.href = source.href; link.setAttribute('download', media.filename); link.referrerPolicy = 'same-origin';
                link.appendChild(node('span', '', '📎'));
                link.appendChild(node('span', '', media.filename + ' · ' + (media.size < 1048576 ? Math.ceil(media.size / 1024) + ' KB' : (media.size / 1048576).toFixed(1) + ' MB')));
                wrapper.appendChild(link); return wrapper;
            } catch (error) { return null; }
        }
        function clientRequestId() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
            if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') return null;
            var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); bytes[6] = bytes[6] & 15 | 64; bytes[8] = bytes[8] & 63 | 128;
            return Array.from(bytes, function (byte) { return ('0' + byte.toString(16)).slice(-2); }).join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
        }
        function remoteAction(context, action, body, target) {
            if (actionBusy || !currentAction(context) || !config.actions_base_url) return;
            var id = clientRequestId(); if (!id) { notice(labels.unavailable, true); return; }
            actionBusy = true; closeActions();
            var attempted = false;
            var base = config.actions_base_url.replace(/\/+$/, '');
            var ready = action === 'delete' ? context.api ? request(base + '/' + context.conversation + '/messages/selection', 'action-state') : Promise.resolve(null)
                : request(config.messages_base_url + '/' + (target || context.conversation) + '/reply-state', 'action-state');
            ready.then(function (state) {
                if (!currentAction(context)) return null;
                if (action !== 'delete') {
                    if (!state || state.can_reply !== true || !Number.isSafeInteger(state.latest_inbound_id) || state.latest_inbound_id < 1) throw new Error('Unavailable');
                    body.client_request_id = id; body.expected_inbound_id = state.latest_inbound_id;
                }
                if (action === 'delete' && context.api) {
                    if (!state || typeof state.snapshot_token !== 'string' || !Number.isSafeInteger(state.max_reply_id) || state.max_reply_id < context.message.id) throw new Error('Unavailable');
                    return request(base + '/' + context.conversation + '/messages/delete', 'message-action', {snapshot_token: state.snapshot_token, select_all: false, message_ids: [], reply_ids: [context.message.id]});
                }
                attempted = action !== 'delete';
                return request(base + '/' + context.conversation + (context.api ? '/replies/' : '/messages/') + context.message.id + '/' + action, 'message-action', body);
            }).then(function (data) {
                if (!data || !currentAction(context)) return;
                if (action === 'delete' && data.dashboard_only === true && (context.api ? Number.isSafeInteger(data.hidden_count) && data.hidden_count >= 0
                    : data.deleted === true && data.message_id === context.message.id)) {
                    if (context.api) removeReply(context.message.id);
                    else { hiddenMessages.add(context.message.id); messageMap.set(context.message.id, tombstone(context.message)); }
                    renderMessages(false, false); notice(label('deleted_dashboard', 'Hidden from your dashboard only.', 'تم الإخفاء من داشبورد حسابك فقط.'), false);
                    emit('whatsapp:conversation-updated', {conversation: context.conversation});
                } else if (data.state === 'ACCEPTED') {
                    if (action === 'reaction' && Object.prototype.hasOwnProperty.call(data, 'reaction')) {
                        var value = reaction(data.reaction);
                        if (context.api) { var reply = apiReplyMap.get('api-reply:' + context.message.id); if (reply) reply.reaction = value; }
                        else { var existing = messageMap.get(context.message.id); if (existing && !existing.deleted) messageMap.set(existing.id, Object.assign({}, existing, {reaction: value})); }
                        renderMessages(false, nearBottom());
                    }
                    actionResult(context, label('action_accepted', 'WhatsApp accepted the action.', 'قبل واتساب الإجراء.'), false);
                    emit('whatsapp:conversation-updated', {conversation: target || context.conversation}); wake();
                } else {
                    actionResult(context, data.state === 'UNKNOWN' ? label('action_unknown', 'Result unknown. Check WhatsApp before trying again.', 'نتيجة الإرسال غير معروفة. راجع واتساب قبل المحاولة مرة أخرى.') : labels.unavailable, true);
                }
            }).catch(function (error) {
                if (!currentAction(context)) return;
                if (attempted && (!error.status || error.status >= 500)) actionResult(context,
                    label('action_unknown', 'Result unknown. Check WhatsApp before trying again.', 'نتيجة الإرسال غير معروفة. راجع واتساب قبل المحاولة مرة أخرى.'), true);
                else unavailable(error);
            }).finally(function () { actionBusy = false; });
        }
        function forward(context) {
            closeActions(); if (!currentAction(context)) return;
            forwardPicker = node('section', 'wa-forward-picker'); forwardPicker.setAttribute('role', 'dialog');
            forwardPicker.setAttribute('aria-label', label('forward_choose', 'Choose a customer to forward to', 'اختر العميل لإعادة التوجيه'));
            forwardPicker.appendChild(node('strong', '', label('forward_choose', 'Choose a customer to forward to', 'اختر العميل لإعادة التوجيه')));
            var selectedTarget = 0, targets = node('div', 'wa-forward-targets');
            Array.from(threadMap.values()).filter(function (thread) {
                return Number(thread.id) !== context.conversation && (!thread.channel || thread.channel === 'whatsapp');
            }).forEach(function (thread) {
                var button = node('button', 'wa-forward-target', displayName(thread)); button.type = 'button';
                button.dataset.waForwardTarget = String(thread.id); button.setAttribute('aria-pressed', 'false');
                button.addEventListener('click', function () {
                    if (!currentAction(context)) return; selectedTarget = Number(thread.id); confirm.disabled = false;
                    Array.from(targets.children).forEach(function (item) { item.setAttribute('aria-pressed', item === button ? 'true' : 'false'); });
                }); targets.appendChild(button);
            });
            forwardPicker.appendChild(targets);
            if (nextCursor) {
                var load = node('button', 'wa-bubble-action', label('more_conversations', 'More customers', 'عملاء آخرون')); load.type = 'button';
                load.addEventListener('click', function () { load.disabled = true; loadThreads(true).then(function () { if (currentAction(context)) forward(context); }).catch(unavailable); });
                forwardPicker.appendChild(load);
            }
            var confirm = node('button', 'wa-bubble-action', label('forward_confirm', 'Confirm forwarding', 'تأكيد إعادة التوجيه')); confirm.type = 'button'; confirm.disabled = true;
            confirm.addEventListener('click', function () {
                if (selectedTarget && currentAction(context)) { confirm.disabled = true; remoteAction(context, 'forward', {target_conversation_id: selectedTarget}, selectedTarget); }
            });
            var cancel = node('button', 'wa-bubble-action', label('cancel', 'Cancel', 'إلغاء')); cancel.type = 'button'; cancel.addEventListener('click', closeActions);
            forwardPicker.append(confirm, cancel); panel.appendChild(forwardPicker);
        }
        function notice(text, isError) {
            alert.textContent = text || ''; alert.hidden = !text;
            alert.classList.toggle('is-error', Boolean(isError));
        }
        function unavailable(error) {
            if (closed || error && error.name === 'AbortError') return;
            if (error && [401, 403, 419].indexOf(error.status) !== -1) {
                denied = true; generation++; chosen = 0; selectedChannel = '';
                closeActions(); resetSelection(); clearHidden(); renderedMap.clear(); scrollAnchor = null; newMessagesCount = 0; newMessagesIndicator(); clearTimeout(edgeTimer);
                clearTimeout(readTimer);
                emit('whatsapp:private-reset', {reason: 'denied'});
                threadMap.clear(); messageMap.clear(); apiReplyMap.clear(); threads.textContent = ''; messages.textContent = '';
                unreadMap.clear(); readSeen.clear(); readPending.clear();
                if (unreadTotal) { unreadTotal.textContent = ''; unreadTotal.hidden = true; }
                title.textContent = labels.choose_conversation; customer.textContent = ''; count.textContent = '';
                older.hidden = true; more.hidden = true;
                controllers.forEach(function (controller) { controller.abort(); }); controllers.clear();
                listStatus.textContent = labels.denied; chatStatus.textContent = labels.denied;
                notice(labels.denied, true); connection.textContent = labels.denied;
            } else {
                var text = navigator.onLine === false ? labels.offline : labels.unavailable;
                notice(text, true); connection.textContent = text;
            }
            retryDelay = Math.min(60000, retryDelay * 2);
        }
        function request(url, key, body) {
            if (closed || denied || !config.available || navigator.onLine === false) {
                return Promise.reject(new Error('Unavailable'));
            }
            if (controllers.has(key)) controllers.get(key).abort();
            var controller = new AbortController(); controllers.set(key, controller);
            var timedOut = false, timeout = setTimeout(function () { timedOut = true; controller.abort(); }, 20000);
            var options = {credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
            if (body !== undefined) {
                options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-TOKEN'] = config.csrf; options.body = JSON.stringify(body);
            }
            return fetch(url, options)
                .then(function (response) {
                    if (response.redirected) {
                        var expired = new Error('Access expired'); expired.status = 401; throw expired;
                    }
                    if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/json')) {
                        var error = new Error('Unavailable'); error.status = response.status; throw error;
                    }
                    return response.json();
                }).then(function (data) {
                    if (!data.success) throw new Error('Unavailable');
                    return data;
                }).catch(function (error) { if (timedOut) throw new Error('Unavailable'); throw error; })
                .finally(function () { clearTimeout(timeout); if (controllers.get(key) === controller) controllers.delete(key); });
        }
        function renderThreads() {
            var oldTop = threadScroll.scrollTop;
            var fragment = document.createDocumentFragment();
            Array.from(threadMap.values()).sort(function (a, b) {
                return String(b.last_message_at || '').localeCompare(String(a.last_message_at || '')) || b.id - a.id;
            }).forEach(function (thread) {
                var item = node('li');
                var button = node('button', 'wa-inbox-thread'); button.type = 'button';
                button.dataset.waThread = String(thread.id);
                button.setAttribute('aria-current', Number(thread.id) === chosen ? 'true' : 'false');
                var avatar = node('span', 'wa-inbox-avatar', (displayName(thread).trim().charAt(0) || '?'));
                avatar.setAttribute('aria-hidden', 'true');
                var body = node('span', 'wa-inbox-thread-body');
                var heading = node('span', 'wa-inbox-thread-heading');
                heading.appendChild(node('strong', '', displayName(thread)));
                if (thread.channel && thread.channel !== 'whatsapp') body.appendChild(node('span', 'wa-inbox-channel-label', (thread.channel === 'instagram' ? 'Instagram' : 'Messenger') + ' · ' + (thread.account_label || '')));
                var unread = unreadMap.get(Number(thread.id)) || 0;
                if (unread > 0) {
                    var badge = node('span', 'wa-inbox-unread', '1');
                    badge.setAttribute('aria-label', label('unread_conversation', 'Unread conversation', 'محادثة غير مقروءة'));
                    heading.appendChild(badge);
                }
                var time = node('time', '', dateText(thread.last_message_at, false));
                if (thread.last_message_at) time.dateTime = thread.last_message_at;
                heading.appendChild(time); body.appendChild(heading);
                var preview = thread.preview;
                body.appendChild(node('span', 'wa-inbox-preview', preview ? (preview.unavailable ? labels.message_unavailable
                    : ((preview.direction === 'outbound' ? labels.business + ': ' : '') + (preview.text || typeText(preview.type)))) : ''));
                if (thread.name && thread.phone) body.appendChild(node('bdi', 'wa-inbox-phone', thread.phone));
                button.append(avatar, body); item.appendChild(button); fragment.appendChild(item);
            });
            threads.textContent = ''; threads.appendChild(fragment);
            threadScroll.scrollTop = oldTop;
            listStatus.textContent = threadMap.size ? '' : labels.empty;
        }
        function loadUnread() {
            if (!config.unread_url || !unreadTotal || closed || denied) return Promise.resolve();
            if (unreadJob) return unreadJob;
            var epoch = threadEpoch;
            var ids = Array.from(threadMap.keys()).slice(0, 99);
            if (chosen && ids.indexOf(chosen) === -1) ids.push(chosen);
            var url = new URL(config.unread_url, window.location.href);
            if (channel) url.searchParams.set('channel', channel);
            ids.forEach(function (id) { url.searchParams.append('ids[]', String(id)); });
            var job = request(url.href, 'unread').then(function (data) {
                if (closed || denied || epoch !== threadEpoch) return;
                (data.conversations || []).forEach(function (row) { unreadMap.set(Number(row.id), Number(row.unread_count) || 0); });
                var total = Number.isSafeInteger(data.total_unread_conversations) && data.total_unread_conversations >= 0
                    ? data.total_unread_conversations : (data.conversations || []).filter(function (row) { return Number(row.unread_count) > 0; }).length;
                unreadTotal.textContent = (config.unread_label || '') + ': ' + total; unreadTotal.hidden = !total;
                renderThreads();
            }).finally(function () { if (unreadJob === job) unreadJob = null; });
            unreadJob = job; return job;
        }
        function acknowledgeVisible() {
            if (!config.read_base_url || closed || denied || document.hidden || navigator.onLine === false || !chosen
                || typeof scroll.getBoundingClientRect !== 'function') return;
            var bounds = scroll.getBoundingClientRect(), seen = 0, selection = chosen, current = generation;
            var height = Number(window.innerHeight), width = Number(window.innerWidth);
            if (!Number.isFinite(height) || !Number.isFinite(width) || height <= 0 || width <= 0) return;
            var visible = {top: Math.max(0, bounds.top), bottom: Math.min(height, bounds.bottom),
                left: Math.max(0, bounds.left), right: Math.min(width, bounds.right)};
            if (visible.bottom <= visible.top || visible.right <= visible.left) return;
            Array.from(messages.children).forEach(function (item) {
                if (item.dataset.waDirection !== 'inbound' || item.dataset.waHidden === 'true' || typeof item.getBoundingClientRect !== 'function') return;
                var rectangle = item.getBoundingClientRect();
                if (rectangle.bottom > visible.top && rectangle.top < visible.bottom && rectangle.right > visible.left && rectangle.left < visible.right) {
                    seen = Math.max(seen, Number(item.dataset.waMessageId) || 0);
                }
            });
            if (!seen || seen <= (readSeen.get(selection) || 0) || seen <= (readPending.get(selection) || 0)) return;
            readPending.set(selection, seen);
            request(config.read_base_url + '/' + selection + '/read', 'read-' + selection, {seen_message_id: seen}).then(function () {
                if (closed || denied) return;
                readSeen.set(selection, Math.max(seen, readSeen.get(selection) || 0));
                if (current === generation && chosen === selection) return loadUnread();
            }).catch(unavailable).finally(function () { if (readPending.get(selection) === seen) readPending.delete(selection); });
        }
        function readScroll() {
            rememberAnchor();
            if (nearBottom()) { newMessagesCount = 0; newMessagesIndicator(); }
            if (config.read_base_url || config.message_updates_base_url) { clearTimeout(readTimer); readTimer = setTimeout(function () { acknowledgeVisible(); loadVisibleUpdates().catch(unavailable); }, 200); }
            if (scroll.scrollTop <= 80) requestOlder();
        }
        function requestOlder() {
            if (!chosen || closed || denied || document.hidden || navigator.onLine === false) return;
            if (beforeId) {
                if (messageLoading) pendingOlder = true;
                else { pendingOlder = false; loadMessages('older').catch(unavailable); }
            }
            if (!apiHistoryStarted) loadReplyHistory('initial').catch(unavailable);
            else if (apiHistoryBefore) loadReplyHistory('older').catch(unavailable);
        }
        function listScroll() {
            if (closed || denied || document.hidden || navigator.onLine === false) return;
            if (threadScroll.scrollHeight - threadScroll.clientHeight - threadScroll.scrollTop <= 180
                && nextCursor && !threadLoading) loadThreads(true).then(loadUnread).catch(unavailable);
        }
        function fillList() {
            clearTimeout(edgeTimer);
            if (closed || denied || !nextCursor || document.hidden || navigator.onLine === false) return;
            edgeTimer = setTimeout(function () {
                edgeTimer = null;
                if (threadScroll.clientHeight > 0 && threadScroll.scrollHeight <= threadScroll.clientHeight + 1) listScroll();
            }, 0);
        }
        function loadThreads(previous) {
            if (threadLoading) return threadJob || Promise.resolve();
            var page = previous ? nextCursor : null, epoch = threadEpoch;
            if (previous && !page) return Promise.resolve();
            threadLoading = true; more.disabled = true;
            var url = new URL(config.conversations_url, window.location.href);
            if (page) url.searchParams.set('cursor', page);
            if (channel) url.searchParams.set('channel', channel);
            var job = request(url.href, 'threads').then(function (data) {
                if (closed || denied || epoch !== threadEpoch) return;
                projectionWarning = Boolean(data.projection_warning);
                // Refresh keeps older explicitly loaded pages, updating only current rows.
                (data.conversations || []).forEach(function (thread) { threadMap.set(Number(thread.id), thread); });
                renderThreads(); count.textContent = String(data.total || 0);
                if (previous || !hasPagedThreads) nextCursor = data.next_cursor && data.next_cursor !== page ? data.next_cursor : null;
                if (previous) hasPagedThreads = true;
                more.hidden = true;
            }).finally(function () {
                if (threadJob === job) threadJob = null;
                if (epoch === threadEpoch) { threadLoading = false; if (!closed) { more.disabled = false; fillList(); } }
            });
            threadJob = job; return job;
        }
        function renderMessages(prepend, toBottom) {
            var oldHeight = scroll.scrollHeight, oldTop = scroll.scrollTop;
            rememberAnchor();
            var rendered = [], nextRendered = new Map();
            var rows = Array.from(messageMap.values()).map(function (message) {
                return {message: message, stamp: messageStamp(message.sent_at || message.received_at), order: Number(message.id), api: false};
            });
            apiReplyMap.forEach(function (reply) {
                var projected = Number.isSafeInteger(reply.projected_message_id) ? messageMap.get(reply.projected_message_id) : null;
                if (!projected) messageMap.forEach(function (message) {
                    if (message.api_reply_id === reply.id && message.direction === 'outbound' && message.type === (reply.kind === 'voice' ? 'audio' : reply.kind)) projected = message;
                });
                // Only a projected copy already loaded in this conversation replaces the API audit bubble.
                if (projected && projected.direction === 'outbound') return;
                rows.push({message: reply, stamp: reply.timestamp, order: reply.id, api: true});
            });
            rows.sort(function (a, b) {
                var left = new Date(a.stamp).getTime(), right = new Date(b.stamp).getTime();
                var byTime = Number.isFinite(left) && Number.isFinite(right) ? left - right
                    : String(a.stamp || '').localeCompare(String(b.stamp || ''));
                return byTime || Number(a.api) - Number(b.api) || a.order - b.order;
            }).forEach(function (row) {
                var message = row.message;
                var key = row.api ? 'api-reply:' + message.id : String(message.id);
                var selectKey = selectionKey(message, row.api);
                var signature = JSON.stringify(message) + '|' + selecting + '|' + selectKey + '|' + isSelected(selectKey) + '|' + selectionBusy + '|' + snapshotIncludes(selectKey);
                var cached = renderedMap.get(key);
                if (cached && cached.signature === signature) { rendered.push(cached.element); nextRendered.set(key, cached); return; }
                function remember(item) { rendered.push(item); nextRendered.set(key, {element: item, signature: signature}); }
                if (row.api) {
                    var apiItem = node('li', 'wa-inbox-message is-outbound is-api-reply');
                    apiItem.dataset.waApiReplyId = 'api-reply:' + message.id; apiItem.dataset.waDirection = 'outbound';
                    var apiBubble = node('article', 'wa-inbox-bubble');
                    apiBubble.appendChild(node('span', 'wa-inbox-author', labels.business));
                    apiBubble.appendChild(node('span', 'wa-inbox-message-type', message.source_label));
                    var apiQuote = quoted({quote: message.reply_to}); if (apiQuote) apiBubble.appendChild(apiQuote);
                    var apiMedia = imageNode(message, true); if (apiMedia) apiBubble.appendChild(apiMedia);
                    var apiDocument = documentNode(message, true); if (apiDocument) apiBubble.appendChild(apiDocument);
                    apiBubble.appendChild(node('p', 'wa-inbox-message-text', message.text || (['audio', 'voice'].indexOf(message.kind) !== -1 ? message.voice_label : typeText(message.kind))));
                    if (message.accepted_label) apiBubble.appendChild(node('span', 'wa-inbox-message-type', message.accepted_label));
                    var apiTime = node('time', '', dateText(row.stamp, true)); if (row.stamp) apiTime.dateTime = row.stamp;
                    apiBubble.appendChild(apiTime); var apiReaction = reactionPill(message); if (apiReaction) apiBubble.appendChild(apiReaction);
                    addActionTrigger(apiBubble, message, true); selectionControl(apiItem, message, true); apiItem.appendChild(apiBubble); remember(apiItem); return;
                }
                var item = node('li', 'wa-inbox-message ' + (message.direction === 'outbound' ? 'is-outbound' : 'is-inbound'));
                item.dataset.waMessageId = String(message.id); item.dataset.waDirection = message.direction;
                if (message.deleted) item.dataset.waHidden = 'true';
                var bubble = node('article', 'wa-inbox-bubble');
                bubble.appendChild(node('span', 'wa-inbox-author', message.direction === 'outbound' ? labels.business : labels.customer));
                if (message.type !== 'text') bubble.appendChild(node('span', 'wa-inbox-message-type', typeText(message.type)));
                var quote = !message.deleted && quoted(message); if (quote) bubble.appendChild(quote);
                var media = imageNode(message); if (media) bubble.appendChild(media);
                var documentMedia = documentNode(message); if (documentMedia) bubble.appendChild(documentMedia);
                var cart = !message.unavailable && message.type === 'order' ? cartNode(message.cart) : null;
                if (cart) bubble.appendChild(cart);
                else bubble.appendChild(node('p', 'wa-inbox-message-text', message.deleted
                    ? label('deleted_dashboard', 'Hidden from your dashboard only.', 'تم الإخفاء من داشبورد حسابك فقط.') : message.unavailable ? labels.message_unavailable
                    : (message.text || typeText(message.type))));
                var timestamp = message.sent_at || message.received_at;
                var time = node('time', '', dateText(timestamp, true)); if (timestamp) time.dateTime = timestamp;
                bubble.appendChild(time); var nativeReaction = reactionPill(message); if (nativeReaction) bubble.appendChild(nativeReaction);
                addActionTrigger(bubble, message, false); selectionControl(item, message, false); item.appendChild(bubble); remember(item);
            });
            if (typeof messages.insertBefore === 'function') {
                var currentNode = messages.firstChild;
                rendered.forEach(function (item) {
                    if (currentNode === item) currentNode = currentNode.nextSibling;
                    else messages.insertBefore(item, currentNode);
                });
                Array.from(messages.children).forEach(function (item) { if (rendered.indexOf(item) === -1) messages.removeChild(item); });
            } else {
                var fragment = document.createDocumentFragment(); rendered.forEach(function (item) { fragment.appendChild(item); });
                messages.textContent = ''; messages.appendChild(fragment);
            }
            renderedMap = nextRendered;
            if (actionMenu && actionMenu.parentNode && !messages.contains(actionMenu.parentNode)) closeActions();
            chatStatus.textContent = rows.length ? '' : labels.no_messages;
            if (prepend) scroll.scrollTop = oldTop + scroll.scrollHeight - oldHeight;
            else if (toBottom) scroll.scrollTop = scroll.scrollHeight;
            else scroll.scrollTop = oldTop;
            if (!toBottom && scrollAnchor && scrollAnchor.key) restoreAnchor();
            rememberAnchor();
            updateSelection();
            acknowledgeVisible();
        }
        function normalizedReply(reply, replyLabels) {
            var id = reply && (Number.isSafeInteger(reply.id) ? reply.id : reply.reply_id);
            if (!reply || !Number.isSafeInteger(id) || id < 1 || reply.state !== 'ACCEPTED'
                || reply.source !== 'dashboard_api' || ['text', 'audio', 'voice', 'image', 'video', 'document'].indexOf(reply.kind) === -1
                || reply.kind === 'text' && (typeof reply.text !== 'string' || !reply.text)) return null;
            var stamp = messageStamp(reply.accepted_at || reply.created_at);
            if (!stamp || !Number.isFinite(new Date(stamp).getTime())) return null;
            var language = document.documentElement && document.documentElement.lang || 'ar';
            var arabic = /^ar(?:-|$)/i.test(language); replyLabels = replyLabels || {};
            var previous = apiReplyMap.get('api-reply:' + id);
            var stored = {id: id, kind: reply.kind, text: typeof reply.text === 'string' ? Array.from(reply.text).slice(0, 4096).join('') : '',
                    timestamp: new Date(stamp).toISOString(), projected_message_id: Number.isSafeInteger(reply.projected_message_id)
                        && reply.projected_message_id > 0 ? reply.projected_message_id : null,
                    source_label: typeof replyLabels.api_source === 'string' && replyLabels.api_source ? replyLabels.api_source
                        : previous ? previous.source_label : labels.api_source || (arabic ? 'رد من الداشبورد · قبله واتساب' : 'Dashboard reply · accepted by WhatsApp'),
                    accepted_label: replyLabels.api_source ? '' : typeof replyLabels.accepted === 'string' ? replyLabels.accepted : previous ? previous.accepted_label : '',
                    voice_label: typeof replyLabels.voice_message === 'string' ? replyLabels.voice_message : previous ? previous.voice_label : typeText('audio')};
            stored.actions = {delete: reply.actions && reply.actions.delete === true};
            ['reply', 'forward', 'react'].forEach(function (key) { stored.actions[key] = reply.actions && reply.actions[key] === true; });
            stored.reply_to = reply.reply_to || null;
            stored.projection_id = Number.isSafeInteger(reply.projection_id) && reply.projection_id > 0 ? reply.projection_id : null;
            stored.media = reply.media && reply.media.type === 'image' && typeof reply.media.url === 'string'
                ? {type: 'image', url: reply.media.url, mime_type: reply.media.mime_type} : null;
            if (reply.media && reply.media.type === 'document') stored.media = {type: 'document', url: reply.media.url,
                mime_type: reply.media.mime_type, filename: reply.media.filename, size: reply.media.size};
            stored.reaction = newestReaction(previous && previous.reaction, reply.reaction);
            return replyHidden(stored) ? null : stored;
        }
        function acceptedReplies(event) {
            var detail = event && event.detail;
            if (closed || denied || document.hidden || navigator.onLine === false || !chosen || !detail
                || selectedChannel !== 'whatsapp' || detail.channel !== selectedChannel || detail.namespace !== namespace
                || detail.conversation !== chosen || detail.generation !== generation || !Array.isArray(detail.replies)) return;
            var bottom = nearBottom(), next = new Set();
            detail.replies.slice(0, 20).forEach(function (reply) {
                var stored = normalizedReply(reply, detail.labels); if (!stored) return;
                var key = 'api-reply:' + stored.id; next.add(key); apiReplyMap.set(key, stored);
            });
            apiRecentKeys.forEach(function (key) { if (!next.has(key) && !apiHistoryKeys.has(key)) apiReplyMap.delete(key); });
            apiRecentKeys = next;
            renderMessages(false, bottom);
        }
        function visibleIds() {
            var result = {messages: [], replies: []}, bounds = typeof scroll.getBoundingClientRect === 'function' ? scroll.getBoundingClientRect() : null;
            if (bounds) { bounds = {top: Math.max(0, bounds.top), bottom: Math.min(Number(window.innerHeight) || bounds.bottom, bounds.bottom)}; if (bounds.bottom <= bounds.top) return result; }
            Array.from(messages.children).forEach(function (item) {
                if (result.messages.length + result.replies.length >= 100 || item.dataset.waHidden === 'true') return;
                if (bounds && typeof item.getBoundingClientRect === 'function') { var rectangle = item.getBoundingClientRect(); if (rectangle.bottom <= bounds.top || rectangle.top >= bounds.bottom) return; }
                var native = Number(item.dataset.waMessageId), api = item.dataset.waApiReplyId;
                if (Number.isSafeInteger(native) && native > 0) result.messages.push(native);
                else if (api && /^api-reply:[1-9][0-9]*$/.test(api)) result.replies.push(Number(api.slice(10)));
            }); return result;
        }
        function removeReply(id) {
            if (!Number.isSafeInteger(id) || id < 1) return;
            hiddenReplies.add(id); var key = 'api-reply:' + id;
            apiReplyMap.delete(key); apiHistoryKeys.delete(key); apiRecentKeys.delete(key); selectedKeys.delete(key);
            messageMap.forEach(function (message) { if (message.api_reply_id === id) { hiddenMessages.add(message.id); messageMap.set(message.id, tombstone(message)); } });
        }
        function loadReplyHistory(mode) {
            if (!config.reply_history_base_url || !chosen || selectedChannel !== 'whatsapp' || closed || denied || document.hidden || navigator.onLine === false) return Promise.resolve();
            if (historyJob) return historyJob;
            if (mode === 'recent' && !apiHistoryStarted) mode = 'initial';
            if (mode === 'older' && (!apiHistoryStarted || !apiHistoryBefore)) return Promise.resolve();
            if (mode === 'initial' && apiHistoryStarted) return Promise.resolve();
            var selection = chosen, current = generation, epoch = historyEpoch, before = mode === 'older' ? apiHistoryBefore : null;
            var url = new URL(config.reply_history_base_url.replace(/\/+$/, '') + '/' + selection + '/replies/history', window.location.href);
            if (before) url.searchParams.set('before_reply_id', String(before));
            var known = Array.from(apiReplyMap.values()).slice(0, 100).map(function (reply) { return reply.id; });
            known.forEach(function (id) { url.searchParams.append('known_reply_ids[]', String(id)); });
            var job = request(url.href, 'reply-history').then(function (data) {
                if (closed || denied || document.hidden || navigator.onLine === false || current !== generation || selection !== chosen || epoch !== historyEpoch) return;
                if (!Array.isArray(data.replies) || data.replies.length > 20) throw new Error('Unavailable');
                var bottom = nearBottom();
                (data.hidden_reply_ids || []).forEach(function (id) { if (known.indexOf(id) !== -1) removeReply(id); });
                data.replies.forEach(function (reply) { var stored = normalizedReply(reply); if (stored) { var key = 'api-reply:' + stored.id; apiHistoryKeys.add(key); apiReplyMap.set(key, stored); } });
                apiHistoryStarted = true;
                // A fresh receipt can arrive from another operator without a native echo.
                // Poll its newest page while retaining the separately paged history cursor.
                if (mode !== 'recent') apiHistoryBefore = data.has_more === true && Number.isSafeInteger(data.next_before_reply_id) && data.next_before_reply_id > 0
                    && (!before || data.next_before_reply_id < before) ? data.next_before_reply_id : null;
                renderMessages(mode === 'older', mode !== 'older' && bottom);
            }).finally(function () {
                if (historyJob !== job) return; historyJob = null;
                if (current === generation && epoch === historyEpoch && !closed && !denied && !document.hidden && navigator.onLine !== false
                    && apiHistoryBefore && scroll.clientHeight > 0 && scroll.scrollHeight <= scroll.clientHeight + 1) {
                    clearTimeout(historyFillTimer); historyFillTimer = setTimeout(function () { historyFillTimer = null; loadReplyHistory('older').catch(unavailable); }, 0);
                }
            });
            historyJob = job; return job;
        }
        function loadVisibleUpdates() {
            if (!config.message_updates_base_url || !chosen || selectedChannel !== 'whatsapp' || closed || denied || document.hidden || navigator.onLine === false) return Promise.resolve();
            if (updatesJob) return updatesJob;
            var ids = visibleIds(); if (!ids.messages.length && !ids.replies.length) return Promise.resolve();
            var selection = chosen, current = generation, epoch = historyEpoch;
            var url = new URL(config.message_updates_base_url.replace(/\/+$/, '') + '/' + selection + '/messages/updates', window.location.href);
            ids.messages.forEach(function (id) { url.searchParams.append('message_ids[]', String(id)); });
            ids.replies.forEach(function (id) { url.searchParams.append('reply_ids[]', String(id)); });
            var job = request(url.href, 'visible-updates').then(function (data) {
                if (closed || denied || document.hidden || navigator.onLine === false || current !== generation || selection !== chosen || epoch !== historyEpoch) return;
                var changed = false;
                (data.messages || []).forEach(function (row) {
                    if (ids.messages.indexOf(row.id) === -1 || !messageMap.has(row.id)) return;
                    var message = messageMap.get(row.id), next;
                    if (row.deleted === true) { hiddenMessages.add(row.id); next = tombstone(message); }
                    else { next = Object.assign({}, message, {reaction: newestReaction(message.reaction, row.reaction)}); }
                    if (JSON.stringify(next) !== JSON.stringify(message)) { messageMap.set(row.id, next); changed = true; }
                });
                (data.replies || []).forEach(function (row) {
                    if (ids.replies.indexOf(row.reply_id) === -1) return;
                    var key = 'api-reply:' + row.reply_id, reply = apiReplyMap.get(key); if (!reply) return;
                    if (row.deleted === true) { removeReply(row.reply_id); changed = true; }
                    else { var value = newestReaction(reply.reaction, row.reaction); if (JSON.stringify(value) !== JSON.stringify(reply.reaction)) { reply.reaction = value; changed = true; } }
                });
                if (changed) renderMessages(false, nearBottom());
            }).finally(function () { if (updatesJob === job) updatesJob = null; });
            updatesJob = job; return job;
        }
        function resetAcceptedReplies(event) {
            closeActions(); resetSelection();
            actionNotice = null;
            apiReplyMap.clear(); clearHistory();
            ['reply-history', 'visible-updates'].forEach(function (key) { if (controllers.has(key)) controllers.get(key).abort(); });
            var reason = event && event.detail && event.detail.reason;
            if (!closed && reason !== 'denied' && reason !== 'closed') renderMessages(false, false);
        }
        function loadMessages(mode) {
            if (!chosen || closed) return Promise.resolve();
            if (messageLoading) return messageJob || Promise.resolve();
            var selection = chosen, current = generation;
            var url = new URL(config.messages_base_url + '/' + selection + '/messages', window.location.href);
            if (mode === 'older') { if (!beforeId) return Promise.resolve(); url.searchParams.set('before_id', beforeId); }
            else if (mode === 'newer') url.searchParams.set('after_id', lastId);
            messageLoading = true; older.disabled = true;
            var startLast = lastId;
            var job = request(url.href, 'messages').then(function (data) {
                if (closed || current !== generation || selection !== chosen) return;
                var followBottom = nearBottom();
                messageStarted = true;
                projectionWarning = projectionWarning || Boolean(data.projection_warning);
                title.textContent = displayName(data.conversation || {id: chosen});
                customer.textContent = data.conversation && data.conversation.name && data.conversation.phone ? data.conversation.phone : '';
                customer.dir = 'ltr';
                if (data.conversation && typeof data.conversation.channel === 'string' && data.conversation.channel
                    && data.conversation.channel !== selectedChannel) {
                    selectedChannel = data.conversation.channel; apiReplyMap.clear(); resetSelection(); closeActions();
                    emit('whatsapp:conversation-selected', {conversation: selection, generation: generation, channel: selectedChannel, namespace: namespace});
                }
                var rows = data.messages || [];
                rows.forEach(function (message) {
                    if (message && Number.isSafeInteger(message.id) && message.id > 0) {
                        if (message.deleted) hiddenMessages.add(message.id);
                        if (!messageHidden(message)) {
                            var existing = messageMap.get(message.id); message.reaction = newestReaction(existing && existing.reaction, message.reaction);
                        }
                        messageMap.set(message.id, messageHidden(message) ? tombstone(message) : message);
                    }
                });
                lastId = Math.max(lastId, Number(data.last_id) || 0);
                if (mode !== 'newer') beforeId = data.next_before_id || null;
                older.hidden = true;
                if (mode === 'newer' && rows.length && !followBottom) {
                    newMessagesCount += rows.filter(function (row) { return row.direction === 'inbound' && Number(row.id) > startLast; }).length;
                    newMessagesIndicator();
                }
                if (mode !== 'newer' || rows.length) renderMessages(mode === 'older', mode === 'initial' || mode === 'newer' && followBottom);
                if (mode === 'newer' && rows.length) emit('whatsapp:conversation-updated', {conversation: selection});
                if (mode === 'newer' && data.has_more && lastId > startLast) {
                    messageLoading = false;
                    return loadMessages('newer');
                }
            }).finally(function () {
                if (messageJob !== job) return;
                messageJob = null;
                if (!closed && current === generation) {
                    messageLoading = false; older.disabled = false;
                    if (pendingOlder && scroll.scrollTop <= 80) requestOlder(); else pendingOlder = false;
                }
            });
            messageJob = job; return job;
        }
        function choose(id) {
            if (closed || denied || !id) return;
            if (chosen === id) { panel.classList.add('has-selection'); back.hidden = false; restoreAnchor(); rememberAnchor(); acknowledgeVisible(); return; }
            closeActions(); resetSelection();
            generation++; chosen = id; beforeId = null; lastId = 0; messageLoading = false; messageMap.clear(); apiReplyMap.clear();
            messageStarted = false;
            clearHistory(); clearHidden();
            renderedMap.clear(); scrollAnchor = null; newMessagesCount = 0; newMessagesIndicator();
            messageJob = null;
            pendingOlder = false;
            var selected = threadMap.get(id);
            selectedChannel = selected && typeof selected.channel === 'string' && selected.channel ? selected.channel
                : channel || (legacyWhatsApp ? 'whatsapp' : '');
            updateSelection();
            emit('whatsapp:conversation-selected', {conversation: id, generation: generation, channel: selectedChannel, namespace: namespace});
            if (controllers.has('messages')) controllers.get('messages').abort();
            if (controllers.has('reply-history')) controllers.get('reply-history').abort();
            if (controllers.has('visible-updates')) controllers.get('visible-updates').abort();
            messages.textContent = ''; title.textContent = labels.loading; customer.textContent = '';
            chatStatus.textContent = labels.loading; older.hidden = true;
            panel.classList.add('has-selection'); back.hidden = false; renderThreads();
            loadMessages('initial').catch(unavailable);
            loadReplyHistory('initial').catch(unavailable);
        }
        function success() {
            if (closed || denied) return;
            retryDelay = pollDelay;
            if (actionNotice && actionNotice.conversation === chosen && actionNotice.generation === generation) notice(actionNotice.text, actionNotice.error);
            else notice(projectionWarning ? labels.projection_warning : '', projectionWarning);
            connection.textContent = labels.connected + ' · ' + new Date().toLocaleTimeString(document.documentElement.lang || 'ar',
                {hour: '2-digit', minute: '2-digit'});
        }
        function schedule() {
            clearTimeout(timer);
            if (!closed && !denied && config.available && !document.hidden && navigator.onLine !== false) timer = setTimeout(poll, retryDelay);
        }
        function wake() { clearTimeout(timer); poll(); }
        function poll() {
            if (pollBusy) { pollAgain = true; return; }
            if (closed || denied || document.hidden || navigator.onLine === false || !config.available) { schedule(); return; }
            pollBusy = true; refresh.disabled = true;
            Promise.all([loadThreads(false), loadMessages(chosen && messageStarted ? 'newer' : 'initial'), loadReplyHistory('recent')])
                .then(loadVisibleUpdates).then(loadUnread).then(success).catch(unavailable)
                .finally(function () {
                    pollBusy = false; if (!closed) refresh.disabled = false;
                    if (pollAgain && !closed && !denied && !document.hidden && navigator.onLine !== false) { pollAgain = false; poll(); }
                    else { pollAgain = false; schedule(); }
                });
        }
        function click(event) {
            if (event.target.closest('[data-wa-new-messages]')) { scroll.scrollTop = scroll.scrollHeight; newMessagesCount = 0; newMessagesIndicator(); rememberAnchor(); acknowledgeVisible(); return; }
            if (event.target.closest('[data-wa-select-mode]')) { startSelection(false); return; }
            if (event.target.closest('[data-wa-select-all]')) { startSelection(true); return; }
            if (event.target.closest('[data-wa-selection-cancel]')) {
                if (selectionAll) { selectionAll = false; selectedKeys.clear(); startSelection(false); }
                else { resetSelection(); renderMessages(false, false); } return;
            }
            if (event.target.closest('[data-wa-delete-selected]')) { deleteSelection(false); return; }
            if (event.target.closest('[data-wa-bulk-confirm]')) { deleteSelection(true); return; }
            var checkbox = event.target.closest('[data-wa-message-select]');
            if (checkbox && !checkbox.disabled && selecting && !selectionBusy && !selectionAll && snapshotIncludes(String(checkbox.dataset.waMessageSelect))) {
                var selectedKey = String(checkbox.dataset.waMessageSelect);
                if (checkbox.checked && !selectedKeys.has(selectedKey) && selectedKeys.size >= 100) {
                    checkbox.checked = false; notice(label('selection_limit', 'Select up to 100 messages, or use Select all.', 'اختر حتى 100 رسالة أو استخدم اختيار الكل.'), true); updateSelection(); return;
                }
                if (checkbox.checked) selectedKeys.add(selectedKey); else selectedKeys.delete(selectedKey);
                renderMessages(false, false); return;
            }
            var trigger = event.target.closest('[data-wa-action-menu]');
            if (trigger && panel.contains(trigger)) { menu(String(trigger.dataset.waActionMenu), trigger); return; }
            var action = event.target.closest('[data-wa-action]');
            if (action && panel.contains(action)) {
                var key = String(action.dataset.waActionMessage || '');
                if (action.dataset.waAction === 'copy') { copyText(key); return; }
                var context = actionContext(key); if (!context || (context.message.actions || {})[action.dataset.waAction === 'react-send' ? 'react'
                    : action.dataset.waAction === 'delete-confirm' ? 'delete' : action.dataset.waAction] !== true) return;
                if (action.dataset.waAction === 'reply') {
                    var quoteContext = {conversation_id: context.conversation, generation: context.generation,
                        channel: selectedChannel, endpoint_namespace: namespace, text: context.message.text || typeText(context.message.type || context.message.kind), kind: context.message.type || context.message.kind};
                    quoteContext[context.api ? 'reply_id' : 'message_id'] = context.message.id; emit('whatsapp:reply-context', quoteContext); closeActions();
                } else if (action.dataset.waAction === 'forward') forward(context);
                else if (action.dataset.waAction === 'delete') {
                    var owner = actionMenu && actionMenu.parentNode;
                    closeActions(); actionMenu = node('div', 'wa-bubble-actions-menu');
                    actionMenu.appendChild(node('p', '', label('delete_confirm', 'Hide this message from your dashboard only?', 'إخفاء الرسالة من داشبورد حسابك فقط؟')));
                    actionMenu.appendChild(actionButton('delete-confirm', label('delete_dashboard', 'Hide from my dashboard', 'إخفاء من داشبورد حسابي'), key));
                    if (owner) { owner.appendChild(actionMenu); positionMenu(); }
                } else if (action.dataset.waAction === 'delete-confirm') remoteAction(context, 'delete', {});
                else if (action.dataset.waAction === 'react') {
                    var parent = actionMenu && actionMenu.parentNode; closeActions(); actionMenu = node('div', 'wa-bubble-actions-menu wa-reaction-options');
                    ['👍', '❤️', '😂', '😮', '😢', '🙏', '✅', ''].forEach(function (emoji) {
                        var button = actionButton('react-send', emoji || label('remove_reaction', 'Remove reaction', 'إزالة التفاعل'), key); button.dataset.waEmoji = emoji; actionMenu.appendChild(button);
                    }); if (parent) { parent.appendChild(actionMenu); positionMenu(); }
                } else if (action.dataset.waAction === 'react-send') remoteAction(context, 'reaction', {emoji: action.dataset.waEmoji});
                return;
            }
            var channelButton = event.target.closest('[data-meta-channel]');
            if (channelButton && panel.contains(channelButton)) {
                channel = channelButton.dataset.metaChannel || ''; threadEpoch++; generation++; chosen = 0; selectedChannel = '';
                closeActions(); resetSelection(); clearHidden(); renderedMap.clear(); scrollAnchor = null; newMessagesCount = 0; newMessagesIndicator(); threadJob = null; messageJob = null; unreadJob = null;
                controllers.forEach(function (controller) { controller.abort(); }); controllers.clear();
                clearTimeout(readTimer); readPending.clear(); emit('whatsapp:private-reset', {reason: 'channel'});
                threadMap.clear(); messageMap.clear(); apiReplyMap.clear(); threads.textContent = ''; messages.textContent = '';
                nextCursor = null; hasPagedThreads = false; beforeId = null; lastId = 0; threadLoading = false; messageLoading = false; messageStarted = false;
                title.textContent = labels.choose_conversation; customer.textContent = ''; older.hidden = true; more.hidden = true;
                panel.classList.remove('has-selection'); back.hidden = true;
                panel.querySelectorAll('[data-meta-channel]').forEach(function (item) { item.setAttribute('aria-pressed', item === channelButton ? 'true' : 'false'); });
                loadThreads(false).then(loadUnread).then(success).catch(unavailable); return;
            }
            var button = event.target.closest('[data-wa-thread]');
            if (button && panel.contains(button)) { choose(Number(button.dataset.waThread)); return; }
            if (event.target.closest('[data-wa-older]') && !older.disabled) loadMessages('older').catch(unavailable);
            if (event.target.closest('[data-wa-more-threads]') && !more.disabled) loadThreads(true).catch(unavailable);
            if (event.target.closest('[data-wa-refresh]')) wake();
            if (event.target.closest('[data-wa-back]')) { rememberAnchor(); closeActions(); panel.classList.remove('has-selection'); back.hidden = true; }
        }
        function visibility() {
            if (document.hidden) { clearTimeout(timer); clearTimeout(edgeTimer); closeActions(); emit('whatsapp:private-reset', {reason: 'hidden'}); }
            else { clearTimeout(timer); if (chosen && !denied) emit('whatsapp:conversation-selected', {conversation: chosen, generation: generation, channel: selectedChannel, namespace: namespace}); acknowledgeVisible(); poll(); }
        }
        function online() {
            clearTimeout(timer);
            if (navigator.onLine === false) { emit('whatsapp:private-reset', {reason: 'offline'}); unavailable(); }
            else { if (chosen && !denied) emit('whatsapp:conversation-selected', {conversation: chosen, generation: generation, channel: selectedChannel, namespace: namespace}); poll(); }
        }
        panel.addEventListener('click', click);
        scroll.addEventListener('scroll', readScroll);
        threadScroll.addEventListener('scroll', listScroll);
        if (typeof window.IntersectionObserver === 'function') {
            [[threadSentinel, threadScroll, listScroll], [messageSentinel, scroll, requestOlder]].forEach(function (entry) {
                if (!entry[0]) return;
                var observer = new window.IntersectionObserver(function (entries) {
                    if (!closed && !denied && !document.hidden && navigator.onLine !== false && entries.some(function (value) { return value.isIntersecting; })) entry[2]();
                }, {root: entry[1], rootMargin: '100px', threshold: 0}); observer.observe(entry[0]); observers.push(observer);
            });
        }
        document.addEventListener('visibilitychange', visibility);
        document.addEventListener('whatsapp:accepted-replies', acceptedReplies);
        document.addEventListener('whatsapp:private-reset', resetAcceptedReplies);
        window.addEventListener('offline', online); window.addEventListener('online', online);
        window.addEventListener('focus', wake);
        var session = {panel: panel, selection: function () { return chosen; },
            selectionState: function () { return {conversation: chosen, generation: generation, channel: selectedChannel, namespace: namespace}; }, destroy: function () {
            if (closed) return; closed = true; generation++; clearTimeout(timer);
            clearTimeout(readTimer); clearTimeout(edgeTimer); closeActions(); resetSelection(); clearHidden(); renderedMap.clear(); scrollAnchor = null; newMessagesCount = 0; newMessagesIndicator(); observers.forEach(function (observer) { observer.disconnect(); });
            emit('whatsapp:private-reset', {reason: 'closed'});
            controllers.forEach(function (controller) { controller.abort(); }); controllers.clear();
            panel.removeEventListener('click', click); document.removeEventListener('visibilitychange', visibility);
            document.removeEventListener('whatsapp:accepted-replies', acceptedReplies);
            document.removeEventListener('whatsapp:private-reset', resetAcceptedReplies);
            scroll.removeEventListener('scroll', readScroll);
            threadScroll.removeEventListener('scroll', listScroll);
            window.removeEventListener('offline', online); window.removeEventListener('online', online);
            window.removeEventListener('focus', wake);
            // No chat cache in browser storage; remove private text when the page leaves the shell.
            threadMap.clear(); messageMap.clear(); apiReplyMap.clear(); threads.textContent = ''; messages.textContent = '';
            unreadMap.clear(); readSeen.clear(); readPending.clear();
            if (unreadTotal) { unreadTotal.textContent = ''; unreadTotal.hidden = true; }
            config.csrf = '';
            title.textContent = labels.choose_conversation; customer.textContent = ''; count.textContent = '';
        }};
        active = session;
        updateSelection();
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function () {
            if (active === session) destroy(); else session.destroy();
        });
        if (!config.available) { connection.textContent = labels.unavailable; listStatus.textContent = ''; notice(labels.unavailable, true); refresh.disabled = true; }
        else if (navigator.onLine === false) { listStatus.textContent = ''; unavailable(); }
        else poll();
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardWhatsAppInbox = {mount: mount, destroy: destroy,
        selection: function () { return active && active.selection ? active.selection() : 0; },
        selectionState: function () { return active && active.selectionState ? active.selectionState() : {conversation: 0, generation: 0, channel: '', namespace: ''}; }};
    document.addEventListener('dashboard:before-unload', destroy);
    document.addEventListener('dashboard:page-mounted', mount);
    mount();
}());

