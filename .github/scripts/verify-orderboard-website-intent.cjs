'use strict';

// Test-only acceptance of the exact captured website journal function. The
// caller supplies and pins the source file; no production state is contacted.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const RemoteAttempts = require(path.join(__dirname, '../../desktop-pos/src/dashboard-remote-attempts.cjs'));

try {
  assert.equal(process.argv.length, 3, 'Supply one captured order-board script.');
  const input = fs.statSync(process.argv[2]);
  assert.ok(input.isFile() && input.size > 0 && input.size <= 1024 * 1024);
  const source = fs.readFileSync(process.argv[2], 'utf8');
  const start = source.indexOf('    function journalCommand(');
  const end = source.indexOf('\n    function setLive(', start);
  assert.ok(start >= 0 && end > start);
  const actualFunction = source.slice(start, end);
  const origin = 'https://board.test';
  const uuid = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;
  const records = new Map();

  function browser(orderSource, storage = records) {
    const context = {
      document: { body: { dataset: { dashboardLocal: '0', dashboardRemoteAttempts: '1', dashboardActor: '1:admin' } } },
      window: { location: { origin, href: origin + '/admin/applies-orders' } },
      crypto, URL, translated: key => key,
      sessionStorage: { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) }
    };
    vm.createContext(context);
    vm.runInContext(actualFunction, context, { timeout: 1000 });
    const card = { dataset: { orderKey: orderSource + ':12' } };
    const form = {
      dataset: { desktopBoardGeneration: 'server' },
      closest(selector) { assert.equal(selector, '[data-order-key]'); return card; },
      getAttribute(name) { assert.equal(name, 'action'); return origin + '/admin/order-board/' + orderSource + '/12/action'; }
    };
    const run = (target, data) => {
      context.testForm = target; context.testData = data;
      try { vm.runInContext('journalCommand(testForm, testData)', context, { timeout: 1000 }); }
      finally { delete context.testForm; delete context.testData; }
    };
    return { context, form, run };
  }
  const data = (action = 'complete') => new URLSearchParams({
    _token: crypto.randomUUID(), action, expected_status: 'accepted',
    expected_accepted_notify: 'yes', expected_revision: ''
  });
  const noNative = value => assert.equal(value.has('_desktop_command'), false);
  const website = browser('website');
  assert.equal(website.context.window.FasakhanstaDesktop, undefined);
  const first = data(); website.run(website.form, first);
  const id = first.get('idempotency_key');
  // Mandatory: the old pre-website implementation must fail this control.
  assert.match(id, uuid); noNative(first); assert.equal(records.size, 1);

  const replacement = { ...website.form, dataset: { ...website.form.dataset } };
  const retry = data(); website.run(replacement, retry);
  assert.equal(retry.get('idempotency_key'), id); noNative(retry); assert.equal(records.size, 1);
  const restarted = browser('website'), afterRestart = data();
  restarted.run(restarted.form, afterRestart);
  assert.equal(afterRestart.get('idempotency_key'), id); noNative(afterRestart); assert.equal(records.size, 1);

  const changedAction = data('cancel'); website.run(replacement, changedAction);
  assert.match(changedAction.get('idempotency_key'), uuid);
  assert.notEqual(changedAction.get('idempotency_key'), id); noNative(changedAction);
  website.context.document.body.dataset.dashboardActor = '2:admin';
  const changedActor = data(); website.run(replacement, changedActor);
  assert.match(changedActor.get('idempotency_key'), uuid);
  assert.notEqual(changedActor.get('idempotency_key'), id);
  assert.notEqual(changedActor.get('idempotency_key'), changedAction.get('idempotency_key'));
  noNative(changedActor); assert.equal(records.size, 3);

  website.context.sessionStorage.setItem = () => { throw Error('storage denied'); };
  const refused = data('cancel');
  assert.throws(() => website.run(replacement, refused), /storage denied/);
  assert.equal(refused.has('idempotency_key'), false); noNative(refused); assert.equal(records.size, 3);

  const ordinaryRecords = new Map(), ordinary = browser('legacy', ordinaryRecords);
  ordinary.form.dataset.desktopBoardGeneration = '';
  ordinary.context.sessionStorage.setItem = () => { throw Error('ordinary browser must not write a native intent'); };
  const unchanged = data(); ordinary.run(ordinary.form, unchanged);
  assert.equal(unchanged.has('idempotency_key'), false); noNative(unchanged); assert.equal(ordinaryRecords.size, 0);

  const attempts = new RemoteAttempts({});
  assert.equal(attempts.supported({ url: origin + '/admin/order-board/legacy/12/action', method: 'POST' }), true);
  assert.equal(attempts.supported({ url: origin + '/admin/order-board/website/12/action', method: 'POST' }), false);
  process.stdout.write('ORDER_BOARD_WEBSITE_INTENT_COMPLETE ' + JSON.stringify({
    format: 1, kind: 'go-erp-orderboard-website-intent',
    sourceSha256: crypto.createHash('sha256').update(source).digest('hex'),
    extractedProductionFunction: true, syntheticDom: true, actualBrowser: false,
    websiteIdempotencyRequired: true, retryStable: true, restartStable: true,
    actionRebinds: true, actorRebinds: true, storageFailureStops: true,
    ordinaryBrowserUnchanged: true, nativeCommandAbsent: true,
    nativeLegacyReservationSupported: true, nativeWebsiteReservationUnsupported: true, completed: true
  }) + '\n');
} catch {
  process.stderr.write('ORDER_BOARD_WEBSITE_INTENT_REJECTED\n');
  process.exitCode = 1;
}
