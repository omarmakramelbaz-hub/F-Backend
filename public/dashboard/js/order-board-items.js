(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var board = document.getElementById('order-board'), dialog = document.getElementById('ob-items-editor');
    if (!board || !dialog) return;
    var form = dialog.querySelector('[data-items-form]'), linesHost = dialog.querySelector('[data-items-lines]');
    var content = dialog.querySelector('[data-items-content]'), message = dialog.querySelector('[data-items-message]');
    var save = dialog.querySelector('[data-items-save]'), reload = dialog.querySelector('[data-items-reload]');
    var search = dialog.querySelector('[data-items-search]'), productsHost = dialog.querySelector('[data-items-products]');
    var categoriesHost = dialog.querySelector('[data-items-categories]'), optionModal = dialog.querySelector('[data-items-option-modal]');
    var optionList = dialog.querySelector('[data-items-option-list]');
    var copy = JSON.parse(document.getElementById('ob-translations').textContent);
    var state = null, lines = [], catalog = [], categories = [], category = '', endpoint = '', opener = null, optionOpener = null;
    var loading = false, saving = false, dirty = false, blocked = false, disposed = false, generation = 0, active = null;
    var quantityMode = 'piece', quantityInput = '1', replaceQuantity = true;
    dialog.dir = /[\u0600-\u06ff]/.test(t('edit_items')) ? 'rtl' : 'ltr';
    function t(key) { return copy[key] || key; }
    function node(tag, className, text) {
        var el = document.createElement(tag); if (className) el.className = className;
        if (text !== undefined) el.textContent = String(text); return el;
    }
    function scaled(value, places) {
        var parts = String(value == null ? '' : value).match(new RegExp('^(\\d{1,10})(?:\\.(\\d{1,' + places + '}))?$'));
        return parts ? BigInt(parts[1]) * (10n ** BigInt(places)) + BigInt((parts[2] || '').padEnd(places, '0')) : null;
    }
    function fixed(value, places) {
        var base = 10n ** BigInt(places); return String(value / base) + '.' + String(value % base).padStart(places, '0');
    }
    function money(value) { var amount = scaled(value, 2); return amount === null ? '0.00' : fixed(amount, 2); }
    function weightFactor(line) { return Number(line.weight_per_unit) > 0 ? Number(line.weight_per_unit) : 0; }
    function unitLabel(unit) { return unit === 'kg' ? t('weight_kg') : unit === 'piece' ? t('piece_unit') : unit; }
    function locked() { return loading || saving || blocked; }
    function showMessage(text, isLoading) {
        message.textContent = text || ''; message.hidden = !text; message.classList.toggle('is-loading', !!isLoading);
    }
    function headers() {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        return {'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf ? csrf.content : ''};
    }
    function sameOrigin(url) {
        var parsed = new URL(url, window.location.href);
        if (parsed.origin !== window.location.origin) throw new Error(t('forbidden')); return parsed.toString();
    }
    async function json(response) {
        var payload; try { payload = await response.json(); } catch (_) { payload = null; }
        var valid = payload && typeof payload === 'object' && !Array.isArray(payload) && payload.success === true;
        if (!response.ok || !valid) {
            var text = response.status === 401 || response.status === 419 ? t('session_expired') : response.status === 403 ? t('forbidden') :
                response.status === 409 ? t('items_stale') : response.status >= 500 ? t('error') : payload && payload.message || t('error');
            if (response.status === 422 && payload && payload.errors) text = Object.values(payload.errors).map(function (v) { return Array.isArray(v) ? v[0] : v; }).join(' ');
            var error = new Error(text); error.status = response.ok && !valid ? 0 : response.status; throw error;
        }
        return payload;
    }
    function readOrder(controller) {
        // Bound both response headers and JSON: aborting fetch alone may leave a body read pending.
        return new Promise(function (resolve, reject) {
            var settled = false, url = endpoint;
            var timer = setTimeout(function () {
                finish(new Error(t('items_load_failed')));
                controller.abort();
            }, 15000);
            function finish(error, payload) {
                if (settled) return;
                settled = true; clearTimeout(timer);
                controller.signal.removeEventListener('abort', cancel);
                if (error) reject(error); else resolve(payload);
            }
            function cancel() {
                var error = new Error(t('items_load_failed')); error.name = 'AbortError'; finish(error);
            }
            controller.signal.addEventListener('abort', cancel, {once:true});
            Promise.resolve().then(function () {
                if (controller.signal.aborted) throw new DOMException('Aborted', 'AbortError');
                return fetch(url,{credentials:'same-origin',cache:'no-store',headers:headers(),signal:controller.signal});
            }).then(json).then(function (payload) { finish(null, payload); }, function (error) { finish(error); });
        });
    }
    function quantityValid(line) {
        var amount = scaled(line.quantity, 3);
        return !line.input_invalid && amount !== null && amount > 0n && amount <= 1000000n && !(Number(line.quantity_step) === 1 && amount % 1000n !== 0n);
    }
    function lineCents(line) {
        var amount = scaled(line.quantity, 3), price = scaled(line.unit_price, 2);
        if (amount === null || price === null) return 0n;
        var original = scaled(line.original_quantity, 3), total = scaled(line.original_total, 2);
        return original !== null && amount === original && total !== null ? total : (price * amount + 500n) / 1000n;
    }
    function updateSummary() {
        var subtotal = lines.reduce(function (sum, line) { return sum + lineCents(line); }, 0n);
        var pricing = state && state.pricing || {};
        var delivery = scaled(pricing.delivery == null ? state && state.delivery || '0' : pricing.delivery, 2) || 0n;
        var taxRate = scaled(pricing.tax_rate || '0', 2) || 0n, serviceRate = scaled(pricing.service_rate || '0', 2) || 0n;
        var tax = !dirty && state && state.tax !== undefined ? scaled(state.tax,2) || 0n : (subtotal * taxRate + 5000n) / 10000n;
        var service = !dirty && state && state.service !== undefined ? scaled(state.service,2) || 0n : (subtotal * serviceRate + 5000n) / 10000n;
        dialog.querySelector('[data-items-subtotal]').textContent = fixed(subtotal, 2);
        [['delivery',delivery],['tax',tax],['service',service],['grand',subtotal + delivery + tax + service]].forEach(function (pair) {
            dialog.querySelector('[data-items-total="' + pair[0] + '"]').textContent = fixed(pair[1], 2);
        });
        dialog.querySelector('[data-items-count]').textContent = lines.length;
        dialog.querySelector('[data-items-empty]').hidden = lines.length > 0;
        save.disabled = locked() || !dirty || !lines.length || lines.some(function (line) { return !quantityValid(line); });
        linesHost.querySelectorAll('[data-line-index]').forEach(function (row) { row.querySelector('[data-line-total]').textContent = fixed(lineCents(lines[Number(row.dataset.lineIndex)]), 2); });
    }
    function safeImage(url) {
        if (!url) return '';
        try { var parsed = new URL(url, window.location.href); return ['http:','https:'].includes(parsed.protocol) ? parsed.toString() : ''; } catch (_) { return ''; }
    }
    function imageNode(url, className) {
        var src = safeImage(url);
        function placeholder() { var el = node('span',className + ' tp-product-no-image'); el.appendChild(node('i','fas fa-fish')); return el; }
        if (!src) return placeholder();
        var image = node('img', className); image.src = src; image.alt = ''; image.loading = 'lazy';
        image.addEventListener('error', function () { image.replaceWith(placeholder()); }, {once:true});
        return image;
    }
    function productById(id) { return catalog.find(function (p) { return String(p.product_id || p.id) === String(id); }); }
    function renderLines() {
        linesHost.replaceChildren();
        lines.forEach(function (line, index) {
            var row = node('tr', 'obi-line'); row.dataset.lineIndex = index;
            var productCell = node('td'), productCopy = node('div', 'tp-line-product');
            var product = productById(line.product_id);
            if (product && safeImage(product.image_url)) productCopy.appendChild(imageNode(product.image_url, ''));
            var name = node('span'); name.appendChild(node('strong', 'tp-line-name', line.name));
            if (line.option_label) name.appendChild(node('small', 'tp-line-option', line.option_label));
            productCopy.appendChild(name); productCell.appendChild(productCopy);
            var quantityCell = node('td'), input = node('input');
            var factor = weightFactor(line); input.type = 'number'; input.min = factor ? '0.001' : line.quantity_step || '0.001';
            input.max = String(1000 * (factor || 1)); input.step = factor ? '0.001' : line.quantity_step || '0.001';
            input.value = factor ? (Number(line.quantity) * factor).toFixed(3) : line.quantity;
            input.dataset.lineQuantity = ''; input.setAttribute('aria-label', (factor ? t('actual_weight') : t('item_quantity')) + ': ' + line.name);
            quantityCell.append(input, node('small', 'tp-line-unit', factor ? t('weight_kg') : unitLabel(line.unit || 'piece')));
            var priceCell = node('td'); priceCell.appendChild(node('bdi', '', money(line.unit_price)));
            var amountCell = node('td', 'tp-line-total'), amount = node('bdi'); amount.dataset.lineTotal = ''; amount.textContent = fixed(lineCents(line), 2); amountCell.appendChild(amount);
            var removeCell = node('td'), remove = node('button', 'tp-line-remove'); remove.type = 'button'; remove.dataset.lineRemove = '';
            remove.appendChild(node('i', 'far fa-trash-alt')); remove.setAttribute('aria-label', t('remove_item') + ': ' + line.name); remove.title = t('remove_item');
            removeCell.appendChild(remove); row.append(productCell, quantityCell, priceCell, amountCell, removeCell); linesHost.appendChild(row);
        });
        updateSummary(); lockControls();
    }
    function productOptions(item) {
        return [{id:'',label:t('base_option'),price:item.price,feature_id:0,product_clean:null,weight_per_unit:item.weight_per_unit}].concat(item.options || []);
    }
    function renderCategories() {
        categoriesHost.replaceChildren();
        [{id:'',name:t('all_categories')}].concat(categories).forEach(function (item) {
            var button = node('button', '', item.name || item.label); button.type = 'button'; button.dataset.itemsCategory = item.id;
            var selected = String(item.id) === category; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); categoriesHost.appendChild(button);
        });
    }
    function renderCatalog() {
        var term = search.value.trim().toLocaleLowerCase(); productsHost.replaceChildren();
        catalog.filter(function (item) { return (!category || String(item.category_id) === category) && (!term || item.name.toLocaleLowerCase().includes(term)); }).forEach(function (item) {
            var card = node('article', 'tp-product-card'), button = node('button', 'tp-product'); button.type = 'button';
            button.dataset.itemsProductId = item.product_id || item.id; button.dataset.itemsCatalogControl = '';
            button.disabled = item.available === false || locked(); button.setAttribute('aria-label', item.name + ' · ' + money(item.price) + ' ' + t('currency'));
            if (item.available === false) button.dataset.unavailable = '1';
            button.appendChild(imageNode(item.image_url, 'tp-product-image'));
            var details = node('span', 'tp-product-copy'); details.appendChild(node('strong', 'tp-product-name', item.name));
            var price = node('span', 'tp-product-price'); price.append(node('bdi', '', money(item.price)), document.createTextNode(' ' + t('currency'))); details.appendChild(price);
            var label = item.available === false ? t('product_unavailable') : item.unit ? unitLabel(item.unit) : t('flexible_unit');
            var badge = node('span', 'tp-product-unit', label);
            if (item.stock) {
                badge.textContent = item.stock.label || t('stock_balance') + ': ' + item.stock.quantity + ' ' + (item.stock.unit_label || unitLabel(item.stock.unit || ''));
                badge.classList.add('obi-stock'); badge.style.color = item.stock.negative || Number(item.stock.quantity) <= 0 ? '#b42318' : item.stock.tracked === false ? '#64748b' : '#158346';
            }
            badge.classList.toggle('is-unavailable', item.available === false); badge.classList.toggle('is-weight', item.quantity_mode === 'weight' || item.unit === 'kg'); details.appendChild(badge);
            if (item.options && item.options.length) details.appendChild(node('small', 'tp-product-base', t('base_option')));
            button.appendChild(details); card.appendChild(button);
            if (item.options && item.options.length) {
                var options = node('button', 'tp-product-options', t('product_options')); options.type = 'button'; options.dataset.itemsOptions = item.product_id || item.id;
                options.dataset.itemsCatalogControl = ''; options.disabled = button.disabled; options.setAttribute('aria-label', t('product_options') + ': ' + item.name); card.appendChild(options);
            }
            productsHost.appendChild(card);
        });
        dialog.querySelector('[data-items-no-products]').hidden = !!productsHost.children.length;
    }
    function showQuantity() {
        dialog.querySelector('[data-items-quantity] bdi').textContent = quantityInput;
        dialog.querySelector('[data-items-quantity-title]').textContent = t(quantityMode === 'weight' ? 'current_weight' : 'current_quantity');
        dialog.querySelector('[data-items-quantity-unit]').textContent = t(quantityMode === 'weight' ? 'weight_kg' : 'piece_unit');
        dialog.querySelectorAll('[data-items-unit]').forEach(function (button) { button.setAttribute('aria-pressed', String(button.dataset.itemsUnit === quantityMode)); });
        var dot = dialog.querySelector('[data-items-key="."]'); dot.disabled = locked() || quantityMode !== 'weight';
    }
    function lockControls() {
        dialog.querySelectorAll('input,button').forEach(function (el) {
            if (el === save) return;
            var unavailable = el.matches('[data-items-catalog-control]') && productById(el.dataset.itemsProductId || el.dataset.itemsOptions);
            el.disabled = saving || (!el.matches('[data-items-close],[data-items-reload],[data-items-options-close]') && locked()) || !!(unavailable && unavailable.available === false);
        });
        if (content.hidden) save.disabled = true;
        else { showQuantity(); updateSummary(); }
    }
    function key(value) {
        if (locked()) return;
        if (value === 'confirm') {
            var amount = scaled(quantityInput, 3);
            if (amount === null || amount <= 0n || amount > 1000000n || quantityMode === 'piece' && amount % 1000n !== 0n) showMessage(t('items_invalid'));
            else { replaceQuantity = true; showMessage(t('quantity_confirmed')); }
            return;
        }
        if (value === 'reset' || value === 'clear') quantityInput = quantityMode === 'piece' ? '1' : '0';
        else if (value === 'backspace') quantityInput = quantityInput.slice(0, -1) || '0';
        else if (value === '.') { if (quantityMode !== 'weight') return; if (replaceQuantity) quantityInput = '0'; if (!quantityInput.includes('.')) quantityInput += '.'; }
        else if (/^\d$/.test(value)) {
            if (replaceQuantity || quantityInput === '0') quantityInput = value;
            else if (quantityInput.length < 8 && (!quantityInput.includes('.') || quantityInput.split('.')[1].length < 3)) quantityInput += value;
        }
        replaceQuantity = value === 'reset' || value === 'clear'; showQuantity();
    }
    function closeOptions() { optionModal.hidden = true; if (optionOpener && optionOpener.isConnected) optionOpener.focus(); }
    function addProduct(item, variant) {
        if (!item || item.available === false || locked()) return;
        variant = variant || productOptions(item)[0];
        var factor = weightFactor(variant) || weightFactor(item);
        var mode = factor ? 'weight' : item.quantity_mode === 'piece' || item.unit === 'piece' ? 'piece' : item.quantity_mode === 'weight' || item.unit === 'kg' ? 'weight' : quantityMode;
        var entered = mode === quantityMode ? quantityInput : mode === 'weight' ? (factor || 1).toFixed(3) : '1';
        var nativeQuantity = mode === 'weight' ? (Number(entered) / (factor || 1)).toFixed(3) : entered;
        var candidate = {line_id:null,product_id:item.product_id || item.id,option_id:variant.id || '',feature_id:variant.feature_id == null ? 0 : variant.feature_id,
            product_clean:variant.product_clean || null,name:item.name,option_label:variant.id ? variant.label : '',quantity:nativeQuantity,
            unit:mode === 'weight' ? 'kg' : item.unit || 'piece',weight_per_unit:mode === 'weight' ? factor || 1 : null,
            quantity_step:mode === 'piece' ? '1' : '0.001',unit_price:variant.price};
        if (!quantityValid(candidate)) { showMessage(t('items_invalid')); return; }
        var existing = lines.find(function (line) { return line.line_id === null && String(line.product_id) === String(candidate.product_id) && line.option_id === candidate.option_id &&
            String(line.unit_price) === String(candidate.unit_price) && weightFactor(line) === weightFactor(candidate); });
        if (existing) {
            var sum = scaled(existing.quantity, 3) + scaled(candidate.quantity, 3), next = Object.assign({}, existing, {quantity:fixed(sum,3)});
            if (!quantityValid(next)) { showMessage(t('items_invalid')); return; } existing.quantity = next.quantity;
        } else {
            if (lines.length >= 100) { showMessage(t('cart_limit')); return; }
            candidate.original_quantity = candidate.quantity; candidate.original_total = fixed(lineCents(candidate), 2); lines.push(candidate);
        }
        dirty = true; replaceQuantity = true; closeOptions(); renderLines(); showMessage(t('item_added'));
    }
    function openOptions(item, button) {
        if (!item || locked() || item.available === false) return;
        optionOpener = button; dialog.querySelector('[data-items-options-title]').textContent = item.name; optionList.replaceChildren();
        productOptions(item).forEach(function (variant) {
            var option = node('button'); option.type = 'button'; option.dataset.itemsVariant = variant.id || '';
            option.append(node('span','',variant.label),node('strong','',money(variant.price) + ' ' + t('currency')));
            option.addEventListener('click', function () { addProduct(item, variant); }); optionList.appendChild(option);
        });
        optionModal.hidden = false; optionList.firstElementChild.focus();
    }
    async function load() {
        if (active) active.abort();
        var controller = new AbortController(), current = ++generation; active = controller;
        try {
            loading = true; blocked = true; content.hidden = true; reload.hidden = true; optionModal.hidden = true; showMessage(t('items_loading'), true); lockControls();
            var payload = await readOrder(controller);
            if (disposed || current !== generation) return;
            if (!payload.order || !Array.isArray(payload.order.items) || !Array.isArray(payload.catalog)) throw new Error(t('items_load_failed'));
            state = payload.order; catalog = payload.catalog; categories = payload.categories || []; category = '';
            lines = state.items.map(function (line) { return Object.assign({},line,{quantity:String(line.quantity),original_quantity:String(line.quantity),original_total:line.line_total}); });
            state.save_url = sameOrigin(payload.save_url || state.save_url || endpoint);
            dialog.querySelector('[data-items-number]').textContent = '#' + String(state.number || '').replace(/^#/,'');
            dialog.querySelector('[data-items-branch]').textContent = payload.branch && (payload.branch.name || payload.branch.label || payload.branch.value) || '';
            dialog.querySelector('[data-items-quantity-hint]').textContent = state.quantity_hint || '';
            blocked = false; dirty = false; search.value = ''; quantityMode = 'piece'; quantityInput = '1'; replaceQuantity = true;
            showMessage(''); content.hidden = false; renderCategories(); renderCatalog(); renderLines();
        } catch (error) {
            if (disposed || current !== generation) return;
            blocked = true; content.hidden = true;
            showMessage(error.status ? error.message : t('items_load_failed')); reload.hidden = false;
        } finally { if (current === generation) { loading = false; active = null; lockControls(); } }
    }
    function close() {
        if (saving) return;
        if (!optionModal.hidden) { closeOptions(); return; }
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
        state = null; lines = []; catalog = []; dirty = false; saving = false;
        dialog.querySelector('[data-items-number]').textContent = ''; dialog.querySelector('[data-items-branch]').textContent = '';
        dialog.showModal(); load();
    }
    board.addEventListener('click',open);
    dialog.querySelectorAll('[data-items-close]').forEach(function (button) { button.addEventListener('click',close); });
    dialog.querySelector('[data-items-options-close]').addEventListener('click',closeOptions);
    dialog.addEventListener('cancel',function (event) { event.preventDefault(); close(); });
    search.addEventListener('input',renderCatalog);
    reload.addEventListener('click',function () { if (!dirty || window.confirm(t('items_reloading'))) load(); });
    categoriesHost.addEventListener('click',function (event) {
        var button = event.target.closest('[data-items-category]'); if (!button || locked()) return;
        category = String(button.dataset.itemsCategory); renderCategories(); renderCatalog();
    });
    productsHost.addEventListener('click',function (event) {
        var optionButton = event.target.closest('[data-items-options]');
        if (optionButton) { openOptions(productById(optionButton.dataset.itemsOptions),optionButton); return; }
        var button = event.target.closest('[data-items-product-id]'); if (button) addProduct(productById(button.dataset.itemsProductId));
    });
    dialog.querySelectorAll('[data-items-unit]').forEach(function (button) {
        button.addEventListener('click',function () { if (locked()) return; quantityMode = button.dataset.itemsUnit; quantityInput = quantityMode === 'piece' ? '1' : '0'; replaceQuantity = true; showQuantity(); });
    });
    dialog.querySelectorAll('[data-items-key]').forEach(function (button) { button.addEventListener('click',function () { key(button.dataset.itemsKey); }); });
    linesHost.addEventListener('input',function (event) {
        if (!event.target.matches('[data-line-quantity]') || locked()) return;
        var line = lines[Number(event.target.closest('[data-line-index]').dataset.lineIndex)], value = event.target.value, factor = weightFactor(line);
        line.input_invalid = !/^\d+(?:\.\d{1,3})?$/.test(value); line.quantity = factor && !line.input_invalid ? (Number(value)/factor).toFixed(3) : value;
        dirty = true; updateSummary();
    });
    linesHost.addEventListener('click',function (event) {
        var remove = event.target.closest('[data-line-remove]'); if (!remove || locked()) return;
        lines.splice(Number(remove.closest('[data-line-index]').dataset.lineIndex),1); dirty = true; renderLines();
    });
    form.addEventListener('submit',async function (event) {
        event.preventDefault(); event.stopImmediatePropagation(); if (locked() || !state) return;
        if (!lines.length) { showMessage(t('items_empty')); return; }
        if (lines.some(function (line) { return !quantityValid(line); })) { showMessage(t('items_invalid')); return; }
        saving = true; dialog.querySelector('[data-items-save-label]').textContent = t('saving'); showMessage(''); lockControls();
        try {
            var h = headers(); h['Content-Type'] = 'application/json';
            var body = {expected_revision:state.revision || state.revision_token,items:lines.map(function (line) {
                return {line_id:line.line_id,product_id:line.product_id,option_id:line.option_id || '',feature_id:line.feature_id == null ? null : line.feature_id,
                    product_clean:line.product_clean || null,quantity:String(line.quantity)};
            })};
            var payload = await json(await fetch(state.save_url,{method:'POST',credentials:'same-origin',headers:h,body:JSON.stringify(body)}));
            if (disposed) return;
            if (payload.card && typeof payload.card.html === 'string' && payload.card.key === state.key) {
                var previous = Array.from(board.querySelectorAll('[data-order-key]')).find(function (el) { return el.dataset.orderKey === state.key; });
                var template = document.createElement('template'); template.innerHTML = payload.card.html.trim(); var next = template.content.firstElementChild;
                if (previous && next && template.content.childElementCount === 1 && next.dataset.orderKey === state.key) previous.replaceWith(next);
            }
            dirty = false; saving = false; dialog.close(); if (window.reloadOrderSections) window.reloadOrderSections();
        } catch (error) {
            if (disposed) return;
            blocked = !error.status || error.status === 409 || error.status >= 500;
            showMessage(blocked ? error.status === 409 ? t('items_stale') : t('items_uncertain') : error.message); reload.hidden = !blocked;
        } finally { saving = false; dialog.querySelector('[data-items-save-label]').textContent = t('save_items'); if (!disposed) lockControls(); }
    },true);
    function cleanup() { disposed = true; generation++; if (active) active.abort(); board.removeEventListener('click',open); if (dialog.open) dialog.close(); }
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(cleanup);
}());
