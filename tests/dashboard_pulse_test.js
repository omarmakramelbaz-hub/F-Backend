'use strict';

// The dashboard's repeated checks travel together in one pulse, each getting back its own answer as if it went alone.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'public/dashboard/js/dashboard-pulse.js'), 'utf8');

function page(answer) {
    const clock = {now: 1_800_000_000_000}, timers = [], calls = [];
    let timerId = 0;
    class FakeDate extends Date { static now() { return clock.now; } }
    const fetch = (input, options) => {
        const url = new URL(String(input), 'https://dash.test');
        calls.push({url, options});
        return Promise.resolve(answer(url, options));
    };
    const window = {fetch, Response, URL, AbortController, DOMException};
    const sandbox = {
        window, document: {currentScript: {dataset: {pulseUrl: '/admin/dashboard/pulse'}}}, location: {href: 'https://dash.test/admin/dashboard', origin: 'https://dash.test'},
        Date: FakeDate, URL, Response, AbortController, DOMException, Promise, Error, Math, Number, String, Array, Object, JSON, Infinity,
        setTimeout: (fn, delay) => { const id = ++timerId; timers.push({id, due: clock.now + Math.max(0, delay || 0), fn}); return id; },
        clearTimeout: id => { const index = timers.findIndex(timer => timer.id === id); if (index >= 0) timers.splice(index, 1); },
    };
    vm.runInNewContext(source, sandbox);
    async function settle() { for (let i = 0; i < 20; i++) await new Promise(resolve => setImmediate(resolve)); }
    async function advance(ms) {
        const end = clock.now + ms;
        for (;;) {
            await settle();
            timers.sort((a, b) => a.due - b.due);
            const next = timers[0];
            if (!next || next.due > end) break;
            timers.shift(); clock.now = next.due; next.fn();
        }
        clock.now = end; await settle();
    }
    return {pulse: window.GoPulse, calls, advance, clock};
}

const json = (data, status = 200) => new Response(JSON.stringify(data), {status, headers: {'Content-Type': 'application/json'}});
const pulseAnswer = data => new Response(JSON.stringify(data), {status: 200, headers: {'Content-Type': 'text/plain; charset=UTF-8'}});
const checkHeaders = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};

const tests = {
    async 'checks asked together go in one pulse and each gets its own status and body'() {
        const {pulse, calls, advance} = page(url => {
            assert.strictEqual(url.pathname, '/admin/dashboard/pulse');
            assert.strictEqual(url.searchParams.get('r[0][u]'), '/admin/getOrders?alerts_only=1');
            assert.strictEqual(url.searchParams.get('r[0][a]'), 'application/json');
            assert.strictEqual(url.searchParams.get('r[0][x]'), '1');
            assert.strictEqual(url.searchParams.get('r[1][u]'), '/admin/branch-expenses/pending-count?branch=f%3A301');
            return pulseAnswer({answers: [{status: 200, type: 'application/json', body: '{"count":3}'}, {status: 503, type: 'application/json', body: '{"success":false}'}]});
        });
        const first = pulse.fetch('/admin/getOrders?alerts_only=1', checkHeaders);
        const second = pulse.fetch('https://dash.test/admin/branch-expenses/pending-count?branch=f%3A301', checkHeaders);
        await advance(100);
        assert.strictEqual(calls.length, 1, 'One request for both');
        const [a, b] = await Promise.all([first, second]);
        assert.deepStrictEqual([a.ok, a.status, await a.json()], [true, 200, {count: 3}]);
        assert.deepStrictEqual([b.ok, b.status, b.headers.get('Content-Type'), await b.json()], [false, 503, 'application/json', {success: false}]);
    },

    async 'a lone check, a write, another address or another site go as themselves'() {
        const {pulse, calls, advance} = page(() => json({ok: true}));
        await Promise.all([pulse.fetch('/admin/phone-orders/print-jobs/claim', {method: 'POST', body: '{}'}), pulse.fetch('/admin/employees/data'),
            pulse.fetch('https://other.test/admin/getOrders')]);
        assert.deepStrictEqual(calls.map(call => call.url.pathname), ['/admin/phone-orders/print-jobs/claim', '/admin/employees/data', '/admin/getOrders']);
        const lone = pulse.fetch('/admin/phone-orders/late', checkHeaders);
        await advance(100);
        await lone;
        assert.strictEqual(calls[3].url.pathname, '/admin/phone-orders/late', 'Nothing to gain wrapping one check');
    },

    async 'pulses keep 8 s apart, and a check waits at most 4 s'() {
        const {pulse, calls, advance} = page(url => url.pathname === '/admin/dashboard/pulse'
            ? pulseAnswer({answers: [...url.searchParams.keys()].filter(key => key.endsWith('[u]')).map(() => ({status: 200, type: 'application/json', body: '{}'}))})
            : json({}));
        pulse.fetch('/admin/getOrders', checkHeaders); pulse.fetch('/admin/phone-orders/late', checkHeaders);
        await advance(100);
        assert.strictEqual(calls.length, 1);
        pulse.fetch('/admin/getOrders', checkHeaders); pulse.fetch('/admin/dashboard-inbox/notifications', checkHeaders);
        await advance(3800);
        assert.strictEqual(calls.length, 1, 'Waiting for the next pulse');
        await advance(300);
        assert.strictEqual(calls.length, 2, 'Never more than 4 s after it was asked');
    },

    async 'a pulse answered as JSON (a copy the desktop program kept) is not trusted: the checks go as themselves'() {
        const {pulse, calls, advance} = page(url => url.pathname === '/admin/dashboard/pulse'
            ? json({answers: [{status: 200, type: 'application/json', body: '{"old":true}'}, {status: 200, type: 'application/json', body: '{"old":true}'}]})
            : json({fresh: url.pathname}));
        const answers = [pulse.fetch('/admin/getOrders', checkHeaders), pulse.fetch('/admin/phone-orders/late', checkHeaders)];
        await advance(100);
        assert.deepStrictEqual(await Promise.all(answers.map(p => p.then(r => r.json()))), [{fresh: '/admin/getOrders'}, {fresh: '/admin/phone-orders/late'}]);
    },

    async 'a pulse that fails sends its checks as themselves, then rests'() {
        let refuse = true;
        const {pulse, calls, advance} = page(url => {
            if (url.pathname === '/admin/dashboard/pulse') return refuse ? new Response('<html>sign in</html>', {status: 200, headers: {'Content-Type': 'text/html'}}) : pulseAnswer({answers: []});
            return json({path: url.pathname});
        });
        const answers = [pulse.fetch('/admin/getOrders', checkHeaders), pulse.fetch('/admin/phone-orders/late', checkHeaders)];
        await advance(100);
        assert.deepStrictEqual(await Promise.all(answers.map(p => p.then(r => r.json()))), [{path: '/admin/getOrders'}, {path: '/admin/phone-orders/late'}]);
        assert.deepStrictEqual(calls.map(call => call.url.pathname), ['/admin/dashboard/pulse', '/admin/getOrders', '/admin/phone-orders/late']);
        refuse = false;
        await Promise.all([pulse.fetch('/admin/getOrders', checkHeaders), pulse.fetch('/admin/phone-orders/late', checkHeaders)]);
        assert.strictEqual(calls.length, 5, 'Resting: straight to their own requests');
    },

    async 'a redirect (a sign-in page) is answered by the check\'s own request'() {
        const {pulse, calls, advance} = page(url => url.pathname === '/admin/dashboard/pulse'
            ? pulseAnswer({answers: [{status: 302, type: 'text/html', location: '/admin/login'}, {status: 200, type: 'application/json', body: '{"late":[]}'}]})
            : new Response('<html>login</html>', {status: 200, headers: {'Content-Type': 'text/html'}}));
        const first = pulse.fetch('/admin/getOrders', checkHeaders), second = pulse.fetch('/admin/phone-orders/late', checkHeaders);
        await advance(100);
        assert.strictEqual(await (await first).text(), '<html>login</html>');
        assert.deepStrictEqual(await (await second).json(), {late: []});
        assert.deepStrictEqual(calls.map(call => call.url.pathname), ['/admin/dashboard/pulse', '/admin/getOrders']);
    },

    async 'a check given up before its pulse is aborted and not sent'() {
        const {pulse, calls, advance} = page(url => pulseAnswer({answers: [...url.searchParams.keys()].filter(key => key.endsWith('[u]')).map(() => ({status: 200, type: 'application/json', body: '{}'}))}));
        const controller = new AbortController();
        const given = pulse.fetch('/admin/getOrders', {...checkHeaders, signal: controller.signal});
        const kept = pulse.fetch('/admin/phone-orders/late', checkHeaders);
        controller.abort();
        await assert.rejects(given, error => error.name === 'AbortError');
        await advance(100);
        await kept;
        assert.deepStrictEqual(calls.map(call => call.url.pathname), ['/admin/phone-orders/late'], 'Only the check still wanted, alone');
    },
};

(async () => {
    for (const [name, test] of Object.entries(tests)) {
        try { await test(); } catch (error) { console.error('FAIL', name); throw error; }
    }
    console.log(`DASHBOARD_PULSE_TESTS=${Object.keys(tests).length} PASS`);
})().catch(() => process.exit(1));
