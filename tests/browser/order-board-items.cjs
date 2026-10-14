// Exercises the production app-order editor with deterministic, branch-scoped API fixtures.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const read = name => fs.readFileSync(path.join(__dirname, '../..', name), 'utf8');
const escape = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const source = read('public/dashboard/js/order-board-items.js');
const css = read('public/dashboard/css/order-board-items.css');
const takeawayCss = read('public/dashboard/css/takeaway-pos.css');
const partial = read('resources/views/admin/orders/board_items.blade.php');
function translations(locale) {
    return Object.fromEntries([...read(`resources/lang/${locale}/order_board.php`).matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(match => [match[1], match[2]]));
}
const unsafeName = '<img src=x onerror="window.fixtureXss=true"> فسيخ نبروه كبير';
const revision = 'b'.repeat(64);
const imageUrl = 'http://localhost/fixtures/fish.svg';
function initial() {
    return {success: true, save_url: '/orders/f-12/items', order: {
        key: 'f:12', number: '20261014-0012', revision,
        subtotal: '242.50', delivery: '50.00', tax: '0.00', service: '0.00', total: '292.50',
        pricing: {delivery: '50.00', tax_rate: '0.00', service_rate: '0.00'},
        quantity_hint: 'للأصناف الموزونة أدخل الوزن الفعلي بالكيلو؛ 0.850 تعني 850 جرام. بقية الأصناف بالعدد.',
        items: [
            {line_id: 11, product_id: 101, option_id: '', feature_id: 0, product_clean: null, name: unsafeName,
                quantity: '1.000', unit_price: '200.00', line_total: '187.50', unit: 'kg', weight_per_unit: '1.000', quantity_step: '0.001', option_label: ''},
            {line_id: 12, product_id: 102, option_id: '', feature_id: 0, product_clean: null, name: 'علبة رنجة',
                quantity: '2', unit_price: '30.00', line_total: '55.00', unit: 'piece', weight_per_unit: null, quantity_step: '1', option_label: ''}
        ]
    }, branch: {value: 'f:100', name: 'فرع المحلة'}, categories: [{id: 1, name: 'فسيخ'}, {id: 2, name: 'رنجة'}, {id: 3, name: 'سردين'}], catalog: [
        {id: 101, product_id: 101, name: unsafeName, price: '200.00', unit: 'kg', quantity_mode: 'weight', image_url: imageUrl, category_id: 1, weight_per_unit: '1.000', quantity_step: '0.001', options: []},
        {id: 102, product_id: 102, name: 'علبة رنجة', price: '30.00', unit: 'piece', quantity_mode: 'piece', image_url: imageUrl, category_id: 2, weight_per_unit: null, quantity_step: '1', options: []},
        {id: 103, product_id: 103, name: 'سردين بلدي', price: '40.00', unit: 'kg', quantity_mode: 'weight', image_url: imageUrl, category_id: 3, weight_per_unit: '1.000', quantity_step: '0.001', options: [
            {id: 'feature:9', feature_id: 9, product_clean: null, label: 'نصف', price: '60.00', weight_per_unit: '0.500'}
        ]},
        {id: 104, product_id: 104, name: 'صنف متوقف', price: '90.00', unit: 'piece', quantity_mode: 'piece', image_url: imageUrl, category_id: 2, weight_per_unit: null, quantity_step: '1', available: false, options: []}
    ]};
}
function fixture(locale) {
    const labels = translations(locale);
    const keypad = ['7','8','9','backspace','4','5','6','clear','1','2','3','confirm','.','0'].map(key => `<button type="button" data-items-key="${key}" ${key === 'confirm' ? 'class="tp-key-confirm"' : ''}>${key === 'confirm' ? escape(labels.confirm_quantity) : key === 'backspace' ? '⌫' : key === 'clear' ? '<span class="tp-key-clear">C</span>' : key}</button>`).join('');
    const editor = partial.replace(/@foreach\(\['7'[\s\S]*?@endforeach/, keypad).replace(/{{\s*__\('order_board\.([^']+)'\)\s*}}/g, (_, key) => escape(labels[key] || key));
    assert(!editor.includes('{{'), 'All Blade labels must render in the browser fixture.');
    return `<html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><meta name="csrf-token" content="fixture-csrf"><style>body{margin:0;font-family:Arial,sans-serif;background:#eef2f7}*{box-sizing:border-box}main{padding:24px}.ob-card{max-width:400px;background:#fff;padding:20px}button{font:inherit}${takeawayCss}${css}</style></head><body><main id="order-board"><article class="ob-card" data-order-key="f:12"><h2>Order #12</h2><div class="ob-order-tools"><button class="ob-edit-items" data-edit-items="/orders/f-12/items">${labels.edit_items}</button></div><strong data-card-total>242.50</strong></article></main>${editor}<script type="application/json" id="ob-translations">${JSON.stringify(labels).replaceAll('<', '\\u003c')}</script><script>window.fixtureReloads=0;window.reloadOrderSections=function(){window.fixtureReloads++};</script></body></html>`;
}

(async () => {
    fs.mkdirSync('test-results/order-board-items', {recursive: true});
    const options = {headless: true};
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) options.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch(options), page = await browser.newPage({viewport: {width: 1366, height: 768}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let locale = 'ar', data = initial(), posts = [], gets = 0, responseMode = 'success', release;
    let confirmation = true;
    page.on('dialog', dialog => confirmation ? dialog.accept() : dialog.dismiss());
    await page.route('http://localhost/**', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname === '/fixtures/fish.svg') return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"><rect width="320" height="180" fill="#edf0f5"/><ellipse cx="155" cy="90" rx="75" ry="35" fill="#bda680"/><path d="M220 90l50-35v70z" fill="#937853"/><circle cx="108" cy="82" r="6" fill="#17283a"/></svg>'});
        if (url.pathname === '/orders/f-12/items') {
            if (request.method() === 'GET') { gets++; return route.fulfill({json: data}); }
            assert.equal(request.method(), 'POST');
            assert.equal(request.headers()['x-csrf-token'], 'fixture-csrf');
            assert.match(request.headers()['content-type'], /application\/json/);
            const body = request.postDataJSON(); posts.push(body);
            if (responseMode === 'hold') await new Promise(resolve => { release = resolve; });
            if (responseMode === 'conflict') return route.fulfill({status: 409, json: {success: false, message: 'conflict'}});
            if (responseMode === 'lost') return route.abort('failed');
            if (responseMode === 'invalid') return route.fulfill({status: 422, json: {success: false, errors: {'items.0.quantity': ['وزن غير صالح']}}});
            return route.fulfill({json: {success: true, card: {key: 'f:12', html: '<article class="ob-card" data-order-key="f:12"><strong data-card-total>180.00</strong></article>'}}});
        }
        return route.fulfill({contentType: 'text/html', body: fixture(locale)});
    });
    const editor = page.locator('#ob-items-editor'), quantities = editor.locator('[data-line-quantity]');
    const save = editor.locator('[data-items-save]'), subtotal = editor.locator('[data-items-subtotal]');
    async function open(nextLocale = 'ar', viewport = {width: 1366, height: 768}, fixtureData = initial()) {
        locale = nextLocale; data = fixtureData; responseMode = 'success'; confirmation = true; release = null;
        await page.setViewportSize(viewport);
        await page.goto('http://localhost/order-items-fixture');
        await page.addScriptTag({content: source});
        await page.locator('[data-edit-items]').click();
        await editor.locator('[data-items-content]').waitFor({state: 'visible'});
        await page.waitForFunction(() => !document.querySelector('[data-items-message]').textContent);
    }
    async function waitMessage(text) { await page.waitForFunction(value => document.querySelector('[data-items-message]').textContent === value, text); }
    async function assertNoOverflow() {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'No document horizontal overflow.');
        assert.equal(await editor.evaluate(node => node.scrollWidth > node.clientWidth + 1), false, 'No editor horizontal overflow.');
        assert.equal(await editor.locator('.tp-workspace').evaluate(node => node.scrollWidth > node.clientWidth + 1), false, 'No editor workspace horizontal overflow.');
        assert.equal(await editor.locator('.tp-invoice-scroll').evaluate(node => node.querySelector('table').getBoundingClientRect().bottom <= node.querySelector('.tp-totals').getBoundingClientRect().top + 1), true, 'Invoice rows finish before the totals; visible table overflow cannot overlap totals.');
        assert.equal(await editor.evaluate(node => { const box = node.getBoundingClientRect(); return box.width >= innerWidth - 33 && box.height >= innerHeight - 33 && box.left >= -1 && box.top >= -1 && box.right <= innerWidth + 1 && box.bottom <= innerHeight + 1; }), true, 'The POS fills the viewport.');
        assert.equal(await editor.locator('input,select,button').evaluateAll(nodes => nodes.some(node => {
            if (node.hidden || !node.getClientRects().length) return false;
            const control = node.getBoundingClientRect(), dialog = node.closest('dialog').getBoundingClientRect();
            return control.left < dialog.left - 1 || control.right > dialog.right + 1;
        })), false, 'Controls fit inside the editor.');
    }
    async function keypad(value, mode = 'weight') {
        await editor.locator(`[data-items-unit="${mode}"]`).click();
        await editor.locator('[data-items-key="clear"]').click();
        for (const key of value) await editor.locator(`[data-items-key="${key}"]`).click();
        assert.equal(await editor.locator('[data-items-quantity] bdi').innerText(), value);
        await editor.locator('[data-items-key="confirm"]').click();
    }
    try {
        // Real labels, exact decimal weights, explicit original line totals, and safe text rendering.
        await open();
        assert.equal(await editor.locator('[data-items-number]').innerText(), '#20261014-0012');
        assert.equal(await subtotal.innerText(), '242.50');
        assert.equal(await save.isDisabled(), true);
        assert.equal(await quantities.first().inputValue(), '1.000');
        assert.equal(await editor.locator('img[src="x"]').count(), 0);
        assert.equal(await editor.locator('.tp-line-name').first().innerText(), unsafeName);
        assert.equal(await editor.locator('select').count(), 0, 'The editor uses the takeaway product grid, without dropdown selection.');
        assert.equal(await editor.locator('[data-items-product-id]').count(), 4);
        assert.equal(await editor.locator('[data-items-product-id="104"]').isDisabled(), true, 'Unavailable products cannot be added.');
        assert.equal(await editor.locator('[data-items-product-id="101"]').isDisabled(), false, 'The first available product can be added immediately after loading.');
        assert.equal(await editor.locator('[data-items-products] .tp-product-image').count(), 4);
        await page.waitForFunction(() => [...document.querySelectorAll('[data-items-products] img')].some(image => image.complete && image.naturalWidth > 0));
        assert.equal(await editor.locator('[data-items-category]').count(), 4);
        await editor.locator('[data-items-category="1"]').click();
        assert.deepEqual(await editor.locator('[data-items-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.itemsProductId)), ['101']);
        await editor.locator('[data-items-category="3"]').click();
        assert.deepEqual(await editor.locator('[data-items-product-id]').evaluateAll(nodes => nodes.map(node => node.dataset.itemsProductId)), ['103']);
        await editor.locator('[data-items-category=""]').click();
        assert.equal(await editor.locator('[data-items-product-id]').count(), 4);
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '292.50');
        assert.equal(await page.evaluate(() => window.fixtureXss), undefined);
        await quantities.first().fill('0.850');
        assert.equal(await subtotal.innerText(), '225.00', 'Changing one row preserves the other row\'s explicit total.');
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '275.00');
        assert.equal(await save.isDisabled(), false);
        await quantities.nth(1).fill('1.5');
        assert.equal(await save.isDisabled(), true, 'Piece products reject fractional quantities.');
        await quantities.nth(1).fill('2');
        assert.equal(await subtotal.innerText(), '225.00');
        await editor.locator('[data-line-remove]').nth(1).click();
        assert.equal(await quantities.count(), 1);
        assert.equal(await subtotal.innerText(), '170.00');
        await editor.locator('[data-items-search]').fill('سردين');
        assert.equal(await editor.locator('[data-items-product-id]').count(), 1);
        await keypad('0.250');
        await editor.locator('[data-items-product-id="103"]').click();
        assert.equal(await quantities.nth(1).inputValue(), '0.250');
        assert.equal(await subtotal.innerText(), '180.00');
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '230.00');
        await assertNoOverflow();
        await page.screenshot({path: 'test-results/order-board-items/editor-desktop-ar.png', fullPage: true});
        const beforeSave = posts.length;
        responseMode = 'hold'; await save.click();
        await page.waitForFunction(() => document.querySelector('[data-items-save]').disabled && document.querySelector('[data-items-save]').textContent.includes('الحفظ'));
        assert.equal(await quantities.first().isDisabled(), true);
        assert.equal(await editor.locator('[data-items-close]').first().isDisabled(), true, 'A pending save cannot be dismissed.');
        assert(release, 'The save request is pending.');
        responseMode = 'success'; release();
        await editor.waitFor({state: 'hidden'});
        assert.equal(posts.length, beforeSave + 1);
        assert.deepEqual(posts.at(-1), {expected_revision: revision, items: [
            {line_id: 11, product_id: 101, option_id: '', feature_id: 0, product_clean: null, quantity: '0.850'},
            {line_id: null, product_id: 103, option_id: '', feature_id: 0, product_clean: null, quantity: '0.250'}
        ]});
        assert.equal(await page.locator('[data-card-total]').innerText(), '180.00');
        assert.equal(await page.evaluate(() => window.fixtureReloads), 1);

        // A stored half/quarter is shown as actual kilograms and converted back on save.
        for (const [factor, feature, label, unitPrice, originalTotal, sentQuantity] of [
            ['0.500', 9, 'نصف', '100.00', '90.00', '1.700'],
            ['0.250', 10, 'ربع', '50.00', '45.00', '3.400']
        ]) {
            const legacy = initial(), item = legacy.order.items[0];
            Object.assign(item, {option_id: `f:${feature}:base`, feature_id: feature, option_label: label,
                weight_per_unit: factor, unit_price: unitPrice, line_total: originalTotal});
            await open('ar', {width: 1366, height: 768}, legacy);
            assert.equal(await quantities.first().inputValue(), factor, 'Selected size opens as its actual weight in kg.');
            assert.equal(await quantities.first().getAttribute('aria-label'), 'الوزن الفعلي: ' + unsafeName);
            assert.equal(await editor.locator('[data-items-lines] .tp-line-unit').first().innerText(), 'كجم');
            assert.equal(await editor.locator('[data-items-lines] .tp-line-unit').nth(1).innerText(), 'قطعة');
            assert.equal(await subtotal.innerText(), (Number(originalTotal) + 55).toFixed(2));
            await quantities.first().fill('0.850');
            assert.equal(await subtotal.innerText(), '225.00', 'Actual weight uses the stored selected-size price.');
            if (factor === '0.500') {
                await page.screenshot({path: 'test-results/order-board-items/editor-half-actual-weight.png', fullPage: true});
            }
            await save.click(); await editor.waitFor({state: 'hidden'});
            assert.deepEqual(posts.at(-1).items[0], {line_id: 11, product_id: 101, option_id: `f:${feature}:base`,
                feature_id: feature, product_clean: null, quantity: sentQuantity});
            assert.equal(posts.at(-1).items[1].feature_id, 0, 'Unchanged base lines retain feature zero.');
            assert.equal(posts.at(-1).items[1].quantity, '2');
        }

        // The purple takeaway keypad supplies actual kg for newly added half/quarter options.
        for (const [optionId, feature, factor, price, nativeQuantity] of [
            ['feature:9', 9, '0.500', '60.00', '1.700'], ['feature:10', 10, '0.250', '30.00', '3.400']
        ]) {
            const weighted = initial();
            if (feature === 10) weighted.catalog[2].options.push({id: optionId, feature_id: feature, product_clean: null, label: 'ربع', price, weight_per_unit: factor});
            await open('ar', {width: 1366, height: 768}, weighted);
            assert.equal(await editor.locator('[data-items-key="."]').isDisabled(), true, 'The piece keypad does not accept fractional pieces.');
            await keypad('0.850');
            await editor.locator('[data-items-options="103"]').click();
            await editor.locator(`[data-items-variant="${optionId}"]`).click();
            assert.equal(await quantities.last().inputValue(), '0.850');
            assert.equal(await editor.locator('.tp-line-total').last().innerText(), '102.00');
            assert.equal(await subtotal.innerText(), '344.50');
            assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '394.50');
            await save.click(); await editor.waitFor({state: 'hidden'});
            assert.deepEqual(posts.at(-1).items.at(-1), {line_id: null, product_id: 103, option_id: optionId,
                feature_id: feature, product_clean: null, quantity: nativeQuantity});
        }

        // Preserve recorded legacy charges until an edit; preview the same branch rates after editing.
        const legacyPricing = initial();
        Object.assign(legacyPricing.order, {tax: '25.00', service: '8.00', total: '325.50', pricing: {delivery: '50.00', tax_rate: '10.00', service_rate: '5.00'}});
        await open('ar', {width: 1366, height: 768}, legacyPricing);
        assert.equal(await editor.locator('[data-items-total="tax"]').innerText(), '25.00');
        assert.equal(await editor.locator('[data-items-total="service"]').innerText(), '8.00');
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '325.50');
        await quantities.first().fill('0.850');
        assert.equal(await editor.locator('[data-items-total="tax"]').innerText(), '22.50');
        assert.equal(await editor.locator('[data-items-total="service"]').innerText(), '11.25');
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '308.75');

        // GO store API rates are zero: editing only adds the recorded delivery fee to the items.
        const storePricing = initial(); storePricing.order.key = 'store:12'; storePricing.branch = {value: 'gs:100', name: 'متجر جو'};
        await open('ar', {width: 1366, height: 768}, storePricing); await quantities.first().fill('0.850');
        assert.equal(await editor.locator('[data-items-total="tax"]').innerText(), '0.00');
        assert.equal(await editor.locator('[data-items-total="service"]').innerText(), '0.00');
        assert.equal(await editor.locator('[data-items-total="grand"]').innerText(), '275.00');

        // Cancellation asks before discarding dirty edits; dismissing keeps changes in the dialog.
        await open(); await quantities.first().fill('0.850'); confirmation = false;
        const beforeCancel = posts.length;
        await editor.locator('[data-items-close]').last().click();
        assert.equal(await editor.isVisible(), true); assert.equal(await quantities.first().inputValue(), '0.850');
        confirmation = true; await editor.locator('[data-items-close]').last().click();
        await editor.waitFor({state: 'hidden'}); assert.equal(posts.length, beforeCancel);
        assert.equal(await page.locator('[data-edit-items]').evaluate(node => document.activeElement === node), true);

        // Empty orders and invalid decimal weights cannot be saved.
        await open(); await quantities.first().fill('0'); assert.equal(await save.isDisabled(), true);
        await quantities.first().fill('0.0001'); assert.equal(await save.isDisabled(), true);
        await quantities.first().fill('0.850'); assert.equal(await save.isDisabled(), false);
        await editor.locator('[data-line-remove]').nth(1).click(); await editor.locator('[data-line-remove]').first().click();
        assert.equal(await save.isDisabled(), true);
        await editor.locator('[data-items-search]').fill('لا يوجد هذا الصنف');
        assert.equal(await editor.locator('[data-items-no-products]').isVisible(), true);
        assert.equal(await editor.locator('[data-items-product-id]').count(), 0);

        // A conflicting order or a lost response requires a fresh read before another save.
        for (const mode of ['conflict', 'lost']) {
            await open(); await quantities.first().fill('0.850'); responseMode = mode; await save.click();
            await waitMessage(translations('ar')[mode === 'conflict' ? 'items_stale' : 'items_uncertain']);
            assert.equal(await editor.locator('[data-items-reload]').isVisible(), true);
            assert.equal(await save.isDisabled(), true);
            const beforeReload = gets, beforeRetry = posts.length;
            assert.equal(await quantities.first().isDisabled(), true, 'A stale or uncertain order is read-only until reloaded.');
            assert.equal(await save.isDisabled(), true, 'The reload requirement cannot be bypassed.');
            data.order.revision = 'c'.repeat(64); data.order.items[0].quantity = '0.850'; data.order.items[0].line_total = '170.00';
            responseMode = 'success'; await editor.locator('[data-items-reload]').click();
            await page.waitForFunction(() => document.querySelector('[data-line-quantity]').value === '0.850' && document.querySelector('[data-items-message]').hidden);
            assert.equal(gets, beforeReload + 1); assert.equal(posts.length, beforeRetry);
            await quantities.first().fill('0.950'); await save.click(); await editor.waitFor({state: 'hidden'});
            assert.equal(posts.at(-1).expected_revision, 'c'.repeat(64));
        }

        // Validation errors keep edits available for correction without requiring a reload.
        await open(); await quantities.first().fill('0.850'); responseMode = 'invalid'; await save.click();
        await waitMessage('وزن غير صالح'); assert.equal(await save.isDisabled(), false);
        assert.equal(await editor.locator('[data-items-reload]').isVisible(), false);

        // Variants carry their stable identifiers, and the real layout fits Arabic and English.
        for (const [language, width, height] of [['ar', 1366, 768], ['ar', 1024, 768], ['ar', 768, 1024], ['ar', 390, 844], ['ar', 320, 568], ['en', 1366, 768], ['en', 390, 844]]) {
            await open(language, {width, height});
            await assertNoOverflow();
            if (width < 600) await page.screenshot({path: `test-results/order-board-items/editor-${width}-${language}-catalog.png`, fullPage: true});
            await editor.locator('[data-items-options="103"]').click();
            assert.equal(await editor.locator('[data-items-option-modal]').isVisible(), true);
            assert.equal(await editor.locator('[data-items-variant]').count(), 2);
            await editor.locator('[data-items-variant="feature:9"]').click();
            assert.equal(await editor.locator('[data-items-option-modal]').isVisible(), false);
            assert.equal(await editor.locator('.tp-line-name').last().innerText(), 'سردين بلدي');
            assert.equal(await quantities.last().inputValue(), '0.500', 'New half-size products start at 0.500 kg.');
            assert.equal(await editor.locator('.tp-line-total').last().innerText(), '60.00');
            await quantities.last().fill('0.850');
            assert.equal(await editor.locator('.tp-line-total').last().innerText(), '102.00');
            await assertNoOverflow();
            await page.screenshot({path: `test-results/order-board-items/editor-${width}-${language}.png`, fullPage: true});
            await save.click(); await editor.waitFor({state: 'hidden'});
            assert.deepEqual(posts.at(-1).items.at(-1), {line_id: null, product_id: 103, option_id: 'feature:9', feature_id: 9, product_clean: null, quantity: '1.700'});
            assert.equal(posts.at(-1).items[0].feature_id, 0);
        }
        assert.deepEqual(errors, []);
        console.log('Takeaway-style app-order editor: image grid, categories, search, keypad, actual half/quarter weights, invoice edits/totals, CSRF, cancellation, validation, stale/lost-response recovery, and Arabic/English desktop/tablet/mobile layout passed.');
    } finally {
        if (release) release();
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
