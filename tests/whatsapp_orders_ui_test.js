'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

class Element {
    constructor(tag = 'div') {
        this.tagName = tag; this.children = []; this.listeners = {}; this.dataset = {}; this.attributes = {};
        this.hidden = false; this.disabled = false; this.checked = false; this.text = ''; this.currentValue = '';
        this.classList = {toggle() {}};
    }
    set textContent(value) { this.text = String(value); this.children = []; if (this.tagName === 'select') this.currentValue = ''; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { throw new Error('Private customer text must never be parsed as HTML'); }
    set value(value) {
        value = String(value);
        this.currentValue = this.tagName === 'select' && !this.children.some(child => child.value === value) ? '' : value;
    }
    get value() { return this.currentValue; }
    appendChild(child) { child.parent = this; this.children.push(child); return child; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    removeEventListener(name, callback) { this.listeners[name] = (this.listeners[name] || []).filter(item => item !== callback); }
    dispatchEvent(event) { (this.listeners[event.type] || []).slice().forEach(callback => callback(event)); }
    closest(selector) {
        const attribute = selector.slice(1, -1);
        const dataset = attribute.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
        if (Object.prototype.hasOwnProperty.call(this.attributes, attribute)
            || Object.prototype.hasOwnProperty.call(this.dataset, dataset)) return this;
        return this.parent ? this.parent.closest(selector) : null;
    }
}

function fixture() {
    const panel = new Element(), host = new Element(), document = new Element(), window = new Element();
    const bootstrap = new Element('script'), elements = {}, fields = {};
    const form = new Element('form');
    ['status', 'proposed', 'items', 'quote', 'analyze', 'reload', 'calculate', 'dispatch', 'add', 'search',
        'search-button', 'more', 'draft', 'draft-label', 'customer-lookup', 'customer-results',
        'address-search', 'address-results', 'map', 'location-status', 'confirm-pin', 'distance'].forEach(name => {
        const tag = name === 'search' ? 'input' : name === 'draft' ? 'select' : 'div';
        const element = new Element(tag); element.attributes['data-wa-order-' + name] = '1';
        elements['[data-wa-order-' + name + ']'] = element;
    });
    elements['[data-wa-order-form]'] = form;
    ['customer_name', 'customer_phone', 'address', 'area', 'delivery_notes', 'branch', 'latitude',
        'longitude', 'location_confirmed'].forEach(name => {
        const element = new Element(name === 'branch' ? 'select' : 'input');
        fields[name] = element; elements['[data-wa-order-field="' + name + '"]'] = element;
    });
    panel.querySelector = selector => elements[selector];
    host.querySelector = selector => selector === '[data-wa-orders]' ? panel : null;
    document.getElementById = id => id === 'whatsapp-inbox' ? host : id === 'whatsapp-orders-bootstrap' ? bootstrap : null;
    document.createElement = tag => new Element(tag); document.hidden = false;
    window.location = {href: 'https://example.test/admin/whatsapp'};
    const state = {calls: [], cleanup: [], redirected: false, status: 200, quoteHold: null, dispatchHold: null,
        inboxDestroyed: 0, aiReady: true, maps: [], timers: new Map(), timerId: 0, mode: 'review',
        customerHold: false, addressHold: false, deliveryHold: false};
    window.setTimeout = (callback, ms) => { const id = ++state.timerId; state.timers.set(id, {callback, ms}); return id; };
    window.clearTimeout = id => state.timers.delete(id);
    window.DashboardLocationPicker = {create: (element, options) => {
        const map = {options, routes: [], pins: [], destroyed: false, clear() { this.pins = []; },
            set(lat, lng) { this.pins.push([lat, lng]); }, route(value) { this.routes.push(value); },
            invalidate() {}, resize() {}, destroy() { this.destroyed = true; }};
        state.maps.push(map); return map;
    }};
    window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => state.cleanup.push(callback)};
    window.DashboardWhatsAppInbox = {selection: () => 0, destroy: () => { state.inboxDestroyed++; }};
    const labels = {};
    ['choose_branch', 'loading', 'unavailable', 'denied', 'offline', 'empty', 'pending', 'changed',
        'review', 'none', 'cancelled', 'failed', 'ready', 'dispatched', 'quote_expired', 'not_configured',
        'proposed', 'proposed_branch', 'approximate', 'currency', 'base', 'quantity', 'choose_mode',
        'choose_product', 'option', 'remove', 'piece', 'weight', 'hint', 'invalid', 'total', 'delivery',
        'ticket', 'unavailable_product', 'lookup_empty', 'pin_review', 'pin_required', 'map_unavailable',
        'branch_policy_missing', 'address_empty', 'route_distance', 'direct_distance', 'km', 'pending_auto',
        'empty_auto'].forEach(name => labels[name] = name.toUpperCase());
    const config = {meta_url: 'https://example.test/admin/whatsapp/orders/meta',
        catalog_url: 'https://example.test/admin/whatsapp/orders/catalog',
        conversations_base_url: 'https://example.test/admin/whatsapp/conversations',
        drafts_base_url: 'https://example.test/admin/whatsapp/orders', csrf: 'fixture-csrf', labels};
    bootstrap.textContent = JSON.stringify(config);
    function draft(conversation, status = 'REVIEW', revision = 1) {
        return {id: conversation + 100, revision, conversation_id: conversation, status, ticket_id: status === 'DISPATCHED' ? 201 : null,
            data: {customer: {name: '<img src=x onerror=alert(1)> customer ' + conversation, phone: '01000000001',
                address: 'Fixture address'}, branch_hint: '<script>branch hint</script>', approximate_total: '120.00',
                items: [{name: '<img> proposed product', quantity: '1', quantity_mode: 'piece'}]},
            review: {customer_name: 'Customer ' + conversation, customer_phone: '01000000001', address: 'Fixture address',
                branch: 'f:1', latitude: 30, longitude: 31, location_confirmed: true,
                items: [{product_id: 11, quantity: '1', quantity_mode: 'piece', option_id: null}]}};
    }
    async function fetch(url, options) {
        state.calls.push({url, options});
        assert.strictEqual(options.cache, 'no-store'); assert.strictEqual(options.credentials, 'same-origin');
        assert.strictEqual(options.headers.Authorization, undefined, 'No provider credential belongs in the browser');
        if (options.method) assert.strictEqual(options.headers['X-CSRF-TOKEN'], config.csrf);
        const parsed = new URL(url), pathname = parsed.pathname;
        const conversation = Number((pathname.match(/conversations\/(\d+)/) || [])[1]) || 1;
        let data;
        if (pathname.endsWith('/meta')) data = {success: true, available: true, ai_ready: state.aiReady, can_checkout: true,
            mode: state.mode, branches: [{value: 'f:1', name: 'Fixture branch'}, {value: 'f:2', name: 'Other branch'}],
            maps: {open_enabled: true, tile_url: 'https://tiles.test/{z}/{x}/{y}.png'},
            urls: Object.fromEntries(['customers', 'delivery-settings', 'delivery-quote', 'address-suggestions']
                .map(key => [key.replace(/-/g, '_'), 'https://example.test/admin/whatsapp/orders/' + key]))};
        else if (pathname.endsWith('/catalog')) data = {success: true,
            items: [{id: 11, name: '<script>catalog product</script>', available: true, options: []}], pagination: {page: 1, last_page: 1}};
        else if (pathname.endsWith('/delivery-settings')) data = {success: true, settings: {ready: true, latitude: 30, longitude: 31, km_price: '50.00'}};
        else if (pathname.endsWith('/customers')) {
            data = {success: true, items: [{name: '<img> saved customer', phone: '01000000001', address: 'Saved full address',
                area: 'Saved area', delivery_notes: 'Floor 3', latitude: 30.2, longitude: 31.2}]};
            if (state.customerHold) await new Promise(resolve => { state.releaseCustomer = resolve; });
        } else if (pathname.endsWith('/address-suggestions')) {
            data = {success: true, items: [{label: '<script> suggested entrance', latitude: 30.3, longitude: 31.3}]};
            if (state.addressHold) await new Promise(resolve => { state.releaseAddress = resolve; });
        } else if (pathname.endsWith('/delivery-quote')) {
            data = {success: true, delivery: {latitude: 30.2, longitude: 31.2, delivery_fee: '60.00',
                distance_km: '1.200', km_price: '50.00', method: 'road_osrm', route_path: [[30, 31], [30.2, 31.2]]}};
            if (state.deliveryHold) await new Promise(resolve => { state.releaseDelivery = resolve; });
        }
        else if (pathname.endsWith('/quote')) {
            data = {success: true, draft: draft(1, 'READY', 2), quote: {quote_hash: 'a'.repeat(64), total: '140.00'},
                delivery: {delivery_quote_hash: 'b'.repeat(64), delivery_fee: '100.00'}};
            if (state.quoteHold) await new Promise(resolve => { state.releaseQuote = resolve; });
        } else if (pathname.endsWith('/dispatch')) {
            data = {success: true, draft: draft(1, 'DISPATCHED', 3), ticket_id: 201, print_queued: true};
            if (state.dispatchHold) await new Promise(resolve => { state.releaseDispatch = resolve; });
        } else if (pathname.endsWith('/analyze')) data = {success: true, draft: draft(conversation)};
        else data = {success: true, available: true, drafts: [draft(conversation)], pending_analysis: false};
        return {ok: state.status === 200, status: state.status, redirected: state.redirected,
            headers: {get: () => state.redirected ? 'text/html' : 'application/json'}, json: async () => data};
    }
    const context = {window, document, navigator: {onLine: true}, URL, AbortController, Map, Set, Promise, Date,
        fetch, console, CustomEvent: class { constructor(type, details) { this.type = type; this.detail = details.detail; } }};
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'public/js/dashboard-whatsapp-orders.js'), 'utf8'), context);
    return {panel, host, document, window, elements, fields, form, state, context, labels};
}

async function settle() { for (let index = 0; index < 10; index++) await new Promise(setImmediate); }
function select(test, id) { test.document.dispatchEvent({type: 'whatsapp:conversation-selected', detail: {conversation: id}}); }
function click(test, name) {
    const target = test.elements['[data-wa-order-' + name + ']'];
    test.panel.dispatchEvent({type: 'click', target});
}
function edit(test, name, value) {
    test.fields[name].value = value;
    test.form.dispatchEvent({type: 'input', target: test.fields[name]});
}

async function quoteAndEdit() {
    const test = fixture();
    assert.strictEqual(test.state.calls.length, 0, 'Opening a page must not analyze or read an unselected conversation');
    select(test, 1); await settle();
    assert(test.elements['[data-wa-order-proposed]'].textContent.includes('<script>branch hint</script>'));
    assert.strictEqual(test.fields.customer_name.value, 'Customer 1');
    assert.strictEqual(test.elements['[data-wa-order-calculate]'].disabled, false);
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/analyze')).length, 0, 'Reading a draft never calls AI');
    click(test, 'calculate'); await settle();
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, false);
    assert(test.elements['[data-wa-order-quote]'].textContent.includes('140.00'));
    const quoteRequest = test.state.calls.find(call => call.url.endsWith('/quote'));
    assert.strictEqual(JSON.parse(quoteRequest.options.body).expected_revision, 1);
    edit(test, 'address', 'Different address');
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, true, 'Any actual order edit discards quote tokens');
    assert.strictEqual(test.elements['[data-wa-order-quote]'].hidden, true);
    click(test, 'dispatch'); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/dispatch')).length, 0);
    test.state.cleanup[0]();
    assert.strictEqual(test.fields.customer_name.value, '');
    assert.strictEqual(test.elements['[data-wa-order-items]'].textContent, '');
    assert.strictEqual(test.document.listeners['whatsapp:conversation-selected'].length, 0);
}

async function staleQuoteAndSwitch() {
    const test = fixture(); select(test, 1); await settle();
    test.state.quoteHold = true; click(test, 'calculate'); await settle();
    const request = test.state.calls.find(call => call.url.endsWith('/quote'));
    select(test, 2); await settle();
    assert.strictEqual(request.options.signal.aborted, true, 'Switching threads aborts pending review requests');
    assert.strictEqual(test.fields.customer_name.value, 'Customer 2');
    test.state.releaseQuote(); await settle();
    assert.strictEqual(test.fields.customer_name.value, 'Customer 2', 'A late response cannot restore another customer');
    assert.strictEqual(test.elements['[data-wa-order-quote]'].hidden, true);
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, true);
    test.state.cleanup[0]();
}

async function repeatedDispatchAndNewMessages() {
    const test = fixture(); select(test, 1); await settle(); click(test, 'calculate'); await settle();
    test.state.dispatchHold = true; click(test, 'dispatch'); click(test, 'dispatch'); await settle();
    const requests = test.state.calls.filter(call => call.url.endsWith('/dispatch'));
    assert.strictEqual(requests.length, 1, 'A repeated click must issue only one dispatch');
    const data = JSON.parse(requests[0].options.body);
    assert.strictEqual(data.expected_revision, 2);
    assert.strictEqual(data.quote_hash, 'a'.repeat(64)); assert.strictEqual(data.delivery_quote_hash, 'b'.repeat(64));
    test.state.releaseDispatch(); await settle();
    assert(test.elements['[data-wa-order-status]'].textContent.includes('201'));
    assert.strictEqual(test.form.hidden, true);
    test.state.cleanup[0]();

    const next = fixture(); select(next, 1); await settle(); click(next, 'calculate'); await settle();
    next.document.dispatchEvent({type: 'whatsapp:conversation-updated', detail: {conversation: 1}});
    assert.strictEqual(next.elements['[data-wa-order-dispatch]'].disabled, true, 'New evidence invalidates the quote');
    assert.strictEqual(next.elements['[data-wa-order-calculate]'].disabled, true, 'New evidence needs a new explicit analysis');
    assert.strictEqual(next.state.calls.filter(call => call.url.endsWith('/analyze')).length, 0);
    next.state.cleanup[0]();
}

async function expiredAndOffline() {
    for (const redirected of [false, true]) {
        const test = fixture(); select(test, 1); await settle();
        test.state.redirected = redirected; test.state.status = redirected ? 200 : 403;
        click(test, 'calculate'); await settle();
        assert.strictEqual(test.fields.customer_name.value, '');
        assert.strictEqual(test.elements['[data-wa-order-items]'].textContent, '');
        assert.strictEqual(test.elements['[data-wa-order-proposed]'].textContent, '');
        assert.strictEqual(test.state.inboxDestroyed, 1, 'Order API expiry also clears the displayed chat');
        const calls = test.state.calls.length; select(test, 2); await settle();
        assert.strictEqual(test.state.calls.length, calls, 'Expired sessions cannot continue reading');
        test.state.cleanup[0]();
    }
    const test = fixture(); select(test, 1); await settle();
    test.context.navigator.onLine = false;
    test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'offline'}});
    assert.strictEqual(test.fields.address.value, '');
    assert.strictEqual(test.panel.hidden, true);
    assert.strictEqual(test.elements['[data-wa-order-items]'].textContent, '');
    const calls = test.state.calls.length; select(test, 2); await settle(); assert.strictEqual(test.state.calls.length, calls);
    test.state.cleanup[0]();
}

async function pendingEditAndHidden() {
    const test = fixture(); select(test, 1); await settle(); test.state.quoteHold = true;
    click(test, 'calculate'); await settle(); edit(test, 'address', 'Changed while waiting');
    test.state.releaseQuote(); await settle();
    assert.strictEqual(test.elements['[data-wa-order-quote]'].hidden, true, 'An edit while waiting rejects the late quote');
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, true);
    test.document.hidden = true;
    test.document.dispatchEvent({type: 'whatsapp:private-reset', detail: {reason: 'hidden'}});
    assert.strictEqual(test.fields.customer_phone.value, '', 'A hidden tab clears its order form');
    assert.strictEqual(test.elements['[data-wa-order-proposed]'].textContent, '');
    test.state.cleanup[0]();

    const unconfigured = fixture(); unconfigured.state.aiReady = false; select(unconfigured, 1); await settle();
    assert.strictEqual(unconfigured.elements['[data-wa-order-analyze]'].disabled, true);
    click(unconfigured, 'analyze'); await settle();
    assert.strictEqual(unconfigured.state.calls.filter(call => call.url.endsWith('/analyze')).length, 0, 'Unconfigured analysis never triggers a provider request');
    unconfigured.state.cleanup[0]();
}

async function savedCustomerMapAndDelivery() {
    const test = fixture(); select(test, 1); await settle();
    assert.strictEqual(test.state.maps.length, 1, 'The shared phone map is created for the receiving branch');
    const map = test.state.maps[0];
    assert.strictEqual(map.options.preferOpenMap, true);
    assert.deepStrictEqual(Array.from(map.options.origin), [30, 31]);
    click(test, 'calculate'); await settle();
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, false);
    click(test, 'customer-lookup'); await settle();
    const lookup = test.state.calls.find(call => call.url.includes('/customers?'));
    assert.strictEqual(new URL(lookup.url).searchParams.get('phone'), '01000000001');
    assert.strictEqual(new URL(lookup.url).searchParams.get('branch'), 'f:1');
    assert.strictEqual(new URL(lookup.url).searchParams.has('prefix'), false, 'The review only requests exact customer lookup');
    const button = test.elements['[data-wa-order-customer-results]'].children[0];
    assert(button.textContent.includes('<img> saved customer'), 'Customer text is rendered literally');
    button.dispatchEvent({type: 'click'});
    assert.strictEqual(test.fields.customer_name.value, '<img> saved customer');
    assert.strictEqual(test.fields.address.value, 'Saved full address');
    assert.strictEqual(test.fields.delivery_notes.value, 'Floor 3');
    assert.strictEqual(test.fields.latitude.value, '30.2');
    assert.strictEqual(test.fields.location_confirmed.checked, false, 'Selecting an address places its pin but cannot reuse a quote');
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, true);
    click(test, 'confirm-pin'); await settle();
    assert.strictEqual(test.fields.location_confirmed.checked, true);
    assert(test.elements['[data-wa-order-distance]'].textContent.includes('1.200'));
    assert(test.elements['[data-wa-order-distance]'].textContent.includes('60.00'));
    assert.deepStrictEqual(Array.from(map.routes.at(-1), point => Array.from(point)), [[30, 31], [30.2, 31.2]]);
    const delivery = test.state.calls.find(call => call.url.endsWith('/delivery-quote'));
    assert.deepStrictEqual(JSON.parse(delivery.options.body), {branch: 'f:1', latitude: 30.2, longitude: 31.2, location_confirmed: true});
    click(test, 'calculate'); await settle(); assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, false);
    map.options.change(30.4, 31.4);
    assert.strictEqual(test.fields.location_confirmed.checked, false, 'Moving a pin invalidates confirmation and both quote hashes');
    assert.strictEqual(test.elements['[data-wa-order-dispatch]'].disabled, true);
    assert.strictEqual(test.elements['[data-wa-order-quote]'].hidden, true);
    test.state.cleanup[0](); assert.strictEqual(map.destroyed, true);
    assert.strictEqual(test.elements['[data-wa-order-customer-results]'].textContent, '');
    assert.strictEqual(test.elements['[data-wa-order-distance]'].textContent, '');
}

async function suggestionAndLateLocationPrivacy() {
    const test = fixture(); select(test, 1); await settle();
    edit(test, 'address', 'Complete delivery address: building 12, floor 3');
    click(test, 'address-search'); await settle();
    const suggestion = test.elements['[data-wa-order-address-results]'].children[0];
    assert(suggestion.textContent.includes('<script> suggested entrance'));
    suggestion.dispatchEvent({type: 'click'}); await settle();
    assert.strictEqual(test.fields.address.value, 'Complete delivery address: building 12, floor 3', 'A geocoder label must not erase delivery details');
    assert.strictEqual(test.fields.latitude.value, '30.3');
    test.state.deliveryHold = true; click(test, 'confirm-pin'); await settle();
    const request = test.state.calls.find(call => call.url.endsWith('/delivery-quote'));
    edit(test, 'address', 'Changed delivery address');
    assert.strictEqual(request.options.signal.aborted, true);
    assert.strictEqual(test.fields.latitude.value, '', 'Changing the address clears the previous pin');
    test.state.releaseDelivery(); await settle();
    assert.strictEqual(test.fields.location_confirmed.checked, false, 'An old delivery response cannot confirm a changed address');
    assert.strictEqual(test.elements['[data-wa-order-distance]'].textContent, '');
    test.state.addressHold = true; click(test, 'address-search'); await settle();
    const addressRequest = test.state.calls.filter(call => call.url.includes('/address-suggestions?')).at(-1);
    select(test, 2); await settle(); test.state.releaseAddress(); await settle();
    assert.strictEqual(addressRequest.options.signal.aborted, true);
    assert.strictEqual(test.elements['[data-wa-order-address-results]'].textContent, '', 'A previous thread cannot reveal late address suggestions');
    assert.strictEqual(test.fields.customer_name.value, 'Customer 2');
    test.state.cleanup[0]();

    const next = fixture(); select(next, 1); await settle(); next.state.customerHold = true;
    click(next, 'customer-lookup'); await settle();
    edit(next, 'customer_phone', '01000000002'); next.state.releaseCustomer(); await settle();
    assert.strictEqual(next.elements['[data-wa-order-customer-results]'].textContent, '', 'Changing the phone rejects stale customer results');
    assert.strictEqual(next.fields.latitude.value, ''); next.state.cleanup[0]();
}

async function serverAutomationReadOnlyRefresh() {
    const test = fixture(); test.state.mode = 'auto'; select(test, 1); await settle();
    assert.strictEqual(test.state.timers.size, 1);
    test.document.dispatchEvent({type: 'whatsapp:conversation-updated', detail: {conversation: 1}});
    assert.strictEqual(test.elements['[data-wa-order-status]'].textContent, 'PENDING_AUTO');
    const [id, timer] = [...test.state.timers.entries()][0];
    assert.strictEqual(timer.ms, 5000, 'New message evidence schedules a result refresh');
    test.state.timers.delete(id); timer.callback(); await settle();
    assert.strictEqual(test.state.calls.filter(call => call.url.endsWith('/analyze') || call.url.endsWith('/dispatch')).length, 0,
        'Automatic UI refresh must never call AI or create an order from the browser');
    const count = test.state.calls.length;
    edit(test, 'customer_name', 'Operator correction');
    const [nextId, next] = [...test.state.timers.entries()][0]; test.state.timers.delete(nextId); next.callback(); await settle();
    assert.strictEqual(test.state.calls.length, count, 'Polling cannot overwrite operator exception edits');
    assert.strictEqual(test.fields.customer_name.value, 'Operator correction');
    test.state.cleanup[0](); assert.strictEqual(test.state.timers.size, 0);
}

(async () => {
    await quoteAndEdit(); await staleQuoteAndSwitch(); await repeatedDispatchAndNewMessages(); await expiredAndOffline(); await pendingEditAndHidden();
    await savedCustomerMapAndDelivery(); await suggestionAndLateLocationPrivacy(); await serverAutomationReadOnlyRefresh();
    console.log('WHATSAPP_ORDERS_UI_PASS plain text, quote invalidation, stale privacy, single dispatch, expiry cleanup, shared saved customer map/delivery, pin confirmation, read-only automatic refresh');
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
