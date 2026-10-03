(function () {
    'use strict';

    var board = document.getElementById('order-board');
    if (!board) return;

    var form = document.getElementById('ob-filters');
    var columns = document.getElementById('all_orders');
    var live = document.getElementById('ob-live');
    var message = document.getElementById('ob-message');
    var copy = JSON.parse(document.getElementById('ob-translations').textContent);
    var pendingAction = false;
    var activeFeed = null;
    var generation = 0;
    var searchTimer = null;
    var messageTimer = null;
    var needsRefresh = false;
    var interval = null;
    var lastSubmitter = null;

    function translated(key) { return copy[key] || key; }

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
        window.history.replaceState(null, '', page.toString());
    }

    function modalIsOpen() {
        return !!document.querySelector('.modal.show, dialog[open]');
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

    function errorText(response, payload) {
        if (response.status === 401 || response.status === 419) return translated('session_expired');
        if (response.status === 403) return translated('forbidden');
        if (response.status === 409) return payload.message || translated('stale');
        return payload.message || translated('error');
    }

    async function readJson(response) {
        var payload;
        try { payload = await response.json(); } catch (_) { payload = {}; }
        if (!response.ok || payload.success === false || payload.status === 'Error') {
            var error = new Error(errorText(response, payload));
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    async function refresh(force) {
        if (pendingAction || (!force && (document.hidden || modalIsOpen()))) {
            needsRefresh = true;
            return;
        }
        if (activeFeed) {
            if (!force) return;
            activeFeed.abort();
        }
        var controller = new AbortController();
        activeFeed = controller;
        var currentGeneration = ++generation;
        var query = filters().toString();
        var endpoint = new URL(board.dataset.feedUrl, window.location.href);
        endpoint.search = query;
        setLive('updating');
        try {
            var response = await fetch(endpoint.toString(), {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            var payload = await readJson(response);
            if (currentGeneration !== generation || query !== filters().toString() || pendingAction) return;
            // A dialog may have opened while the request was in flight; defer DOM changes.
            if (modalIsOpen()) { needsRefresh = true; return; }
            var html = payload.html !== undefined ? payload.html : payload.view;
            if (typeof html !== 'string') throw new Error(translated('loading_failed'));
            syncCards(html);
            syncCounts(payload.counts);
            setLive('updated');
            needsRefresh = false;
            if (message.querySelector('[data-board-retry]').hidden === false) message.hidden = true;
        } catch (error) {
            if (error.name === 'AbortError' || currentGeneration !== generation) return;
            setLive('offline');
            notify(error.message || translated('loading_failed'), false, true);
        } finally {
            if (activeFeed === controller) activeFeed = null;
        }
    }

    // The existing notification handlers call this function after an order event.
    window.reloadOrderSections = function () { refresh(false); };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        // The shared footer replaces all submit-button labels; this AJAX form owns its UI.
        event.stopImmediatePropagation();
        clearTimeout(searchTimer);
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
        searchTimer = setTimeout(function () { resetPages(); rememberFilters(); refresh(true); }, 450);
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
        if (pendingAction) return;
        var submitter = event.submitter || lastSubmitter;
        if (!submitter || submitter.form !== actionForm) return;
        var action = submitter.value;
        if ((action === 'reject' || action === 'cancel') && !window.confirm(translated('confirm_' + action))) return;
        var data = new FormData(actionForm);
        data.set('action', action);
        pendingAction = true;
        if (activeFeed) activeFeed.abort();
        var card = actionForm.closest('[data-order-key]');
        var buttons = Array.from(actionForm.querySelectorAll('button'));
        buttons.forEach(function (button) { button.disabled = true; });
        card.classList.add('is-busy');
        card.setAttribute('aria-busy', 'true');
        notify(translated('saving'), false, false);
        var refreshAfter = false;
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
            notify(payload.message || translated('saved'), true, false);
            refreshAfter = true;
            // Shared notification audio can be unavailable while external SDKs initialize.
            // A sound failure must never report a successfully committed order as failed.
            if (typeof window.stopSound === 'function') {
                try { window.stopSound(); } catch (_) { /* The order has already been saved. */ }
            }
        } catch (error) {
            notify(error.message || translated('error'), false, false);
            refreshAfter = error.status === 409;
        } finally {
            pendingAction = false;
            buttons.forEach(function (button) { button.disabled = false; });
            card.classList.remove('is-busy');
            card.removeAttribute('aria-busy');
            if (refreshAfter || needsRefresh) refresh(true);
        }
    }, true);

    function sizeBoard() {
        var wrapper = board.closest('.order-board-wrapper');
        var footer = document.querySelector('.main-footer');
        wrapper.style.setProperty('--ob-top', Math.max(0, wrapper.getBoundingClientRect().top) + 'px');
        wrapper.style.setProperty('--ob-footer', (footer ? footer.getBoundingClientRect().height : 0) + 'px');
    }
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
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { sizeBoard(); refresh(false); interval = setInterval(function () { refresh(false); }, 10000); }
    });
}());
