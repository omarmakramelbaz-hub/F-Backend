// The actual expense page must own its submit state even with the shared footer.
const {chromium} = require('playwright');
const fs = require('fs'), path = require('path'), vm = require('vm'), assert = require('node:assert/strict');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
const fixtureSource = fs.readFileSync(path.join(__dirname, 'expense-stock.cjs'), 'utf8')
    .split('(async()=>{')[0].replace("const {chromium}=require('playwright');", '');
const context = {require, __dirname, exports: {}};
vm.runInNewContext(fixtureSource + '\nexports.fixture={template,data,labels};', context);
const {template, data, labels} = context.exports.fixture;
const footerMatch = read('resources/views/admin/layouts/footer.blade.php')
    .match(/\$\(\s*"form"\s*\)\.not\("\[data-wa-reply-form\]"\)\.submit\(function\(\)\s*\{[\s\S]*?\n\s*\}\);/);
assert(footerMatch, 'extract the real shared-footer submit handler');
const underClick = read('resources/lang/ar/main.php').match(/'under click'\s*=>\s*'([^']+)'/)[1];
const footerHandler = footerMatch[0].replace("@lang('main.under click')", underClick);
const branches = [{value: 'f:100', name: 'فرع المحلة'}, {value: 'f:101', name: 'فرع المنصورة'}];
const clone = value => JSON.parse(JSON.stringify(value));
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
function initialCatalog() {
    return {categories: {purchases: 'مشتريات', salaries: 'رواتب', custom_88: 'مرتب حمادة'},
        active_categories: {purchases: 'مشتريات', salaries: 'رواتب', custom_88: 'مرتب حمادة'}, stock_categories: {},
        category_items: [{key: 'salaries', name: 'رواتب', revision: 0}, {key: 'custom_88', name: 'مرتب حمادة', revision: 0}]};
}
(async () => {
    const browser = await chromium.launch({headless: true,
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
    const page = await browser.newPage(), errors = [], commands = [], saves = [];
    const catalogs = Object.fromEntries(branches.map(branch => [branch.value, initialCatalog()]));
    let outcome = {type: 'success'}, release, dataCalls = 0, holdData = false;
    const pendingData = [];
    function dataset(scope = 'f:100') {
        return {...data(), ...clone(catalogs[scope]), category_scope: scope, branch_categories: clone(catalogs),
            filters: {...data().filters, branch: scope}};
    }
    const boot = {actor_id: 1, today: '2026-10-18', branches, initial: dataset(),
        permissions: {can_create: true, can_approve: true, can_manage_categories: true},
        urls: {data: '/data', save: '/save', recover: '/recover', categorySave: '/category-save', export: '/export', report: '/report'}};
    const options = branches.map(branch => `<option value="${branch.value}">${branch.name}</option>`).join('');
    const content = template(true, true)
        .replace(/(<select data-expense-branch[^>]*>)[\s\S]*?(<\/select>)/, '$1' + options + '$2')
        .replace(/(<select name="branch"[^>]*>)[\s\S]*?(<\/select>)/, '$1' + options + '$2');
    const html = `<html dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture"></head><body>
        ${content}<form data-shell-native><button type="submit">حفظ عام</button></form>
        <script id="branch-expenses-bootstrap" type="application/json">${JSON.stringify(boot)}</script>
        <script id="branch-expenses-labels" type="application/json">${JSON.stringify(labels)}</script></body></html>`;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://localhost/**', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname === '/category-save') {
            const value = JSON.parse(request.postData()); commands.push(value);
            const result = outcome;
            if (result.hold) await new Promise(resolve => { release = resolve; });
            if (result.type === 'network') return route.abort('failed');
            if (result.type === 'error') return route.fulfill({status: result.status || 422, json: result.payload});
            const catalog = catalogs[value.branch], category = catalog.category_items.find(item => item.key === value.key);
            category.name = value.name; category.revision++;
            catalog.categories[value.key] = value.name; catalog.active_categories[value.key] = value.name;
            return route.fulfill({json: {success: true, category: clone(category), ...clone(catalog), category_scope: value.branch}});
        }
        if (url.pathname === '/data') {
            dataCalls++;
            if(holdData) await new Promise(resolve => pendingData.push(resolve));
            return route.fulfill({json: dataset(url.searchParams.get('branch') || 'f:100')});
        }
        if (url.pathname === '/save') {
            saves.push(request.postData());
            return route.fulfill({json: {success: true, expense: {branch: 'f:100', status: 'pending'}}});
        }
        return route.fulfill({contentType: 'text/html', body: html});
    });
    const categoryForm = page.locator('[data-expense-category-form]'), input = page.locator('[data-expense-category-name]');
    const saveCategory = page.locator('[data-expense-category-save]'), status = page.locator('[data-expense-category-status]');
    async function submit(name, result) {
        outcome = result || {type: 'success'}; await input.fill(name); await saveCategory.click();
    }
    async function idle(label = labels.save_category_edit) {
        await page.waitForFunction(expected => {
            const button = document.querySelector('[data-expense-category-save]');
            return !button.disabled && button.textContent === expected;
        }, label);
    }
    async function edit() { await page.locator('[data-category-key="custom_88"] button').first().click(); }
    try {
        await page.goto('http://localhost/');
        await page.addScriptTag({content: read('public/dashboard/plugins/jquery/jquery.js')});
        await page.addScriptTag({content: read('public/dashboard/js/branch-expenses.js')});
        // Use the footer's real direct jQuery binding after the page handlers, as in admin.index.
        await page.evaluate(handler => {
            window.footerSpinnerWrites = 0; const original = jQuery.fn.html;
            jQuery.fn.html = function (value) { if (typeof value === 'string' && value.includes('fa-spinner')) window.footerSpinnerWrites++; return original.apply(this, arguments); };
            document.querySelector('[data-shell-native]').addEventListener('submit', event => event.preventDefault());
            eval(handler);
        }, footerHandler);
        await page.locator('[data-expense-categories-open]').first().click(); await edit();
        await submit('مرتب حمادة المحلة', {type: 'success', hold: true});
        await page.waitForFunction(label => document.querySelector('[data-expense-category-save]').textContent === label, labels.saving);
        check(commands.length === 1, 'rename sends exactly one POST before completing');
        check(await saveCategory.isDisabled(), 'category save is disabled while the request is active');
        check(commands[0].branch === 'f:100' && commands[0].action === 'update' && commands[0].key === 'custom_88', 'request scopes the custom category rename to Mahalla');
        check(commands[0].expected_revision === 0 && /^[0-9a-f-]{36}$/.test(commands[0].idempotency_key), 'rename retains revision and operation UUID');
        holdData = true;
        const successListing = page.waitForRequest(request => new URL(request.url()).pathname === '/data');
        release(); await successListing; await idle(labels.add_category);
        check(pendingData.length === 1 && await saveCategory.isEnabled(), 'a successful rename unlocks without waiting for its listing refresh');
        holdData = false; pendingData.splice(0).forEach(resolve => resolve());
        check(await status.textContent() === labels.category_saved, 'valid rename completes with its success message');
        check(await page.locator('[data-category-key="custom_88"] strong').textContent() === 'مرتب حمادة المحلة — فرع المحلة', 'local list displays the changed category');
        check(catalogs['f:101'].categories.custom_88 === 'مرتب حمادة', 'another branch keeps its original category name');
        check(await page.evaluate(() => window.footerSpinnerWrites) === 0, 'page-owned category submit never reaches the footer spinner');

        await edit();
        holdData = true;
        const blockedListing = page.waitForRequest(request => new URL(request.url()).pathname === '/data');
        await submit('رواتب', {type: 'error', payload: {message: 'The given data was invalid.', errors: {name: [labels.category_duplicate]}}});
        await blockedListing;
        await idle();
        check(await status.textContent() === labels.category_duplicate, 'Laravel field error explains a duplicate rename in Arabic');
        check(pendingData.length === 1 && await saveCategory.isEnabled(), 'an unanswered listing refresh cannot keep a rejected edit locked');
        check(await input.inputValue() === 'رواتب' && await categoryForm.locator('[data-expense-category-cancel]').isVisible(), 'validation keeps the edit and entered name available for correction');
        const rejected = commands[commands.length - 1];
        holdData = false;
        pendingData.splice(0).forEach(resolve => resolve());
        await submit('مرتب حمادة معدل'); await idle(labels.add_category);
        check(commands[commands.length - 1].idempotency_key !== rejected.idempotency_key, 'corrected validation failure uses a fresh UUID');
        check(catalogs['f:100'].categories.custom_88 === 'مرتب حمادة معدل' && catalogs['f:101'].categories.custom_88 === 'مرتب حمادة', 'correcting the name succeeds in the selected branch only');

        await edit(); await submit('مرتب حمادة جديد', {type: 'network'}); await idle();
        const uncertain = commands[commands.length - 1];
        check(await status.textContent() === labels.category_retry && await input.inputValue() === 'مرتب حمادة جديد', 'network failure restores the editable title and keeps the pending draft');
        await submit('مرتب حمادة جديد'); await idle(labels.add_category);
        check(commands[commands.length - 1].idempotency_key === uncertain.idempotency_key, 'network retry preserves the original UUID');

        await edit();
        for (const [payload, expected, code] of [
            [{message: 'generic', errors: {name: 'اسم غير صحيح'}}, 'اسم غير صحيح'],
            [{message: 'generic', errors: {name: ['اسم مطلوب', 123, null, {}], branch: ['فرع غير صحيح']}}, 'اسم مطلوب · فرع غير صحيح'],
            [{message: 'رسالة بديلة', errors: {name: [null, {}]}}, 'رسالة بديلة'],
            [{message: 'رسالة بديلة', errors: ['خطأ في بنية الرد']}, 'رسالة بديلة'],
            [{message: {bad: 'type'}, errors: {name: [false]}}, labels.error],
            [{message: 'generic', errors: {name: ['<img src=x onerror="window.expenseInjected=1">']}}, '<img src=x onerror="window.expenseInjected=1">'],
            [{message: 'generic', errors: {name: ['تفاصيل لا تتقدم على انتهاء الجلسة']}}, labels.session, 419]
        ]) {
            await submit('اسم مصحح', {type: 'error', status: code || 422, payload}); await idle();
            check(await status.textContent() === expected, 'field-error parsing and fallback: ' + expected);
        }
        check(await status.locator('img').count() === 0 && !(await page.evaluate(() => window.expenseInjected)), 'validation messages render as text without injecting HTML');
        check(await input.inputValue() === 'اسم مصحح' && await saveCategory.textContent() === labels.save_category_edit, 'even rejected sessions leave the category form editable with its original action label');
        await page.locator('[data-expense-category-cancel]').click(); await page.locator('[data-expense-categories-close]').click();

        const beforeFilters = dataCalls;
        await page.locator('[data-expense-filters] button[type="submit"]').click();
        await page.waitForFunction(() => !document.querySelector('[data-expense-save]').disabled);
        check(dataCalls === beforeFilters + 1 && await page.locator('[data-expense-filters] button[type="submit"]').textContent() === labels.apply, 'filter submit loads data and retains its own button label');
        const expenseForm = page.locator('[data-expense-form]');
        await expenseForm.locator('[name="category"]').selectOption('purchases'); await expenseForm.locator('[name="amount"]').fill('25');
        await expenseForm.locator('[data-expense-save]').click();
        await page.waitForFunction(message => document.querySelector('[data-expense-message] span').textContent === message, labels.saved);
        check(saves.length === 1 && await expenseForm.locator('[data-expense-save]').textContent() === labels.save, 'expense submit completes once and restores its own action label');
        check(await page.evaluate(() => window.footerSpinnerWrites) === 0, 'all three expense forms exclude the footer submit handler');

        await page.locator('[data-shell-native] button').click();
        check((await page.locator('[data-shell-native] button').textContent()).includes(underClick), 'control form confirms the extracted footer handler was active');
        check(await page.evaluate(() => window.footerSpinnerWrites) === 1, 'only the unrelated control form runs the real footer spinner');
        check(errors.length === 0, 'shell interaction completes without browser exceptions');
        console.log(checks + ' expense submit-shell checks passed with actual Chromium, jQuery, Blade controls and footer handler');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
