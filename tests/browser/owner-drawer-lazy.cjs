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
function ownerHtml(includeDrawer = true) {
    let partial = read('resources/views/admin/home_owner.blade.php').replace(/^@php[^\n]*\n/, '');
    partial = partial.replace(/@foreach\(\['takeaway','dine','phone','app','total'\] as \$channel\)([\s\S]*?)@endforeach/g,
        (_, body) => [...channels, 'total'].map(key => body.replace(/{{\s*__\('home_overview\.'\.\$channel\)\s*}}/g, labels[key])).join(''));
    if (!includeDrawer) partial = partial.replace(/@if\(isset\(\$boot\['initial'\]\['owner_drawer'\]\)\)[\s\S]*?@endif/, '');
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


const initialSync = {available: true, prepared: true, mode: 'local', online: true, error: '',
    refresh_deferred: false, refresh_running: false, refreshedAt: '2026-10-27T13:00:00Z', acknowledged: 0};
const syncState = (minute, changes = {}) => ({...initialSync, refreshedAt: '2026-10-27T13:' + String(minute).padStart(2, '0') + ':00Z', ...changes});

(async () => {
    const browser = await chromium.launch({headless: true,
        ...(process.env.DESKTOP_TEST_BROWSER_EXECUTABLE ? {executablePath: process.env.DESKTOP_TEST_BROWSER_EXECUTABLE} : {})});
    const errors = [];
    let assertions = 0, pageCount = 0;
    const check = (value, expected, message) => {assert.equal(value, expected, message); assertions++;};
    try {
        async function open({native = false, owner = true} = {}) {
            const page = await browser.newPage({viewport: {width: 1366, height: 900}}); pageCount++;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.request().isNavigationRequest()
                ? route.fulfill({contentType: 'text/html', body: '<!doctype html><html><body></body></html>'}) : route.abort());
            await page.goto('http://owner-drawer.fixture.test/admin/dashboard');
            const initial = overview(); if (!owner) delete initial.owner_drawer;
            const boot = {locale: 'en', labels, url: '/admin/dashboard/overview',
                ...(owner ? {drawer_url: '/admin/dashboard/overview?section=drawers'} : {}), initial};
            await page.setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body'
                + (native ? ' data-dashboard-local="1"' : '') + '>' + ownerHtml(owner)
                + '<script type="application/json" id="home-overview-bootstrap">' + JSON.stringify(boot) + '</script></body></html>');
            await page.evaluate(({native, initialSync}) => {
                window.fixture = {calls: [], intervals: [], now: Date.now(), cleanup: null, nativeState: initialSync,
                    nativeSubscriber: null, nativeUnsubscribed: 0, statusReads: 0};
                Date.now = () => fixture.now;
                window.setInterval = callback => (fixture.intervals.push(callback), fixture.intervals.length);
                window.clearInterval = () => {};
                window.DashboardSPA = {isCurrentPage: () => true, onCleanup: callback => {fixture.cleanup = callback;}};
                if (native) window.FasakhanstaDesktop = {
                    status: async () => {fixture.statusReads++; return {...fixture.nativeState};},
                    onState: callback => {fixture.nativeSubscriber = callback; return () => {fixture.nativeSubscriber = null; fixture.nativeUnsubscribed++;};}
                };
                // Replies may arrive after abort: transport cancellation alone
                // must never let an obsolete branch or sync overwrite the view.
                window.fetch = (url, options) => new Promise(resolve => {
                    const call = {url: String(url), options, aborted: false, replied: false, resolve};
                    options.signal?.addEventListener('abort', () => {call.aborted = true;}); fixture.calls.push(call);
                });
                fixture.reply = (index, result, status = 200) => {
                    fixture.calls[index].replied = true;
                    fixture.calls[index].resolve({ok: status >= 200 && status < 300, status, json: async () => result});
                };
                fixture.emitState = state => {fixture.nativeState = state; fixture.nativeSubscriber?.(state);};
            }, {native, initialSync});
            await page.addScriptTag({content: read('public/dashboard/js/home-overview.js')});
            const total = page.locator('[data-ho-drawer-total]'), status = page.locator('[data-ho-drawer-status]');
            const flush = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const count = () => page.evaluate(() => fixture.calls.length);
            const wait = async expected => {
                await page.waitForFunction(n => fixture.calls.length >= n, expected, {timeout: 5000}); await flush();
                check(await count(), expected, 'Only the expected nonoverlapping automatic reads are requested.');
                check(await page.evaluate(() => fixture.calls.filter(call => !call.replied && !call.aborted).length <= 1), true,
                    'Overview and drawer reads do not overlap or form a request storm.');
            };
            const request = async (index, kind, branch = '') => {
                const value = await page.evaluate(index => {const c = fixture.calls[index];return {url: c.url, credentials: c.options.credentials,
                    cache: c.options.cache, method: c.options.method || 'GET'};}, index);
                const url = new URL(value.url); check(url.origin, 'http://owner-drawer.fixture.test', 'Financial reads remain same-origin.');
                check(url.pathname, '/admin/dashboard/overview'); check(url.searchParams.get('section'), kind === 'drawers' ? 'drawers' : null);
                check(url.searchParams.get('branch'), branch); check(value.credentials, 'same-origin'); check(value.cache, 'no-store'); check(value.method, 'GET');
            };
            const reply = async (index, value, statusCode = 200) => {await page.evaluate(({index, value, statusCode}) => fixture.reply(index, value, statusCode), {index, value, statusCode}); await flush();};
            const periodic = () => page.evaluate(() => {fixture.now += 61000; fixture.intervals.forEach(callback => callback());});
            const emit = value => page.evaluate(value => fixture.emitState(value), value);
            return {page, total, status, flush, count, wait, request, reply, periodic, emit};
        }

        // Ordinary web home: balances load themselves after the overview has
        // rendered, then follow every successful periodic overview read.
        const web = await open();
        check(await web.page.locator('[data-ho-load-drawers]').count(), 0, 'Owner has no special manual gate for balances.');
        await web.wait(1); await web.request(0, 'drawers');
        check(await web.page.locator('[data-ho-platform] .ho-kpi').count() > 0, true, 'The light overview renders while the balance response is delayed.');
        check(await web.page.locator('[data-ho-content]').isVisible(), true, 'An outstanding balance calculation does not hide or block the dashboard.');
        check(await web.total.textContent(), '—', 'Unloaded balances are not fabricated as zero.');
        check(await web.status.textContent(), labels.drawers_loading);
        await web.reply(0, drawerResponse());
        check(await web.total.textContent(), '115.00 EGP');
        assert.deepEqual(await web.page.locator('[data-ho-drawers] article strong').allTextContents(), ['125.00', '-10.00']); assertions++;
        await web.periodic(); await web.wait(2); await web.request(1, 'overview');
        check(await web.total.textContent(), '115.00 EGP', 'Same-scope refresh keeps the last confirmed balance visible.');
        await web.reply(1, overview()); await web.wait(3); await web.request(2, 'drawers');
        check(await web.total.textContent(), '115.00 EGP', 'The previous amount remains visible during the automatic replacement read.');
        check((await web.status.textContent()).includes(labels.drawers_loading), true);
        check((await web.status.textContent()).includes(labels.drawers_last_updated), true, 'A cached amount always retains its last successful timestamp.');
        await web.reply(2, drawerResponse('', 0)); check(await web.total.textContent(), '0.00 EGP', 'A genuine returned zero is shown normally.');
        await web.page.close();

        // Desktop refresh publication: initial/progress/failed/deferred state
        // cannot masquerade as a successfully synchronized database.
        const desktop = await open({native: true}); await desktop.wait(1); await desktop.reply(0, drawerResponse());
        check(await desktop.page.evaluate(() => fixture.statusReads), 1, 'The current native stamp is baselined once at entry.');
        for (const value of [syncState(0, {acknowledged: 9}), syncState(1, {refresh_running: true}),
            syncState(1, {error: 'Fixed synthetic failure'}), syncState(1, {refresh_deferred: true}),
            syncState(1, {online: false}), syncState(1, {mode: 'server'}), syncState(1, {prepared: false})]) {
            await desktop.emit(value); await desktop.flush(); check(await desktop.count(), 1, 'Only a proven settled local sync may trigger automatic figures.');
        }
        await desktop.emit(syncState(1)); await desktop.wait(2); await desktop.request(1, 'overview');
        await desktop.emit(syncState(1)); await desktop.flush(); check(await desktop.count(), 2, 'Repeated publication of the same stamp does not duplicate work.');
        await desktop.emit(syncState(2)); await desktop.emit(syncState(3)); await desktop.emit(syncState(4)); await desktop.flush();
        check(await desktop.count() <= 3, true, 'A burst of completed sync notifications coalesces to one latest refresh.');
        await desktop.reply(1, overview()); await desktop.wait(3); await desktop.request(2, 'overview');
        await desktop.reply(2, overview()); await desktop.wait(4); await desktop.request(3, 'drawers');
        check(await desktop.total.textContent(), '115.00 EGP');
        await desktop.reply(3, drawerResponse('', 20000)); check(await desktop.total.textContent(), '200.00 EGP');
        await desktop.emit(syncState(4)); await desktop.flush(); check(await desktop.count(), 4);

        // A sync arriving after a drawer query began invalidates that reply;
        // even a transport ignoring cancellation cannot publish the old value.
        await desktop.periodic(); await desktop.wait(5); await desktop.reply(4, overview()); await desktop.wait(6);
        await desktop.emit(syncState(5)); await desktop.flush();
        check(await desktop.count(), 6, 'Sync completion fences the old read and queues one refresh without overlapping server reads.');
        await desktop.reply(5, drawerResponse('', 999999));
        await desktop.wait(7); await desktop.request(6, 'overview');
        check(await desktop.total.textContent(), '200.00 EGP', 'An old pre-sync reply cannot overwrite the last known balance.');
        await desktop.reply(6, overview()); await desktop.wait(8); await desktop.reply(7, drawerResponse('', 30000));
        check(await desktop.total.textContent(), '300.00 EGP');
        await desktop.page.close();

        // The scope and permission boundaries still take priority over keeping
        // a cached display. Network errors retain known same-scope values;
        // denied authority and a new branch cannot retain them.
        const scoped = await open(); await scoped.wait(1); await scoped.reply(0, drawerResponse());
        await scoped.periodic(); await scoped.wait(2); await scoped.reply(1, overview()); await scoped.wait(3);
        await scoped.reply(2, {success: false}, 500);
        check(await scoped.total.textContent(), '115.00 EGP', 'A failed background read keeps the last confirmed amount.');
        check((await scoped.status.textContent()).includes(labels.drawers_failed), true);
        check((await scoped.status.textContent()).includes(labels.drawers_last_updated), true);
        await scoped.periodic(); await scoped.wait(4); await scoped.reply(3, overview()); await scoped.wait(5);
        await scoped.page.locator('[data-ho-filters] select[name=branch]').selectOption('f:101'); await scoped.wait(6);
        check(await scoped.page.evaluate(() => fixture.calls[4].aborted), true, 'A scope change aborts the previous balance query.');
        check(await scoped.total.textContent(), '—', 'Changing branches clears old branch totals immediately.');
        await scoped.reply(5, overview('f:101')); await scoped.wait(7); await scoped.request(6, 'drawers', 'f:101');
        await scoped.reply(4, drawerResponse('', 999999)); check(await scoped.total.textContent(), '—', 'A late old-scope reply stays excluded.');
        await scoped.reply(6, drawerResponse('f:101', 4321)); check(await scoped.total.textContent(), '43.21 EGP');
        check(await scoped.page.locator('[data-ho-drawers] article').count(), 1);
        await scoped.periodic(); await scoped.wait(8); await scoped.reply(7, overview('f:101')); await scoped.wait(9);
        await scoped.reply(8, {success: false}, 403);
        check(await scoped.total.textContent(), '—', 'A denied permission clears previously visible financial data.');
        check((await scoped.status.textContent()).includes(labels.drawers_last_updated), false, 'A denied read does not preserve a cached-authority timestamp.');
        await scoped.periodic(); await scoped.wait(10); await scoped.reply(9, overview('f:101')); await scoped.wait(11);
        await scoped.reply(10, drawerResponse('f:101', null)); check(await scoped.total.textContent(), '—', 'Malformed totals are not coerced into zero.');
        await scoped.periodic(); await scoped.wait(12); await scoped.reply(11, overview('f:101')); await scoped.wait(13);
        await scoped.reply(12, drawerResponse('f:101', 0)); check(await scoped.total.textContent(), '0.00 EGP');
        await scoped.page.close();

        const cleanup = await open({native: true}); await cleanup.wait(1); await cleanup.reply(0, drawerResponse());
        await cleanup.emit(syncState(1)); await cleanup.wait(2); const beforeCleanup = await cleanup.total.textContent();
        await cleanup.page.evaluate(() => fixture.cleanup());
        check(await cleanup.page.evaluate(() => fixture.calls[1].aborted), true, 'SPA navigation cancels the active overview.');
        check(await cleanup.page.evaluate(() => fixture.nativeUnsubscribed), 1, 'The native state listener is detached with its page.');
        await cleanup.reply(1, overview()); await cleanup.emit(syncState(2)); await cleanup.periodic(); await cleanup.flush();
        check(await cleanup.count(), 2, 'Disposed home schedules neither balances nor native/periodic refreshes.');
        check(await cleanup.total.textContent(), beforeCleanup, 'Disposed home receives no late financial mutation.');
        await cleanup.page.close();

        const other = await open({native: true, owner: false}); await other.flush();
        check(await other.count(), 0, 'Accounts without Owner authority do not request balances at entry.');
        check(await other.page.locator('[data-ho-drawers]').count(), 0, 'The original non-Owner Blade does not expose a drawer panel.');
        await other.periodic(); await other.wait(1); await other.request(0, 'overview');
        const withoutOwner = overview(); delete withoutOwner.owner_drawer;
        await other.reply(0, withoutOwner); check(await other.count(), 1, 'Ordinary overview completion never adds a privileged financial request.');
        await other.page.close();
        assert.deepEqual(errors, []);
        console.log('GO_ERP_OWNER_DRAWER_AUTOMATIC_BROWSER_OK ' + JSON.stringify({genuineChromium: true, actualOwnerBlade: true,
            actualHomeScript: true, pages: pageCount, assertions, automaticAtEntry: true, automaticOnPeriodicAndNativeSync: true,
            sameScopeAmountsPreserved: true, permissionAndScopeFenced: true, queuedSyncCoalesced: true, noManualGate: true}));
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
