// Exercise actual Blade controls and JavaScript with separate branch catalogs.
const fs = require('fs'), path = require('path'), vm = require('vm'), assert = require('node:assert/strict');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, 'expense-stock.cjs'), 'utf8')
    .split('(async()=>{')[0].replace("const {chromium}=require('playwright');", '');
const context = {require, __dirname, exports: {}};
vm.runInNewContext(source + '\nexports.fixture={template,data,labels};', context);
const {template, data, labels} = context.exports.fixture;
const branches = [{value: 'f:100', name: 'فرع المحلة'}, {value: 'f:101', name: 'فرع المنصورة'}, {value: 'gs:1', name: 'متجر إلكتروني'}];
const clone = value => JSON.parse(JSON.stringify(value));
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
const tick = () => new Promise(resolve => setImmediate(resolve));
async function settled() { await tick(); await tick(); }
function fixture(manage = true) {
    const catalogs = Object.fromEntries(branches.map(branch => [branch.value, {
        categories: {purchases: 'مشتريات', purchase_oil: 'زيت', custom_88: 'نظافة'},
        active_categories: {purchases: 'مشتريات', purchase_oil: 'زيت', custom_88: 'نظافة'},
        stock_categories: {purchase_oil: 'purchase_oil'},
        category_items: [{key: 'purchase_oil', name: 'زيت', protected: true, revision: 0}, {key: 'custom_88', name: 'نظافة', protected: false, revision: 0}]
    }]));
    catalogs['f:100'].stock_categories.custom_88 = 'purchase_vinegar';
    let fail = false, failLoad = false;
    const commands = [], saves = [], errors = [];
    function dataset(scope = 'all') {
        const d = data();
        const catalog = scope === 'all' ? catalogs['f:100'] : catalogs[scope];
        Object.assign(d, clone(catalog), {category_scope: scope, branch_categories: clone(catalogs)});
        d.filters.branch = scope;
        d.items = branches.slice(0, 2).map((branch, i) => ({id: i + 1, number: 'EXP-' + i, revision: 1, branch: branch.value, branch_name: branch.name,
            occurred_on: '2026-10-18', category: 'purchase_oil', category_base_name: catalogs[branch.value].categories.purchase_oil,
            category_name: catalogs[branch.value].categories.purchase_oil + ' — ' + branch.name, description: '', amount: '10.00', payment_method: 'cash', actor_name: 'المالك', status: 'pending'}));
        return d;
    }
    const boot = {actor_id: manage ? 1 : 99, today: '2026-10-18', initial: dataset(), branches,
        permissions: {can_create: true, can_approve: manage, can_manage_categories: manage},
        urls: {data: '/data', save: '/save', recover: '/recover', export: '/export', report: '/report', categorySave: '/category-save'}};
    const dom = new JSDOM(`<meta name="csrf-token" content="test">${template(manage, manage)}<script type="application/json" id="branch-expenses-bootstrap">${JSON.stringify(boot)}</script><script type="application/json" id="branch-expenses-labels">${JSON.stringify(labels)}</script>`, {url: 'https://expense-test.invalid/', runScripts: 'outside-only'});
    const w = dom.window, doc = w.document;
    const form = doc.querySelector('[data-expense-form]'), header = doc.querySelector('[data-expense-branch]');
    const options = branches.map(branch => `<option value="${branch.value}">${branch.name}</option>`).join('');
    header.innerHTML = '<option value="all">جميع الفروع</option>' + options; header.value = 'all'; form.elements.branch.innerHTML = options;
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () { this.open = false; };
    w.confirm = () => true;
    w.addEventListener('error', event => errors.push(event.message));
    const response = value => ({ok: true, status: 200, json: async () => value});
    w.fetch = async (address, options = {}) => {
        const target = new URL(address);
        if (target.pathname === '/category-save') {
            const value = JSON.parse(options.body); commands.push(value);
            if (fail) throw new TypeError('network interrupted');
            const catalog = catalogs[value.branch];
            let category;
            if (value.action === 'update') {
                category = catalog.category_items.find(item => item.key === value.key);
                category.name = value.name; category.revision++;
                catalog.categories[value.key] = value.name; catalog.active_categories[value.key] = value.name;
            } else if (value.action === 'create') {
                category = {key: 'custom_99', name: value.name, protected: false, revision: 0};
                catalog.category_items.push(category); catalog.categories[category.key] = value.name; catalog.active_categories[category.key] = value.name;
            } else if (value.action === 'delete') {
                delete catalog.active_categories[value.key]; catalog.category_items = catalog.category_items.filter(item => item.key !== value.key);
            } else if (value.action === 'apply_basic' && !catalog.categories.custom_77) {
                catalog.categories.custom_77 = 'بنود أساسية'; catalog.active_categories.custom_77 = 'بنود أساسية';
                catalog.category_items.push({key: 'custom_77', name: 'بنود أساسية', protected: false, revision: 0});
            }
            // A mutation returns only its target catalog, so the UI must retain other branches.
            return response({success: true, ...clone(catalog), category_scope: value.branch, category,
                basic_categories: value.action === 'apply_basic' ? {target: value.branch, target_name: branches.find(branch => branch.value === value.branch).name, count: 3} : undefined});
        }
        if (target.pathname === '/save') {
            const value = Object.fromEntries(options.body.entries()); saves.push(value);
            return response({success: true, expense: {branch: value.branch, status: 'pending'}});
        }
        if (failLoad) throw new TypeError('listing network interrupted');
        return response(dataset(target.searchParams.get('branch') || header.value));
    };
    w.eval(fs.readFileSync(path.join(__dirname, '../../public/dashboard/js/branch-expenses.js'), 'utf8'));
    function change(field, value) { field.value = value; field.dispatchEvent(new w.Event('change', {bubbles: true})); }
    const categoryForm = doc.querySelector('[data-expense-category-form]');
    function submitCategory(name) { doc.querySelector('[data-expense-category-name]').value = name; categoryForm.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true})); }
    return {w, doc, form, header, catalogs, commands, saves, errors, change, submitCategory,
        notice: () => doc.querySelector('[data-expense-message] span').textContent,
        fail(value) { fail = value; }, failLoad(value) { failLoad = value; }, close() { w.close(); }};
}
(async () => {
    const f = fixture();
    try {
        const manager = f.doc.querySelector('[data-expense-categories-dialog]');
        const open = f.doc.querySelector('[data-expense-categories-open]');
        const option = key => f.form.elements.category.querySelector(`[value="${key}"]`);
        const edit = key => f.doc.querySelector(`[data-category-key="${key}"] button`).click();
        open.click();
        check(!manager.open && f.notice() === labels.category_choose_branch, 'all-branches view asks for a specific branch before opening management');
        f.submitCategory('بند عام');
        check(f.commands.length === 0, 'forced all-branches category submission is refused');
        f.change(f.header, 'f:101'); await settled();
        open.click();
        check(manager.open && f.doc.querySelector('[data-category-key="purchase_oil"] strong').textContent === 'زيت — فرع المنصورة', 'manager uses the selected header branch catalog');
        check(f.doc.querySelector('[data-expense-category-branch]').textContent === '— فرع المنصورة', 'management heading visibly identifies its branch');
        f.change(f.form.elements.branch, 'f:100'); f.change(f.form.elements.category, 'purchase_oil');
        f.form.elements.quantity_kg.value = '2.500'; f.form.elements.amount.value = '34.50'; f.form.elements.description.value = 'مسودة';
        edit('purchase_oil'); f.submitCategory('زيت عباد'); await settled();
        check(f.commands[0].action === 'update' && f.commands[0].branch === 'f:101' && f.commands[0].expected_revision === 0, 'rename posts the selected header branch, stable key and revision');
        check(option('purchase_oil').textContent === 'زيت — فرع المحلة', 'renaming Mansoura leaves the Mahalla draft name unchanged');
        check(f.form.elements.branch.value === 'f:100' && f.form.elements.category.value === 'purchase_oil' && f.form.elements.quantity_kg.value === '2.500', 'scoped rename preserves another branch’s draft category and kilograms');
        check(f.form.elements.amount.value === '34.50' && f.form.elements.description.value === 'مسودة', 'scoped mutation preserves unsaved description and amount');
        const rows = [...f.doc.querySelectorAll('[data-expense-rows] tr')];
        check(rows[0].children[3].textContent === 'زيت — فرع المحلة' && rows[1].children[3].textContent === 'زيت عباد — فرع المنصورة', 'expense rows display each branch’s distinct category name');
        const filterOption = f.doc.querySelector('[data-expense-filters] [name="category"] [value="purchase_oil"]');
        check(filterOption.textContent === 'زيت عباد — فرع المنصورة', 'header filter uses its own branch catalog');
        f.change(f.form.elements.branch, 'f:101');
        check(option('purchase_oil').textContent === 'زيت عباد — فرع المنصورة' && f.form.elements.quantity_kg.required && f.form.elements.quantity_kg.value === '2.500', 'form branch switch uses the local label and retains the required stock quantity');
        f.change(f.form.elements.category, 'custom_88');
        check(!f.form.elements.quantity_kg.required && f.doc.querySelector('[data-expense-quantity]').hidden, 'Mansoura local category does not inherit Mahalla’s stock mapping');
        f.change(f.form.elements.branch, 'f:100');
        check(f.form.elements.quantity_kg.required && !f.doc.querySelector('[data-expense-quantity]').hidden, 'Mahalla’s own stock category map makes kilograms required');
        f.form.elements.quantity_kg.value = '1.250';
        f.submitCategory('صيانة محلية'); await settled();
        check(f.commands[1].action === 'create' && f.commands[1].branch === 'f:101', 'new category is assigned to the selected header branch');
        check(!option('custom_99') && f.form.elements.category.value === 'custom_88' && f.form.elements.quantity_kg.value === '1.250', 'local creation does not insert or select a foreign branch category in the draft');
        f.change(f.form.elements.branch, 'f:101');
        check(option('custom_99').textContent === 'صيانة محلية — فرع المنصورة', 'new category appears when its own branch is selected');
        f.doc.querySelector('[data-category-key="custom_88"] .ex-category-delete').click(); await settled();
        check(f.commands[2].action === 'delete' && f.commands[2].branch === 'f:101' && !option('custom_88'), 'deletion removes a category only from the target branch’s new choices');
        f.change(f.form.elements.branch, 'f:100');
        check(option('custom_88').textContent === 'نظافة — فرع المحلة', 'another branch retains the deleted category');
        f.doc.querySelector('[data-expense-basic-categories]').click(); await settled();
        check(f.commands[3].action === 'apply_basic' && f.commands[3].branch === 'f:101', 'basic category use posts its selected receiving branch');
        check(!option('custom_77') && option('purchase_oil').textContent === 'زيت — فرع المحلة', 'basic category response does not overwrite another branch’s draft catalog');
        f.change(f.form.elements.branch, 'f:101');
        check(option('purchase_oil').textContent === 'زيت عباد — فرع المنصورة' && !option('custom_88'), 'using basics preserves this branch’s rename and deleted category');
        const before = f.form.elements.category.options.length;
        f.doc.querySelector('[data-expense-basic-categories]').click(); await settled();
        check(f.form.elements.category.options.length === before, 'repeated basic use does not duplicate options');
        edit('purchase_oil'); f.fail(true); f.submitCategory('زيت ذرة'); await settled();
        const failed = f.commands[f.commands.length - 1];
        f.fail(false); f.submitCategory('زيت ذرة'); await settled();
        check(f.commands[f.commands.length - 1].idempotency_key === failed.idempotency_key, 'uncertain same-branch rename retries with its original UUID');
        edit('purchase_oil'); f.fail(true); f.submitCategory('زيت سمسم'); await settled();
        const anotherFailed = f.commands[f.commands.length - 1];
        f.change(f.header, 'f:100'); await settled();
        check(!manager.open && f.doc.querySelector('[data-expense-category-name]').value === '' && f.doc.querySelector('[data-expense-category-status]').textContent === '', 'header branch change closes and clears the old category editor and status');
        open.click(); f.fail(false); edit('purchase_oil'); f.submitCategory('زيت سمسم'); await settled();
        check(f.commands[f.commands.length - 1].branch === 'f:100' && f.commands[f.commands.length - 1].idempotency_key !== anotherFailed.idempotency_key, 'same rename in another branch is a separate command UUID');
        f.change(f.header, 'all'); await settled(); open.click();
        check(!manager.open && f.notice() === labels.category_choose_branch, 'switching back to all refuses category editing again');
        f.change(f.header, 'gs:1'); await settled(); open.click(); f.submitCategory('خدمة متجر'); await settled();
        check(f.commands[f.commands.length - 1].branch === 'gs:1', 'store category management also posts an explicit branch');
        check(!f.form.elements.description.required && !f.form.elements.description.closest('label').querySelector('b'), 'description remains optional');
        check(f.errors.length === 0, 'scoped category operations complete without browser errors');
    } finally { f.close(); }
    const reloadFailure = fixture();
    try {
        const f = reloadFailure;
        f.change(f.header, 'f:101'); await settled();
        f.doc.querySelector('[data-expense-categories-open]').click();
        f.doc.querySelector('[data-category-key="purchase_oil"] button').click();
        f.failLoad(true); f.submitCategory('زيت فرع المنصورة'); await settled();
        f.change(f.form.elements.branch, 'f:100');
        check(f.form.elements.category.querySelector('[value="purchase_oil"]').textContent === 'زيت — فرع المحلة', 'failed list refresh retains the other branch catalog after an acknowledged rename');
        f.change(f.form.elements.branch, 'f:101');
        check(f.form.elements.category.querySelector('[value="purchase_oil"]').textContent === 'زيت فرع المنصورة — فرع المنصورة', 'form branch switch retains the acknowledged name after list refresh fails');
        f.doc.querySelector('[data-expense-new]').click();
        check(f.form.elements.category.querySelector('[value="purchase_oil"]').textContent === 'زيت فرع المنصورة — فرع المنصورة', 'resetting an expense draft does not reingest the stale pre-rename listing catalog');
        f.change(f.header, 'f:100'); await settled();
        check(f.form.elements.category.querySelector('[value="purchase_oil"]').textContent === 'زيت — فرع المحلة', 'header reset after failed refresh retains the unaffected branch name');
        f.change(f.header, 'f:101'); await settled();
        check(f.form.elements.category.querySelector('[value="purchase_oil"]').textContent === 'زيت فرع المنصورة — فرع المنصورة' && f.errors.length === 0, 'returning to the edited header retains the acknowledged local name without browser errors');
    } finally { reloadFailure.close(); }
    const denied = fixture(false);
    try {
        check(!denied.doc.querySelector('[data-expense-categories-open]') && denied.errors.length === 0, 'branch users without management permission render safely without category controls');
    } finally { denied.close(); }
    console.log(checks + ' branch-local category DOM checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
