const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/dashboard-order-alerts.js'), 'utf8');

function setup(initial = {}, shared, session = new Map()) {
    const storage = shared || new Map(Object.entries(initial)), nodes = new Map(), intervals = [], timers = [], instances = [];
    const docListeners = new Map(), windowListeners = new Map();
    function element(id) {
        const classes = new Set();
        return { id, dataset: {}, style: {}, hidden: false, children: [], attributes: {},
            classList: { toggle(name, yes) { if (yes) classes.add(name); else classes.delete(name); } },
            setAttribute(name, value) { this.attributes[name] = value; },
            addEventListener(name, handler) { this[name] = handler; },
            appendChild(child) { this.children.push(child); },
            replaceChildren(...children) { this.children = children; },
            closest(selector) { return selector.includes('#toggle-sound') && this.id === 'toggle-sound' ? this : null; }
        };
    }
    const button = element('toggle-sound'); nodes.set(button.id, button); nodes.set('sound-icon', element('sound-icon'));
    const body = element('body');
    class Audio {
        constructor() { this.attempts = 0; this.pauses = 0; this.rejectNext = false; instances.push(this); }
        play() { this.attempts++; return this.rejectNext ? (this.rejectNext = false, Promise.reject(new Error('NotAllowedError'))) : Promise.resolve(); }
        pause() { this.pauses++; }
    }
    const document = { currentScript: { dataset: { soundUrl: '/sound.wav', soundActor: '7' } }, body,
        getElementById(id) { return nodes.get(id); },
        querySelector() { return body.children.find(node => Object.hasOwn(node.dataset, 'orderSoundUnlock')) || null; },
        createElement(tag) { return element(tag); },
        addEventListener(name, fn) { if (!docListeners.has(name)) docListeners.set(name, []); docListeners.get(name).push(fn); },
        dispatchEvent(event) { (docListeners.get(event.type) || []).forEach(fn => fn(event)); }
    };
    const window = { Audio, addEventListener(name, fn) { if (!windowListeners.has(name)) windowListeners.set(name, []); windowListeners.get(name).push(fn); } };
    const context = vm.createContext({ window, document, localStorage: { getItem(key) { return storage.get(key) || null; }, setItem(key, value) { storage.set(key, value); } },
        sessionStorage: { getItem(key) { return session.get(key) || null; }, setItem(key, value) { session.set(key, value); } },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setInterval(fn) { intervals.push(fn); return intervals.length; }, clearInterval() {},
        setTimeout(fn) { timers.push(fn); return timers.length; }, clearTimeout() {}, console
    });
    vm.runInContext(source, context);
    return { api: window.DashboardOrderAlerts, audio: instances[0], instances, storage, session, nodes, button, context, document, intervals, timers,
        click(target = button) { document.dispatchEvent({ type: 'click', target }); },
        event(type, event) { (windowListeners.get(type) || []).forEach(fn => fn(event)); },
        plainState() { return JSON.parse(JSON.stringify(window.DashboardOrderAlerts.state())); }
    };
}
const settle = async () => { await Promise.resolve(); await Promise.resolve(); };
(async () => {
    let h = setup();
    h.click(); // Actual audio test before any order: does not poison later playback.
    assert.equal(h.audio.attempts, 1); assert.equal(h.api.state().playing, false, 'do not claim play succeeded before promise resolution');
    await settle(); h.timers.forEach(fn => fn());
    h.api.notify('legacy:1'); await settle(); assert.equal(h.audio.attempts, 2); assert.equal(h.audio.loop, true); assert.equal(h.api.state().playing, true);
    h.api.notify('legacy:2'); await settle(); assert.equal(h.audio.attempts, 2, 'one looping player per session');
    h.click({ closest() { return null; } }); assert.equal(h.api.state().pending.length, 2, 'opening orders never acknowledges them');
    h.api.acknowledge('legacy:1'); assert.equal(h.api.state().playing, true, 'a different pending order keeps ringing');
    h.api.acknowledge('legacy:2'); assert.equal(h.api.state().playing, false);

    h = setup(); h.audio.rejectNext = true; h.api.notify('legacy:3'); await settle();
    assert.deepEqual(h.plainState(), { enabled: true, playing: false, blocked: true, pending: ['legacy:3'] });
    h.click(); await settle(); assert.equal(h.audio.attempts, 2); assert.equal(h.api.state().playing, true, 'user gesture retries blocked autoplay');
    h.api.acknowledge('legacy:3'); h.audio.rejectNext = true; h.api.notify('legacy:4'); await settle();
    h.api.notify('store:5'); await settle(); assert.equal(h.api.state().playing, true, 'new arrival can retry failed playback');

    h = setup({ soundEnabled: 'false' }); h.api.sync('app-board', ['store:6', 'legacy:7']); await settle();
    assert.equal(h.audio.attempts, 0, 'persisted mute respected');
    h.click(); await settle(); assert.equal(h.storage.get('soundEnabled'), 'true'); assert.equal(h.audio.loop, true);
    h.api.notify('legacy:8'); h.api.sync('app-board', ['store:6']);
    assert.deepEqual(h.plainState().pending.sort(), ['legacy:7', 'legacy:8', 'store:6'], 'filtered snapshot cannot acknowledge absent orders');
    h.api.acknowledge('legacy:7');
    h.click(); assert.equal(h.storage.get('soundEnabled'), 'false'); assert.equal(h.api.state().playing, false); assert.equal(h.api.state().pending.length, 2, 'muting is not order acknowledgement');
    vm.runInContext(source, h.context); assert.equal(h.instances.length, 1, 'SPA/repeated script execution does not create another player');

    const shared = new Map(), first = setup({}, shared), second = setup({}, shared);
    first.api.notify('phone:9'); await settle(); second.api.notify('phone:9'); await settle();
    assert.equal(first.audio.attempts, 1); assert.equal(second.audio.attempts, 0, 'another tab owns the looping sound');
    first.api.acknowledge('phone:9');
    const ackKey = 'dashboardOrderSound:7:ack'; second.event('storage', { key: ackKey, newValue: shared.get(ackKey) });
    assert.equal(second.api.state().pending.length, 0, 'explicit acknowledgement propagates to other tabs');

    h = setup(); h.api.notify('phone:12'); await settle(); h.event('pagehide', {});
    const nextPage = setup({}, h.storage, h.session); await settle();
    assert.deepEqual(nextPage.plainState().pending, ['phone:12'], 'opening another dashboard page preserves unacknowledged orders');
    nextPage.event('pagehide', {}); nextPage.event('pageshow', { persisted: true }); await settle();
    const resumedOwner = JSON.parse(nextPage.storage.get('dashboardOrderSound:7'));
    nextPage.storage.set('dashboardOrderSound:7', JSON.stringify({ tab: resumedOwner.tab, until: 0 }));
    nextPage.intervals[nextPage.intervals.length - 1]();
    assert(JSON.parse(nextPage.storage.get('dashboardOrderSound:7')).until > Date.now(), 'BFCache resumes shared lease heartbeat');

    h = setup(); h.api.notify('legacy:20'); h.api.notify('store:21'); h.api.notify('phone:22');
    h.api.sync('app-board', ['service:23', 'partner_service:24']);
    h.api.reconcileApp(['store:21', 'service:23', 'partner_service:24'], h.api.checkpoint());
    assert.deepEqual(h.plainState().pending.sort(), ['partner_service:24', 'phone:22', 'service:23', 'store:21'], 'complete unfiltered snapshot resolves externally accepted off-page app order while keeping phone and filtered pending app keys');
    const beforeArrival = h.api.checkpoint(); h.api.notify('legacy:25');
    h.api.reconcileApp(['store:21', 'service:23', 'partner_service:24'], beforeArrival);
    assert(h.api.state().pending.includes('legacy:25'), 'in-flight snapshot does not clear a newer live arrival');
    h.api.reconcileApp(['store:21', 'service:23', 'partner_service:24'], h.api.checkpoint());
    assert(!h.api.state().pending.includes('legacy:25'), 'next complete snapshot can resolve that arrival');
    h.api.notify('legacy:26'); const beforeAction = h.api.checkpoint(); h.api.acknowledge('legacy:26');
    h.api.reconcileApp(['legacy:26', 'store:21', 'service:23', 'partner_service:24'], beforeAction);
    assert(!h.api.state().pending.includes('legacy:26'), 'stale in-flight snapshot cannot resurrect a confirmed local action');

    const footer = fs.readFileSync(path.join(__dirname, '../resources/views/admin/layouts/footer.blade.php'), 'utf8');
    assert(footer.indexOf('dashboard-order-alerts.js') < footer.indexOf('@stack(\'custom-js\')'), 'polling board initializes after sound engine');
    assert(!footer.includes('isSoundPlaying'), 'legacy false playback flag removed');
    assert(!footer.includes('stopSound();'), 'generic UI/push events never clear every pending order');
    assert(footer.includes('https://unpkg.com/sweetalert/dist/sweetalert.min.js'), 'other dashboard confirmations retain SweetAlert dependency');
    assert(footer.includes("route('getOrders', ['alerts_only' => 1])"), 'off-board poll requests lightweight authoritative feed');
    const board = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/order-board.js'), 'utf8');
    const start = board.indexOf('    function syncOrderAlerts('), end = board.indexOf('\n    function syncCards(', start);
    h = setup(); h.api.notify('legacy:7');
    const boardContext = vm.createContext({ window: { DashboardOrderAlerts: h.api }, document: {} });
    vm.runInContext(board.slice(start, end), boardContext);
    boardContext.syncOrderAlerts({ querySelectorAll(selector) { return selector.includes(':not') ? [{ dataset: { orderKey: 'legacy:7' } }] : [{ dataset: { orderKey: 'store:10' } }, { dataset: { orderKey: 'legacy:11' } }]; } });
    assert.deepEqual(h.plainState().pending.sort(), ['legacy:11', 'store:10'], 'board polling detects source-specific new keys and confirms resolved orders');
    assert(!board.includes('document.hidden || modalIsOpen()'), 'background polling detects arrivals independently of Pusher');
    console.log('Order sound regressions passed: gesture test, autoplay recovery, pending isolation, board polling, persistent mute, SPA singleton and multi-tab ownership/acknowledgement.');
})().catch(error => { console.error(error); process.exitCode = 1; });
