'use strict';
// The app works from the device only when the dashboard is really out of reach.
const test = require('node:test');
const assert = require('node:assert/strict');
const { unreachable, ConnectionWatch, GATEWAY } = require('../src/main/connection.cjs');

const probes = (answers) => {
  const asked = [];
  return { asked, probe: async () => { asked.push(true); const next = answers.shift(); if (next instanceof Error) throw next; return next; } };
};
const instant = { wait: async () => {} };

test('one failure is not enough: the dashboard is asked again before the app works from the device', async () => {
  const once = probes([false, true]);
  assert.equal(await unreachable(once.probe, instant), false, 'It answered the second time: still online');
  assert.equal(once.asked.length, 2);
  const never = probes([false, new Error('ERR_CONNECTION_REFUSED')]);
  assert.equal(await unreachable(never.probe, instant), true, 'No answer twice: offline');
  const answers = probes([true]);
  assert.equal(await unreachable(answers.probe, instant), false);
  assert.equal(answers.asked.length, 1, 'Asked once when it answers');
});

test('the check waits between the two questions and stops when it no longer matters', async () => {
  const waited = [];
  const slow = probes([false, false]);
  assert.equal(await unreachable(slow.probe, { pause: 5000, wait: async (ms) => { waited.push(ms); } }), true);
  assert.deepEqual(waited, [5000]);
  let stopped = false;
  const stopping = probes([false, false]);
  assert.equal(await unreachable(stopping.probe, { wait: async () => { stopped = true; }, stop: () => stopped }), false);
  assert.equal(stopping.asked.length, 1);
  assert.deepEqual([...GATEWAY], [502, 503, 504]);
});

/** A watch with a dashboard that answers or not, no waiting, and the recovery timer held for the test to fire. */
function watchWith(answers) {
  const log = [];
  const timer = { fn: null, every: 0, cleared: 0 };
  const watch = new ConnectionWatch({
    answers: async () => { log.push('ask'); return answers.shift(); },
    changed: (value, reason) => log.push(`${value ? 'offline' : 'online'}:${reason}`),
    stillOnline: (reason) => log.push(`still:${reason}`),
    runSync: () => log.push('sync'),
    every: 10000, wait: async () => {},
    timers: { setInterval: (fn, every) => { timer.fn = fn; timer.every = every; return 1; }, clearInterval: (id) => { if (id) timer.cleared++; } },
  });
  return { watch, log, timer };
}

test('one unanswered question is not enough: the dashboard is asked again and a single answer keeps the app online', async () => {
  const { watch, log, timer } = watchWith([false, true]);
  await watch.suspect('timeout');
  assert.equal(watch.offline, false);
  assert.deepEqual(log, ['ask', 'ask', 'still:timeout']);
  assert.equal(timer.fn, null, 'No recovery check online');
});

test('offline only when the dashboard never answers; then a sync, and a check every 10 seconds', async () => {
  const { watch, log, timer } = watchWith([false, false]);
  await watch.suspect('sync failed');
  assert.equal(watch.offline, true);
  assert.deepEqual(log, ['ask', 'ask', 'offline:sync failed', 'sync']);
  assert.equal(timer.every, 10000);
  // Offline, a new suspicion asks nothing.
  assert.equal(await watch.suspect('again'), null);
  assert.equal(log.length, 4);
});

test('suspicions at the same time share one check', async () => {
  const { watch, log } = watchWith([false, false]);
  const first = watch.suspect('a');
  assert.equal(watch.suspect('b'), first);
  await first;
  assert.deepEqual(log, ['ask', 'ask', 'offline:a', 'sync']);
});

test('offline, the dashboard answering brings a sync, which switches the app back online once', async () => {
  const { watch, log, timer } = watchWith([false, true]);
  assert.equal(watch.set(true, 'network'), true);
  assert.equal(watch.set(true, 'network'), false, 'Already offline');
  await timer.fn();
  assert.deepEqual(log, ['offline:network', 'ask'], 'Not answering: still offline, no sync');
  await timer.fn();
  assert.deepEqual(log, ['offline:network', 'ask', 'ask', 'sync']);
  assert.equal(watch.set(false, 'sync'), true);
  assert.equal(timer.cleared, 1, 'The recovery check stops online');
  assert.equal(watch.offline, false);
  await watch.recover();
  assert.deepEqual(log.slice(-1), ['online:sync'], 'Online, recovery asks nothing');
});
