(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;

    var board = document.getElementById('order-board');
    if (!board) return;

    var form = document.getElementById('ob-filters');
    var columns = document.getElementById('all_orders');
    var live = document.getElementById('ob-live');
    var message = document.getElementById('ob-message');
    var copy = JSON.parse(document.getElementById('ob-translations').textContent);
    var pendingAction = 0;
    var pendingKeys = new Set();
    var activeFeed = null;
    var generation = 0;
    var searchTimer = null;
    var messageTimer = null;
    var reconcileTimer = null;
    var reconcileNeeded = false;
    var reconcileUrgent = false;
    var needsRefresh = false;
    var interval = null;
    var lastSubmitter = null;
    var disposed = false;
    var previousReload = window.reloadOrderSections;

    function translated(key) { return copy[key] || key; }

    function journalCommand(actionForm, data) {
        var local = document.body.dataset.dashboardLocal === '1';
        var website = /^website:/.test(actionForm.closest('[data-order-key]').dataset.orderKey || '');
        var desktop = local || !!(window.FasakhanstaDesktop && document.body.dataset.dashboardRemoteAttempts === '1');
        if (!website && !desktop) return;
        var epoch = actionForm.dataset.desktopBoardGeneration;
        var uuid = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;
        if (local ? !uuid.test(epoch || '') : epoch !== 'server') throw new Error(translated('error'));
        var endpoint = new URL(actionForm.getAttribute('action'), window.location.href);
        if (endpoint.origin !== window.location.origin || !/^\/admin\/order-board\/(?:legacy|store|service|partner_service|website)\/[1-9][0-9]{0,18}\/action$/.test(endpoint.pathname)) throw new Error(translated('error'));
        var fields = Array.from(data.entries()).filter(function (field) { return !['_token', '_desktop_command', 'idempotency_key'].includes(field[0]); });
        if (fields.some(function (field) { return typeof field[1] !== 'string'; })) throw new Error(translated('error'));
        fields.sort(function (a, b) { return a[0] === b[0] ? a[1].localeCompare(b[1]) : a[0].localeCompare(b[0]); });
        var key = 'fasakhansta.board.command.' + JSON.stringify([document.body.dataset.dashboardActor, epoch, endpoint.pathname, fields]);
        // Keep this intent across a lost response, polling replacement and reload.
        // A different account, snapshot, state or selected action gets another UUID.
        var command = sessionStorage.getItem(key);
        if (!command) { command = crypto.randomUUID(); sessionStorage.setItem(key, command); }
        if (!uuid.test(command)) throw new Error(translated('error'));
        if (desktop) data.set('_desktop_command', command);
        data.set('idempotency_key', command);
    }

    function setLive(state) {
        live.classList.toggle('is-updating', state === 'updating');
        live.classList.toggle('is-offline', state === 'offline');
        var label = translated(state === 'offline' ? 'offline' : state === 'updating' ? 'updating' : 'live');
        if (state === 'updated') {
            label = translated('updated') + ' ' + new Date().toLocaleTimeString(document.documentElement.dir === 'rtl' ? 'ar-EG' : 'en', { hour: '2-digit', minute: '2-digit' });
        }
        live.querySelector('[data-live-label]').textContent = label;
    }

    function notify(text, success, retry) {
        if (disposed) return;
        clearTimeout(messageTimer);
        message.querySelector('[data-message-text]').textContent = text;
        message.querySelector('[data-board-retry]').hidden = !retry;
        message.classList.toggle('is-success', !!success);
        message.hidden = false;
        if (success) messageTimer = setTimeout(function () { message.hidden = true; }, 6000);
    }

    function filters() {
        var params = new URLSearchParams(new FormData(form));
        Array.from(params.entries()).forEach(function (entry) {
            var key = entry[0], value = entry[1];
            if (!value || (/^page_/.test(key) && value === '1')) params.delete(key);
        });
        return params;
    }

    function resetPages() {
        form.querySelectorAll('input[name^="page_"]').forEach(function (input) { input.value = '1'; });
    }

    function rememberFilters() {
        var page = new URL(form.action, window.location.href);
        page.search = filters().toString();
        window.history.replaceState(window.history.state, '', page.toString());
    }

    function modalIsOpen() {
        return !!document.querySelector('.modal.show, dialog[open]');
    }

    function syncOrderAlerts(source) {
        var alerts = window.DashboardOrderAlerts;
        if (!alerts) return;
        // Only an explicitly resolved card acknowledges a live event. Missing cards
        // may simply have moved outside the selected branch, search or page.
        alerts.observeApp(source);
    }

    function syncCards(nextHtml) {
        // Reconcile each column by order key. Existing cards and their scroll positions
        // remain in place when a polling response contains no changes.
        var next = document.createElement('template');
        next.innerHTML = nextHtml;
        var scroll = {};
        columns.querySelectorAll('[data-column-list]').forEach(function (list) {
            scroll[list.dataset.columnList] = list.scrollTop;
        });
        var horizontal = columns.scrollLeft;
        var correctedPage = false;
        next.content.querySelectorAll('[data-column]').forEach(function (incomingColumn) {
            var stage = incomingColumn.dataset.column;
            var existingColumn = columns.querySelector('[data-column="' + stage + '"]');
            if (!existingColumn) return;
            var existingList = existingColumn.querySelector('[data-column-list]');
            var incomingList = incomingColumn.querySelector('[data-column-list]');
            if (!existingList || !incomingList) return;
            var known = new Map();
            existingList.querySelectorAll('[data-order-key]').forEach(function (card) { known.set(card.dataset.orderKey, card); });
            var keep = new Set();
            var cursor = existingList.firstElementChild;
            Array.from(incomingList.children).forEach(function (incoming) {
                var key = incoming.dataset.orderKey;
                var existing = key ? known.get(key) : existingList.querySelector('.ob-empty');
                var node = existing && existing.outerHTML === incoming.outerHTML ? existing : incoming;
                // Polling must not erase a cashier's unfinished company/cash choice.
                if (existing && node !== existing && /^website:/.test(key || '')) {
                    var beforeRevision = existing.querySelector('[name="expected_revision"]');
                    var afterRevision = incoming.querySelector('[name="expected_revision"]');
                    if (beforeRevision && afterRevision && beforeRevision.value === afterRevision.value) {
                        ['company_id', 'cash_received', 'payment_confirmed'].forEach(function (field) {
                            var before = existing.querySelector('[name="' + field + '"]');
                            var after = incoming.querySelector('[name="' + field + '"]');
                            if (!before || !after) return;
                            if (after.type === 'checkbox') after.checked = before.checked;
                            else after.value = before.value;
                        });
                    }
                }
                if (node !== cursor) existingList.insertBefore(node, cursor);
                keep.add(node);
                cursor = node.nextElementSibling;
            });
            Array.from(existingList.children).forEach(function (node) { if (!keep.has(node)) node.remove(); });
            existingList.scrollTop = scroll[stage] || 0;
            var nextFooter = incomingColumn.querySelector('.ob-column-footer');
            var currentFooter = existingColumn.querySelector('.ob-column-footer');
            if (nextFooter && currentFooter && nextFooter.outerHTML !== currentFooter.outerHTML) currentFooter.replaceWith(nextFooter);
            var pageInput = form.querySelector('[name="page_' + stage + '"]');
            if (nextFooter && pageInput && nextFooter.dataset.currentPage && pageInput.value !== nextFooter.dataset.currentPage) {
                pageInput.value = nextFooter.dataset.currentPage;
                correctedPage = true;
            }
        });
        columns.scrollLeft = horizontal;
        if (correctedPage) rememberFilters();
    }

    function syncCounts(counts) {
        if (!counts) return;
        board.querySelectorAll('[data-board-count]').forEach(function (counter) {
            var value = counts[counter.dataset.boardCount];
            if (value !== undefined) counter.textContent = value;
        });
    }

    function applyConfirmedCard(patch, previousCard, query) {
        // Only move a card after the server confirms the committed state. An old
        // request must not insert an order into a newly selected branch or date.
        if (!patch || query !== filters().toString() || !previousCard.isConnected
            || patch.key !== previousCard.dataset.orderKey || typeof patch.html !== 'string') return false;
        var sourceColumn = previousCard.closest('[data-column]');
        var source = sourceColumn && sourceColumn.dataset.column;
        if (source !== patch.from_group || ['new', 'preparing', 'courier', 'completed'].indexOf(patch.group) === -1) return false;
        var targetColumn = columns.querySelector('[data-column="' + patch.group + '"]');
        var target = targetColumn && targetColumn.querySelector('[data-column-list]');
        if (!target) return false;
        var incoming = document.createElement('template');
        incoming.innerHTML = patch.html.trim();
        var nextCard = incoming.content.firstElementChild;
        if (incoming.content.childElementCount !== 1 || !nextCard.matches('[data-order-key]')
            || nextCard.dataset.orderKey !== patch.key) return false;
        if (source === patch.group) {
            previousCard.replaceWith(nextCard);
            return true;
        }
        var sourceList = previousCard.parentElement;
        var empty = columns.querySelector('.ob-empty');
        var emptyCopy = empty && empty.cloneNode(true);
        var sourceScroll = sourceList.scrollTop, targetScroll = target.scrollTop;
        previousCard.remove();
        var pageInput = form.querySelector('[name="page_' + patch.group + '"]');
        // A later page has its own server-defined slice; let reconciliation fill it.
        if (!pageInput || pageInput.value === '1') {
            var targetEmpty = target.querySelector('.ob-empty');
            if (targetEmpty) targetEmpty.remove();
            target.insertBefore(nextCard, target.firstElementChild);
        }
        if (!sourceList.querySelector('[data-order-key]') && !sourceList.querySelector('.ob-empty')) {
            if (!emptyCopy) {
                emptyCopy = document.createElement('div');
                emptyCopy.className = 'ob-empty';
                emptyCopy.textContent = translated('empty');
            }
            sourceList.appendChild(emptyCopy);
        }
        board.querySelectorAll('[data-board-count]').forEach(function (counter) {
            var stage = counter.dataset.boardCount, value = Number(counter.textContent);
            if (!Number.isFinite(value)) return;
            if (stage === source) counter.textContent = Math.max(0, value - 1);
            else if (stage === patch.group) counter.textContent = value + 1;
        });
        sourceList.scrollTop = sourceScroll;
        target.scrollTop = targetScroll;
        return true;
    }

    function reconcileAfterActions() {
        if (disposed || pendingAction) return;
        clearTimeout(reconcileTimer);
        if (reconcileUrgent) {
            reconcileUrgent = false; reconcileNeeded = false;
            refresh(true);
        } else if (reconcileNeeded || needsRefresh) {
            // The confirmed card is already visible. Batch the full feed for exact
            // pagination and other operators' changes after a burst of actions.
            reconcileTimer = setTimeout(function () {
                reconcileTimer = null; reconcileNeeded = false;
                refresh(true);
            }, 650);
        }
    }

    function errorText(response, payload) {
        if (response.status === 401 || response.status === 419) return translated('session_expired');
        if (response.status === 403) return translated('forbidden');
        // Server faults can contain exception messages; keep them out of the operator UI.
        if (response.status >= 500) return translated('error');
        if (response.status === 409) return payload.message || translated('stale');
        return payload.message || translated('error');
    }

    async function readJson(response, readBody) {
        var payload;
        var parsed = false;
        try { payload = await (readBody ? readBody(response) : response.json()); parsed = true; } catch (_) { payload = {}; }
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) { payload = {}; parsed = false; }
        if (response.ok && !parsed) throw new Error(translated('error'));
        if (!response.ok || payload.success === false || payload.status === 'Error') {
            var error = new Error(errorText(response, payload));
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    async function readFeed(endpoint, controller) {
        var reader = null, timer, expired = false, cancel;
        var stopped = new Promise(function (_resolve, reject) {
            cancel = function () {
                reject(expired ? new Error(translated('loading_failed')) : new DOMException('The read was cancelled', 'AbortError'));
                if (reader) reader.cancel().catch(function () {});
            };
            controller.signal.addEventListener('abort', cancel, { once: true });
            timer = setTimeout(function () { expired = true; controller.abort(); }, 15000);
        });
        var reading = (async function () {
            if (controller.signal.aborted) throw new DOMException('The read was cancelled', 'AbortError');
            var response = await fetch(endpoint, { method: 'GET', credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (controller.signal.aborted) {
                if (response.body) response.body.cancel().catch(function () {});
                throw new DOMException('The read was cancelled', 'AbortError');
            }
            return readJson(response, async function (value) {
                if (!value.body || typeof value.body.getReader !== 'function') return value.json();
                reader = value.body.getReader();
                var decoder = new TextDecoder(), text = '';
                try {
                    while (true) {
                        var part = await reader.read();
                        if (controller.signal.aborted) throw new DOMException('The read was cancelled', 'AbortError');
                        if (part.done) break;
                        text += decoder.decode(part.value, { stream: true });
                    }
                    return JSON.parse(text + decoder.decode());
                } finally { reader.releaseLock(); reader = null; }
            });
        })();
        try { return await Promise.race([reading, stopped]); }
        finally { clearTimeout(timer); controller.signal.removeEventListener('abort', cancel); }
    }

    async function refresh(force) {
        if (disposed) return;
        if (pendingAction) {
            needsRefresh = true;
            if (pendingAction && force) reconcileUrgent = true;
            return;
        }
        if (activeFeed) {
            if (!force) return;
            activeFeed.abort();
        }
        var controller = new AbortController();
        activeFeed = controller;
        var currentGeneration = ++generation;
        var alertCheckpoint = window.DashboardOrderAlerts ? window.DashboardOrderAlerts.checkpoint() : 0;
        var query = filters().toString();
        var endpoint = new URL(board.dataset.feedUrl, window.location.href);
        endpoint.search = query;
        setLive('updating');
        try {
            var payload = await readFeed(endpoint.toString(), controller);
            if (disposed || currentGeneration !== generation || query !== filters().toString() || pendingAction) return;
            var html = payload.html !== undefined ? payload.html : payload.view;
            if (typeof html !== 'string') throw new Error(translated('loading_failed'));
            if (window.DashboardOrderAlerts && Array.isArray(payload.pending_alert_keys)) window.DashboardOrderAlerts.reconcileApp(payload.pending_alert_keys, alertCheckpoint);
            else syncOrderAlerts(html);
            // Keep detecting new orders while a dialog is open, but defer DOM changes.
            if (modalIsOpen()) { needsRefresh = true; return; }
            syncCards(html);
            syncCounts(payload.counts);
            setLive('updated');
            needsRefresh = false;
            if (message.querySelector('[data-board-retry]').hidden === false) message.hidden = true;
        } catch (error) {
            if (disposed || error.name === 'AbortError' || currentGeneration !== generation) return;
            setLive('offline');
            notify(error.message || translated('loading_failed'), false, true);
        } finally {
            if (activeFeed === controller) activeFeed = null;
        }
    }

    // The existing notification handlers call this function after an order event.
    var reloadBoard = function () { refresh(false); };
    window.reloadOrderSections = reloadBoard;

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        // The shared footer replaces all submit-button labels; this AJAX form owns its UI.
        event.stopImmediatePropagation();
        clearTimeout(searchTimer); searchTimer = null;
        resetPages();
        rememberFilters();
        refresh(true);
    });
    function filterChanged() {
        resetPages();
        rememberFilters();
        refresh(true);
    }
    form.addEventListener('change', function (event) {
        if (event.target.matches('input[type="date"]') || (!window.jQuery && event.target.matches('select'))) {
            filterChanged();
        }
    });
    if (window.jQuery) {
        // AdminLTE upgrades these selects with Select2, which emits jQuery change events.
        window.jQuery(form).on('change.orderBoard', 'select', filterChanged);
    }
    form.querySelector('[name="search"]').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { searchTimer = null; resetPages(); rememberFilters(); refresh(true); }, 450);
    });
    board.querySelector('[data-board-reset]').addEventListener('click', function (event) {
        event.preventDefault();
        form.querySelectorAll('input, select').forEach(function (input) { input.value = ''; });
        if (window.jQuery) window.jQuery(form).find('select').trigger('change.select2');
        resetPages();
        var date = form.querySelector('[name="date"]');
        date.value = board.dataset.defaultDate || '';
        rememberFilters();
        refresh(true);
    });
    board.querySelector('[data-board-retry]').addEventListener('click', function () { refresh(true); });

    columns.addEventListener('click', function (event) {
        var printLink = event.target.closest('a[href]');
        if (printLink && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) {
            var printUrl = new URL(printLink.href, window.location.href);
            if (printUrl.origin === window.location.origin && /^\/admin\/order-board\/(legacy|store|service|partner_service|website)\/\d+\/print\/?$/.test(printUrl.pathname)) {
                event.preventDefault();
                if (!window.DashboardPrint) { notify(translated('error'), false, false); return; }
                printLink.setAttribute('aria-busy', 'true');
                window.DashboardPrint.print(printUrl.toString()).catch(function (error) {
                    if (!disposed) notify(error.message || translated('error'), false, false);
                }).finally(function () { printLink.removeAttribute('aria-busy'); });
                return;
            }
        }
        var pageLink = event.target.closest('[data-board-page]');
        if (pageLink) {
            event.preventDefault();
            var url = new URL(pageLink.href, window.location.href);
            form.querySelectorAll('input[name^="page_"]').forEach(function (input) { input.value = url.searchParams.get(input.name) || '1'; });
            var list = pageLink.closest('[data-column]').querySelector('[data-column-list]');
            list.scrollTop = 0;
            rememberFilters();
            refresh(true);
            return;
        }
        var button = event.target.closest('button[name="action"]');
        if (button) lastSubmitter = button;
    });
    columns.addEventListener('submit', async function (event) {
        var actionForm = event.target.closest('[data-order-action]');
        if (!actionForm) return;
        event.preventDefault();
        // Capture before the shared footer's direct form-submit listeners can alter cards.
        event.stopImmediatePropagation();
        var card = actionForm.closest('[data-order-key]');
        var key = card && card.dataset.orderKey;
        if (!key || pendingKeys.has(key)) return;
        var submitter = event.submitter || lastSubmitter;
        if (!submitter || submitter.form !== actionForm) return;
        var action = submitter.value;
        var company = actionForm.querySelector('[data-website-company]');
        var cash = actionForm.querySelector('[data-website-cash]');
        var payment = actionForm.querySelector('[data-website-payment]');
        if (action === 'dispatch' && company && !company.value) {
            company.setCustomValidity(document.documentElement.dir === 'rtl' ? 'اختر شركة التوصيل أولًا.' : 'Choose a delivery company.');
            company.reportValidity(); company.setCustomValidity(''); return;
        }
        if (action === 'complete' && cash && (!cash.value.trim() || !payment.checked)) {
            notify(document.documentElement.dir === 'rtl' ? 'أدخل الكاش المستلم وأكد تحصيل قيمة الطلب فعليًا.' : 'Enter the cash received and confirm actual collection.', false, false);
            return;
        }
        if ((action === 'reject' || action === 'cancel') && !window.confirm(translated('confirm_' + action))) return;
        var data = new FormData(actionForm);
        data.set('action', action);
        try { journalCommand(actionForm, data); }
        catch (error) { notify(error.message || translated('error'), false, false); return; }
        var query = filters().toString();
        pendingKeys.add(key); pendingAction++;
        clearTimeout(reconcileTimer); reconcileTimer = null;
        // Aborting fetch alone does not cancel a response already being parsed.
        generation++;
        if (activeFeed) activeFeed.abort();
        var buttons = Array.from(actionForm.querySelectorAll('button')).map(function (button) {
            return { element: button, disabled: button.disabled, html: button.innerHTML };
        });
        buttons.forEach(function (button) { button.element.disabled = true; });
        var savingIcon = document.createElement('i');
        savingIcon.className = 'fas fa-spinner fa-spin'; savingIcon.setAttribute('aria-hidden', 'true');
        submitter.replaceChildren(savingIcon, document.createTextNode(' ' + translated('saving')));
        card.classList.add('is-busy');
        card.setAttribute('aria-busy', 'true');
        notify(translated('saving'), false, false);
        try {
            // Buttons named "action" shadow HTMLFormElement.action with a RadioNodeList.
            var response = await fetch(actionForm.getAttribute('action'), {
                method: 'POST', credentials: 'same-origin', body: data,
                headers: {
                    'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });
            var payload = await readJson(response);
            if (disposed) return;
            if (action === 'reject') {
                clearTimeout(messageTimer); message.hidden = true;
            } else notify(payload.message || translated('saved'), true, false);
            reconcileNeeded = true;
            if (applyConfirmedCard(payload.card, card, query)) setLive('updated');
            else reconcileUrgent = true;
            // Shared notification audio can be unavailable while external SDKs initialize.
            // A sound failure must never report a successfully committed order as failed.
            if (window.DashboardOrderAlerts) {
                try { window.DashboardOrderAlerts.acknowledge(key); } catch (_) { /* The order has already been saved. */ }
            }
        } catch (error) {
            if (disposed) return;
            notify(error.message || translated('error'), false, false);
            if (error.status === 409) reconcileUrgent = true;
        } finally {
            pendingKeys.delete(key); pendingAction--;
            buttons.forEach(function (button) {
                button.element.disabled = button.disabled;
                button.element.innerHTML = button.html;
            });
            card.classList.remove('is-busy');
            card.removeAttribute('aria-busy');
            reconcileAfterActions();
        }
    }, true);

    function sizeBoard() {
        if (disposed) return;
        var wrapper = board.closest('.order-board-wrapper');
        var footer = document.querySelector('.main-footer');
        wrapper.style.setProperty('--ob-top', Math.max(0, wrapper.getBoundingClientRect().top) + 'px');
        wrapper.style.setProperty('--ob-footer', (footer ? footer.getBoundingClientRect().height : 0) + 'px');
    }
    syncOrderAlerts(columns);
    sizeBoard();
    window.addEventListener('load', sizeBoard);
    window.addEventListener('resize', sizeBoard);
    if (window.ResizeObserver) {
        var resize = new ResizeObserver(sizeBoard);
        var footer = document.querySelector('.main-footer');
        var navbar = document.querySelector('.main-header');
        if (footer) resize.observe(footer);
        if (navbar) resize.observe(navbar);
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(false); });
    window.addEventListener('online', function () { refresh(false); });
    interval = setInterval(function () { refresh(false); }, 10000);
    window.addEventListener('pagehide', function () { clearInterval(interval); if (activeFeed) activeFeed.abort(); });
    if (window.DashboardSPA && window.DashboardSPA.onIdle) window.DashboardSPA.onIdle(function () {
        if (disposed || pendingAction || pendingKeys.size || activeFeed || searchTimer || reconcileTimer
            || reconcileNeeded || reconcileUrgent || modalIsOpen()) return false;
        try {
            var target = new URL(form.getAttribute('action'), window.location.href);
            if (form.id !== 'ob-filters' || String(form.method).toUpperCase() !== 'GET'
                || target.origin !== window.location.origin || target.pathname !== '/admin/applies-orders'
                || target.search || target.hash) return false;
            var entries = function (value) {
                var params = new URLSearchParams(value);
                Array.from(params.entries()).forEach(function (entry) {
                    if (!entry[1] || (/^page_/.test(entry[0]) && entry[1] === '1')) params.delete(entry[0]);
                });
                return JSON.stringify(Array.from(params.entries()).sort());
            };
            var current = filters(), query = new URLSearchParams(window.location.search);
            var names = Array.from(new Set(Array.from(current.keys())));
            for (var index = 0; index < names.length; index++) {
                var name = names[index];
                if (query.has(name)) continue;
                var fields = Array.from(form.elements || []).filter(function (field) { return field.name === name; });
                if (fields.length !== 1 || current.getAll(name).length !== 1) return false;
                var field = fields[0], original;
                if (field.tagName === 'SELECT') {
                    if (field.multiple) return false;
                    var defaults = Array.from(field.options).filter(function (option) { return option.defaultSelected; });
                    var option = defaults[defaults.length - 1];
                    if (!option && !(Number(field.size) > 1)) option = Array.from(field.options).find(function (candidate) {
                        var group = candidate.parentElement;
                        return !candidate.disabled && !(group && group.tagName === 'OPTGROUP' && group.disabled);
                    });
                    original = option ? option.value : '';
                } else original = field.defaultValue;
                if (typeof original !== 'string' || current.get(name) !== original) return false;
                current.delete(name);
            }
            return entries(current) === entries(query);
        } catch (_) { return false; }
    });
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () {
        disposed = true; generation++;
        clearInterval(interval); clearTimeout(searchTimer); clearTimeout(messageTimer); clearTimeout(reconcileTimer);
        if (activeFeed) activeFeed.abort();
        if (resize) resize.disconnect();
        if (window.jQuery) window.jQuery(form).off('.orderBoard');
        if (window.reloadOrderSections === reloadBoard) window.reloadOrderSections = previousReload;
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { sizeBoard(); refresh(false); interval = setInterval(function () { refresh(false); }, 10000); }
    });
}());
