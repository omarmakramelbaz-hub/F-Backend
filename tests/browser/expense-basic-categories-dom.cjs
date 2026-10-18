// Exercise the actual Blade controls and expense JavaScript; HTTP is mocked.
const fs = require('fs'), path = require('path'), vm = require('vm'), assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const fixtureSource = fs.readFileSync(path.join(__dirname, 'expense-stock.cjs'), 'utf8')
    .split('(async()=>{')[0].replace("const {chromium}=require('playwright');", '');
const fixtureContext = {require, __dirname, exports: {}};
vm.runInNewContext(fixtureSource + '\nexports.fixture={template,data,labels};', fixtureContext);
const {template, data, labels} = fixtureContext.exports.fixture;
const branches = [
    {value: 'f:100', name: 'فرع المحلة'},
    {value: 'f:101', name: 'فرع المنصورة'},
    {value: 'gs:1', name: 'متجر إلكتروني'}
];
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
const tick = () => new Promise(resolve => setImmediate(resolve));
async function settled() { await tick(); await tick(); }
function fixture(manage = true) {
    let applied = false, responder;
    const categoryPosts = [], expensePosts = [], requests = [], errors = [];
    function dataset(branch = 'all') {
        const result = data();
        result.filters.branch = branch;
        result.categories = {...result.categories};
        result.active_categories = {...result.active_categories};
        if (applied) {
            result.categories.custom_77 = 'تنظيف';
            result.active_categories.custom_77 = 'تنظيف';
            result.category_items.push({key: 'custom_77', name: 'تنظيف', protected: false, revision: 0});
        }
        return result;
    }
    const boot = {
        actor_id: manage ? 1 : 99, today: '2026-10-07', initial: dataset(), branches,
        permissions: {can_create: true, can_approve: manage, can_manage_categories: manage},
        urls: {data: '/data', save: '/save', recover: '/recover', export: '/export', report: '/report', categorySave: '/category-save'}
    };
    const dom = new JSDOM(`<meta name="csrf-token" content="test">${template(manage, manage)}<script type="application/json" id="branch-expenses-bootstrap">${JSON.stringify(boot)}</script><script type="application/json" id="branch-expenses-labels">${JSON.stringify(labels)}</script>`, {url: 'https://expense-test.invalid/', runScripts: 'outside-only'});
    const w = dom.window, doc = w.document;
    const form = doc.querySelector('[data-expense-form]'), branch = doc.querySelector('[data-expense-branch]');
    const branchOptions = branches.map(item => `<option value="${item.value}">${item.name}</option>`).join('');
    branch.innerHTML = '<option value="all">جميع الفروع</option>' + branchOptions;
    branch.value = 'all';
    form.elements.branch.innerHTML = branchOptions;
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () { this.open = false; };
    w.confirm = () => true;
    w.addEventListener('error', event => errors.push(event.message));
    const response = value => ({ok: true, status: 200, json: async () => value});
    const success = value => {
        applied = true;
        return response({...dataset(value.branch), basic_categories: {
            source: 'f:100', target: value.branch,
            target_name: branches.find(item => item.value === value.branch).name,
            count: 3, added: 1
        }});
    };
    responder = success;
    w.fetch = async (address, options = {}) => {
        const target = new URL(address);
        requests.push({path: target.pathname, method: options.method || 'GET'});
        if (target.pathname === '/category-save') {
            const value = JSON.parse(options.body);
            categoryPosts.push(value);
            return responder(value, options);
        }
        if (target.pathname === '/save') {
            const value = Object.fromEntries(options.body.entries());
            expensePosts.push(value);
            return response({success: true, expense: {branch: value.branch, status: 'pending', stock_quantity_kg: value.quantity_kg}});
        }
        return response(dataset(target.searchParams.get('branch') || branch.value));
    };
    w.eval(fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/branch-expenses.js'), 'utf8'));
    return {
        w, doc, form, branch, categoryPosts, expensePosts, requests, errors,
        button: doc.querySelector('[data-expense-basic-categories]'),
        notice: () => doc.querySelector('[data-expense-message] span').textContent,
        respond: callback => { responder = callback; }, success,
        change(field, value) { field.value = value; field.dispatchEvent(new w.Event('change', {bubbles: true})); },
        submit() { form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true})); },
        close() { w.close(); }
    };
}

(async () => {
    let f = fixture();
    try {
        check(f.button.textContent.trim() === labels.use_basic_categories, 'basic categories button uses the requested Arabic label');
        check(f.button.previousElementSibling.matches('[data-expense-categories-open]'), 'basic categories button is next to Add category');
        for (const target of ['all', 'gs:1']) {
            f.branch.value = target;
            f.button.click();
            check(f.categoryPosts.length === 0, `${target} cannot post a basic-category application`);
            check(f.notice() === labels.basic_categories_choose_branch, `${target} asks for a specific restaurant branch`);
        }
        f.branch.value = 'f:101';
        // A draft may refer to another branch: application must use the header.
        f.change(f.form.elements.branch, 'f:100');
        f.change(f.form.elements.category, 'purchase_oil');
        f.form.elements.description.value = 'مسودة لم تحفظ';
        f.form.elements.amount.value = '34.50';
        f.form.elements.quantity_kg.value = '2.500';
        const filters = f.doc.querySelector('[data-expense-filters]');
        filters.elements.category.value = 'purchases';
        filters.elements.search.value = 'فاتورة';
        f.button.click();
        await settled();
        const command = f.categoryPosts[0];
        check(f.categoryPosts.length === 1 && command.action === 'apply_basic' && command.branch === 'f:101', 'application posts to the selected header branch, not the stale draft branch');
        check(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(command.idempotency_key), 'application sends a fresh UUID');
        check(f.expensePosts.length === 0 && f.requests.every(request => request.path !== '/save'), 'applying categories never creates a financial expense');
        check(f.form.elements.branch.value === 'f:100' && f.form.elements.category.value === 'purchase_oil', 'application preserves draft branch and selected category');
        check(f.form.elements.description.value === 'مسودة لم تحفظ' && f.form.elements.amount.value === '34.50' && f.form.elements.quantity_kg.value === '2.500', 'application preserves unsaved description, amount and kilograms');
        check(filters.elements.category.value === 'purchases' && filters.elements.search.value === 'فاتورة' && f.branch.value === 'f:101', 'application preserves expense filters and selected header branch');
        check(f.notice().includes('فرع المنصورة') && f.notice().includes('3'), 'success notice identifies the receiving branch and category count');
        check(filters.elements.category.querySelector('[value="custom_77"]').textContent === 'تنظيف — فرع المنصورة', 'available categories use the receiving branch label');
        f.change(f.form.elements.branch, 'f:101');
        check(f.form.elements.category.querySelector('[value="custom_77"]').textContent === 'تنظيف — فرع المنصورة', 'draft category labels follow its selected receiving branch');
        const before = [...f.form.elements.category.options].map(option => option.textContent);
        f.button.click();
        await settled();
        check(JSON.stringify([...f.form.elements.category.options].map(option => option.textContent)) === JSON.stringify(before), 'repeated application does not duplicate category options or branch suffixes');
        check(f.categoryPosts[1].idempotency_key !== command.idempotency_key, 'a completed application starts a new command on a later click');
        check(f.errors.length === 0, 'successful basic-category use has no browser errors');
    } finally { f.close(); }

    f = fixture(false);
    try {
        check(!f.button && !f.doc.querySelector('[data-expense-categories-open]'), 'users without category-management permission see neither management button');
        check(f.errors.length === 0, 'branch-user rendering has no missing-control JavaScript errors');
    } finally { f.close(); }

    f = fixture();
    try {
        f.branch.value = 'f:101';
        let release;
        f.respond(value => new Promise(resolve => { release = () => resolve(f.success(value)); }));
        f.button.click();
        check(f.button.disabled && f.form.elements.amount.disabled && f.branch.disabled, 'pending application disables the button, expense form and branch selector');
        f.button.click();
        f.button.dispatchEvent(new f.w.Event('click', {bubbles: true}));
        check(f.categoryPosts.length === 1, 'rapid clicks and programmatic events cannot duplicate a pending application');
        release();
        await settled();
        check(!f.button.disabled && !f.form.elements.amount.disabled && !f.branch.disabled, 'application releases controls after completion');
    } finally { f.close(); }

    f = fixture();
    try {
        f.branch.value = 'f:101';
        f.respond(() => { throw new TypeError('network interrupted'); });
        f.button.click();
        await settled();
        const retryKey = f.categoryPosts[0].idempotency_key;
        check(f.notice() === labels.category_retry && !f.button.disabled, 'network failure offers retry and releases the button');
        f.respond(f.success);
        f.button.click();
        await settled();
        check(f.categoryPosts.length === 2 && f.categoryPosts[1].idempotency_key === retryKey, 'retrying the same branch retains its UUID after an uncertain response');
        f.respond(() => { throw new TypeError('network interrupted'); });
        f.button.click();
        await settled();
        const uncertainKey = f.categoryPosts[2].idempotency_key;
        f.branch.value = 'f:100';
        f.button.click();
        await settled();
        check(f.categoryPosts[3].branch === 'f:100' && f.categoryPosts[3].idempotency_key !== uncertainKey, 'changing the target after a failure creates a separate UUID');
        check(f.expensePosts.length === 0, 'retry and target changes never post to the expense save endpoint');
    } finally { f.close(); }

    f = fixture();
    try {
        check(!f.form.elements.description.required && !f.form.elements.description.closest('label').querySelector('b'), 'description is optional and has no required-field marker');
        f.form.elements.amount.value = '10';
        f.change(f.form.elements.category, 'purchases');
        check(f.form.checkValidity(), 'native validation accepts a blank description for an otherwise complete expense');
        f.submit();
        await settled();
        check(f.expensePosts.length === 1 && f.expensePosts[0].description === '', 'blank description is submitted successfully');
        f.form.elements.amount.value = '10';
        f.change(f.form.elements.category, 'purchase_oil');
        check(f.form.elements.quantity_kg.required && !f.form.checkValidity(), 'optional description does not remove stock quantity validation');
        f.submit();
        check(f.expensePosts.length === 1, 'blank kilograms still block a stock purchase');
        f.form.elements.quantity_kg.value = '٢٫٥٠٠';
        f.submit();
        await settled();
        check(f.expensePosts.length === 2 && f.expensePosts[1].quantity_kg === '2.500' && f.expensePosts[1].description === '', 'stock purchase accepts a blank description with valid required kilograms');
        check(f.errors.length === 0, 'optional-description save has no browser errors');
    } finally { f.close(); }
    console.log(checks + ' basic-category and optional-description DOM checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
