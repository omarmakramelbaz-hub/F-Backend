'use strict';
// Run the production state/request functions with controlled network completion.
// These tests never call the network or modify a customer/order.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/phone-orders.js'), 'utf8');
function fn(name) {
    const start = source.search(new RegExp('    (?:async )?function ' + name + '\\('));
    assert(start >= 0, name);
    const rest = source.slice(start), end = rest.slice(1).search(/\n    (?:async )?function /);
    return end < 0 ? rest : rest.slice(0, end + 1);
}
const functions = ['validDeliveryOrigin', 'ticketDeliveryOrigin', 'deliveryOriginLabel', 'setDeliveryOrigin', 'loadDeliveryContext', 'branchLabel', 'scaled', 'decimal', 'payload', 'resetDraft', 'confirmLocation', 'setCustomerLocation', 'editTicket'];
function data(origin = 'f:400') {
    return {branch: 'f:301', delivery_origin_branch: origin, fulfillment_branch: 'f:301', delivery_origin: {value: origin, id: Number(origin.slice(2)), parent_id: null, name: 'أستور القاهرة'}, fulfillment: {value: 'f:301', name: 'فرع شبرا'}, latitude: 30.1, longitude: 31.2, km_price: '12.00', ready: true};
}
function fixture() {
    const nodes = new Map();
    function node(selector) {if (!nodes.has(selector)) nodes.set(selector, {textContent: '', hidden: false, scrollTop: 0});return nodes.get(selector);}
    const fields = Object.fromEntries(['customer_name', 'customer_phone', 'address', 'area', 'delivery_notes', 'notes', 'discount', 'discount_reason', 'delivery_fee'].map(name => [name, {value: name === 'delivery_fee' || name === 'discount' ? '0.00' : ''}]));
    // A native select rejects values until the corresponding option exists.
    const requests = [], messages = [], companySelect = {_value: '7', options: [{value: '7'}], get value() {return this._value;}, set value(value) {this._value = this.options.some(option => option.value === String(value)) ? String(value) : '';}, replaceChildren(option) {this.options = [option];this.value = '';}, appendChild(option) {this.options.push(option);}};
    const c = vm.createContext({assert, console, Map, BigInt, Number, String, Array, Object, JSON, RegExp, Promise, setTimeout: () => 1, clearTimeout() {}, AbortController,
        root: {querySelector: node}, companySelect, fields, requests, messages,
        branch: 'f:301', deliveryOriginBranch: '', deliveryOriginContext: null, deliverySettings: null, contextGeneration: 0, locationGeneration: 0,
        branchSelect: {value: 'f:301', selectedOptions: [{textContent: 'فرع شبرا'}]}, view: 'compose', boot: {open_phone_maps: true}, step: 'number', disposed: false,
        urls: {delivery_settings: '/settings', companies: '/companies', delivery_quote: '/delivery-quote'},
        deliveryLocation: null, locationPicker: null, addressSearch: null, locationPreviewTimer: null, latInput: {value: '30.2'}, lngInput: {value: '31.3'}, locating: false,
        manualDeliveryFee: false, customerRef: null, policy: {}, cart: [], products: new Map(), editing: null, quote: null, dirty: true, requestKey: '', quoteGeneration: 0, customerGeneration: 0, customerTimer: null, quoteTimer: null, editGeneration: 0, modalGeneration: 0, suggestionIndex: -1, lastGeocoded: '', savedAddressPending: '', desktopLocal: false, modal: {hidden: true}, window: {confirm: () => true},
        text: key => key, element: (tag, css, text) => ({tag, textContent: text, value: ''}), begin: () => new AbortController(), abort() {}, ensureLocation() {}, notify: value => messages.push(value),
        locked: () => false, canWrite: () => true, lock() {}, changed() {}, uuid: () => 'new-command', renderLines() {}, totals() {}, flow() {}, switchView() {}, invalidateLocation() {},
        DashboardLocationPicker: {valid: () => true},
        get(url, values, signal) {return new Promise((resolve, reject) => requests.push({url, values, signal, resolve, reject}));},
        post(url, values, signal) {return new Promise((resolve, reject) => requests.push({url, values, signal, resolve, reject}));},
        read: async result => result
    });
    c.fetchTicket = async () => c.nextTicket;
    c.changeBranch = () => {c.branch = c.branchSelect.value;c.resetDraft(false);};
    vm.runInContext(functions.map(fn).join('\n'), c);
    return c;
}
function ticket(origin) {return {branch: {value: 'f:301'}, delivery_location: data(origin)};}
async function settle(c, start, settings) {
    c.requests[start].resolve({settings});c.requests[start + 1].resolve({items: [{id: 7, name: 'شركة التوصيل'}]});
    await new Promise(resolve => setImmediate(resolve));
}
(async () => {
    const c = fixture();
    assert.equal(c.ticketDeliveryOrigin(ticket()), 'f:400');
    assert.equal(c.ticketDeliveryOrigin({branch: {value: 'f:94'}, delivery_location: {branch: 'f:94'}}), '');
    assert.equal(c.validDeliveryOrigin({branch: 'g:7'}, ''), true);
    assert.equal(c.validDeliveryOrigin(data(), ''), false);
    assert.equal(c.validDeliveryOrigin(data(), 'f:301'), false);
    const wrongParent = ticket();wrongParent.delivery_location.delivery_origin.parent_id = 94;
    assert.throws(() => c.ticketDeliveryOrigin(wrongParent), /list_error/);
    const zeroParent = ticket();zeroParent.delivery_location.delivery_origin.parent_id = 0;
    assert.equal(c.ticketDeliveryOrigin(zeroParent), 'f:400');
    const linkedParent = ticket();linkedParent.delivery_location.delivery_origin.parent_id = 301;
    assert.equal(c.ticketDeliveryOrigin(linkedParent), 'f:400');
    const mismatchedIdentity = ticket();mismatchedIdentity.delivery_location.delivery_origin.id = 401;
    assert.throws(() => c.ticketDeliveryOrigin(mismatchedIdentity), /list_error/);
    const wrongFulfillment = ticket();wrongFulfillment.branch.value = 'f:94';
    assert.throws(() => c.ticketDeliveryOrigin(wrongFulfillment), /list_error/);
    const mismatchedTop = ticket();mismatchedTop.delivery_origin_branch = 'f:401';
    assert.throws(() => c.ticketDeliveryOrigin(mismatchedTop), /list_error/);
    assert.throws(() => c.ticketDeliveryOrigin({branch: {value: 'f:301'}, delivery_origin_branch: 'f:400'}), /list_error/);
    c.setDeliveryOrigin('f:400', data());
    assert.match(c.branchLabel(), /الأستور: أستور القاهرة.*فرع التجهيز: فرع شبرا/);
    c.deliveryLocation = {latitude: 30.2, longitude: 31.3, delivery_quote_hash: 'store-quote'};
    c.cart = [{product: {id: 10}, quantity: '1', mode: 'piece'}];
    const payload = c.payload();assert.equal(payload.branch, 'f:301');assert.equal(payload.delivery_origin_branch, 'f:400');assert.equal(payload.delivery_quote_hash, 'store-quote');
    c.resetDraft(false);
    assert.equal(c.deliveryOriginBranch, '');assert.equal(c.deliveryOriginContext, null);assert.equal(c.deliveryLocation, null);assert.equal(c.requests.length, 0);assert.equal(c.payload().delivery_origin_branch, undefined);
    assert.equal(c.branchLabel(), 'فرع شبرا');

    // An origin A response cannot set the map after switching to another ticket B.
    c.setDeliveryOrigin('f:400', data());const old = c.loadDeliveryContext();
    assert.equal(c.requests[0].values.delivery_origin_branch, 'f:400');
    c.setDeliveryOrigin('f:401', data('f:401'));const current = c.loadDeliveryContext();
    await settle(c, 2, {...data('f:401'), branch: {value: 'f:301'}});await current;
    assert.equal(c.deliverySettings.delivery_origin_branch, 'f:401');
    await settle(c, 0, {...data(), branch: {value: 'f:301'}});await old;
    assert.equal(c.deliverySettings.delivery_origin_branch, 'f:401');
    assert.equal(c.messages.length, 0);

    // Resetting to a new ordinary ticket cannot reuse B's origin or a late error.
    const delayed = c.loadDeliveryContext();c.resetDraft();
    assert.equal(c.requests[6].values.delivery_origin_branch, undefined);
    c.requests[4].reject(new Error('old ticket failure'));c.requests[5].resolve({items: []});await delayed;
    assert.equal(c.messages.length, 0);
    await settle(c, 6, {branch: {value: 'f:301'}, ready: true, latitude: 30, longitude: 31, km_price: '10.00'});
    await Promise.resolve();assert.equal(c.deliverySettings.delivery_origin_branch, undefined);

    // Server metadata must describe the requested store and fulfillment scope.
    c.setDeliveryOrigin('f:400', data());const mismatch = c.loadDeliveryContext();
    await settle(c, 8, {...data('f:401'), branch: {value: 'f:301'}});await mismatch;
    assert.equal(c.deliverySettings, null);assert.equal(c.messages.at(-1), 'load_error');

    // Quote starts at the store but retains the physical branch for the ticket.
    c.latInput.value = '30.2';c.lngInput.value = '31.3';
    const pendingQuote = c.confirmLocation(false);
    assert.equal(c.requests[10].values.branch, 'f:301');assert.equal(c.requests[10].values.delivery_origin_branch, 'f:400');
    c.requests[10].resolve({delivery: {...data(), delivery_fee: '24.00', distance_km: '2.000', delivery_quote_hash: 'confirmed'}});await pendingQuote;
    assert.equal(c.deliveryLocation.delivery_origin_branch, 'f:400');assert.equal(c.fields.delivery_fee.value, '24.00');
    const staleQuote = c.confirmLocation(false);c.setDeliveryOrigin('f:401', data('f:401'));
    c.requests[11].resolve({delivery: {...data(), delivery_fee: '999.00', distance_km: '2.000'}});await staleQuote;
    assert.equal(c.deliveryLocation, null);assert.equal(c.fields.delivery_fee.value, '24.00');
    const oldError = c.confirmLocation(false);c.setDeliveryOrigin('f:400', data());const noticeCount = c.messages.length;
    c.requests[12].reject(new Error('previous customer failure'));await oldError;
    assert.equal(c.messages.length, noticeCount);assert.equal(c.locating, false);

    // Edit from an all-branches board hydrates store origin and its saved courier.
    c.branch = '';c.companySelect.replaceChildren({value: ''});
    c.nextTicket = {...ticket(), id: 20, payment_status: 'unpaid', status: 'new', discount: '0.00', delivery_fee: '24.00', delivery_company: {id: 7}, items: [{product_id: 10, name: 'صنف', unit_price: '100.00', quantity_mode: 'piece', quantity: '1'}]};
    await c.editTicket(20);
    assert.equal(c.deliveryOriginBranch, 'f:400');assert.equal(c.branch, 'f:301');assert.equal(c.requests[13].values.delivery_origin_branch, 'f:400');
    assert.equal(c.companySelect.value, '7');assert.equal(c.payload().delivery_company_id, 7);
    await settle(c, 13, {...data(), branch: {value: 'f:301'}});
    assert.equal(c.companySelect.value, '7');assert.equal(c.payload().delivery_origin_branch, 'f:400');
    c.nextTicket = {...c.nextTicket, id: 21, delivery_location: {branch: 'f:301', latitude: 30.2, longitude: 31.3}};
    await c.editTicket(21);
    assert.equal(c.deliveryOriginBranch, '');assert.equal(c.requests[15].values.delivery_origin_branch, undefined);assert.equal(c.payload().delivery_origin_branch, undefined);
    await settle(c, 15, {branch: {value: 'f:301'}, ready: true, latitude: 30, longitude: 31, km_price: '10.00'});
    c.fields.customer_name.value = 'Current draft';
    c.nextTicket = {...c.nextTicket, id: 22, branch: {value: 'f:94'}, delivery_location: data()};
    await c.editTicket(22);
    assert.equal(c.branch, 'f:301');assert.equal(c.fields.customer_name.value, 'Current draft');assert.equal(c.messages.at(-1), 'list_error');

    // A deliberate user change during hydration must survive its completion.
    const reloading = c.loadDeliveryContext('7', 'شركة محفوظة');
    assert.equal(c.payload().delivery_company_id, 7);c.companySelect.value = '';
    await settle(c, 17, {branch: {value: 'f:301'}, ready: true, latitude: 30, longitude: 31, km_price: '10.00'});await reloading;
    assert.equal(c.companySelect.value, '');assert.equal(c.payload().delivery_company_id, null);
    console.log('PHONE_DELIVERY_ORIGIN_UI_CHECKS=55');
})().catch(error => {console.error(error);process.exitCode = 1;});
