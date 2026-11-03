'use strict';
// The app works from the device only when the dashboard is really out of reach.
const test = require('node:test');
const assert = require('node:assert/strict');
const { unreachable, GATEWAY } = require('../src/main/connection.cjs');

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
