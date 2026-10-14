// Production board behavior: rejection keeps confirmation/errors, hides only its successful result notice.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const labels = Object.fromEntries([...read('resources/lang/ar/order_board.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(m => [m[1], m[2]]));
const stages = ['new', 'preparing', 'courier', 'completed'];
let orders, mode = 'success', actionRequests = [], dialogs = [], confirm = true;
function card(id, stage) {
    return `<article data-order-key="legacy:${id}" data-order-status="${stage}" class="ob-order"><strong>#${id}</strong>${stage === 'new' ? `<form data-order-action action="/actions/${id}" method="post"><input type="hidden" name="expected_status" value="pending"><button type="submit" name="action" value="accept">قبول</button><button type="submit" name="action" value="reject">رفض</button></form>` : ''}</article>`;
}
function columns() {
    return stages.map(stage => `<section data-column="${stage}"><div data-column-list="${stage}">${Object.entries(orders).filter(([, group]) => group === stage).map(([id]) => card(id, stage)).join('') || '<div class="ob-empty">لا توجد طلبات</div>'}</div><footer class="ob-column-footer" data-current-page="1"></footer></section>`).join('');
}
function fixture() {
    return `<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="fixture-csrf"><style>[hidden]{display:none!important}body{font-family:Arial}button{margin:6px}.ob-order{padding:15px;background:#eef2f7}.ob-column{padding:15px}</style><body><div class="order-board-wrapper"><main id="order-board" data-feed-url="/feed"><div id="ob-live"><span data-live-label></span></div>${stages.map(stage => `<b data-board-count="${stage}">${Object.values(orders).filter(value => value === stage).length}</b>`).join('')}<form id="ob-filters" action="/admin/applies-orders"><input name="search"><input name="date" type="date"><a href="/admin/applies-orders" data-board-reset>مسح الفلاتر</a>${stages.map(stage => `<input type="hidden" name="page_${stage}" value="1">`).join('')}</form><div id="ob-message" hidden><span data-message-text></span><button type="button" data-board-retry hidden>إعادة المحاولة</button></div><div id="all_orders">${columns()}</div></main></div><script type="application/json" id="ob-translations">${JSON.stringify(labels).replaceAll('<', '\\u003c')}</script><script>window.fixtureAcknowledged=[];window.fixturePending=new Set(['legacy:1','legacy:2']);window.DashboardOrderAlerts={observeApp(){},checkpoint(){return 0},reconcileApp(){},acknowledge(key){fixtureAcknowledged.push(key);fixturePending.delete(key)}};</script></body></html>`;
}
(async () => {
    const options = { headless: true };
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) options.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch(options);
    try {
        const page = await browser.newPage(), errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', async dialog => { dialogs.push({ type: dialog.type(), message: dialog.message() }); await (confirm ? dialog.accept() : dialog.dismiss()); });
        await page.route('http://localhost/**', async route => {
            const request = route.request(), url = new URL(request.url());
            if (url.pathname === '/feed') return route.fulfill({ json: { success: true, html: columns(), counts: Object.fromEntries(stages.map(stage => [stage, Object.values(orders).filter(value => value === stage).length])) } });
            if (url.pathname.startsWith('/actions/')) {
                assert.equal(request.method(), 'POST'); assert.equal(request.headers()['x-csrf-token'], 'fixture-csrf');
                const action = request.postData().match(/name="action"\r?\n\r?\n([^\r\n]+)/)?.[1], id = url.pathname.split('/').at(-1);
                assert(['accept', 'reject'].includes(action)); actionRequests.push({ id, action });
                if (mode === 'error') return route.fulfill({ status: 422, json: { success: false, message: 'تعذر رفض الطلب' } });
                orders[id] = action === 'reject' ? 'completed' : 'preparing';
                return route.fulfill({ json: { success: true, message: 'تم تحديث الطلب.', card: { key: 'legacy:' + id, from_group: 'new', group: orders[id], html: card(id, orders[id]) } } });
            }
            return route.fulfill({ contentType: 'text/html', body: fixture() });
        });
        async function open() {
            orders = { 1: 'new', 2: 'new' }; mode = 'success'; actionRequests = []; dialogs = []; confirm = true;
            await page.goto('http://localhost/admin/applies-orders'); await page.addScriptTag({ content: read('public/dashboard/js/order-board.js') }); assert.deepEqual(errors, [], 'production board initializes in the complete fixture');
        }
        const reject = () => page.locator('[data-order-key="legacy:1"] button[value="reject"]');
        await open(); confirm = false; await reject().click();
        assert.deepEqual(dialogs, [{ type: 'confirm', message: labels.confirm_reject }]); assert.equal(actionRequests.length, 0); assert.deepEqual(await page.evaluate(() => fixtureAcknowledged), []);
        confirm = true; await reject().click();
        await page.waitForFunction(() => fixtureAcknowledged.includes('legacy:1'));
        assert.equal(await page.locator('#ob-message').isHidden(), true, 'Successful rejection does not leave a dashboard success notice');
        assert.equal(await page.locator('[data-column="completed"] [data-order-key="legacy:1"]').count(), 1, 'Confirmed rejection still moves the card');
        assert.deepEqual(await page.evaluate(() => Array.from(fixturePending)), ['legacy:2'], 'Only rejected order sound is acknowledged');
        assert.deepEqual(actionRequests, [{ id: '1', action: 'reject' }]);
        await open(); mode = 'error'; await reject().click();
        await page.waitForFunction(() => document.querySelector('[data-message-text]').textContent === 'تعذر رفض الطلب');
        assert.equal(await page.locator('#ob-message').isVisible(), true, 'Rejection errors remain visible');
        assert.deepEqual(await page.evaluate(() => fixtureAcknowledged), []); assert.equal(await page.locator('[data-column="new"] [data-order-key="legacy:1"]').count(), 1);
        await open(); await page.locator('[data-order-key="legacy:1"] button[value="accept"]').click();
        await page.waitForFunction(() => fixtureAcknowledged.includes('legacy:1'));
        assert.equal(await page.locator('#ob-message').isVisible(), true, 'Normal accepted-order success notice remains');
        assert.equal(await page.locator('[data-message-text]').innerText(), 'تم تحديث الطلب.'); assert.equal(dialogs.length, 0);
        assert.deepEqual(errors, []);
        console.log('Board rejection browser checks passed: native confirmation/cancel, hidden rejection success notice, visible rejection failure, normal acceptance success, card transition and specific sound acknowledgement.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
