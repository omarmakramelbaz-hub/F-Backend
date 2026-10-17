'use strict';
// Production standalone application invoice Blade/JS; local API and printing fixtures only.
const assert = require('node:assert/strict'), fs = require('node:fs'), os = require('node:os'), path = require('node:path'), cp = require('node:child_process');
const { chromium } = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '../..'), temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'app-invoice-'));
const php = process.env.CRM_TEST_PHP || 'php', autoload = process.env.CRM_TEST_AUTOLOAD;
assert(autoload && fs.existsSync(autoload), 'CRM_TEST_AUTOLOAD must point to the isolated Laravel runtime.');
const script = fs.readFileSync(path.join(root, 'public/dashboard/js/order-board-detail.js'), 'utf8');
const renderer = `require getenv('CRM_TEST_AUTOLOAD');
$container=new \\Illuminate\\Container\\Container; \\Illuminate\\Container\\Container::setInstance($container);
$loader=new \\Illuminate\\Translation\\ArrayLoader; $loader->addMessages('ar','order_board',require getenv('QA_ROOT').'/resources/lang/ar/order_board.php');
$container->instance('translator',new \\Illuminate\\Translation\\Translator($loader,'ar'));
$container->instance('path.public',getenv('QA_ROOT').'/public');
$container->instance('session',new class {function token(){return 'fixture-token';}});
$container->instance('url',new class {function asset($path,$secure=null){return '/'.$path;}function route($name,$parameters=[],$absolute=true){return '/admin/applies-orders';}});
$container->instance('request',new class {function boolean($key){return false;}});
$fixture=json_decode(file_get_contents(getenv('QA_FIXTURE')),true);$card=$fixture['card'];$printing=$fixture['printing'];
$__env=new class {use \\Illuminate\\View\\Concerns\\ManagesLoops;};
$compiler=new \\Illuminate\\View\\Compilers\\BladeCompiler(new \\Illuminate\\Filesystem\\Filesystem,getenv('QA_CACHE'));
eval('?>'.$compiler->compileString(file_get_contents(getenv('QA_ROOT').'/resources/views/admin/orders/board_detail.blade.php')));`;
function initial(options = {}) {
    const factor = options.factor || '0.500', feature = factor === '1.000' ? 0 : factor === '0.500' ? 9 : 10;
    return { success: true, save_url: '/admin/order-board/legacy/12/items', catalog: [{ id: 101, price: '999.00' }], order: {
        key: 'legacy:12', number: '0012', revision: 'b'.repeat(64), subtotal: '145.00', delivery: '50.00', tax: '0.00', service: '0.00', total: '195.00',
        pricing: { delivery: '50.00', tax_rate: '0.00', service_rate: '0.00' }, items: [
            { line_id: options.zeroId ? 0 : 11, product_id: 101, option_id: feature ? `f:${feature}:extra_clean` : 'f:0:extra_clean', feature_id: feature, product_clean: 'extra_clean',
                name: '<img src=x onerror="window.fixtureXss=true"> فسيخ', quantity: options.nativeQuantity || '1.000', unit: 'kg', quantity_step: '0.001', weight_per_unit: factor, unit_price: '100.00', line_total: '90.00', option_label: 'نصف / تنظيف' },
            { line_id: 12, product_id: 102, option_id: '', feature_id: 0, product_clean: null, name: 'علبة رنجة', quantity: '2.000', unit: 'piece', quantity_step: '1', weight_per_unit: null, unit_price: '30.00', line_total: '55.00', option_label: 'عادي' }
        ]
    } };
}
function card(data, options = {}) {
    return { number: '0012', app_label: 'فسخانستا', store: 'المحلة', created_label: '17/10/2026 19:30', status_label: options.finished ? 'تم التسليم' : 'قيد التجهيز', customer: 'عميل اختبار', phone: '01012345678', address: 'عنوان الاختبار', payment_label: 'كاش', payment_failed: false,
        notes: '', action_note: '', actions: [], revision: 1, accepted_notify: 'yes', status: 'pending',
        items: data.order.items.map(line => ({ line_id: line.line_id, name: line.name, option_label: line.option_label, quantity: line.quantity, line_total: line.line_total,
            ...(line.unit === 'kg' ? { display_quantity: (Number(line.quantity) * Number(line.weight_per_unit || 1)).toFixed(3), quantity_unit: 'كجم' } : {}) })),
        totals: [{ label: 'قيمة الأصناف', amount: data.order.subtotal }, { label: 'التوصيل', amount: data.order.delivery }], total: data.order.total,
        urls: { action: '/admin/order-board/legacy/12/action', ...(!options.locked && !options.finished && !options.printing ? { items: data.save_url } : {}), ...(!options.locked && !options.finished || options.owner ? { print: '/admin/order-board/legacy/12/print' } : {}) }
    };
}
function render(data, options = {}) {
    const fixture = path.join(temporary, 'fixture.json'); fs.writeFileSync(fixture, JSON.stringify({ card: card(data, options), printing: !!options.printing }));
    return cp.execFileSync(php, ['-r', renderer], { encoding: 'utf8', env: { ...process.env, QA_ROOT: root, QA_FIXTURE: fixture, QA_CACHE: temporary } });
}
async function setup(browser, options = {}) {
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } }), errors = [], calls = [];
    let data = initial(options), mode = 'success', release;
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.addInitScript(() => { window.nativePrintCalls = 0; window.print = () => window.nativePrintCalls++; });
    await page.route('http://localhost/**', async route => {
        const request = route.request(), pathname = new URL(request.url()).pathname;
        calls.push({ method: request.method(), path: pathname, body: request.method() === 'POST' ? request.postDataJSON() : null });
        if (pathname === '/dashboard/js/order-board-detail.js') return route.fulfill({ contentType: 'text/javascript', body: script });
        if (pathname.endsWith('.png')) return route.fulfill({ status: 404, body: '' });
        if (pathname.endsWith('/items')) {
            if (request.method() === 'GET') return route.fulfill({ json: data });
            assert.equal(request.headers()['x-csrf-token'], 'fixture-token');
            if (mode === 'hold') await new Promise(resolve => { release = resolve; });
            if (mode === 'conflict') return route.fulfill({ status: 409, json: { success: false, message: 'stale' } });
            if (mode === 'invalid') return route.fulfill({ status: 422, json: { success: false, errors: { quantity: ['وزن غير صالح'] } } });
            if (mode === 'lost') return route.abort('failed');
            const body = request.postDataJSON();
            assert.equal(body.expected_revision, data.order.revision);
            for (const input of body.items) {
                const line = data.order.items.find(item => item.line_id === input.line_id);
                assert(line, 'Save retains the real persisted line identity.');
                assert.equal(input.product_id, line.product_id); assert.equal(input.option_id, line.option_id); assert.equal(input.feature_id, line.feature_id); assert.equal(input.product_clean, line.product_clean);
                if (Number(input.quantity) !== Number(line.quantity)) line.line_total = (Number(line.unit_price) * Number(input.quantity)).toFixed(2);
                line.quantity = input.quantity;
            }
            data.order.subtotal = data.order.items.reduce((sum, line) => sum + Number(line.line_total), 0).toFixed(2);
            data.order.total = (Number(data.order.subtotal) + 50).toFixed(2); data.order.revision = (data.order.revision[0] === 'b' ? 'c' : 'd').repeat(64);
            return route.fulfill({ json: { success: true } });
        }
        if (pathname.endsWith('/print')) return route.fulfill({ contentType: 'text/html', body: render(data, { ...options, locked: true, printing: true }) });
        return route.fulfill({ contentType: 'text/html', body: render(data, options) });
    });
    await page.goto('http://localhost/admin/order-board/legacy/12');
    if (!options.locked && !options.finished) await page.waitForFunction(() => document.querySelectorAll('[data-app-line-quantity]').length === 2 && !document.querySelector('[data-app-print]').disabled);
    return { page, errors, calls, get data() { return data; }, setMode(value) { mode = value; }, release() { mode = 'success'; assert(release); release(); } };
}
const posts = fixture => fixture.calls.filter(call => call.method === 'POST'), prints = fixture => fixture.calls.filter(call => call.path.endsWith('/print'));
(async () => {
    const executablePath = process.env.CRM_TEST_CHROMIUM || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    const qa = process.env.APP_INVOICE_QA_SCREENSHOTS || path.join(temporary, 'screenshots'); fs.mkdirSync(qa, { recursive: true });
    try {
        const f = await setup(browser), page = f.page, quantities = page.locator('[data-app-line-quantity]');
        assert.equal(prints(f).length, 0, 'Viewing details never issues a print.'); assert.equal(await page.evaluate(() => window.nativePrintCalls), 0);
        assert.equal(await quantities.first().inputValue(), '0.500', 'A stored half is shown in actual kilograms.');
        assert.equal(await page.locator('select').count(), 0, 'Existing options remain immutable.'); assert.equal(await page.locator('img[src="x"]').count(), 0);
        assert.equal(await page.locator('[data-app-save]').isDisabled(), true);
        await quantities.first().fill('0.850');
        assert.equal(await page.locator('[data-app-line-total]').first().innerText(), '170.00', 'Weight uses the saved half-unit price, not current catalog price 999.');
        assert.equal(await page.locator('[data-app-total="subtotal"]').innerText(), '225.00', 'Unchanged manual line total is preserved.');
        assert.equal(await page.locator('[data-app-total="total"]').innerText(), '275.00');
        await page.screenshot({ path: path.join(qa, 'app-invoice-desktop.png'), fullPage: true });
        await quantities.nth(1).fill('1.5'); assert.equal(await page.locator('[data-app-save]').isDisabled(), true); assert.equal(await page.locator('[data-app-print]').isDisabled(), true);
        await quantities.nth(1).fill('2'); await page.locator('[data-app-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-app-message]').textContent.includes('تم حفظ'));
        assert.equal(posts(f).length, 1); assert.deepEqual(posts(f)[0].body.items, [
            { line_id: 11, product_id: 101, option_id: 'f:9:extra_clean', feature_id: 9, product_clean: 'extra_clean', quantity: '1.700' },
            { line_id: 12, product_id: 102, option_id: '', feature_id: 0, product_clean: null, quantity: '2.000' }
        ]);
        assert.equal(await quantities.first().inputValue(), '0.850'); assert.equal(await page.locator('[data-app-save]').isDisabled(), true); assert.equal(prints(f).length, 0);
        await quantities.first().fill('0.900'); f.setMode('hold'); await page.locator('[data-app-print]').click();
        await page.waitForFunction(() => document.querySelector('[data-app-message]').textContent.includes('جارٍ حفظ'));
        assert.equal(await quantities.first().isDisabled(), true); assert.equal(prints(f).length, 0, 'Print waits for the dirty invoice save.');
        await page.locator('[data-app-print]').dispatchEvent('click'); assert.equal(posts(f).length, 2, 'A pending save cannot be posted twice.');
        f.release(); await page.waitForURL('**/print');
        assert.equal(posts(f).length, 2); assert.equal(prints(f).length, 1); assert.equal(posts(f)[1].body.expected_revision, 'c'.repeat(64)); assert.equal(posts(f)[1].body.items[0].quantity, '1.800');
        assert.equal(await page.locator('[data-app-line-quantity]').count(), 0, 'The issued receipt is read only.'); assert.equal(await page.evaluate(() => window.nativePrintCalls), 1);
        assert.equal(await page.locator('[data-app-quantity] .number').first().innerText(), '0.900', 'Printed invoice shows actual kg while persisted half quantity stays 1.800.');
        assert.equal(await page.locator('[data-app-quantity] .app-line-unit').first().innerText(), 'كجم');
        assert.deepEqual(f.errors, []);
        for (const [factor, entered, native, amount] of [['1.000', ' ۰٫۸۵۰ ', '0.850', '85.00'], ['0.250', '0.850', '3.400', '340.00']]) {
            const variant = await setup(browser, { factor, zeroId: true });
            await variant.page.locator('[data-app-line-quantity]').first().fill(entered);
            assert.equal(await variant.page.locator('[data-app-line-total]').first().innerText(), amount);
            await variant.page.locator('[data-app-save]').click(); await variant.page.waitForFunction(() => document.querySelector('[data-app-message]').textContent.includes('تم حفظ'));
            assert.equal(posts(variant)[0].body.items[0].line_id, 0, 'Store snapshot index zero is retained.'); assert.equal(posts(variant)[0].body.items[0].quantity, native); assert.deepEqual(variant.errors, []);
            await variant.page.close();
        }
        for (const mode of ['invalid', 'conflict', 'lost']) {
            const failure = await setup(browser); failure.setMode(mode); await failure.page.locator('[data-app-line-quantity]').first().fill('0.850'); await failure.page.locator('[data-app-print]').click();
            await failure.page.waitForFunction(() => !document.querySelector('[data-app-message]').textContent.includes('جارٍ'));
            assert.equal(posts(failure).length, 1); assert.equal(prints(failure).length, 0, 'Failed or uncertain saving cannot print stale totals.');
            if (mode === 'invalid') { assert.equal(await failure.page.locator('[data-app-line-quantity]').first().isDisabled(), false); assert.equal(await failure.page.locator('[data-app-message]').innerText(), 'وزن غير صالح'); }
            else { assert.equal(await failure.page.locator('[data-app-line-quantity]').first().isDisabled(), true); assert.equal(await failure.page.locator('[data-app-reload]').isVisible(), true); assert.equal(await failure.page.locator('[data-app-print]').isDisabled(), true); }
            assert.deepEqual(failure.errors, []); await failure.page.close();
        }
        for (const options of [{ locked: true, nativeQuantity: '1.700' }, { finished: true }, { locked: true, finished: true, owner: true }]) {
            const readonly = await setup(browser, options);
            assert.equal(await readonly.page.locator('[data-app-save],[data-app-line-quantity]').count(), 0);
            assert.equal(await readonly.page.locator('[data-app-print]').count(), options.owner ? 1 : 0, 'Finished or already printed invoices hide branch printing; owner retains it.');
            assert.equal(readonly.calls.filter(call => call.path.endsWith('/items')).length, 0); assert.equal(prints(readonly).length, 0);
            if (options.nativeQuantity) { assert.equal(await readonly.page.locator('[data-app-quantity] .number').first().innerText(), '0.850'); assert.equal(await readonly.page.locator('[data-app-quantity] .app-line-unit').first().innerText(), 'كجم'); }
            if (options.owner) { await readonly.page.locator('[data-app-print]').click(); await readonly.page.waitForURL('**/print'); assert.equal(prints(readonly).length, 1); }
            assert.deepEqual(readonly.errors, []); await readonly.page.close();
        }
        const mobile = await setup(browser); await mobile.page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await mobile.page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Standalone invoice fits a phone viewport.');
        assert(await mobile.page.locator('[data-app-line-quantity]').first().evaluate(input => input.getBoundingClientRect().width >= 64), 'The full decimal weight fits the mobile quantity field.');
        await mobile.page.locator('[data-app-line-quantity]').first().fill(' ٠٫٨٥٠ ');
        assert.equal(await mobile.page.locator('[data-app-line-total]').first().innerText(), '170.00', 'Arabic digits and decimal separator normalize with surrounding whitespace.');
        await mobile.page.locator('[data-app-save]').click(); await mobile.page.waitForFunction(() => document.querySelector('[data-app-message]').textContent.includes('تم حفظ'));
        assert.equal(posts(mobile)[0].body.items[0].quantity, '1.700', 'Arabic input sends canonical English native quantity.');
        await mobile.page.screenshot({ path: path.join(qa, 'app-invoice-mobile.png'), fullPage: true });
        assert.deepEqual(mobile.errors, []); await mobile.page.close();
        console.log('App invoice QA passed: real Blade/JS, persisted prices/options/line IDs, actual weight, canonical save, save-before-print, conflict/uncertainty freeze, read-only issued/finished invoices and owner printing. QA=' + qa);
    } finally { await browser.close(); fs.rmSync(temporary, { recursive: true, force: true }); }
})().catch(error => { console.error(error); process.exit(1); });
