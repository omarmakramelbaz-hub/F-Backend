const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/dashboard/js/branch-print-receiver.js'), 'utf8');
function setup({ restored = [], session = new Map() } = {}) {
    const listeners = new Map(), windowListeners = new Map(), timers = [], body = { children: [], appendChild(n) { this.children.push(n); } };
    function element(tag) { return { tag, dataset: {}, style: {}, hidden: false, children: [], listeners: {}, setAttribute() {}, appendChild(n) { this.children.push(n); }, addEventListener(n, fn) { this.listeners[n] = fn; } }; }
    const boot = { branch: 'f:301', actor_id: 7, jobs: '/jobs', claim: '/claim', complete: '/complete', orders: '/orders', labels: { ready: 'ready', offline: 'offline', attention: 'attention', incoming: 'incoming' } };
    const events = [], pending = new Set(restored), notified = [], acknowledged = [], requests = [], prints = [], conflicts = new Set();
    let ready = false, listing = { success: true, latest_ticket_id: 0, incoming_ticket_ids: [], jobs: [] }, failPrint = false;
    const document = { body,
        getElementById(id) { return id === 'branch-print-bootstrap' ? { textContent: JSON.stringify(boot) } : null; },
        querySelector(selector) { if (selector.startsWith('script')) return { dataset: { soundActor: '7' } }; if (selector.startsWith('meta')) return { content: 'csrf-fixture' }; return body.children.find(n => Object.hasOwn(n.dataset, 'branchOrderAlert')) || null; },
        createElement: element,
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); },
        dispatchEvent(event) { events.push(event); for (const fn of listeners.get(event.type) || []) fn(event); }
    };
    const emit = () => document.dispatchEvent({ type: 'dashboard:order-alert-state', detail: { pending: Array.from(pending) } });
    const engine = { notify(key) { notified.push(key); pending.add(key); emit(); }, acknowledge(key) { acknowledged.push(key); pending.delete(key); emit(); }, state() { return { pending: Array.from(pending) }; }, isReady() { return ready; } };
    const window = { DashboardOrderAlerts: engine, DashboardPrint: { async print(url) { prints.push(url); if (failPrint) throw Error('Print failed'); } }, addEventListener(name, fn) { windowListeners.set(name, fn); } };
    const context = vm.createContext({ window, document, URL, AbortController, location: { href: 'https://dashboard.test/admin/home', origin: 'https://dashboard.test' },
        crypto: { randomUUID() { return '00000000-0000-4000-8000-000000000000'; } },
        sessionStorage: { getItem(key) { return session.get(key) || null; }, setItem(key, value) { session.set(key, value); } },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout(fn, delay) { const timer = { fn, delay, active: true }; timers.push(timer); return timer; }, clearTimeout(timer) { if (timer) timer.active = false; },
        async fetch(url, options) {
            const name = new URL(url).pathname, values = options.body ? JSON.parse(options.body) : null; requests.push({ name, values });
            let result = listing, ok = true, status = 200;
            if (name === '/claim') { if (conflicts.has(values.job_id)) { result = { success: false }; ok = false; status = 409; } else result = { success: true, print_url: '/print/' + values.job_id }; }
            if (name === '/complete') result = { success: true };
            return { ok, status, async json() { return result; } };
        }
    });
    vm.runInContext(source, context);
    return { pending, notified, acknowledged, session, events, requests, prints, conflicts, engine, window,
        async poll(result) { listing = { success: true, latest_ticket_id: 0, jobs: [], ...result }; const timer = timers.find(t => t.active && t.delay < 15000); assert(timer, 'receiver scheduled its next poll'); timer.active = false; await timer.fn(); },
        receive() { const banner = document.querySelector('[data-branch-order-alert]'); const button = banner.children.find(n => n.tag === 'button'); button.listeners.click(); },
        banner() { return document.querySelector('[data-branch-order-alert]'); },
        setReady(value) { ready = value; emit(); },
        setPrintFailure(value) { failPrint = value; },
        pagehide() { windowListeners.get('pagehide')(); },
        listenerCount() { return listeners.get('dashboard:order-alert-state')?.size || 0; }
    };
}
(async () => {
    const oldHeard = new Map([['branch-delivery-sound:7:f:301', '[11]']]);
    let h = setup({ restored: ['phone:9', 'legacy:30'], session: oldHeard });
    await h.poll({ latest_ticket_id: 999, incoming_ticket_ids: [11], jobs: [{ id: 100, ticket_id: 998 }] });
    assert.deepEqual(Array.from(h.pending).sort(), ['legacy:30', 'phone:11'], 'authoritative caller-created active IDs take priority over draft/latest/local print jobs');
    assert(h.notified.includes('phone:11'), 'old heard-beep history does not count as user acknowledgement');
    assert.equal(h.requests.filter(r => r.name === '/complete')[0].values.result, 'invoked');
    const attempts = h.notified.length;
    await h.poll({ latest_ticket_id: 1000, incoming_ticket_ids: [11] }); assert.equal(h.notified.length, attempts, 'same active request does not re-notify');
    h.setReady(true); assert.equal(h.window.DashboardBranchPrinter.soundReady, true); assert(h.events.some(e => e.type === 'dashboard:branch-print' && e.detail.soundReady), 'desktop event shape retained');
    h.receive(); assert(!h.pending.has('phone:11'));
    assert.deepEqual(JSON.parse(h.session.get('branch-delivery-received:7:f:301')), [11], 'acknowledgement is scoped by actor and branch');
    await h.poll({ incoming_ticket_ids: [11] }); assert.equal(h.notified.length, attempts, 'acknowledged still-active request remains silent');
    await h.poll({ incoming_ticket_ids: [11, 12] }); assert(h.pending.has('phone:12'), 'new ID after acknowledgement rings');
    h.engine.acknowledge('phone:12'); await h.poll({ incoming_ticket_ids: [11, 12] }); assert(!h.pending.has('phone:12'), 'cross-tab engine acknowledgement cannot be resurrected by next poll');
    await h.poll({ incoming_ticket_ids: [] }); assert(!h.pending.has('phone:11')); assert(h.pending.has('legacy:30'), 'phone reconciliation leaves app orders untouched');
    await h.poll({ incoming_ticket_ids: [13] }); h.setReady(false);
    await h.poll({ incoming_ticket_ids: [] }); assert(!h.pending.has('phone:13'), 'cancelled/completed request is removed before audio unlock'); assert.equal(h.banner().hidden, true);
    h.pagehide(); assert.equal(h.listenerCount(), 0);

    h = setup(); h.conflicts.add(201);
    await h.poll({ latest_ticket_id: 21, jobs: [{ id: 201, ticket_id: 21 }] });
    assert.deepEqual(Array.from(h.pending), ['phone:21'], 'old feed fallback alerts first pending jobs');
    assert.equal(h.prints.length, 0); assert.equal(h.requests.filter(r => r.name === '/complete').length, 0, 'claim 409 does not print or complete another terminal claim');
    h.receive(); await h.poll({ latest_ticket_id: 21, jobs: [{ id: 201, ticket_id: 21 }] }); assert(!h.pending.has('phone:21'));
    await h.poll({ latest_ticket_id: 22, jobs: [{ id: 202, ticket_id: 22 }] }); assert(h.pending.has('phone:22'));
    const completed = h.requests.filter(r => r.name === '/complete'); assert.equal(completed.length, 1); assert.equal(completed[0].values.result, 'invoked'); assert.equal(completed[0].values.job_id, 202);
    h.setPrintFailure(true); await h.poll({ latest_ticket_id: 23, jobs: [{ id: 203, ticket_id: 23 }] });
    assert.equal(h.requests.filter(r => r.name === '/complete').at(-1).values.result, 'failed', 'printing failure is completed as failed without recycling claim');
    const baseline = execFileSync('git', ['show', 'adc9e46a86df569bd6dab15e9f4302fc1c55aa91:public/dashboard/js/branch-print-receiver.js'], { cwd: root, encoding: 'utf8' });
    const pipeline = value => value.slice(value.indexOf('            for (var job of result.jobs || []) {'), value.indexOf('        } catch (_) { if (!stopped) report(boot.labels.offline); }'));
    assert.equal(pipeline(source), pipeline(baseline), 'print claim/409/print/complete pipeline is byte-identical');
    console.log('Receiver regressions passed: authoritative active IDs, explicit scoped acknowledgement, cross-tab no resurrection, cancellation before unlock, legacy fallback and unchanged print claims/completion.');
})().catch(error => { console.error(error); process.exitCode = 1; });
