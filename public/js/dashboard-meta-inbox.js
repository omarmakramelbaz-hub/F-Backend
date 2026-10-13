(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardMetaInbox) { window.DashboardMetaInbox.mount(); return; }

    var active = null;
    function mount() {
        var panel = document.getElementById('whatsapp-inbox');
        var bootstrap = document.getElementById('whatsapp-inbox-bootstrap');
        if (!panel || !bootstrap || (active && active.panel === panel)) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        if (!config.meta_chat) return;
        var priorInbox = window.DashboardWhatsAppInbox;
        if (priorInbox && priorInbox !== window.DashboardMetaInbox) priorInbox.destroy();
        window.DashboardWhatsAppInbox = window.DashboardMetaInbox;
        var labels = config.labels || {};
        var rich = config.rich_labels || {};
        var overlay = panel.querySelector('[data-meta-overlay]');
        var overlayBody = panel.querySelector('[data-meta-overlay-body]');
        var overlayTitle = panel.querySelector('[data-meta-overlay-title]');
        var actionBusy = false, forwardContext = null;
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
        var threadMap = new Map(), messageMap = new Map(), controllers = new Map();
        var unreadMap = new Map(), readSeen = new Map(), readPending = new Map(), readTimer = null;
        var channel = '', threadEpoch = 0, chosen = 0, generation = 0, nextCursor = null, beforeId = null, lastId = 0;
        var closed = false, pollBusy = false, timer = null, retryDelay = 8000, denied = false;
        var threadLoading = false, messageLoading = false, projectionWarning = false;

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
        function stopMessageMedia() {
            if (messages && messages.querySelectorAll) messages.querySelectorAll('audio,video').forEach(function (media) { media.pause(); media.removeAttribute('src'); media.load(); });
        }
        function closeOverlay() {
            if (!overlay) return;
            overlay.hidden = true; forwardContext = null;
            if (overlayBody) {
                overlayBody.querySelectorAll('audio,video').forEach(function (media) { media.pause(); media.removeAttribute('src'); media.load(); });
                overlayBody.textContent = '';
            }
        }
        function showOverlay(titleText) {
            closeOverlay(); if (!overlay || !overlayBody) return false;
            overlayTitle.textContent = titleText; overlay.hidden = false; return true;
        }
        function safeMediaUrl(media, message) {
            if (!media || typeof media.url !== 'string' || !Number.isSafeInteger(Number(media.index)) || Number(media.index) < 0) return null;
            try {
                var url = new URL(media.url, window.location.href), base = new URL(config.messages_base_url, window.location.href);
                var expected = base.pathname.replace(/\/$/, '') + '/' + chosen + '/messages/' + message.id + '/media/' + media.index;
                return url.origin === new URL(window.location.href).origin && url.pathname === expected && !url.username && !url.password ? url.href : null;
            } catch (error) { return null; }
        }
        function mediaNode(media, message) {
            var url = safeMediaUrl(media, message); if (!url || ['image','audio','video','document'].indexOf(media.type) === -1) return null;
            var holder = node('div', 'meta-chat-media');
            if (media.type === 'image') {
                var button = node('button', 'meta-chat-image'); button.type = 'button';
                button.dataset.metaImage = url; button.dataset.metaImageName = media.name || rich.image || '';
                button.setAttribute('aria-label', rich.open_image || 'Open image');
                var img = node('img'); img.src = url; img.loading = 'lazy'; img.decoding = 'async'; img.alt = media.name || rich.image || '';
                img.referrerPolicy = 'no-referrer';
                img.addEventListener('error', function () { img.hidden = true; button.appendChild(node('span', 'meta-media-unavailable', rich.media_unavailable || 'Media unavailable')); });
                button.appendChild(img); holder.appendChild(button);
            } else if (media.type === 'audio' || media.type === 'video') {
                var player = node(media.type); player.src = url; player.controls = true; player.preload = 'none';
                player.setAttribute('aria-label', media.name || typeText(media.type));
                if (media.type === 'video') player.playsInline = true;
                player.addEventListener('error', function () { player.hidden = true; holder.appendChild(node('span', 'meta-media-unavailable', rich.media_unavailable || 'Media unavailable')); });
                holder.appendChild(player);
            } else {
                var link = node('a', 'meta-chat-document', media.name || rich.document || 'Document');
                link.href = url; link.download = media.name || 'attachment'; link.rel = 'noopener'; holder.appendChild(link);
            }
            return holder;
        }
        function messageActions(message) {
            if (message.hidden || message.unavailable) return null;
            var actions = node('details', 'meta-chat-actions'), summary = node('summary', '', '•••');
            summary.setAttribute('aria-label', rich.actions || 'Message actions'); actions.appendChild(summary);
            var menu = node('div', 'meta-chat-menu');
            [['copy', rich.copy || 'Copy text'], ['forward', rich.forward || 'Forward'], ['hide', rich.hide || 'Delete from dashboard']].forEach(function (action) {
                if (action[0] === 'copy' && !message.text) return;
                var button = node('button', '', action[1]); button.type = 'button';
                button.dataset.metaAction = action[0]; button.dataset.metaMessage = String(message.id); menu.appendChild(button);
            });
            actions.appendChild(menu); return actions;
        }
        function renderForwardRecipients(message, part) {
            var origin = threadMap.get(chosen); if (!origin || !overlayBody) return;
            overlayBody.textContent = ''; forwardContext = {message: message, part: part};
            var summary = part.kind === 'text' ? message.text : (typeText(part.media.type) + ' · ' + (part.media.name || ''));
            overlayBody.appendChild(node('p', 'meta-forward-preview', summary));
            if (part.kind === 'media') { var preview = mediaNode(part.media, message); if (preview) overlayBody.appendChild(preview); }
            var targets = Array.from(threadMap.values()).filter(function (thread) {
                return Number(thread.id) !== chosen && thread.channel === origin.channel && Boolean(origin.account_key) && thread.account_key === origin.account_key;
            });
            if (!targets.length) { overlayBody.appendChild(node('p', '', rich.no_recipients || 'No conversations in this account')); return; }
            overlayBody.appendChild(node('p', 'meta-forward-note', rich.choose_recipient || 'Choose a conversation to review before sending'));
            targets.forEach(function (thread) {
                var button = node('button', 'wa-inbox-button meta-forward-recipient', displayName(thread)); button.type = 'button';
                button.dataset.metaForwardTarget = String(thread.id); button.dataset.metaMessage = String(message.id); overlayBody.appendChild(button);
            });
        }
        function openForward(message) {
            if (!threadMap.get(chosen) || !showOverlay(rich.forward || 'Forward')) return;
            var parts = [];
            if (message.text) parts.push({kind: 'text'});
            (Array.isArray(message.media) ? message.media : []).forEach(function (media) {
                if (['image','audio','video','document'].indexOf(media.type) !== -1 && safeMediaUrl(media, message)) parts.push({kind: 'media', index: Number(media.index), media: media});
            });
            if (!parts.length) { overlayBody.appendChild(node('p', '', rich.forward_unavailable || 'This message cannot be forwarded')); return; }
            if (parts.length === 1) { renderForwardRecipients(message, parts[0]); return; }
            forwardContext = {message: message, parts: parts};
            overlayBody.appendChild(node('p', 'meta-forward-note', rich.choose_part || 'Choose the text or attachment to forward'));
            parts.forEach(function (part, index) {
                var button = node('button', 'wa-inbox-button meta-forward-recipient', part.kind === 'text' ? (rich.text_part || 'Text') : (typeText(part.media.type) + ' · ' + (part.media.name || '')));
                button.type = 'button'; button.dataset.metaForwardPart = String(index); overlayBody.appendChild(button);
            });
        }
        function hideMessage(message) {
            if (actionBusy || closed || denied || !showOverlay(rich.hide || 'Delete from dashboard')) return;
            overlayBody.appendChild(node('p', 'meta-forward-note', rich.hide_note || 'This only hides the message in your dashboard.'));
            var confirm = node('button', 'wa-inbox-button meta-hide-confirm', rich.hide || 'Delete from dashboard');
            confirm.type = 'button'; confirm.dataset.metaHideConfirm = String(message.id); overlayBody.appendChild(confirm);
        }
        function displayName(thread) { return thread.name || thread.phone || labels.customer + ' #' + thread.id; }
        function notice(text, isError) {
            alert.textContent = text || ''; alert.hidden = !text;
            alert.classList.toggle('is-error', Boolean(isError));
        }
        function unavailable(error) {
            if (closed || error && error.name === 'AbortError') return;
            if (error && [401, 403, 419].indexOf(error.status) !== -1) {
                denied = true; generation++; chosen = 0; closeOverlay();
                clearTimeout(readTimer);
                emit('whatsapp:private-reset', {reason: 'denied'});
                threadMap.clear(); messageMap.clear(); threads.textContent = ''; stopMessageMedia(); messages.textContent = '';
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
                }).finally(function () { if (controllers.get(key) === controller) controllers.delete(key); });
        }
        function renderThreads() {
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
                if (thread.channel) body.appendChild(node('span', 'wa-inbox-channel-label', (thread.channel === 'instagram' ? 'Instagram' : 'Messenger') + ' · ' + (thread.account_label || '')));
                var unread = unreadMap.get(Number(thread.id)) || 0;
                if (unread > 0) {
                    var badge = node('span', 'wa-inbox-unread', unread > 99 ? '99+' : String(unread));
                    badge.setAttribute('aria-label', String(unread) + ' ' + (config.unread_label || ''));
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
            listStatus.textContent = threadMap.size ? '' : labels.empty;
        }
        function loadUnread() {
            if (!config.unread_url || !unreadTotal || closed || denied) return Promise.resolve();
            var ids = Array.from(threadMap.keys()).slice(0, 99);
            if (chosen && ids.indexOf(chosen) === -1) ids.push(chosen);
            var url = new URL(config.unread_url, window.location.href);
            ids.forEach(function (id) { url.searchParams.append('ids[]', String(id)); });
            return request(url.href, 'unread').then(function (data) {
                if (closed || denied) return;
                (data.conversations || []).forEach(function (row) { unreadMap.set(Number(row.id), Number(row.unread_count) || 0); });
                var total = Number(data.total_unread) || 0;
                unreadTotal.textContent = (config.unread_label || '') + ': ' + total; unreadTotal.hidden = !total;
                renderThreads();
            });
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
                if (item.dataset.waDirection !== 'inbound' || typeof item.getBoundingClientRect !== 'function') return;
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
            if (!config.read_base_url) return;
            clearTimeout(readTimer); readTimer = setTimeout(acknowledgeVisible, 200);
        }
        function loadThreads(previous) {
            if (threadLoading) return Promise.resolve();
            var page = previous ? nextCursor : null, epoch = threadEpoch;
            if (previous && !page) return Promise.resolve();
            threadLoading = true; more.disabled = true;
            var url = new URL(config.conversations_url, window.location.href);
            if (page) url.searchParams.set('cursor', page);
            if (channel) url.searchParams.set('channel', channel);
            return request(url.href, 'threads').then(function (data) {
                if (closed || denied || epoch !== threadEpoch) return;
                projectionWarning = Boolean(data.projection_warning);
                // Refresh keeps older explicitly loaded pages, updating only current rows.
                (data.conversations || []).forEach(function (thread) { threadMap.set(Number(thread.id), thread); });
                renderThreads(); count.textContent = String(data.total || 0);
                if (previous || !nextCursor || threadMap.size <= 25) nextCursor = data.next_cursor || null;
                more.hidden = !nextCursor;
            }).finally(function () { if (epoch === threadEpoch) { threadLoading = false; if (!closed) more.disabled = false; } });
        }
        function renderMessages(prepend, toBottom) {
            var oldHeight = scroll.scrollHeight, oldTop = scroll.scrollTop;
            var fragment = document.createDocumentFragment();
            Array.from(messageMap.values()).sort(function (a, b) {
                return String(a.sent_at || a.received_at || '').localeCompare(String(b.sent_at || b.received_at || '')) || a.id - b.id;
            }).forEach(function (message) {
                var item = node('li', 'wa-inbox-message ' + (message.hidden ? 'is-hidden ' : '') + (message.direction === 'outbound' ? 'is-outbound' : 'is-inbound'));
                item.dataset.waMessageId = String(message.id); item.dataset.waDirection = message.direction;
                var bubble = node('article', 'wa-inbox-bubble');
                bubble.appendChild(node('span', 'wa-inbox-author', message.direction === 'outbound' ? labels.business : displayName(threadMap.get(chosen) || {id: chosen})));
                var actions = messageActions(message); if (actions) bubble.appendChild(actions);
                if (message.type !== 'text') bubble.appendChild(node('span', 'wa-inbox-message-type', typeText(message.type)));
                var cart = !message.unavailable && message.type === 'order' ? cartNode(message.cart) : null;
                if (!message.hidden && !message.unavailable && Array.isArray(message.media)) message.media.slice(0, 10).forEach(function (media) { var element = mediaNode(media, message); if (element) bubble.appendChild(element); });
                if (cart) bubble.appendChild(cart);
                else bubble.appendChild(node('p', 'wa-inbox-message-text', message.unavailable ? labels.message_unavailable
                    : (message.text || typeText(message.type))));
                var timestamp = message.sent_at || message.received_at;
                var time = node('time', '', dateText(timestamp, true)); if (timestamp) time.dateTime = timestamp;
                bubble.appendChild(time); item.appendChild(bubble); fragment.appendChild(item);
            });
            stopMessageMedia(); messages.textContent = ''; messages.appendChild(fragment);
            chatStatus.textContent = messageMap.size ? '' : labels.no_messages;
            if (prepend) scroll.scrollTop = oldTop + scroll.scrollHeight - oldHeight;
            else if (toBottom) scroll.scrollTop = scroll.scrollHeight;
            else scroll.scrollTop = oldTop;
            acknowledgeVisible();
        }
        function loadMessages(mode) {
            if (!chosen || closed || messageLoading) return Promise.resolve();
            var selection = chosen, current = generation;
            var url = new URL(config.messages_base_url + '/' + selection + '/messages', window.location.href);
            if (mode === 'older') { if (!beforeId) return Promise.resolve(); url.searchParams.set('before_id', beforeId); }
            else if (mode === 'newer') url.searchParams.set('after_id', lastId);
            var nearBottom = scroll.scrollHeight - scroll.clientHeight - scroll.scrollTop < 90;
            messageLoading = true; older.disabled = true;
            return request(url.href, 'messages').then(function (data) {
                if (closed || current !== generation || selection !== chosen) return;
                projectionWarning = projectionWarning || Boolean(data.projection_warning);
                if (data.conversation) threadMap.set(Number(data.conversation.id || chosen), Object.assign({}, threadMap.get(chosen) || {}, data.conversation));
                title.textContent = displayName(data.conversation || {id: chosen});
                customer.textContent = data.conversation && data.conversation.name && data.conversation.phone ? data.conversation.phone : '';
                customer.dir = 'ltr';
                var rows = data.messages || [];
                rows.forEach(function (message) { messageMap.set(Number(message.id), message); });
                lastId = Math.max(lastId, Number(data.last_id) || 0);
                if (mode !== 'newer') beforeId = data.next_before_id || null;
                older.hidden = !beforeId;
                if (mode !== 'newer' || rows.length) renderMessages(mode === 'older', mode === 'initial' || mode === 'newer' && nearBottom);
                if (mode === 'newer' && rows.length) emit('whatsapp:conversation-updated', {conversation: selection});
                if (mode === 'newer' && data.has_more) {
                    messageLoading = false;
                    return loadMessages('newer');
                }
            }).finally(function () {
                if (!closed && current === generation) { messageLoading = false; older.disabled = false; }
            });
        }
        function choose(id) {
            if (closed || denied || !id) return;
            if (chosen === id) { panel.classList.add('has-selection'); back.hidden = false; return; }
            closeOverlay(); generation++; chosen = id; beforeId = null; lastId = 0; messageLoading = false; messageMap.clear();
            emit('whatsapp:conversation-selected', {conversation: id});
            if (controllers.has('messages')) controllers.get('messages').abort();
            stopMessageMedia(); messages.textContent = ''; title.textContent = labels.loading; customer.textContent = '';
            chatStatus.textContent = labels.loading; older.hidden = true;
            panel.classList.add('has-selection'); back.hidden = false; renderThreads();
            loadMessages('initial').catch(unavailable);
        }
        function success() {
            if (closed || denied) return;
            retryDelay = 8000; notice(projectionWarning ? labels.projection_warning : '', projectionWarning);
            connection.textContent = labels.connected + ' · ' + new Date().toLocaleTimeString(document.documentElement.lang || 'ar',
                {hour: '2-digit', minute: '2-digit'});
        }
        function schedule() {
            clearTimeout(timer);
            if (!closed && !denied && config.available && !document.hidden && navigator.onLine !== false) timer = setTimeout(poll, retryDelay);
        }
        function poll() {
            if (closed || pollBusy || denied || document.hidden || navigator.onLine === false || !config.available) { schedule(); return; }
            pollBusy = true; refresh.disabled = true;
            Promise.all([loadThreads(false), loadMessages(chosen && lastId ? 'newer' : 'initial')]).then(loadUnread).then(success).catch(unavailable)
                .finally(function () { pollBusy = false; if (!closed) refresh.disabled = false; schedule(); });
        }
        function click(event) {
            var close = event.target.closest('[data-meta-overlay-close]');
            if (close) { closeOverlay(); return; }
            var imageButton = event.target.closest('[data-meta-image]');
            if (imageButton && panel.contains(imageButton)) {
                if (showOverlay(imageButton.dataset.metaImageName || rich.image || 'Image')) {
                    var large = node('img', 'meta-lightbox-image'); large.src = imageButton.dataset.metaImage; large.alt = imageButton.dataset.metaImageName || ''; overlayBody.appendChild(large);
                } return;
            }
            var action = event.target.closest('[data-meta-action]');
            if (action && panel.contains(action)) {
                var message = messageMap.get(Number(action.dataset.metaMessage)); if (!message || message.hidden) return;
                if (action.dataset.metaAction === 'copy' && navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(message.text || '').then(function () { if (!closed) notice(rich.copied || 'Copied'); }).catch(function () { notice(rich.copy_failed || 'Copy unavailable', true); });
                } else if (action.dataset.metaAction === 'forward') openForward(message);
                else if (action.dataset.metaAction === 'hide') hideMessage(message);
                return;
            }
            var partButton = event.target.closest('[data-meta-forward-part]');
            if (partButton && forwardContext && forwardContext.parts) {
                var part = forwardContext.parts[Number(partButton.dataset.metaForwardPart)];
                if (part) renderForwardRecipients(forwardContext.message, part); return;
            }
            var target = event.target.closest('[data-meta-forward-target]');
            if (target && panel.contains(target)) {
                var source = messageMap.get(Number(target.dataset.metaMessage)), recipient = Number(target.dataset.metaForwardTarget);
                var origin = threadMap.get(chosen), destination = threadMap.get(recipient);
                if (!source || !origin || !destination || !origin.account_key || origin.account_key !== destination.account_key || origin.channel !== destination.channel) return;
                if (!forwardContext || !forwardContext.part || Number(forwardContext.message.id) !== Number(source.id)) return;
                var part = forwardContext.part, summary = part.kind === 'text' ? source.text : (typeText(part.media.type) + ' · ' + (part.media.name || '')), sourceId = Number(source.id);
                choose(recipient); emit('meta:forward-draft', {conversation: recipient, source_message_id: sourceId, source_kind: part.kind, source_media_index: part.index, preview: summary}); return;
            }
            var hide = event.target.closest('[data-meta-hide-confirm]');
            if (hide && panel.contains(hide) && !actionBusy) {
                var hiddenId = Number(hide.dataset.metaHideConfirm), hiddenMessage = messageMap.get(hiddenId), selected = chosen, current = generation;
                if (!hiddenMessage) return; actionBusy = true; hide.disabled = true;
                request(config.messages_base_url + '/' + selected + '/messages/' + hiddenId + '/hide', 'hide', {}).then(function (data) {
                    if (closed || current !== generation || selected !== chosen) return;
                    if (data.hidden !== true) throw new Error('Unavailable');
                    messageMap.set(hiddenId, Object.assign({}, hiddenMessage, {hidden: true, type: 'system', text: rich.hidden_message || 'Deleted from your dashboard', media: [], cart: null}));
                    closeOverlay(); renderMessages(false, false);
                }).catch(unavailable).finally(function () { actionBusy = false; }); return;
            }

            var channelButton = event.target.closest('[data-meta-channel]');
            if (channelButton && panel.contains(channelButton)) {
                closeOverlay(); channel = channelButton.dataset.metaChannel || ''; threadEpoch++; generation++; chosen = 0;
                controllers.forEach(function (controller) { controller.abort(); }); controllers.clear();
                clearTimeout(readTimer); readPending.clear(); emit('whatsapp:private-reset', {reason: 'channel'});
                threadMap.clear(); messageMap.clear(); threads.textContent = ''; stopMessageMedia(); messages.textContent = '';
                nextCursor = null; beforeId = null; lastId = 0; threadLoading = false; messageLoading = false;
                title.textContent = labels.choose_conversation; customer.textContent = ''; older.hidden = true; more.hidden = true;
                panel.classList.remove('has-selection'); back.hidden = true;
                panel.querySelectorAll('[data-meta-channel]').forEach(function (item) { item.setAttribute('aria-pressed', item === channelButton ? 'true' : 'false'); });
                loadThreads(false).then(loadUnread).then(success).catch(unavailable); return;
            }
            var button = event.target.closest('[data-wa-thread]');
            if (button && panel.contains(button)) { choose(Number(button.dataset.waThread)); return; }
            if (event.target.closest('[data-wa-older]') && !older.disabled) loadMessages('older').catch(unavailable);
            if (event.target.closest('[data-wa-more-threads]') && !more.disabled) loadThreads(true).catch(unavailable);
            if (event.target.closest('[data-wa-refresh]')) { clearTimeout(timer); poll(); }
            if (event.target.closest('[data-wa-back]')) { panel.classList.remove('has-selection'); back.hidden = true; }
        }
        function keyboard(event) { if (event.key === 'Escape') closeOverlay(); }
        function visibility() {
            if (document.hidden) { closeOverlay(); clearTimeout(timer); emit('whatsapp:private-reset', {reason: 'hidden'}); }
            else { clearTimeout(timer); if (chosen && !denied) emit('whatsapp:conversation-selected', {conversation: chosen}); acknowledgeVisible(); poll(); }
        }
        function online() {
            clearTimeout(timer);
            if (navigator.onLine === false) { closeOverlay(); emit('whatsapp:private-reset', {reason: 'offline'}); unavailable(); }
            else { if (chosen && !denied) emit('whatsapp:conversation-selected', {conversation: chosen}); poll(); }
        }
        panel.addEventListener('click', click);
        document.addEventListener('keydown', keyboard);
        scroll.addEventListener('scroll', readScroll);
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('offline', online); window.addEventListener('online', online);
        var session = {panel: panel, selection: function () { return chosen; }, destroy: function () {
            if (closed) return; closeOverlay(); closed = true; generation++; clearTimeout(timer);
            clearTimeout(readTimer);
            emit('whatsapp:private-reset', {reason: 'closed'});
            controllers.forEach(function (controller) { controller.abort(); }); controllers.clear();
            panel.removeEventListener('click', click); document.removeEventListener('keydown', keyboard); document.removeEventListener('visibilitychange', visibility);
            scroll.removeEventListener('scroll', readScroll);
            window.removeEventListener('offline', online); window.removeEventListener('online', online);
            // No chat cache in browser storage; remove private text when the page leaves the shell.
            threadMap.clear(); messageMap.clear(); threads.textContent = ''; stopMessageMedia(); messages.textContent = '';
            unreadMap.clear(); readSeen.clear(); readPending.clear();
            if (unreadTotal) { unreadTotal.textContent = ''; unreadTotal.hidden = true; }
            if (window.DashboardWhatsAppInbox === window.DashboardMetaInbox) window.DashboardWhatsAppInbox = priorInbox;
            config.csrf = '';
            title.textContent = labels.choose_conversation; customer.textContent = ''; count.textContent = '';
        }};
        active = session;
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function () {
            if (active === session) destroy(); else session.destroy();
        });
        if (!config.available) { connection.textContent = labels.unavailable; listStatus.textContent = ''; notice(labels.unavailable, true); refresh.disabled = true; }
        else if (navigator.onLine === false) { listStatus.textContent = ''; unavailable(); }
        else poll();
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardMetaInbox = {mount: mount, destroy: destroy,
        selection: function () { return active && active.selection ? active.selection() : 0; }};
    document.addEventListener('dashboard:before-unload', destroy);
    document.addEventListener('dashboard:page-mounted', mount);
    mount();
}());
