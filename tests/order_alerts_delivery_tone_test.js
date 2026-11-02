'use strict';

// A delivery order sent to the branch (phone:<ticket>) needs nothing from the branch (it prints there by itself): it
// chimes once with its own tone and is received by itself. Every other order rings until handled, as before.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'public/dashboard/js/dashboard-order-alerts.js'), 'utf8');

function browser() {
    const clock = {now: 1_800_000_000_000}, timers = [], local = new Map();
    let timerId = 0;
    function open(dataset = {}, sessionValues = {}) {
        const listeners = {}, played = [], sounds = [];
        class FakeAudio {
            constructor(src) { this.src = src; this.loop = false; this.currentTime = 0; this.paused = true; this.listeners = {}; sounds.push(this); }
            play() { this.paused = false; played.push(this.src); if (this.broken) { this.fail(); return Promise.reject(Object.assign(new Error('x'), {name: 'NotSupportedError'})); } return Promise.resolve(); }
            pause() { this.paused = true; }
            addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
            fail() { (this.listeners.error || []).forEach(callback => callback({})); }
        }
        const session = new Map(Object.entries(sessionValues));
        const sandbox = {
            document: {
                currentScript: {dataset: Object.assign({soundActor: '7', soundUrl: '/sounds/order.wav'}, dataset)},
                getElementById: () => null, querySelector: () => null, querySelectorAll: () => [],
                createElement: () => ({dataset: {}, style: {}, addEventListener() {}}), body: {appendChild() {}},
                addEventListener(type, callback) { (listeners['document:' + type] ||= []).push(callback); }, dispatchEvent() {},
            },
            console, JSON, Map, Set, Array, Math, Promise, Error, String, Number, Object, AbortController, URL,
            Date: class extends Date { static now() { return clock.now; } },
            CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
            setTimeout: (fn, delay) => { const id = ++timerId; timers.push({id, due: clock.now + (delay || 0), fn}); return id; },
            clearTimeout: id => { const index = timers.findIndex(timer => timer.id === id); if (index >= 0) timers.splice(index, 1); },
            setInterval: () => 0, clearInterval() {},
            localStorage: {getItem: key => local.has(key) ? local.get(key) : null, setItem: (key, value) => local.set(key, String(value))},
            sessionStorage: {getItem: key => session.has(key) ? session.get(key) : null, setItem: (key, value) => session.set(key, String(value))},
            Audio: FakeAudio,
            addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
        };
        sandbox.window = sandbox;
        vm.createContext(sandbox);
        vm.runInContext(source, sandbox);
        const sound = src => sounds.filter(item => item.src === src);
        const ringing = () => sounds.filter(item => !item.paused && item.loop).map(item => item.src);
        const click = target => (listeners['document:click'] || []).forEach(callback => callback({target}));
        return {api: sandbox.DashboardOrderAlerts, sounds, sound, ringing, played, click,
            pending: () => sandbox.DashboardOrderAlerts.state().pending.slice().sort()};
    }
    async function flush() { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); }
    async function advance(ms) {
        const end = clock.now + ms;
        for (;;) {
            const due = timers.filter(timer => timer.due <= end).sort((a, b) => a.due - b.due || a.id - b.id)[0];
            if (!due) break;
            clock.now = Math.max(clock.now, due.due); timers.splice(timers.indexOf(due), 1);
            due.fn(); await flush();
        }
        clock.now = end; await flush();
    }
    return {open, flush, advance};
}

const cases = [];
const test = (name, fn) => cases.push([name, fn]);
const TONE = '/sounds/go-delivery-order.wav';

test('a delivery order sent to the branch chimes once with its tone, shows for a moment, then is received by itself', async () => {
    const b = browser(), tab = b.open();
    tab.api.notify('phone:41', 'phone-receiver');
    await b.flush();
    assert.deepStrictEqual(tab.played, [TONE], 'One chime with the delivery tone');
    assert.strictEqual(tab.sound(TONE)[0].loop, false, 'Never repeated');
    assert.deepStrictEqual(tab.ringing(), []);
    assert.deepStrictEqual(tab.pending(), ['phone:41'], 'Shown to the branch for a moment');
    await b.advance(15000);
    assert.deepStrictEqual(tab.pending(), [], 'Received by itself: nobody has to press anything');
    assert.deepStrictEqual(tab.played, [TONE], 'And nothing more sounds');
    tab.api.notify('phone:41', 'phone-receiver');
    await b.flush();
    assert.deepStrictEqual(tab.played, [TONE], 'The same order never chimes twice');
});

test('another order waiting still rings until handled with the usual sound', async () => {
    const b = browser(), tab = b.open();
    tab.api.notify('phone:41', 'phone-receiver');
    tab.api.notify('website:9');
    await b.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/order.wav']);
    await b.advance(15000);
    assert.deepStrictEqual(tab.ringing(), ['/sounds/order.wav'], 'The delivery order going does not stop the other');
    tab.api.acknowledge('website:9');
    await b.flush();
    assert.deepStrictEqual(tab.ringing(), []);
    assert.strictEqual(tab.played.filter(src => src === TONE).length, 1);
});

test('one chime per order in a browser: other tabs and a reload stay quiet', async () => {
    const b = browser(), first = b.open(), second = b.open();
    first.api.notify('phone:41', 'phone-receiver');
    second.api.notify('phone:41', 'phone-receiver');
    await b.flush();
    assert.strictEqual(first.played.length + second.played.length, 1);
    // A page reloaded with the order still shown never restores it as ringing.
    const reloaded = b.open({}, {'dashboardOrderSound:7:pending': JSON.stringify(['phone:41', 'website:9'])});
    await b.flush();
    assert.deepStrictEqual(reloaded.pending(), ['website:9']);
});

test('the sound button mutes the chime; the order is still received by itself', async () => {
    const b = browser(), tab = b.open();
    // The first press turns the sound on (and tests it), the second mutes it.
    tab.click({closest: selector => (selector === '#toggle-sound' ? {} : null)});
    await b.flush();
    tab.click({closest: selector => (selector === '#toggle-sound' ? {} : null)});
    await b.flush();
    assert.strictEqual(tab.api.state().enabled, false);
    const before = tab.played.length;
    tab.api.notify('phone:5', 'phone-receiver');
    await b.advance(15000);
    assert.strictEqual(tab.played.length, before, 'Muted');
    assert.deepStrictEqual(tab.pending(), []);
});

test('a tone that cannot load still sounds: the usual sound, once', async () => {
    const b = browser(), tab = b.open({deliverySoundUrl: '/sounds/missing.wav'});
    // The tone's file answers with an error.
    const make = tab.sounds.length;
    tab.api.notify('phone:8', 'phone-receiver');
    const tone = tab.sounds.slice(make).find(item => item.src === '/sounds/missing.wav');
    tone.fail();
    await b.flush();
    assert.deepStrictEqual(tab.played.filter(src => src === '/sounds/order.wav').length, 1);
    tone.fail();
    await b.flush();
    assert.deepStrictEqual(tab.played.filter(src => src === '/sounds/order.wav').length, 1, 'Once');
});

(async () => {
    for (const [name, fn] of cases) {
        try { await fn(); } catch (error) { console.error('FAIL ' + name); throw error; }
    }
    console.log('ORDER_ALERT_DELIVERY_TONE_TESTS=' + cases.length + ' PASS');
})().catch(error => { console.error(error); process.exit(1); });
