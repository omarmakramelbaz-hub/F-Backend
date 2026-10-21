'use strict';
// Execute the actual invoice-switching code with DOM/network side effects stubbed.
// POS_BRANCH_TAKEAWAY_JS may point to an older source to demonstrate the regression.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = process.env.POS_BRANCH_TAKEAWAY_JS || path.join(__dirname, '../public/dashboard/js/takeaway-pos.js');
const source = fs.readFileSync(sourcePath, 'utf8');
function definition(name) {
    const marker = '    function ' + name + '(';
    const start = source.indexOf(marker);
    assert(start >= 0 && source.indexOf(marker, start + marker.length) < 0, 'unique production function ' + name);
    const end = source.indexOf('\n    function ', start + marker.length);
    assert(end > start, 'production function boundary ' + name);
    return source.slice(start, end);
}

function element() {
    return { value: '', checked: false, hidden: false, textContent: '', dataset: {},
        querySelectorAll: () => [], appendChild() {}, replaceChildren() {}, remove() {},
        setAttribute() {}, classList: { toggle() {} } };
}

function fixture(selected = 'f:100') {
    const controls = new Map();
    const get = selector => { if (!controls.has(selector)) controls.set(selector, element()); return controls.get(selector); };
    const reads = [], events = [];
    let sequence = 0;
    const context = vm.createContext({
        boot: { branches: [{ value: 'f:100', name: 'Mahalla' }, { value: 'f:101', name: 'Mansoura' }], permissions: { can_checkout: true } },
        uuid: () => 'fixture-key-' + (++sequence),
        minor: value => Number(value),
        root: { querySelector: get },
        branchSelect: Object.assign(element(), { value: selected, selectize: { setValue(value, silent) { events.push(['selectize', value, silent]); } } }),
        branch: selected, activeInvoice: 0,
        selling: false, registerLocked: false, uncertain: false, quarantined: false, sold: false, disposed: false,
        consentEnterAffirmed: false, catalogGeneration: 0, quoteGeneration: 0, modalGeneration: 0,
        catalogController: null, quoteController: null, modalController: null, searchTimer: null, quoteTimer: null,
        clearTimeout() {}, modal: element(), customerPhone: element(), customerName: element(),
        discountInput: element(), cashInput: element(), confirmedInput: element(), products: new Map(),
        node: () => element(), label: key => key,
        renderCategories() {}, renderPayments() {}, renderLines() {}, totals() {}, showQuantity() {},
        updateRegister() {}, renderSaved() {}, showConsent() {}, lock() {},
        notify: (...args) => events.push(['notify', ...args]),
        loadCatalog: page => reads.push({ branch: context.branch, page }),
        recoverSale: async () => false,
        storeInvoice: () => { context.invoices[context.activeInvoice].branch = context.branch; },
    });
    vm.runInContext([definition('newInvoice'), definition('invoiceIsDirty'), definition('switchInvoice')].join('\n'), context);
    context.invoices = Array.from({ length: 5 }, () => context.newInvoice(''));
    context.invoices[0] = context.newInvoice(selected);
    return { context, reads, events, switchTo: (index, restoring) => context.switchInvoice(index, restoring) };
}

let checks = 0;
function check(message, run) { run(); checks++; }

check('all five empty tabs follow the selected branch', () => {
    const { context, switchTo, reads } = fixture();
    for (const index of [1, 2, 3, 4, 0]) {
        switchTo(index);
        assert.equal(context.branch, 'f:100', 'empty invoice ' + index + ' retains the selected branch');
        assert.equal(context.branchSelect.value, 'f:100');
        assert.equal(reads.at(-1).branch, 'f:100');
    }
});

check('an empty tab with an old concrete branch adopts the current branch and clears branch caches', () => {
    const { context, switchTo, reads } = fixture('f:101');
    context.invoices[1] = context.newInvoice('f:100');
    context.invoices[1].products = [{ id: 900 }];
    context.invoices[1].categories = [{ id: 99 }];
    context.invoices[1].register = { branch: 'f:100' };
    context.invoices[1].policy = { can_discount: true };
    switchTo(1);
    assert.equal(context.branch, 'f:101');
    assert.equal(context.invoices[1].branchName, 'Mansoura');
    assert.equal(context.invoices[1].products.length, 0);
    assert.equal(context.invoices[1].categories.length, 0);
    assert.equal(context.invoices[1].register, null);
    assert.equal(Object.keys(context.invoices[1].policy).length, 0);
    assert.equal(reads.at(-1).branch, 'f:101');
});

check('an explicit all-branches selection stays selected on empty tabs without fetching a branch catalog', () => {
    const { context, switchTo, reads } = fixture('');
    for (let index = 1; index < 5; index++) context.invoices[index] = context.newInvoice('f:100');
    for (const index of [1, 2, 3, 4, 0]) { switchTo(index); assert.equal(context.branch, ''); }
    assert.equal(reads.length, 0);
});

for (const [name, values] of [
    ['cart', { cart: [{ product: { id: 1 }, quantity: '1' }] }],
    ['customer phone', { customerPhone: '01012345678' }],
    ['customer name', { customerName: 'Customer' }],
    ['notes', { notes: 'Keep this branch' }],
    ['cash', { cash: '100.00' }],
    ['payment reference', { reference: 'saved-ref' }],
    ['discount', { discount: '5.00' }],
    ['sold receipt', { sold: true, lastReceipt: { id: 42, branch: { value: 'f:100' } } }],
    ['uncertain payment', { uncertain: true }],
    ['frozen payment', { frozenPayload: { branch: 'f:100', idempotency_key: 'immutable-key' } }],
    ['quarantined payment', { quarantined: true }],
]) check(name + ' stays bound to the original branch and object', () => {
    const { context, switchTo } = fixture('f:101');
    const invoice = context.invoices[1] = Object.assign(context.newInvoice('f:100'), values);
    const before = JSON.stringify(invoice);
    switchTo(1);
    assert.equal(context.branch, 'f:100', name + ' must not move to a different branch');
    assert.equal(context.invoices[1], invoice, name + ' invoice must not be replaced');
    assert.equal(JSON.stringify(invoice), before, name + ' state and payment key must not change');
});

check('restoring an existing empty invoice leaves its saved branch unchanged', () => {
    const { context, switchTo } = fixture('f:101');
    const invoice = context.invoices[1] = context.newInvoice('f:100');
    switchTo(1, true);
    assert.equal(context.branch, 'f:100');
    assert.equal(context.invoices[1], invoice);
});

for (const lock of ['selling', 'registerLocked']) check(lock + ' blocks invoice switching', () => {
    const { context, switchTo, reads } = fixture('f:101');
    context[lock] = true;
    switchTo(1);
    assert.equal(context.activeInvoice, 0);
    assert.equal(context.branch, 'f:101');
    assert.equal(reads.length, 0);
});

check('selectize mirrors the inherited branch without emitting another branch-change event', () => {
    const { events, switchTo } = fixture('f:101');
    switchTo(1);
    assert.deepEqual(events.find(event => event[0] === 'selectize'), ['selectize', 'f:101', true]);
});

console.log('Takeaway invoice branch regression checks passed: ' + checks + ' cases against production functions.');
