'use strict';

// Production home renderer in Chromium, real Owner Blade structure, synthetic
// financial data, and deferred responses. No production account or database.
const {chromium} = require(process.env.DESKTOP_TEST_BROWSER_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const labels = Object.fromEntries([...read('resources/lang/en/home_overview.php').matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)].map(match => [match[1], match[2]]));
const channels = ['takeaway', 'dine', 'phone', 'app'];
const branches = [{value: 'f:100', name: 'First branch'}, {value: 'f:101', name: 'Second branch'}];
const filters = {branch: '', period: 'today', from: '2026-10-27', to: '2026-10-27'};
const emptyDrawer = () => ({ready: false, loaded: false, total_cents: null, branches: {}});
const zeroChannels = () => Object.fromEntries(channels.map(key => [key, {gross_cents: 0, count: 0}]));
function overview(branch = '') {
    const selected = branch ? branches.filter(entry => entry.value === branch) : branches;
    return {success: true, can_view_financials: true, selected_branches: selected.length,
        filters: {...filters, branch}, operating_day: {date: '2026-10-27'}, updated_at: '2026-10-27T13:00:00Z', branches,
        owner_platform: {go_partners: 0, go_stores: 0, go_users: 2, fasakhansta_users: 10,
            fasakhansta_stores: 2, pending_total: 0, pending_stores: 0, pending_partners: 0},
        owner_drawer: emptyDrawer(), modules: {pos: true, app: true, expenses: true, inventory: true},
        sales: {gross_cents: 0, delivery_cents: 0, expenses_cents: 0, net_cents: 0, channels: zeroChannels()},
        expenses_cents: 0, completed: 0, channels: channels.map(key => ({key, amount_cents: 0, count: 0})),
        branch_cards: selected.map(entry => ({...entry, completed: 0, expenses_cents: 0,
            order_counts: Object.fromEntries(channels.map(key => [key, 0])), sales: {gross_cents: 0, channels: zeroChannels()}})),
        inventory: {tracked: 0, items: []}, ranking: selected.map((entry, index) => ({...entry, amount_cents: 0, count: 0, sales_rank: index + 1})),
        trend: [{label: '06:00', amount_cents: 0, count: 0}]};
}
function drawerResponse(branch = '', total = 11500) {
    return {success: true, filters: {...filters, branch}, updated_at: '2026-10-27T13:01:00Z',
        owner_drawer: {ready: true, loaded: true, total_cents: total,
            branches: branch ? {[branch]: {expected_cents: total}} : {'f:100': {expected_cents: 12500}, 'f:101': {expected_cents: -1000}}}};
}
function ownerHtml() {
    let partial = read('resources/views/admin/home_owner.blade.php').replace(/^@php[^\n]*\n/, '');
    partial = partial.replace(/@foreach\(\['takeaway','dine','phone','app','total'\] as \$channel\)([\s\S]*?)@endforeach/g,
        (_, body) => [...channels, 'total'].map(key => body.replace(/{{\s*__\('home_overview\.'\.\$channel\)\s*}}/g, labels[key])).join(''));
    partial = partial.replace("@if(isset($boot['initial']['owner_drawer']))", '').replace(/@endif/g, '');
    let main = read('resources/views/admin/home.blade.php').split("@section('content')")[1].split('@endsection')[0];
    main = main.replace("@include('admin.home_hud')", '');
    const open = main.indexOf("@if(isset($boot['initial']['owner_platform']))");
    const otherwise = main.indexOf('@else', open), close = main.indexOf('@endif', otherwise);
    assert(open >= 0 && otherwise > open && close > otherwise);
    main = main.slice(0, open) + partial + main.slice(close + 6);
    main = main.replace(/@foreach\(\$boot\['initial'\]\['branches'\] as \$branch\)([\s\S]*?)@endforeach/g,
        (_, body) => branches.map(branch => body.replace(/{{\s*\$branch\['value'\]\s*}}/g, branch.value).replace(/{{\s*\$branch\['name'\]\s*}}/g, branch.name)).join(''));
    main = main.replace(/@foreach\(\['today','week','month','custom'\] as \$period\)([\s\S]*?)@endforeach/g,
        (_, body) => ['today', 'week', 'month', 'custom'].map(period => body.replace(/{{\s*\$period\s*}}/g, period)
            .replace(/{{\s*__\('home_overview\.'\.\$period\)\s*}}/g, labels[period])).join(''));
    main = main.replace("@if(isset($boot['initial']['owner_platform']))", '')
        .replace("@if($boot['initial']['can_view_financials'] || !isset($boot['initial']['owner_platform']))", '').replace(/@endif/g, '');
    main = main.replace(/{{\s*isset\(\$boot\['initial'\]\['owner_platform'\]\)\?' ho-compact':''\s*}}/g, ' ho-compact')
        .replace(/{{\s*app\(\)->getLocale\(\)==='ar'\?'rtl':'ltr'\s*}}/g, 'ltr')
        .replace(/{{\s*\$financial\s*\?\s*''\s*:\s*' ho-admin-overview'\s*}}/g, '')
        .replace(/{{\s*__\([^}]*?'home_overview\.([^']+)'[^}]*\)\s*}}/g, (_, key) => labels[key] || key)
        .replace(/{{\s*route\('[^']+'\)\s*}}/g, '/admin/branch-stock');
    assert(!/@(?:if|else|foreach|endif|php|include)|{{/.test(main), 'Only Blade directives and translations are substituted; production Owner markup is used.');
    return main;
}

(async () => {
    const browser = await chromium.launch({headless: true,
        ...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE ? {executablePath: process.env.DESKTOP_TEST_BROWSER_EXECUTABLE} : {})});
    const errors = [];
    try {
        const page = await browser.newPage({viewport: {width: 1366, height: 900}});
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.request().isNavigationRequest()
            ? route.fulfill({contentType: 'text/html', body: '<!doctype html><html><body></body></html>'}) : route.abort());
        await page.goto('http://owner-drawer.fixture.test/admin/dashboard');
        const boot = {locale: 'en', labels, url: '/admin/dashboard/overview', drawer_url: '/admin/dashboard/overview?section=drawers', initial: overview()};
        await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>' + ownerHtml()
            + '<script type="application/json" id="home-overview-bootstrap">' + JSON.stringify(boot) + '</script></body></html>');
        await page.evaluate(() => {
            window.fixture = {calls: [], intervals: [], now: Date.now(), cleanup: null};
            Date.now = () => fixture.now;
            window.setInterval = callback => (fixture.intervals.push(callback), fixture.intervals.length);
            window.clearInterval = () => {};
            window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => {fixture.cleanup = callback;}};
            // Deliberately allow replies after abort: sequence/scope checks must
            // also prevent late updates when a transport ignores cancellation.
            window.fetch = (url, options) => new Promise(resolve => {
                const call = {url: String(url), options, aborted: false, resolve};
                options.signal?.addEventListener('abort', () => {call.aborted = true;});
                fixture.calls.push(call);
            });
            fixture.reply = (index, result, status = 200) => fixture.calls[index].resolve({ok: status >= 200 && status < 300, status,
                json: async () => result});
        });
        await page.addScriptTag({content: read('public/dashboard/js/home-overview.js')});
        const button = page.locator('[data-ho-load-drawers]');
        const total = page.locator('[data-ho-drawer-total]');
        const waitCalls = count => page.waitForFunction(expected => fixture.calls.length === expected, count);
        const reply = (index, result, status) => page.evaluate(({index, result, status}) => fixture.reply(index, result, status), {index, result, status});
        const flushed = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

        assert.equal(await button.count(), 1, 'The existing Owner card exposes an explicit balance action.');
        assert.equal(await total.textContent(), '—', 'Unloaded balances are not presented as a zero balance.');
        assert.equal(await page.evaluate(() => fixture.calls.length), 0, 'Opening the Owner home triggers no drawer request.');

        await page.evaluate(() => {fixture.now += 61000; fixture.intervals.forEach(callback => callback());});
        await waitCalls(1);
        assert.equal(new URL(await page.evaluate(() => fixture.calls[0].url)).pathname, '/admin/dashboard/overview', 'Automatic home refresh requests only the lightweight overview.');
        assert.equal(new URL(await page.evaluate(() => fixture.calls[0].url)).searchParams.get('section'), null);
        await reply(0, overview()); await flushed();
        assert.equal(await page.evaluate(() => fixture.calls.length), 1, 'Periodic overview completion does not start drawer work.');

        await button.evaluate(node => {node.click(); node.click();}); await waitCalls(2);
        const request = await page.evaluate(() => ({url: fixture.calls[1].url, credentials: fixture.calls[1].options.credentials,
            cache: fixture.calls[1].options.cache, method: fixture.calls[1].options.method || 'GET'}));
        assert.equal(new URL(request.url).pathname, '/admin/dashboard/overview');
        assert.equal(new URL(request.url).searchParams.get('section'), 'drawers');
        assert.equal(new URL(request.url).searchParams.get('branch'), '');
        assert.equal(request.credentials, 'same-origin'); assert.equal(request.cache, 'no-store'); assert.equal(request.method, 'GET');
        assert.equal(await button.isDisabled(), true, 'Repeated clicks cannot queue duplicate heavy work.');
        await reply(1, drawerResponse()); await flushed();
        assert.equal(await total.textContent(), '115.00 EGP');
        assert.deepEqual(await page.locator('[data-ho-drawers] article strong').allTextContents(), ['125.00', '-10.00'], 'Signed branch amounts and total are the returned financial values.');
        assert.equal(await button.isEnabled(), true);

        await page.locator('[data-ho-refresh]').click(); await waitCalls(3);
        assert.equal(await total.textContent(), '—', 'A new overview refresh invalidates previously requested balances immediately.');
        await reply(2, overview()); await flushed();
        assert.equal(await page.evaluate(() => fixture.calls.length), 3);

        await button.click(); await waitCalls(4);
        await page.locator('[data-ho-filters] select[name=branch]').selectOption('f:101'); await waitCalls(5);
        assert.equal(await page.evaluate(() => fixture.calls[3].aborted), true, 'Changing scope aborts the outstanding drawer wait.');
        await reply(4, overview('f:101')); await flushed();
        await reply(3, drawerResponse('', 999999)); await flushed();
        assert.equal(await total.textContent(), '—', 'A late reply for the old branch scope never populates the new screen.');
        await button.click(); await waitCalls(6);
        assert.equal(new URL(await page.evaluate(() => fixture.calls[5].url)).searchParams.get('branch'), 'f:101');
        await reply(5, drawerResponse('f:101', 4321)); await flushed();
        assert.equal(await total.textContent(), '43.21 EGP');
        assert.equal(await page.locator('[data-ho-drawers] article').count(), 1);

        await button.click(); await waitCalls(7);
        await reply(6, {success: false, message: 'Access refused'}, 403); await flushed();
        assert.equal(await button.isEnabled(), true, 'A rejected request can be retried.');
        assert.equal(await total.textContent(), '—', 'Failed refreshes do not keep old balances as if current.');
        assert.equal(await page.locator('[data-ho-drawer-status]').textContent(), labels.drawers_failed);

        await button.click(); await waitCalls(8);
        await reply(7, drawerResponse('', 999999)); await flushed();
        assert.equal(await total.textContent(), '—', 'Even an active response must match the selected branch filters.');
        assert.equal(await page.locator('[data-ho-drawer-status]').textContent(), labels.drawers_failed);

        await button.click(); await waitCalls(9);
        const malformed = drawerResponse('f:101', null);
        await reply(8, malformed); await flushed();
        assert.equal(await total.textContent(), '—', 'Ready without a numeric total must not be coerced into a zero balance.');
        assert.equal(await page.locator('[data-ho-drawer-status]').textContent(), labels.drawers_failed);

        await button.click(); await waitCalls(10);
        await reply(9, drawerResponse('f:101', 0)); await flushed();
        assert.equal(await total.textContent(), '0.00 EGP', 'A genuine requested zero remains a valid financial value.');

        await button.click(); await waitCalls(11);
        const beforeCleanup = await total.textContent();
        await page.evaluate(() => fixture.cleanup());
        assert.equal(await page.evaluate(() => fixture.calls[10].aborted), true, 'SPA navigation aborts the drawer wait.');
        await reply(10, drawerResponse('f:101', 888888)); await flushed();
        assert.equal(await total.textContent(), beforeCleanup, 'Disposed home receives no late financial update.');
        assert.deepEqual(errors, []);
        console.log('GO_ERP_OWNER_DRAWER_LAZY_BROWSER_OK ' + JSON.stringify({genuineChromium: true, actualOwnerBlade: true,
            actualHomeScript: true, automaticDrawerRequests: 0, explicitLoads: 8, scopeAndCleanupFenced: true, unloadedIsNull: true}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
