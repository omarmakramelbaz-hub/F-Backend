'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const php = process.env.DESKTOP_TEST_PHP || 'php';
const options = { encoding: 'utf8', timeout: 15000, windowsHide: true, maxBuffer: 1024 * 1024 };
function run(t, mode) {
  const available = spawnSync(php, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && available.error?.code === 'ENOENT') {
    t.skip('PHP unavailable; set DESKTOP_TEST_PHP for actual employee service tests.'); return;
  }
  assert.ifError(available.error); assert.equal(available.status, 0, available.stderr);
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'go-erp-identity-'));
  try {
    const actual = spawnSync(php, ['-n', path.join(__dirname, 'employee-identity-service-fixture.php'), root, mode, temp], options);
    assert.ifError(actual.error); assert.equal(actual.status, 0, actual.stdout + actual.stderr); assert.equal(actual.stderr, '');
    return JSON.parse(actual.stdout);
  } finally { fs.rmSync(temp, { recursive: true, force: true }); }
}
test('serialized consent replay binds the displayed text and preserves its first acceptance timestamp', t => {
  const r = run(t, 'consent'); if (!r) return;
  // This published policy version identifies these exact terms. A future text
  // edit must deliberately change its version and this acceptance baseline.
  assert.equal(r.digest, '7c4dbe0964187ab095748bc5e989645bd9387545d8365d7982d7e19f9f071419');
  assert.equal(r.first.regulation_version, '2026-10-23');
  assert.equal(r.first.regulation_accepted_at, '2026-10-23 12:00:00');
  assert.equal(r.second.regulation_accepted_at, r.first.regulation_accepted_at);
  assert.equal(r.second.regulation_accepted_by, 1);
  for (const key of ['regulation_version', 'regulation_accepted_at', 'regulation_accepted_by']) assert.equal(r.cleared[key], null);
});
test('missing or stale consent identity fails before any write with a fixed account-free explanation', t => {
  const r = run(t, 'stale'); if (!r) return;
  assert.equal(r.writes, 0); assert.equal(r.row.regulation_version, null);
  assert.equal(new Set(r.responses.map(v => v.message)).size, 1);
  for (const response of r.responses) {
    assert.equal(response.ok, false); assert.equal(response.status, 422);
    assert.ok(response.message.length > 10); assert.ok(!response.message.includes('PRIVATE_ACCOUNT_VALUE'));
    assert.ok(!response.message.includes('2000-01-01')); assert.ok(!response.message.includes('a'.repeat(64)));
  }
});
test('a version without a recorded consent date/actor is incomplete and can be explicitly repaired', t => {
  const r = run(t, 'repair'); if (!r) return;
  assert.equal(r.before.current, false); assert.equal(r.before.accepted, false);
  assert.equal(r.after.current, true); assert.equal(r.after.accepted_at, '2026-10-23 12:00:00');
});
test('A to B to A uploads retain immutable files without changing employee revision and serve verified bytes', t => {
  const r = run(t, 'replace'); if (!r) return;
  assert.equal(r.retained_a_after_b, true); assert.equal(r.retained_b_after_a, true);
  assert.equal(r.current_a, true); assert.equal(r.first_revision, r.last_revision);
  assert.equal(r.served_hash, r.hash, 'A later pathname change must not change already verified response bytes.');
});
test('identity writes enforce owner role and branch scope, and private reads enforce branch scope', t => {
  const r = run(t, 'scope'); if (!r) return;
  for (const key of ['vendor', 'branch', 'read_other']) assert.equal(r[key].status, 403, key);
  assert.equal(r.missing.status, 404);
});
test('identity storage rejects public disk roots, directory/file symlinks and corrupted photo bytes', t => {
  const r = run(t, 'storage'); if (!r) return;
  for (const response of Object.values(r)) { assert.equal(response.ok, false); assert.equal(response.status, 409); }
});
test('migration repairs a partial nine-column cohort, is idempotent, and readiness checks each column', t => {
  const r = run(t, 'migration'); if (!r) return;
  assert.equal(r.before, false); assert.equal(r.ready, true); assert.equal(r.alters_after_repeat, 1);
  assert.equal(Object.keys(r.columns).length, 9);
  for (const value of Object.values(r.columns)) assert.equal(value.nullable, true);
  assert.equal(r.columns.id_front_path.length, 300); assert.equal(r.columns.id_back_hash.type, 'char');
  assert.equal(r.columns.id_front_mime.length, 20); assert.equal(r.columns.regulation_version.length, 20);
  assert.equal(r.columns.regulation_accepted_at.type, 'timestamp'); assert.equal(r.columns.regulation_accepted_by.type, 'unsignedBigInteger');
  assert.ok(Object.values(r.missing).every(value => value === false));
  assert.deepEqual(r.after_down, []);
});
