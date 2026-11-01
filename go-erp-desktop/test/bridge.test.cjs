'use strict';
// The screens reach the app only through the named actions the preload bridge allows.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SOURCE = path.join(__dirname, '../src');

function files(folder) {
  return fs.readdirSync(folder, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(folder, entry.name);
    return entry.isDirectory() ? files(full) : /\.(vue|js)$/.test(entry.name) ? [full] : [];
  });
}

test('every action the screens call is allowed by the bridge and handled by the app', () => {
  const preload = fs.readFileSync(path.join(SOURCE, 'main/preload.cjs'), 'utf8');
  const allowed = new Set([...preload.match(/const CHANNELS = \[([\s\S]*?)\];/)[1].matchAll(/'([a-z]+:[a-z-]+)'/g)].map((found) => found[1]));
  const used = new Set();
  for (const file of files(path.join(SOURCE, 'renderer'))) {
    for (const found of fs.readFileSync(file, 'utf8').matchAll(/call\('([a-z]+:[a-z-]+)'/g)) used.add(found[1]);
  }
  assert.ok(used.has('setup:release'));
  assert.deepEqual([...used].filter((channel) => !allowed.has(channel)), [], 'Called by the screens but not allowed by the bridge');
  const main = fs.readFileSync(path.join(SOURCE, 'main/main.cjs'), 'utf8');
  assert.deepEqual([...allowed].filter((channel) => !main.includes(`'${channel}':`)), [], 'Allowed by the bridge but handled nowhere');
});

test('the «برنامج الكمبيوتر» page asks the app only for the device actions it handles', () => {
  const preload = fs.readFileSync(path.join(SOURCE, 'main/dashboard-preload.cjs'), 'utf8');
  const used = new Set([...preload.matchAll(/device\('([a-z-]+)'/g)].map((found) => found[1]));
  assert.ok(used.has('release') && used.has('cancel-order') && used.has('state'));
  const main = fs.readFileSync(path.join(SOURCE, 'main/main.cjs'), 'utf8');
  const actions = main.match(/const DEVICE_ACTIONS = \{([\s\S]*?)\n\};/)[1];
  assert.deepEqual([...used].filter((action) => !new RegExp(`^  ('${action}'|${action}):`, 'm').test(actions)), [], 'Asked by the page but not handled');
  // Only from that page, in the dashboard window's own frame.
  assert.match(main, /event\.sender !== dashboardView\.webContents/);
  assert.match(main, /url\.pathname\.replace\(\/\\\/\+\$\/, ''\) !== PROGRAM_PAGE/);
});
