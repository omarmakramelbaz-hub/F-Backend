(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root = document.querySelector('#phone-orders');
    if (!root) return;
    var boot = JSON.parse(document.querySelector('#phone-orders-bootstrap').textContent);
    var labels = JSON.parse(document.querySelector('#phone-orders-translations').textContent);
    var urls = boot.urls || {}, branchSelect = root.querySelector('[data-phone-branch]'), branch = branchSelect.value;
    var products = new Map(), cart = [], editing = null, quote = null, policy = boot.policy || {}, permissions = boot.permissions || {};
    var category = '', catalogPage = 1, pagination = {}, orderStage = boot.company_filter ? 'all' : 'new', orderPage = 1, orderPagination = {}, view = 'compose';
    var step = 'number', receiverTimer, lastIncoming = null, customerTimer, suggestionIndex = -1;
    var loaded = false, disposed = false, dirty = false, writing = false, uncertain = false, frozen = null, requestKey = uuid(), modalFocus;
    var catalogGeneration = 0, quoteGeneration = 0, orderGeneration = 0, customerGeneration = 0, modalGeneration = 0;
    var controllers = {}, searchTimer, quoteTimer, editGeneration = 0, mode = 'piece', listeners = [], modal = root.querySelector('[data-phone-modal]');
    var saveButton = root.querySelector('[data-phone-save]'), message = root.querySelector('[data-phone-message]');
    var customerRef=null, deliveryLocation=null, locationPicker=null, deliverySettings=null, locationGeneration=0, locating=false, lastGeocoded='', contextGeneration=0, locationPreviewTimer, addressSearch;
    var latInput=root.querySelector('[data-phone-lat]'),lngInput=root.querySelector('[data-phone-lng]'),companySelect=root.querySelector('[data-phone-company]');
    var fields = {};
    root.querySelectorAll('[data-phone-field]').forEach(function (input) { fields[input.dataset.phoneField] = input; });

    function invalidateLocation(){clearTimeout(locationPreviewTimer);abort('location');deliveryLocation=null;locationGeneration++;if(locationPicker)locationPicker.invalidate();fields.delivery_fee.value='0.00';root.querySelector('[data-phone-distance]').textContent='لم يتم تأكيد الموقع بعد.';quote=null;changed(true);}
    function addressChanged(){invalidateLocation();lastGeocoded='';latInput.value='';lngInput.value='';if(locationPicker)locationPicker.clear();if(addressSearch)addressSearch.schedule();}
    function previewLocation(){clearTimeout(locationPreviewTimer);locationPreviewTimer=setTimeout(function(){if(!disposed&&!locked()&&DashboardLocationPicker.valid(latInput.value,lngInput.value))confirmLocation(true);},350);}
    function setCustomerLocation(customer){if(addressSearch)addressSearch.reset();invalidateLocation();lastGeocoded='';latInput.value=customer.latitude==null?'':customer.latitude;lngInput.value=customer.longitude==null?'':customer.longitude;if(locationPicker){locationPicker.destroy();locationPicker=null;}}
    async function loadDeliveryContext(){
        if(addressSearch)addressSearch.reset();
        var selected=branch,generation=++contextGeneration;companySelect.replaceChildren(element('option','','توصيل الفرع'));companySelect.options[0].value='';
        try{var results=await Promise.all([get(urls.delivery_settings,{branch:selected}).then(function(r){return read(r,'load_error');}),get(urls.companies,{branch:selected,active:1}).then(function(r){return read(r,'load_error');})]);if(disposed||selected!==branch||generation!==contextGeneration)return;
            deliverySettings=results[0].settings;results[1].items.forEach(function(c){var o=element('option','',c.name);o.value=String(c.id);companySelect.appendChild(o);});
            if(!deliverySettings.ready)root.querySelector('[data-phone-location-status]').textContent='يجب ضبط دبوس الفرع وسعر الكيلومتر في إعدادات المطعم.';else root.querySelector('[data-phone-location-status]').textContent='سعر الكيلومتر: '+deliverySettings.km_price+' جنيه. '+(boot.open_phone_maps?'الخدمة من مسافة الطريق.':'الخدمة من المسافة المباشرة بين الدبوسين.')+'';
            if(locationPicker){locationPicker.destroy();locationPicker=null;}if(step==='details')ensureLocation();
        }catch(e){if(!disposed&&selected===branch)notify(e.message||'تعذر تحميل إعدادات التوصيل.');}
    }
    function ensureLocation(){if(!deliverySettings||disposed)return;if(!locationPicker){locationPicker=DashboardLocationPicker.create(root.querySelector('[data-phone-map]'),{key:boot.maps_key,preferOpenMap:!!boot.open_phone_maps,routeOnly:!!boot.open_phone_maps,tileUrl:boot.tile_url,latitude:latInput.value,longitude:lngInput.value,center:deliverySettings.ready?[deliverySettings.latitude,deliverySettings.longitude]:null,origin:deliverySettings.ready?[deliverySettings.latitude,deliverySettings.longitude]:null,locked:locked,change:function(lat,lng){if(addressSearch)addressSearch.reset();latInput.value=lat;lngInput.value=lng;invalidateLocation();previewLocation();}});}locationPicker.resize();if(!latInput.value&&!lngInput.value&&fields.address.value.trim())locateAddress();else if(!deliveryLocation)previewLocation();}
    function locateAddress(){if(!addressSearch)return;addressSearch.search();}
    addressSearch=PhoneAddressSearch.create({input:fields.address,box:root.querySelector('[data-phone-address-options]'),status:root.querySelector('[data-phone-address-status]'),locked:locked,query:function(){return fields.address.value.trim()+(fields.area.value.trim()?'، '+fields.area.value.trim():'');},search:async function(query,signal){
        if(boot.open_phone_maps){var r=await read(await post(urls.address_suggestions,{branch:branch,query:query},signal),'load_error');return r.items||[];}
        if(!locationPicker)throw Error('انتظر تحميل خريطة الفرع.');return locationPicker.suggest(query);
    },resolve:function(item){return boot.open_phone_maps?Promise.resolve(item):locationPicker.resolve(item);},select:function(point){
        fields.address.value=point.label;invalidateLocation();latInput.value=point.latitude;lngInput.value=point.longitude;if(locationPicker)locationPicker.set(point.latitude,point.longitude);previewLocation();
    }});
    async function confirmLocation(preview){if(locked()||!branch)return;clearTimeout(locationPreviewTimer);if(!DashboardLocationPicker.valid(latInput.value,lngInput.value)){if(!preview)notify('حدد دبوس العميل أو أدخل إحداثيات صحيحة.');return;}if(locationPicker){locationPicker.invalidate();locationPicker.set(latInput.value,lngInput.value);}deliveryLocation=null;var generation=++locationGeneration,selected=branch;locating=!preview;lock();var aborter=begin('location'),timeout=setTimeout(function(){aborter.abort();},15000);
        try{var r=await read(await post(urls.delivery_quote,{branch:branch,latitude:latInput.value,longitude:lngInput.value,location_confirmed:true},aborter.signal),'quote_error');if(disposed||generation!==locationGeneration||selected!==branch)return;deliveryLocation=preview?null:r.delivery;fields.delivery_fee.value=r.delivery.delivery_fee;if(locationPicker&&r.delivery.route_path)locationPicker.route(r.delivery.route_path);root.querySelector('[data-phone-distance]').textContent=(r.delivery.method==='road_osrm'?'مسافة الطريق: ':'المسافة المباشرة: ')+r.delivery.distance_km+' كم × '+r.delivery.km_price+' جنيه = '+r.delivery.delivery_fee+' جنيه'+(preview?' — راجع دبوس العميل ثم أكده.':' — تم تأكيد الدبوس.');if(!preview)notify('تم تأكيد موقع العميل وحساب الخدمة.',true);}
        catch(e){if(!disposed&&generation===locationGeneration&&e.name!=='AbortError')notify(e.message||'تعذر حساب الخدمة.');}finally{clearTimeout(timeout);if(generation===locationGeneration){locating=false;lock();if(deliveryLocation)changed(true);}}
    }
    async function pollReceiver() {
        if (disposed) return;
        var printer = window.DashboardBranchPrinter;
        root.querySelector('[data-phone-printer-status]').textContent = printer ? printer.status : text('callcenter_print');
        if (view === 'orders' && !locked()) loadOrders(orderPage, true);
        receiverTimer = setTimeout(pollReceiver, 4000);
    }
    function flow(next, focus) {
        root.querySelector(next === 'number' ? '[data-phone-number-matches]' : '[data-phone-detail-matches]').appendChild(root.querySelector('[data-phone-lookup-result]'));
        step = next; ['number','details','products'].forEach(function (value) { root.classList.toggle('ph-step-' + value, value === next); });
        root.querySelector('[data-phone-step-label]').textContent = text('step_' + next);
        root.querySelector('[data-phone-selected-branch]').textContent = branchSelect.selectedOptions[0] && branchSelect.selectedOptions[0].textContent || '';
        root.querySelector('[data-phone-customer-summary]').textContent = fields.customer_name.value + ' · ' + fields.customer_phone.value + ' · ' + fields.address.value;
        if (focus !== false && view === 'compose') (next === 'number' ? fields.customer_phone : next === 'details' ? fields.customer_name : root.querySelector('[data-phone-search]')).focus();
        lock();if(next==='details')ensureLocation();
    }
    function inputPhoneDigits() { return fields.customer_phone.value.replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/[^0-9]/g, ''); }
    function nextNumber() {
        if (locked() || !canWrite()) return;
        fields.customer_phone.value = fields.customer_phone.value.replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).trim();
        if (!/^\+?[0-9 ()-]{6,30}$/.test(fields.customer_phone.value)) { notify(text('required_customer')); fields.customer_phone.focus(); return; }
        clearTimeout(customerTimer); flow('details'); lookupCustomer(false);
    }
    function nextDetails() {
        if (locked() || !canWrite()) return;
        if (!branch) { notify(text('select_branch')); branchSelect.focus(); return; }
        if (!fields.customer_name.value.trim() || !fields.address.value.trim()) { notify(text('required_customer')); (!fields.customer_name.value.trim() ? fields.customer_name : fields.address).focus(); return; }
        if (scaled(fields.delivery_fee.value,2) === null) { notify(text('invalid_money')); fields.delivery_fee.focus(); return; }
        if(!deliveryLocation){notify('أكد دبوس موقع العميل لحساب الخدمة أولًا.');return;}
        var v={branch:branch,name:fields.customer_name.value.trim(),phone:fields.customer_phone.value.trim(),address:fields.address.value.trim(),area:fields.area.value.trim(),delivery_notes:fields.delivery_notes.value.trim(),latitude:deliveryLocation.latitude,longitude:deliveryLocation.longitude,idempotency_key:uuid()};
        if(customerRef){v.customer_id=customerRef.id;v.expected_revision=customerRef.revision;}
        execute({type:'customer',url:urls.customer_save,payload:v});
    }
    function text(key) { return labels[key] || key; }
    function element(tag, className, value) { var item = document.createElement(tag); if (className) item.className = className; if (value !== undefined) item.textContent = String(value); return item; }
    function listen(target, type, fn, options) { target.addEventListener(type, fn, options); listeners.push(function () { target.removeEventListener(type, fn, options); }); }
    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        var bytes = new Uint8Array(16); if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Secure browser required');
        window.crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        var value = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        return value.slice(0, 8) + '-' + value.slice(8, 12) + '-' + value.slice(12, 16) + '-' + value.slice(16, 20) + '-' + value.slice(20);
    }
    function scaled(value, places) {
        var raw = String(value === undefined || value === null ? '' : value).trim().replace(/[٠-٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); }).replace(/٫/g, '.');
        var match = raw.match(new RegExp('^([0-9]{1,10})(?:\\.([0-9]{1,' + places + '}))?$'));
        if (!match) return null;
        return BigInt(match[1]) * (10n ** BigInt(places)) + BigInt((match[2] || '').padEnd(places, '0') || '0');
    }
    function decimal(value, places) { var divider = 10n ** BigInt(places); return String(value / divider) + '.' + String(value % divider).padStart(places, '0'); }
    function money(value) { var amount = scaled(value, 2); if (amount === null) return '—'; var parts = decimal(amount, 2).split('.'); return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + parts[1]; }
    function quantity(value, unit) { var amount = scaled(value, 3); if (amount === null || amount <= 0n || amount > 1000000n || unit === 'piece' && amount % 1000n !== 0n) return null; return { amount: amount, value: unit === 'piece' ? String(amount / 1000n) : decimal(amount, 3) }; }
    function dateLabel(value) { var date = new Date(value); if (!Number.isFinite(date.getTime())) return String(value || ''); try { return new Intl.DateTimeFormat(document.documentElement.lang === 'ar' ? 'ar-EG' : 'en-GB', { timeZone: 'Africa/Cairo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date); } catch (_) { return String(value || ''); } }
    function lineKey(line) { return String(line.product.id) + '|' + String(line.option && line.option.id || '') + '|' + line.mode; }
    function quoteLine(line) { return quote && Array.isArray(quote.items) && quote.items.find(function (item) { return String(item.product_id) + '|' + String(item.option_id || '') + '|' + item.quantity_mode === lineKey(line); }); }
    function endpoint(url, parameters, identifier) {
        if (typeof url !== 'string' || !url) throw new Error(text('write_error'));
        if (identifier !== undefined) url = url.replace('__TICKET__', String(identifier)).replace('__KITCHEN__', String(identifier));
        var target = new URL(url, location.href); if (target.origin !== location.origin || /__(TICKET|KITCHEN)__/.test(target.href)) throw new Error(text('forbidden'));
        Object.keys(parameters || {}).forEach(function (key) { if (parameters[key] !== undefined && parameters[key] !== null && parameters[key] !== '') target.searchParams.set(key, String(parameters[key])); });
        return target.href;
    }
    function notify(value, success, retry) {
        if (disposed) return;
        message.hidden = !value; message.querySelector('span').textContent = value || ''; message.classList.toggle('is-success', !!success); message.querySelector('[data-phone-retry]').hidden = !retry;
        var inline = modal.querySelector('[data-phone-modal-message]');
        if (!value || modal.hidden) { if (inline) inline.remove(); return; }
        if (!inline) { inline = element('div', 'ph-notice'); inline.dataset.phoneModalMessage = ''; inline.setAttribute('role', 'status'); inline.appendChild(element('span')); var action = element('button', '', text('retry')); action.type = 'button'; action.dataset.phoneRetry = ''; inline.appendChild(action); root.querySelector('[data-phone-modal-content]').prepend(inline); }
        inline.querySelector('span').textContent = value; inline.classList.toggle('is-success', !!success); inline.querySelector('button').hidden = !retry; inline.querySelector('button').disabled = writing;
    }
    function abort(kind) { if (controllers[kind]) controllers[kind].abort(); }
    function begin(kind) { abort(kind); controllers[kind] = new AbortController(); return controllers[kind]; }
    async function read(response, fallback) {
        var result; try { result = await response.json(); } catch (_) { result = null; }
        if (!response.ok || !result || result.success === false) {
            var error = new Error(response.status === 401 || response.status === 419 ? text('session_expired') : response.status === 403 ? text('forbidden') : response.status === 409 ? text('stale') : response.status >= 500 || !result ? text(fallback) : result.message || text(fallback));
            error.status = response.status; throw error;
        }
        return result;
    }
    function get(url, parameters, signal) { return fetch(endpoint(url, parameters), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: signal }); }
    function post(url, payload, signal) { return fetch(endpoint(url), { method: 'POST', credentials: 'same-origin', signal: signal, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) }); }
    function locked() { return writing || uncertain || locating; }
    function canWrite() { return !!(permissions.can_checkout || permissions.can_operate); }
    function lock() {
        root.classList.toggle('is-locked', locked());
        root.querySelectorAll('input,textarea,select,button').forEach(function (control) {
            if (control.hasAttribute('data-phone-retry')) { control.disabled = writing; return; }
            control.disabled = locked() || control.hasAttribute('data-unavailable') || control.hasAttribute('data-no-access');
        });
        if (branchSelect.selectize) { if (locked()) branchSelect.selectize.disable(); else branchSelect.selectize.enable(); }
        branchSelect.disabled = locked() || !!boot.receiver_branch;
        root.querySelectorAll('[data-phone-product]').forEach(function (button) { button.disabled = button.disabled || step !== 'products' || !canWrite(); });
        root.querySelectorAll('[data-phone-remove]').forEach(function (control) { control.disabled = control.disabled || !!editing && !!editing.kitchen_sent && cart.length <= 1; });
        saveButton.disabled = step !== 'products' || locked() || !loaded || !cart.length || !quote || !canWrite() || editing && !dirty;
        saveButton.querySelector('span').textContent = text(writing ? 'saving' : editing ? 'save_changes' : 'save_order');
        root.querySelector('[data-phone-page="previous"]').disabled = locked() || catalogPage <= 1;
        root.querySelector('[data-phone-page="next"]').disabled = locked() || catalogPage >= Number(pagination.last_page || 1);
        root.querySelector('[data-phone-orders-page="previous"]').disabled = locked() || orderPage <= 1;
        root.querySelector('[data-phone-orders-page="next"]').disabled = locked() || orderPage >= Number(orderPagination.last_page || 1);
        if (!canWrite()) root.querySelectorAll('[data-phone-field],[data-phone-product],[data-phone-remove],[data-phone-mode],[data-phone-quantity],[data-phone-lookup],[data-phone-line-quantity],[data-phone-line-option]').forEach(function (control) { control.disabled = true; });
    }
    function totals(value) {
        ['subtotal', 'discount', 'tax', 'service', 'total', 'delivery_fee'].forEach(function (key) { var amount = value && value[key]; if (key === 'delivery_fee' && amount === undefined && value) amount = value.delivery; root.querySelector('[data-phone-total="' + key + '"]').textContent = value ? money(amount || '0.00') : '—'; });
        root.querySelector('[data-phone-tax-rate]').textContent = value && value.tax_rate !== undefined ? '(' + value.tax_rate + '%)' : '';
        root.querySelector('[data-phone-service-row]').hidden = !(value && scaled(value.service || '0.00', 2) > 0n);
    }
    function payload() {
        var discount = scaled(fields.discount.value, 2), delivery = scaled(fields.delivery_fee.value, 2);
        return { branch: branch, items: cart.map(function (line) { var item = { product_id: line.product.id, quantity: line.quantity, quantity_mode: line.mode }; if (line.option && line.option.id) item.option_id = String(line.option.id); return item; }), discount: policy.can_discount ? discount === null ? fields.discount.value : decimal(discount, 2) : '0.00', discount_reason: policy.can_discount ? fields.discount_reason.value.trim() : '', delivery_fee: delivery === null ? fields.delivery_fee.value : decimal(delivery, 2), latitude:deliveryLocation&&deliveryLocation.latitude,longitude:deliveryLocation&&deliveryLocation.longitude,location_confirmed:!!deliveryLocation,delivery_quote_hash:deliveryLocation&&deliveryLocation.delivery_quote_hash,customer_id:customerRef&&customerRef.id,delivery_company_id:companySelect.value?Number(companySelect.value):null };
    }
    function sameFinancialDraft() {
        if (!editing) return false;
        function signature(items, discount, reason, delivery) { var discountMinor = scaled(discount, 2), deliveryMinor = scaled(delivery, 2); if (!Array.isArray(items) || discountMinor === null || deliveryMinor === null) return null; var values = []; for (var index = 0; index < items.length; index++) { var item = items[index], amount = quantity(item.quantity, item.quantity_mode); if (!amount) return null; values.push([String(Number(item.product_id)), item.quantity_mode, String(item.option_id || ''), String(amount.amount)]); } values.sort(function (a, b) { return JSON.stringify(a).localeCompare(JSON.stringify(b)); }); return JSON.stringify([values, String(discountMinor), String(reason || '').trim(), String(deliveryMinor)]); }
        var current = payload(), previous = editing.cart || editing;
        var currentSignature = signature(current.items, current.discount, current.discount_reason, current.delivery_fee), previousSignature = signature(previous.items, previous.discount, previous.discount_reason, editing.delivery_fee || editing.delivery || '0.00');
        return currentSignature !== null && previousSignature !== null && currentSignature === previousSignature;
    }
    function changed(affectsQuote) { if (locked()) return; dirty = true; requestKey = uuid(); if (affectsQuote) invalidateQuote(); else lock(); }
    function invalidateQuote() {
        quoteGeneration++; abort('quote'); clearTimeout(quoteTimer); quote = sameFinancialDraft() ? editing : null; totals(quote); renderLines(); lock();
        if (!quote && cart.length && branch && loaded) quoteTimer = setTimeout(calculate, 180);
    }
    async function calculate() {
        if(!deliveryLocation)return;
        if (disposed || locked() || !cart.length || !branch || !loaded) return;
        if (sameFinancialDraft()) { quote = editing; totals(quote); renderLines(); lock(); notify(''); return; }
        var generation = ++quoteGeneration, controller = begin('quote'), draft = payload();
        try {
            var response = await post(urls.quote, draft, controller.signal), result = await read(response, 'quote_error'); if (disposed || generation !== quoteGeneration) return;
            var value = result.quote || result; if (!value.quote_hash || !Array.isArray(value.items) || scaled(value.total, 2) === null) throw new Error(text('quote_error'));
            quote = value; totals(value); renderLines(); lock(); notify('');
        } catch (error) { if (disposed || generation !== quoteGeneration || error.name === 'AbortError') return; quote = null; totals(null); lock(); notify(error.message || text('quote_error'), false, true); }
    }
    function renderLines() {
        var body = root.querySelector('[data-phone-lines]'); body.replaceChildren(); root.querySelector('[data-phone-empty]').hidden = !!cart.length;
        cart.forEach(function (line, index) {
            var confirmed = quoteLine(line), lineName = confirmed && confirmed.name || line.product.name; var row = element('tr'), itemCell = element('td'); itemCell.appendChild(element('strong', 'ph-line-name', lineName));
            var choices = [{ id: '', label: text('base'), price: line.product.unit_price || line.product.price }].concat(line.product.options || []);
            if (choices.length > 1) {
                var select = element('select', 'ph-line-option'); select.dataset.phoneLineOption = String(index); select.setAttribute('aria-label', text('options') + ' · ' + lineName);
                choices.forEach(function (option) { var selected = String(option.id || '') === String(line.option && line.option.id || ''); var optionPrice = selected && confirmed ? confirmed.unit_price : option.price; var optionLabel = selected && confirmed && confirmed.option_label ? confirmed.option_label : option.label; var entry = element('option', '', optionLabel + ' · ' + money(optionPrice)); entry.value = String(option.id || ''); entry.selected = selected; select.appendChild(entry); }); itemCell.appendChild(select);
            } else if (line.option) itemCell.appendChild(element('small', 'ph-line-price', line.option.label));
            var price = line.option ? line.option.price : line.product.unit_price || line.product.price; if (confirmed) price = confirmed.unit_price;
            itemCell.appendChild(element('bdi', 'ph-line-price', money(price))); row.appendChild(itemCell);
            var countCell = element('td'), count = element('div', 'ph-line-quantity'), input = element('input'); input.value = line.quantity; input.inputMode = 'decimal'; input.maxLength = 10; input.dataset.phoneLineQuantity = String(index); input.setAttribute('aria-label', text('quantity') + ' · ' + lineName); count.appendChild(input); count.appendChild(element('small', 'ph-line-unit', text(line.mode === 'weight' ? 'kg' : 'piece'))); countCell.appendChild(count); row.appendChild(countCell);
            var priceMinor = scaled(price, 2), countMillis = scaled(line.quantity, 3); var amount = confirmed && confirmed.total || (priceMinor !== null && countMillis !== null ? decimal((priceMinor * countMillis + 500n) / 1000n, 2) : null); var amountCell = element('td'); amountCell.appendChild(element('bdi', 'ph-line-total', money(amount))); row.appendChild(amountCell);
            var removeCell = element('td'), remove = element('button'); remove.type = 'button'; remove.dataset.phoneRemove = String(index); remove.setAttribute('aria-label', text('remove') + ' · ' + lineName); remove.appendChild(element('i', 'fas fa-times')); removeCell.appendChild(remove); row.appendChild(removeCell); body.appendChild(row);
        });
    }
    function addProduct(product) {
        if (!product || !product.available || step !== 'products' || locked() || !canWrite()) return;
        var itemMode = ['piece','weight'].includes(product.quantity_mode) ? product.quantity_mode : mode;
        var amount = quantity(itemMode === mode ? root.querySelector('[data-phone-quantity]').value : itemMode === 'piece' ? '1' : '1.000', itemMode); if (!amount) { notify(text('invalid_quantity')); return; }
        var existing = cart.find(function (line) { return String(line.product.id) === String(product.id) && line.mode === itemMode && !line.option; });
        if (existing) { var sum = quantity(decimal(scaled(existing.quantity, 3) + amount.amount, 3), itemMode); if (!sum) { notify(text('invalid_quantity')); return; } existing.quantity = sum.value; }
        else cart.push({ product: product, option: null, quantity: amount.value, mode: itemMode });
        changed(true); notify('');
    }
    function productNode(product) {
        var button = element('button', 'ph-product'); button.type = 'button'; button.dataset.phoneProduct = String(product.id); if (!product.available) button.dataset.unavailable = '1';
        if (product.image_url) { var image = element('img', 'ph-product-image'); image.src = product.image_url; image.alt = ''; image.loading = 'lazy'; image.addEventListener('error', function () { var placeholder = element('span', 'ph-product-image ph-product-placeholder'); placeholder.appendChild(element('i', 'fas fa-fish')); image.replaceWith(placeholder); }, { once: true }); button.appendChild(image); }
        else { var placeholder = element('span', 'ph-product-image ph-product-placeholder'); placeholder.appendChild(element('i', 'fas fa-fish')); button.appendChild(placeholder); }
        var copy = element('span', 'ph-product-copy'); copy.appendChild(element('strong', '', product.name)); copy.appendChild(element('bdi', '', money(product.unit_price || product.price) + ' ' + text('currency'))); copy.appendChild(element('small', product.available ? '' : 'ph-unavailable', product.stock ? 'الرصيد: '+product.stock.quantity+' '+product.stock.unit_label : (product.available ? product.unit || text('piece') + ' / ' + text('weight') : text('unavailable')))); if(product.stock)copy.querySelector('small').style.color=product.stock.negative?'#b42318':'#126b4a';button.appendChild(copy); return button;
    }
    async function loadCatalog(page) {
        if (!branch || disposed || locked()) return;
        var generation = ++catalogGeneration, controller = begin('catalog'); loaded = false; lock(); var list = root.querySelector('[data-phone-products]'), hint = root.querySelector('[data-phone-catalog-message]'); list.setAttribute('aria-busy', 'true'); hint.hidden = false; hint.textContent = text('loading');
        try {
            var response = await get(urls.catalog, { branch: branch, search: root.querySelector('[data-phone-search]').value.trim(), category_id: category, page: page || 1 }, controller.signal), result = await read(response, 'load_error'); if (disposed || generation !== catalogGeneration) return;
            var items = result.items || result.products; if (!Array.isArray(items) || result.ready === false) throw new Error(text('load_error'));
            policy = result.policy || {}; permissions = result.permissions || boot.permissions || {}; loaded = true; products.clear(); list.replaceChildren(); items.forEach(function (product) { products.set(String(product.id), product); list.appendChild(productNode(product)); });
            var categories = root.querySelector('[data-phone-categories]'); categories.replaceChildren(); (result.categories || []).forEach(function (value) { var button = element('button', '', value.name || value.label); button.type = 'button'; button.dataset.phoneCategory = String(value.id); var selected = category === String(value.id); button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); categories.appendChild(button); });
            pagination = result.pagination || {}; catalogPage = Number(pagination.page || 1); root.querySelector('[data-phone-pagination]').hidden = !(pagination.last_page > 1); root.querySelector('[data-phone-page-label]').textContent = catalogPage + ' / ' + (pagination.last_page || 1);
            root.querySelector('[data-phone-discount-wrap]').hidden = !policy.can_discount; if (!policy.can_discount) { fields.discount.value = '0.00'; fields.discount_reason.value = ''; }
            hint.hidden = !!items.length; hint.textContent = text('no_products'); lock(); if (cart.length && !quote) invalidateQuote();
        } catch (error) { if (disposed || generation !== catalogGeneration || error.name === 'AbortError') return; products.clear(); list.replaceChildren(); hint.hidden = false; hint.textContent = error.message || text('load_error'); notify(error.message || text('load_error'), false, true); }
        finally { if (generation === catalogGeneration) list.setAttribute('aria-busy', 'false'); }
    }
    function resetDraft() {
        clearTimeout(locationPreviewTimer);abort('location');customerRef=null;deliveryLocation=null;lastGeocoded='';locationGeneration++;if(locationPicker){locationPicker.destroy();locationPicker=null;}latInput.value='';lngInput.value='';companySelect.value='';root.querySelector('[data-phone-distance]').textContent='';
        cart = []; editing = null; quote = null; dirty = false; requestKey = uuid(); quoteGeneration++; abort('quote'); clearTimeout(quoteTimer);
        Object.keys(fields).forEach(function (key) { fields[key].value = key === 'discount' || key === 'delivery_fee' ? '0.00' : ''; });
        root.querySelector('[data-phone-edit-number]').textContent = ''; root.querySelector('[data-phone-discount-reason-wrap]').hidden = true; root.querySelector('[data-phone-lookup-result]').hidden = true; renderLines(); totals(null); flow('number', false);
    }
    function switchView(target) {
        if (locked()) { notify(text('locked'), false, true); return; }
        view = target; root.querySelector('[data-phone-step-label]').textContent = text(target === 'orders' ? 'saved_orders' : 'step_' + step); root.querySelector('[data-phone-compose]').hidden = target !== 'compose'; root.querySelector('[data-phone-orders]').hidden = target !== 'orders'; root.querySelectorAll('[data-phone-view]').forEach(function (button) { var selected = button.dataset.phoneView === target; button.classList.toggle('is-active', selected); button.setAttribute('aria-pressed', String(selected)); }); if (target === 'orders' && branch) loadOrders(1); else if (target === 'compose' && branch) { if (!loaded) loadCatalog(catalogPage); else if (cart.length && !quote) calculate(); }
    }
    function changeBranch() {
        var selected = branchSelect.value; if (selected === branch) return; clearTimeout(customerTimer);
        if (boot.receiver_branch || locked() || cart.length && !window.confirm(text('branch_change'))) { branchSelect.value = branch; if (branchSelect.selectize) branchSelect.selectize.setValue(branch, true); return; }
        var customerDraft = {}; Object.keys(fields).forEach(function (key) { if (['notes','discount','discount_reason','delivery_fee'].indexOf(key)<0) customerDraft[key] = fields[key].value; }); var previousStep = step;
        branch = selected; boot.company_filter=0; deliverySettings=null;loadDeliveryContext();catalogGeneration++; quoteGeneration++; orderGeneration++; customerGeneration++; modalGeneration++; editGeneration++; Object.keys(controllers).forEach(abort); clearTimeout(searchTimer); clearTimeout(quoteTimer); modal.hidden = true; loaded = false; permissions = {}; products.clear(); category = ''; root.querySelector('[data-phone-search]').value = ''; root.querySelector('[data-phone-products]').replaceChildren(); root.querySelector('[data-phone-categories]').replaceChildren(); root.querySelector('[data-phone-order-list]').replaceChildren(); resetDraft(); Object.keys(customerDraft).forEach(function (key) { fields[key].value = customerDraft[key]; }); dirty = !!fields.customer_phone.value; flow(previousStep === 'products' ? 'details' : previousStep, false); notify('');
        var hint = root.querySelector('[data-phone-catalog-message]'); hint.hidden = false; hint.textContent = text('select_branch'); if (branch) { loadCatalog(1); if (view === 'orders') loadOrders(1); }
    }
    async function lookupCustomer(prefix) {
        if (!branch || locked() || !canWrite() || !fields.customer_phone.value.trim()) return;
        var generation = ++customerGeneration, controller = begin('customer'), phone = fields.customer_phone.value.trim(), requestedBranch = branch;
        var resultBox = root.querySelector('[data-phone-lookup-result]'); resultBox.hidden = false; resultBox.textContent = text('loading');
        try {
            var response = await get(urls.customers, { branch: branch, phone: phone, prefix: prefix ? 1 : 0 }, controller.signal), result = await read(response, 'lookup_error'); if (disposed || generation !== customerGeneration || requestedBranch !== branch || phone !== fields.customer_phone.value.trim() || locked()) return;
            var matches = result.matches || result.customers || result.items || []; resultBox.replaceChildren(); if (!matches.length) { resultBox.textContent = text('lookup_empty'); return; }
            suggestionIndex = -1; resultBox.appendChild(element('p', '', text('lookup_found'))); matches.forEach(function (customer, index) {
                var button = element('button'); button.type = 'button'; button.dataset.phoneSuggestion = String(index);
                button.appendChild(element('bdi', '', customer.phone)); button.appendChild(element('strong', '', customer.name)); button.appendChild(element('small', '', customer.address || text('address_placeholder')));
                button.addEventListener('click', function () { if (locked() || requestedBranch !== branch || phone !== fields.customer_phone.value.trim()) return;
                    clearTimeout(customerTimer); customerGeneration++; abort('customer'); fields.customer_phone.value = customer.phone || phone;
                    fields.customer_name.value = customer.name || ''; fields.address.value = customer.address || ''; fields.area.value = customer.area || ''; fields.delivery_notes.value = customer.delivery_notes || '';
                    customerRef=customer.customer_id?{id:customer.customer_id,revision:customer.customer_revision}:null;setCustomerLocation(customer);resultBox.hidden = true; changed(false); flow('details');
                }); resultBox.appendChild(button);
            });if(!prefix&&matches.length===1)resultBox.querySelector('[data-phone-suggestion]').click();
        } catch (error) { if (disposed || generation !== customerGeneration || error.name === 'AbortError') return; resultBox.textContent = error.message || text('lookup_error'); }
    }
    function validTicket(ticket, id, summary) {
        return ticket && Number.isSafeInteger(Number(ticket.id)) && Number(ticket.id) > 0 && (id === undefined || Number(ticket.id) === Number(id)) && ticket.channel === 'phone' && ['new', 'preparing', 'out_for_delivery', 'finished', 'cancelled'].includes(ticket.status) && ['unpaid', 'paid'].includes(ticket.payment_status) && ticket.branch && ticket.branch.value === branch && Number.isSafeInteger(Number(ticket.revision)) && Number(ticket.revision) > 0 && (summary || Array.isArray(ticket.items) && ticket.items.length) && scaled(ticket.total, 2) !== null;
    }
    async function fetchTicket(id, signal) {
        var response = await get(endpoint(urls.show, null, id), { branch: branch }, signal), result = await read(response, 'list_error'); if (!validTicket(result.ticket, id)) throw new Error(text('list_error')); return result.ticket;
    }
    function openModal(title) {
        modalGeneration++; abort('modal'); if (modal.hidden) modalFocus = document.activeElement; modal.hidden = false; modal.querySelector('h2').textContent = title;
        var content = root.querySelector('[data-phone-modal-content]'); content.replaceChildren(element('p', 'ph-empty', text('loading'))); modal.querySelector('[role="dialog"]').focus(); return content;
    }
    function closeModal() { if (locked()) { notify(text('locked'), false, true); return; } modalGeneration++; abort('modal'); modal.hidden = true; if (modalFocus && modalFocus.isConnected) modalFocus.focus(); }
    async function print(url) { try { if (!window.DashboardPrint) throw new Error('Missing printer'); await window.DashboardPrint.print(endpoint(url)); } catch (_) { notify(text('print_error'), false); } }
    function button(label, callback, className) { var control = element('button', className || '', text(label)); control.type = 'button'; control.addEventListener('click', callback); return control; }
    function ticketActions(ticket, container, details) {
        var paid = ticket.payment_status === 'paid', cancelled = ticket.status === 'cancelled';
        if (canWrite()&&!paid&&!cancelled&&!ticket.bill_locked)container.appendChild(button('kitchen',function(){action(ticket,'send_kitchen',true);},'is-primary'));
        if (!details) container.appendChild(button('details', function () { detailsModal(ticket.id); }));
        if (ticket.bill_print_url && !cancelled && (canWrite() || paid || ticket.bill_locked)) container.appendChild(button('receipt', function () { if (paid || ticket.bill_locked) print(ticket.receipt_url || ticket.bill_print_url); else action(ticket, 'request_bill'); }, 'is-bill'));
        if (!canWrite()) return;
        if (!paid && !cancelled) container.appendChild(button('collect', function () { settlement(ticket.id); }, 'is-warning'));
        if (!paid && !cancelled && !ticket.bill_locked) {
            if (ticket.status === 'new') container.appendChild(button('prepare', function () { action(ticket, 'prepare'); }, 'is-primary'));
            if (ticket.status === 'preparing') container.appendChild(button('dispatch', function () { action(ticket, 'dispatch'); }, 'is-primary'));
            if (ticket.status === 'out_for_delivery') container.appendChild(button('finish', function () { action(ticket, 'finish'); }, 'is-primary'));
            container.appendChild(button('edit', function () { editTicket(ticket.id); }));
            if (!ticket.kitchen_sent) container.appendChild(button('cancel', function () { var reason = window.prompt(text('cancel_prompt')); if (reason && reason.trim()) action(ticket, 'cancel', false, reason.trim()); }));
        }
    }
    function detailContent(ticket, content) {
        content.replaceChildren(); var information = element('dl', 'ph-detail-info');
        [['saved_number', ticket.number || ticket.id], ['status', text(ticket.status)], ['name', ticket.customer_name], ['phone', ticket.customer_phone], ['area', ticket.area || '—'], ['payment_status', text(ticket.payment_status === 'paid' ? 'paid' : ticket.bill_locked ? 'bill_locked' : 'unpaid')], ['address', ticket.address], ['date', ticket.created_label || dateLabel(ticket.created_at)]].forEach(function (pair) { var row = element('div', pair[0] === 'address' ? 'ph-full' : ''); row.appendChild(element('dt', '', text(pair[0]))); row.appendChild(element('dd', '', pair[1])); information.appendChild(row); }); content.appendChild(information);
        var table = element('table', 'ph-detail-table'), header = element('thead'), tr = element('tr'); ['item', 'quantity', 'line_total'].forEach(function (key) { tr.appendChild(element('th', '', text(key))); }); header.appendChild(tr); table.appendChild(header); var body = element('tbody');
        ticket.items.forEach(function (item) { var row = element('tr'), cell = element('td', '', item.name); if (item.option_label) cell.appendChild(element('small', '', item.option_label)); row.appendChild(cell); row.appendChild(element('td', '', item.quantity + ' ' + text(item.quantity_mode === 'weight' ? 'kg' : 'piece'))); row.appendChild(element('td', '', money(item.total))); body.appendChild(row); }); table.appendChild(body); content.appendChild(table);
        var summary = element('dl', 'ph-totals'); ['subtotal', 'discount', 'delivery_fee', 'tax', 'service', 'total'].forEach(function (key) { var row = element('div', key === 'total' ? 'ph-grand-total' : ''); row.appendChild(element('dt', '', text(key))); row.appendChild(element('dd', '', money(ticket[key] === undefined && key === 'delivery_fee' ? ticket.delivery : ticket[key] || '0.00') + ' ' + text('currency'))); summary.appendChild(row); }); content.appendChild(summary);
        [['order_notes', ticket.notes], ['customer_note', ticket.delivery_notes], ['discount_reason', ticket.discount_reason], ['cancel_reason', ticket.cancel_reason]].forEach(function (entry) { if (entry[1]) { var note = element('p', 'ph-detail-note'); note.appendChild(element('strong', '', text(entry[0]) + ': ')); note.appendChild(document.createTextNode(entry[1])); content.appendChild(note); } });
        var actions = element('div', 'ph-detail-actions'); ticketActions(ticket, actions, true); content.appendChild(actions); lock();
    }
    async function detailsModal(id) {
        if (locked()) return; var content = openModal(text('details')), generation = modalGeneration, controller = begin('modal');
        try { var ticket = await fetchTicket(id, controller.signal); if (disposed || modal.hidden || generation !== modalGeneration) return; detailContent(ticket, content); }
        catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(element('p', 'ph-empty', error.message || text('list_error'))); }
    }
    function card(ticket) {
        var article = element('article', 'ph-ticket'), header = element('div', 'ph-ticket-heading'); header.appendChild(element('strong', '', '#' + (ticket.number || ticket.id))); var stage = element('span', 'ph-stage', text(ticket.status)); stage.dataset.stage = ticket.status; header.appendChild(stage); article.appendChild(header); article.appendChild(element('p', 'ph-ticket-name', ticket.customer_name));if(ticket.delivery_company)article.appendChild(element('p','ph-ticket-detail','شركة التوصيل: '+ticket.delivery_company.name));
        [['fas fa-phone-alt', ticket.customer_phone], ['fas fa-map-marker-alt', ticket.area ? ticket.area + ' · ' + ticket.address : ticket.address], ['far fa-clock', ticket.created_label || dateLabel(ticket.created_at)]].forEach(function (entry) { var row = element('p', 'ph-ticket-detail'); row.appendChild(element('i', entry[0])); row.appendChild(element('span', '', entry[1] || '')); article.appendChild(row); });
        var summary = element('div', 'ph-ticket-summary'); summary.appendChild(element('strong', '', money(ticket.total) + ' ' + text('currency'))); summary.appendChild(element('span', 'ph-payment-state' + (ticket.payment_status === 'paid' ? ' is-paid' : ''), text(ticket.payment_status === 'paid' ? 'paid' : ticket.bill_locked ? 'bill_locked' : 'unpaid'))); article.appendChild(summary); var actions = element('div', 'ph-ticket-actions'); ticketActions(ticket, actions, false); article.appendChild(actions); return article;
    }
    async function loadOrders(page, quiet) {
        if (!branch || disposed || locked()) return;
        var generation = ++orderGeneration, controller = begin('orders'), list = root.querySelector('[data-phone-order-list]'); list.setAttribute('aria-busy', 'true'); if (!quiet) list.replaceChildren(element('p', 'ph-empty', text('loading')));
        try {
            var response = await get(urls.tickets, { branch: branch, status: orderStage, page: page || 1, delivery_company_id:boot.company_filter||null }, controller.signal), result = await read(response, 'list_error'); if (disposed || generation !== orderGeneration) return;
            var items = result.items || result.tickets; if (!Array.isArray(items)) throw new Error(text('list_error')); list.replaceChildren(); items.forEach(function (ticket) { if (validTicket(ticket, undefined, true)) list.appendChild(card(ticket)); }); if (!list.children.length) list.appendChild(element('p', 'ph-empty', text('empty_orders')));
            orderPagination = result.pagination || {}; orderPage = Number(orderPagination.page || 1); root.querySelector('[data-phone-order-pagination]').hidden = !(orderPagination.last_page > 1); root.querySelector('[data-phone-orders-page-label]').textContent = orderPage + ' / ' + (orderPagination.last_page || 1); lock();
        } catch (error) { if (disposed || generation !== orderGeneration || error.name === 'AbortError') return; list.replaceChildren(element('p', 'ph-empty', error.message || text('list_error'))); }
        finally { if (generation === orderGeneration) list.setAttribute('aria-busy', 'false'); }
    }
    function validateWriteResult(result, operation, recovered) {
        if (!validTicket(result.ticket, operation.ticketId) || operation.payload.expected_revision !== undefined && Number(result.ticket.revision) <= Number(operation.payload.expected_revision) || operation.type === 'save' && !recovered && result.ticket.payment_status !== 'unpaid' || operation.type === 'settle' && result.ticket.payment_status !== 'paid') throw new Error(text('uncertain'));
        if (!result.operation || result.operation.idempotency_key !== operation.payload.idempotency_key || Number(result.operation.ticket_id) !== Number(result.ticket.id)) throw new Error(text('uncertain'));
        if (operation.type === 'action' && operation.payload.action === 'request_bill' && !result.ticket.bill_locked) throw new Error(text('uncertain'));
        if (operation.type === 'save' && !recovered && !result.replayed && result.ticket.quote_hash !== operation.payload.quote_hash) throw new Error(text('uncertain'));
        var receiptPath = operation.type === 'settle' && result.receipt_url && new URL(endpoint(result.receipt_url)).pathname.match(/\/takeaway\/receipts\/([1-9][0-9]*)\/print\/?$/);
        if (operation.type === 'settle' && (!receiptPath || !result.receipt || Number(receiptPath[1]) !== Number(result.receipt.id) || !Number.isSafeInteger(Number(result.receipt.id)) || !result.receipt_url || !result.receipt.branch || result.receipt.branch.value !== branch || result.receipt.idempotency_key !== operation.payload.idempotency_key || scaled(result.receipt.total, 2) !== scaled(operation.total, 2))) throw new Error(text('uncertain'));
        if (operation.type === 'action' && operation.payload.action === 'send_kitchen' && !result.kitchen_print_url && !(result.kitchen && (result.kitchen.print_url || result.kitchen.kitchen_print_url)) && !result.ticket.kitchen_print_url) throw new Error(text('uncertain'));
    }
    function applyWrite(result, operation, recovered) {
        if(operation.type==='customer'){
            var c=result.customer;if(!result.success||!c||c.branch!==branch||!Number.isSafeInteger(Number(c.id))||!Number(c.revision))throw new Error(text('uncertain'));
            customerRef={id:Number(c.id),revision:Number(c.revision)};writing=false;uncertain=false;frozen=null;category='';root.querySelector('[data-phone-search]').value='';flow('products');notify('تم حفظ بيانات العميل. اختر الأصناف لإرسالها للفرع.',true);loadCatalog(1);if(cart.length&&!quote)calculate();return;
        }
        validateWriteResult(result, operation, recovered);
        if (operation.type === 'save') { resetDraft(); notify(text(operation.ticketId ? 'saved_edit' : 'saved'), true); }
        else { notify(text(operation.type === 'settle' ? 'collected' : operation.payload.action === 'cancel' ? 'cancelled_message' : 'ticket_updated'), true); if (editing && Number(editing.id) === Number(result.ticket.id)) resetDraft(); }
        var content = root.querySelector('[data-phone-modal-content]'); if (modal.hidden) content = openModal(text('details')); detailContent(result.ticket, content); writing = false; uncertain = false; lock(); frozen = null;
        if(operation.type==='settle' && branch)loadCatalog(catalogPage);
        if (view === 'orders') loadOrders(orderPage); else if (!loaded && branch && operation.type!=='settle') loadCatalog(catalogPage); else if (cart.length && !quote) calculate();
        if (operation.type === 'action' && operation.payload.action === 'request_bill') print(result.ticket.receipt_url || result.ticket.bill_print_url);
        else if (operation.printKitchen && !result.print_queued) print(result.kitchen_print_url || result.kitchen && (result.kitchen.print_url || result.kitchen.kitchen_print_url) || result.ticket.kitchen_print_url);
    }
    async function recover(operation) {
        var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
            var response = await get(operation.type==='customer'?urls.operations_recover:urls.recover, { branch: operation.payload.branch, idempotency_key: operation.payload.idempotency_key }, controller.signal), result = await read(response, 'write_error'); if (disposed || frozen !== operation || !result.found) return false;
            if (operation.type!=='customer'&&(!result.operation || result.operation.idempotency_key !== operation.payload.idempotency_key || Number(result.operation.ticket_id) !== Number(result.ticket && result.ticket.id))) throw new Error(text('uncertain'));
            applyWrite(result, operation, true); return true;
        } catch (_) { return false; } finally { clearTimeout(timeout); }
    }
    async function execute(operation) {
        if (writing || disposed || !operation || locked() && frozen !== operation) return;
        var wasUncertain = uncertain; frozen = operation; writing = true; modalGeneration++; orderGeneration++; customerGeneration++; editGeneration++; quoteGeneration++; catalogGeneration++; clearTimeout(quoteTimer); clearTimeout(searchTimer); abort('modal'); abort('orders'); abort('customer'); abort('edit'); abort('quote'); abort('catalog'); lock(); notify(text(uncertain ? 'uncertain' : 'saving'));
        if (uncertain && await recover(operation)) return;
        var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 25000);
        try { var response = await post(operation.url, operation.payload, controller.signal), result = await read(response, 'write_error'); if (disposed || frozen !== operation) return; applyWrite(result, operation); }
        catch (error) {
            if (disposed || frozen !== operation) return;
            if (wasUncertain || !error.status || error.status < 400 || error.status >= 500) { uncertain = true; notify(text('uncertain'), false, true); }
            else { uncertain = false; frozen = null; if (error.status === 409) { quote = null; quoteGeneration++; totals(null); } notify(error.message || text('write_error'), false, true); }
        } finally { clearTimeout(timeout); writing = false; lock(); if (!uncertain && !frozen && operation.type === 'settle' && !modal.hidden && modal.querySelector('[data-phone-settle-submit]')) settlement(operation.ticketId); }
    }
    function save() {
        if (step !== 'products') return;
        if (locked()) { if (uncertain && frozen) execute(frozen); return; }
        if (!branch || !cart.length || !canWrite()) return;
        if (!quote) { notify(text('quote_required'), false, true); return; }
        if (!fields.customer_name.value.trim() || !fields.customer_phone.value.trim() || !fields.address.value.trim()) { notify(text('required_customer')); return; }
        if (scaled(fields.delivery_fee.value, 2) === null || policy.can_discount && scaled(fields.discount.value, 2) === null) { notify(text('invalid_money')); return; }
        if (policy.can_discount && scaled(fields.discount.value, 2) > 0n && !fields.discount_reason.value.trim()) { notify(text('invalid_discount')); return; }
        if (cart.some(function (line) { return !quantity(line.quantity, line.mode); })) { notify(text('invalid_quantity')); return; }
        var values = Object.assign(payload(), { send_to_kitchen: true, idempotency_key: requestKey, quote_hash: quote.quote_hash, customer_name: fields.customer_name.value.trim(), customer_phone: fields.customer_phone.value.trim(), address: fields.address.value.trim(), area: fields.area.value.trim(), delivery_notes: fields.delivery_notes.value.trim(), notes: fields.notes.value.trim() });
        if (editing) { values.ticket_id = editing.id; values.expected_revision = editing.revision; }
        execute({ type: 'save', url: endpoint(urls.save), payload: values, ticketId: editing ? editing.id : undefined });
    }
    function action(ticket, actionName, printKitchen, reason) {
        if (locked() || ticket.bill_locked || !canWrite() || !validTicket(ticket, undefined, true)) return;
        execute({ type: 'action', url: endpoint(urls.action, null, ticket.id), ticketId: ticket.id, printKitchen: !!printKitchen, payload: { branch: branch, expected_revision: ticket.revision, idempotency_key: uuid(), action: actionName, reason: reason || '' } });
    }
    async function editTicket(id) {
        if (locked() || dirty && !window.confirm(text('new_warning'))) return;
        var controller = begin('edit'), requestedBranch = branch, generation = ++editGeneration;
        try {
            var ticket = await fetchTicket(id, controller.signal); if (disposed || generation !== editGeneration || requestedBranch !== branch || locked()) return;
            if (ticket.payment_status !== 'unpaid' || ticket.status === 'cancelled' || ticket.bill_locked || !canWrite()) { notify(text('forbidden')); return; }
            resetDraft(); editing = ticket; fields.customer_name.value = ticket.customer_name || ''; fields.customer_phone.value = ticket.customer_phone || ''; fields.address.value = ticket.address || ''; fields.area.value = ticket.area || ''; fields.delivery_notes.value = ticket.delivery_notes || ''; fields.notes.value = ticket.notes || ''; fields.delivery_fee.value = ticket.delivery_fee || ticket.delivery || '0.00'; fields.discount.value = ticket.discount || '0.00'; fields.discount_reason.value = ticket.discount_reason || '';
            customerRef=ticket.customer_id&&ticket.customer_revision?{id:ticket.customer_id,revision:ticket.customer_revision}:null;setCustomerLocation(ticket.delivery_location||{});deliveryLocation=ticket.delivery_location||null;companySelect.value=String(ticket.delivery_company&&ticket.delivery_company.id||'');
            var quotedItems = ticket.items; cart = quotedItems.map(function (line) { var product = products.get(String(line.product_id)); if (!product) product = { id: line.product_id, name: line.name, price: line.unit_price, unit_price: line.unit_price, options: [], available: true, quantity_mode: 'select' }; var choice = line.option_id ? (product.options || []).find(function (option) { return String(option.id) === String(line.option_id); }) || { id: line.option_id, label: line.option_label, price: line.unit_price } : null; return { product: product, option: choice, quantity: line.quantity_mode === 'piece' ? String(scaled(line.quantity, 3) / 1000n) : line.quantity, mode: line.quantity_mode }; });
            root.querySelector('[data-phone-edit-number]').textContent = '#' + (ticket.number || ticket.id); root.querySelector('[data-phone-discount-reason-wrap]').hidden = !(policy.can_discount && scaled(ticket.discount, 2) > 0n); modal.hidden = true; modalGeneration++; abort('modal'); switchView('compose'); flow(deliveryLocation?'products':'details', false); dirty = false; quote = ticket; renderLines(); totals(quote); lock(); notify('');
        } catch (error) { if (disposed || requestedBranch !== branch || error.name === 'AbortError') return; notify(error.message || text('list_error')); }
    }
    async function settlement(id) {
        if (locked() || !canWrite()) return;
        var content = openModal(text('collect')), generation = modalGeneration, controller = begin('modal');
        try {
            var ticket = await fetchTicket(id, controller.signal); if (disposed || generation !== modalGeneration) return;
            if (ticket.payment_status !== 'unpaid' || ticket.status === 'cancelled') { detailContent(ticket, content); return; }
            var response = await post(urls.quote, { branch: branch, ticket_id: ticket.id }, controller.signal), result = await read(response, 'quote_error'); if (disposed || generation !== modalGeneration) return; var value = result.quote || result; if (!value.quote_hash || scaled(value.total, 2) === null) throw new Error(text('quote_error'));
            content.replaceChildren(); var form = element('form', 'ph-settle-form'); form.appendChild(element('strong', 'ph-settle-total', money(value.total) + ' ' + text('currency'))); form.appendChild(element('p', 'ph-payment-note', text('payment_note')));
            var methodLabel = element('label', 'ph-field'); methodLabel.appendChild(element('span', '', text('payment_method'))); var method = element('select'); method.dataset.phoneSettleMethod = ''; ['cash', 'card', 'mobile_wallet', 'other'].forEach(function (key) { var option = element('option', '', text(key)); option.value = key; method.appendChild(option); }); methodLabel.appendChild(method); form.appendChild(methodLabel);
            var cashLabel = element('label', 'ph-field'); cashLabel.appendChild(element('span', '', text('cash_received'))); var cash = element('input'); cash.inputMode = 'decimal'; cash.maxLength = 14; cash.value = value.total; cash.dataset.phoneSettleCash = ''; cashLabel.appendChild(cash); form.appendChild(cashLabel);
            var referenceLabel = element('label', 'ph-field'); referenceLabel.hidden = true; referenceLabel.appendChild(element('span', '', text('payment_reference'))); var reference = element('input'); reference.maxLength = 100; reference.dataset.phoneSettleReference = ''; referenceLabel.appendChild(reference); form.appendChild(referenceLabel);
            var confirmation = element('label', 'ph-settle-confirm'), confirmed = element('input'); confirmed.type = 'checkbox'; confirmed.dataset.phoneSettleConfirmed = ''; confirmation.appendChild(confirmed); confirmation.appendChild(element('span', '', text('payment_confirmed'))); form.appendChild(confirmation); var submit = element('button', '', text('collect')); submit.type = 'submit'; submit.dataset.phoneSettleSubmit = ''; form.appendChild(submit); content.appendChild(form);
            method.addEventListener('change', function () { cashLabel.hidden = method.value !== 'cash'; referenceLabel.hidden = method.value === 'cash'; confirmed.checked = false; });
            form.addEventListener('submit', function (event) { event.preventDefault(); if (locked() || generation !== modalGeneration) return; if (!confirmed.checked) { notify(text('confirm_payment')); return; } var received = scaled(cash.value, 2); if (method.value === 'cash' && (received === null || received < scaled(value.total, 2))) { notify(text('cash_insufficient')); return; }
                execute({ type: 'settle', url: endpoint(urls.settle, null, ticket.id), ticketId: ticket.id, total: value.total, payload: { branch: branch, expected_revision: ticket.revision, idempotency_key: uuid(), quote_hash: value.quote_hash, payment_method: method.value, cash_received: method.value === 'cash' ? decimal(received, 2) : null, payment_confirmed: true, payment_reference: reference.value.trim() } });
            }); lock();
        } catch (error) { if (disposed || generation !== modalGeneration || error.name === 'AbortError') return; content.replaceChildren(element('p', 'ph-empty', error.message || text('quote_error'))); }
    }
    function mayLeave() { if (locked()) { notify(text('locked'), false, true); return false; } return !dirty || window.confirm(text('unsaved')); }

    listen(root, 'click', function (event) {
        var control = event.target.closest('button'); if (!control || !root.contains(control)) return;
        if (control.hasAttribute('data-phone-retry')) { if (uncertain && frozen) return execute(frozen); if (view === 'orders') return loadOrders(orderPage); if (!loaded) return loadCatalog(catalogPage); return calculate(); }
        if (locked()) return;
        if (control.hasAttribute('data-phone-save')) return save();
        if (control.hasAttribute('data-phone-lookup') || control.hasAttribute('data-phone-next-number')) return nextNumber();
        if(control.hasAttribute('data-phone-clear-company')){boot.company_filter=0;control.hidden=true;return loadOrders(1);}
        if (control.hasAttribute('data-phone-next-details')) return nextDetails();
        if (control.hasAttribute('data-phone-edit-customer')) return flow('details');
        if (control.hasAttribute('data-phone-modal-close')) return closeModal();
        if (control.hasAttribute('data-phone-clear')) { if (!dirty || window.confirm(text('new_warning'))) { resetDraft(); notify(''); } return; }
        if (control.dataset.phoneView) { if (control.dataset.phoneView === 'compose' && view === 'compose') { if (!dirty || window.confirm(text('new_warning'))) resetDraft(); } return switchView(control.dataset.phoneView); }
        if (control.dataset.phoneProduct !== undefined) return addProduct(products.get(control.dataset.phoneProduct));
        if (control.dataset.phoneRemove !== undefined) { if (editing && editing.kitchen_sent && cart.length <= 1) return; cart.splice(Number(control.dataset.phoneRemove), 1); return changed(true); }
        if (control.dataset.phoneCategory !== undefined) { category = category === control.dataset.phoneCategory ? '' : control.dataset.phoneCategory; return loadCatalog(1); }
        if (control.dataset.phonePage) return loadCatalog(catalogPage + (control.dataset.phonePage === 'next' ? 1 : -1));
        if (control.dataset.phoneOrdersPage) return loadOrders(orderPage + (control.dataset.phoneOrdersPage === 'next' ? 1 : -1));
        if (control.dataset.phoneStage) { orderStage = control.dataset.phoneStage; root.querySelectorAll('[data-phone-stage]').forEach(function (tab) { var selected = tab.dataset.phoneStage === orderStage; tab.classList.toggle('is-active', selected); tab.setAttribute('aria-pressed', String(selected)); }); return loadOrders(1); }
        if (control.hasAttribute('data-phone-refresh')) return loadOrders(orderPage);
        if (control.dataset.phoneMode) { mode = control.dataset.phoneMode; root.querySelectorAll('[data-phone-mode]').forEach(function (tab) { tab.setAttribute('aria-pressed', String(tab.dataset.phoneMode === mode)); }); root.querySelector('[data-phone-quantity]').value = mode === 'piece' ? '1' : '1.000'; }
    });
    listen(root, 'change', function (event) {
        var input = event.target;
        if (locked()) return;
        if (input.dataset.phoneLineQuantity !== undefined) { var line = cart[Number(input.dataset.phoneLineQuantity)], amount = line && quantity(input.value, line.mode); if (!amount) { if (line) input.value = line.quantity; notify(text('invalid_quantity')); return; } line.quantity = amount.value; return changed(true); }
        if (input.dataset.phoneLineOption !== undefined) { var selectedLine = cart[Number(input.dataset.phoneLineOption)]; if (!selectedLine) return; var previousOption = selectedLine.option; selectedLine.option = input.value ? (selectedLine.product.options || []).find(function (option) { return String(option.id) === input.value; }) || selectedLine.option : null; var duplicate = cart.find(function (line) { return line !== selectedLine && lineKey(line) === lineKey(selectedLine); }); if (duplicate) { var combined = quantity(decimal(scaled(duplicate.quantity, 3) + scaled(selectedLine.quantity, 3), 3), duplicate.mode); if (!combined) { selectedLine.option = previousOption; input.value = String(previousOption && previousOption.id || ''); notify(text('invalid_quantity')); return; } duplicate.quantity = combined.value; cart.splice(cart.indexOf(selectedLine), 1); } return changed(true); }
    });
    Object.keys(fields).forEach(function (key) { listen(fields[key], 'input', function () { if(['customer_name','address','area','delivery_notes'].includes(key)){customerGeneration++;abort('customer');}if(['address','area'].includes(key))addressChanged();if(key==='customer_phone')customerRef=null; if (key === 'customer_phone') { customerGeneration++; abort('customer'); clearTimeout(customerTimer); suggestionIndex = -1; root.querySelector('[data-phone-lookup-result]').hidden = true; if (inputPhoneDigits().length >= 3) customerTimer = setTimeout(function () { lookupCustomer(true); }, 250); } if (key === 'discount') root.querySelector('[data-phone-discount-reason-wrap]').hidden = !(policy.can_discount && scaled(fields.discount.value, 2) > 0n); changed(['discount', 'discount_reason', 'delivery_fee'].includes(key)); }); });
    listen(fields.customer_phone, 'keydown', function (event) {
        var box = root.querySelector('[data-phone-lookup-result]'), suggestions = box.querySelectorAll('[data-phone-suggestion]');
        if (event.key === 'Escape') { clearTimeout(customerTimer); customerGeneration++; abort('customer'); box.hidden = true; return; }
        if (box.hidden || !suggestions.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault(); suggestionIndex = (suggestionIndex + (event.key === 'ArrowDown' ? 1 : -1) + suggestions.length) % suggestions.length;
            suggestions.forEach(function (button, index) { button.classList.toggle('is-highlighted', index === suggestionIndex); }); suggestions[suggestionIndex].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter' && suggestionIndex >= 0) { event.preventDefault(); event.stopPropagation(); suggestions[suggestionIndex].click(); }
    });
    listen(root.querySelector('[data-phone-locate]'),'click',function(){lastGeocoded='';locateAddress();});listen(root.querySelector('[data-phone-confirm-location]'),'click',function(){confirmLocation(false);});
    [latInput,lngInput].forEach(function(input){listen(input,'input',function(){invalidateLocation();previewLocation();});});listen(companySelect,'change',function(){changed(false);});
    listen(branchSelect, 'change', changeBranch); if (window.jQuery) window.jQuery(branchSelect).on('change.phoneOrders', changeBranch);
    listen(root.querySelector('[data-phone-search]'), 'input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(function () { loadCatalog(1); }, 250); });
    listen(modal, 'click', function (event) { if (event.target === modal) closeModal(); });
    listen(document, 'keydown', function (event) { if (disposed) return; if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && modal.hidden && view === 'compose' && step !== 'products' && root.contains(event.target) && !event.target.matches('button')) { event.preventDefault(); if (step === 'number') nextNumber(); else nextDetails(); return; } if (event.key === 'Escape' && !modal.hidden) { closeModal(); return; } if (!modal.hidden && event.key === 'Tab') { var controls = modal.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled)'); if (!controls.length) { event.preventDefault(); return; } var first = controls[0], last = controls[controls.length - 1]; if (event.shiftKey && (document.activeElement === first || document.activeElement === modal.querySelector('[role="dialog"]'))) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } } if (event.key === 'F9' && modal.hidden && view === 'compose') { event.preventDefault(); save(); } });
    listen(window, 'beforeunload', function (event) { if (locked() || dirty) { event.preventDefault(); event.returnValue = ''; } });
    if (window.DashboardSPA && window.DashboardSPA.onBeforeLeave) window.DashboardSPA.onBeforeLeave(mayLeave);
    else listen(document, 'click', function (event) { var link = event.target.closest('a[href]'); if (link && !root.contains(link) && link.target !== '_blank' && !mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
    if (window.DashboardSPA) window.DashboardSPA.onCleanup(function () { disposed = true;if(addressSearch)addressSearch.destroy();locationGeneration++;contextGeneration++;clearTimeout(locationPreviewTimer);if(locationPicker)locationPicker.destroy();clearTimeout(customerTimer); clearTimeout(receiverTimer); catalogGeneration++; quoteGeneration++; orderGeneration++; customerGeneration++; modalGeneration++; editGeneration++; Object.keys(controllers).forEach(abort); clearTimeout(searchTimer); clearTimeout(quoteTimer); listeners.forEach(function (remove) { remove(); }); if (window.jQuery) window.jQuery(branchSelect).off('.phoneOrders'); });
    root.querySelector('.ph-customer').appendChild(root.querySelector('[data-phone-next-details]'));
    renderLines(); totals(null); flow('number'); if (branch) {loadCatalog(1);loadDeliveryContext();}
    receiverTimer = setTimeout(pollReceiver, 1000);
    listen(document, 'dashboard:branch-print', function (event) { root.querySelector('[data-phone-printer-status]').textContent = event.detail.status; if (event.detail.latest !== lastIncoming) { lastIncoming = event.detail.latest; if (view === 'orders' && !locked()) loadOrders(orderPage, true); } });
    if (new URL(location.href).searchParams.get('view') === 'orders') switchView('orders');
}());
