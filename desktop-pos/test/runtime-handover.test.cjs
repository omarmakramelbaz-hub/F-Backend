'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const RuntimeHandover = require('../src/runtime-handover.cjs');

const oldRevision = 'a'.repeat(40), newRevision = 'b'.repeat(40);
const now = () => new Date('2026-10-24T20:30:00.000Z');
async function profile() { return fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-handover-')); }

test('mismatch only reports two valid, different revisions', () => {
  assert.equal(RuntimeHandover.mismatch(newRevision, oldRevision), true);
  assert.equal(RuntimeHandover.mismatch(newRevision, newRevision), false);
  assert.equal(RuntimeHandover.mismatch(newRevision, undefined), false);
  assert.equal(RuntimeHandover.mismatch('not-a-revision', oldRevision), false);
});

test('without a request, startup leaves the device profile untouched', async () => {
  const root = await profile();
  await fs.mkdir(path.join(root, 'dashboard'));
  await fs.writeFile(path.join(root, 'dashboard', 'prepared.enc'), 'kept');
  const result = new RuntimeHandover({ profile: root, now }).apply();
  assert.equal(result.applied, false);
  assert.equal(await fs.readFile(path.join(root, 'dashboard', 'prepared.enc'), 'utf8'), 'kept');
});

test('a requested handover renames the old profile aside and keeps every file', async () => {
  const root = await profile(), handover = new RuntimeHandover({ profile: root, now });
  await fs.mkdir(path.join(root, 'dashboard', 'database'), { recursive: true });
  await fs.writeFile(path.join(root, 'dashboard', 'database', 'ibdata1'), 'journal');
  await fs.writeFile(path.join(root, 'fasakhansta-pos.sqlite'), 'pos');
  await handover.request({ from: oldRevision, to: newRevision, version: '0.4.4-preview.47' });
  const result = handover.apply();
  assert.equal(result.applied, true);
  assert.equal(path.basename(result.retired), 'dashboard-retired-aaaaaaaa-20261024203000');
  assert.equal(await fs.readFile(path.join(result.retired, 'database', 'ibdata1'), 'utf8'), 'journal');
  assert.equal(await fs.readFile(path.join(root, 'fasakhansta-pos.sqlite'), 'utf8'), 'pos');
  await assert.rejects(fs.access(path.join(root, 'dashboard')), { code: 'ENOENT' });
  assert.equal(handover.pending(), null);
  assert.equal((handover.apply()).applied, false);
});

test('a handover is refused when there is no newer installed revision', async () => {
  const handover = new RuntimeHandover({ profile: await profile(), now });
  await assert.rejects(handover.request({ from: newRevision, to: newRevision }));
  assert.equal(handover.pending(), null);
});

test('a rename failure keeps the request for the next start', async () => {
  const root = await profile(), handover = new RuntimeHandover({ profile: root, now });
  await fs.mkdir(path.join(root, 'dashboard'));
  await handover.request({ from: oldRevision, to: newRevision });
  const stamp = path.join(root, 'dashboard-retired-aaaaaaaa-20261024203000');
  await fs.mkdir(path.join(stamp, 'occupied'), { recursive: true });
  const result = handover.apply();
  assert.equal(result.applied, false);
  assert.ok(result.error);
  assert.ok(handover.pending());
  await fs.access(path.join(root, 'dashboard'));
});

test('a corrupt request is discarded without moving anything', async () => {
  const root = await profile(), handover = new RuntimeHandover({ profile: root, now });
  await fs.mkdir(path.join(root, 'dashboard'));
  await fs.writeFile(handover.marker, '{broken');
  assert.equal((handover.apply()).applied, false);
  await fs.access(path.join(root, 'dashboard'));
  await assert.rejects(fs.access(handover.marker), { code: 'ENOENT' });
});
