(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.querySelector('#takeaway-pos');
    if (!root) return;
    var boot = JSON.parse(document.querySelector('#takeaway-bootstrap').textContent);
    var labels = JSON.parse(document.querySelector('#takeaway-translations').textContent);
    var urls = boot.urls || {};
    var branchSelect = root.querySelector('[data-pos-branch]');
    var branch = branchSelect.value;
    var products = new Map(), cart = [], category = '', catalogPage = 1, pagination = {};
    var policy = {}, permissions = {}, quote = null, quoteGeneration = 0, catalogGeneration = 0;
    var catalogController, quoteController, searchTimer, quoteTimer;
    var quantityMode = 'piece', quantityInput = '1', replaceQuantity = true;
    var paymentMethod = 'cash', loaded = false, disposed = false, selling = false, uncertain = false, sold = false;
    var saleKey = uuid(), frozenPayload = null, lastReceipt = null, register = null, modalFocus, modalController, modalGeneration = 0, registerLocked = false;
    var listeners = [], navigationGuardRegistered = false;
    var message = root.querySelector('[data-pos-message]');
    var modal = root.querySelector('[data-pos-modal]');
    var finish = root.querySelector('[data-pos-finish]');
    var cashInput = root.querySelector('[data-pos-cash-received]');
    var discountInput = root.querySelector('[data-pos-discount]');
    var confirmedInput = root.querySelector('[data-pos-payment-confirmed]');

    function label(key) { return labels[key] || key; }
    function node(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = String(text);
        return element;
    }
    function listen(target, type, callback, options) {
        target.addEventListener(type, callback, options);
        listeners.push(function () { target.removeEventListener(type, callback, options); });
    }
    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        var bytes = new Uint8Array(16);
        if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Secure browser required');
        window.crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }
    function decimalInput(value, places) {
        var text = String(value === undefined ? '' : value).trim().replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/٫/g, '.');
        var pattern = new RegExp('^([0-9]{1,10})(?:\\.([0-9]{1,' + places + '}))?$');
        var parts = text.match(pattern);
        if (!parts) return null;
        return BigInt(parts[1]) * (10n ** BigInt(places)) + BigInt((parts[2] || '').padEnd(places, '0') || '0');
    }
    function minor(value) { return decimalInput(value, 2); }
    function decimal(value, places) {
        var scale = 10n ** BigInt(places), absolute = value < 0n ? -value : value;
        return (value < 0n ? '-' : '') + String(absolute / scale) + '.' + String(absolute % scale).padStart(places, '0');
    }
    function money(value) {
        var signed = String(value).charAt(0) === '-';
        var amount = minor(signed ? String(value).slice(1) : value);
        if (amount === null) return '—';
        if (signed) amount = -amount;
        var fixed = decimal(amount, 2).split('.');
        return fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + fixed[1];
    }
    function quantity(value, mode) {
        var amount = decimalInput(value, 3);
        if (amount === null || amount <= 0n || amount > 999999999n || mode === 'piece' && amount % 1000n !== 0n) return null;
        return { scaled: amount, value: mode === 'piece' ? String(amount / 1000n) : decimal(amount, 3) };
    }
    function notify(text, success, retry) {
        if (disposed) return;
        message.querySelector('span').textContent = text || '';
        message.hidden = !text;
        message.classList.toggle('is-success', !!success);
        message.querySelector('[data-pos-retry]').hidden = !retry;
    }
    function endpoint(url, parameters) {
        var target = new URL(url, location.href);
        if (target.origin !== location.origin) throw new Error(label('forbidden'));
        Object.keys(parameters || {}).forEach(function (key) { if (parameters[key] !== undefined && parameters[key] !== null) target.searchParams.set(key, parameters[key]); });
        return target.href;
    }
    async function read(response, fallback) {
        var payload;
        try { payload = await response.json(); } catch (_) { payload = null; }
        if (!response.ok || !payload || payload.success === false) {
            var error = new Error(response.status === 401 || response.status === 419 ? label('session_expired')
                : response.status === 403 ? label('forbidden') : response.status === 409 ? label('stale')
                : response.status >= 500 || !payload ? label(fallback) : payload.message || label(fallback));
            error.status = response.status; throw error;
        }
        return payload;
    }
    function post(url, payload, signal) {
        return fetch(endpoint(url), { method: 'POST', credentials: 'same-origin', signal: signal,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
    }
    function isLocked() { return selling || uncertain || sold || registerLocked; }
    function lock() {
        root.classList.toggle('is-checkout-locked', isLocked()); root.classList.toggle('is-sold', sold);
        root.querySelectorAll('[data-pos-branch],[data-pos-search],[data-pos-notes],[data-pos-payment-reference],[data-pos-discount],[data-pos-discount-reason],[data-pos-cash-received],[data-pos-payment-confirmed],[data-pos-clear],[data-pos-key],[data-pos-unit],[data-pos-weight-reset],[data-pos-payment],[data-pos-product],[data-pos-remove],[data-pos-category]').forEach(function (control) {
            control.disabled = isLocked() || control.hasAttribute('data-unavailable');
        });
        if (branchSelect.selectize) { if (isLocked()) branchSelect.selectize.disable(); else branchSelect.selectize.enable(); }
        finish.disabled = registerLocked || selling || sold || !cart.length || !permissions.can_checkout || !uncertain && !quote;
        finish.hidden = sold;
        finish.querySelector('span').textContent = label(selling ? 'saving' : uncertain ? 'retry' : 'finish_sale');
    }
    function updateRegister(value, today) {
        if (value) register = value;
        root.querySelector('[data-pos-register-balance]').textContent = register && register.balance !== undefined ? money(register.balance) : '—';
        if (today && today.count !== undefined) {
            var count = root.querySelector('[data-pos-day-count]'); count.hidden = false; count.textContent = today.count;
        }
    }
    function unitText(mode) { return label(mode === 'weight' ? 'kg' : 'piece'); }
    function showQuantity() {
        root.querySelector('[data-pos-quantity] bdi').textContent = quantityInput;
        root.querySelector('[data-pos-quantity-title]').textContent = label(quantityMode === 'weight' ? 'current_weight' : 'current_quantity');
        root.querySelector('[data-pos-quantity-unit]').textContent = unitText(quantityMode);
        root.querySelector('[data-pos-confirm-label]').textContent = label(quantityMode === 'weight' ? 'confirm_weight' : 'confirm_quantity');
        root.querySelectorAll('[data-pos-unit]').forEach(function (button) { button.setAttribute('aria-pressed', String(button.dataset.posUnit === quantityMode)); });
        root.querySelector('[data-pos-key="."]').disabled = quantityMode === 'piece' || isLocked();
    }
    function key(value) {
        if (isLocked()) return;
        if (value === 'confirm') { if (!quantity(quantityInput, quantityMode)) notify(label('invalid_quantity')); else { replaceQuantity = true; notify(label('confirmed'), true); } return; }
        if (value === 'clear') quantityInput = quantityMode === 'piece' ? '1' : '0';
        else if (value === 'backspace') quantityInput = quantityInput.slice(0, -1) || '0';
        else if (value === '.') { if (quantityMode !== 'weight') return; if (replaceQuantity) quantityInput = '0'; if (quantityInput.indexOf('.') === -1) quantityInput += '.'; }
        else {
            if (replaceQuantity || quantityInput === '0') quantityInput = value;
            else if (quantityInput.length < 10 && (quantityInput.indexOf('.') === -1 || quantityInput.split('.')[1].length < 3)) quantityInput += value;
        }
        replaceQuantity = value === 'clear'; showQuantity();
    }
    function itemsPayload() {
        return cart.map(function (line) { var item = { product_id: line.product.id, quantity: line.quantity, quantity_mode: line.mode };
            if (line.option && line.option.id !== undefined && line.option.id !== null && line.option.id !== '') item.option_id = String(line.option.id);
            return item;
        });
    }
    function draft() { var discount = minor(discountInput.value); return { branch: branch, items: itemsPayload(), discount: policy.can_discount ? discount === null ? discountInput.value || '0.00' : decimal(discount, 2) : '0.00', discount_reason: policy.can_discount ? root.querySelector('[data-pos-discount-reason]').value.trim() : '' }; }
    function renderLines() {
        var body = root.querySelector('[data-pos-lines]'); body.replaceChildren();
        cart.forEach(function (line, index) {
            var optionKey = String(line.option && line.option.id || '');
            var snapshot = quote && quote.items && quote.items.find(function (item) { return Number(item.product_id) === Number(line.product.id) && String(item.option_id || '') === optionKey && item.quantity_mode === line.mode; }) || {};
            var row = node('tr'); var productCell = node('td'); var copy = node('div', 'tp-line-product');
            if (line.product.image_url) { var image = node('img'); image.src = line.product.image_url; image.alt = ''; image.addEventListener('error', function () { image.remove(); }, { once: true }); copy.appendChild(image); }
            var title = node('span'); title.appendChild(node('span', 'tp-line-name', snapshot.name || line.product.name));
            if (line.option) title.appendChild(node('small', 'tp-line-option', line.option.label)); copy.appendChild(title); productCell.appendChild(copy); row.appendChild(productCell);
            var amount = node('td'); amount.appendChild(node('bdi', 'tp-line-quantity', line.quantity)); amount.appendChild(node('small', 'tp-line-unit', unitText(line.mode))); row.appendChild(amount);
            var price = snapshot.unit_price || line.option && line.option.price || line.product.unit_price || line.product.price;
            var priceCell = node('td'); priceCell.appendChild(node('bdi', '', money(price))); row.appendChild(priceCell);
            var cents = minor(price), scaled = decimalInput(line.quantity, 3);
            var lineTotal = snapshot.total !== undefined ? snapshot.total : snapshot.line_total !== undefined ? snapshot.line_total : cents !== null && scaled !== null ? decimal((cents * scaled + 500n) / 1000n, 2) : null;
            var total = node('td', 'tp-line-total'); total.appendChild(node('bdi', '', money(lineTotal))); row.appendChild(total);
            var actions = node('td'); var remove = node('button', 'tp-line-remove'); remove.type = 'button'; remove.dataset.posRemove = index; remove.setAttribute('aria-label', label('remove_item') + ' ' + line.product.name); remove.appendChild(node('i', 'far fa-trash-alt')); remove.disabled = isLocked(); actions.appendChild(remove); row.appendChild(actions); body.appendChild(row);
        });
        root.querySelector('[data-pos-empty-invoice]').hidden = cart.length > 0;
    }
    function totals(value) {
        root.querySelectorAll('[data-pos-total]').forEach(function (element) { element.textContent = value && value[element.dataset.posTotal] !== undefined ? money(value[element.dataset.posTotal]) : '—'; });
        root.querySelector('[data-pos-tax-rate]').textContent = value && value.tax_rate !== undefined ? '(' + value.tax_rate + '%)' : policy.tax_rate !== undefined ? '(' + policy.tax_rate + '%)' : '';
        root.querySelector('[data-pos-service-row]').hidden = !value || !minor(value.service);
        showChange(); lock();
    }
    function showChange() {
        var received = minor(cashInput.value), total = quote && minor(quote.total);
        root.querySelector('[data-pos-change]').textContent = paymentMethod === 'cash' && received !== null && total !== null && received >= total ? label('change') + ': ' + money(decimal(received - total, 2)) : '';
    }
    function invalidateQuote() {
        clearTimeout(quoteTimer); quoteGeneration++; if (quoteController) quoteController.abort(); quote = null; totals(null);
        if (cart.length && loaded && !isLocked()) quoteTimer = setTimeout(requestQuote, 180);
    }
    async function requestQuote() {
        if (!cart.length || !branch || disposed || isLocked()) return;
        var generation = ++quoteGeneration; if (quoteController) quoteController.abort(); quoteController = new AbortController();
        var payload = draft(); notify(label('calculating'));
        try {
            var response = await post(urls.quote, payload, quoteController.signal); var result = await read(response, 'quote_error');
            if (disposed || generation !== quoteGeneration) return;
            if (!result.quote_hash || !Array.isArray(result.items) || minor(result.total) === null) throw new Error(label('quote_error'));
            quote = result; totals(result); renderLines(); notify('');
        } catch (error) { if (disposed || generation !== quoteGeneration || error.name === 'AbortError') return; quote = null; totals(null); notify(error.message || label('quote_error'), false, true); }
    }
    function addProduct(product, option) {
        if (isLocked() || !permissions.can_checkout || !product.available) return;
        var amount = quantity(quantityInput, quantityMode); if (!amount) { notify(label('invalid_quantity')); return; }
        var mode = product.quantity_mode;
        if (mode && mode !== 'flexible' && mode !== 'select' && mode !== quantityMode) { notify(label('unit_mismatch')); return; }
        var optionKey = option && option.id !== undefined ? String(option.id) : '';
        var existing = cart.find(function (line) { return line.product.id === product.id && line.mode === quantityMode && String(line.option && line.option.id || '') === optionKey; });
        if (existing) {
            var sum = decimalInput(existing.quantity, 3) + amount.scaled;
            var checked = quantity(decimal(sum, 3), quantityMode); if (!checked) { notify(label('invalid_quantity')); return; } existing.quantity = checked.value;
        } else cart.push({ product: product, option: option || null, mode: quantityMode, quantity: amount.value });
        replaceQuantity = true; renderLines(); invalidateQuote();
    }
    function productNode(product) {
        var button = node('button', 'tp-product'); button.type = 'button'; button.dataset.posProduct = product.id;
        button.disabled = !product.available || !permissions.can_checkout || isLocked(); if (!product.available) button.dataset.unavailable = '1';
        if (product.image_url) { var image = node('img', 'tp-product-image'); image.src = product.image_url; image.alt = ''; image.loading = 'lazy'; image.addEventListener('error', function () { var placeholder = node('span', 'tp-product-image tp-product-no-image'); placeholder.appendChild(node('i', 'fas fa-fish')); image.replaceWith(placeholder); }, { once: true }); button.appendChild(image); }
        else { var placeholder = node('span', 'tp-product-image tp-product-no-image'); placeholder.appendChild(node('i', 'fas fa-fish')); button.appendChild(placeholder); }
        var copy = node('span', 'tp-product-copy'); copy.appendChild(node('strong', 'tp-product-name', product.name));
        var price = node('span', 'tp-product-price'); price.appendChild(node('bdi', '', money(product.unit_price || product.price))); price.appendChild(document.createTextNode(' ' + label('currency'))); copy.appendChild(price);
        var unit = node('span', 'tp-product-unit', !product.available ? label('unavailable') : product.unit || label('flexible_unit'));
        unit.classList.toggle('is-unavailable', !product.available); unit.classList.toggle('is-weight', product.quantity_mode === 'weight' || product.unit === 'kg'); copy.appendChild(unit); button.appendChild(copy); return button;
    }
    function renderPayments(methods) {
        var list = root.querySelector('[data-pos-payments]'); list.replaceChildren();
        var icons = { cash: 'far fa-money-bill-alt', card: 'far fa-credit-card', mobile_wallet: 'fas fa-mobile-alt', other: 'fas fa-ellipsis-h' };
        (methods || ['cash', 'card', 'mobile_wallet', 'other']).forEach(function (method) { var key = typeof method === 'string' ? method : method.value;
            if (!icons[key]) return; var button = node('button', 'tp-payment'); button.type = 'button'; button.dataset.posPayment = key; button.appendChild(node('i', icons[key])); button.appendChild(node('span', '', label(key === 'mobile_wallet' ? 'wallet' : key))); button.setAttribute('aria-pressed', String(key === paymentMethod)); button.classList.toggle('is-active', key === paymentMethod); list.appendChild(button);
        });
        setPayment(paymentMethod);
    }
    function setPayment(method) {
        if (isLocked()) return; paymentMethod = method;
        root.querySelectorAll('[data-pos-payment]').forEach(function (button) { var selected = button.dataset.posPayment === method; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); });
        root.querySelector('[data-pos-cash-wrap]').hidden = method !== 'cash'; root.querySelector('[data-pos-payment-confirm-wrap]').hidden = method === 'cash'; root.querySelector('[data-pos-reference-wrap]').hidden = method === 'cash'; confirmedInput.checked = false; showChange();
    }
    async function loadCatalog(page) {
        if (!branch || disposed || isLocked()) return;
        loaded = false; var generation = ++catalogGeneration; if (catalogController) catalogController.abort(); catalogController = new AbortController();
        var list = root.querySelector('[data-pos-products]'); list.setAttribute('aria-busy', 'true'); root.querySelector('[data-pos-catalog-message]').hidden = false; root.querySelector('[data-pos-catalog-message]').textContent = label('loading'); lock();
        try {
            var response = await fetch(endpoint(urls.catalog, { branch: branch, search: root.querySelector('[data-pos-search]').value.trim(), category_id: category, page: page || 1 }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: catalogController.signal });
            var result = await read(response, 'load_error'); if (disposed || generation !== catalogGeneration) return;
            var items = result.items || result.products; if (!Array.isArray(items) || result.ready === false) throw new Error(result.message || label('load_error'));
            policy = result.policy || {}; permissions = result.permissions || boot.permissions || {}; loaded = true;
            root.querySelector('[data-pos-discount-wrap]').hidden = !policy.can_discount; root.querySelector('[data-pos-total="discount"]').hidden = !!policy.can_discount; if (!policy.can_discount) discountInput.value = '0.00';
            products.clear(); list.replaceChildren(); items.forEach(function (product) { products.set(String(product.id), product); list.appendChild(productNode(product)); });
            var categories = root.querySelector('[data-pos-categories]'); categories.replaceChildren();
            [{ id: '', name: label('all') }].concat(result.categories || []).forEach(function (value) { var button = node('button', '', value.name || value.label); button.type = 'button'; button.dataset.posCategory = value.id; button.classList.toggle('is-active', String(value.id) === category); categories.appendChild(button); });
            pagination = result.pagination || {}; catalogPage = Number(pagination.page || 1); root.querySelector('[data-pos-pagination]').hidden = !pagination.last_page || pagination.last_page < 2; root.querySelector('[data-pos-page-label]').textContent = catalogPage + ' / ' + (pagination.last_page || 1); root.querySelector('[data-pos-page="previous"]').disabled = catalogPage <= 1; root.querySelector('[data-pos-page="next"]').disabled = catalogPage >= Number(pagination.last_page || 1);
            root.querySelector('[data-pos-catalog-message]').hidden = items.length > 0; root.querySelector('[data-pos-catalog-message]').textContent = label('no_products');
            updateRegister(result.register, result.today); renderPayments(result.payment_methods || policy.payment_methods); totals(quote); lock();
            if (cart.length && !quote) invalidateQuote();
        } catch (error) { if (disposed || generation !== catalogGeneration || error.name === 'AbortError') return; list.replaceChildren(); products.clear(); root.querySelector('[data-pos-catalog-message]').hidden = false; root.querySelector('[data-pos-catalog-message]').textContent = error.message || label('load_error'); notify(error.message || label('load_error'), false, true); }
        finally { if (generation === catalogGeneration) list.setAttribute('aria-busy', 'false'); }
    }
    function resetDraft() {
        cart = []; quote = null; frozenPayload = null; uncertain = false; sold = false; saleKey = uuid(); lastReceipt = null; quoteGeneration++; if (quoteController) quoteController.abort(); clearTimeout(quoteTimer);
        root.querySelector('[data-pos-notes]').value = ''; root.querySelector('[data-pos-payment-reference]').value = ''; discountInput.value = '0.00'; root.querySelector('[data-pos-discount-reason]').value = ''; root.querySelector('[data-pos-discount-reason-wrap]').hidden = true; cashInput.value = ''; confirmedInput.checked = false; root.querySelector('[data-pos-saved]').hidden = true; renderLines(); totals(null); lock(); showQuantity();
    }
    function changeBranch() {
        var selected = branchSelect.value;
        if (selected === branch) return;
        if (isLocked() || cart.length && !window.confirm(label('branch_change'))) { branchSelect.value = branch; if (branchSelect.selectize) branchSelect.selectize.setValue(branch, true); return; }
        modalGeneration++; if (modalController) modalController.abort(); modal.hidden = true;
        branch = selected; category = ''; root.querySelector('[data-pos-search]').value = ''; loaded = false; permissions = {}; register = null; resetDraft(); catalogGeneration++; if (catalogController) catalogController.abort(); updateRegister(null); root.querySelector('[data-pos-day-count]').hidden = true;
        if (branch) loadCatalog(1); else { root.querySelector('[data-pos-products]').replaceChildren(); root.querySelector('[data-pos-categories]').replaceChildren(); root.querySelector('[data-pos-catalog-message]').hidden = false; root.querySelector('[data-pos-catalog-message]').textContent = label('choose_branch_first'); notify(''); }
    }
    async function printReceipt(url) {
        if (!url) return;
        try { if (!window.DashboardPrint) throw new Error(label('print_error')); await window.DashboardPrint.print(endpoint(url)); }
        catch (error) { notify(label('print_error'), false, false); }
    }
    function saved(result) {
        var receipt = result.receipt;
        var candidate = result.receipt_url || receipt && receipt.receipt_url;
        var printable = candidate && new URL(endpoint(candidate));
        var receiptPath = printable && printable.pathname.match(/\/takeaway\/receipts\/([1-9][0-9]*)\/print\/?$/);
        if (!receipt || !Number.isSafeInteger(Number(receipt.id)) || Number(receipt.id) <= 0 || receipt.idempotency_key !== saleKey
            || !receipt.branch || receipt.branch.value !== branch || !Array.isArray(receipt.items) || !receipt.items.length
            || receipt.status !== 'completed' || minor(receipt.total) === null || !receiptPath || Number(receiptPath[1]) !== Number(receipt.id)
            || frozenPayload && receipt.payment_method !== frozenPayload.payment_method
            || quote && minor(receipt.total) !== minor(quote.total)) throw new Error(label('uncertain_sale'));
        if (!result.register) register = null;
        selling = false; uncertain = false; sold = true; frozenPayload = null; lastReceipt = result.receipt || result;
        var url = result.receipt_url || lastReceipt.receipt_url;
        updateRegister(result.register, result.today); root.querySelector('[data-pos-saved]').hidden = false; root.querySelector('[data-pos-saved] span').textContent = label('sale_saved') + (lastReceipt.number ? ' #' + lastReceipt.number : '') + ' · ' + label(lastReceipt.payment_method === 'mobile_wallet' ? 'wallet' : lastReceipt.payment_method) + (lastReceipt.payment_method === 'cash' ? ' · ' + label('change') + ': ' + money(lastReceipt.change) : '');
        var link = root.querySelector('[data-pos-reprint]'); if (url) link.href = endpoint(url); link.hidden = !url; notify(label('sale_saved'), true); lock(); printReceipt(url);
    }
    async function recoverSale() {
        if (!urls.daily || disposed) return false;
        var recoveryController = new AbortController(), recoveryTimeout = setTimeout(function () { recoveryController.abort(); }, 10000);
        try {
            var response = await fetch(endpoint(urls.daily, { branch: branch, idempotency_key: saleKey }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: recoveryController.signal }); var result = await read(response, 'daily_error');
            if (disposed) return false;
            var receipt = result.receipt || (result.items || result.receipts || []).find(function (item) { return item.idempotency_key === saleKey; });
            if (!receipt) return false; saved({ receipt: receipt, receipt_url: receipt.receipt_url, register: result.register, today: result.today }); return true;
        } catch (_) { return false; } finally { clearTimeout(recoveryTimeout); }
    }
    async function checkout() {
        if (registerLocked || selling || sold || !cart.length || !permissions.can_checkout) return;
        if (!uncertain) {
            if (!quote) { notify(label('quote_required'), false, true); return; }
            if (paymentMethod === 'cash') { var received = minor(cashInput.value), total = minor(quote.total); if (received === null) { notify(label('invalid_money')); cashInput.focus(); return; } if (received < total) { notify(label('cash_insufficient')); cashInput.focus(); return; } }
            else if (!confirmedInput.checked) { notify(label('confirm_payment')); confirmedInput.focus(); return; }
            frozenPayload = Object.assign(draft(), { quote_hash: quote.quote_hash, idempotency_key: saleKey, payment_method: paymentMethod,
                cash_received: paymentMethod === 'cash' ? decimal(minor(cashInput.value), 2) : null, payment_confirmed: paymentMethod === 'cash' ? false : true,
                payment_reference: root.querySelector('[data-pos-payment-reference]').value.trim(), notes: root.querySelector('[data-pos-notes]').value.trim() });
        }
        selling = true; lock(); notify(label('saving'));
        if (uncertain && await recoverSale()) return;
        var timeout, writeController = new AbortController(); timeout = setTimeout(function () { writeController.abort(); }, 25000);
        try {
            var response = await post(urls.checkout, frozenPayload, writeController.signal); var result = await read(response, 'sale_error'); if (disposed) return;
            if (!result.receipt || !result.receipt_url) { uncertain = true; throw new Error(label('uncertain_sale')); }
            saved(result);
        } catch (error) {
            if (disposed) return;
            if (!error.status || error.status < 400 || error.status >= 500) { uncertain = true; notify(label('uncertain_sale'), false, true); }
            else { uncertain = false; frozenPayload = null; if (error.status === 409) { quote = null; totals(null); } notify(error.message || label('sale_error'), false, true); }
        } finally { clearTimeout(timeout); selling = false; lock(); }
    }
    function openModal(title) {
        modalGeneration++; if (modalController) modalController.abort();
        if (modal.hidden) modalFocus = document.activeElement; modal.hidden = false; modal.querySelector('h2').textContent = title; var content = root.querySelector('[data-pos-modal-content]'); content.replaceChildren(node('p', 'tp-empty', label('loading'))); modal.querySelector('[role="dialog"]').focus(); return content;
    }
    function closeModal() { if (registerLocked) { notify(label('sale_locked')); return; } modalGeneration++; modal.hidden = true; if (modalController) modalController.abort(); if (modalFocus && modalFocus.isConnected) modalFocus.focus(); }
    function chooseProduct(product) {
        if (!product || isLocked()) return;
        if (!product.options || !product.options.length) { addProduct(product); return; }
        var content = openModal(label('choose_option') + ' · ' + product.name); content.replaceChildren(); var list = node('div', 'tp-option-list');
        [{ id: '', label: label('choose_base'), price: product.unit_price || product.price }].concat(product.options).forEach(function (option) {
            var button = node('button'); button.type = 'button'; button.appendChild(node('span', '', option.label)); button.appendChild(node('bdi', '', money(option.price))); button.addEventListener('click', function () { closeModal(); addProduct(product, option.id === '' ? null : option); }); list.appendChild(button);
        }); content.appendChild(list);
    }
    function receiptTable(items, content) {
        var table = node('table'), head = node('thead'), headings = node('tr'); ['invoice_number', 'date', 'payment_method', 'total', 'print_invoice'].forEach(function (key) { headings.appendChild(node('th', '', label(key))); }); head.appendChild(headings); table.appendChild(head); var body = node('tbody');
        items.forEach(function (item) { var row = node('tr'); row.appendChild(node('td', '', item.number || item.id)); row.appendChild(node('td', '', item.created_label || item.created_at || '')); row.appendChild(node('td', '', item.payment_label || label(item.payment_method === 'mobile_wallet' ? 'wallet' : item.payment_method))); row.appendChild(node('td', '', money(item.total))); var cell = node('td'); if (item.receipt_url) { var link = node('a', '', label('print_invoice')); link.href = endpoint(item.receipt_url); link.addEventListener('click', function (event) { event.preventDefault(); printReceipt(link.href); }); cell.appendChild(link); } row.appendChild(cell); body.appendChild(row); }); table.appendChild(body); content.appendChild(table);
    }
    async function daily(page) {
        if (registerLocked) { notify(label('sale_locked')); return; }
        if (!branch) { notify(label('choose_branch_first')); return; } var content = openModal(label('daily_invoices')), generation = modalGeneration; modalController = new AbortController();
        try { var response = await fetch(endpoint(urls.daily, { branch: branch, page: page || 1 }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: modalController.signal }); var result = await read(response, 'daily_error'); if (disposed || modal.hidden || generation !== modalGeneration) return; content.replaceChildren(); var items = result.items || result.receipts || []; if (!items.length) content.appendChild(node('p', 'tp-empty', label('daily_empty'))); else receiptTable(items, content); var pages = result.pagination || {}; if (pages.last_page > 1) { var controls = node('nav', 'tp-pagination'); ['previous', 'next'].forEach(function (direction) { var button = node('button', '', label(direction)); button.type = 'button'; button.disabled = direction === 'previous' ? pages.page <= 1 : pages.page >= pages.last_page; button.addEventListener('click', function () { daily(pages.page + (direction === 'next' ? 1 : -1)); }); controls.appendChild(button); }); content.appendChild(controls); } updateRegister(result.register, result.today); }
        catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(node('p', 'tp-empty', error.message || label('daily_error'))); }
    }
    async function till() {
        if (registerLocked) { notify(label('sale_locked')); return; }
        if (!branch) { notify(label('choose_branch_first')); return; } var content = openModal(label('cash_register')), generation = modalGeneration; modalController = new AbortController();
        try { var response = await fetch(endpoint(urls.register || urls.tills, { branch: branch }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: modalController.signal }); var result = await read(response, 'register_error'); if (disposed || modal.hidden || generation !== modalGeneration) return; content.replaceChildren(); updateRegister(result.register || result, result.today); var summary = node('div', 'tp-modal-summary'); summary.appendChild(node('span', '', label('register_balance'))); summary.appendChild(node('strong', '', money(register && register.balance) + ' ' + label('currency'))); content.appendChild(summary);
            var rows = result.entries || result.movements || result.items || []; if (rows.length) { var table = node('table'), body = node('tbody'); rows.forEach(function (entry) { var row = node('tr'); row.appendChild(node('td', '', entry.created_label || entry.created_at)); row.appendChild(node('td', '', entry.note || entry.reason || label({ cash_sale: 'cash_sales', cash_in: 'cash_in', cash_out: 'cash_out', tax_setting: 'till_settings' }[entry.kind] || 'cash_movements'))); row.appendChild(node('td', '', money(entry.amount))); body.appendChild(row); }); table.appendChild(body); content.appendChild(table); } else content.appendChild(node('p', 'tp-empty', label('register_readonly')));
            if ((result.permissions || permissions).can_manage && !selling && !uncertain && !registerLocked) registerForms(content);
        } catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(node('p', 'tp-empty', error.message || label('register_error'))); }
    }
    function inputLabel(name, input) { var wrapper = node('label'); wrapper.appendChild(node('span', '', label(name))); wrapper.appendChild(input); return wrapper; }
    function registerForms(content) {
        var forms = node('div', 'tp-register-forms');
        var operationBranch = branch, operationRegister = Object.assign({}, register);
        function formFor(kind) {
            var form = node('form', 'tp-register-form'); form.setAttribute('data-spa-off', '');
            form.appendChild(node('h3', '', label(kind === 'settings' ? 'till_settings' : 'movement')));
            var tax, direction, amount;
            if (kind === 'settings') { tax = node('input'); tax.type = 'text'; tax.inputMode = 'decimal'; tax.value = operationRegister.tax_rate || policy.tax_rate || '0.00'; form.appendChild(inputLabel('tax_rate', tax)); }
            else { direction = node('select'); ['in', 'out'].forEach(function (value) { var option = node('option', '', label(value === 'in' ? 'cash_in' : 'cash_out')); option.value = value; direction.appendChild(option); }); form.appendChild(direction); amount = node('input'); amount.type = 'text'; amount.inputMode = 'decimal'; form.appendChild(inputLabel('amount', amount)); }
            var note = node('input'); note.type = 'text'; note.maxLength = 500; note.required = true; form.appendChild(inputLabel('reason', note));
            var submit = node('button', '', label('save')); submit.type = 'submit'; form.appendChild(submit);
            var feedback = node('p'); feedback.setAttribute('role', 'status'); form.appendChild(feedback);
            var operationKey = uuid(), operationPayload = null, pending = false;
            form.addEventListener('submit', async function (event) {
                event.preventDefault(); if (pending || disposed || operationBranch !== branch) return;
                if (!operationPayload) { operationPayload = { branch: operationBranch, note: note.value.trim(), idempotency_key: operationKey, expected_revision: operationRegister.revision };
                    if (kind === 'settings') operationPayload.tax_rate = minor(tax.value) === null ? tax.value : decimal(minor(tax.value), 2);
                    else { operationPayload.direction = direction.value; operationPayload.amount = minor(amount.value) === null ? amount.value : decimal(minor(amount.value), 2); }
                }
                pending = true; registerLocked = true; lock();
                forms.querySelectorAll('input,select,button').forEach(function (control) { control.disabled = true; }); root.querySelector('[data-pos-modal-close]').disabled = true;
                feedback.textContent = label('saving');
                var writeController = new AbortController(), timeout = setTimeout(function () { writeController.abort(); }, 25000);
                try {
                    var response = await post(urls[kind], operationPayload, writeController.signal); var result = await read(response, 'register_error');
                    if (disposed || operationBranch !== branch) return;
                    if (!result.branch || result.branch.value !== operationBranch || !result.register || result.register.branch !== operationBranch
                        || minor(result.register.balance) === null || typeof result.replayed !== 'boolean'
                        || !Number.isSafeInteger(Number(result.register.revision)) || Number(result.register.revision) <= Number(operationRegister.revision)) throw new Error(label('uncertain_sale'));
                    registerLocked = false; updateRegister(result.register); root.querySelector('[data-pos-modal-close]').disabled = false; closeModal();
                    if (kind === 'settings') { quote = null; invalidateQuote(); loadCatalog(catalogPage); }
                    notify(label(kind === 'settings' ? 'settings_saved' : 'movement_saved'), true);
                } catch (error) {
                    if (disposed || operationBranch !== branch) return;
                    if (!error.status || error.status < 400 || error.status >= 500) { registerLocked = true; feedback.textContent = label('uncertain_sale'); submit.textContent = label('retry'); submit.disabled = false; }
                    else { registerLocked = false; operationPayload = null; feedback.textContent = error.message || label('register_error'); forms.querySelectorAll('input,select,button').forEach(function (control) { control.disabled = false; }); root.querySelector('[data-pos-modal-close]').disabled = false; }
                } finally { clearTimeout(timeout); pending = false; lock(); }
            }); return form;
        }
        if (urls.settings) forms.appendChild(formFor('settings'));
        if (urls.movements) forms.appendChild(formFor('movements'));
        content.appendChild(forms);
    }
    function mayLeave() { if (selling || uncertain || registerLocked) { notify(label('sale_locked'), false, uncertain); return false; } return !cart.length || sold || window.confirm(label('unsaved_invoice')); }
    listen(root, 'click', function (event) {
        var button = event.target.closest('button'); if (!button || !root.contains(button)) return;
        if (button.hasAttribute('data-pos-finish')) return checkout();
        if (button.hasAttribute('data-pos-modal-close')) return closeModal();
        if (button.hasAttribute('data-pos-daily')) return daily();
        if (button.hasAttribute('data-pos-register')) return till();
        if (button.hasAttribute('data-pos-new')) { resetDraft(); notify(''); return loadCatalog(catalogPage); }
        if (button.hasAttribute('data-pos-retry')) { if (uncertain) return checkout(); if (branch) return quote === null && cart.length && loaded ? requestQuote() : loadCatalog(catalogPage); }
        if (isLocked()) return;
        if (button.dataset.posKey !== undefined) return key(button.dataset.posKey);
        if (button.dataset.posUnit) { quantityMode = button.dataset.posUnit; quantityInput = quantityMode === 'piece' ? '1' : '0'; replaceQuantity = true; showQuantity(); return; }
        if (button.hasAttribute('data-pos-weight-reset')) { quantityInput = quantityMode === 'piece' ? '1' : '0'; replaceQuantity = true; showQuantity(); return; }
        if (button.dataset.posPayment) return setPayment(button.dataset.posPayment);
        if (button.dataset.posProduct) return chooseProduct(products.get(button.dataset.posProduct));
        if (button.dataset.posRemove !== undefined) { cart.splice(Number(button.dataset.posRemove), 1); renderLines(); invalidateQuote(); return; }
        if (button.hasAttribute('data-pos-clear')) { if (!cart.length || window.confirm(label('confirm_clear'))) { resetDraft(); notify(''); } return; }
        if (button.dataset.posCategory !== undefined) { category = button.dataset.posCategory; return loadCatalog(1); }
        if (button.dataset.posPage) return loadCatalog(catalogPage + (button.dataset.posPage === 'next' ? 1 : -1));
    });
    listen(branchSelect, 'change', changeBranch);
    if (window.jQuery) window.jQuery(branchSelect).on('change.takeawayPos', changeBranch);
    listen(root.querySelector('[data-pos-search]'), 'input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(function () { loadCatalog(1); }, 250); });
    listen(discountInput, 'input', function () { root.querySelector('[data-pos-discount-reason-wrap]').hidden = !policy.can_discount || !(minor(discountInput.value) > 0n); invalidateQuote(); }); listen(root.querySelector('[data-pos-discount-reason]'), 'input', invalidateQuote); listen(cashInput, 'input', showChange);
    listen(root.querySelector('[data-pos-reprint]'), 'click', function (event) { event.preventDefault(); printReceipt(event.currentTarget.href); });
    listen(modal, 'click', function (event) { if (event.target === modal) closeModal(); });
    listen(document, 'keydown', function (event) {
        if (disposed) return; if (event.key === 'Escape' && !modal.hidden) { closeModal(); return; }
        if (!modal.hidden) { if (event.key === 'Tab') { var controls = modal.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled)'); if (controls.length) { var first = controls[0], last = controls[controls.length - 1]; if (event.shiftKey && (document.activeElement === first || document.activeElement === modal.querySelector('[role="dialog"]'))) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } } return; }
        if (event.key === 'F9') { event.preventDefault(); checkout(); return; }
        if (event.target.matches('input,textarea,select,[contenteditable]')) return;
        if (/^[0-9.]$/.test(event.key)) { event.preventDefault(); key(event.key); }
        else if (event.key === 'Backspace') { event.preventDefault(); key('backspace'); }
        else if (event.key === 'Enter' && root.querySelector('.tp-weight').contains(event.target)) { event.preventDefault(); key('confirm'); }
    });
    listen(window, 'beforeunload', function (event) { if (registerLocked || cart.length && !sold) { event.preventDefault(); event.returnValue = ''; } });
    if (window.DashboardSPA && window.DashboardSPA.onBeforeLeave) { window.DashboardSPA.onBeforeLeave(mayLeave); navigationGuardRegistered = true; }
    listen(document, 'click', function (event) { if (navigationGuardRegistered || disposed || !cart.length || sold) return; var link = event.target.closest('a[href]'); if (link && !root.contains(link) && link.target !== '_blank' && link.href.indexOf('#') < 0 && !mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () { disposed = true; catalogGeneration++; quoteGeneration++; clearTimeout(searchTimer); clearTimeout(quoteTimer); if (catalogController) catalogController.abort(); if (quoteController) quoteController.abort(); if (modalController) modalController.abort(); listeners.forEach(function (remove) { remove(); }); if (window.jQuery) window.jQuery(branchSelect).off('.takeawayPos'); });
    renderLines(); renderPayments(); showQuantity(); lock(); if (branch) loadCatalog(1);
}());
