(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var board = document.getElementById('order-board');
    var dialog = document.getElementById('ob-items-editor');
    if (!board || !dialog) return;
    var form = dialog.querySelector('[data-items-form]');
    var linesHost = dialog.querySelector('[data-items-lines]');
    var content = dialog.querySelector('[data-items-content]');
    var message = dialog.querySelector('[data-items-message]');
    var save = dialog.querySelector('[data-items-save]');
    var reload = dialog.querySelector('[data-items-reload]');
    var product = dialog.querySelector('[data-items-product]');
    var option = dialog.querySelector('[data-items-option]');
    var search = dialog.querySelector('[data-items-search]');
    var add = dialog.querySelector('[data-items-add]');
    var copy = JSON.parse(document.getElementById('ob-translations').textContent);
    var state = null, lines = [], catalog = [], endpoint = '', opener = null;
    var loading = false, saving = false, dirty = false, blocked = false, disposed = false, generation = 0, active = null;
    function t(key) { return copy[key] || key; }
    function money(value) { return (Number(value) || 0).toFixed(2); }
    function showMessage(text, isLoading) {
        message.textContent = text || ''; message.hidden = !text;
        message.classList.toggle('is-loading', !!isLoading);
    }
    function headers() {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        return {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN':csrf ? csrf.content : ''};
    }
    function sameOrigin(url) {
        var parsed = new URL(url, window.location.href);
        if (parsed.origin !== window.location.origin) throw new Error(t('forbidden'));
        return parsed.toString();
    }
    async function json(response) {
        var payload;
        try { payload = await response.json(); } catch (_) { payload = {}; }
        if (!response.ok || !payload || payload.success === false) {
            var text = response.status === 401 || response.status === 419 ? t('session_expired') :
                response.status === 403 ? t('forbidden') : response.status === 409 ? t('items_stale') :
                response.status >= 500 ? t('error') : payload.message || t('error');
            if (response.status === 422 && payload.errors) text = Object.values(payload.errors).map(function (v) { return Array.isArray(v) ? v[0] : v; }).join(' ');
            var error = new Error(text); error.status = response.status; throw error;
        }
        return payload;
    }
    function weightFactor(line) { return Number(line.weight_per_unit) > 0 ? Number(line.weight_per_unit) : 0; }
    function unitLabel(unit) { return unit === 'kg' ? t('weight_kg') : unit === 'piece' ? t('piece_unit') : unit; }
    function quantityValid(line) {
        if (line.input_invalid) return false;
        var value = String(line.quantity);
        return /^\d+(?:\.\d{1,3})?$/.test(value) && Number(value) > 0 && Number(value) <= 1000 &&
            !(Number(line.quantity_step) === 1 && Number(value) % 1 !== 0);
    }
    function lineTotal(line) {
        if (Number(line.quantity) === Number(line.original_quantity)) return Number(line.original_total);
        return Math.round(Number(line.quantity) * Number(line.unit_price) * 100) / 100;
    }
    function updateSummary() {
        add.disabled = loading || saving || blocked || !selectedProduct();
        dialog.querySelector('[data-items-subtotal]').textContent = money(lines.reduce(function (sum, line) { return sum + lineTotal(line); }, 0));
        save.disabled = loading || saving || blocked || !dirty || !lines.length || lines.some(function (line) { return !quantityValid(line); });
        linesHost.querySelectorAll('[data-line-index]').forEach(function (row) {
            row.querySelector('[data-line-total]').textContent = money(lineTotal(lines[Number(row.dataset.lineIndex)]));
        });
    }
    function renderLines() {
        linesHost.replaceChildren();
        lines.forEach(function (line, index) {
            var row = document.createElement('div'); row.className = 'obi-line'; row.dataset.lineIndex = index;
            var info = document.createElement('div'); info.className = 'obi-line-info';
            var title = document.createElement('h3'); title.textContent = line.name;
            var subtitle = document.createElement('small');
            subtitle.textContent = [line.option_label, t('unit_price') + ': ' + money(line.unit_price) + ' ' + t('currency')].filter(Boolean).join(' · ');
            info.append(title, subtitle);
            var label = document.createElement('label'); label.className = 'obi-quantity';
            var factor = weightFactor(line);
            var caption = document.createElement('span'); caption.textContent = factor ? t('actual_weight') + ' (' + t('weight_kg') + ')' : t('item_quantity') + (line.unit ? ' (' + unitLabel(line.unit) + ')' : '');
            var input = document.createElement('input'); input.type = 'number'; input.min = factor ? '0.001' : line.quantity_step || '0.001'; input.max = String(1000 * (factor || 1)); input.step = factor ? '0.001' : line.quantity_step || '0.001'; input.value = factor ? (Number(line.quantity) * factor).toFixed(3) : line.quantity;
            input.setAttribute('aria-label', t('item_quantity') + ': ' + line.name); input.dataset.lineQuantity = '';
            label.append(caption, input);
            var amount = document.createElement('bdi'); amount.className = 'obi-line-total'; amount.dataset.lineTotal = ''; amount.textContent = money(lineTotal(line));
            var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'obi-remove'; remove.dataset.lineRemove = ''; remove.textContent = '×';
            remove.setAttribute('aria-label', t('remove_item') + ': ' + line.name); remove.title = t('remove_item');
            row.append(info, label, amount, remove); linesHost.append(row);
        });
        updateSummary();
    }
    function selectedProduct() { return catalog.find(function (p) { return String(p.product_id || p.id) === product.value; }); }
    function productOptions(item) {
        return [{id:'', label:t('base_option'), price:item.price, feature_id:0, product_clean:null, weight_per_unit:item.weight_per_unit}].concat(item.options || []);
    }
    function renderOptions() {
        option.replaceChildren();
        var item = selectedProduct();
        if (item) productOptions(item).forEach(function (variant) {
            var el = document.createElement('option'); el.value = variant.id || ''; el.textContent = variant.label + ' · ' + money(variant.price); option.append(el);
        });
        option.disabled = !item; add.disabled = !item || loading || saving || blocked;
    }
    function renderCatalog() {
        var previous = product.value, term = search.value.trim().toLocaleLowerCase();
        product.replaceChildren();
        catalog.filter(function (item) { return (!term || item.name.toLocaleLowerCase().includes(term)) && item.available !== false; }).forEach(function (item) {
            var el = document.createElement('option'); el.value = item.product_id || item.id; el.textContent = item.name; product.append(el);
        });
        if (Array.from(product.options).some(function (v) { return v.value === previous; })) product.value = previous;
        product.disabled = !product.options.length;
        dialog.querySelector('[data-items-no-products]').hidden = !!product.options.length;
        renderOptions();
    }
    async function load() {
        if (active) active.abort();
        var controller = new AbortController(), current = ++generation; active = controller;
        loading = true; blocked = true; content.hidden = true; reload.hidden = true; save.disabled = true;
        showMessage(t('items_loading'), true);
        try {
            var payload = await json(await fetch(endpoint, {credentials:'same-origin',cache:'no-store',headers:headers(),signal:controller.signal}));
            if (disposed || current !== generation) return;
            if (!payload.order || !Array.isArray(payload.order.items) || !Array.isArray(payload.catalog)) throw new Error(t('items_load_failed'));
            state = payload.order; catalog = payload.catalog;
            lines = state.items.map(function (line) {
                return Object.assign({}, line, {quantity:String(line.quantity), original_quantity:String(line.quantity), original_total:line.line_total});
            });
            state.save_url = sameOrigin(payload.save_url || state.save_url || endpoint);
            dialog.querySelector('[data-items-number]').textContent = '#' + String(state.number || '').replace(/^#/, '');
            dialog.querySelector('[data-items-quantity-hint]').textContent = state.quantity_hint || '';
            blocked = false; dirty = false; search.value = ''; showMessage(''); content.hidden = false;
            renderCatalog(); renderLines();
        } catch (error) {
            if (disposed || current !== generation || error.name === 'AbortError') return;
            showMessage(error.message || t('items_load_failed')); reload.hidden = false;
        } finally {
            if (current === generation) { loading = false; active = null; updateSummary(); }
        }
    }
    function close() {
        if (saving) return;
        if (dirty && !window.confirm(t('items_discard'))) return;
        generation++; if (active) active.abort(); active = null;
        dialog.close(); state = null; dirty = false;
        if (opener && opener.isConnected) opener.focus();
        if (window.reloadOrderSections) window.reloadOrderSections();
    }
    function open(event) {
        var button = event.target.closest('[data-edit-items]');
        if (!button || !board.contains(button) || dialog.open) return;
        event.preventDefault(); opener = button;
        try { endpoint = sameOrigin(button.dataset.editItems); } catch (_) { return; }
        state = null; lines = []; dirty = false; saving = false;
        dialog.querySelector('[data-items-number]').textContent = '';
        dialog.showModal(); load();
    }
    board.addEventListener('click', open);
    dialog.querySelectorAll('[data-items-close]').forEach(function (button) { button.addEventListener('click', close); });
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
    search.addEventListener('input', renderCatalog); product.addEventListener('change', renderOptions);
    reload.addEventListener('click', function () { if (!dirty || window.confirm(t('items_reloading'))) load(); });
    linesHost.addEventListener('input', function (event) {
        if (!event.target.matches('[data-line-quantity]')) return;
        var index = Number(event.target.closest('[data-line-index]').dataset.lineIndex);
        var line = lines[index], value = event.target.value, factor = weightFactor(line);
        line.input_invalid = !/^\d+(?:\.\d{1,3})?$/.test(value);
        line.quantity = factor && !line.input_invalid ? (Number(value) / factor).toFixed(3) : value;
        dirty = true; updateSummary();
    });
    linesHost.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-line-remove]'); if (!remove || saving) return;
        lines.splice(Number(remove.closest('[data-line-index]').dataset.lineIndex), 1); dirty = true; renderLines();
    });
    add.addEventListener('click', function () {
        var item = selectedProduct(); if (!item || blocked || saving) return;
        var variant = productOptions(item).find(function (entry) { return String(entry.id || '') === option.value; });
        if (!variant) return;
        lines.push({line_id:null, product_id:item.product_id || item.id, option_id:variant.id || '', feature_id:variant.feature_id || null,
            product_clean:variant.product_clean || null, name:item.name, option_label:variant.label === t('base_option') ? '' : variant.label,
            quantity:'1', unit:item.unit || '', weight_per_unit:variant.weight_per_unit || item.weight_per_unit || null, quantity_step:item.quantity_step || '0.001', unit_price:variant.price,
            original_quantity:'1', original_total:variant.price});
        dirty = true; renderLines();
        linesHost.lastElementChild.querySelector('input').focus();
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        if (saving || loading || blocked || !state) return;
        if (!lines.length) { showMessage(t('items_empty')); return; }
        if (lines.some(function (line) { return !quantityValid(line); })) { showMessage(t('items_invalid')); return; }
        saving = true; save.disabled = true; save.textContent = t('saving'); showMessage('');
        // Freeze the editor while saving; dismissing or resubmitting cannot race a committed write.
        dialog.querySelectorAll('input,select,button').forEach(function (el) { el.disabled = true; });
        try {
            var h = headers(); h['Content-Type'] = 'application/json';
            var body = {expected_revision:state.revision || state.revision_token, items:lines.map(function (line) {
                return {line_id:line.line_id, product_id:line.product_id, option_id:line.option_id || '', feature_id:line.feature_id == null ? null : line.feature_id,
                    product_clean:line.product_clean || null, quantity:String(line.quantity)};
            })};
            var payload = await json(await fetch(state.save_url, {method:'POST',credentials:'same-origin',headers:h,body:JSON.stringify(body)}));
            if (disposed) return;
            if (payload.card && typeof payload.card.html === 'string' && payload.card.key === state.key) {
                var previous = Array.from(board.querySelectorAll('[data-order-key]')).find(function (el) { return el.dataset.orderKey === state.key; });
                var template = document.createElement('template'); template.innerHTML = payload.card.html.trim();
                var next = template.content.firstElementChild;
                if (previous && next && template.content.childElementCount === 1 && next.dataset.orderKey === state.key) previous.replaceWith(next);
            }
            dirty = false; saving = false; dialog.close();
            if (window.reloadOrderSections) window.reloadOrderSections();
        } catch (error) {
            if (disposed) return;
            // A timeout or failed response may follow a committed update. Reload, never blindly retry it.
            blocked = !error.status || error.status === 409 || error.status >= 500;
            showMessage(blocked ? (error.status === 409 ? t('items_stale') : t('items_uncertain')) : error.message);
            reload.hidden = !blocked;
        } finally {
            saving = false; save.textContent = t('save_items');
            if (!disposed) {
                dialog.querySelectorAll('input,select,button').forEach(function (el) { el.disabled = false; });
                add.disabled = !selectedProduct() || blocked; updateSummary();
            }
        }
    }, true);
    function cleanup() {
        disposed = true; generation++; if (active) active.abort();
        board.removeEventListener('click', open); if (dialog.open) dialog.close();
    }
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(cleanup);
}());
