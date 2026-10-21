(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.querySelector('#dining-pos');
    if (!root) return;
    var boot = JSON.parse(document.querySelector('#dining-bootstrap').textContent);
    var labels = JSON.parse(document.querySelector('#dining-translations').textContent);
    var urls = boot.urls || {}, branchSelect = root.querySelector('[data-dining-branch]');
    var branch = branchSelect.value, policy = boot.policy || {}, permissions = boot.permissions || {};
    if (permissions.can_operate === undefined) permissions.can_operate = !!permissions.can_checkout;
    var products = new Map(), tables = [], cart = [], selectedTable = null, ticket = null, quote = null;
    var category = '', catalogPage = 1, floorFilter = 'any', quantityMode = 'weight', quantityInput = '1', replaceQuantity = true;
    var disposed = false, dirty = false, pricingDirty = false, busy = false, uncertain = false, readingTicket = false, loaded = false;
    var operation = null, paymentMethod = 'cash', catalogGeneration = 0, tableGeneration = 0, ticketGeneration = 0, quoteGeneration = 0, modalGeneration = 0;
    var catalogController, tableController, ticketController, quoteController, modalController, quoteTimer, searchTimer, tableTimer, modalFocus, catalogTimer;
    var listeners = [], guardRegistered = false, activeWriteController;
    var message = root.querySelector('[data-dining-message]'), modal = root.querySelector('[data-dining-modal]');
    var customerInput = root.querySelector('[data-dining-customer]');
    var customerPhone = root.querySelector('[data-dining-customer-phone]'), customerConsent = root.querySelector('[data-dining-customer-consent]');
    var waiterInput = root.querySelector('[data-dining-waiter]'), guestsInput = root.querySelector('[data-dining-guests]');
    var notesInput = root.querySelector('[data-dining-notes]'), discountInput = root.querySelector('[data-dining-discount]');
    var reasonInput = root.querySelector('[data-dining-discount-reason]'), cashInput = root.querySelector('[data-dining-cash-received]');
    var confirmedInput = root.querySelector('[data-dining-payment-confirmed]');
    var saveButton = root.querySelector('[data-dining-save]'), settleButton = root.querySelector('[data-dining-settle]');
    var consentEnterAffirmed = false, enterKeyDown = false, composing = false, collectionOpen = false;

    function normalizedCustomerPhone(value) {
        var phone = String(value || '').trim().replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); });
        if (!phone || /[^+0-9\s().\-]/u.test(phone)) return null;
        phone = phone.replace(/[\s().\-]+/g, ''); if (phone.indexOf('00') === 0) phone = '+' + phone.slice(2);
        if (/^01[0125][0-9]{8}$/.test(phone)) phone = '+20' + phone.slice(1);
        else if (/^1[0125][0-9]{8}$/.test(phone)) phone = '+20' + phone;
        else if (/^0[2-9][0-9]{7,8}$/.test(phone)) phone = '+20' + phone.slice(1);
        else if (/^[1-9][0-9]{7,14}$/.test(phone)) phone = '+' + phone;
        if (!/^\+[1-9][0-9]{7,14}$/.test(phone) || phone.indexOf('+20') === 0 && !/^\+20(?:1[0125][0-9]{8}|[2-9][0-9]{7,8})$/.test(phone)) return null;
        return phone;
    }
    function focusKitchenAction() {
        if (!selectedTable || !cart.length || locked() || billLocked() || !!ticket && !openTicket()) return;
        if (!customerInput.value.trim()) { customerInput.focus(); return; }
        if (!waiterInput.value.trim()) { notify(label('waiter_required')); waiterInput.focus(); return; }
        var guests = Number(guestsInput.value);
        if (!Number.isInteger(guests) || guests < 1 || guests > Number(guestsInput.max)) { guestsInput.focus(); guestsInput.reportValidity(); return; }
        if (!quote) { notify(label('quote_required'), false, true); return; }
        var nextAction = kitchenSent() ? root.querySelector('[data-dining-request-bill]') : saveButton;
        if (nextAction && !nextAction.disabled && !nextAction.hidden) nextAction.focus();
    }
    function checkoutEnter(event) {
        if (event.key !== 'Enter') return false;
        var target = event.target;
        var nameField = target === customerInput, phoneField = target === customerPhone, consentField = target === customerConsent, cashField = target === cashInput;
        var metadataField = target === waiterInput || target === guestsInput, saveField = target === saveButton, settleField = target === settleButton;
        var retryField = target.hasAttribute('data-dining-retry'), editable = target.matches('input,textarea,select,[contenteditable]');
        var productButton = !!target.closest('[data-dining-product],[data-dining-options]'), keypad = !!target.closest('.dp-weight');
        var catalogSurface = !!target.closest('.dp-catalog') && !target.closest('button,a,input,textarea,select,[contenteditable]');
        var start = target === document.body || target === root || productButton || !editable && (keypad || catalogSurface);
        if (!nameField && !phoneField && !consentField && !cashField && !metadataField && !saveField && !settleField && !retryField && !start) return false;
        if (!cart.length && !keypad && !productButton && !cashField && !saveField && !settleField && !retryField) return false;
        if (composing || event.isComposing || event.keyCode === 229) { if (!editable || consentField) event.preventDefault(); return true; }
        // Retry only the existing immutable operation; the remaining ticket stays locked.
        var retryReady = retryField && uncertain && operation && !busy && !readingTicket && !target.disabled && !target.hidden;
        if (event.repeat || enterKeyDown || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey || target.disabled || !retryReady && (locked() || !permissions.can_operate)) {
            event.preventDefault(); return true;
        }
        enterKeyDown = true;
        if (!cart.length && productButton) return false;
        event.preventDefault();
        if (retryField) {
            if (uncertain && operation) commit(operation);
            else if (!uncertain) { if (cart.length && !quote) requestQuote(); else { loadCatalog(catalogPage); loadTables(); } }
            return true;
        }
        if (!cart.length && keypad) { key('confirm'); return true; }
        if (!selectedTable || !cart.length || !!ticket && !openTicket()) return true;
        if (saveField) { if (!billLocked() && !kitchenSent() && !saveButton.disabled && !saveButton.hidden) saveAndSend(); return true; }
        if (cashField || settleField) {
            if (!billLocked()) { notify(label('request_bill_first')); return true; }
            if (cashField && !collectionOpen) return true;
            if (!settleButton.disabled && !settleButton.hidden) settle();
            return true;
        }
        if (nameField) { consentEnterAffirmed = false; customerPhone.focus(); return true; }
        if (phoneField) {
            consentEnterAffirmed = false;
            if (!normalizedCustomerPhone(customerPhone.value)) { notify(label('customer_phone_required')); customerPhone.focus(); return true; }
            if (['customer_phone_required', 'consent_required'].some(function (key) { return message.querySelector('span').textContent === label(key); })) notify('');
            // Focusing first flushes the phone's change event, which clears the previous customer's approval.
            customerConsent.focus(); customerConsent.checked = true; return true;
        }
        if (consentField) {
            if (!customerConsent.checked) { notify(label('consent_required')); customerConsent.focus(); return true; }
            consentEnterAffirmed = false;
            if (!billLocked()) focusKitchenAction();
            else if (collectionOpen) { cashInput.focus(); cashInput.select(); }
            else if (!settleButton.disabled) settleButton.focus();
            return true;
        }
        if (metadataField) { consentEnterAffirmed = false; focusKitchenAction(); return true; }
        consentEnterAffirmed = false;
        if (customerInput.disabled || billLocked()) customerPhone.focus(); else customerInput.focus();
        return true;
    }
    function label(key) { return labels[key] || key; }
    function node(tag, className, text) { var element = document.createElement(tag); if (className) element.className = className; if (text !== undefined) element.textContent = String(text); return element; }
    function listen(target, type, fn, options) { target.addEventListener(type, fn, options); listeners.push(function () { target.removeEventListener(type, fn, options); }); }
    function uuid() { if (crypto.randomUUID) return crypto.randomUUID(); var bytes = new Uint8Array(16); crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128; var hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join(''); return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20); }
    function scaled(value, places) { var text = String(value === undefined || value === null ? '' : value).trim().replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/٫/g, '.'); var found = text.match(new RegExp('^([0-9]{1,10})(?:\\.([0-9]{1,' + places + '}))?$')); return found ? BigInt(found[1]) * 10n ** BigInt(places) + BigInt((found[2] || '').padEnd(places, '0') || '0') : null; }
    function decimal(value, places) { var factor = 10n ** BigInt(places), absolute = value < 0n ? -value : value; return (value < 0n ? '-' : '') + String(absolute / factor) + '.' + String(absolute % factor).padStart(places, '0'); }
    function money(value) { var text = String(value === undefined || value === null ? '' : value), negative = text.charAt(0) === '-'; var amount = scaled(negative ? text.slice(1) : text, 2); if (amount === null) return '—'; var parts = decimal(negative ? -amount : amount, 2).split('.'); return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + parts[1]; }
    function quantity(value, mode) { var amount = scaled(value, 3); if (amount === null || amount < 1n || amount > 1000000n || mode === 'piece' && amount % 1000n) return null; return { amount: amount, value: mode === 'piece' ? String(amount / 1000n) : decimal(amount, 3) }; }
    function unit(mode, product) { return label(product && ['piece', 'weight'].indexOf(product.quantity_mode) < 0 ? 'automatic_unit' : mode === 'weight' ? 'kg' : 'piece'); }
    function endpoint(url, params, id) { if (!url) throw new Error(label('load_error')); var text = String(url); if (id !== undefined) text = text.replace(/__TICKET__|__KITCHEN__|__ID__|\{id\}/g, String(id)); var target = new URL(text, location.href); if (target.origin !== location.origin) throw new Error(label('forbidden')); Object.keys(params || {}).forEach(function (key) { if (params[key] !== undefined && params[key] !== null && params[key] !== '') target.searchParams.set(key, params[key]); }); return target.href; }
    async function read(response, fallback) { var result; try { result = await response.json(); } catch (_) { result = null; } if (!response.ok || !result || result.success === false) { var error = new Error(response.status === 401 || response.status === 419 ? label('session_expired') : response.status === 403 ? label('forbidden') : response.status === 409 ? label('stale') : response.status >= 500 || !result ? label(fallback) : result.message || label(fallback)); error.status = response.status; throw error; } return result; }
    function get(url, params, signal, id) { return fetch(endpoint(url, params, id), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: signal }); }
    function post(url, payload, signal, id) { var csrf = document.querySelector('meta[name="csrf-token"]'); return fetch(endpoint(url, null, id), { method: 'POST', credentials: 'same-origin', signal: signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.content : '' }, body: JSON.stringify(payload) }); }
    function notify(text, success, retry) { if (disposed) return; message.hidden = !text; message.querySelector('span').textContent = text || ''; message.classList.toggle('is-success', !!success); message.querySelector('[data-dining-retry]').hidden = !retry; }
    function locked() { return busy || uncertain || readingTicket; }
    function openTicket() { return !!ticket && ticket.payment_status !== 'paid' && ticket.status !== 'paid' && ticket.status !== 'cancelled'; }
    function billLocked() { return !!ticket && (!!ticket.bill_locked || ticket.status === 'awaiting_bill'); }
    function kitchenSent() { return !!ticket && !!ticket.kitchen_sent; }
    function lock() {
        if (locked()) consentEnterAffirmed = false;
        root.classList.toggle('is-checkout-locked', locked());
        root.querySelectorAll('input,textarea,select,[data-dining-clear],[data-dining-key],[data-dining-weight-reset],[data-dining-product],[data-dining-options],[data-dining-remove],[data-dining-category],[data-dining-table],[data-dining-payment],[data-dining-settings]').forEach(function (control) { control.disabled = locked() || control.hasAttribute('data-unavailable') || (control.hasAttribute('data-dining-product') || control.hasAttribute('data-dining-options') || control.closest('.dp-weight')) && !permissions.can_operate || control.closest('.dp-invoice-scroll') && (!permissions.can_operate || !!ticket && !openTicket()); });
        root.querySelectorAll('[data-dining-modal-close]').forEach(function (control) { control.disabled = busy || uncertain; });
        if (branchSelect.selectize) { if (locked()) branchSelect.selectize.disable(); else branchSelect.selectize.enable(); }
        root.querySelector('[data-dining-save]').disabled = locked() || !permissions.can_operate || !selectedTable || !cart.length || !quote || kitchenSent() || !!ticket && (!openTicket() || billLocked());
        root.querySelector('[data-dining-save] span').textContent = label(busy ? 'sending' : 'send_kitchen');
        root.querySelectorAll('[data-dining-request-bill]').forEach(function (button) { button.disabled = locked() || !permissions.can_operate || !openTicket() || dirty && !quote || ticket.status === 'awaiting_bill'; });
        root.querySelector('[data-dining-cancel]').hidden = !openTicket() || kitchenSent() || billLocked(); root.querySelector('[data-dining-cancel]').disabled = locked() || !permissions.can_operate;
        root.querySelector('[data-dining-settle]').disabled = locked() || !permissions.can_operate || !openTicket() || !billLocked() || dirty || !quote || !cart.length;
        root.querySelector('[data-dining-clear]').disabled = locked() || !permissions.can_operate || !cart.length || !!ticket && (!openTicket() || billLocked());
        root.querySelectorAll('[data-dining-customer],[data-dining-waiter],[data-dining-guests],[data-dining-notes],[data-dining-discount],[data-dining-discount-reason],[data-dining-line-quantity],[data-dining-remove],[data-dining-product],[data-dining-options],[data-dining-key],[data-dining-weight-reset]').forEach(function (control) { control.disabled = control.disabled || billLocked() || !!ticket && !openTicket() || control.hasAttribute('data-dining-remove') && kitchenSent() && cart.length <= 1; });
        root.querySelectorAll('[data-dining-save]').forEach(function (control) { control.hidden = billLocked() || kitchenSent(); });
        root.querySelectorAll('[data-dining-request-bill],[data-dining-print-bill],[data-dining-print-kitchen]').forEach(function (control) { control.hidden = billLocked(); });
        root.querySelector('[data-dining-clear]').hidden = billLocked() || kitchenSent();
        root.querySelector('[data-dining-details]').disabled = locked() || !ticket;
        root.querySelector('[data-dining-key="."]').disabled = locked() || billLocked() || !permissions.can_operate;
        renderCollection();
        root.querySelector('[data-dining-state]').textContent = uncertain ? label('pending_write') : !selectedTable ? label('select_table') : dirty ? label('unsaved') : ticket ? (billLocked() ? label('bill_locked') : label('status_' + ticket.status)) + ' · ' + (ticket.payment_status === 'paid' ? label('status_paid') : label('pending_payment')) : label('status_free');
    }
    function payloadItems() { return cart.map(function (line) { var value = { product_id: Number(line.product.id), quantity: line.quantity, quantity_mode: line.mode }; if (line.option && line.option.id) value.option_id = String(line.option.id); return value; }); }
    function draft() { var amount = scaled(discountInput.value, 2); return { branch: branch, items: payloadItems(), discount: policy.can_discount ? amount === null ? discountInput.value : decimal(amount, 2) : ticket && !pricingDirty ? ticket.discount || '0.00' : '0.00', discount_reason: policy.can_discount ? reasonInput.value.trim() : ticket && !pricingDirty ? ticket.discount_reason || '' : '' }; }
    function lineSnapshot(line) { var optionId = String(line.option && line.option.id || ''); return quote && quote.items && quote.items.find(function (item) { return Number(item.product_id) === Number(line.product.id) && String(item.option_id || '') === optionId && item.quantity_mode === line.mode; }) || {}; }
    function renderLines() {
        var list = root.querySelector('[data-dining-lines]'); list.replaceChildren();
        cart.forEach(function (line, index) { var snapshot = lineSnapshot(line), row = node('tr'), itemCell = node('td'), copy = node('div', 'dp-line-product'); if (line.product.image_url) { var image = node('img'); image.src = line.product.image_url; image.alt = ''; image.addEventListener('error', function () { image.remove(); }, { once: true }); copy.appendChild(image); } var title = node('span'); var name = node('span', 'dp-line-name', snapshot.name || line.product.name); name.title = name.textContent; title.appendChild(name); var optionText = lineOptionLabel(line, snapshot); if (optionText) { var optionLabel = node('small', 'dp-line-option', optionText); optionLabel.title = optionLabel.textContent; title.appendChild(optionLabel); } copy.appendChild(title); itemCell.appendChild(copy); row.appendChild(itemCell); var amountCell = node('td'); var lineQuantity = node('input', 'dp-line-quantity-input'); lineQuantity.type = 'text'; lineQuantity.inputMode = 'decimal'; lineQuantity.maxLength = 8; lineQuantity.value = line.quantity; lineQuantity.dataset.diningLineQuantity = index; lineQuantity.setAttribute('aria-label', label('quantity') + ' ' + line.product.name); amountCell.appendChild(lineQuantity); amountCell.appendChild(node('small', 'dp-line-unit', unit(line.mode, line.product))); row.appendChild(amountCell); var price = snapshot.unit_price || line.option && line.option.price || line.product.unit_price || line.product.price; row.appendChild(node('td', '', money(price))); var cents = scaled(price, 2), amount = scaled(line.quantity, 3), total = snapshot.total !== undefined ? snapshot.total : cents !== null && amount !== null ? decimal((cents * amount + 500n) / 1000n, 2) : null; row.appendChild(node('td', 'dp-line-total', money(total))); var cell = node('td'), remove = node('button', 'dp-line-remove'); remove.type = 'button'; remove.dataset.diningRemove = index; remove.setAttribute('aria-label', label('remove_item') + ' ' + line.product.name); remove.appendChild(node('i', 'far fa-trash-alt')); remove.hidden = billLocked(); remove.disabled = locked() || !!ticket && (!openTicket() || billLocked()); cell.appendChild(remove); row.appendChild(cell); list.appendChild(row); }); root.querySelector('[data-dining-empty-invoice]').hidden = !!cart.length; renderDetail();
    }
    function renderTotals() { root.querySelectorAll('[data-dining-total]').forEach(function (element) { element.textContent = quote && quote[element.dataset.diningTotal] !== undefined ? money(quote[element.dataset.diningTotal]) : '—'; }); root.querySelector('[data-dining-tax-rate]').textContent = '(' + (quote && quote.tax_rate !== undefined ? quote.tax_rate : policy.tax_rate || '0.00') + '%)'; root.querySelector('[data-dining-service-rate]').textContent = '(' + (quote && quote.service_rate !== undefined ? quote.service_rate : policy.service_rate || '0.00') + '%)'; root.querySelector('[data-dining-service-row]').hidden = !quote || !scaled(quote.service, 2); showChange(); renderDetail(); lock(); }
    function changed(pricing) { consentEnterAffirmed = false; if (locked() || !!ticket && (!openTicket() || billLocked())) return; dirty = true; if (pricing) { pricingDirty = true; invalidateQuote(); } else lock(); }
    function invalidateQuote() { consentEnterAffirmed = false; quoteGeneration++; clearTimeout(quoteTimer); if (quoteController) quoteController.abort(); quote = null; renderTotals(); if (cart.length && branch && !locked()) quoteTimer = setTimeout(requestQuote, 160); }
    async function requestQuote() { if (!cart.length || !branch || disposed || locked() || billLocked()) return; var generation = ++quoteGeneration; if (quoteController) quoteController.abort(); quoteController = new AbortController(); try { var result = await read(await post(urls.quote, draft(), quoteController.signal), 'quote_error'); if (disposed || generation !== quoteGeneration) return; if (!result.quote_hash || !Array.isArray(result.items) || scaled(result.total, 2) === null || !result.branch || result.branch.value !== branch) throw new Error(label('quote_error')); quote = result; renderLines(); renderTotals(); if (message.querySelector('span').textContent === label('calculating')) notify(''); } catch (error) { if (disposed || generation !== quoteGeneration || error.name === 'AbortError') return; quote = null; renderTotals(); notify(error.message || label('quote_error'), false, true); } }
    function showQuantity() {
        root.querySelector('[data-dining-quantity] bdi').textContent = quantityInput;
        root.querySelector('[data-dining-quantity-title]').textContent = label('quantity_auto_title');
        root.querySelector('[data-dining-quantity-unit]').textContent = label('automatic_unit');
        root.querySelector('[data-dining-confirm-label]').textContent = label('confirm_amount');
        lock();
    }
    function key(value) {
        if (locked() || billLocked()) return;
        // The next product determines its own unit; the previous selection never blocks decimals.
        if (value === 'confirm') { var valid = !!quantity(quantityInput, 'weight'); notify(label(valid ? 'confirmed' : 'invalid_quantity'), valid); replaceQuantity = true; return; }
        if (value === 'clear') quantityInput = '0';
        else if (value === 'backspace') quantityInput = quantityInput.slice(0, -1) || '0';
        else if (value === '.') { if (replaceQuantity) quantityInput = '0'; if (quantityInput.indexOf('.') < 0) quantityInput += '.'; }
        else { if (replaceQuantity || quantityInput === '0') quantityInput = value; else if (quantityInput.length < 8 && (quantityInput.indexOf('.') < 0 || quantityInput.split('.')[1].length < 3)) quantityInput += value; }
        replaceQuantity = value === 'clear'; showQuantity();
    }
    function addProduct(product, option) {
        if (!product || locked() || !permissions.can_operate || !product.available || !!ticket && (!openTicket() || billLocked())) return;
        if (!selectedTable) { notify(label('select_table_first')); root.querySelector('[data-dining-tables]').scrollIntoView({ block: 'nearest' }); return; }
        // Explicit stock units are authoritative. Older untracked products retain the decimal
        // amount with weight mode and a generic displayed unit, without guessing from their names.
        var mode = product.quantity_mode === 'piece' ? 'piece' : 'weight', amount = quantity(quantityInput, mode);
        if (!amount) { notify(label('invalid_quantity')); return; }
        var optionId = String(option && option.id || ''), existing = cart.find(function (line) { return Number(line.product.id) === Number(product.id) && line.mode === mode && String(line.option && line.option.id || '') === optionId; });
        if (existing) { var total = scaled(existing.quantity, 3) + amount.amount, checked = quantity(decimal(total, 3), mode); if (!checked) { notify(label('invalid_quantity')); return; } existing.quantity = checked.value; }
        else { if (cart.length >= 100) { notify(label('cart_limit')); return; } cart.push({ product: product, option: option || null, quantity: amount.value, mode: mode }); }
        quantityMode = mode; replaceQuantity = true; dirty = true; pricingDirty = true; showQuantity(); renderLines(); invalidateQuote();
    }
    function productNode(product) { var wrapper = node('div', 'dp-product'), button = node('button', 'dp-product-main'); button.type = 'button'; button.dataset.diningProduct = product.id; button.disabled = !product.available || !permissions.can_operate || locked(); if (!product.available) button.dataset.unavailable = '1'; if (product.image_url) { var image = node('img', 'dp-product-image'); image.src = product.image_url; image.alt = ''; image.loading = 'lazy'; image.addEventListener('error', function () { image.replaceWith(node('span', 'dp-product-image dp-product-no-image', '◈')); }, { once: true }); button.appendChild(image); } else button.appendChild(node('span', 'dp-product-image dp-product-no-image', '◈')); var copy = node('span', 'dp-product-copy'); copy.appendChild(node('strong', 'dp-product-name', product.name)); copy.appendChild(node('bdi', 'dp-product-price', money(product.unit_price || product.price) + ' ' + label('currency'))); copy.appendChild(node('small', 'dp-product-unit' + (!product.available ? ' is-unavailable' : product.quantity_mode === 'weight' ? ' is-weight' : ''), product.stock ? (product.stock.label || 'الرصيد: '+product.stock.quantity+' '+product.stock.unit_label) : (!product.available ? label('unavailable') : product.quantity_mode === 'weight' ? label('weight') : product.quantity_mode === 'piece' ? label('piece') : label('automatic_unit')))); if(product.stock)copy.querySelector('small').style.color=product.stock.negative||product.stock.quantity==='0'?'#b42318':product.stock.tracked===false?'#64748b':'#158346';button.appendChild(copy); if (productChoices(product).length > 1) button.setAttribute('aria-haspopup', 'dialog'); wrapper.appendChild(button); return wrapper; }
    function applyPolicy(result) { policy = Object.assign({}, policy, result.policy || {}); permissions = Object.assign({}, permissions, result.permissions || {}); if (result.permissions && result.permissions.can_operate === undefined) permissions.can_operate = !!permissions.can_checkout; if (result.permissions) policy.can_discount = !!permissions.can_manage; root.querySelectorAll('[data-dining-settings]').forEach(function (button) { button.hidden = !permissions.can_manage_tables; }); root.querySelector('[data-dining-discount-wrap]').hidden = !policy.can_discount; root.querySelector('[data-dining-total="discount"]').hidden = !!policy.can_discount; if (!policy.can_discount) discountInput.value = '0.00'; root.querySelector('[data-dining-discount-reason-wrap]').hidden = !policy.can_discount || !(scaled(discountInput.value, 2) > 0n); if (result.register) updateRegister(result.register); if (result.today) { var count = root.querySelector('[data-dining-day-count]'); count.hidden = false; count.textContent = result.today.count || 0; } }
    function cancelCatalogNavigation() {
        clearTimeout(catalogTimer); catalogTimer = null; clearTimeout(searchTimer); searchTimer = null;
    }
    function selectCatalogCategory(value) {
        if (!branch || disposed || locked()) return;
        cancelCatalogNavigation(); category = category === String(value) ? '' : String(value);
        root.querySelectorAll('[data-dining-category]').forEach(function (button) {
            var selected = button.dataset.diningCategory === category;
            button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected));
        });
        loaded = false; var generation = ++catalogGeneration, selectedBranch = branch;
        if (catalogController) catalogController.abort();
        root.querySelector('[data-dining-products]').setAttribute('aria-busy', 'true');
        root.querySelector('[data-dining-catalog-message]').hidden = false; root.querySelector('[data-dining-catalog-message]').textContent = label('loading');
        catalogTimer = setTimeout(function () {
            catalogTimer = null;
            if (disposed || generation !== catalogGeneration || branch !== selectedBranch || locked()) return;
            loadCatalog(1, true);
        }, 80);
    }
    async function loadCatalog(page, catalogOnly) { cancelCatalogNavigation(); if (!branch || disposed || locked()) return; loaded = false; var generation = ++catalogGeneration; if (catalogController) catalogController.abort(); catalogController = new AbortController(); var list = root.querySelector('[data-dining-products]'), status = root.querySelector('[data-dining-catalog-message]'); list.setAttribute('aria-busy', 'true'); status.hidden = false; status.textContent = label('loading'); try { var result = await read(await get(urls.catalog, { branch: branch, search: root.querySelector('[data-dining-search]').value.trim(), category_id: category, page: page || 1, per_page: 24, catalog_only: catalogOnly ? 1 : undefined }, catalogController.signal), 'load_error'); if (disposed || generation !== catalogGeneration) return; var items = result.items || result.products; if (!Array.isArray(items) || result.ready === false || !result.branch || result.branch.value !== branch) throw new Error(label('load_error')); applyPolicy(result); loaded = true; products.clear(); list.replaceChildren(); items.forEach(function (item) { products.set(String(item.id), item); list.appendChild(productNode(item)); }); var categories = root.querySelector('[data-dining-categories]'); categories.replaceChildren(); (result.categories || []).forEach(function (item) { var button = node('button', '', item.name || item.label); button.type = 'button'; button.dataset.diningCategory = String(item.id); button.classList.toggle('is-active', String(item.id) === category); button.setAttribute('aria-pressed', String(String(item.id) === category)); categories.appendChild(button); }); var pagination = result.pagination || {}; catalogPage = Number(pagination.page || 1); root.querySelector('[data-dining-pagination]').hidden = !pagination.last_page || Number(pagination.last_page) < 2; root.querySelector('[data-dining-page-label]').textContent = catalogPage + ' / ' + (pagination.last_page || 1); root.querySelector('[data-dining-page="previous"]').disabled = catalogPage <= 1; root.querySelector('[data-dining-page="next"]').disabled = catalogPage >= Number(pagination.last_page || 1); status.hidden = !!items.length; status.textContent = label('no_products'); if (cart.length) renderLines(); lock(); if (!permissions.can_operate) notify(label('access_required')); } catch (error) { if (disposed || generation !== catalogGeneration || error.name === 'AbortError') return; loaded = false; products.clear(); list.replaceChildren(); status.hidden = false; status.textContent = error.message || label('load_error'); notify(error.message || label('load_error'), false, true); } finally { if (generation === catalogGeneration) list.setAttribute('aria-busy', 'false'); } }
    function opened(value) { var date = new Date(value); return Number.isFinite(date.getTime()) ? date.toLocaleTimeString(document.documentElement.lang || 'ar', { hour: '2-digit', minute: '2-digit' }) : '—'; }
    function minutes(created) { if (!created) return '—'; var timestamp = new Date(created).getTime(); return Number.isFinite(timestamp) ? Math.max(0, Math.floor((Date.now() - timestamp) / 60000)) + ' ' + label('minutes') : '—'; }
    function renderTables() { var container = root.querySelector('[data-dining-tables]'), search = root.querySelector('[data-dining-table-search]').value.trim().toLocaleLowerCase(); container.replaceChildren(); tables.filter(function (table) { return table.active && (floorFilter === 'any' || table.status === floorFilter) && String(table.name + ' ' + (table.branch_name || '') + ' ' + (table.ticket && table.ticket.customer_name || '')).toLocaleLowerCase().indexOf(search) >= 0; }).forEach(function (table) { var button = node('button', 'dp-table is-' + table.status + (selectedTable && Number(table.id) === Number(selectedTable.id) ? ' is-selected' : '')); button.type = 'button'; button.dataset.diningTable = table.id; button.setAttribute('aria-pressed', String(!!selectedTable && Number(table.id) === Number(selectedTable.id))); button.appendChild(node('strong', 'dp-table-number', table.name)); var copy = node('span', 'dp-table-copy'); if (!branch && table.branch_name) copy.appendChild(node('strong', '', table.branch_name)); copy.appendChild(node('span', '', label('status_' + table.status))); if (table.ticket) { copy.appendChild(node('strong', 'dp-table-customer', table.ticket.customer_name || '')); copy.appendChild(node('strong', '', money(table.ticket.total))); copy.appendChild(node('small', '', minutes(table.ticket.opened_at || table.ticket.created_at))); } else { var icon = node('i', 'fas fa-chair dp-table-icon'); icon.setAttribute('aria-hidden', 'true'); copy.appendChild(icon); } button.appendChild(copy); button.disabled = locked(); container.appendChild(button); }); var help = root.querySelector('[data-dining-floor-help]'); help.textContent = !tables.some(function (table) { return table.active; }) ? label('tables_empty') : label('select_table'); }
    function renderDetail() { root.querySelector('[data-dining-detail-title]').textContent = selectedTable ? label('table') + ' ' + selectedTable.name : label('select_table'); }
    async function loadTables() { if (!branch && !boot.can_view_all_branches || disposed || busy || uncertain) return; var generation = ++tableGeneration; if (tableController) tableController.abort(); tableController = new AbortController(); try { var result = await read(await get(urls.tables, { branch: branch || 'all' }, tableController.signal), 'tables_error'); if (disposed || generation !== tableGeneration) return; if (!Array.isArray(result.tables)) throw new Error(label('tables_error')); if (result.branch && result.branch.value !== branch) throw new Error(label('tables_error')); tables = result.tables; applyPolicy(result); renderTables(); lock(); } catch (error) { if (disposed || generation !== tableGeneration || error.name === 'AbortError') return; notify(error.message || label('tables_error'), false, true); } }
    function resetInvoice() { quantityMode = 'weight'; quantityInput = '1'; replaceQuantity = true; ticket = null; selectedTable = null; cart = []; quote = null; dirty = false; pricingDirty = false; quoteGeneration++; ticketGeneration++; if (quoteController) quoteController.abort(); if (ticketController) ticketController.abort(); clearTimeout(quoteTimer); customerInput.value = ''; customerPhone.value = ''; customerConsent.checked = false; consentEnterAffirmed = false; waiterInput.value = boot.cashier && boot.cashier.name || ''; guestsInput.value = '1'; notesInput.value = ''; discountInput.value = '0.00'; reasonInput.value = ''; cashInput.value = ''; collectionOpen = false; confirmedInput.checked = false; root.querySelector('[data-dining-payment-reference]').value = ''; renderLines(); renderTotals(); renderTables(); showQuantity(); }
    function validTicket(value, operationBranch) { return value && value.channel === 'dine' && Number.isSafeInteger(Number(value.id)) && Number(value.id) > 0 && Number.isSafeInteger(Number(value.revision)) && Number(value.revision) > 0 && value.branch && value.branch.value === operationBranch && Array.isArray(value.items) && value.table && Number(value.table.id) > 0 && scaled(value.total, 2) !== null; }
    function hydrate(value) { if (!validTicket(value, branch)) throw new Error(label('load_error')); ticket = value; selectedTable = value.table; guestsInput.max = Math.min(200, Number(value.table.capacity) || 200); cart = value.items.map(function (item) { var product = products.get(String(item.product_id)) || { id: item.product_id, name: item.name, unit_price: item.unit_price, available: true }; var option = item.option_id || item.option_label ? { id: item.option_id || '', label: item.option_label || '', price: item.unit_price } : null; return { product: product, option: option, quantity: item.quantity, mode: item.quantity_mode }; }); customerInput.value = value.customer_name || ''; customerPhone.value = value.customer_phone || ''; customerConsent.checked = false; consentEnterAffirmed = false; waiterInput.value = value.waiter_name || ''; guestsInput.value = value.guest_count || 1; notesInput.value = value.notes || ''; discountInput.value = value.discount || '0.00'; reasonInput.value = value.discount_reason || ''; root.querySelector('[data-dining-discount-reason-wrap]').hidden = !policy.can_discount || !(scaled(discountInput.value, 2) > 0n); quote = value.quote_hash ? value : null; dirty = false; pricingDirty = false; cashInput.value = ''; collectionOpen = false; confirmedInput.checked = false; renderLines(); renderTotals(); renderTables(); }
    function mayDiscard() { if (busy || uncertain || readingTicket) { notify(label('write_locked'), false, uncertain); return false; } return !dirty || window.confirm(label('unsaved_invoice')); }
    async function openTable(table) { if (!table || !mayDiscard()) return; if (table.branch && table.branch !== branch) { if (!(boot.branches || []).some(function (allowed) { return allowed.value === table.branch; })) return; resetInvoice(); branchSelect.value = table.branch; if (branchSelect.selectize) branchSelect.selectize.setValue(table.branch, true); await changeBranch(); if (disposed || branch !== table.branch) return; } resetInvoice(); selectedTable = table; guestsInput.max = Math.min(200, Number(table.capacity) || 200); renderDetail(); renderTables(); if (!table.ticket_id && !table.ticket) { lock(); notify(''); openCustomer(); return; } var id = table.ticket_id || table.ticket.id; readingTicket = true; lock(); var generation = ++ticketGeneration; ticketController = new AbortController(); try { var result = await read(await get(urls.show, { branch: branch }, ticketController.signal, id), 'load_error'); if (disposed || generation !== ticketGeneration) return; hydrate(result.ticket || result); notify(''); } catch (error) { if (disposed || generation !== ticketGeneration || error.name === 'AbortError') return; selectedTable = null; notify(error.message || label('load_error'), false, true); } finally { if (generation === ticketGeneration) { readingTicket = false; lock();  } } }
    function openCustomer() {
        var content = openModal(label('open_table') + ' · ' + selectedTable.name), form = node('form', 'dp-settings-form');
        content.replaceChildren(); var name = input('text', customerInput.value); name.required = true; name.maxLength = 100; name.dataset.diningOpenCustomer = '';
        form.appendChild(field('customer_name', name)); var submit = node('button', '', label('start_products')); submit.type = 'submit'; form.appendChild(submit); content.appendChild(form); name.focus();
        form.addEventListener('submit', function (event) { event.preventDefault(); if (!name.value.trim() || locked()) return; customerInput.value = name.value.trim(); changed(false); closeModal(); renderDetail(); root.querySelector('[data-dining-search]').focus(); });
    }
    function cancelInvoice() {
        if (locked() || billLocked() || kitchenSent() || !openTicket() || !permissions.can_operate || !mayDiscard()) return;
        var content = openModal(label('cancel_invoice')), reason = node('textarea'), form = node('form', 'dp-settings-form'); content.replaceChildren(); reason.maxLength = 500; reason.required = true; form.appendChild(field('cancel_reason', reason)); var submit = node('button', '', label('cancel_confirm')); submit.type = 'submit'; form.appendChild(submit); content.appendChild(form);
        var currentId = ticket.id, currentRevision = ticket.revision, currentHash = ticket.quote_hash;
        form.addEventListener('submit', function (event) { event.preventDefault(); if (locked() || !reason.value.trim()) return; submit.disabled = true; dirty = false; modal.hidden = true; modalGeneration++; commit({ kind: 'action', id: currentId, payload: { branch: branch, expected_revision: currentRevision, idempotency_key: uuid(), action: 'cancel', reason: reason.value.trim() }, expectedQuoteHash: currentHash }); });
    }
    function renderPayments() { var list = root.querySelector('[data-dining-payments]'); list.replaceChildren(); var icons = { cash: 'far fa-money-bill-alt', card: 'far fa-credit-card', mobile_wallet: 'fas fa-mobile-alt', mixed: 'fas fa-money-check-alt' }; ['cash'].forEach(function (method) { var button = node('button', 'dp-payment'); button.type = 'button'; button.dataset.diningPayment = method; button.appendChild(node('i', icons[method])); button.appendChild(node('span', '', label(method === 'mobile_wallet' ? 'wallet' : method))); list.appendChild(button); }); setPayment('cash'); }
    function setPayment(method) { if (locked() || method !== 'cash') return; paymentMethod = method; root.querySelectorAll('[data-dining-payment]').forEach(function (button) { var active = button.dataset.diningPayment === method; button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active)); }); renderCollection(); root.querySelector('[data-dining-payment-confirm-wrap]').hidden = method === 'cash'; root.querySelector('[data-dining-reference-wrap]').hidden = method === 'cash'; confirmedInput.checked = false; showChange(); }
    function renderCollection() { var visible = collectionOpen && billLocked() && openTicket() && permissions.can_operate; root.querySelector('[data-dining-cash-wrap]').hidden = !visible; cashInput.disabled = !visible || locked(); settleButton.setAttribute('aria-expanded', String(!!visible)); showChange(); }
    function showChange() { var total = quote && scaled(quote.total, 2), received = scaled(cashInput.value, 2); root.querySelector('[data-dining-change]').textContent = collectionOpen && received !== null && total !== null && received >= total ? label('change') + ': ' + money(decimal(received - total, 2)) : ''; }
    async function print(url) { if (!url) return; try { if (!window.DashboardPrint) throw new Error(label('print_error')); await window.DashboardPrint.print(endpoint(url)); } catch (_) { notify(label('print_error')); } }
    function issueBill() {
        if (locked() || !ticket) return;
        if (billLocked() || ticket.payment_status === 'paid') return;
        if (dirty) return saveTicket('request_bill');
        return runAction('request_bill');
    }
    function validateOperationResult(result, op) {
        var evidence = result && result.operation;
        if (!result || !validTicket(result.ticket, op.payload.branch) || typeof result.replayed !== 'boolean'
            || !evidence || evidence.idempotency_key !== op.payload.idempotency_key || evidence.kind !== op.kind
            || Number(evidence.ticket_id) !== Number(result.ticket.id) || !Number.isSafeInteger(Number(evidence.revision))
            || Number(evidence.revision) !== (op.payload.expected_revision ? Number(op.payload.expected_revision) + 1 : 1)
            || Number(result.ticket.revision) < Number(evidence.revision)) throw new Error(label('pending_write'));
        if (op.id && Number(result.ticket.id) !== Number(op.id) || op.payload.ticket_id && Number(result.ticket.id) !== Number(op.payload.ticket_id)) throw new Error(label('pending_write'));
        var superseded = result.replayed && Number(result.ticket.revision) > Number(evidence.revision);
        if (op.kind === 'save') {
            if (Number(result.ticket.table.id) !== Number(op.payload.table_id)) throw new Error(label('pending_write'));
            if (!superseded) {
                if (result.ticket.quote_hash !== op.payload.quote_hash || scaled(result.ticket.total, 2) !== op.expectedTotal
                    || result.ticket.items.length !== op.payload.items.length || result.ticket.waiter_name !== op.payload.waiter_name || Number(result.ticket.guest_count) !== op.payload.guest_count || (result.ticket.notes || '') !== op.payload.notes) throw new Error(label('pending_write'));
                op.payload.items.forEach(function (expected) { var actual = result.ticket.items.find(function (item) { return Number(item.product_id) === Number(expected.product_id) && String(item.option_id || '') === String(expected.option_id || '') && item.quantity_mode === expected.quantity_mode; }); if (!actual || scaled(actual.quantity, 3) !== scaled(expected.quantity, 3)) throw new Error(label('pending_write')); });
            }
        }
        if (!superseded && op.expectedQuoteHash && result.ticket.quote_hash !== op.expectedQuoteHash) throw new Error(label('pending_write'));
        if (op.kind === 'action' && op.payload.action === 'request_bill' && !result.ticket.bill_locked) throw new Error(label('pending_write'));
        if (op.kind !== 'settle') return;
        var receipt = result.receipt, printURL = result.first_print_url || result.receipt_url, printable = printURL && new URL(endpoint(printURL));
        var path = printable && printable.pathname.match(/\/takeaway\/receipts\/([1-9][0-9]*)\/print\/?$/);
        if (result.ticket.payment_status !== 'paid' || !receipt || !Number.isSafeInteger(Number(receipt.id)) || Number(receipt.id) < 1
            || Number(receipt.ticket_id) !== Number(op.id) || receipt.channel !== 'dine'
            || !receipt.branch || receipt.branch.value !== op.payload.branch || receipt.idempotency_key !== op.payload.idempotency_key
            || receipt.payment_method !== op.payload.payment_method || scaled(receipt.total, 2) === null || scaled(receipt.total, 2) !== op.expectedTotal
            || printURL && (!path || Number(path[1]) !== Number(receipt.id))) throw new Error(label('pending_write'));
        if (op.payload.payment_method === 'mixed') {
            if (!Array.isArray(receipt.payment_breakdown) || receipt.payment_breakdown.length !== op.payload.tenders.length) throw new Error(label('pending_write'));
            op.payload.tenders.forEach(function (expected) { var actual = receipt.payment_breakdown.find(function (part) { return part.method === expected.method; }); if (!actual || scaled(actual.amount, 2) !== scaled(expected.amount, 2)) throw new Error(label('pending_write')); });
        }
    }
    async function recover(op) { var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 10000); try { var result = await read(await get(urls.recover, { branch: op.payload.branch, idempotency_key: op.payload.idempotency_key }, controller.signal), 'save_error'); if (disposed) return null; if (!result.found) return null; validateOperationResult(result, op); return result; } catch (_) { return null; } finally { clearTimeout(timeout); } }
    async function commit(op) {
        if (disposed || busy || readingTicket || operation && operation !== op) return;
        operation = op; busy = true; lock(); notify(label('sending'));
        quoteGeneration++; if (quoteController) quoteController.abort(); clearTimeout(quoteTimer); tableGeneration++; if (tableController) tableController.abort(); catalogGeneration++; if (catalogController) catalogController.abort(); root.querySelector('[data-dining-products]').setAttribute('aria-busy', 'false');
        var result, timeout, controller = new AbortController(); activeWriteController = controller; var continuation, wasUncertain = uncertain, committed = false;
        try {
            if (uncertain) result = await recover(op);
            if (!result) { timeout = setTimeout(function () { controller.abort(); }, 25000); result = await read(await post(urls[op.kind], op.payload, controller.signal, op.id), 'save_error'); if (disposed) return; validateOperationResult(result, op); }
            if (disposed || branch !== op.payload.branch) return;
            hydrate(result.ticket); applyPolicy(result); continuation = Number(result.ticket.revision) === Number(result.operation.revision) ? op.after : null;
            notify(label(op.kind === 'settle' ? 'settled' : op.kind === 'action' ? op.payload.action === 'send_kitchen' ? 'sent_kitchen' : op.payload.action === 'cancel' ? 'cancelled' : 'bill_requested' : 'saved'), true);
            if (Number(result.ticket.revision) !== Number(result.operation.revision)) notify(label('concurrent_update'));
            if (op.kind === 'action' && op.payload.action === 'send_kitchen') print(result.kitchen_print_url || result.ticket.kitchen_print_url);
            if (op.kind === 'action' && op.payload.action === 'request_bill') print(result.ticket.receipt_url || result.ticket.bill_print_url);
            if (op.kind === 'settle') { resetInvoice(); } if (op.kind === 'action' && op.payload.action === 'cancel') { resetInvoice(); modal.hidden = true; modalGeneration++; } uncertain = false; operation = null; committed = true;
        } catch (error) {
            if (disposed) return;
            if (wasUncertain || !error.status || error.status < 400 || error.status >= 500) { uncertain = true; notify(label('pending_write'), false, true); }
            else { uncertain = false; operation = null; if (error.status === 409) quote = null; notify(error.message || label('save_error'), false, true); }
        } finally { clearTimeout(timeout); if (activeWriteController === controller) activeWriteController = null; busy = false; lock(); }
        if (!uncertain && continuation && ticket && openTicket()) { await runAction(continuation); }
        if (!busy && !uncertain) { loadTables(); if (committed && op.kind === 'settle') loadCatalog(catalogPage); }
    }
    function saveAndSend() { if (kitchenSent()) return; return saveTicket('send_kitchen'); }
    function saveTicket(after) { if (billLocked() || locked() || !permissions.can_operate || !selectedTable || !cart.length) return; if (!quote) { notify(label('quote_required'), false, true); return; } if (!customerInput.value.trim()) { openCustomer(); return; } if (!waiterInput.value.trim()) { notify(label('waiter_required')); waiterInput.focus(); return; } if (customerConsent.checked && !normalizedCustomerPhone(customerPhone.value)) { notify(label('customer_phone_required')); customerPhone.focus(); return; } var guests = Number(guestsInput.value); if (!Number.isInteger(guests) || guests < 1 || guests > Number(guestsInput.max)) { guestsInput.reportValidity(); return; } var payload = Object.assign(draft(), { table_id: selectedTable.id, customer_name: customerInput.value.trim(), customer_phone: customerPhone.value.trim() || null, service_consent: customerConsent.checked, marketing_consent: false, waiter_name: waiterInput.value.trim(), guest_count: guests, notes: notesInput.value.trim(), quote_hash: quote.quote_hash, idempotency_key: uuid() }); if (ticket) { payload.ticket_id = ticket.id; payload.expected_revision = ticket.revision; if (pricingDirty) payload.reprice = true; } commit({ kind: 'save', id: null, payload: payload, after: after, expectedTotal: scaled(quote.total, 2) }); }
    function runAction(action) { if (action === 'send_kitchen' && kitchenSent() || billLocked() || locked() || !openTicket() || !permissions.can_operate) return; if (dirty) { notify(label('save_first')); return; } return commit({ kind: 'action', id: ticket.id, payload: { branch: branch, expected_revision: ticket.revision, idempotency_key: uuid(), action: action }, expectedQuoteHash: ticket.quote_hash }); }
    function settle() {
        if (locked() || !openTicket() || !permissions.can_operate || !quote) return;
        if (dirty) { notify(label('save_first')); return; }
        if (!billLocked()) { notify(label('request_bill_first')); return; }
        if (!collectionOpen) { collectionOpen = true; cashInput.value = ''; lock(); notify(''); cashInput.focus(); cashInput.select(); return; }
        var phone = normalizedCustomerPhone(customerPhone.value); if (!phone) { notify(label('customer_phone_required')); customerPhone.focus(); return; }
        if (!customerConsent.checked) { notify(label('consent_required')); customerConsent.focus(); return; }
        var total = scaled(quote.total, 2), received = scaled(cashInput.value, 2);
        if (received === null || received < total) { notify(label(received === null ? 'invalid_money' : 'cash_insufficient')); cashInput.focus(); return; }
        var payload = { branch: branch, expected_revision: ticket.revision, idempotency_key: uuid(), quote_hash: quote.quote_hash, customer_phone: phone, service_consent: customerConsent.checked, marketing_consent: false, payment_method: 'cash', cash_received: decimal(received, 2), payment_confirmed: true, payment_reference: '' };
        commit({ kind: 'settle', id: ticket.id, payload: payload, expectedTotal: total });
    }
    function openModal(title) { consentEnterAffirmed = false; modalGeneration++; if (modalController) modalController.abort(); if (modal.hidden) modalFocus = document.activeElement; modal.hidden = false; modal.querySelector('h2').textContent = title; var content = root.querySelector('[data-dining-modal-content]'); content.replaceChildren(node('p', 'dp-empty', label('loading'))); modal.querySelector('[role="dialog"]').focus(); return content; }
    function closeModal() { if (busy || uncertain) { notify(label('write_locked')); return; } modalGeneration++; if (modalController) modalController.abort(); modal.hidden = true; if (modalFocus && modalFocus.isConnected) modalFocus.focus(); }
    function productChoices(product) {
        // Portion size stays in the keypad; these are full-unit preparation prices.
        if (Array.isArray(product.preparation_options)) return product.preparation_options;
        return [{ id: '', label: label('choose_base'), price: product.unit_price || product.price }].concat(product.options || []);
    }
    function lineOptionLabel(line, snapshot) {
        if (snapshot.option_label) return snapshot.option_label;
        if (line.option && line.option.label) return line.option.label;
        if (line.option && line.option.id) return '';
        var product = products.get(String(line.product.id)) || line.product, choices = product.preparation_options;
        var whole = Array.isArray(choices) && choices.length > 1 && choices.find(function (option) { return option.id === ''; });
        return whole ? whole.label : '';
    }
    function chooseOption(product) {
        if (!product || locked() || billLocked() || !product.available || !permissions.can_operate) return;
        if (!selectedTable) { notify(label('select_table_first')); return; }
        var choices = productChoices(product);
        if (choices.length <= 1) { addProduct(product); return; }
        var content = openModal(label('choose_option') + ' · ' + product.name), generation = modalGeneration, selected = false;
        var list = node('div', 'dp-option-list'); content.replaceChildren();
        choices.forEach(function (option) {
            var button = node('button'); button.type = 'button';
            button.appendChild(node('span', '', option.label));
            button.appendChild(node('bdi', '', money(option.price) + ' ' + label('currency')));
            button.addEventListener('click', function () {
                if (selected || modal.hidden || generation !== modalGeneration || locked() || billLocked()) return;
                selected = true; closeModal(); addProduct(product, option.id || Array.isArray(product.preparation_options) ? option : null);
            }); list.appendChild(button);
        }); content.appendChild(list); list.firstElementChild.focus();
    }
    async function daily(page) { if (locked()) return; if (!branch) { notify(label('choose_branch_first')); return; } var content = openModal(label('daily_invoices')), generation = modalGeneration; modalController = new AbortController(); try { var result = await read(await get(urls.tickets, { branch: branch, page: page || 1, per_page: 40 }, modalController.signal), 'daily_error'); if (disposed || generation !== modalGeneration) return; var items = result.items || result.tickets; if (!Array.isArray(items)) throw new Error(label('daily_error')); content.replaceChildren(); if (!items.length) { content.appendChild(node('p', 'dp-empty', label('daily_empty'))); return; } var table = node('table'), head = node('thead'), headings = node('tr'); ['invoice_number', 'table', 'customer_name', 'status', 'total', 'open_invoice'].forEach(function (key) { headings.appendChild(node('th', '', label(key))); }); head.appendChild(headings); table.appendChild(head); var body = node('tbody'); items.forEach(function (item) { var row = node('tr', 'dp-daily-row'); [item.number || item.id, item.table && item.table.name || '—', item.customer_name || '—', label('status_' + item.status), money(item.total)].forEach(function (text) { row.appendChild(node('td', '', text)); }); var cell = node('td'), button = node('button', '', item.payment_status === 'paid' ? label('print_bill') : label('open_invoice')); button.type = 'button'; button.addEventListener('click', function () { if (item.payment_status === 'paid') return print(item.receipt_url || item.bill_print_url); closeModal(); openTable({ id: item.table.id, name: item.table.name, ticket_id: item.id, active: true }); }); if (item.status !== 'cancelled' && (item.payment_status !== 'paid' || item.can_reprint !== false && permissions.can_reprint !== false && (!boot.permissions || boot.permissions.can_reprint !== false) && (item.receipt_url || item.bill_print_url))) cell.appendChild(button); var details = node('button', 'dashboard-details-button', label('details')); details.type = 'button'; details.addEventListener('click', function () { window.DashboardInvoiceDetails.open(endpoint(item.details_url)); }); cell.appendChild(details); if (item.payment_status !== 'paid' && item.status !== 'cancelled') { var bill = node('button', '', label('print_bill')); bill.type = 'button'; bill.addEventListener('click', async function () { closeModal(); await openTable({ id: item.table.id, name: item.table.name, ticket_id: item.id, active: true }); if (ticket && Number(ticket.id) === Number(item.id)) issueBill(); }); cell.appendChild(bill); } row.appendChild(cell); body.appendChild(row); }); table.appendChild(body); content.appendChild(table); var pages = result.pagination || {}; if (Number(pages.last_page) > 1) { var pager = node('nav', 'dp-pagination'); ['previous', 'next'].forEach(function (direction) { var control = node('button', '', label(direction)); control.type = 'button'; control.disabled = direction === 'previous' ? Number(pages.page) <= 1 : Number(pages.page) >= Number(pages.last_page); control.addEventListener('click', function () { daily(Number(pages.page) + (direction === 'next' ? 1 : -1)); }); pager.appendChild(control); }); content.appendChild(pager); } } catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(node('p', 'dp-empty', error.message || label('daily_error'))); } }
    function updateRegister(register) { var el=root.querySelector('[data-dining-register-balance]');el.hidden=!register||register.balance===undefined;el.textContent=register&&register.balance!==undefined?money(register.balance):''; }
    function showRegister() {
        if (locked()) return;
        if (!branch) { notify(label('choose_branch_first')); return; }
        var content = openModal(label('cash_register'));
        var notice = node('p', 'dp-modal-summary');
        notice.appendChild(node('strong', '', label('register_print_only')));
        content.replaceChildren(notice);
    }
    function field(key, input) { var wrapper = node('label'); wrapper.appendChild(node('span', '', label(key))); wrapper.appendChild(input); return wrapper; }
    function input(type, value) { var element = node('input'); element.type = type || 'text'; if (value !== undefined) element.value = value; return element; }
    function validateConfiguration(response, frozen, settingsBranch) {
        var evidence = response && response.operation, payload = frozen.payload, kind = frozen.kind;
        var expectedRevision = payload.expected_revision ? Number(payload.expected_revision) + 1 : 1;
        if (!response || !response.success || !response.branch || response.branch.value !== settingsBranch || typeof response.replayed !== 'boolean'
            || !evidence || evidence.idempotency_key !== payload.idempotency_key || evidence.kind !== (kind === 'settings' ? 'service_setting' : 'table_config')
            || Number(evidence.revision) !== expectedRevision) throw new Error(label('pending_write'));
        if (kind === 'settings') {
            if (!response.policy || Number(response.policy.settings_revision) < expectedRevision || scaled(response.policy.service_rate, 2) === null
                || !(response.replayed && Number(response.policy.settings_revision) > expectedRevision) && scaled(response.policy.service_rate, 2) !== scaled(payload.service_rate, 2)) throw new Error(label('pending_write'));
        } else {
            var table = response.table;
            if (!table || !Number.isSafeInteger(Number(table.id)) || Number(table.id) < 1 || Number(table.id) !== Number(evidence.table_id)
                || table.branch !== settingsBranch || Number(table.revision) < expectedRevision || payload.id && Number(table.id) !== Number(payload.id)
                || !(response.replayed && Number(table.revision) > expectedRevision) && (table.name !== payload.name || Number(table.capacity) !== payload.capacity || table.active !== payload.active)) throw new Error(label('pending_write'));
        }
    }
    async function settings() { if (locked()) return; if (!branch) { notify(label('choose_branch_first')); return; } if (!permissions.can_manage_tables) return; var content = openModal(label('floor_settings')), generation = modalGeneration, settingsBranch = branch; modalController = new AbortController(); try { var result = await read(await get(urls.tables, { branch: branch }, modalController.signal), 'settings_error'); if (disposed || generation !== modalGeneration || branch !== settingsBranch) return; if (!Array.isArray(result.tables)) throw new Error(label('settings_error')); applyPolicy(result); content.replaceChildren(); var grid = node('div', 'dp-settings-grid'); var serviceForm = node('form', 'dp-settings-form'), rate = input('text', policy.service_rate || '0.00'), note = input('text'); rate.inputMode = 'decimal'; rate.maxLength = 6; note.maxLength = 500; serviceForm.appendChild(node('h3', '', label('service_rate'))); serviceForm.appendChild(node('p', '', label('service_hint'))); serviceForm.appendChild(field('service_rate', rate)); serviceForm.appendChild(field('settings_note', note)); serviceForm.appendChild(node('button', '', label('save'))); if (permissions.can_manage) grid.appendChild(serviceForm); var tableForm = node('form', 'dp-settings-form'), tableChooser = node('select'), fresh = node('option', '', label('add_table')); fresh.value = ''; tableChooser.appendChild(fresh); result.tables.forEach(function (table) { var option = node('option', '', table.name); option.value = table.id; tableChooser.appendChild(option); }); var name = input('text'), capacity = input('number', 4), active = input('checkbox'); capacity.min = 1; capacity.max = 200; name.maxLength = 100; active.checked = true; tableForm.appendChild(node('h3', '', label('table_settings_hint'))); tableForm.appendChild(tableChooser); tableForm.appendChild(field('table_name', name)); tableForm.appendChild(field('capacity', capacity)); tableForm.appendChild(field('active', active)); tableForm.appendChild(node('button', '', label('save'))); var feedback = node('p'); feedback.setAttribute('role', 'status'); content.appendChild(grid); grid.appendChild(tableForm); content.appendChild(feedback); tableChooser.addEventListener('change', function () { var table = result.tables.find(function (value) { return String(value.id) === tableChooser.value; }); name.value = table ? table.name : ''; capacity.value = table ? table.capacity : 4; active.checked = table ? !!table.active : true; }); var frozen = null, pending = false;
            async function submit(kind, payload) { if (pending || disposed || generation !== modalGeneration || branch !== settingsBranch) return; if (!frozen) frozen = { kind: kind, payload: Object.assign({ branch: settingsBranch, idempotency_key: uuid() }, payload) }; if (frozen.kind !== kind) return; pending = true; busy = true; lock(); grid.querySelectorAll('button,input,select').forEach(function (control) { control.disabled = true; }); feedback.textContent = label('sending'); var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 25000); activeWriteController = controller; var wasUncertain = uncertain; try { var response = await read(await post(kind === 'settings' ? urls.settings : urls['table-save'] || urls.table_save, frozen.payload, controller.signal), 'settings_error'); if (disposed || generation !== modalGeneration || branch !== settingsBranch) return; validateConfiguration(response, frozen, settingsBranch); applyPolicy(response); uncertain = false; busy = false; if (cart.length && (!ticket || dirty)) invalidateQuote(); notify(label(kind === 'settings' ? 'settings_saved' : 'table_saved'), true); closeModal(); frozen = null; operation = null; loadTables(); } catch (error) { if (disposed) return; if (wasUncertain || !error.status || error.status < 400 || error.status >= 500) { uncertain = true; feedback.textContent = label('pending_write'); var retry = (kind === 'settings' ? serviceForm : tableForm).querySelector('button'); retry.textContent = label('retry'); retry.disabled = false; } else { uncertain = false; frozen = null; feedback.textContent = error.message || label('settings_error'); grid.querySelectorAll('button,input,select').forEach(function (control) { control.disabled = false; }); } } finally { clearTimeout(timeout); if (activeWriteController === controller) activeWriteController = null; busy = false; pending = false; lock(); if (uncertain) (kind === 'settings' ? serviceForm : tableForm).querySelector('button').disabled = false; } }
            serviceForm.addEventListener('submit', function (event) { event.preventDefault(); var cents = scaled(rate.value, 2); submit('settings', { service_rate: cents === null ? rate.value : decimal(cents, 2), note: note.value.trim(), expected_revision: policy.settings_revision }); }); tableForm.addEventListener('submit', function (event) { event.preventDefault(); var chosen = result.tables.find(function (value) { return String(value.id) === tableChooser.value; }); var values = { name: name.value.trim(), capacity: Number(capacity.value), active: active.checked }; if (chosen) { values.id = chosen.id; values.expected_revision = chosen.revision; } submit('table', values); });
        } catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(node('p', 'dp-empty', error.message || label('settings_error'))); } }
    function changeBranch() { var next = branchSelect.value; if (next === branch) return; if (!mayDiscard()) { branchSelect.value = branch; if (branchSelect.selectize) branchSelect.selectize.setValue(branch, true); return; } cancelCatalogNavigation(); branch = next; root.querySelector('[data-dining-register-balance]').textContent = '—'; root.querySelector('[data-dining-day-count]').hidden = true; catalogGeneration++; tableGeneration++; modalGeneration++; if (catalogController) catalogController.abort(); if (tableController) tableController.abort(); if (modalController) modalController.abort(); modal.hidden = true; category = ''; root.querySelector('[data-dining-search]').value = ''; root.querySelector('[data-dining-table-search]').value = ''; tables = []; products.clear(); permissions = {}; loaded = false; resetInvoice(); if (branch) { var catalogLoading = loadCatalog(1); loadTables(); return catalogLoading; } else { root.querySelector('[data-dining-products]').replaceChildren(); root.querySelector('[data-dining-categories]').replaceChildren(); root.querySelector('[data-dining-catalog-message]').hidden = false; root.querySelector('[data-dining-catalog-message]').textContent = label('choose_branch_first'); if (boot.can_view_all_branches) loadTables(); } }
    listen(root, 'click', function (event) { var button = event.target.closest('button'); if (!button || !root.contains(button) || button.disabled) return; if (button.hasAttribute('data-dining-modal-close')) return closeModal(); if (button.hasAttribute('data-dining-retry')) { if (uncertain && operation) return commit(operation); if (uncertain) return; if (cart.length && !quote) return requestQuote(); loadCatalog(catalogPage); loadTables(); return; } if (button.hasAttribute('data-dining-daily')) return daily(); if (button.hasAttribute('data-dining-register')) return showRegister(); if (button.hasAttribute('data-dining-settings')) return settings(); if (locked()) return; if (button.hasAttribute('data-dining-save')) return saveAndSend(); if (button.hasAttribute('data-dining-request-bill')) return issueBill(); if (button.hasAttribute('data-dining-settle')) return settle(); if (button.hasAttribute('data-dining-cancel')) return cancelInvoice(); if (button.hasAttribute('data-dining-print-kitchen')) { if (!billLocked()) return print(ticket && ticket.kitchen_print_url); return; } if (button.hasAttribute('data-dining-print-bill')) return issueBill(); if (button.hasAttribute('data-dining-details')) return window.DashboardInvoiceDetails.open(ticket && endpoint(ticket.details_url)); if (button.dataset.diningTable) return openTable(tables.find(function (table) { return String(table.id) === button.dataset.diningTable; })); if (button.dataset.diningProduct) return chooseOption(products.get(button.dataset.diningProduct)); if (button.dataset.diningOptions) return chooseOption(products.get(button.dataset.diningOptions)); if (button.dataset.diningKey !== undefined) return key(button.dataset.diningKey); if (button.hasAttribute('data-dining-weight-reset')) return key('clear'); if (button.dataset.diningPayment) return setPayment(button.dataset.diningPayment); if (button.dataset.diningRemove !== undefined) { if (billLocked() || kitchenSent() && cart.length <= 1) return; cart.splice(Number(button.dataset.diningRemove), 1); dirty = true; pricingDirty = true; renderLines(); invalidateQuote(); return; } if (button.hasAttribute('data-dining-clear')) { if (billLocked() || kitchenSent()) return; if (window.confirm(label('confirm_clear'))) { cart = []; dirty = true; pricingDirty = true; renderLines(); invalidateQuote(); } return; } if (button.dataset.diningCategory !== undefined) return selectCatalogCategory(button.dataset.diningCategory); if (button.dataset.diningPage) return loadCatalog(catalogPage + (button.dataset.diningPage === 'next' ? 1 : -1), true); if (button.dataset.diningFloorFilter) { floorFilter = button.dataset.diningFloorFilter; root.querySelectorAll('[data-dining-floor-filter]').forEach(function (control) { control.setAttribute('aria-pressed', String(control.dataset.diningFloorFilter === floorFilter)); }); renderTables(); return; } if (button.hasAttribute('data-dining-refresh')) return loadTables(); });
    listen(root, 'change', function (event) {
        var input = event.target, index = input.dataset.diningLineQuantity;
        if (input === customerPhone || input === customerInput || input === customerConsent) consentEnterAffirmed = false;
        if (input === customerPhone && !locked()) customerConsent.checked = false;
        if (input === customerConsent && customerConsent.checked && !locked() && message.querySelector('span').textContent === label('consent_required')) notify('');
        if (index === undefined || locked() || billLocked() || !permissions.can_operate) return;
        var line = cart[Number(index)]; if (!line) return;
        var checked = quantity(input.value, line.mode);
        if (!checked) { input.value = line.quantity; notify(label('invalid_quantity')); return; }
        if (scaled(checked.value, 3) === scaled(line.quantity, 3)) return;
        line.quantity = checked.value; dirty = true; pricingDirty = true; renderLines(); invalidateQuote();
    });
    listen(branchSelect, 'change', changeBranch); if (window.jQuery) window.jQuery(branchSelect).on('change.diningPos', changeBranch);
    listen(root.querySelector('[data-dining-search]'), 'input', function () { cancelCatalogNavigation(); searchTimer = setTimeout(function () { loadCatalog(1, true); }, 220); });
    listen(root.querySelector('[data-dining-table-search]'), 'input', renderTables);
    [customerInput, waiterInput, guestsInput, notesInput].forEach(function (control) { listen(control, 'input', function () { changed(false); renderDetail(); }); });
    [discountInput, reasonInput].forEach(function (control) { listen(control, 'input', function () { root.querySelector('[data-dining-discount-reason-wrap]').hidden = !policy.can_discount || !(scaled(discountInput.value, 2) > 0n); changed(true); }); });
    listen(cashInput, 'input', showChange);
    [customerPhone, customerConsent].forEach(function (control) { listen(control, 'input', function () { consentEnterAffirmed = false; if (control === customerPhone && !locked()) customerConsent.checked = false; }); });
    listen(customerConsent, 'blur', function () { consentEnterAffirmed = false; });
    listen(document, 'keyup', function (event) { if (event.key === 'Enter') enterKeyDown = false; });
    listen(document, 'compositionstart', function () { composing = true; consentEnterAffirmed = false; });
    listen(document, 'compositionend', function () { composing = false; });
    listen(window, 'blur', function () { enterKeyDown = false; composing = false; consentEnterAffirmed = false; });
    listen(modal, 'click', function (event) { if (event.target === modal) closeModal(); });
    listen(document, 'keydown', function (event) { if (disposed) return; if (event.key === 'Escape' && !modal.hidden) { closeModal(); return; } if (!modal.hidden) { if (event.key === 'Tab') { var controls = modal.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)'); if (controls.length) { var first = controls[0], last = controls[controls.length - 1]; if (event.shiftKey && (document.activeElement === first || document.activeElement === modal.querySelector('[role="dialog"]'))) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } } return; } if (event.key === 'Escape' && !event.defaultPrevented && !event.repeat && !composing && !event.isComposing && event.keyCode !== 229 && !event.shiftKey && !event.ctrlKey && !event.altKey && !event.metaKey && !event.target.closest('input,textarea,select,[contenteditable],[role="textbox"]') && (event.target === document.body || event.target === document.documentElement || event.target === root || !!event.target.closest('.dp-catalog,.dp-weight'))) { event.preventDefault(); if (!locked() && !billLocked() && permissions.can_operate) key('clear'); return; } if (event.key === 'F9') { event.preventDefault(); if (!locked() && !kitchenSent()) saveAndSend(); return; } if (checkoutEnter(event)) return; if (event.target.matches('input,textarea,select,[contenteditable]')) return; if (/^[0-9.]$/.test(event.key)) { event.preventDefault(); key(event.key); } else if (event.key === 'Backspace') { event.preventDefault(); key('backspace'); } });
    listen(window, 'beforeunload', function (event) { if (dirty || busy || uncertain) { event.preventDefault(); event.returnValue = ''; } });
    if (window.DashboardSPA && window.DashboardSPA.onBeforeLeave) { window.DashboardSPA.onBeforeLeave(mayDiscard); guardRegistered = true; }
    listen(document, 'click', function (event) { if (guardRegistered || disposed) return; var link = event.target.closest('a[href]'); if (link && !root.contains(link) && link.target !== '_blank' && !mayDiscard()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
    function cleanup() { disposed = true; catalogGeneration++; tableGeneration++; ticketGeneration++; quoteGeneration++; modalGeneration++; clearTimeout(quoteTimer); cancelCatalogNavigation(); clearInterval(tableTimer); [catalogController, tableController, ticketController, quoteController, modalController].forEach(function (controller) { if (controller) controller.abort(); }); listeners.forEach(function (remove) { remove(); }); if (window.jQuery) window.jQuery(branchSelect).off('.diningPos'); }
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(cleanup);
    customerConsent.checked = false; updateRegister(boot.register); renderPayments(); renderLines(); renderTotals(); showQuantity(); lock(); if (branch) loadCatalog(1); if (branch || boot.can_view_all_branches) loadTables(); tableTimer = setInterval(function () { if (!document.hidden && !disposed) { loadTables(); renderDetail(); } }, 15000);
}());

