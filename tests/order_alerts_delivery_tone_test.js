'use strict';

// A delivery order sent to the branch (phone:<ticket>) rings with its own tone; any other order rings as before.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'public/dashboard/js/dashboard-order-alerts.js'), 'utf8');

function open(dataset = {}) {
    const timers = [], listeners = {}, played = [];
    let now = 1_800_000_000_000, timerId = 0;
    class FakeAudio {
        constructor(src) { this.src = src; this.loop = false; this.currentTime = 0; this.paused = true; this.listeners = {}; }
        play() { this.paused = false; played.push(this.src); return Promise.resolve(); }
        pause() { this.paused = true; }
        addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
        fail() { (this.listeners.error || []).forEach(callback => callback({})); }
    }
    const store = new Map(), session = new Map(), sounds = [];
    const sandbox = {
        document: {
            currentScript: {dataset: Object.assign({soundActor: '7', soundUrl: '/sounds/order.wav'}, dataset)},
            getElementById: () => null, querySelector: () => null, querySelectorAll: () => [],
            createElement: () => ({dataset: {}, style: {}, addEventListener() {}}), body: {appendChild() {}},
            addEventListener(type, callback) { (listeners['document:' + type] ||= []).push(callback); }, dispatchEvent() {},
        },
        console, JSON, Map, Set, Array, Math, Promise, Error, String, Number, AbortController, URL,
        Date: class extends Date { static now() { return now; } },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        setTimeout: (fn, delay) => { const id = ++timerId; timers.push({id, due: now + (delay || 0), fn}); return id; },
        clearTimeout: id => { const index = timers.findIndex(timer => timer.id === id); if (index >= 0) timers.splice(index, 1); },
        setInterval: () => 0, clearInterval() {},
        localStorage: {getItem: key => store.has(key) ? store.get(key) : null, setItem: (key, value) => store.set(key, String(value))},
        sessionStorage: {getItem: key => session.has(key) ? session.get(key) : null, setItem: (key, value) => session.set(key, String(value))},
        Audio: class extends FakeAudio { constructor(src) { super(src); sounds.push(this); } },
        addEventListener(type, callback) { (listeners[type] ||= []).push(callback); },
    };
    sandbox.window = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox);
    const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(resolve => setImmediate(resolve)); };
    const sound = src => sounds.find(item => item.src === src);
    const ringing = () => sounds.filter(item => !item.paused).map(item => item.src);
    const click = target => (listeners['document:click'] || []).forEach(callback => callback({target}));
    return {api: sandbox.DashboardOrderAlerts, sound, ringing, played, flush, click, advance(ms) { now += ms; }};
}

const cases = [];
const test = (name, fn) => cases.push([name, fn]);

test('a delivery order sent to the branch rings with the delivery tone, again and again until received', async () => {
    const tab = open();
    tab.api.notify('phone:41', 'phone-receiver');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/go-delivery-order.wav']);
    assert.strictEqual(tab.sound('/sounds/go-delivery-order.wav').loop, true, 'It repeats while the order waits');
    tab.api.acknowledge('phone:41');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), [], 'Received: silent');
});

test('another order waiting rings with the usual sound, and the delivery tone comes back once only deliveries wait', async () => {
    const tab = open();
    tab.api.notify('phone:41', 'phone-receiver');
    await tab.flush();
    tab.api.notify('website:9');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/order.wav'], 'One sound at a time: the usual one for any other order');
    tab.api.acknowledge('website:9');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/go-delivery-order.wav']);
    tab.api.acknowledge('phone:41');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), []);
});

test('other orders alone ring exactly as before', async () => {
    const tab = open();
    tab.api.notify('website:9');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/order.wav']);
    assert.ok(!tab.played.includes('/sounds/go-delivery-order.wav'));
});

test('the page may name the delivery tone; muted means silent for deliveries too', async () => {
    const tab = open({deliverySoundUrl: '/sounds/custom.wav'});
    tab.api.notify('phone:7', 'phone-receiver');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/custom.wav']);
    tab.click({closest: selector => (selector === '#toggle-sound' ? {} : null)});
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), [], 'The sound button mutes the delivery tone as well');
});

test('a delivery tone that cannot load rings with the usual sound instead', async () => {
    const tab = open();
    tab.api.notify('phone:41', 'phone-receiver');
    await tab.flush();
    tab.sound('/sounds/go-delivery-order.wav').fail();
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), ['/sounds/order.wav'], 'Never silent');
    tab.api.acknowledge('phone:41');
    await tab.flush();
    assert.deepStrictEqual(tab.ringing(), []);
});

(async () => {
    for (const [name, fn] of cases) {
        try { await fn(); } catch (error) { console.error('FAIL ' + name); throw error; }
    }
    console.log('ORDER_ALERT_DELIVERY_TONE_TESTS=' + cases.length + ' PASS');
})().catch(error => { console.error(error); process.exit(1); });
