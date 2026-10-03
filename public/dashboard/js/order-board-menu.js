(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var panel = document.querySelector('#branch-menu');
    var form = document.querySelector('#ob-filters');
    var select = form && form.querySelector('[name="branch"]');
    if (!panel || !select) return;
    var labels = JSON.parse(document.querySelector('#ob-translations').textContent);
    var list = panel.querySelector('[data-menu-items]');
    var message = panel.querySelector('[data-menu-message]');
    var search = panel.querySelector('input[type="search"]');
    var toggle = document.querySelector('[data-menu-toggle]');
    var backdrop = document.querySelector('[data-menu-backdrop]');
    var previous = panel.querySelector('[data-menu-page="previous"]');
    var next = panel.querySelector('[data-menu-page="next"]');
    var branch = '';
    var page = 1;
    var generation = 0;
    var controller = null;
    var timer;
    var items = new Map();
    var canToggle = false;
    var writing = false;
    var queuedPage = null;
    var disposed = false;

    function text(key) { return labels[key] || key; }
    function node(tag, className, value) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (value !== undefined) element.textContent = value;
        return element;
    }
    function notify(value, success) {
        if (disposed) return;
        message.textContent = value || '';
        message.hidden = !value;
        message.classList.toggle('is-success', !!success);
    }
    function show(open) {
        panel.hidden = !open || !branch;
        toggle.hidden = !branch;
        toggle.setAttribute('aria-expanded', String(!panel.hidden));
        backdrop.hidden = panel.hidden || window.innerWidth >= 992;
    }
    function money(value) {
        if (value === null || value === undefined) return '';
        return Number.isFinite(Number(value)) ? Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 }) : String(value);
    }
    function itemNode(item) {
        var card = node('article', 'ob-menu-item');
        card.dataset.menuProduct = item.id;
        if (item.image_url) {
            var image = node('img');
            image.src = item.image_url;
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', function () { image.remove(); });
            card.appendChild(image);
        }
        var copy = node('div', 'ob-menu-item-copy');
        copy.appendChild(node('strong', 'ob-menu-item-name', item.name));
        if (item.price !== null && item.price !== undefined) {
            var price = node('span', 'ob-menu-item-price');
            price.appendChild(node('bdi', '', money(item.price)));
            price.appendChild(document.createTextNode(' ' + text('currency') + (item.unit ? ' · ' + item.unit : '')));
            copy.appendChild(price);
        }
        if (item.options && item.options.length) {
            var options = node('span', 'ob-menu-item-price', item.options.map(function (option) {
                return option.label + (option.price === null ? '' : ': ' + money(option.price));
            }).join(' · '));
            copy.appendChild(options);
        }
        card.appendChild(copy);
        var status = node('span', 'ob-menu-item-status', text(item.available ? 'menu_available' : 'menu_unavailable'));
        status.classList.toggle('is-unavailable', !item.available);
        card.appendChild(status);
        if (canToggle) {
            var button = node('button', 'ob-menu-item-toggle', text(item.available ? 'menu_disable' : 'menu_enable'));
            button.type = 'button';
            button.dataset.menuAvailability = item.id;
            button.classList.toggle('is-open', !item.available);
            button.setAttribute('aria-label', button.textContent + ' ' + item.name);
            card.appendChild(button);
        }
        return card;
    }
    async function read(response, fallback) {
        var payload;
        try { payload = await response.json(); } catch (_) { payload = null; }
        if (!response.ok || !payload || payload.success === false) {
            var error = new Error(response.status === 401 || response.status === 419 ? text('session_expired')
                : response.status >= 500 || !payload ? text(fallback)
                : payload.message || text(fallback));
            error.status = response.status;
            throw error;
        }
        return payload;
    }
    async function load(requestedPage) {
        if (disposed || !branch) return;
        if (writing) { queuedPage = requestedPage || 1; return; }
        if (controller) controller.abort();
        var current = ++generation;
        var requestedBranch = branch;
        controller = new AbortController();
        var signal = controller.signal;
        var endpoint = new URL(panel.dataset.menuUrl, window.location.href);
        endpoint.searchParams.set('branch', requestedBranch);
        endpoint.searchParams.set('search', search.value.trim());
        endpoint.searchParams.set('page', requestedPage || 1);
        list.setAttribute('aria-busy', 'true');
        notify(text('menu_loading'));
        try {
            var response = await fetch(endpoint.toString(), { credentials: 'same-origin', cache: 'no-store',
                signal: signal, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            var payload = await read(response, 'menu_error');
            if (disposed || current !== generation || branch !== requestedBranch) return;
            if (!Array.isArray(payload.items) || !payload.branch || !payload.pagination) throw new Error(text('menu_error'));
            canToggle = !!payload.can_toggle;
            items.clear();
            list.replaceChildren();
            payload.items.forEach(function (item) {
                items.set(String(item.id), item);
                list.appendChild(itemNode(item));
            });
            if (!payload.items.length) list.appendChild(node('p', 'ob-menu-empty', payload.message || text('menu_empty')));
            panel.querySelector('[data-menu-branch]').textContent = payload.branch.label;
            page = payload.pagination.page;
            panel.querySelector('[data-menu-count]').textContent = page + ' / ' + payload.pagination.last_page + ' · ' + payload.pagination.total;
            previous.disabled = !payload.pagination.previous_url;
            next.disabled = !payload.pagination.next_url;
            notify(payload.message || (!canToggle && payload.ready ? text('menu_readonly') : ''));
        } catch (error) {
            if (!disposed && error.name !== 'AbortError' && current === generation) notify(error.message || text('menu_error'));
        } finally {
            if (current === generation) {
                controller = null;
                list.setAttribute('aria-busy', 'false');
            }
        }
    }
    function selected() {
        var value = select.value;
        if (value === branch) return;
        if (controller) controller.abort();
        generation++;
        branch = value;
        search.value = '';
        page = 1;
        items.clear();
        list.replaceChildren();
        previous.disabled = next.disabled = true;
        panel.querySelector('[data-menu-count]').textContent = '';
        panel.querySelector('[data-menu-branch]').textContent = select.selectedOptions[0] ? select.selectedOptions[0].textContent : '';
        notify('');
        show(!!branch);
        if (branch) load(1);
    }
    list.addEventListener('click', async function (event) {
        var button = event.target.closest('[data-menu-availability]');
        if (!button || button.disabled || writing) return;
        var item = items.get(button.dataset.menuAvailability);
        if (!item || !canToggle || !branch) return;
        var requestedBranch = branch;
        var parts = branch.split(':');
        var endpoint = panel.dataset.menuAction + '/' + encodeURIComponent(parts[0]) + '/' + encodeURIComponent(parts[1])
            + '/products/' + encodeURIComponent(item.id) + '/availability';
        writing = true;
        if (controller) { controller.abort(); controller = null; generation++; }
        list.setAttribute('aria-busy', 'false');
        list.querySelectorAll('[data-menu-availability]').forEach(function (control) { control.disabled = true; });
        notify(text('saving'));
        try {
            var response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ available: !item.available, expected_available: !!item.available, expected_revision: item.revision }) });
            var payload = await read(response, 'menu_save_error');
            if (disposed || branch !== requestedBranch) return;
            if (!payload.item || payload.item.id !== item.id) throw new Error(text('menu_save_error'));
            // A newer menu fetch may have replaced this card while the write was pending.
            var currentCard = list.querySelector('[data-menu-product="' + item.id + '"]');
            if (currentCard) currentCard.replaceWith(itemNode(payload.item));
            items.set(String(item.id), payload.item);
            notify(text('menu_saved'), true);
        } catch (error) {
            if (disposed || branch !== requestedBranch) return;
            if (error.status === 409) {
                queuedPage = page;
                notify(text('menu_stale'));
            } else notify(error.message || text('menu_save_error'));
        } finally {
            writing = false;
            list.querySelectorAll('[data-menu-availability]').forEach(function (control) { control.disabled = false; });
            if (!disposed && queuedPage !== null) { var requestedPage = queuedPage; queuedPage = null; load(requestedPage); }
        }
    });
    if (window.jQuery) window.jQuery(select).on('change.orderBoardMenu', selected);
    else select.addEventListener('change', selected);
    document.querySelector('[data-board-reset]').addEventListener('click', selected);
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); });
    panel.querySelector('[data-menu-refresh]').addEventListener('click', function () { load(page); });
    previous.addEventListener('click', function () { if (!previous.disabled) load(page - 1); });
    next.addEventListener('click', function () { if (!next.disabled) load(page + 1); });
    toggle.addEventListener('click', function () { show(panel.hidden); });
    panel.querySelector('[data-menu-close]').addEventListener('click', function () { show(false); toggle.focus(); });
    backdrop.addEventListener('click', function () { show(false); toggle.focus(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !panel.hidden) { show(false); toggle.focus(); } });
    window.addEventListener('resize', function () { backdrop.hidden = panel.hidden || window.innerWidth >= 992; });
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () {
        disposed = true; generation++; queuedPage = null;
        clearTimeout(timer); if (controller) controller.abort();
        if (window.jQuery) window.jQuery(select).off('.orderBoardMenu');
    });
    selected();
})();
