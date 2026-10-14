// Actual Blade/Laravel rendering and production POS JavaScript; browser-only API fixtures.
// ORDER_EDITOR_TEST_PHP=/path/to/php ORDER_BOARD_TEST_VENDOR=/path/to/vendor
// PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/to/chromium "$CODEX_PRIMARY_RUNTIME_NODE" tests/browser/order-board-items-loading.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES, 'playwright') : 'playwright');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/dashboard/js/order-board-items.js'), 'utf8');
const cache = fs.mkdtempSync(path.join(os.tmpdir(), 'order-editor-blade-'));
const php = process.env.ORDER_EDITOR_TEST_PHP || 'php';
const vendor = process.env.ORDER_BOARD_TEST_VENDOR;
assert(vendor, 'Set ORDER_BOARD_TEST_VENDOR to the isolated Laravel 8 vendor directory.');
const render = String.raw`
require $argv[3].'/autoload.php';
$root=$argv[1]; $labels=require $root.'/resources/lang/ar/order_board.php';
function __($key) { global $labels; return $labels[substr($key,strlen('order_board.'))] ?? $key; }
$fs=new \Illuminate\Filesystem\Filesystem();
$compiler=new \Illuminate\View\Compilers\BladeCompiler($fs,$argv[2]);
$resolver=new \Illuminate\View\Engines\EngineResolver();
$resolver->register('blade',fn()=>new \Illuminate\View\Engines\CompilerEngine($compiler));
$resolver->register('php',fn()=>new \Illuminate\View\Engines\PhpEngine($fs));
$factory=new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($fs,[$root.'/resources/views']),
    new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container()));
echo json_encode(['html'=>$factory->make('admin.orders.board_items')->render(),'labels'=>$labels],JSON_UNESCAPED_UNICODE);
`;
let compiled;
try { compiled = JSON.parse(execFileSync(php, ['-r', render, root, cache, vendor], { encoding: 'utf8' })); }
finally { fs.rmSync(cache, { recursive: true, force: true }); }
assert(!compiled.html.includes('@foreach') && !compiled.html.includes('{{'), 'Actual Blade directives compiled.');
const fixture = {
    success: true, save_url: '/orders/f-12/items',
    order: { key: 'legacy:12', number: 'normal-12', revision: 'b'.repeat(64),
        items: [{ line_id: 1, product_id: 101, name: 'فسيخ', quantity: '1.000', unit_price: '200.00', line_total: '200.00',
            unit: 'kg', quantity_step: '0.001', weight_per_unit: '1.000', option_id: '', feature_id: 0 }],
        pricing: { delivery: '50.00', tax_rate: '0.00', service_rate: '0.00' } },
    branch: { value: 'f:100', name: 'فرع الاختبار' }, categories: [{ id: 1, name: 'أسماك' }],
    catalog: [{ id: 101, product_id: 101, name: 'فسيخ', price: '200.00', category_id: 1, unit: 'kg',
        quantity_mode: 'weight', quantity_step: '0.001', weight_per_unit: '1.000', options: [] }],
};
const escaped = value => JSON.stringify(value).replaceAll('<', '\\u003c');
const css = ['takeaway-pos.css', 'order-board-items.css'].map(name => fs.readFileSync(path.join(root, 'public/dashboard/css', name), 'utf8')).join('\n');
const html = `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><style>${css}</style><body>
<main id="order-board"><article data-order-key="legacy:12"><button data-edit-items="/orders/f-12/items">تعديل</button></article></main>
${compiled.html}<script id="ob-translations" type="application/json">${escaped(compiled.labels)}</script>
<script>
window.fixtureData=${escaped(fixture)}; window.fixtureMode='normal'; window.fixtureCalls=[]; window.fixtureDeferred=[]; window.fixtureAborts=0;
const realFetch=window.fetch.bind(window);
function fixtureResponse(payload) { return new Response(JSON.stringify(payload),{status:200,headers:{'Content-Type':'application/json'}}); }
window.fetch=function(url,options) {
    if (!String(url).includes('/orders/f-12/items')) return realFetch(url,options);
    fixtureCalls.push({url:String(url),mode:fixtureMode});
    if(options?.signal) options.signal.addEventListener('abort',()=>fixtureAborts++);
    if(fixtureMode==='unexpected-abort') return Promise.reject(new DOMException('Unexpected transport cancellation','AbortError'));
    if(fixtureMode==='stalled-fetch') return new Promise(()=>{}); // Deliberately ignores abort.
    if(fixtureMode==='stalled-body') return Promise.resolve({ok:true,status:200,json:()=>new Promise(()=>{})});
    if(fixtureMode==='stale') return new Promise(resolve=>fixtureDeferred.push(resolve));
    if(fixtureMode==='stale-body') return Promise.resolve({ok:true,status:200,json:()=>new Promise(resolve=>fixtureDeferred.push(resolve))});
    return Promise.resolve(fixtureResponse(fixtureData));
};
window.reloadOrderSections=function(){};
</script></body></html>`;

(async () => {
    const launch = { headless: true };
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) launch.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch(launch);
    const errors = [];
    try {
        const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.request().url() === 'http://localhost/order-editor-loading' ?
            route.fulfill({ contentType: 'text/html', body: html }) : route.abort());
        const editor = page.locator('#ob-items-editor'), message = editor.locator('[data-items-message]');
        const reload = editor.locator('[data-items-reload]'), content = editor.locator('[data-items-content]');
        async function setup(mode = 'normal', missingSelector = false) {
            await page.goto('http://localhost/order-editor-loading');
            await page.clock.install();
            await page.evaluate(({mode,missingSelector}) => {
                fixtureMode=mode;
                if(missingSelector) document.querySelector('[data-items-total="grand"]').remove();
            }, { mode, missingSelector });
            await page.addScriptTag({ content: source });
            await page.locator('[data-edit-items]').click();
        }
        async function healthy() {
            await content.waitFor({ state: 'visible' });
            assert.equal(await message.isHidden(), true);
            assert.equal(await message.evaluate(node => node.classList.contains('is-loading')), false);
            assert.equal(await reload.isHidden(), true);
            assert.equal(await editor.locator('[data-items-number]').innerText(), '#normal-12');
            assert.equal(await editor.locator('[data-items-products] [data-items-product-id]').count(), 1);
            assert.equal(await editor.locator('[data-line-quantity]').inputValue(), '1.000');
            assert.equal(await editor.locator('[data-items-search]').isDisabled(), false);
        }
        async function retryable(label) {
            await reload.waitFor({ state: 'visible', timeout: 3000 });
            assert.equal(await message.evaluate(node => node.classList.contains('is-loading')), false, label + ': loading style cleared');
            assert.equal(await reload.isDisabled(), false, label + ': retry enabled');
            assert.equal(await editor.locator('[data-items-close]').first().isDisabled(), false, label + ': close enabled');
            assert.equal(await editor.locator('[data-items-save]').isDisabled(), true, label + ': no save before a successful read');
            assert.notEqual(await message.innerText(), compiled.labels.items_loading, label + ': loading text replaced');
        }
        async function retryNormal() {
            await page.evaluate(() => { fixtureMode='normal'; });
            await reload.click();
            await healthy();
        }
        await setup(); await healthy();
        await setup('unexpected-abort'); await retryable('Unexpected AbortError'); await retryNormal();
        for (const mode of ['stalled-fetch', 'stalled-body']) {
            await setup(mode);
            assert.equal(await message.evaluate(node => node.classList.contains('is-loading')), true);
            await page.clock.runFor(16000);
            await retryable(mode + ' after deadline');
            assert.equal(await page.evaluate(() => fixtureAborts), 1, mode + ': timed-out transport cancelled');
            await retryNormal();
        }
        for (const mode of ['stale', 'stale-body']) {
            await setup(mode);
            await page.waitForFunction(() => fixtureDeferred.length === 1);
            await editor.locator('[data-items-close]').first().click();
            assert.equal(await editor.isHidden(), true);
            assert.equal(await page.evaluate(() => fixtureAborts), 1, 'Close aborts its current read.');
            await page.evaluate(() => { fixtureMode='normal'; });
            await page.locator('[data-edit-items]').click(); await healthy();
            await page.evaluate(mode => {
                const stale=JSON.parse(JSON.stringify(fixtureData)); stale.order.number='STALE'; stale.order.items[0].name='STALE ITEM';
                fixtureDeferred.shift()(mode === 'stale-body' ? stale : fixtureResponse(stale));
            }, mode);
            await page.clock.runFor(16000);
            assert.equal(await editor.locator('[data-items-number]').innerText(), '#normal-12', 'Closed request cannot replace the reopened order.');
            assert.equal(await reload.isHidden(), true, 'Cancelled old deadline cannot surface an error on the reopened order.');
            assert.equal(await message.evaluate(node => node.classList.contains('is-loading')), false);
        }
        await setup('normal', true); await retryable('Missing rendering marker');
        assert.deepEqual(errors, [], 'Failures stay inside the editor rather than uncaught browser exceptions.');
        console.log('POS editor loading browser checks passed: actual Blade render, normal load, unexpected abort, stalled fetch/body deadlines and retry, cancelled stale reopen, missing render marker.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
