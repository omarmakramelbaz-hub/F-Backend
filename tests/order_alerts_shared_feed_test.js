'use strict';

// Several dashboard tabs of one user share a single order-alert poll through localStorage.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'public/dashboard/js/dashboard-order-alerts.js'), 'utf8');

function browser() {
    const clock = {now: 1_800_000_000_000}, timers = [], tabs = [], server = {keys: [], calls: [], hold: null};
    let timerId = 0, storageBroken = false;
    const local = new Map();
    const localStorage = {
        getItem(key) { if (storageBroken) throw new Error('blocked'); return local.has(key) ? local.get(key) : null; },
        setItem(key, value) {
            if (storageBroken) throw new Error('blocked');
            const previous = local.has(key) ? local.get(key) : null; value = String(value); local.set(key, value);
            if (previous === value) return;
            // Browsers deliver storage events to the other tabs only, as a separate task.
            tabs.filter(tab => tab.open).forEach(tab => {
                if (tab === writer.current) return;
                setImmediate(() => { if (tab.open) tab.emit('storage', {key, newValue: value}); });
            });
        },
    };
    const writer = {current: null};
    function open(options = {}) {
        const listeners = {}, session = new Map(), elements = {};
        const tab = {open: true, frozen: false, listeners, fetches: 0,
            emit(type, event) { (listeners[type] || []).slice().forEach(callback => { writer.current = tab; try { callback(event); } finally { writer.current = null; } }); }};
        if (options.board) elements['order-board'] = {id: 'order-board'};
        const schedule = (fn, delay, repeat) => { const id = ++timerId; timers.push({id, tab, due: clock.now + Math.max(0, delay || 0), fn, repeat: repeat ? Math.max(1, delay) : 0}); return id; };
        const cancel = id => { const index = timers.findIndex(timer => timer.id === id); if (index >= 0) timers.splice(index, 1); };
        class FakeDate extends Date { static now() { return clock.now; } }
        const document = {
            currentScript: {dataset: {soundActor: '7', soundFeed: '/admin/getOrders?alerts_only=1', soundUrl: '/sound.wav'}},
            getElementById: id => elements[id] || null, querySelector: () => null, querySelectorAll: () => [],
            createElement: () => ({dataset: {}, style: {}, addEventListener() {}}), body: {appendChild() {}},
            addEventListener() {}, dispatchEvent() {},
        };
        const sandbox = {
            document, console, JSON, Map, Set, Array, Math, Promise, Error, String, Number, AbortController, URL,
            Date: FakeDate, CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
            setTimeout: (fn, delay) => schedule(fn, delay, false), clearTimeout: cancel,
            setInterval: (fn, delay) => schedule(fn, delay, true), clearInterval: cancel,
            localStorage: {getItem: key => localStorage.getItem(key), setItem: (key, value) => { writer.current = tab; try { localStorage.setItem(key, value); } finally { writer.current = null; } }},
            sessionStorage: {getItem: key => session.has(key) ? session.get(key) : null, setItem: (key, value) => session.set(key, String(value))},
            Audio: class { constructor() { this.loop = false; this.currentTime = 0; } play() { return Promise.resolve(); } pause() {} },
            addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
            fetch: async (url, init) => {
                tab.fetches++; server.calls.push({tab, at: clock.now});
                if (server.hold) await server.hold.promise;
                if (init.signal.aborted) throw Object.assign(new Error('aborted'), {name: 'AbortError'});
                const keys = server.keys.slice();
                return {ok: true, json: async () => ({pending_alert_keys: keys})};
            },
        };
        sandbox.window = sandbox;
        vm.createContext(sandbox);
        tabs.push(tab);
        vm.runInContext(source, sandbox);
        tab.api = sandbox.DashboardOrderAlerts;
        tab.close = () => { tab.emit('pagehide', {}); tab.open = false; for (let i = timers.length - 1; i >= 0; i--) if (timers[i].tab === tab) timers.splice(i, 1); };
        return tab;
    }
    async function flush() { for (let i = 0; i < 6; i++) await new Promise(resolve => setImmediate(resolve)); }
    async function advance(ms) {
        const end = clock.now + ms;
        for (;;) {
            const runnable = timers.filter(timer => !timer.tab.frozen && timer.due <= end).sort((a, b) => a.due - b.due || a.id - b.id);
            if (!runnable.length) break;
            const timer = runnable[0];
            clock.now = Math.max(clock.now, timer.due);
            if (timer.repeat) timer.due = clock.now + timer.repeat; else timers.splice(timers.indexOf(timer), 1);
            timer.fn(); await flush();
        }
        clock.now = end; await flush();
    }
    return {open, advance, flush, server, clock, breakStorage() { storageBroken = true; }, store(key, value) { local.set(key, value); }};
}

const pending = tab => tab.api.state().pending.slice().sort();
const cases = [];
const test = (name, fn) => cases.push([name, fn]);

test('one tab polls while every tab learns the pending orders', async () => {
    const b = browser(); b.server.keys = ['legacy:11', 'store:5'];
    const first = b.open(), second = b.open(), third = b.open();
    await b.advance(1000);
    assert.strictEqual(b.server.calls.length, 1, 'Three open tabs make one request');
    [first, second, third].forEach(tab => assert.deepStrictEqual(pending(tab), ['legacy:11', 'store:5']));
    await b.advance(30000);
    assert.strictEqual(b.server.calls.length, 4, 'Each 10 second cycle still makes one request in total');
    assert(b.server.calls.every(call => call.tab === first), 'The first tab keeps leading while it is open');
});

test('orders handled on the server stop ringing in every tab', async () => {
    const b = browser(); b.server.keys = ['legacy:11', 'store:5'];
    const first = b.open(), second = b.open();
    await b.advance(1000);
    b.server.keys = ['store:5'];
    await b.advance(10000);
    assert.deepStrictEqual(pending(first), ['store:5']);
    assert.deepStrictEqual(pending(second), ['store:5']);
});

test('another tab takes over when the polling tab closes', async () => {
    const b = browser(); b.server.keys = ['legacy:11'];
    const first = b.open(), second = b.open();
    await b.advance(1000);
    first.close();
    b.server.keys = ['legacy:11', 'service:3'];
    await b.advance(10500);
    assert(second.fetches >= 1, 'The remaining tab polls after the leader closes');
    assert.deepStrictEqual(pending(second), ['legacy:11', 'service:3']);
});

test('a frozen polling tab is replaced after its lease expires', async () => {
    const b = browser(); b.server.keys = ['legacy:11'];
    const first = b.open(), second = b.open();
    await b.advance(1000);
    first.frozen = true; b.server.keys = ['legacy:11', 'partner_service:8'];
    await b.advance(31000);
    assert(second.fetches >= 1, 'A tab whose timers stopped does not block the others');
    assert.deepStrictEqual(pending(second), ['legacy:11', 'partner_service:8']);
});

test('an order acknowledged while the shared request is in flight is not resurrected', async () => {
    const b = browser(); b.server.keys = ['legacy:11', 'store:5'];
    const first = b.open(), second = b.open();
    await b.advance(1000);
    let release; b.server.hold = {promise: new Promise(resolve => { release = resolve; })};
    await b.advance(10000);
    assert.strictEqual(b.server.calls.length, 2, 'The next shared request has started');
    second.api.acknowledge('legacy:11');
    await b.flush();
    release(); b.server.hold = null;
    await b.advance(100);
    assert.deepStrictEqual(pending(second), ['store:5'], 'The tab that handled the order keeps it handled');
    assert.deepStrictEqual(pending(first), ['store:5'], 'The polling tab also honours the newer acknowledgement');
});

test('a lease left far in the future by a clock change does not block polling', async () => {
    const b = browser(); b.server.keys = ['legacy:11'];
    b.store('dashboardOrderSound:7:feed', JSON.stringify({tab: 'gone', until: b.clock.now + 3_600_000}));
    const tab = b.open();
    await b.advance(1000);
    assert.strictEqual(tab.fetches, 1, 'The tab claims the feed despite the distant lease');
    assert.deepStrictEqual(pending(tab), ['legacy:11']);
});

test('the order board page keeps its own feed', async () => {
    const b = browser(); b.server.keys = ['legacy:11'];
    const board = b.open({board: true}), other = b.open();
    await b.advance(1000);
    assert.strictEqual(board.fetches, 0, 'The mounted board does not use the alert feed');
    assert.strictEqual(other.fetches, 1, 'Another page leads the alert feed');
    assert.deepStrictEqual(pending(board), [], 'The board ignores shared results and relies on its own polling');
});

test('without localStorage every tab polls for itself', async () => {
    const b = browser(); b.server.keys = ['legacy:11'];
    b.breakStorage();
    const first = b.open(), second = b.open();
    await b.advance(1000);
    assert.strictEqual(first.fetches, 1); assert.strictEqual(second.fetches, 1);
    assert.deepStrictEqual(pending(second), ['legacy:11']);
});

(async () => {
    for (const [name, fn] of cases) {
        try { await fn(); } catch (error) { console.error('FAIL ' + name); throw error; }
    }
    console.log('ORDER_ALERT_SHARED_FEED_TESTS=' + cases.length + ' PASS');
})().catch(error => { console.error(error); process.exit(1); });
