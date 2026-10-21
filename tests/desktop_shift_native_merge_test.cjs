'use strict';
// Run the actual merged shift functions with isolated network/native adapters.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/dashboard/js/branch-shifts.js'), 'utf8');
function block(start, end) {
    const first = source.indexOf(start), last = source.indexOf(end, first + start.length);
    assert(first >= 0 && last > first, 'actual production function boundary');
    assert.equal(source.indexOf(start, first + start.length), -1, 'unambiguous production function');
    return source.slice(first, last);
}
const printing = block('async function printClosing(closing){\n', 'async function closeShift(){');
const closing = block('async function closeShift(){', "\nform.addEventListener('submit'");

function fixture(desktop) {
    const calls = { requests: [], receipts: [], refreshes: 0, messages: [] };
    const context = vm.createContext({
        printRequests: Object.create(null), crypto: { randomUUID: () => 'stable-request-id' },
        window: { FasakhanstaDesktop: desktop }, disposed: false,
        boot: { urls: { reprint: '/reprint', close: '/close', recover: '/recover' } },
        document: { querySelector: () => ({ content: 'synthetic-csrf' }) },
        url: value => value, read: async value => value,
        fetch: async (url, options) => { calls.requests.push({ url, options }); return { closing: { id: 41 } }; },
        get: async () => { throw Error('Unexpected recovery request'); },
        message: (text, ok) => calls.messages.push({ text, ok }),
        pending: null, busy: false, selectedClosing: null,
        counted: { value: '123.45' }, notes: { value: 'saved notes' },
        lock() {}, refresh: async () => { calls.refreshes++; },
        AbortController, setTimeout: () => 1, clearTimeout() {},
    });
    vm.runInContext("'use strict';\n" + printing + closing, context);
    return { calls, context };
}

(async () => {
    let checks = 0;
    {
        const receipts = [];
        const f = fixture({ status: async () => ({ mode: 'local' }),
            printReceipt: async (...args) => { receipts.push(args); } });
        await f.context.printClosing({ id: 10, print_url: '/branch-shifts/10/print' });
        assert.deepEqual(receipts, [['/branch-shifts/10/print', 'stable-request-id']]);
        assert.equal(f.calls.requests.length, 0, 'local receipt uses the native adapter');
        assert.equal(f.context.printRequests[10], undefined);
        checks++;
    }
    {
        const f = fixture({ status: async () => ({ mode: 'connected' }) });
        const requests = [];
        f.context.fetch = async (url, options) => {
            requests.push({ url, payload: JSON.parse(options.body) });
            if (requests.length === 1) throw Error('Synthetic lost reply');
            return { success: true };
        };
        const record = { id: 11, print_url: '/branch-shifts/11/print' };
        await f.context.printClosing(record);
        assert.equal(f.context.printRequests[11], 'stable-request-id', 'uncertain print retains its key');
        await f.context.printClosing(record);
        assert.equal(requests.length, 2);
        assert.equal(requests[0].url, '/reprint');
        assert.deepEqual(requests[0].payload, requests[1].payload, 'retry retains the same server print command');
        assert.equal(f.context.printRequests[11], undefined);
        checks++;
    }
    {
        let pendingPrints = 0;
        const f = fixture({ printPending: async () => { pendingPrints++; return { local: true, invoked: 1, attention: false }; } });
        f.context.pending = { payload: { branch: 'f:100', idempotency_key: 'closing-command', counted_cash: '123.45' } };
        await f.context.closeShift();
        assert.equal(f.calls.requests.length, 1);
        assert.equal(f.calls.requests[0].url, '/close');
        assert.equal(JSON.parse(f.calls.requests[0].options.body).idempotency_key, 'closing-command');
        assert.equal(f.context.pending, null);
        assert.equal(f.context.selectedClosing, 41);
        assert.equal(f.context.counted.value, '');
        assert.equal(f.context.notes.value, '');
        assert.equal(f.calls.refreshes, 1);
        assert.equal(pendingPrints, 1, 'successful close retains native pending-print dispatch');
        checks++;
    }
    console.log('DESKTOP_SHIFT_NATIVE_MERGE_OK checks=' + checks);
})().catch(error => { console.error(error); process.exitCode = 1; });
