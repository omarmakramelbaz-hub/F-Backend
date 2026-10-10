(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardWhatsAppOrders) { window.DashboardWhatsAppOrders.mount(); return; }
    var active = null;

    function mount() {
        var host = document.getElementById('whatsapp-inbox');
        var bootstrap = document.getElementById('whatsapp-orders-bootstrap');
        if (!host || !bootstrap || (active && active.host === host)) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        var panel = host.querySelector('[data-wa-orders]');
        if (!panel) return;
        var labels = config.labels || {}, fields = {}, controllers = new Map();
        var form = panel.querySelector('[data-wa-order-form]');
        var status = panel.querySelector('[data-wa-order-status]');
        var proposed = panel.querySelector('[data-wa-order-proposed]');
        var items = panel.querySelector('[data-wa-order-items]');
        var quoteBox = panel.querySelector('[data-wa-order-quote]');
        var analyze = panel.querySelector('[data-wa-order-analyze]');
        var reload = panel.querySelector('[data-wa-order-reload]');
        var calculate = panel.querySelector('[data-wa-order-calculate]');
        var dispatch = panel.querySelector('[data-wa-order-dispatch]');
        var add = panel.querySelector('[data-wa-order-add]');
        var search = panel.querySelector('[data-wa-order-search]');
        var searchButton = panel.querySelector('[data-wa-order-search-button]');
        var more = panel.querySelector('[data-wa-order-more]');
        var picker = panel.querySelector('[data-wa-order-draft]');
        var pickerLabel = panel.querySelector('[data-wa-order-draft-label]');
        var customerLookup = panel.querySelector('[data-wa-order-customer-lookup]');
        var customerResults = panel.querySelector('[data-wa-order-customer-results]');
        var addressSearch = panel.querySelector('[data-wa-order-address-search]');
        var addressResults = panel.querySelector('[data-wa-order-address-results]');
        var mapElement = panel.querySelector('[data-wa-order-map]');
        var locationStatus = panel.querySelector('[data-wa-order-location-status]');
        var confirmPin = panel.querySelector('[data-wa-order-confirm-pin]');
        var distance = panel.querySelector('[data-wa-order-distance]');
        ['customer_name', 'customer_phone', 'address', 'area', 'delivery_notes', 'branch',
            'latitude', 'longitude', 'location_confirmed'].forEach(function (name) {
            fields[name] = panel.querySelector('[data-wa-order-field="' + name + '"]');
        });
        var conversation = 0, generation = 0, editVersion = 0, closed = false, denied = false;
        var meta = null, drafts = [], draft = null, rows = [], catalog = new Map();
        var catalogBranch = '', catalogPage = 1, catalogLast = 1, catalogSearch = '', catalogRequest = 0;
        var quoteToken = null, busy = '', catalogBusy = false, pending = false;
        var locationPicker = null, deliverySettings = null, locationVersion = 0, mapRequest = 0;
        var locationBusy = false, localEdited = false, stateTimer = null;

        function node(tag, className, text) {
            var element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== undefined && text !== null) element.textContent = String(text);
            return element;
        }
        function option(select, value, text, disabled) {
            var item = node('option', '', text); item.value = String(value); item.disabled = Boolean(disabled);
            select.appendChild(item);
        }
        function note(text, error) {
            status.textContent = text || '';
            status.classList.toggle('is-error', Boolean(error));
        }
        function abort() { controllers.forEach(function (controller) { controller.abort(); }); controllers.clear(); }
        function invalidate(message) {
            quoteToken = null; quoteBox.textContent = ''; quoteBox.hidden = true; editVersion++;
            if (message && draft) note(message, false);
            controls();
        }
        function clear(reason) {
            generation++; abort(); conversation = 0; draft = null; drafts = []; rows = [];
            resetMap(); window.clearTimeout(stateTimer); stateTimer = null; localEdited = false;
            catalog.clear(); catalogBranch = ''; catalogRequest++; quoteToken = null; busy = ''; catalogBusy = false; pending = false;
            Object.keys(fields).forEach(function (name) {
                if (name === 'location_confirmed') fields[name].checked = false;
                else fields[name].value = '';
            });
            items.textContent = ''; proposed.textContent = ''; proposed.hidden = true;
            quoteBox.textContent = ''; quoteBox.hidden = true; form.hidden = true;
            picker.textContent = ''; pickerLabel.hidden = true; search.value = ''; more.hidden = true;
            panel.hidden = true; note(reason === 'denied' ? labels.denied : '', reason === 'denied');
            controls();
        }
        function controls() {
            var allowed = !closed && !denied && conversation && meta && meta.available && meta.can_checkout
                && navigator.onLine !== false && !document.hidden;
            var reviewing = allowed && draft && ['REVIEW', 'READY'].indexOf(draft.status) !== -1 && !pending;
            analyze.disabled = !allowed || !meta.ai_ready || Boolean(busy);
            reload.disabled = !conversation || Boolean(busy) || closed || denied;
            calculate.disabled = !reviewing || Boolean(busy) || catalogBusy || locationBusy;
            dispatch.disabled = !reviewing || Boolean(busy) || !quoteToken;
            add.disabled = !reviewing || Boolean(busy) || rows.length >= 60;
            searchButton.disabled = !reviewing || !fields.branch.value || Boolean(busy) || catalogBusy;
            more.disabled = searchButton.disabled;
            picker.disabled = Boolean(busy);
            customerLookup.disabled = !reviewing || Boolean(busy) || !fields.branch.value || !fields.customer_phone.value.trim();
            addressSearch.disabled = !reviewing || Boolean(busy) || !fields.branch.value || !fields.address.value.trim();
            confirmPin.disabled = !reviewing || Boolean(busy) || locationBusy || !validPin();
            Object.keys(fields).forEach(function (name) { fields[name].disabled = !reviewing || Boolean(busy); });
            rows.forEach(function (row) {
                row.product.disabled = !reviewing || Boolean(busy) || !fields.branch.value || catalogBusy;
                row.option.disabled = !reviewing || Boolean(busy) || !row.product.value;
                row.quantity.disabled = !reviewing || Boolean(busy); row.mode.disabled = !reviewing || Boolean(busy);
                row.remove.disabled = !reviewing || Boolean(busy);
            });
        }
        function endpoint(key, values) {
            var target = new URL(meta && meta.urls && meta.urls[key] || '', window.location.href);
            if (!meta || !meta.urls || !meta.urls[key] || target.origin !== new URL(window.location.href).origin) throw new Error('Unavailable');
            Object.keys(values || {}).forEach(function (name) { target.searchParams.set(name, String(values[name])); });
            return target.href;
        }
        function validPin() {
            var lat = fields.latitude.value, lng = fields.longitude.value;
            return lat !== '' && lng !== '' && Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))
                && Math.abs(Number(lat)) <= 90 && Math.abs(Number(lng)) <= 180 && !(Number(lat) === 0 && Number(lng) === 0);
        }
        function locationLocked() {
            return closed || denied || !draft || form.hidden || busy || pending || !meta || !meta.can_checkout
                || navigator.onLine === false || document.hidden;
        }
        function resetMap() {
            mapRequest++; locationVersion++; locationBusy = false; deliverySettings = null;
            if (locationPicker) locationPicker.destroy(); locationPicker = null;
            [customerResults, addressResults].forEach(function (box) { box.textContent = ''; box.hidden = true; });
            locationStatus.textContent = ''; distance.textContent = '';
        }
        function invalidateLocation(clearPin) {
            locationVersion++; locationBusy = false; fields.location_confirmed.checked = false;
            ['delivery', 'address', 'customers'].forEach(function (key) { if (controllers.has(key)) controllers.get(key).abort(); });
            addressResults.textContent = ''; addressResults.hidden = true; distance.textContent = '';
            if (locationPicker) { locationPicker.invalidate(); locationPicker.route(null); }
            if (clearPin) {
                fields.latitude.value = ''; fields.longitude.value = '';
                if (locationPicker) locationPicker.clear();
            }
            invalidate(labels.quote_expired);
        }
        function setPin(lat, lng) {
            invalidateLocation(false); fields.latitude.value = String(lat); fields.longitude.value = String(lng);
            if (!validPin()) { invalidateLocation(true); return; }
            if (locationPicker) locationPicker.set(lat, lng);
            locationStatus.textContent = labels.pin_review; localEdited = true; controls();
        }
        function setupMap() {
            var branch = fields.branch.value, current = generation, selection = conversation, version = ++mapRequest;
            if (locationPicker) locationPicker.destroy(); locationPicker = null; deliverySettings = null;
            if (!branch || form.hidden || !meta || !meta.urls) return;
            if (!window.DashboardLocationPicker) { locationStatus.textContent = labels.map_unavailable; return; }
            request(endpoint('delivery_settings', {branch: branch}), 'delivery-settings').then(function (data) {
                if (closed || current !== generation || selection !== conversation || branch !== fields.branch.value || version !== mapRequest) return;
                deliverySettings = data.settings;
                var ready = deliverySettings && deliverySettings.ready, maps = meta.maps || {};
                locationStatus.textContent = ready ? labels.pin_review : labels.branch_policy_missing;
                locationPicker = window.DashboardLocationPicker.create(mapElement, {
                    key: maps.browser_key, preferOpenMap: Boolean(maps.open_enabled), routeOnly: Boolean(maps.open_enabled), tileUrl: maps.tile_url,
                    latitude: fields.latitude.value, longitude: fields.longitude.value,
                    center: ready ? [deliverySettings.latitude, deliverySettings.longitude] : null,
                    origin: ready ? [deliverySettings.latitude, deliverySettings.longitude] : null,
                    locked: locationLocked, unavailable: function () { if (!closed) { invalidateLocation(true); locationStatus.textContent = labels.map_unavailable; } },
                    change: function (lat, lng) { if (!locationLocked()) setPin(lat, lng); }
                });
                locationPicker.resize(); controls();
            }).catch(function (error) { failure(error, current); });
        }
        function loadCustomers() {
            if (locationLocked() || !fields.branch.value || !fields.customer_phone.value.trim()) return;
            var branch = fields.branch.value, phone = fields.customer_phone.value.trim(), current = generation, selection = conversation, version = locationVersion;
            customerResults.textContent = ''; customerResults.hidden = false;
            request(endpoint('customers', {branch: branch, phone: phone}), 'customers').then(function (data) {
                if (closed || current !== generation || selection !== conversation || version !== locationVersion
                    || branch !== fields.branch.value || phone !== fields.customer_phone.value.trim() || locationLocked()) return;
                customerResults.textContent = '';
                var matches = Array.isArray(data.items) ? data.items.slice(0, 8) : [];
                if (!matches.length) customerResults.appendChild(node('p', '', labels.lookup_empty));
                matches.forEach(function (customer) {
                    var button = node('button', 'wa-inbox-button', [customer.name, customer.address, customer.area].filter(Boolean).join(' · '));
                    button.type = 'button'; button.addEventListener('click', function () {
                        if (locationLocked() || current !== generation || selection !== conversation || branch !== fields.branch.value || phone !== fields.customer_phone.value.trim()) return;
                        invalidateLocation(true);
                        ['customer_name', 'address', 'area', 'delivery_notes'].forEach(function (name) {
                            var key = name === 'customer_name' ? 'name' : name; fields[name].value = customer[key] || '';
                        });
                        if (customer.latitude !== null && customer.longitude !== null && customer.latitude !== undefined && customer.longitude !== undefined) setPin(customer.latitude, customer.longitude);
                        localEdited = true; locationStatus.textContent = validPin() ? labels.pin_review : labels.pin_required;
                        controls();
                    }); customerResults.appendChild(button);
                });
            }).catch(function (error) { failure(error, current); });
        }
        function searchAddress() {
            if (locationLocked() || !fields.branch.value || fields.address.value.trim().length < 2) return;
            var query = fields.address.value.trim().slice(0, 240), branch = fields.branch.value;
            var current = generation, selection = conversation, version = locationVersion, pickerAtStart = locationPicker;
            addressResults.textContent = ''; addressResults.hidden = false; locationStatus.textContent = labels.loading;
            var searchPromise = meta.maps && meta.maps.open_enabled
                ? request(endpoint('address_suggestions', {branch: branch, query: query}), 'address').then(function (data) { return data.items || []; })
                : pickerAtStart ? pickerAtStart.suggest(query) : Promise.reject(new Error('Unavailable'));
            searchPromise.then(function (results) {
                if (closed || current !== generation || selection !== conversation || version !== locationVersion
                    || branch !== fields.branch.value || query !== fields.address.value.trim().slice(0, 240) || locationLocked()) return;
                locationStatus.textContent = results.length ? labels.pin_review : labels.address_empty;
                results.slice(0, 6).forEach(function (item) {
                    var button = node('button', 'wa-inbox-button', item.label); button.type = 'button';
                    button.addEventListener('click', function () {
                        if (locationLocked() || version !== locationVersion || current !== generation || branch !== fields.branch.value || selection !== conversation) return;
                        var resolve = meta.maps && meta.maps.open_enabled ? Promise.resolve(item) : pickerAtStart.resolve(item);
                        resolve.then(function (point) {
                            if (locationLocked() || current !== generation || version !== locationVersion || branch !== fields.branch.value || selection !== conversation) return;
                            // Preserve the full delivery address typed by the operator; suggestions choose the pin only.
                            setPin(point.latitude, point.longitude);
                        }).catch(function (error) { failure(error, current); });
                    }); addressResults.appendChild(button);
                });
            }).catch(function (error) { failure(error, current); });
        }
        function quoteDelivery() {
            if (locationLocked() || !validPin() || locationBusy) return;
            invalidate(labels.quote_expired); fields.location_confirmed.checked = false;
            var branch = fields.branch.value, current = generation, selection = conversation, version = ++locationVersion;
            locationBusy = true; controls();
            request(endpoint('delivery_quote'), 'delivery', {branch: branch, latitude: Number(fields.latitude.value),
                longitude: Number(fields.longitude.value), location_confirmed: true}).then(function (data) {
                if (closed || current !== generation || selection !== conversation || version !== locationVersion || branch !== fields.branch.value || locationLocked()) return;
                fields.location_confirmed.checked = true; localEdited = true; showDelivery(data.delivery);
            }).catch(function (error) { failure(error, current); }).finally(function () {
                if (current === generation && version === locationVersion) { locationBusy = false; controls(); }
            });
        }
        function showDelivery(delivery) {
            if (!delivery) return;
            if (locationPicker && Array.isArray(delivery.route_path)) locationPicker.route(delivery.route_path);
            distance.textContent = (delivery.method === 'road_osrm' ? labels.route_distance : labels.direct_distance)
                + ': ' + String(delivery.distance_km || '') + ' ' + labels.km + ' × ' + String(delivery.km_price || '')
                + ' ' + labels.currency + ' = ' + String(delivery.delivery_fee || '') + ' ' + labels.currency;
        }
        function request(url, key, body) {
            if (closed || denied || navigator.onLine === false || document.hidden) return Promise.reject(new Error('Unavailable'));
            if (controllers.has(key)) controllers.get(key).abort();
            var controller = new AbortController(); controllers.set(key, controller);
            var options = {credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
            if (body !== undefined) {
                options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-TOKEN'] = config.csrf; options.body = JSON.stringify(body);
            }
            return fetch(url, options).then(function (response) {
                if (response.redirected) { var expired = new Error('Unavailable'); expired.status = 401; throw expired; }
                if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/json')) {
                    var error = new Error('Unavailable'); error.status = response.status; throw error;
                }
                return response.json();
            }).then(function (data) {
                if (!data || data.success !== true) throw new Error('Unavailable');
                return data;
            }).finally(function () { if (controllers.get(key) === controller) controllers.delete(key); });
        }
        function failure(error, current) {
            if (closed || current !== generation || error && error.name === 'AbortError') return;
            invalidate();
            if (error && [401, 403, 419].indexOf(error.status) !== -1) {
                denied = true; clear('denied');
                if (window.DashboardWhatsAppInbox) window.DashboardWhatsAppInbox.destroy();
                note(labels.denied, true); panel.hidden = false;
            } else if (error && error.status === 409) {
                note(labels.changed, true); loadState(true);
            } else note(error && error.status === 422 ? labels.invalid
                : navigator.onLine === false ? labels.offline : labels.unavailable, true);
        }
        function loadMeta(current) {
            return request(config.meta_url, 'meta').then(function (data) {
                if (closed || current !== generation) return null;
                meta = data; fields.branch.textContent = ''; option(fields.branch, '', labels.choose_branch);
                (data.branches || []).forEach(function (branch) {
                    if (/^f:[1-9][0-9]{0,18}$/.test(branch.value || '')) option(fields.branch, branch.value, branch.name);
                });
                return data;
            });
        }
        function choose(event) {
            var id = Number(event && event.detail && event.detail.conversation);
            if (!Number.isSafeInteger(id) || id < 1 || closed || denied || document.hidden || navigator.onLine === false) return;
            clear(); conversation = id; panel.hidden = false; note(labels.loading); controls();
            var current = generation;
            loadMeta(current).then(function () {
                if (closed || current !== generation || conversation !== id) return;
                return loadState();
            }).catch(function (error) { failure(error, current); });
        }
        function loadState(changed) {
            if (!conversation || closed || denied) return Promise.resolve();
            var selected = conversation, current = generation;
            return request(config.conversations_base_url + '/' + selected + '/orders', 'state').then(function (data) {
                if (closed || current !== generation || conversation !== selected) return;
                drafts = Array.isArray(data.drafts) ? data.drafts.slice(0, 10) : [];
                pending = Boolean(data.pending_analysis); picker.textContent = '';
                drafts.forEach(function (item) { option(picker, item.id, '#' + item.id + ' · ' + statusText(item)); });
                pickerLabel.hidden = drafts.length < 2;
                renderDraft(drafts[0] || null);
                if (!meta.available || data.available === false) note(labels.unavailable, true);
                else if (changed) note(labels.changed, true);
                controls();
                scheduleState();
            }).catch(function (error) { failure(error, current); });
        }
        function scheduleState() {
            window.clearTimeout(stateTimer); stateTimer = null;
            if (closed || denied || !conversation || !meta || meta.mode !== 'auto') return;
            stateTimer = window.setTimeout(function () {
                stateTimer = null;
                // Server automation owns analysis and checkout; the browser only reads its result.
                if (closed || denied || document.hidden || navigator.onLine === false) return;
                if (busy || localEdited) { scheduleState(); return; }
                loadState();
            }, pending ? 5000 : 30000);
        }
        function statusText(value) {
            return value && value.status === 'DISPATCHED' ? labels.dispatched
                : value && value.status === 'READY' ? labels.ready
                : value && value.status === 'NONE' ? labels.none
                : value && value.status === 'CANCELLED' ? labels.cancelled
                : value && value.status === 'FAILED' ? labels.failed : labels.review;
        }
        function showProposed(data) {
            proposed.textContent = ''; proposed.hidden = !data;
            if (!data) return;
            proposed.appendChild(node('strong', '', labels.proposed));
            if (data.branch_hint) proposed.appendChild(node('p', '', labels.proposed_branch + ': ' + data.branch_hint));
            if (data.approximate_total) proposed.appendChild(node('p', '', labels.approximate + ': ' + data.approximate_total + ' ' + labels.currency));
            var list = node('ul');
            (data.items || []).forEach(function (item) {
                list.appendChild(node('li', '', [item.name, item.quantity, item.option_hint].filter(Boolean).join(' · ')));
            });
            proposed.appendChild(list);
        }
        function renderDraft(value) {
            resetMap(); localEdited = false;
            invalidate(); draft = value; rows = []; catalog.clear(); catalogBranch = ''; items.textContent = '';
            showProposed(value && value.data);
            Object.keys(fields).forEach(function (name) {
                if (name === 'location_confirmed') fields[name].checked = false;
                else fields[name].value = '';
            });
            form.hidden = !value || ['REVIEW', 'READY'].indexOf(value.status) === -1;
            if (!value) { note(meta && !meta.ai_ready ? labels.not_configured : meta && meta.mode === 'auto' ? labels.empty_auto : labels.empty); controls(); return; }
            var reviewed = value.review || {}, customer = value.data && value.data.customer || {};
            ['customer_name', 'customer_phone', 'address', 'area', 'delivery_notes'].forEach(function (name) {
                var key = {customer_name: 'name', customer_phone: 'phone', delivery_notes: 'notes'}[name] || name;
                fields[name].value = reviewed[name] || customer[key] || '';
            });
            fields.branch.value = reviewed.branch || '';
            fields.latitude.value = reviewed.latitude === undefined || reviewed.latitude === null ? '' : String(reviewed.latitude);
            fields.longitude.value = reviewed.longitude === undefined || reviewed.longitude === null ? '' : String(reviewed.longitude);
            fields.location_confirmed.checked = reviewed.location_confirmed === true;
            var source = Array.isArray(reviewed.items) && reviewed.items.length ? reviewed.items : value.data && value.data.items || [];
            source.slice(0, 60).forEach(function (item) { addRow(item); });
            if (!rows.length && !form.hidden) addRow({});
            note(pending ? meta && meta.mode === 'auto' ? labels.pending_auto : labels.pending : statusText(value) + (value.ticket_id ? ' ' + labels.ticket + ': ' + value.ticket_id : ''));
            if (!form.hidden && fields.branch.value) { loadCatalog(false); setupMap(); }
            controls();
        }
        function productOptions(row) {
            var selected = row.product.value || row.productId || '';
            row.product.textContent = ''; option(row.product, '', labels.choose_product);
            Array.from(catalog.values()).forEach(function (product) {
                option(row.product, product.id, product.name + (product.available ? '' : ' · ' + labels.unavailable_product), !product.available);
            });
            row.product.value = String(selected);
            if (!catalog.has(Number(selected))) row.product.value = '';
            optionOptions(row);
        }
        function optionOptions(row) {
            var selected = row.option.value || row.optionId || '';
            var product = catalog.get(Number(row.product.value));
            row.option.textContent = ''; option(row.option, '', labels.base);
            if (product) (product.options || []).forEach(function (item) { option(row.option, item.id, item.label); });
            row.option.value = selected;
            if (!product || !(product.options || []).some(function (item) { return item.id === selected; })) row.option.value = '';
        }
        function addRow(item) {
            var element = node('div', 'wa-orders-item');
            function control(labelText, tag) {
                var label = node('label', '', labelText), input = node(tag); label.appendChild(input); element.appendChild(label); return input;
            }
            if (item.name || item.option_hint) element.appendChild(node('p', 'wa-orders-item-hint', labels.hint + ': ' + [item.name, item.option_hint].filter(Boolean).join(' · ')));
            var row = {element: element, productId: item.product_id || '', optionId: item.option_id || ''};
            row.product = control(labels.choose_product, 'select'); row.product.dataset.waOrderProduct = '1';
            row.option = control(labels.option, 'select'); row.quantity = control(labels.quantity, 'input');
            row.quantity.type = 'number'; row.quantity.min = '0.001'; row.quantity.max = '9999.999'; row.quantity.step = '0.001'; row.quantity.required = true;
            row.quantity.value = item.quantity || ''; row.quantity.dir = 'ltr';
            row.mode = control(labels.choose_mode, 'select'); option(row.mode, '', labels.choose_mode);
            option(row.mode, 'piece', labels.piece); option(row.mode, 'weight', labels.weight);
            row.mode.value = ['piece', 'weight'].indexOf(item.quantity_mode) !== -1 ? item.quantity_mode : '';
            row.remove = node('button', 'wa-inbox-button', labels.remove); row.remove.type = 'button';
            row.remove.dataset.waOrderRemove = '1'; element.appendChild(row.remove);
            rows.push(row); items.appendChild(element); productOptions(row); controls();
        }
        function loadCatalog(previous) {
            var branch = fields.branch.value;
            if (!branch || !conversation || closed || denied || catalogBusy) return Promise.resolve();
            var current = generation, selection = conversation, version = editVersion, catalogCurrent = ++catalogRequest;
            var term = search.value.trim(), page = previous ? catalogPage + 1 : 1;
            if (previous && catalogPage >= catalogLast) return Promise.resolve();
            var url = new URL(config.catalog_url, window.location.href);
            url.searchParams.set('branch', branch); url.searchParams.set('per_page', '100');
            url.searchParams.set('page', String(page)); if (term) url.searchParams.set('search', term);
            catalogBusy = true; controls();
            return request(url.href, 'catalog').then(function (data) {
                if (closed || current !== generation || selection !== conversation || fields.branch.value !== branch || catalogCurrent !== catalogRequest) return;
                if (catalogBranch !== branch) catalog.clear(); catalogBranch = branch;
                (data.items || []).forEach(function (product) { catalog.set(Number(product.id), product); });
                catalogPage = Number(data.pagination && data.pagination.page) || 1;
                catalogLast = Number(data.pagination && data.pagination.last_page) || 1; catalogSearch = term;
                more.hidden = catalogPage >= catalogLast;
                rows.forEach(productOptions);
                // Newly loaded catalog cannot restore an invalidated price token.
                if (version !== editVersion) quoteToken = null;
            }).catch(function (error) { failure(error, current); }).finally(function () {
                if (current === generation && catalogCurrent === catalogRequest) { catalogBusy = false; controls(); }
            });
        }
        function payload() {
            if (!draft || !Number.isSafeInteger(Number(draft.revision)) || !/^f:[1-9][0-9]{0,18}$/.test(fields.branch.value)) return null;
            var latitude = Number(fields.latitude.value), longitude = Number(fields.longitude.value);
            if (!fields.customer_name.value.trim() || !fields.customer_phone.value.trim() || !fields.address.value.trim()
                || !fields.latitude.value || !fields.longitude.value || !Number.isFinite(latitude) || !Number.isFinite(longitude)
                || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180 || !fields.location_confirmed.checked || !rows.length) return null;
            var lines = [];
            for (var index = 0; index < rows.length; index++) {
                var row = rows[index], product = catalog.get(Number(row.product.value)), quantity = row.quantity.value;
                if (!product || !product.available || !/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/.test(quantity) || Number(quantity) <= 0
                    || ['piece', 'weight'].indexOf(row.mode.value) === -1 || row.mode.value === 'piece' && !Number.isInteger(Number(quantity))) return null;
                var selectedOption = row.option.value || null;
                if (selectedOption && !(product.options || []).some(function (item) { return item.id === selectedOption; })) return null;
                lines.push({product_id: Number(row.product.value), quantity: quantity, quantity_mode: row.mode.value, option_id: selectedOption});
            }
            return {expected_revision: Number(draft.revision), branch: fields.branch.value,
                customer_name: fields.customer_name.value.trim(), customer_phone: fields.customer_phone.value.trim(),
                address: fields.address.value.trim(), area: fields.area.value.trim() || null,
                delivery_notes: fields.delivery_notes.value.trim() || null, latitude: latitude, longitude: longitude,
                location_confirmed: true, items: lines};
        }
        function action(kind) {
            if (closed || denied || busy || !conversation || !meta || !meta.available || !meta.can_checkout) return;
            var selected = conversation, current = generation, version = editVersion, body, url;
            if (kind === 'analyze') {
                if (!meta.ai_ready) return;
                body = {force: Boolean(draft)}; url = config.conversations_base_url + '/' + selected + '/orders/analyze';
            } else {
                body = payload();
                if (!body || pending) { invalidate(); note(labels.invalid, true); return; }
                if (kind === 'dispatch') {
                    if (!quoteToken || quoteToken.snapshot !== JSON.stringify(body)) { invalidate(); note(labels.quote_expired, true); return; }
                    body.quote_hash = quoteToken.quote; body.delivery_quote_hash = quoteToken.delivery;
                }
                url = config.drafts_base_url + '/' + draft.id + '/' + (kind === 'calculate' ? 'quote' : 'dispatch');
            }
            busy = kind; controls(); note(labels.loading);
            request(url, 'action', body).then(function (data) {
                if (closed || current !== generation || selected !== conversation) return;
                if (kind === 'analyze') { pending = false; return loadState(); }
                if (kind === 'dispatch') { invalidate(); renderDraft(data.draft); return; }
                if (version !== editVersion || !data.draft || !data.quote || !data.delivery) { invalidate(); note(labels.quote_expired, true); return; }
                draft = data.draft;
                var review = payload(), quoteHash = data.quote.quote_hash, deliveryHash = data.delivery.delivery_quote_hash;
                if (!review || !/^[a-f0-9]{64}$/.test(quoteHash || '') || !/^[a-f0-9]{64}$/.test(deliveryHash || '')) {
                    invalidate(); note(labels.unavailable, true); return;
                }
                quoteToken = {snapshot: JSON.stringify(review), quote: quoteHash, delivery: deliveryHash};
                quoteBox.textContent = labels.total + ': ' + String(data.quote.total || '') + ' ' + labels.currency
                    + '\n' + labels.delivery + ': ' + String(data.delivery.delivery_fee || '') + ' ' + labels.currency;
                quoteBox.hidden = false; note(labels.ready);
                showDelivery(data.delivery);
            }).catch(function (error) { failure(error, current); }).finally(function () {
                if (current === generation) { busy = ''; controls(); }
            });
        }
        function click(event) {
            if (event.target.closest('[data-wa-order-analyze]') && !analyze.disabled) action('analyze');
            else if (event.target.closest('[data-wa-order-reload]') && !reload.disabled) { invalidate(); loadState(); }
            else if (event.target.closest('[data-wa-order-calculate]') && !calculate.disabled) action('calculate');
            else if (event.target.closest('[data-wa-order-dispatch]') && !dispatch.disabled) action('dispatch');
            else if (event.target.closest('[data-wa-order-add]') && !add.disabled) { localEdited = true; invalidate(labels.quote_expired); addRow({}); }
            else if (event.target.closest('[data-wa-order-search-button]') && !searchButton.disabled) loadCatalog(false);
            else if (event.target.closest('[data-wa-order-more]') && !more.disabled) {
                if (catalogSearch !== search.value.trim()) loadCatalog(false); else loadCatalog(true);
            } else if (event.target.closest('[data-wa-order-customer-lookup]') && !customerLookup.disabled) loadCustomers();
            else if (event.target.closest('[data-wa-order-address-search]') && !addressSearch.disabled) searchAddress();
            else if (event.target.closest('[data-wa-order-confirm-pin]') && !confirmPin.disabled) quoteDelivery();
            else if (event.target.closest('[data-wa-order-remove]') && !busy) {
                var row = rows.find(function (item) { return item.remove === event.target.closest('[data-wa-order-remove]'); });
                if (row) { localEdited = true; invalidate(labels.quote_expired); rows = rows.filter(function (item) { return item !== row; }); row.element.remove(); controls(); }
            }
        }
        function edited(event) {
            if (event.target === search || event.target === picker) return;
            localEdited = true;
            if ([fields.branch, fields.customer_phone, fields.address, fields.area].indexOf(event.target) !== -1) {
                invalidateLocation(true); customerResults.textContent = ''; customerResults.hidden = true;
            }
            invalidate(labels.quote_expired);
            if (busy) return;
            if (event.target === fields.branch) {
                if (controllers.has('catalog')) controllers.get('catalog').abort();
                catalogRequest++;
                catalogBusy = false; catalog.clear(); catalogBranch = ''; search.value = ''; more.hidden = true;
                rows.forEach(function (row) { row.productId = ''; row.optionId = ''; row.product.value = ''; row.option.value = ''; productOptions(row); });
                loadCatalog(false); setupMap();
            } else {
                rows.forEach(function (row) {
                    if (event.target === row.product) { row.productId = row.product.value; row.optionId = ''; row.option.value = ''; optionOptions(row); }
                    if (event.target === row.option) row.optionId = row.option.value;
                });
            }
            controls();
        }
        function changedPicker(event) {
            if (event.target !== picker || busy) return;
            renderDraft(drafts.find(function (item) { return String(item.id) === picker.value; }) || null);
        }
        function reset(event) {
            var reason = event && event.detail && event.detail.reason;
            if (reason === 'denied') denied = true;
            clear(reason);
        }
        function updated(event) {
            if (conversation && Number(event.detail && event.detail.conversation) === conversation) {
                pending = true; invalidate(); note(meta && meta.mode === 'auto' ? labels.pending_auto : labels.pending); controls();
                scheduleState();
            }
        }
        function submit(event) { event.preventDefault(); }
        panel.addEventListener('click', click); form.addEventListener('input', edited); form.addEventListener('change', edited);
        form.addEventListener('submit', submit); picker.addEventListener('change', changedPicker);
        document.addEventListener('whatsapp:conversation-selected', choose);
        document.addEventListener('whatsapp:conversation-updated', updated);
        document.addEventListener('whatsapp:private-reset', reset);
        var session = {host: host, destroy: function () {
            if (closed) return; clear(); closed = true;
            panel.removeEventListener('click', click); form.removeEventListener('input', edited); form.removeEventListener('change', edited);
            form.removeEventListener('submit', submit); picker.removeEventListener('change', changedPicker);
            document.removeEventListener('whatsapp:conversation-selected', choose);
            document.removeEventListener('whatsapp:conversation-updated', updated);
            document.removeEventListener('whatsapp:private-reset', reset);
            config.csrf = ''; meta = null; controls();
        }};
        active = session;
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function () {
            if (active === session) destroy(); else session.destroy();
        });
        var selected = window.DashboardWhatsAppInbox && typeof window.DashboardWhatsAppInbox.selection === 'function'
            ? window.DashboardWhatsAppInbox.selection() : 0;
        if (selected) choose({detail: {conversation: selected}});
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardWhatsAppOrders = {mount: mount, destroy: destroy};
    document.addEventListener('dashboard:before-unload', destroy);
    document.addEventListener('dashboard:page-mounted', mount);
    mount();
}());
