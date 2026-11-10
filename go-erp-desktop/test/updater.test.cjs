'use strict';
// New versions install themselves only when the device is quiet and nothing waits to reach the dashboard.
const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { startUpdates, quietEnough, cairoHour } = require('../src/main/updater.cjs');

test('quiet enough: just opened with nobody signed in after a minute, 4-6 in the morning after 3 minutes, else 30 minutes', () => {
  assert.equal(quietEnough({ signedIn: false, idle: 60, hour: 14 }), true);
  assert.equal(quietEnough({ signedIn: false, idle: 59, hour: 14 }), false);
  assert.equal(quietEnough({ signedIn: true, idle: 600, hour: 14 }), false);
  assert.equal(quietEnough({ signedIn: true, idle: 180, hour: 4 }), true);
  assert.equal(quietEnough({ signedIn: true, idle: 180, hour: 5 }), true);
  assert.equal(quietEnough({ signedIn: true, idle: 180, hour: 6 }), false);
  assert.equal(quietEnough({ signedIn: true, idle: 179, hour: 4 }), false);
  assert.equal(quietEnough({ signedIn: true, idle: 1800, hour: 14 }), true);
});

test('the hour is Cairo time', () => {
  assert.equal(cairoHour(new Date('2026-11-10T02:30:00Z')), 4);
  assert.equal(cairoHour(new Date('2026-11-10T22:30:00Z')), 0);
});

function harness({ signedIn = true, idle = 0, pending = 0, at = '2026-11-10T12:00:00Z' } = {}) {
  const updater = new EventEmitter();
  const calls = { checks: 0, installs: 0, stops: 0 };
  updater.checkForUpdates = () => { calls.checks++; return Promise.resolve(); };
  updater.quitAndInstall = () => { calls.installs++; };
  const state = { signedIn, idle, pending, at };
  const timers = { setInterval: () => 0, setTimeout: (fn) => fn() };
  const run = startUpdates({ updater, signedIn: () => state.signedIn, idle: () => state.idle, pending: () => state.pending,
    stopSync: () => { calls.stops++; }, now: () => new Date(state.at), log: () => {}, timers });
  return { updater, calls, state, run };
}

test('it checks at start, downloads by itself and installs on close', () => {
  const { updater, calls } = harness();
  assert.equal(calls.checks, 1);
  assert.equal(updater.autoDownload, true);
  assert.equal(updater.autoInstallOnAppQuit, true);
});

test('nothing installs before a version is downloaded, nor while the device is in use', () => {
  const { updater, calls, state, run } = harness({ idle: 5000 });
  run.look();
  assert.equal(calls.installs, 0, 'Nothing downloaded yet');
  updater.emit('update-downloaded', { version: '9.9.9' });
  state.idle = 100;
  run.look();
  assert.equal(calls.installs, 0, 'In use');
});

test('a quiet device waits for its operations to reach the dashboard, then stops the sync and installs once', () => {
  const { updater, calls, state, run } = harness({ idle: 1800, pending: 2 });
  updater.emit('update-downloaded', { version: '9.9.9' });
  run.look();
  assert.equal(calls.installs, 0, 'Operations still waiting');
  state.pending = 0;
  run.look();
  assert.deepEqual([calls.stops, calls.installs], [1, 1]);
  run.look();
  run.check();
  assert.equal(calls.installs, 1, 'Installs once');
  assert.equal(calls.checks, 1, 'No new check while installing');
});

test('when the idle time or the waiting operations cannot be read, it does not install', () => {
  const { updater, calls, run, state } = harness({ idle: 1800 });
  updater.emit('update-downloaded', { version: '9.9.9' });
  const original = state.idle;
  Object.defineProperty(state, 'idle', { get() { throw new Error('unknown'); }, configurable: true });
  run.look();
  assert.equal(calls.installs, 0);
  Object.defineProperty(state, 'idle', { value: original, writable: true, configurable: true });
  Object.defineProperty(state, 'pending', { get() { throw new Error('unknown'); }, configurable: true });
  run.look();
  assert.equal(calls.installs, 0);
});
