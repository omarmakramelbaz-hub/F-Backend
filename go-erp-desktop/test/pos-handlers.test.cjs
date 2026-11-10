'use strict';
// The app's selling handlers read the device's store and sync when called (both are set once the app is ready).
const test = require('node:test');
const assert = require('node:assert/strict');
const Module = require('node:module');

// handlers/pos.cjs loads Electron for its print window; outside the app a stand-in is enough.
const load = Module._load;
Module._load = function (request, ...rest) { return request === 'electron' ? { BrowserWindow: function () {} } : load.call(this, request, ...rest); };
const { posHandlers } = require('../src/main/handlers/pos.cjs');
Module._load = load;

test('the store and sync set after the handlers were made are the ones they use', () => {
  let store = null;
  let sync = null;
  const kicks = [];
  const session = { branch: 'f:1', user: { id: 7, can_manage: true } };
  const handlers = posHandlers({ get store() { return store; }, get sync() { return sync; }, requireSession: () => session,
    printSettings: () => ({}), branchInfo: () => ({}), now: () => new Date('2026-11-10T10:00:00Z') });
  store = { openTickets: (branch) => [`open in ${branch}`], recordExpense: (user, branch, values) => ({ user: user.id, branch, ...values }),
    salesReport: (branch, from, to) => ({ branch, from, to }), cashSummary: () => ({ cash: 1 }) };
  sync = { kick: () => kicks.push('kick') };
  assert.deepEqual(handlers['tickets:open'](), ['open in f:1']);
  assert.deepEqual(handlers['cash:expense']({ amount: '5' }), { user: 7, branch: 'f:1', amount: '5' });
  assert.deepEqual(kicks, ['kick'], 'A new expense is sent with the next sync');
  // One day: the report carries the till of that day; the operating day of 10:00 UTC (12:00 Cairo) is the 10th.
  assert.deepEqual(handlers['reports:sales']({}), { today: '2026-11-10', report: { branch: 'f:1', from: '2026-11-10', to: '2026-11-10', cash: { cash: 1 } } });
});

test('reports stay for the owner and the management', () => {
  const handlers = posHandlers({ store: {}, sync: {}, requireSession: () => ({ branch: 'f:1', user: { can_manage: false } }),
    printSettings: () => ({}), branchInfo: () => ({}), now: () => new Date() });
  assert.throws(() => handlers['reports:sales']({}), /للمالك والإدارة فقط/);
});
