// Run the actual stock and recipe page scripts against the real Blade markup.
// Only HTTP responses are fixtures; assertions cover user-visible confirmation,
// branch scoping, conflicts, and recovery of a committed operation.
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { JSDOM, VirtualConsole } = require('jsdom');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
const pendingKey = 'fasakhansta:recipe-pending:7';
const branches = [
    { value: 'f:100', name: 'المنصورة' },
    { value: 'f:101', name: 'نبروه' },
    { value: 'f:94', name: 'المحلة' },
];
const ingredients = [{ id: 16, name: 'بصل', unit: 'kg', stock: null }];
const product = { id: 101, name: 'طبق بصل', features: [{ id: 0, label: 'الحجم الأساسي' }], recipe: null, stock_source: 'ingredient_preview', recipe_hint: [{ ingredient_id: 16, measure: 'g', quantity: '' }] };
const hostileName = '<img src=x onerror="window.recipeUnsafe=true"> صنف غير مطابق';
const source = { branch: 'f:94', name: 'المحلة', frozen_at: '2026-10-18 15:30:00', recipe_count: 12 };
const summary = { matched: 8, changed: 3, unchanged: 5, skipped: 2 };
const skipped = [{ name: hostileName, reason: '<script>recipeUnsafe=true</script> الحجم غير موجود' }, { name: 'سلطة موقوفة', reason: 'الصنف غير موجود في الفرع' }];

function stockData(branch = 'f:100', manage = true) {
    return { success: true, branch: { value: branch, name: branches.find(row => row.value === branch).name }, items: ingredients, ingredients, can_manage_recipes: manage, history: [], legacy: [], unmapped_sales: [], unconfigured_count: 1, pagination: { page: 1, last_page: 1, total: 1 } };
}

function template() {
    let html = read('resources/views/admin/branch_stock/index.blade.php').split("@section('content')")[1].split('@endsection')[0];
    html = html.replace(/@foreach\(\$boot\['branches'\] as \$b\)[\s\S]*?@endforeach/, branches.map(branch => `<option value="${branch.value}">${branch.name}</option>`).join(''));
    html = html.replace(/@if\(count\(\$boot\['branches'\]\)===1\) disabled @endif/g, '');
    assert(!/@if|@foreach|{{/.test(html), 'recipe DOM fixture must resolve every Blade directive');
    return html;
}

function response(data, status = 200) {
    return { ok: status >= 200 && status < 300, status, json: async () => structuredClone(data) };
}

function previewData(branch = 'f:100', extra = {}) {
    return { success: true, branch: { value: branch, name: branches.find(row => row.value === branch).name }, can_apply: true, available: true, source, preview_hash: 'preview-' + branch, summary, skipped, ...extra };
}

function committed(op) {
    return { success: true, basic_recipes: { summary, skipped, source }, operation: { branch: op.branch, idempotency_key: op.idempotency_key, preview_hash: op.preview_hash } };
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

async function eventually(condition, message) {
    for (let i = 0; i < 80; i++) {
        if (condition()) return;
        await new Promise(resolve => setImmediate(resolve));
    }
    assert(condition(), message);
}

function setup({ manage = true, stored = null, apply, recover, status, stock } = {}) {
    const errors = [], calls = [], virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', error => errors.push(error.message));
    const boot = { actor_id: 7, branch: 'f:100', branches, initial: stockData('f:100', manage), urls: { data: '/stock-data', receive: '/stock-receive', recover: '/recipe-recover', recipes: '/recipes', recipe_save: '/recipe-save', basic_status: '/basic-status', basic_apply: '/basic-apply' } };
    const dom = new JSDOM(`<meta name="csrf-token" content="test-csrf">${template()}<script type="application/json" id="branch-stock-bootstrap">${JSON.stringify(boot)}</script>`, { url: 'https://recipe-test.invalid/', runScripts: 'outside-only', virtualConsole });
    const w = dom.window, document = w.document;
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
    w.crypto.randomUUID = randomUUID;
    w.confirm = () => true;
    w.addEventListener('error', event => errors.push(event.message));
    if (stored) w.localStorage.setItem(pendingKey, JSON.stringify(stored));
    w.fetch = async (address, options = {}) => {
        const url = new URL(address), body = options.body ? JSON.parse(options.body) : null;
        const call = { path: url.pathname, query: Object.fromEntries(url.searchParams), method: options.method || 'GET', body, options };
        calls.push(call);
        if (url.pathname === '/stock-data') return stock ? stock(call) : response(stockData(url.searchParams.get('branch'), manage));
        if (url.pathname === '/recipes') return response({ success: true, can_manage: manage, items: [product], ingredients, pagination: { page: 1, last_page: 1, total: 1 } });
        if (url.pathname === '/basic-status') return status ? status(call, calls.filter(row => row.path === '/basic-status').length) : response(previewData(call.query.branch, { can_apply: manage }));
        if (url.pathname === '/basic-apply') return apply ? apply(call) : response(committed(body));
        if (url.pathname === '/recipe-recover') return recover ? recover(call) : response({ success: true, found: false });
        throw new Error('Unexpected HTTP request: ' + url.pathname);
    };
    w.eval(read('public/dashboard/js/branch-stock.js'));
    w.eval(read('public/dashboard/js/branch-recipes.js'));
    const $ = selector => document.querySelector(selector);
    const count = endpoint => calls.filter(call => call.path === endpoint).length;
    const click = selector => { assert($(selector), 'missing ' + selector); $(selector).click(); };
    const forceClick = selector => $(selector).dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    const root = $('#branch-stock'), branch = $('[data-stock-branch]');
    return { dom, w, document, $, calls, count, click, forceClick, root, branch, errors, close: () => { assert.deepEqual(errors, [], 'no browser errors'); w.close(); } };
}

async function recipes(ctx) {
    ctx.click('[data-inventory-tab="recipes"]');
    await eventually(() => ctx.$('[data-recipe-product="101"]') && !ctx.$('[data-basic-preview]').disabled, 'recipe list and basic action must become available');
}

async function preview(ctx) {
    const before = ctx.count('/basic-status');
    ctx.click('[data-basic-preview]');
    await eventually(() => ctx.count('/basic-status') > before && ctx.$('[data-basic-dialog]').open && !ctx.$('[data-basic-confirm]').disabled, 'basic preview must open before confirmation');
}

(async () => {
    // The action belongs to the recipes panel, and no write occurs before an
    // explicit review of the current branch's counts and unmatched products.
    const held = deferred();
    const normal = setup({ apply: call => held.promise.then(() => response(committed(call.body))) });
    try {
        assert.equal(normal.$('[data-basic-preview]').textContent.trim(), 'استخدم الوصفة الأساسية');
        assert(normal.$('[data-basic-preview]').closest('[data-inventory-panel="recipes"]'));
        assert(normal.$('[data-inventory-panel="recipes"]').hidden);
        assert.equal(normal.count('/basic-apply'), 0);
        await recipes(normal);
        await preview(normal);
        const statusCall = normal.calls.find(call => call.path === '/basic-status');
        assert.equal(statusCall.query.branch, 'f:100', 'preview is scoped to the selected target branch');
        assert.equal(normal.count('/basic-apply'), 0, 'reviewing a preview never applies recipes');
        for (const key of ['changed', 'unchanged', 'skipped']) assert(normal.$('[data-basic-summary]').textContent.includes(String(summary[key])), 'preview renders ' + key + ' count');
        assert(normal.$('[data-basic-summary]').textContent.includes('المنصورة'), 'the review names its target branch');
        assert(normal.$('[data-basic-dialog]').textContent.includes('المحلة'), 'the review names its frozen source branch');
        assert(normal.$('[data-basic-skipped]').textContent.includes(hostileName));
        assert.equal(normal.$('[data-basic-skipped] img, [data-basic-skipped] script'), null, 'unmatched product data is rendered as text');
        assert.equal(normal.w.recipeUnsafe, undefined);
        normal.click('[data-basic-cancel]');
        assert(!normal.$('[data-basic-dialog]').open);
        assert.equal(normal.count('/basic-apply'), 0, 'cancel leaves branch recipes unchanged');
        await preview(normal);
        normal.click('[data-basic-confirm]');
        await eventually(() => normal.count('/basic-apply') === 1, 'confirmation posts one application');
        const posted = normal.calls.find(call => call.path === '/basic-apply');
        assert.equal(posted.method, 'POST');
        assert.equal(posted.body.branch, 'f:100');
        assert.equal(posted.body.preview_hash, 'preview-f:100');
        assert.match(posted.body.idempotency_key, /^[a-f\d-]{36}$/i);
        assert.equal(posted.options.headers['X-CSRF-TOKEN'], 'test-csrf');
        const stored = JSON.parse(normal.w.localStorage.getItem(pendingKey));
        assert.equal(stored.type, 'basic');
        assert.equal(stored.idempotency_key, posted.body.idempotency_key, 'pending operation is durable before the request completes');
        assert(normal.branch.disabled, 'the branch selector is locked while applying');
        assert(normal.$('[data-stock-save]').disabled, 'stock receipt writes share the recipe operation lock');
        const beforeBranch = new normal.w.CustomEvent('inventory:before-branch', { cancelable: true });
        assert.equal(normal.root.dispatchEvent(beforeBranch), false, 'a busy application prevents branch changes');
        normal.forceClick('[data-basic-confirm]');
        normal.forceClick('[data-basic-preview]');
        assert.equal(normal.count('/basic-apply'), 1, 'double clicks cannot duplicate an in-flight application');
        held.resolve();
        await eventually(() => normal.w.localStorage.getItem(pendingKey) === null && !normal.branch.disabled, 'successful application clears its operation lock');
        assert(normal.count('/stock-data') >= 1, 'successful recipe application refreshes branch stock guidance');
        assert(normal.count('/recipes') >= 2, 'successful application refreshes menu recipes');
        assert(!normal.$('[data-basic-dialog]').open);
        assert(!normal.$('[data-basic-result]').hidden, 'success retains a visible application report');
        assert(normal.$('[data-basic-result-skipped]').textContent.includes(hostileName));
        assert.equal(normal.$('[data-basic-result-skipped] img, [data-basic-result-skipped] script'), null, 'success report also renders skipped names safely');
    } finally { held.resolve(); normal.close(); }

    // Changing branches after a preview must never apply its old target/hash.
    const scoped = setup();
    try {
        await recipes(scoped);
        await preview(scoped);
        scoped.click('[data-basic-cancel]');
        scoped.branch.value = 'f:101';
        scoped.branch.dispatchEvent(new scoped.w.Event('change', { bubbles: true }));
        await eventually(() => scoped.calls.some(call => call.path === '/recipes' && call.query.branch === 'f:101') && !scoped.$('[data-basic-preview]').disabled, 'branch switch loads its own recipe list');
        await preview(scoped);
        assert.equal(scoped.calls.filter(call => call.path === '/basic-status').at(-1).query.branch, 'f:101');
        scoped.click('[data-basic-confirm]');
        await eventually(() => scoped.count('/basic-apply') === 1, 'new branch apply completes');
        const op = scoped.calls.find(call => call.path === '/basic-apply').body;
        assert.equal(op.branch, 'f:101');
        assert.equal(op.preview_hash, 'preview-f:101');
        await eventually(() => scoped.w.localStorage.getItem(pendingKey) === null, 'new branch operation clears');
    } finally { scoped.close(); }

    // A changed preview is a definitive conflict, then a new review; it cannot
    // silently retry with a new hash or replace recipes without confirmation.
    const conflict = setup({
        status: (call, number) => response(previewData(call.query.branch, { preview_hash: 'revision-' + number })),
        apply: () => response({ success: false, message: 'تغيرت وصفات الفرع؛ راجع المعاينة الجديدة.' }, 409),
    });
    try {
        await recipes(conflict);
        await preview(conflict);
        conflict.click('[data-basic-confirm]');
        await eventually(() => conflict.count('/recipe-recover') === 1 && conflict.w.localStorage.getItem(pendingKey) === null && !conflict.$('[data-basic-preview]').disabled, 'conflict resolves its pending operation');
        assert(!conflict.$('[data-basic-dialog]').open);
        conflict.forceClick('[data-basic-confirm]');
        assert.equal(conflict.count('/basic-apply'), 1, 'the conflicted preview can no longer be confirmed');
        await preview(conflict);
        assert.equal(conflict.count('/basic-status'), 2, 'a fresh click reloads the branch preview');
        assert.equal(conflict.count('/basic-apply'), 1, 'a refreshed preview requires a new confirmation');
        assert.equal(conflict.w.localStorage.getItem(pendingKey), null);
        assert.equal(conflict.calls.find(call => call.path === '/basic-apply').body.preview_hash, 'revision-1');
        assert.equal(conflict.calls.filter(call => call.path === '/basic-status').at(-1).query.branch, 'f:100');
        conflict.click('[data-basic-cancel]');
    } finally { conflict.close(); }

    // Simulate the server committing successfully while its HTTP reply is lost.
    // Retrying must recover the same ledger entry, never apply it a second time.
    let committedOp;
    const lost = setup({
        apply: call => { committedOp = call.body; throw new Error('Connection lost after commit'); },
        recover: call => { assert.equal(call.query.idempotency_key, committedOp.idempotency_key); assert.equal(call.query.branch, committedOp.branch); return response({ ...committed(committedOp), found: true }); },
    });
    try {
        await recipes(lost);
        await preview(lost);
        lost.click('[data-basic-confirm]');
        await eventually(() => !lost.$('[data-recipe-retry]').hidden && !lost.$('[data-recipe-retry]').disabled, 'lost reply exposes recovery');
        assert.equal(lost.count('/basic-apply'), 1);
        assert.equal(JSON.parse(lost.w.localStorage.getItem(pendingKey)).idempotency_key, committedOp.idempotency_key);
        assert(lost.branch.disabled, 'uncertain operation keeps the target branch locked');
        lost.click('[data-recipe-retry]');
        await eventually(() => lost.w.localStorage.getItem(pendingKey) === null && !lost.branch.disabled, 'recovery releases the saved operation');
        assert.equal(lost.count('/recipe-recover'), 1);
        assert.equal(lost.count('/basic-apply'), 1, 'recovering committed basic recipes never reapplies them');
    } finally { lost.close(); }

    const restoredOp = { type: 'basic', branch: 'f:101', preview_hash: 'restored-hash', idempotency_key: randomUUID() };
    const restored = setup({ stored: restoredOp, recover: call => { assert.equal(call.query.branch, 'f:101'); return response({ ...committed(restoredOp), found: true }); } });
    try {
        await eventually(() => restored.count('/recipe-recover') === 1 && restored.w.localStorage.getItem(pendingKey) === null, 'page reload recovers pending basic recipe operation');
        assert.equal(restored.branch.value, 'f:101');
        assert.equal(restored.count('/basic-apply'), 0, 'startup recovery makes no write');
        assert.equal(restored.count('/recipe-save'), 0, 'basic operation is never sent to the single-recipe endpoint');
    } finally { restored.close(); }

    const absentOp = { type: 'basic', branch: 'f:101', preview_hash: 'unconfirmed-preview', idempotency_key: randomUUID() };
    const absent = setup({ stored: absentOp });
    try {
        await eventually(() => absent.count('/recipe-recover') === 1 && !absent.$('[data-recipe-retry]').disabled, 'unconfirmed startup operation offers explicit recovery');
        assert.equal(absent.count('/basic-apply'), 0, 'a missing ledger entry at startup never writes automatically');
        assert(absent.branch.disabled);
        absent.click('[data-recipe-retry]');
        await eventually(() => absent.w.localStorage.getItem(pendingKey) === null, 'explicit recovery can finish the original operation');
        assert.equal(absent.count('/basic-apply'), 1);
        assert.deepEqual(absent.calls.find(call => call.path === '/basic-apply').body, absentOp, 'retry reuses the frozen target, preview and operation key');
        assert.equal(absent.count('/basic-status'), 0, 'retry does not silently replace the reviewed preview');
    } finally { absent.close(); }

    const mismatched = setup({ stored: restoredOp, recover: () => response({ ...committed({ ...restoredOp, preview_hash: 'another-preview' }), found: true }) });
    try {
        await eventually(() => mismatched.count('/recipe-recover') === 1 && !mismatched.$('[data-recipe-retry]').hidden && !mismatched.$('[data-recipe-retry]').disabled, 'mismatched recovery is treated as uncertain');
        assert.equal(JSON.parse(mismatched.w.localStorage.getItem(pendingKey)).preview_hash, restoredOp.preview_hash, 'a different result never consumes the original pending operation');
        assert(mismatched.branch.disabled);
        assert.equal(mismatched.count('/basic-apply'), 0);
        assert(mismatched.$('[data-basic-result]').hidden, 'an unrelated recovered result is never reported as successful');
    } finally { mismatched.close(); }

    const unauthorized = setup({ manage: false });
    try {
        unauthorized.click('[data-inventory-tab="recipes"]');
        await eventually(() => unauthorized.$('[data-recipe-product="101"]'), 'read-only recipe list loads');
        assert(unauthorized.$('[data-basic-preview]').hidden, 'read-only branch accounts do not see an apply action');
        unauthorized.forceClick('[data-basic-preview]');
        unauthorized.forceClick('[data-basic-confirm]');
        assert.equal(unauthorized.count('/basic-status'), 0);
        assert.equal(unauthorized.count('/basic-apply'), 0);
    } finally { unauthorized.close(); }

    // A stock refresh already in flight must also block the recipe action; this
    // exercises the two production scripts' shared events in both directions.
    const stockHeld = deferred();
    const stockBusy = setup({ stock: call => stockHeld.promise.then(() => response(stockData(call.query.branch))) });
    try {
        await recipes(stockBusy);
        stockBusy.click('[data-inventory-tab="goods"]');
        stockBusy.click('[data-stock-refresh]');
        assert.equal(stockBusy.count('/stock-data'), 1);
        assert(stockBusy.$('[data-basic-preview]').disabled, 'pending stock request locks basic preview');
        stockBusy.forceClick('[data-basic-preview]');
        stockBusy.forceClick('[data-basic-confirm]');
        assert.equal(stockBusy.count('/basic-status'), 0, 'even a dispatched click cannot preview while stock is busy');
        assert.equal(stockBusy.count('/basic-apply'), 0);
        stockHeld.resolve();
        await eventually(() => !stockBusy.$('[data-basic-preview]').disabled && !stockBusy.branch.disabled, 'stock completion releases recipe actions');
    } finally { stockHeld.resolve(); stockBusy.close(); }

    const noChange = setup({ status: call => response(previewData(call.query.branch, { summary: { matched: 8, changed: 0, unchanged: 8, skipped: 2 } })) });
    try {
        await recipes(noChange);
        noChange.click('[data-basic-preview]');
        await eventually(() => noChange.$('[data-basic-dialog]').open, 'unchanged preview remains reviewable');
        assert(noChange.$('[data-basic-confirm]').disabled, 'an unchanged branch needs no application');
        noChange.forceClick('[data-basic-confirm]');
        assert.equal(noChange.count('/basic-apply'), 0, 'unchanged preview cannot write through a dispatched click');
        noChange.click('[data-basic-cancel]');
    } finally { noChange.close(); }

    console.log('Basic recipe DOM integration passed: real Blade and page scripts, target branch preview/confirmation, safe skipped names, shared locks, conflict review, lost acknowledgement and reload recovery, mismatched ledger protection, frozen retries, and permission guards.');
})().catch(error => { console.error(error); process.exitCode = 1; });
