'use strict';
// Run real POS navigation/read functions with deterministic timers and delayed network responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function definition(source, name) {
    const match = new RegExp('^    (?:async )?function ' + name + '\\(', 'm').exec(source);
    assert(match, 'production function exists: ' + name);
    const firstLine = source.slice(match.index, source.indexOf('\n', match.index));
    if (firstLine.trimEnd().endsWith('}')) return firstLine;
    const tail = source.slice(match.index + match[0].length);
    const next = /\n    (?:async )?function /.exec(tail);
    assert(next, 'production function boundary: ' + name);
    return source.slice(match.index, match.index + match[0].length + next.index);
}
function element() {
    const classes = new Set();
    return { value: '', hidden: false, checked: false, disabled: false, textContent: '', dataset: {}, attributes: {}, children: [],
        classList: { toggle(key, on) { if (on) classes.add(key); else classes.delete(key); }, contains: key => classes.has(key) },
        setAttribute(key, value) { this.attributes[key] = String(value); },
        appendChild(value) { this.children.push(value); return value; },
        replaceChildren(...values) { this.children = values; }, remove() {},
        querySelector() { return element(); }, querySelectorAll: () => [] };
}
function fixture(kind) {
    const source = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/' + kind + '-pos.js'), 'utf8');
    const prefix = kind === 'takeaway' ? 'pos' : 'dining';
    const controls = new Map(), timers = new Map(), requests = [], notices = [];
    const control = selector => { if (!controls.has(selector)) controls.set(selector, element()); return controls.get(selector); };
    let timerSequence = 0;
    const request = (parameters, signal) => new Promise(resolve => requests.push({ parameters: { ...parameters }, signal, resolve }));
    const context = vm.createContext({
        AbortController, branch: 'f:100', activeInvoice: 0, branchSelect: element(), category: '', catalogPage: 1,
        catalogTimer: null, searchTimer: null, quoteTimer: null, tableTimer: null,
        catalogController: null, quoteController: null, tableController: null, modalController: null,
        catalogGeneration: 0, quoteGeneration: 0, tableGeneration: 0, modalGeneration: 0,
        disposed: false, selling: false, registerLocked: false, uncertain: false, busy: false, readingTicket: false,
        loaded: true, policy: { payment_methods: ['cash'] }, permissions: { can_checkout: true, can_operate: true },
        boot: { permissions: { can_checkout: true, can_operate: true }, can_view_all_branches: true },
        urls: { catalog: '/catalog' }, cart: [], quote: null, products: new Map(), pagination: {}, tables: [],
        invoices: [{ today: { count: 7 } }], register: { balance: '55.00' }, discountInput: element(), modal: element(),
        root: { querySelector: control, querySelectorAll(selector) {
            return selector === '[data-' + prefix + '-category]' ? control('[data-' + prefix + '-categories]').children : [];
        } },
        setTimeout(fn, delay) { const id = ++timerSequence; timers.set(id, { fn, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        isLocked: () => context.selling || context.uncertain || context.registerLocked,
        locked: () => context.busy || context.uncertain || context.readingTicket,
        endpoint: (url, parameters) => parameters,
        fetch: (parameters, options) => request(parameters, options.signal),
        get: (url, parameters, signal) => request(parameters, signal),
        read: async value => value,
        label: key => key, money: value => String(value), scaled: value => Number(value), minor: value => Number(value),
        productNode: item => item, node() { return element(); }, renderCategories() {},
        renderPayments() {}, totals() {}, lock() {}, renderLines() {}, invalidateQuote() {},
        notify: (...values) => notices.push(values), resetDraft() {}, resetInvoice() {}, loadTables() {}, mayDiscard: () => true,
        window: { confirm: () => true },
    });
    const functions = ['cancelCatalogNavigation', 'selectCatalogCategory', 'loadCatalog', 'updateRegister', 'changeBranch'];
    if (kind === 'dining') functions.push('applyPolicy');
    vm.runInContext(functions.map(name => definition(source, name)).join('\n'), context);
    const categoryButtons = ['10', '20', '30'].map(id => {
        const button = element(); button.dataset[prefix === 'pos' ? 'posCategory' : 'diningCategory'] = id; return button;
    });
    control('[data-' + prefix + '-categories]').children = categoryButtons;
    control('[data-' + prefix + '-day-count]').textContent = '7';
    context.updateRegister(context.register, context.invoices[0].today);
    async function runTimers() {
        const due = [...timers.values()]; timers.clear();
        for (const timer of due) timer.fn();
        await Promise.resolve();
    }
    async function settle(index, body) {
        requests[index].resolve(body);
        for (let i = 0; i < 6; i++) await Promise.resolve();
    }
    return { source, context, requests, controls, control, timers, categoryButtons, runTimers, settle, notices, prefix };
}
function response(branch = 'f:100', id = 1) {
    return { success: true, ready: true, branch: { value: branch }, items: [{ id, unit_price: String(id) + '.00', stock: { quantity: String(id) } }],
        categories: [{ id: 10, name: 'A' }, { id: 20, name: 'B' }], pagination: { page: 1, last_page: 2 },
        permissions: { can_checkout: true, can_operate: true, can_manage: false }, policy: { can_discount: false, payment_methods: ['cash'] } };
}
let count = 0;
async function check(name, run) { await run(); count++; }
(async () => {
    for (const kind of ['takeaway', 'dining']) {
        await check(kind + ': rapid categories highlight immediately and fetch the final selection once', async () => {
            const f = fixture(kind), c = f.context;
            const old = c.catalogController = new AbortController();
            c.selectCatalogCategory('10'); c.selectCatalogCategory('20'); c.selectCatalogCategory('30');
            assert(old.signal.aborted, 'old catalog read is cancelled before the timer');
            assert.equal(f.requests.length, 0);
            assert.equal(f.timers.size, 1);
            assert.equal([...f.timers.values()][0].delay, 80);
            assert.deepEqual(f.categoryButtons.map(button => button.attributes['aria-pressed']), ['false', 'false', 'true']);
            assert(f.categoryButtons[2].classList.contains('is-active'));
            await f.runTimers();
            assert.equal(f.requests.length, 1);
            assert.equal(f.requests[0].parameters.category_id, '30');
            assert.equal(f.requests[0].parameters.catalog_only, 1);
            await f.settle(0, response());
            assert.equal(c.products.get('1').stock.quantity, '1');
        });
        await check(kind + ': toggling the selected category keeps existing all-category semantics', async () => {
            const f = fixture(kind); f.context.selectCatalogCategory('10'); f.context.selectCatalogCategory('10');
            await f.runTimers(); assert.equal(f.requests[0].parameters.category_id, '');
            await f.settle(0, response());
        });
        await check(kind + ': full reads cancel category/search timers and omit the light flag', async () => {
            const f = fixture(kind), c = f.context;
            c.selectCatalogCategory('10'); c.searchTimer = c.setTimeout(() => { throw Error('stale search'); }, 250);
            const read = c.loadCatalog(2);
            assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 1);
            assert.equal(f.requests[0].parameters.catalog_only, undefined);
            assert.equal(f.requests[0].parameters.page, 2);
            await f.settle(0, { ...response(), register: { balance: '80.00' }, today: { count: 9 } }); await read;
            assert.equal(f.control('[data-' + f.prefix + '-register-balance]').textContent, '80.00');
            assert.equal(String(f.control('[data-' + f.prefix + '-day-count]').textContent), '9');
            await f.runTimers(); assert.equal(f.requests.length, 1);
        });
        await check(kind + ': light reads preserve register/day but use fresh stock, prices and permissions', async () => {
            const f = fixture(kind), c = f.context, register = c.register, today = c.invoices[0].today;
            const first = c.loadCatalog(1, true); await f.settle(0, response('f:100', 2)); await first;
            assert.equal(c.register, register); assert.equal(c.invoices[0].today, today);
            assert.equal(f.control('[data-' + f.prefix + '-register-balance]').textContent, '55.00');
            assert.equal(String(f.control('[data-' + f.prefix + '-day-count]').textContent), '7');
            const next = c.loadCatalog(1, true); const changed = response('f:100', 3); changed.permissions.can_checkout = false; changed.permissions.can_operate = false;
            await f.settle(1, changed); await next;
            assert.equal(f.requests.length, 2, 'no catalog cache skips a live read');
            assert.equal(c.products.has('2'), false); assert.equal(c.products.get('3').unit_price, '3.00');
            assert.equal(c.products.get('3').stock.quantity, '3'); assert.equal(c.permissions.can_checkout, false);
        });
        await check(kind + ': an older response cannot overwrite a newer category or clear its busy state', async () => {
            const f = fixture(kind), c = f.context, old = c.loadCatalog(1);
            c.selectCatalogCategory('20'); await f.settle(0, response('f:100', 90)); await old;
            assert.equal(c.products.size, 0);
            assert.equal(f.control('[data-' + f.prefix + '-products]').attributes['aria-busy'], 'true');
            await f.runTimers(); await f.settle(1, response('f:100', 2));
            assert.equal(c.products.has('90'), false); assert.equal(c.products.get('2').unit_price, '2.00');
        });
        await check(kind + ': an actual branch transition cancels delayed navigation and performs one full read', async () => {
            const f = fixture(kind), c = f.context;
            c.selectCatalogCategory('10'); c.branchSelect.value = 'f:101'; c.changeBranch();
            assert.equal(f.timers.size, 0); assert.equal(f.requests.length, 1);
            assert.equal(f.requests[0].parameters.branch, 'f:101'); assert.equal(f.requests[0].parameters.catalog_only, undefined);
            await f.settle(0, response('f:101', 5)); await f.runTimers(); assert.equal(f.requests.length, 1);
        });
        for (const transition of ['disposed', 'branch', 'locked', 'generation', ...(kind === 'takeaway' ? ['invoice'] : [])]) {
            await check(kind + ': scheduled navigation cannot cross ' + transition, async () => {
                const f = fixture(kind), c = f.context; c.selectCatalogCategory('10');
                if (transition === 'disposed') c.disposed = true;
                if (transition === 'branch') c.branch = 'f:101';
                if (transition === 'locked') { c.selling = true; c.busy = true; }
                if (transition === 'generation') c.catalogGeneration++;
                if (transition === 'invoice') c.activeInvoice = 1;
                await f.runTimers(); assert.equal(f.requests.length, 0);
            });
        }
        await check(kind + ': cancelling navigation removes both category and search timers', async () => {
            const f = fixture(kind), c = f.context;
            c.searchTimer = c.setTimeout(() => { throw Error('stale search'); }, 250);
            c.selectCatalogCategory('10'); assert.equal(f.timers.size, 1);
            c.cancelCatalogNavigation(); await f.runTimers(); assert.equal(f.requests.length, 0);
        });
        await check(kind + ': source wiring reserves light reads for browsing and cancels them on cleanup', async () => {
            const f = fixture(kind), src = f.source;
            assert(src.includes("searchTimer = setTimeout(function () { loadCatalog(1, true); }"));
            assert(src.includes("=== 'next' ? 1 : -1), true)"));
            const cleanup = kind === 'takeaway' ? src.slice(src.indexOf('window.DashboardSPA.onCleanup(function')) : definition(src, 'cleanup');
            assert(cleanup.includes('cancelCatalogNavigation();'));
            if (kind === 'takeaway') assert(definition(src, 'switchInvoice').includes('cancelCatalogNavigation();'));
        });
    }
    console.log('POS catalog navigation checks passed: ' + count + ' cases against actual production functions.');
})().catch(error => { console.error(error); process.exitCode = 1; });
