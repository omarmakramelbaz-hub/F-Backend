'use strict';
// The dashboard's current look offline (the GO opening and the GO in the sidebar), and the device's copy of the
// dashboard following the sync.
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { currentLook, fromDevice, hologramFile, homeBoot, sectionsOnly, PREFIX } = require('../src/main/offline/hologram.cjs');
const { HomeOffline, savedCounters } = require('../src/main/offline/home.cjs');
const { PageCache } = require('../src/main/offline/cache.cjs');
const { DashboardOffline, warmUpScript, REFRESHED_DATA } = require('../src/main/offline/interceptor.cjs');

const ORIGIN = 'https://fasakhaninja.com';
const sha256 = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

// deployment/hologram/manifest.json of the dashboard's release hologram-energy-20261101-v5 (after_sha256).
const RELEASE = {
  'go-hologram.js': 'da67e52ba5ab02bf11e11e596f66206dc0c78b60ae6fafa9d741541e551ba3fa',
  'go-hologram-shell.js': '089934b4e467e144c60a179db3065ea81036b9cf57eba34051c2ec09f2284200',
  'go-hologram.css': '83dd2b84bae97e3a1e6a14b4300875636272981e2b57c471b6647fae9c5bb018',
  'home-hud.js': '7c120df407a8dd7e2d23f026241940ec305dd4a6e570a6bb960bfde41a6dfc3b',
  'home-hud.css': '9999469049e0d0404b1e9789822e93ffa98c91b258a4259bacc5fcebc9c6dae2',
  'home-hud.html': '1b405989261c885cf9e18b5d1689c6ccf94d2c6db52850708f7e01398610d978',
  'sidebar.html': 'c6f2c09cf917dc117917775cb44c40adeee19f7c31bb69de33f39bce5f0803b9',
};

/** A dashboard page as the dashboard rendered it before the hologram release (the solar opening, the GO ERP logo). */
function earlierPage({ home = true, actor = '4:admin' } = {}) {
  // The dashboard's opening as it was before the hologram release (the repository now holds the live, newer one).
  const opening = fs.readFileSync(path.join(__dirname, 'fixtures', 'home-hud-earlier.blade.php'), 'utf8')
    .replace(/\{\{--[\s\S]*?--\}\}\s*/, '')
    .replace("{{ !empty($boot['intro']) ? '1' : '0' }}", '1')
    .replace("@include('admin.home_hud_solar')", '<svg class="hud-solar" viewBox="0 0 10 10"><circle r="4"/><svg viewBox="0 0 1 1"></svg></svg>');
  assert.ok(opening.includes('data-hud-core'), 'The fixture is the earlier opening');
  const boot = { initial: { filters: { branch: '', period: 'today', from: '2026-11-02', to: '2026-11-02' }, owner_drawer: { loaded: false } },
    url: ORIGIN + '/admin/dashboard/overview', drawer_url: ORIGIN + '/admin/dashboard/overview?section=drawers', locale: 'ar',
    labels: { title: 'الرئيسية <b>' }, intro: true,
    hud_links: { employees: ORIGIN + '/admin/employees', expenses: ORIGIN + '/admin/branch-expenses', 'ch-takeaway': ORIGIN + '/admin/takeaway',
      sales: ORIGIN + '/admin/branch-orders', drawer: ORIGIN + '/admin/branch-shifts' } };
  const json = JSON.stringify(boot).replace(/</g, '\\u003C').replace(/>/g, '\\u003E');
  return `<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>GO</title>
<link rel="stylesheet" href="${ORIGIN}/dashboard/css/dashboard-fit.css?v=1">
<!-- dashboard-page-css:start -->${home ? `<link rel="stylesheet" href="${ORIGIN}/dashboard/css/home-overview.css?v=1">
<link rel="stylesheet" href="${ORIGIN}/dashboard/css/home-hud.css?v=1761000000">` : ''}<!-- dashboard-page-css:end -->
</head><body class="dashboard-theme" data-dashboard-actor="${actor}">
<aside class="main-sidebar" id="dashboard-sidebar"><div class="brand-link dashboard-brand">
<svg xmlns="http://www.w3.org/2000/svg" class="dashboard-brand-logo go-erp-platform-logo" data-go-erp-vector="1" data-go-erp-explosion="1" data-go-erp-dynamic="1" data-go-erp-solar="1" color="#fd7201" viewBox="-180 -18 1420 520" role="img" aria-label="GO" focusable="false">
  <title>GO</title><defs><radialGradient id="g1"><stop offset="0"/></radialGradient></defs><g><svg viewBox="0 0 1 1"><path d="M0 0"/></svg></g>
</svg>
<span class="dashboard-brand-copy"><strong>فسخانستا</strong><small>GO · الداشبورد</small></span>
</div><hr><a href="${ORIGIN}/admin/takeaway">الكاشير</a></aside>
<nav class="main-header"></nav>
<div id="dashboard-page" data-dashboard-page style="display:contents">
${home ? `<div class="content-wrapper ho-wrapper">${opening}<section id="home-overview"><h1 id="ho-title">الرئيسية</h1></section></div>` : '<div class="content-wrapper"><h1>صفحة</h1></div>'}
</div>
<script src="${ORIGIN}/dashboard/js/dashboard-order-alerts.js?v=1"></script>
<div data-dashboard-page-scripts hidden>
${home ? `<script type="application/json" id="home-overview-bootstrap">${json}</script>
<script src="${ORIGIN}/dashboard/js/home-overview.js?v=1"></script>
<script src="${ORIGIN}/dashboard/js/home-hud.js?v=1761000000"></script>` : ''}
</div>
</body></html>`;
}

test('the GO opening shipped with the app is the dashboard\'s release, file by file', () => {
  for (const [name, digest] of Object.entries(RELEASE)) {
    assert.equal(sha256(path.join(__dirname, '..', 'src/main/offline/hologram', name)), digest, name);
  }
  // The sign-in screen's hologram is the same engine.
  assert.equal(sha256(path.join(__dirname, '..', 'src/renderer/vendor/go-hologram.js')), RELEASE['go-hologram.js']);
  assert.ok(fs.readFileSync(path.join(__dirname, '..', 'src/main/offline/hologram/go-hud-mark.svg'), 'utf8').includes('id="go-hud-mark-fill"'));
  // Under the dashboard's own folders: its in-place page switch runs a page's script under /dashboard/js/ on every visit.
  assert.equal(PREFIX, '/go-erp-desktop/dashboard/');
  assert.equal(hologramFile(PREFIX + 'js/home-hud.js').contentType, 'application/javascript; charset=utf-8');
  assert.equal(hologramFile(PREFIX + 'css/go-hologram.css').body.length, fs.statSync(path.join(__dirname, '..', 'src/main/offline/hologram/go-hologram.css')).size);
  for (const other of ['js/home-hud.html', 'home-hud.js', 'css/home-hud.js', 'js/sidebar.html', 'go-hud-mark.svg', 'js/../../hologram.cjs', '%2e%2e/home.cjs', '']) {
    assert.equal(hologramFile(PREFIX + other), null, other);
  }
  assert.equal(hologramFile('/dashboard/js/home-hud.js'), null);
});

test('a home page saved before the dashboard\'s GO opening opens offline in the dashboard\'s current look', () => {
  const counters = { cards: [{ key: 'employees', label: 'الموظفين والرواتب', url: ORIGIN + '/admin/employees', count: 12, count_label: 'موظف',
    secondary_count: null, secondary_label: null, warning: false, available: true }], scope: 'all', scope_label: 'جميع الفروع المصرّح بها', branch_count: 3,
  updated_at: '2026-11-02T02:00:00+02:00' };
  const earlier = Buffer.from(earlierPage());
  const html = currentLook(earlier, { origin: ORIGIN, name: 'عمر <المالك>', counters }).toString('utf8');
  // The sidebar: the GO hologram in place of the GO ERP logo, its styles and scripts as the dashboard's layout has them.
  assert.ok(!html.includes('data-go-erp-solar'), 'The earlier logo is gone');
  assert.ok(html.includes(`<a class="go-sidebar-robot" href="${ORIGIN}/admin/dashboard" aria-label="GO — الرئيسية">`));
  assert.ok(html.includes('<canvas data-go-sidebar-robot aria-hidden="true"></canvas>'));
  assert.ok(html.includes('<span class="dashboard-brand-copy">'), 'The brand text stays (the styles hide it, as on the dashboard)');
  assert.ok(html.indexOf(`<link rel="stylesheet" href="${PREFIX}css/go-hologram.css">`) < html.indexOf('</head>'));
  const engine = html.indexOf(`<script src="${PREFIX}js/go-hologram.js"></script>`);
  assert.ok(engine > html.indexOf('dashboard-order-alerts.js') && engine < html.indexOf(`<script src="${PREFIX}js/go-hologram-shell.js"></script>`));
  assert.ok(html.indexOf(`${PREFIX}js/go-hologram-shell.js`) < html.indexOf('<div data-dashboard-page-scripts'), 'Before the page\'s own scripts');
  // The home page: the release's opening in place of the solar one, with the person's name and the GO mark.
  assert.ok(!html.includes('data-hud-core') && !html.includes('hud-checks') && !html.includes('hud-solar'), 'Nothing of the earlier opening is left');
  assert.ok(html.includes('<canvas data-hud-robot></canvas>') && html.includes('data-hud-right') && html.includes('data-hud-enter'));
  assert.ok(html.includes('data-intro="1"'), 'Its start-up plays as the saved page said');
  assert.ok(html.includes('<strong data-hud-who>عمر &lt;المالك&gt;</strong>'), 'The name, escaped');
  assert.ok(html.includes('<linearGradient id="go-hud-mark-fill"'), 'The GO mark');
  assert.ok(!html.includes('{{') && !html.includes('@include'), 'No template left');
  assert.ok(html.includes(`href="${PREFIX}css/home-hud.css"`) && html.includes(`src="${PREFIX}js/home-hud.js"`));
  assert.ok(!html.includes(ORIGIN + '/dashboard/css/home-hud.css') && !html.includes(ORIGIN + '/dashboard/js/home-hud.js'), 'Not the earlier files');
  assert.ok(html.includes('<section id="home-overview">'), 'The home page under the opening is kept');
  // The opening starts with the counts last read online, the rest of the page's data unchanged.
  const found = homeBoot(html);
  assert.deepEqual(found.boot.initial.hologram, counters);
  assert.equal(found.boot.labels.title, 'الرئيسية <b>');
  assert.ok(!/<\/?b>/.test(html.slice(found.start, found.start + found.text.length)), 'The data stays safe inside its script tag');
  // Once in the current look, nothing changes any more.
  assert.equal(currentLook(Buffer.from(html), { origin: ORIGIN, name: 'x', counters: null }).toString('utf8'), html);
});

test('without counts saved, the opening shows the account\'s sections; other pages only get the sidebar', () => {
  const html = currentLook(Buffer.from(earlierPage()), { origin: ORIGIN, name: 'كاشير', counters: () => null }).toString('utf8');
  const { cards } = homeBoot(html).boot.initial.hologram;
  assert.deepEqual(cards.map((card) => card.key), ['employees', 'expenses', 'drawer', 'ch-takeaway', 'pending-expenses']);
  assert.ok(cards.every((card) => card.available === false && card.count === null), 'No count is made up');
  assert.equal(cards.find((card) => card.key === 'pending-expenses').url, ORIGIN + '/admin/branch-expenses');
  assert.deepEqual(sectionsOnly(null).cards, []);
  const other = currentLook(Buffer.from(earlierPage({ home: false })), { origin: ORIGIN, name: 'x' }).toString('utf8');
  assert.ok(other.includes('data-go-sidebar-robot') && other.includes(`${PREFIX}js/go-hologram.js`));
  assert.ok(!other.includes('data-hud-robot') && !other.includes('home-hud'));
});

test('a home page from the device says its counts are the last read; other pages are untouched', () => {
  const current = '<body data-dashboard-actor="4:admin"><section id="go-hud"><canvas data-hud-robot></canvas><span data-hud-freshness></span></section>'
    + `<script src="${ORIGIN}/dashboard/js/home-hud.js?v=1762041600"></script><p>بعد</p></body>`;
  const marked = fromDevice(Buffer.from(current)).toString('utf8');
  assert.ok(marked.includes('home-hud.js?v=1762041600"></script><script>(function(){') && marked.endsWith('<p>بعد</p></body>'));
  assert.ok(marked.includes("f.textContent='تعذّر التحديث · آخر أرقام متاحة'"));
  const other = Buffer.from(earlierPage({ home: false }));
  assert.equal(fromDevice(other), other);
  const earlier = Buffer.from(earlierPage());
  assert.equal(fromDevice(earlier), earlier, 'The earlier opening has no such words');
});

test('pages that already have the dashboard\'s look, or not a dashboard page, are left exactly as saved', () => {
  const current = Buffer.from(earlierPage().replace(/<svg xmlns="http:\/\/www.w3.org\/2000\/svg" class="dashboard-brand-logo[\s\S]*?<\/g>\n<\/svg>/,
    '<a class="go-sidebar-robot" href="/admin/dashboard"><canvas data-go-sidebar-robot></canvas></a>')
    .replace(/<section id="go-hud"[\s\S]*?data-hud-skip hidden>تخطي<\/button>\r?\n<\/section>/, '<section id="go-hud"><canvas data-hud-robot></canvas></section>'));
  assert.ok(current.toString().includes('data-go-sidebar-robot') && current.toString().includes('data-hud-robot'));
  assert.equal(currentLook(current, { origin: ORIGIN, name: 'x' }), current, 'The same copy, untouched');
  const plain = Buffer.from('<html><body><p>receipt</p></body></html>');
  assert.equal(currentLook(plain, { origin: ORIGIN }), plain);
  // A later look of the dashboard (neither the earlier logo nor the earlier opening) is never replaced by this one.
  const later = Buffer.from(earlierPage().replace('data-go-erp-solar="1"', 'data-go-erp-later="1"').replace('data-hud-core', 'data-hud-later'));
  assert.equal(currentLook(later, { origin: ORIGIN, name: 'x' }), later);
});

function cacheAt(start) {
  let clock = Date.parse(start);
  const cache = new PageCache(':memory:', { now: () => new Date(clock) });
  return { cache, tick: (ms) => { clock += ms; } };
}

test('offline, the home page opens from the device in the current look, with the files shipped with the app', async () => {
  const { cache, tick } = cacheAt('2026-11-01T19:07:00Z');
  const user = { id: 4, name: 'عمر', can_checkout: true };
  cache.put('u:4', 'page', ORIGIN + '/admin/dashboard', 'text/html; charset=UTF-8', Buffer.from(earlierPage()));
  tick(60000);
  const counters = { cards: [], scope: 'all', scope_label: 'كل الفروع', branch_count: 2, updated_at: '2026-11-01T21:08:00+02:00' };
  cache.put('u:4', 'data', ORIGIN + '/admin/dashboard/overview?branch=&period=today', 'application/json', Buffer.from(JSON.stringify({ success: true, hologram: counters, kpis: [1] })));
  const home = new HomeOffline({ cache, origin: ORIGIN });
  const offline = new DashboardOffline({ origin: ORIGIN, cache, pages: [home], user: () => user });
  const contents = {};
  const ask = (url, resourceType = 'Document', headers = {}, online = false) => offline.answer(contents, { request: { url, method: 'GET', headers }, resourceType }, !online);
  const page = await ask(ORIGIN + '/admin/dashboard');
  const html = page.body.toString('utf8');
  assert.ok(html.includes('data-hud-robot') && html.includes('data-go-sidebar-robot'));
  assert.deepEqual(homeBoot(html).boot.initial.hologram, counters, 'The opening starts with the counts last read online');
  // From the start, the opening says its counts are the last ones read, right after its own script.
  const script = html.indexOf(`<script src="${PREFIX}js/home-hud.js"></script>`);
  assert.ok(script > 0 && html.indexOf("textContent='تعذّر التحديث · آخر أرقام متاحة'") > script);
  for (const name of ['js/go-hologram.js', 'js/go-hologram-shell.js', 'css/go-hologram.css', 'js/home-hud.js', 'css/home-hud.css']) {
    for (const online of [false, true]) {
      const file = await ask(ORIGIN + PREFIX + name + '?x=1', 'Script', {}, online);
      assert.equal(file.status, 200, name);
      assert.ok(file.body.equals(fs.readFileSync(path.join(__dirname, '..', 'src/main/offline/hologram', path.basename(name)))), name);
    }
  }
  // The opening's counters are never answered as live offline; the overview's saved data comes without them.
  const live = await ask(ORIGIN + '/admin/dashboard/overview?section=hologram', 'Fetch', { Accept: 'application/json' });
  assert.equal(live.status, 503);
  const overview = await ask(ORIGIN + '/admin/dashboard/overview?branch=&period=today', 'Fetch', { Accept: 'application/json' });
  assert.equal(overview.status, 200);
  assert.deepEqual(overview.json, { success: true, kpis: [1] });
  const unknown = await ask(ORIGIN + '/admin/dashboard/overview?branch=f%3A9&period=week', 'Fetch', { Accept: 'application/json' });
  assert.equal(unknown.status, 503, 'Never saved: the page keeps what it shows');
  // Online the dashboard answers, and its answers are kept (the page's numbers offline).
  assert.equal(await ask(ORIGIN + '/admin/dashboard/overview?section=hologram', 'Fetch', { Accept: 'application/json' }, true), null);
  assert.ok(offline.plan({ url: ORIGIN + '/admin/dashboard/overview?section=hologram', headers: {} }, 'Fetch', [{ name: 'Content-Type', value: 'application/json' }]));
});

test('the opening\'s counts are the newest read online, by the opening itself or with the overview', () => {
  const { cache, tick } = cacheAt('2026-11-02T08:00:00Z');
  assert.equal(savedCounters(cache, 'u:4', ORIGIN), null);
  cache.put('u:4', 'data', ORIGIN + '/admin/dashboard/overview?section=hologram', 'application/json', Buffer.from(JSON.stringify({ success: true, hologram: { cards: [{ key: 'a' }] } })));
  tick(1000);
  cache.put('u:4', 'data', ORIGIN + '/admin/dashboard/overview?branch=&period=today', 'application/json', Buffer.from(JSON.stringify({ success: true, hologram: { cards: [{ key: 'b' }] } })));
  assert.deepEqual(savedCounters(cache, 'u:4', ORIGIN).cards, [{ key: 'b' }]);
  tick(1000);
  cache.put('u:4', 'data', ORIGIN + '/admin/dashboard/overview?section=hologram', 'application/json', Buffer.from(JSON.stringify({ success: true, hologram: { cards: [{ key: 'c' }] } })));
  assert.deepEqual(savedCounters(cache, 'u:4', ORIGIN).cards, [{ key: 'c' }]);
  assert.equal(savedCounters(cache, 'u:5', ORIGIN), null, 'Never another account\'s');
});

test('an in-place page switch offline shows the newer of its own copy and the whole page read again with the sync', async () => {
  const { cache, tick } = cacheAt('2026-11-01T19:00:00Z');
  const user = { id: 4, name: 'عمر' };
  const offline = new DashboardOffline({ origin: ORIGIN, cache, pages: [], user: () => user });
  const spa = { 'X-Dashboard-SPA': '1', Accept: 'text/html' };
  cache.put('u:4', 'spa', ORIGIN + '/admin/branch-stock', 'text/html', Buffer.from('<body data-dashboard-actor="4:admin">قبل التصفير</body>'));
  tick(5 * 60000);
  cache.put('u:4', 'page', ORIGIN + '/admin/branch-stock', 'text/html', Buffer.from('<body data-dashboard-actor="4:admin">بعد التصفير</body>'));
  const ask = (headers, resourceType) => offline.answer({}, { request: { url: ORIGIN + '/admin/branch-stock', method: 'GET', headers }, resourceType }, true);
  assert.match((await ask(spa, 'Fetch')).body.toString(), /بعد التصفير/);
  tick(60000);
  cache.put('u:4', 'spa', ORIGIN + '/admin/branch-stock', 'text/html', Buffer.from('<body data-dashboard-actor="4:admin">أحدث</body>'));
  assert.match((await ask(spa, 'Fetch')).body.toString(), /أحدث/);
  assert.match((await ask({ Accept: 'text/html' }, 'Document')).body.toString(), /بعد التصفير/, 'A whole page load only from a whole page');
  // A copy of the very address asked for (its filters) comes before a newer copy of the page with other filters.
  cache.put('u:4', 'spa', ORIGIN + '/admin/branch-stock?branch=f%3A2', 'text/html', Buffer.from('<body data-dashboard-actor="4:admin">فرع 2</body>'));
  tick(60000);
  cache.put('u:4', 'page', ORIGIN + '/admin/branch-stock?branch=f%3A1', 'text/html', Buffer.from('<body data-dashboard-actor="4:admin">فرع 1</body>'));
  const filtered = await offline.answer({}, { request: { url: ORIGIN + '/admin/branch-stock?branch=f%3A2', method: 'GET', headers: spa }, resourceType: 'Fetch' }, true);
  assert.match(filtered.body.toString(), /فرع 2/);
});

test('the sync reads the home page\'s numbers and the lists the offline pages start from', () => {
  const { cache, tick } = cacheAt('2026-10-20T08:00:00Z');
  cache.put('u:4', 'data', ORIGIN + '/admin/branch-stock/data?branch=f%3A1', 'application/json', Buffer.from('{}'));
  tick(10 * 24 * 3600 * 1000);
  cache.put('u:4', 'data', ORIGIN + '/admin/branch-expenses/data?branch=f%3A1', 'application/json', Buffer.from('{}'));
  tick(1000);
  cache.put('u:4', 'data', ORIGIN + '/admin/getOrders?alerts_only=1', 'application/json', Buffer.from('{}'));
  const week = new Date(Date.parse('2026-10-30T08:00:00Z') - 7 * 24 * 3600 * 1000);
  assert.deepEqual(cache.recent('u:4', 'data', { since: week }), [ORIGIN + '/admin/getOrders?alerts_only=1', ORIGIN + '/admin/branch-expenses/data?branch=f%3A1']);
  assert.equal(cache.forgetOlder('u:4', 'data', week), 1, 'A list nobody opened for a week is dropped');
  assert.equal(cache.get('u:4', 'data', ORIGIN + '/admin/branch-stock/data?branch=f%3A1'), null);
  // Only the dashboard's lists the offline pages build on are read again (never the order alerts' feed).
  for (const address of ['/admin/dashboard/overview', '/admin/branch-stock/data', '/admin/branch-expenses/data', '/admin/branch-expenses/pending-count',
    '/admin/branch-shifts/data', '/admin/employees/data', '/admin/employees/entries']) assert.ok(REFRESHED_DATA.test(address), address);
  for (const address of ['/admin/getOrders', '/admin/branch-expenses/export', '/admin/dashboard', '/admin/employees/data/x']) assert.ok(!REFRESHED_DATA.test(address), address);
  const script = warmUpScript({ pages: ['/admin/dashboard'], data: [ORIGIN + '/admin/branch-expenses/data?branch=f%3A1'] });
  assert.doesNotThrow(() => new Function(script));
  assert.ok(script.includes('"/admin/dashboard"') && script.includes('branch-expenses/data?branch=f%3A1'));
  assert.ok(script.includes("searchParams.set('section', 'hologram')") && script.includes('boot.drawer_url'));
});
