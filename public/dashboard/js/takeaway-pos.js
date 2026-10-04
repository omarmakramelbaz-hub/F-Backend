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
    var paymentMethod = 'cash', loaded = false, disposed = false, selling = false, uncertain = false, sold = false, quarantined = false;
    var saleKey = uuid(), frozenPayload = null, lastReceipt = null, lastQuotedTotal = null, register = null, modalFocus, modalController, modalGeneration = 0, registerLocked = false;
    var listeners = [], navigationGuardRegistered = false;
    var message = root.querySelector('[data-pos-message]');
    var modal = root.querySelector('[data-pos-modal]');
    var finish = root.querySelector('[data-pos-finish]');
    var cashInput = root.querySelector('[data-pos-cash-received]');
    var discountInput = root.querySelector('[data-pos-discount]');
    var confirmedInput = root.querySelector('[data-pos-payment-confirmed]');
    var activeInvoice = 0;
    var invoices = Array.from({ length: 5 }, function () { return newInvoice(branch); });
    var storageKey = 'takeaway-invoices:v2:' + String(boot.cashier && boot.cashier.id || '');
    var storageReady = false, storageWarning = false;
    saleKey = invoices[0].saleKey;

    function newInvoice(selectedBranch) {
        var selected = (boot.branches || []).find(function (item) { return String(item.value || item.id) === selectedBranch; });
        return { branch: selectedBranch, branchName: selected && selected.name || selectedBranch, quarantined: false, cart: [], quote: null, saleKey: uuid(), frozenPayload: null, uncertain: false, sold: false, lastReceipt: null, lastQuotedTotal: null,
            discount: '0.00', discountReason: '', notes: '', cash: '', reference: '', confirmed: false, paymentMethod: 'cash',
            quantityMode: 'piece', quantityInput: '1', replaceQuantity: true, category: '', search: '', catalogPage: 1,
            policy: {}, permissions: boot.permissions || {}, products: [], categories: [], pagination: {}, register: null, today: null };
    }
    function captureInvoice() {
        return { branch: branch, branchName: branchSelect.selectedOptions[0] && branchSelect.selectedOptions[0].textContent || invoices[activeInvoice].branchName, quarantined: quarantined,
            cart: cart, quote: quote, saleKey: saleKey, frozenPayload: frozenPayload, uncertain: uncertain, sold: sold, lastReceipt: lastReceipt, lastQuotedTotal: lastQuotedTotal,
            discount: discountInput.value, discountReason: root.querySelector('[data-pos-discount-reason]').value, notes: root.querySelector('[data-pos-notes]').value,
            cash: cashInput.value, reference: root.querySelector('[data-pos-payment-reference]').value, confirmed: confirmedInput.checked, paymentMethod: paymentMethod,
            quantityMode: quantityMode, quantityInput: quantityInput, replaceQuantity: replaceQuantity, category: category,
            search: root.querySelector('[data-pos-search]').value, catalogPage: catalogPage, policy: policy, permissions: permissions,
            products: Array.from(products.values()), categories: invoices[activeInvoice].categories || [], pagination: pagination, register: register, today: invoices[activeInvoice].today || null };
    }
    function storeInvoice() { invoices[activeInvoice] = captureInvoice(); persistInvoices(); }
    function persistInvoices() {
        if (!storageReady || !boot.cashier || !boot.cashier.id) return;
        try {
            var values = invoices.map(function (invoice, index) {
                var value = Object.assign({}, invoice, { products: [], categories: [], register: null, today: null,
                    uncertain: invoice.uncertain || index === activeInvoice && selling });
                value.cart = invoice.cart.map(function (line) { return { product: { id: line.product.id, name: line.product.name,
                    image_url: line.product.image_url, unit_price: line.product.unit_price || line.product.price, unit: line.product.unit,
                    quantity_mode: line.product.quantity_mode, available: line.product.available }, option: line.option, mode: line.mode, quantity: line.quantity }; });
                return value;
            });
            var serialized = JSON.stringify({ version: 2, actor: String(boot.cashier.id), active: activeInvoice, updated: Date.now(), invoices: values });
            if (serialized.length > 524288) throw new Error('Draft storage limit');
            window.sessionStorage.setItem(storageKey, serialized);
        } catch (_) { if (!storageWarning) { storageWarning = true; notify(label('storage_unavailable')); } }
    }
    function restoreInvoices() {
        try {
            var raw = window.sessionStorage.getItem(storageKey); if (!raw || raw.length > 524288) return false;
            var savedState = JSON.parse(raw), actor = String(boot.cashier && boot.cashier.id || '');
            if (savedState.version !== 2 || savedState.actor !== actor || !actor || !Array.isArray(savedState.invoices) || savedState.invoices.length !== 5) return false;
            var allowed = (boot.branches || []).map(function (item) { return String(item.value || item.id); }); allowed.push('');
            var fresh = Date.now() - Number(savedState.updated) < 12 * 60 * 60 * 1000;
            var restored = savedState.invoices.map(function (value) {
                if (boundPendingInvoice(value)) return recoverPendingInvoice(value, allowed);
                try {
                if (!value || allowed.indexOf(value.branch) < 0 || !validStoredCart(value.cart)
                    || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value.saleKey)) throw new Error('Invalid draft');
                if (typeof value.uncertain !== 'boolean' || typeof value.sold !== 'boolean' || !value.policy || typeof value.policy !== 'object'
                    || !value.permissions || typeof value.permissions !== 'object' || ['piece', 'weight'].indexOf(value.quantityMode) < 0
                    || typeof value.quantityInput !== 'string' || !/^[0-9]{1,10}(?:\.[0-9]{0,3})?$/.test(value.quantityInput)
                    || ['cash', 'card', 'mobile_wallet', 'other'].indexOf(value.paymentMethod) < 0
                    || ['discount', 'discountReason', 'notes', 'cash', 'reference', 'category', 'search'].some(function (key) { return typeof value[key] !== 'string'; })) throw new Error('Invalid draft fields');
                if (value.uncertain || value.frozenPayload) throw new Error('Invalid pending sale');
                if (!fresh && !value.uncertain) return newInvoice(value.branch);
                return Object.assign(newInvoice(value.branch), value, { quarantined: false, products: [], categories: [], register: null, today: null });
                } catch (_) { return newInvoice(String(boot.selected_branch || '')); }
            });
            invoices = restored; activeInvoice = Number.isInteger(savedState.active) && savedState.active >= 0 && savedState.active < 5 ? savedState.active : 0; return true;
        } catch (_) { return false; }
    }
    function validStoredCart(value) {
        return Array.isArray(value) && value.length <= 100 && value.every(function (line) { return line && line.product && Number.isSafeInteger(Number(line.product.id)) && Number(line.product.id) > 0
            && typeof line.product.name === 'string' && ['piece', 'weight'].indexOf(line.mode) >= 0 && !!quantity(line.quantity, line.mode); });
    }
    function boundPendingInvoice(value) {
        var payload = value && value.frozenPayload;
        return !!(value && payload && typeof value.saleKey === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value.saleKey)
            && typeof value.branch === 'string' && /^(?:f|gs):[1-9][0-9]*$/.test(value.branch) && payload.branch === value.branch && payload.idempotency_key === value.saleKey
            && ['cash', 'card', 'mobile_wallet', 'other'].indexOf(payload.payment_method) >= 0 && typeof payload.quote_hash === 'string' && /^[0-9a-f]{64}$/i.test(payload.quote_hash)
            && Array.isArray(payload.items) && payload.items.length > 0 && payload.items.length <= 100 && payload.items.every(function (item) {
                return item && Number.isSafeInteger(Number(item.product_id)) && Number(item.product_id) > 0 && ['piece', 'weight'].indexOf(item.quantity_mode) >= 0 && !!quantity(item.quantity, item.quantity_mode);
            }));
    }
    function recoverPendingInvoice(value, allowed) {
        var payload = value.frozenPayload, invoice = newInvoice(value.branch);
        invoice.saleKey = value.saleKey; invoice.frozenPayload = payload; invoice.uncertain = true; invoice.quarantined = allowed.indexOf(value.branch) < 0;
        invoice.branchName = typeof value.branchName === 'string' ? value.branchName : invoice.branchName;
        invoice.quote = value.quote && Array.isArray(value.quote.items) && minor(value.quote.total) !== null ? value.quote : null;
        invoice.lastQuotedTotal = typeof value.lastQuotedTotal === 'string' && minor(value.lastQuotedTotal) !== null ? value.lastQuotedTotal : invoice.quote && invoice.quote.total || null;
        invoice.cart = validStoredCart(value.cart) && value.cart.length ? value.cart : payload.items.map(function (item) {
            var snapshot = invoice.quote && invoice.quote.items.find(function (line) { return Number(line.product_id) === Number(item.product_id) && String(line.option_id || '') === String(item.option_id || '') && line.quantity_mode === item.quantity_mode; }) || {};
            return { product: { id: item.product_id, name: snapshot.name || label('item') + ' #' + item.product_id, unit_price: snapshot.unit_price, available: false },
                option: item.option_id ? { id: item.option_id, label: snapshot.option_label || '', price: snapshot.unit_price } : null, mode: item.quantity_mode, quantity: item.quantity };
        });
        invoice.paymentMethod = payload.payment_method; invoice.confirmed = !!payload.payment_confirmed;
        invoice.discount = String(payload.discount || '0.00'); invoice.discountReason = String(payload.discount_reason || ''); invoice.notes = String(payload.notes || '');
        invoice.cash = String(payload.cash_received || ''); invoice.reference = String(payload.payment_reference || '');
        invoice.policy = value.policy && typeof value.policy === 'object' ? value.policy : {};
        invoice.permissions = value.permissions && typeof value.permissions === 'object' ? value.permissions : boot.permissions || {};
        return invoice;
    }
    function renderInvoiceTabs() {
        storeInvoice(); var list = root.querySelector('[data-pos-invoices]');
        invoices.forEach(function (invoice, index) {
            var button = list.querySelector('[data-pos-invoice="' + index + '"]');
            if (!button) { button = node('button', 'tp-invoice-tab'); button.type = 'button'; button.dataset.posInvoice = index;
                button.appendChild(node('strong')); button.appendChild(node('small')); button.appendChild(node('bdi', 'tp-invoice-count')); list.appendChild(button); }
            button.setAttribute('role', 'tab'); button.setAttribute('aria-controls', 'takeaway-invoice'); button.setAttribute('aria-selected', String(index === activeInvoice));
            button.classList.toggle('is-active', index === activeInvoice); button.classList.toggle('is-uncertain', invoice.uncertain); button.disabled = selling || registerLocked;
            button.querySelector('strong').textContent = label('invoice_tab') + ' ' + (index + 1);
            button.querySelector('small').textContent = label(invoice.uncertain ? 'invoice_uncertain' : invoice.sold ? 'invoice_saved' : invoiceIsDirty(invoice) ? 'invoice_draft' : 'invoice_empty');
            var count = button.querySelector('.tp-invoice-count'); count.hidden = !invoice.cart.length; count.textContent = invoice.cart.length;
        });
    }
    function switchInvoice(index, restoring) {
        if (!Number.isInteger(index) || index < 0 || index >= invoices.length || index === activeInvoice && !restoring) return;
        if (selling || registerLocked) { notify(label('sale_locked'), false, uncertain); return; }
        if (!restoring) storeInvoice(); catalogGeneration++; quoteGeneration++; modalGeneration++;
        if (catalogController) catalogController.abort(); if (quoteController) quoteController.abort(); if (modalController) modalController.abort();
        clearTimeout(searchTimer); clearTimeout(quoteTimer); modal.hidden = true;
        activeInvoice = index; var invoice = invoices[index];
        branch = invoice.branch; quarantined = !!invoice.quarantined;
        branchSelect.querySelectorAll('[data-pos-quarantined-branch]').forEach(function (option) { option.remove(); });
        if (quarantined) { var blockedOption = node('option', '', invoice.branchName || branch); blockedOption.value = branch; blockedOption.dataset.posQuarantinedBranch = '1'; branchSelect.appendChild(blockedOption); }
        branchSelect.value = branch; if (branchSelect.selectize) branchSelect.selectize.setValue(branch, true);
        cart = invoice.cart; quote = invoice.uncertain || invoice.sold ? invoice.quote : null; saleKey = invoice.saleKey; frozenPayload = invoice.frozenPayload;
        uncertain = invoice.uncertain; sold = invoice.sold; lastReceipt = invoice.lastReceipt; lastQuotedTotal = invoice.lastQuotedTotal || invoice.quote && invoice.quote.total || null; selling = false;
        paymentMethod = invoice.paymentMethod; quantityMode = invoice.quantityMode; quantityInput = invoice.quantityInput; replaceQuantity = invoice.replaceQuantity;
        category = invoice.category; catalogPage = invoice.catalogPage; policy = invoice.policy; permissions = invoice.permissions; register = invoice.register; pagination = invoice.pagination; loaded = false;
        discountInput.value = invoice.discount; root.querySelector('[data-pos-discount-reason]').value = invoice.discountReason;
        root.querySelector('[data-pos-notes]').value = invoice.notes; cashInput.value = invoice.cash; root.querySelector('[data-pos-payment-reference]').value = invoice.reference;
        confirmedInput.checked = invoice.confirmed; root.querySelector('[data-pos-search]').value = invoice.search;
        root.querySelector('[data-pos-discount-wrap]').hidden = !policy.can_discount; root.querySelector('[data-pos-total="discount"]').hidden = !!policy.can_discount;
        root.querySelector('[data-pos-discount-reason-wrap]').hidden = !policy.can_discount || !(minor(invoice.discount) > 0n);
        products.clear(); var list = root.querySelector('[data-pos-products]'); list.replaceChildren();
        invoice.products.forEach(function (product) { products.set(String(product.id), product); list.appendChild(productNode(product)); });
        renderCategories(invoice.categories); renderPayments(policy.payment_methods); renderLines(); totals(quote); showQuantity(); updateRegister(register, invoice.today);
        root.querySelector('[data-pos-day-count]').hidden = !invoice.today; root.querySelector('[data-pos-pagination]').hidden = true;
        renderSaved(); notify(quarantined ? label('pending_branch_unavailable') : uncertain ? label('uncertain_sale') : sold ? label('sale_saved') : ''); lock();
        if (branch && !uncertain && !sold) loadCatalog(catalogPage);
        else if (!branch) { root.querySelector('[data-pos-catalog-message]').hidden = false; root.querySelector('[data-pos-catalog-message]').textContent = label('choose_branch_first'); }
        if (restoring && uncertain && !quarantined) { selling = true; lock(); recoverSale().then(function (recovered) { if (!recovered && !disposed) notify(label('uncertain_sale'), false, true); }).finally(function () { if (!disposed) { selling = false; lock(); } }); }
    }
    function invoiceIsDirty(invoice) { return invoice.cart.length > 0 || !!invoice.notes.trim() || !!invoice.cash.trim() || !!invoice.reference.trim() || minor(invoice.discount) > 0n; }
    function hasOpenInvoices() { storeInvoice(); return invoices.some(function (invoice) { return !invoice.sold && invoiceIsDirty(invoice); }); }
    function hasUncertainInvoices() { storeInvoice(); return invoices.some(function (invoice) { return invoice.uncertain; }); }

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
    function isLocked() { return selling || uncertain || sold || registerLocked || quarantined; }
    function lock() {
        root.classList.toggle('is-checkout-locked', isLocked()); root.classList.toggle('is-sold', sold);
        root.querySelectorAll('[data-pos-branch],[data-pos-search],[data-pos-notes],[data-pos-payment-reference],[data-pos-discount],[data-pos-discount-reason],[data-pos-cash-received],[data-pos-payment-confirmed],[data-pos-clear],[data-pos-key],[data-pos-unit],[data-pos-weight-reset],[data-pos-payment],[data-pos-product],[data-pos-options],[data-pos-remove],[data-pos-category]').forEach(function (control) {
            control.disabled = isLocked() || control.hasAttribute('data-unavailable') || control.matches('[data-pos-product],[data-pos-options]') && !permissions.can_checkout;
        });
        if (branchSelect.selectize) { if (isLocked()) branchSelect.selectize.disable(); else branchSelect.selectize.enable(); }
        finish.disabled = quarantined || registerLocked || selling || sold || !cart.length || !permissions.can_checkout || !uncertain && !quote;
        finish.hidden = sold;
        finish.querySelector('span').textContent = label(selling ? 'saving' : uncertain ? 'retry' : 'finish_sale');
        renderInvoiceTabs();
    }
    function updateRegister(value, today) {
        if (value) register = value;
        root.querySelector('[data-pos-register-balance]').textContent = register && register.balance !== undefined ? money(register.balance) : '—';
        if (today && today.count !== undefined) {
            invoices[activeInvoice].today = today;
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
        storeInvoice();
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
        renderInvoiceTabs();
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
            var collectedAmountChanged = confirmedInput.checked && lastQuotedTotal !== null && minor(lastQuotedTotal) !== minor(result.total);
            if (collectedAmountChanged) confirmedInput.checked = false;
            lastQuotedTotal = result.total; quote = result; totals(result); renderLines(); notify(collectedAmountChanged ? label('confirm_updated_payment') : '');
        } catch (error) { if (disposed || generation !== quoteGeneration || error.name === 'AbortError') return; quote = null; totals(null); notify(error.message || label('quote_error'), false, true); }
    }
    function addProduct(product, option) {
        if (!product || isLocked() || !permissions.can_checkout || !product.available) return;
        var amount = quantity(quantityInput, quantityMode); if (!amount) { notify(label('invalid_quantity')); return; }
        var mode = product.quantity_mode;
        if (mode && mode !== 'flexible' && mode !== 'select' && mode !== quantityMode) { notify(label('unit_mismatch')); return; }
        var optionKey = option && option.id !== undefined ? String(option.id) : '';
        var existing = cart.find(function (line) { return line.product.id === product.id && line.mode === quantityMode && String(line.option && line.option.id || '') === optionKey; });
        if (existing) {
            var sum = decimalInput(existing.quantity, 3) + amount.scaled;
            var checked = quantity(decimal(sum, 3), quantityMode); if (!checked) { notify(label('invalid_quantity')); return; } existing.quantity = checked.value;
        } else { if (cart.length >= 100) { notify(label('cart_limit')); return; } cart.push({ product: product, option: option || null, mode: quantityMode, quantity: amount.value }); }
        replaceQuantity = true; renderLines(); invalidateQuote(); notify(label('item_added') + ' · ' + product.name, true);
    }
    function productNode(product) {
        var card = node('article', 'tp-product-card');
        var button = node('button', 'tp-product'); button.type = 'button'; button.dataset.posProduct = product.id;
        button.disabled = !product.available || !permissions.can_checkout || isLocked(); if (!product.available) button.dataset.unavailable = '1';
        button.setAttribute('aria-label', product.name + ' · ' + money(product.unit_price || product.price) + ' ' + label('currency'));
        if (product.image_url) { var image = node('img', 'tp-product-image'); image.src = product.image_url; image.alt = ''; image.loading = 'lazy'; image.addEventListener('error', function () { var placeholder = node('span', 'tp-product-image tp-product-no-image'); placeholder.appendChild(node('i', 'fas fa-fish')); image.replaceWith(placeholder); }, { once: true }); button.appendChild(image); }
        else { var placeholder = node('span', 'tp-product-image tp-product-no-image'); placeholder.appendChild(node('i', 'fas fa-fish')); button.appendChild(placeholder); }
        var copy = node('span', 'tp-product-copy'); copy.appendChild(node('strong', 'tp-product-name', product.name));
        var price = node('span', 'tp-product-price'); price.appendChild(node('bdi', '', money(product.unit_price || product.price))); price.appendChild(document.createTextNode(' ' + label('currency'))); copy.appendChild(price);
        var unit = node('span', 'tp-product-unit', !product.available ? label('unavailable') : product.unit || label('flexible_unit'));
        unit.classList.toggle('is-unavailable', !product.available); unit.classList.toggle('is-weight', product.quantity_mode === 'weight' || product.unit === 'kg'); copy.appendChild(unit);
        if (product.options && product.options.length) copy.appendChild(node('small', 'tp-product-base', label('base_option')));
        button.appendChild(copy); card.appendChild(button);
        if (product.options && product.options.length) { var options = node('button', 'tp-product-options', label('product_options')); options.type = 'button'; options.dataset.posOptions = product.id; options.setAttribute('aria-label', label('product_options') + ' · ' + product.name); options.disabled = button.disabled; if (!product.available) options.dataset.unavailable = '1'; card.appendChild(options); }
        return card;
    }
    function renderPayments(methods) {
        var list = root.querySelector('[data-pos-payments]'); list.replaceChildren();
        var icons = { cash: 'far fa-money-bill-alt', card: 'far fa-credit-card', mobile_wallet: 'fas fa-mobile-alt', other: 'fas fa-ellipsis-h' };
        (methods || ['cash', 'card', 'mobile_wallet', 'other']).forEach(function (method) { var key = typeof method === 'string' ? method : method.value;
            if (!icons[key]) return; var button = node('button', 'tp-payment'); button.type = 'button'; button.dataset.posPayment = key; button.appendChild(node('i', icons[key])); button.appendChild(node('span', '', label(key === 'mobile_wallet' ? 'wallet' : key))); button.setAttribute('aria-pressed', String(key === paymentMethod)); button.classList.toggle('is-active', key === paymentMethod); list.appendChild(button);
        });
        applyPayment();
    }
    function setPayment(method) {
        if (isLocked()) return; if (paymentMethod !== method) confirmedInput.checked = false; paymentMethod = method; applyPayment(); storeInvoice();
    }
    function applyPayment() {
        root.querySelectorAll('[data-pos-payment]').forEach(function (button) { var selected = button.dataset.posPayment === paymentMethod; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); });
        root.querySelector('[data-pos-cash-wrap]').hidden = paymentMethod !== 'cash'; root.querySelector('[data-pos-payment-confirm-wrap]').hidden = paymentMethod === 'cash'; root.querySelector('[data-pos-reference-wrap]').hidden = paymentMethod === 'cash'; showChange();
    }
    function renderCategories(values) {
        var categories = root.querySelector('[data-pos-categories]'); categories.replaceChildren();
        (values || []).forEach(function (value) { var button = node('button', '', value.name || value.label); button.type = 'button'; button.dataset.posCategory = value.id; button.classList.toggle('is-active', String(value.id) === category); button.setAttribute('aria-pressed', String(String(value.id) === category)); button.title = String(value.id) === category ? label('filter_reset') : value.name || value.label; categories.appendChild(button); });
        categories.hidden = !(values || []).length;
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
            invoices[activeInvoice].categories = result.categories || []; renderCategories(result.categories);
            pagination = result.pagination || {}; catalogPage = Number(pagination.page || 1); root.querySelector('[data-pos-pagination]').hidden = !pagination.last_page || pagination.last_page < 2; root.querySelector('[data-pos-page-label]').textContent = catalogPage + ' / ' + (pagination.last_page || 1); root.querySelector('[data-pos-page="previous"]').disabled = catalogPage <= 1; root.querySelector('[data-pos-page="next"]').disabled = catalogPage >= Number(pagination.last_page || 1);
            root.querySelector('[data-pos-catalog-message]').hidden = items.length > 0; root.querySelector('[data-pos-catalog-message]').textContent = label('no_products');
            invoices[activeInvoice].today = result.today || null; updateRegister(result.register, result.today); renderPayments(result.payment_methods || policy.payment_methods); totals(quote); lock(); if (!permissions.can_checkout) notify(label('access_required'));
            if (cart.length && !quote) invalidateQuote();
        } catch (error) { if (disposed || generation !== catalogGeneration || error.name === 'AbortError') return; list.replaceChildren(); products.clear(); root.querySelector('[data-pos-catalog-message]').hidden = false; root.querySelector('[data-pos-catalog-message]').textContent = error.message || label('load_error'); notify(error.message || label('load_error'), false, true); }
        finally { if (generation === catalogGeneration) list.setAttribute('aria-busy', 'false'); }
    }
    function resetDraft() {
        cart = []; quote = null; frozenPayload = null; uncertain = false; sold = false; saleKey = uuid(); lastReceipt = null; lastQuotedTotal = null; quoteGeneration++; if (quoteController) quoteController.abort(); clearTimeout(quoteTimer);
        paymentMethod = 'cash'; quantityMode = 'piece'; quantityInput = '1'; replaceQuantity = true;
        root.querySelector('[data-pos-notes]').value = ''; root.querySelector('[data-pos-payment-reference]').value = ''; discountInput.value = '0.00'; root.querySelector('[data-pos-discount-reason]').value = ''; root.querySelector('[data-pos-discount-reason-wrap]').hidden = true; cashInput.value = ''; confirmedInput.checked = false; root.querySelector('[data-pos-saved]').hidden = true; applyPayment(); renderLines(); totals(null); lock(); showQuantity();
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
        var printingInvoice = activeInvoice, printingKey = saleKey;
        try { if (!window.DashboardPrint) throw new Error(label('print_error')); await window.DashboardPrint.print(endpoint(url)); }
        catch (error) { if (printingInvoice === activeInvoice && printingKey === saleKey) notify(label('print_error'), false, false); }
    }
    function renderSaved() {
        var savedRow = root.querySelector('[data-pos-saved]'); savedRow.hidden = !sold;
        if (!sold || !lastReceipt) return;
        savedRow.querySelector('span').textContent = label('sale_saved') + (lastReceipt.number ? ' #' + lastReceipt.number : '') + ' · ' + label(lastReceipt.payment_method === 'mobile_wallet' ? 'wallet' : lastReceipt.payment_method) + (lastReceipt.payment_method === 'cash' ? ' · ' + label('change') + ': ' + money(lastReceipt.change) : '');
        var link = root.querySelector('[data-pos-reprint]'); link.hidden = !lastReceipt.receipt_url; if (lastReceipt.receipt_url) link.href = endpoint(lastReceipt.receipt_url);
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
        var pendingPayload = frozenPayload, url = result.receipt_url || receipt.receipt_url;
        try {
            if (!result.register) register = null;
            selling = false; uncertain = false; sold = true; lastReceipt = receipt;
            if (url) lastReceipt.receipt_url = url;
            updateRegister(result.register, result.today); renderSaved(); notify(label('sale_saved'), true); lock();
            frozenPayload = null; storeInvoice();
        } catch (_) {
            frozenPayload = pendingPayload; selling = false; uncertain = true; sold = false;
            try { renderSaved(); notify(label('uncertain_sale'), false, true); lock(); } catch (_) { /* The pre-write storage still retains the original transaction. */ }
            throw new Error(label('uncertain_sale'));
        }
        printReceipt(url);
    }
    async function recoverSale() {
        if (!urls.daily || disposed || quarantined) return false;
        var recoveryInvoice = activeInvoice, recoveryKey = saleKey;
        var recoveryController = new AbortController(), recoveryTimeout = setTimeout(function () { recoveryController.abort(); }, 10000);
        try {
            var response = await fetch(endpoint(urls.daily, { branch: branch, idempotency_key: saleKey }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: recoveryController.signal }); var result = await read(response, 'daily_error');
            if (disposed || recoveryInvoice !== activeInvoice || recoveryKey !== saleKey) return false;
            var receipt = result.receipt || (result.items || result.receipts || []).find(function (item) { return item.idempotency_key === saleKey; });
            if (!receipt) return false; saved({ receipt: receipt, receipt_url: receipt.receipt_url, register: result.register, today: result.today }); return true;
        } catch (_) { return false; } finally { clearTimeout(recoveryTimeout); }
    }
    async function checkout() {
        if (quarantined) { notify(label('pending_branch_unavailable')); return; }
        if (registerLocked || selling || sold || !cart.length || !permissions.can_checkout) return;
        var previouslyUncertain = uncertain;
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
        if (disposed) return;
        var timeout, writeController = new AbortController(); timeout = setTimeout(function () { writeController.abort(); }, 25000);
        try {
            var response = await post(urls.checkout, frozenPayload, writeController.signal); var result = await read(response, 'sale_error'); if (disposed) return;
            if (!result.receipt || !result.receipt_url) { uncertain = true; throw new Error(label('uncertain_sale')); }
            saved(result);
        } catch (error) {
            if (disposed) return;
            if (previouslyUncertain || !error.status || error.status < 400 || error.status >= 500) { uncertain = true; notify(label('uncertain_sale'), false, true); }
            else { uncertain = false; frozenPayload = null; if (error.status === 409) { quote = null; totals(null); } notify(error.message || label('sale_error'), false, true); }
        } finally { clearTimeout(timeout); selling = false; lock(); }
    }
    function openModal(title) {
        modalGeneration++; if (modalController) modalController.abort();
        if (modal.hidden) modalFocus = document.activeElement; modal.hidden = false; modal.querySelector('h2').textContent = title; var content = root.querySelector('[data-pos-modal-content]'); content.replaceChildren(node('p', 'tp-empty', label('loading'))); modal.querySelector('[role="dialog"]').focus(); return content;
    }
    function closeModal(confirmedWrite) { if (registerLocked && confirmedWrite !== true) { notify(label('sale_locked')); return; } modalGeneration++; modal.hidden = true; if (modalController) modalController.abort(); if (modalFocus && modalFocus.isConnected) modalFocus.focus(); }
    function chooseProduct(product) {
        if (!product || isLocked()) return;
        if (!product.options || !product.options.length) { addProduct(product); return; }
        var content = openModal(label('choose_option') + ' · ' + product.name); content.replaceChildren(); var list = node('div', 'tp-option-list');
        [{ id: '', label: label('choose_base'), price: product.unit_price || product.price }].concat(product.options).forEach(function (option) {
            var button = node('button'); button.type = 'button'; button.appendChild(node('span', '', option.label)); button.appendChild(node('bdi', '', money(option.price))); button.addEventListener('click', function () { closeModal(); addProduct(product, option.id === '' ? null : option); }); list.appendChild(button);
        }); content.appendChild(list);
    }
    function receiptTable(items, content) {
        var table = node('table'), head = node('thead'), headings = node('tr'); ['invoice_number', 'channel', 'date', 'payment_method', 'total', 'print_invoice'].forEach(function (key) { headings.appendChild(node('th', '', label(key))); }); head.appendChild(headings); table.appendChild(head); var body = node('tbody');
        items.forEach(function (item) { var row = node('tr'); row.appendChild(node('td', '', item.number || item.id)); row.appendChild(node('td', '', label(item.channel || 'takeaway'))); row.appendChild(node('td', '', item.created_label || item.created_at || '')); row.appendChild(node('td', '', item.payment_label || label(item.payment_method === 'mobile_wallet' ? 'wallet' : item.payment_method))); row.appendChild(node('td', '', money(item.total))); var cell = node('td'); if (item.receipt_url) { var link = node('a', '', label('print_invoice')); link.href = endpoint(item.receipt_url); link.addEventListener('click', function (event) { event.preventDefault(); printReceipt(link.href); }); cell.appendChild(link); } row.appendChild(cell); body.appendChild(row); }); table.appendChild(body); content.appendChild(table);
    }
    async function daily(page) {
        if (quarantined) { notify(label('pending_branch_unavailable')); return; }
        if (registerLocked) { notify(label('sale_locked')); return; }
        if (!branch) { notify(label('choose_branch_first')); return; } var content = openModal(label('daily_invoices')), generation = modalGeneration; modalController = new AbortController();
        try { var response = await fetch(endpoint(urls.daily, { branch: branch, page: page || 1 }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: modalController.signal }); var result = await read(response, 'daily_error'); if (disposed || modal.hidden || generation !== modalGeneration) return; content.replaceChildren(); var items = result.items || result.receipts || []; if (!items.length) content.appendChild(node('p', 'tp-empty', label('daily_empty'))); else receiptTable(items, content); var pages = result.pagination || {}; if (pages.last_page > 1) { var controls = node('nav', 'tp-pagination'); ['previous', 'next'].forEach(function (direction) { var button = node('button', '', label(direction)); button.type = 'button'; button.disabled = direction === 'previous' ? pages.page <= 1 : pages.page >= pages.last_page; button.addEventListener('click', function () { daily(pages.page + (direction === 'next' ? 1 : -1)); }); controls.appendChild(button); }); content.appendChild(controls); } updateRegister(result.register, result.today); }
        catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(node('p', 'tp-empty', error.message || label('daily_error'))); }
    }
    async function till() {
        if (quarantined) { notify(label('pending_branch_unavailable')); return; }
        if (registerLocked) { notify(label('sale_locked')); return; }
        if (!branch) { notify(label('choose_branch_first')); return; } var content = openModal(label('cash_register')), generation = modalGeneration; modalController = new AbortController();
        try { var response = await fetch(endpoint(urls.register || urls.tills, { branch: branch }), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: modalController.signal }); var result = await read(response, 'register_error'); if (disposed || modal.hidden || generation !== modalGeneration) return; content.replaceChildren(); updateRegister(result.register || result, result.today); var summary = node('div', 'tp-modal-summary'); summary.appendChild(node('span', '', label('register_balance'))); summary.appendChild(node('strong', '', money(register && register.balance) + ' ' + label('currency'))); content.appendChild(summary);
            var rows = result.entries || result.movements || result.items || []; if (rows.length) { var table = node('table'), body = node('tbody'); rows.forEach(function (entry) { var row = node('tr'); row.appendChild(node('td', '', entry.created_label || entry.created_at)); row.appendChild(node('td', '', entry.note || entry.reason || label({ cash_sale: 'cash_sales', expense: 'entry_expense', expense_refund: 'entry_expense_refund', cash_in: 'cash_in', cash_out: 'cash_out', tax_setting: 'till_settings' }[entry.kind] || 'cash_movements'))); row.appendChild(node('td', '', money(entry.amount))); body.appendChild(row); }); table.appendChild(body); content.appendChild(table); } else content.appendChild(node('p', 'tp-empty', label('register_readonly')));
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
            var operationKey = uuid(), operationPayload = null, pending = false, previouslyAmbiguous = false;
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
                    try {
                        updateRegister(result.register); root.querySelector('[data-pos-modal-close]').disabled = false; closeModal(true);
                        if (kind === 'settings') { quote = null; invalidateQuote(); }
                        notify(label(kind === 'settings' ? 'settings_saved' : 'movement_saved'), true);
                        registerLocked = false; lock(); previouslyAmbiguous = false;
                    } catch (_) { previouslyAmbiguous = true; registerLocked = true; modal.hidden = false; root.querySelector('[data-pos-modal-close]').disabled = true; throw new Error(label('uncertain_sale')); }
                    if (kind === 'settings') loadCatalog(catalogPage);
                } catch (error) {
                    if (disposed || operationBranch !== branch) return;
                    if (previouslyAmbiguous || !error.status || error.status < 400 || error.status >= 500) { previouslyAmbiguous = true; registerLocked = true; feedback.textContent = label('uncertain_sale'); submit.textContent = label('retry'); submit.disabled = false; }
                    else { registerLocked = false; operationPayload = null; feedback.textContent = error.message || label('register_error'); forms.querySelectorAll('input,select,button').forEach(function (control) { control.disabled = false; }); root.querySelector('[data-pos-modal-close]').disabled = false; }
                } finally { clearTimeout(timeout); pending = false; lock(); }
            }); return form;
        }
        if (urls.settings) forms.appendChild(formFor('settings'));
        if (urls.movements) forms.appendChild(formFor('movements'));
        content.appendChild(forms);
    }
    function mayLeave() { if (selling || registerLocked || hasUncertainInvoices()) { notify(label('sale_locked'), false, uncertain); return false; } return !hasOpenInvoices() || window.confirm(label('unsaved_invoices')); }
    listen(root, 'click', function (event) {
        var button = event.target.closest('button'); if (!button || !root.contains(button)) return;
        if (button.dataset.posInvoice !== undefined) return switchInvoice(Number(button.dataset.posInvoice));
        if (button.hasAttribute('data-pos-finish')) return checkout();
        if (button.hasAttribute('data-pos-modal-close')) return closeModal();
        if (button.hasAttribute('data-pos-daily')) return daily();
        if (button.hasAttribute('data-pos-register')) return till();
        if (button.hasAttribute('data-pos-new')) { if (selling || uncertain || registerLocked) { notify(label('sale_locked'), false, uncertain); return; } resetDraft(); notify(''); return loadCatalog(catalogPage); }
        if (button.hasAttribute('data-pos-retry')) { if (uncertain) return checkout(); if (branch) return quote === null && cart.length && loaded ? requestQuote() : loadCatalog(catalogPage); }
        if (isLocked()) return;
        if (button.dataset.posKey !== undefined) return key(button.dataset.posKey);
        if (button.dataset.posUnit) { quantityMode = button.dataset.posUnit; quantityInput = quantityMode === 'piece' ? '1' : '0'; replaceQuantity = true; showQuantity(); return; }
        if (button.hasAttribute('data-pos-weight-reset')) { quantityInput = quantityMode === 'piece' ? '1' : '0'; replaceQuantity = true; showQuantity(); return; }
        if (button.dataset.posPayment) return setPayment(button.dataset.posPayment);
        if (button.dataset.posProduct) return addProduct(products.get(button.dataset.posProduct));
        if (button.dataset.posOptions) return chooseProduct(products.get(button.dataset.posOptions));
        if (button.dataset.posRemove !== undefined) { cart.splice(Number(button.dataset.posRemove), 1); renderLines(); invalidateQuote(); return; }
        if (button.hasAttribute('data-pos-clear')) { if (!cart.length || window.confirm(label('confirm_clear'))) { resetDraft(); notify(''); } return; }
        if (button.dataset.posCategory !== undefined) { category = category === button.dataset.posCategory ? '' : button.dataset.posCategory; return loadCatalog(1); }
        if (button.dataset.posPage) return loadCatalog(catalogPage + (button.dataset.posPage === 'next' ? 1 : -1));
    });
    listen(branchSelect, 'change', changeBranch);
    listen(root, 'input', function (event) { if (event.target.matches('input,textarea,select')) renderInvoiceTabs(); });
    listen(root, 'change', function (event) { if (event.target.matches('input,textarea,select')) renderInvoiceTabs(); });
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
    listen(window, 'beforeunload', function (event) { if (registerLocked || selling || hasUncertainInvoices() || hasOpenInvoices()) { event.preventDefault(); event.returnValue = ''; } });
    if (window.DashboardSPA && window.DashboardSPA.onBeforeLeave) { window.DashboardSPA.onBeforeLeave(mayLeave); navigationGuardRegistered = true; }
    listen(document, 'click', function (event) { if (navigationGuardRegistered || disposed) return; var link = event.target.closest('a[href]'); if (link && !root.contains(link) && link.target !== '_blank' && link.href.indexOf('#') < 0 && !mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () { disposed = true; catalogGeneration++; quoteGeneration++; clearTimeout(searchTimer); clearTimeout(quoteTimer); if (catalogController) catalogController.abort(); if (quoteController) quoteController.abort(); if (modalController) modalController.abort(); listeners.forEach(function (remove) { remove(); }); if (window.jQuery) window.jQuery(branchSelect).off('.takeawayPos'); });
    var restored = restoreInvoices(); storageReady = true;
    if (restored) switchInvoice(activeInvoice, true);
    else { renderLines(); renderPayments(); showQuantity(); lock(); if (branch) loadCatalog(1); }
}());
