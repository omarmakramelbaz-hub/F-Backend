'use strict';
// Render the real Blade pages and production assets against isolated HTTP fixtures.
// Only catalog, tables and quote endpoints are available: QA cannot create live orders.
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const cp = require('child_process');
const { chromium } = require(path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.env.NODE_PATH || '', 'playwright'));
const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-preparation-picker-'));
const php = process.env.CRM_TEST_PHP || 'php';
assert(process.env.CRM_TEST_AUTOLOAD && fs.existsSync(process.env.CRM_TEST_AUTOLOAD), 'CRM_TEST_AUTOLOAD must point to isolated Laravel');
const qa = process.env.TAKEAWAY_QA_SCREENSHOTS;
if (qa) fs.mkdirSync(qa, { recursive: true });
const preparations = [
    { id: '', label: 'كامل', price: '400.00' },
    { id: 'f:0:extra_clean', label: 'تنظيف', price: '420.00', feature_id: 0, product_clean: 'extra_clean' },
    { id: 'f:0:extra_clear', label: 'مخلي', price: '450.00', feature_id: 0, product_clean: 'extra_clear' },
];
const portionOptions = [
    { id: 'f:1:base', label: 'ربع كيلو', price: '100.00' },
    { id: 'f:0:extra_clean', label: 'تنظيف', price: '420.00' },
    { id: 'f:0:extra_clear', label: 'مخلي', price: '450.00' },
    { id: 'f:0:extra_vacuum', label: 'فاكيوم', price: '460.00' },
];
function product(id, preparationOptions, available = true) {
    return { id, name: `فسيخ اختبار ${id}`, unit_price: '400.00', price: '400.00', quantity_mode: 'weight', unit: 'kg', available, preparation_options: preparationOptions, options: portionOptions };
}
const products = [product(1, preparations), product(2, preparations.slice(0, 2)), product(3, preparations.slice(0, 1)), product(4, preparations, false)];
const goProducts = [{ id: 1, name: 'منتج متجر Go', unit_price: '20.00', price: '20.00', quantity_mode: 'piece', unit: 'piece', available: true, options: [{ id: 'g:9', label: 'حجم كبير', price: '35.00' }] }];
const permissions = { can_checkout: true, can_operate: true, can_manage: true, can_manage_tables: true };
const policy = { can_discount: true, tax_rate: '0.00', service_rate: '0.00', settings_revision: 1, payment_methods: ['cash'] };
const render = String.raw`require getenv('CRM_TEST_AUTOLOAD');$c=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($c);$loader=new \Illuminate\Translation\ArrayLoader;$kind=getenv('QA_KIND');$labels=require getenv('QA_ROOT').'/resources/lang/ar/'.$kind.'.php';$loader->addMessages('ar',$kind,$labels);$c->instance('translator',new \Illuminate\Translation\Translator($loader,'ar'));$c->instance('url',new class {public function route($name,$parameters=[],$absolute=true){return 'https://pos.test/admin/takeaway';}});$boot=json_decode(file_get_contents(getenv('QA_BOOT')),true);if($kind==='takeaway')$pos=$boot;else $dining=$boot;$blade=file_get_contents(getenv('QA_ROOT').'/resources/views/admin/'.$kind.'/index.blade.php');$blade=explode("@section('content')",$blade,2)[1];$blade=explode('@endsection',$blade,2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,getenv('QA_CACHE'));$__env=new class {use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));echo json_encode(['content'=>ob_get_clean(),'labels'=>$labels],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);`;
const safe = value => JSON.stringify(value).replaceAll('<', '\\u003c');
function html(kind, boot) {
    const bootFile = path.join(tmp, `${kind}-boot.json`);
    fs.writeFileSync(bootFile, JSON.stringify(boot));
    const rendered = JSON.parse(cp.execFileSync(php, ['-r', render], { encoding: 'utf8', env: { ...process.env, QA_KIND: kind, QA_ROOT: root, QA_BOOT: bootFile, QA_CACHE: tmp } }));
    return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-token"><style>*{box-sizing:border-box}html,body{margin:0;font-family:Arial,sans-serif}.content-wrapper{margin:0}</style><style>${fs.readFileSync(path.join(root, `public/dashboard/css/${kind}-pos.css`), 'utf8')}</style></head><body class="dashboard-theme dashboard-${kind}-page">${rendered.content}<script type="application/json" id="${kind}-bootstrap">${safe(boot)}</script><script type="application/json" id="${kind}-translations">${safe(rendered.labels)}</script><script>${fs.readFileSync(path.join(root, `public/dashboard/js/${kind}-pos.js`), 'utf8')}</script></body></html>`;
}
async function setup(browser, kind, go = false) {
    const prefix = kind === 'takeaway' ? 'pos' : 'dining';
    const cls = kind === 'takeaway' ? 'tp' : 'dp';
    const branch = { id: 100, value: `${go ? 'g' : 'f'}:100`, name: 'فرع الاختبار' };
    const list = go ? goProducts : products;
    const urls = { catalog: `/admin/${kind}/catalog`, quote: `/admin/${kind}/quote`, tables: '/admin/dining/tables' };
    const boot = { branches: [branch], selected_branch: branch.value, cashier: { id: 1, name: 'كاشير الاختبار' }, permissions, policy, urls };
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });
    const errors = [], calls = [], outside = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => { window.DashboardPrint = { print: () => { throw Error('Printing forbidden in picker QA'); } }; window.DashboardInvoiceDetails = { open: () => {} }; });
    const rendered = html(kind, boot);
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin === 'https://pos.test' && url.pathname === `/admin/${kind}`) return route.fulfill({ contentType: 'text/html', body: rendered });
        if (url.origin !== 'https://pos.test') { outside.push(url.href); return route.abort(); }
        const payload = request.method() === 'POST' ? request.postDataJSON() : Object.fromEntries(url.searchParams);
        calls.push({ path: url.pathname, method: request.method(), payload });
        let result;
        if (url.pathname === urls.catalog) result = { success: true, ready: true, branch, items: list, categories: [], policy, permissions, pagination: { page: 1, last_page: 1, total: list.length }, today: { count: 0 } };
        else if (kind === 'dining' && url.pathname === urls.tables) result = { success: true, branch, policy, permissions, tables: [{ id: 1, name: '1', capacity: 6, active: true, status: 'free', ticket_id: null }] };
        else if (url.pathname === urls.quote) {
            assert.strictEqual(request.headers()['x-csrf-token'], 'fixture-token');
            let total = 0;
            const items = payload.items.map(item => {
                const product = list.find(value => value.id === item.product_id);
                const choices = product.preparation_options || [{ id: '', price: product.price }].concat(product.options || []);
                const option = choices.find(value => value.id === (item.option_id || ''));
                assert(option, `Unexpected submitted option ${item.option_id}`);
                const amount = Math.round(Number(item.quantity) * Number(option.price) * 100);
                total += amount;
                return { ...item, name: product.name, option_label: option.label || '', unit_price: option.price, total: (amount / 100).toFixed(2) };
            });
            result = { success: true, branch, items, quote_hash: 'a'.repeat(64), subtotal: (total / 100).toFixed(2), discount: '0.00', tax: '0.00', service: '0.00', tax_rate: '0.00', service_rate: '0.00', total: (total / 100).toFixed(2) };
        } else { outside.push(url.href); return route.abort(); }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(result) });
    });
    await page.goto(`https://pos.test/admin/${kind}`);
    await page.locator(`[data-${prefix}-product="1"]`).waitFor();
    if (kind === 'dining') {
        await page.locator('[data-dining-table="1"]').click();
        await page.locator('[data-dining-open-customer]').fill('عميل اختبار');
        await page.locator('[data-dining-open-customer]').press('Enter');
        await page.locator('[data-dining-modal]').waitFor({ state: 'hidden' });
    }
    return { page, prefix, cls, errors, calls, outside };
}
async function amount(ctx, value) {
    await ctx.page.locator(`[data-${ctx.prefix}-key="clear"]`).click();
    for (const key of value) await ctx.page.locator(`[data-${ctx.prefix}-key="${key}"]`).click();
}
const rows = ctx => ctx.page.locator(`[data-${ctx.prefix}-lines] tr`);
const modal = ctx => ctx.page.locator(`[data-${ctx.prefix}-modal]`);
const choices = ctx => ctx.page.locator(`.${ctx.cls}-option-list button`);
const card = (ctx, id) => ctx.page.locator(`[data-${ctx.prefix}-product="${id}"]`);
async function priced(ctx, itemCount) {
    await ctx.page.waitForFunction(({ prefix, itemCount }) => document.querySelectorAll(`[data-${prefix}-lines] tr`).length === itemCount && document.querySelector(`[data-${prefix}-total="total"]`).textContent !== '—', { prefix: ctx.prefix, itemCount });
}
async function testFasakhansta(browser, kind) {
    const ctx = await setup(browser, kind), { page, prefix, cls } = ctx;
    assert.strictEqual(await page.locator(`[data-${prefix}-options]`).count(), 0, 'cards must not render options footers');
    assert.strictEqual(await card(ctx, 1).getAttribute('aria-haspopup'), 'dialog');
    assert(await card(ctx, 4).isDisabled(), 'unavailable product remains disabled');
    await card(ctx, 4).evaluate(button => button.click());
    assert.strictEqual(await rows(ctx).count(), 0);
    assert(!await modal(ctx).isVisible());

    // An empty cart can open the picker by keyboard. Cancel retains the entered weight.
    await amount(ctx, '.25');
    await card(ctx, 1).focus();
    await page.keyboard.press('Enter');
    await modal(ctx).waitFor({ state: 'visible' });
    assert.strictEqual(await rows(ctx).count(), 0, 'opening a picker must not add the base product');
    assert.strictEqual(await choices(ctx).count(), 3);
    assert.deepStrictEqual(await choices(ctx).locator('span').allTextContents(), ['كامل', 'تنظيف', 'مخلي']);
    assert.deepStrictEqual(await choices(ctx).locator('bdi').allTextContents(), ['400.00 جنيه', '420.00 جنيه', '450.00 جنيه']);
    assert(await choices(ctx).first().evaluate(button => document.activeElement === button), 'first choice receives keyboard focus');
    assert(!/ربع|نصف|فاكيوم/.test(await modal(ctx).innerText()), 'portion and vacuum variants are excluded from the three preparations');
    await choices(ctx).last().focus();
    await page.keyboard.press('Tab');
    assert(await page.locator(`[data-${prefix}-modal-close]`).evaluate(button => document.activeElement === button), 'Tab stays inside the picker');
    if (qa) await page.screenshot({ path: path.join(qa, `${kind}-preparation-picker.png`), fullPage: true });
    await page.keyboard.press('Escape');
    assert(!await modal(ctx).isVisible());
    assert.strictEqual(await rows(ctx).count(), 0);
    assert.strictEqual(await page.locator(`[data-${prefix}-quantity] bdi`).innerText(), '0.25');
    assert(await card(ctx, 1).evaluate(button => document.activeElement === button), 'Escape returns focus to the product card');
    assert(!ctx.calls.some(call => call.path.endsWith('/quote')), 'cancel must not request a quote');

    // Repeated activation on the same choice must add exactly one quarter-kilo line.
    await card(ctx, 1).click();
    await choices(ctx).nth(1).evaluate(button => { button.click(); button.click(); });
    await priced(ctx, 1);
    assert(!await modal(ctx).isVisible());
    assert((await rows(ctx).first().innerText()).includes('420.00'));
    assert.strictEqual(await rows(ctx).first().locator(`.${cls}-line-total`).innerText(), '105.00');
    let lastQuote = ctx.calls.filter(call => call.path.endsWith('/quote')).at(-1).payload;
    assert.deepStrictEqual(lastQuote.items, [{ product_id: 1, quantity: '0.250', quantity_mode: 'weight', option_id: 'f:0:extra_clean' }]);

    // Base, cleaned and filleted preparations remain separately identifiable lines.
    for (const index of [0, 2]) {
        await amount(ctx, '.25');
        await card(ctx, 1).click();
        await choices(ctx).nth(index).click();
    }
    await priced(ctx, 3);
    assert.deepStrictEqual(await rows(ctx).locator(`.${cls}-line-total`).allTextContents(), ['105.00', '100.00', '112.50']);
    assert.deepStrictEqual(await rows(ctx).locator(`.${cls}-line-option`).allTextContents(), ['تنظيف', 'مخلي']);
    lastQuote = ctx.calls.filter(call => call.path.endsWith('/quote')).at(-1).payload;
    assert.deepStrictEqual(lastQuote.items.map(item => item.option_id || ''), ['f:0:extra_clean', '', 'f:0:extra_clear']);

    await amount(ctx, '1');
    await card(ctx, 2).click();
    assert.strictEqual(await choices(ctx).count(), 2, 'only configured preparations appear');
    assert.deepStrictEqual(await choices(ctx).locator('span').allTextContents(), ['كامل', 'تنظيف']);
    await page.locator(`[data-${prefix}-modal-close]`).click();
    assert.strictEqual(await rows(ctx).count(), 3);
    await card(ctx, 3).click();
    await priced(ctx, 4);
    assert(!await modal(ctx).isVisible(), 'a product with a single preparation adds directly');
    assert.strictEqual(await rows(ctx).last().locator(`.${cls}-line-total`).innerText(), '400.00');

    // Space still opens the chooser when the cart is populated; the modal fits mobile.
    await page.setViewportSize({ width: 390, height: 844 });
    await card(ctx, 1).focus();
    await page.keyboard.press('Space');
    await modal(ctx).waitFor({ state: 'visible' });
    assert.strictEqual(await choices(ctx).count(), 3);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'mobile picker has no horizontal overflow');
    assert(await choices(ctx).last().isVisible());
    if (qa) await page.screenshot({ path: path.join(qa, `${kind}-preparation-picker-mobile.png`), fullPage: true });
    await page.keyboard.press('Escape');
    assert.strictEqual(await rows(ctx).count(), 4);
    assert.deepStrictEqual(ctx.errors, []);
    assert.deepStrictEqual(ctx.outside, []);
    await page.close();
    console.log(`${kind}: three/two/single preparations, precise quarter-kilo pricing, separate lines, guarded double selection, cancellation, keyboard focus and mobile passed`);
}
async function testGoFallback(browser, kind) {
    const ctx = await setup(browser, kind, true);
    await card(ctx, 1).click();
    assert.strictEqual(await choices(ctx).count(), 2, 'Go retains its base and configured legacy variant');
    assert((await choices(ctx).last().innerText()).includes('حجم كبير'));
    await choices(ctx).last().click();
    await priced(ctx, 1);
    assert.strictEqual(await rows(ctx).first().locator(`.${ctx.cls}-line-total`).innerText(), '35.00');
    const lastQuote = ctx.calls.filter(call => call.path.endsWith('/quote')).at(-1).payload;
    assert.strictEqual(lastQuote.items[0].option_id, 'g:9');
    assert.deepStrictEqual(ctx.errors, []);
    assert.deepStrictEqual(ctx.outside, []);
    await ctx.page.close();
    console.log(`${kind}: Go legacy-option fallback passed`);
}
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CRM_TEST_CHROMIUM || undefined });
    try {
        for (const kind of ['takeaway', 'dining']) {
            await testFasakhansta(browser, kind);
            await testGoFallback(browser, kind);
        }
        console.log('POS preparation picker QA passed with real Blade/CSS/JS and no order writes.');
    } finally { await browser.close(); fs.rmSync(tmp, { recursive: true, force: true }); }
})().catch(error => { console.error(error); process.exit(1); });
