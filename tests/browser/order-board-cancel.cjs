// Actual Laravel Blade card/detail rendering and production board JS cancellation flow.
// ORDER_EDITOR_TEST_PHP=/path/to/php ORDER_BOARD_TEST_VENDOR=/path/to/vendor
// PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/to/chromium "$CODEX_PRIMARY_RUNTIME_NODE" tests/browser/order-board-cancel.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
    ? path.join(process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES, 'playwright') : 'playwright');
const root = path.resolve(__dirname, '../..');
const php = process.env.ORDER_EDITOR_TEST_PHP || 'php', vendor = process.env.ORDER_BOARD_TEST_VENDOR;
assert(vendor, 'Set ORDER_BOARD_TEST_VENDOR to isolated Laravel 8 vendor.');
const cache = fs.mkdtempSync(path.join(os.tmpdir(), 'board-cancel-blade-'));
const card = {
    id: 12, source: 'legacy', key: 'legacy:12', number: '#12', app: 'fasakhansta', app_label: 'فسخانستا',
    status: 'accepted', status_label: 'قيد التجهيز', group: 'preparing', elapsed: 'الآن', created_label: '15/10/2026 01:00',
    store: 'فرع الاختبار', customer: 'عميل', phone: '01000000000', address: 'عنوان', payment_label: 'كاش',
    accepted_notify: 'yes', revision: 1, total: '150.00', totals: [], notes: '',
    items: [{ name: 'فسيخ نبروه سوبر ممتاز قطعة واحدة مخصوص للتوصيل للمنزل اسم طويل للتجربة', option_label: 'نص / مخلي', quantity: '0.880', line_total: '150.00' }],
    actions: ['prepare', 'dispatch', 'cancel'], action_labels: { prepare: 'مندوب الفرع', dispatch: 'طلب مندوب' },
    urls: { details: '/details/12', print: '/print/12', action: '/actions/12' },
};
const compile = String.raw`
require $argv[3].'/autoload.php';
class FixtureContainer extends \Illuminate\Container\Container { public function getLocale() { return 'ar'; } }
$app=new FixtureContainer(); \Illuminate\Container\Container::setInstance($app);
function app() { return \Illuminate\Container\Container::getInstance(); }
function csrf_field() { return '<input type="hidden" name="_token" value="fixture-csrf">'; }
function asset($path) { return '/'.$path; }
function route($name) { return '/admin/applies-orders'; }
function request() { return \Illuminate\Http\Request::create('/print','GET',['dashboard_print'=>1]); }
$root=$argv[1]; $labels=require $root.'/resources/lang/ar/order_board.php';
function __($key) { global $labels; return $labels[substr($key,strlen('order_board.'))] ?? $key; }
$card=json_decode($argv[4],true); $files=new \Illuminate\Filesystem\Filesystem();
$compiler=new \Illuminate\View\Compilers\BladeCompiler($files,$argv[2]);
$resolver=new \Illuminate\View\Engines\EngineResolver();
$resolver->register('blade',fn()=>new \Illuminate\View\Engines\CompilerEngine($compiler));
$factory=new \Illuminate\View\Factory($resolver,new \Illuminate\View\FileViewFinder($files,[$root.'/resources/views']),new \Illuminate\Events\Dispatcher($app));
$initial=$factory->make('admin.orders.board_card',['card'=>$card])->render();
$detail=$factory->make('admin.orders.board_detail',['card'=>$card,'printing'=>false])->render();
$card['status']='cancelled'; $card['status_label']='ملغي'; $card['group']='completed'; $card['actions']=[];
echo json_encode(['labels'=>$labels,'initial'=>$initial,'completed'=>$factory->make('admin.orders.board_card',['card'=>$card])->render(),'detail'=>$detail],JSON_UNESCAPED_UNICODE);
`;
let compiled;
try { compiled = JSON.parse(execFileSync(php, ['-r', compile, root, cache, vendor, JSON.stringify(card)], { encoding: 'utf8' })); }
finally { fs.rmSync(cache, { recursive: true, force: true }); }
const stages = ['new', 'preparing', 'courier', 'completed'];
const boardCss = fs.readFileSync(path.join(root, 'public/dashboard/css/order-board.css'), 'utf8');
let stage = 'preparing', mode = 'success', confirmation = true, requests = [], dialogs = [], release;
function columns() {
    return stages.map(name => `<section class="ob-column" data-column="${name}"><div data-column-list="${name}">${name === stage ? compiled[stage === 'completed' ? 'completed' : 'initial'] : '<div class="ob-empty">لا توجد طلبات</div>'}</div><footer class="ob-column-footer" data-current-page="1"></footer></section>`).join('');
}
function pageFixture() {
    return `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="fixture-csrf"><style>${boardCss}[hidden]{display:none!important}</style><body><div class="order-board-wrapper"><main id="order-board" class="ob-board" data-feed-url="/feed"><div id="ob-live"><span data-live-label></span></div>${stages.map(name => `<b data-board-count="${name}">${name === stage ? 1 : 0}</b>`).join('')}<form id="ob-filters" action="/admin/applies-orders"><input name="search"><input name="date" type="date"><button type="button" data-board-reset>مسح</button>${stages.map(name => `<input type="hidden" name="page_${name}" value="1">`).join('')}</form><div id="ob-message" hidden><span data-message-text></span><button type="button" data-board-retry hidden>إعادة المحاولة</button></div><div id="all_orders" class="ob-columns">${columns()}</div></main></div><script type="application/json" id="ob-translations">${JSON.stringify(compiled.labels).replaceAll('<', '\\u003c')}</script><script>window.fixtureAcknowledged=[];window.fixturePending=new Set(['legacy:12','legacy:13']);window.DashboardOrderAlerts={observeApp(){},checkpoint(){return 0},reconcileApp(){},acknowledge(key){fixtureAcknowledged.push(key);fixturePending.delete(key)}};</script></body></html>`;
}

(async () => {
    const launch = { headless: true };
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) launch.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch(launch);
    try {
        const page = await browser.newPage(), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => { dialogs.push(dialog.message()); await (confirmation ? dialog.accept() : dialog.dismiss()); });
        await page.route('http://localhost/**', async route => {
            const req = route.request(), url = new URL(req.url());
            if (url.pathname === '/details/12') return route.fulfill({ contentType: 'text/html', body: compiled.detail });
            if (url.pathname === '/feed') return route.fulfill({ json: { success: true, html: columns(), counts: Object.fromEntries(stages.map(name => [name, name === stage ? 1 : 0])) } });
            if (url.pathname === '/actions/12') {
                assert.equal(req.method(), 'POST'); assert.equal(req.headers()['x-csrf-token'], 'fixture-csrf');
                const field = name => req.postData().match(new RegExp('name="' + name + '"\\r?\\n\\r?\\n([^\\r\\n]+)'))?.[1];
                requests.push({ action: field('action'), status: field('expected_status'), accepted: field('expected_accepted_notify'), revision: field('expected_revision') });
                if (mode === 'hold') await new Promise(resolve => { release = resolve; });
                if (mode === 'error') return route.fulfill({ status: 422, json: { success: false, message: 'تعذر إلغاء الطلب' } });
                stage = 'completed';
                return route.fulfill({ json: { success: true, message: 'تم إلغاء الطلب.', card: { key: 'legacy:12', from_group: 'preparing', group: 'completed', html: compiled.completed } } });
            }
            return route.fulfill({ contentType: 'text/html', body: pageFixture() });
        });
        async function open() {
            stage='preparing'; mode='success'; confirmation=true; requests=[]; dialogs=[]; release=null;
            await page.goto('http://localhost/admin/applies-orders');
            await page.addScriptTag({ content: fs.readFileSync(path.join(root, 'public/dashboard/js/order-board.js'), 'utf8') });
            assert.equal((await page.locator('[data-order-key="legacy:12"] .ob-item-option').innerText()).trim(), 'نص / مخلي', 'Actual Blade card includes preparation label.');
        }
        const cancel = () => page.locator('[data-order-key="legacy:12"] button[value="cancel"]');
        async function visiblePreparation() {
            for (const width of [1366,390]) {
                await page.setViewportSize({width,height:844});
                const visible=await page.locator('[data-order-key="legacy:12"] .ob-item-option').evaluate(el=>{
                    const option=el.getBoundingClientRect(), clip=el.parentElement.getBoundingClientRect();
                    return option.width > 0 && option.height > 0 && option.left >= clip.left - 1 && option.right <= clip.right + 1
                        && option.top >= clip.top - 1 && option.bottom <= clip.bottom + 1;
                });
                assert.equal(visible,true,`Preparation choices remain visibly inside the long-name item at ${width}px.`);
            }
        }
        await open(); await visiblePreparation(); confirmation=false; await cancel().click();
        assert.deepEqual(dialogs,[compiled.labels.confirm_cancel]);
        assert.deepEqual(requests,[], 'Dismissing native confirmation submits no cancellation.');
        assert.deepEqual(await page.evaluate(() => fixtureAcknowledged),[]);
        assert.equal(await page.locator('[data-column="preparing"] [data-order-key="legacy:12"]').count(),1);
        confirmation=true; mode='hold'; await cancel().click();
        await page.waitForFunction(() => document.querySelector('[data-order-key="legacy:12"]').classList.contains('is-busy'));
        assert.equal(await cancel().isDisabled(),true);
        await page.evaluate(() => { const form=document.querySelector('[data-order-action]'); form.dispatchEvent(new SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:form.querySelector('[value="cancel"]')})); });
        assert.equal(requests.length,1,'A pending cancellation cannot submit twice.');
        assert(release); mode='success'; release();
        await page.waitForFunction(() => fixtureAcknowledged.includes('legacy:12'));
        assert.deepEqual(requests,[{action:'cancel',status:'accepted',accepted:'yes',revision:'1'}]);
        assert.equal(await page.locator('[data-column="completed"] [data-order-key="legacy:12"]').count(),1);
        assert.equal(await page.locator('[data-column="preparing"] [data-order-key="legacy:12"]').count(),0);
        assert.deepEqual(await page.evaluate(() => Array.from(fixturePending)),['legacy:13'],'Only committed cancellation acknowledges its sound.');
        assert.equal(await page.locator('[data-board-count="preparing"]').innerText(),'0');
        assert.equal(await page.locator('[data-board-count="completed"]').innerText(),'1');

        await open(); mode='error'; await cancel().click();
        await page.waitForFunction(() => document.querySelector('[data-message-text]').textContent === 'تعذر إلغاء الطلب');
        assert.equal(await page.locator('#ob-message').isVisible(),true,'Cancellation errors remain visible.');
        assert.equal(await page.locator('#ob-message').evaluate(node=>node.classList.contains('is-success')),false);
        assert.deepEqual(await page.evaluate(() => fixtureAcknowledged),[],'Failed cancellation acknowledges no order.');
        assert.equal(await page.locator('[data-column="preparing"] [data-order-key="legacy:12"]').count(),1);
        assert.equal(await cancel().isDisabled(),false,'Failed cancellation restores controls.');

        await page.goto('http://localhost/details/12'); confirmation=false; dialogs=[]; const before=requests.length;
        assert.equal(await page.locator('.item-option').innerText(),'نص / مخلي','Actual details retains preparation label.');
        await page.locator('button.cancel').click();
        assert.deepEqual(dialogs,[compiled.labels.confirm_cancel],'Actual Blade details uses localized cancellation confirmation.');
        assert.equal(requests.length,before,'Dismissing detail confirmation submits nothing.');
        assert.deepEqual(errors,[]);
        console.log('Actual board cancellation browser passed: Blade card/details options and native confirmations, dismissed/no POST, pending duplicate guard, successful completed-card move/counts/specific acknowledgement, visible errors/no acknowledgement.');
    } finally { if (release) release(); await browser.close(); }
})().catch(error=>{ console.error(error); process.exitCode=1; });
