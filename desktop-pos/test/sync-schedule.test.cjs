'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const SyncSchedule = require('../src/sync-schedule.cjs');
const settled = () => new Promise(resolve => setImmediate(resolve));
function clock() {
  let now = 0, id = 0; const timers = new Map();
  return { now: () => now, setTimer(fn, delay) { const key = ++id; timers.set(key, { at: now + delay, fn }); return key; },
    clearTimer(key) { timers.delete(key); },
    async advance(target) {
      while (true) {
        const next = [...timers].sort(([, a], [, b]) => a.at - b.at)[0];
        if (!next || next[1].at > target) break;
        now = next[1].at; timers.delete(next[0]); next[1].fn(); await settled();
      }
      now = target; await settled();
    },
    async delayed(target) {
      now = target; const next = [...timers].sort(([, a], [, b]) => a.at - b.at)[0];
      assert.ok(next && next[1].at <= now); timers.delete(next[0]); next[1].fn(); await settled();
    }, size: () => timers.size };
}

test('local work accumulates immediately and background synchronization starts only on 30-second slots', async () => {
  const time = clock(), queue = [], batches = [];
  const schedule = new SyncSchedule({ ...time, work: async () => batches.push(queue.splice(0)) });
  schedule.start(); schedule.start(); queue.push('order', 'stock', 'expense');
  await time.advance(29999); assert.equal(queue.length, 3); assert.equal(batches.length, 0);
  await time.advance(30000); assert.deepEqual(batches, [['order', 'stock', 'expense']]);
  queue.push('another-order'); await time.advance(59999); assert.equal(batches.length, 1);
  await time.advance(60000); assert.deepEqual(batches[1], ['another-order']); schedule.stop();
});

test('repeated sync requests share the next scheduled cycle, and an active cycle receives no parallel request', async () => {
  const time = clock(), calls = []; let release;
  const schedule = new SyncSchedule({ ...time, work: async options => { calls.push(options); await new Promise(resolve => { release = resolve; }); } });
  schedule.start();
  const first = schedule.request(), second = schedule.request({ refresh: true }); assert.equal(first, second);
  await time.advance(29999); assert.equal(calls.length, 0);
  await time.advance(30000); assert.deepEqual(calls, [{ refresh: true }]);
  const active = schedule.request(); assert.equal(active, schedule.running);
  release(); await Promise.all([first, second, active]); assert.equal(calls.length, 1);
  await time.advance(59999); assert.equal(calls.length, 1); schedule.stop();
});

test('a cycle spanning another 30-second slot skips that slot and never starts a catch-up burst', async () => {
  const time = clock(); let calls = 0, release;
  const schedule = new SyncSchedule({ ...time, work: async () => { calls++; if (calls === 1) await new Promise(resolve => { release = resolve; }); } });
  schedule.start(); await time.advance(30000); const active = schedule.request();
  await time.advance(70000); assert.equal(calls, 1);
  release(); await active; await settled(); assert.equal(calls, 1);
  await time.advance(89999); assert.equal(calls, 1);
  await time.advance(90000); assert.equal(calls, 2); schedule.stop();
});

test('a delayed event loop skips all missed deadlines and resumes at the next 30-second boundary', async () => {
  const time = clock(); let calls = 0;
  const schedule = new SyncSchedule({ ...time, work: async () => { calls++; } });
  schedule.start(); await time.delayed(95000); assert.equal(calls, 1);
  await time.advance(119999); assert.equal(calls, 1);
  await time.advance(120000); assert.equal(calls, 2); schedule.stop();
});

test('a failed cycle keeps the fixed cadence and closing cancels queued requests and future cycles', async () => {
  const time = clock(), errors = []; let calls = 0;
  const schedule = new SyncSchedule({ ...time, onError: error => errors.push(error.message), work: async () => { calls++; if (calls === 1) throw Error('lost reply'); } });
  schedule.start(); const requested = assert.rejects(schedule.request(), /lost reply/);
  await time.advance(30000); await requested; assert.deepEqual(errors, ['lost reply']);
  await time.advance(59999); assert.equal(calls, 1);
  await time.advance(60000); assert.equal(calls, 2);
  const cancelled = assert.rejects(schedule.request(), /stopped/); schedule.stop(); await cancelled;
  await time.advance(120000); assert.equal(calls, 2); assert.equal(time.size(), 0);
});

test('closing during an accepted cycle lets its result settle without another cycle', async () => {
  const time = clock(); let calls = 0, release;
  const schedule = new SyncSchedule({ ...time, work: async () => { calls++; await new Promise(resolve => { release = resolve; }); return 'confirmed'; } });
  schedule.start(); await time.advance(30000); const accepted = schedule.request(); schedule.stop();
  release(); assert.equal(await accepted, 'confirmed'); await time.advance(120000);
  assert.equal(calls, 1); assert.equal(time.size(), 0);
});
