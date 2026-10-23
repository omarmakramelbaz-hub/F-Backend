'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const readSnapshot = require('../src/dashboard-download.cjs');

const php = process.env.DESKTOP_TEST_PHP || 'php';
const script = path.resolve(__dirname, '../../deployment/diagnose_go_erp_numeric_transport.php');
const bootstrap = path.resolve(__dirname, '../../app/Services/Dashboard/DesktopDashboardBootstrap.php');
const options = { encoding: 'utf8', timeout: 15000, windowsHide: true, maxBuffer: 2 * 1024 * 1024 };
function available(t) {
  const result = spawnSync(php, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && result.error?.code === 'ENOENT') {
    t.skip('PHP is unavailable; set DESKTOP_TEST_PHP for the cross-precision transport proof.');
    return false;
  }
  assert.ifError(result.error); assert.equal(result.status, 0);
  assert.ok(Number(result.stdout) >= 80000);
  return true;
}
function run(args, input) {
  const result = spawnSync(php, ['-n', ...args], { ...options, input });
  assert.ifError(result.error); assert.equal(result.status, 0, 'The synthetic PHP probe must complete.');
  assert.equal(result.stderr, '');
  return result.stdout;
}
function report() { return JSON.parse(run([script])); }

test('different PHP serialize_precision reproduces table hash rejection without account data', t => {
  if (!available(t)) return;
  const value = report();
  assert.equal(value.result, 'GO_ERP_SYNTHETIC_NUMERIC_TRANSPORT');
  assert.equal(value.synthetic_only, true); assert.equal(value.real_account_read, false);
  assert.equal(value.database_connected, false); assert.equal(value.synthetic_users, 11132);
  assert.equal(value.matrix.length, 9);
  for (const row of value.matrix) {
    if (row.server_serialize_precision === row.desktop_serialize_precision)
      assert.deepEqual(row.failed_tables, []);
    if (new Set([row.server_serialize_precision, row.desktop_serialize_precision]).size === 2
        && [row.server_serialize_precision, row.desktop_serialize_precision].every(n => [-1, 17].includes(n))) {
      for (const name of ['float_point_one', 'float32_point_nine', 'exponents', 'synthetic_11132_users'])
        assert.ok(row.failed_tables.includes(name));
    }
  }
});

test('streamed table hashing is identical to whole table encoding for each server setting', t => {
  if (!available(t)) return;
  assert.deepEqual(report().stream_hash_matches_whole_table, { '-1': true, '17': true, '14': true });
});

test('generic precision changes do not repair JSON hashes and all changed INI values are restored', t => {
  if (!available(t)) return;
  const value = JSON.parse(run(['-d', 'serialize_precision=17', '-d', 'precision=6', script]));
  assert.deepEqual(value.configured_ini, { serialize_precision: '17', precision: '6' });
  assert.equal(value.ini_restored, true);
  assert.equal(new Set(Object.values(value.generic_precision_control)).size, 1);
});

test('decimal strings, negative zero, integral float types and large integers survive raw PHP wire', t => {
  if (!available(t)) return;
  for (const row of report().matrix) {
    for (const flag of ['negative_zero_preserved', 'decimal_string_exact', 'large_integer_exact', 'integral_float_preserved'])
      assert.equal(row[flag], true, flag);
  }
});

test('the actual line-46 hash comparison still rejects changed rows under matching encoders', t => {
  if (!available(t)) return;
  const matching = report().matrix.filter(row => row.server_serialize_precision === row.desktop_serialize_precision);
  assert.equal(matching.length, 3);
  for (const row of matching) assert.equal(row.tampered_row_rejected, true);
});

test('raw downloaded transport preserves the server PHP types while JavaScript re-encoding loses them', async t => {
  if (!available(t)) return;
  const wire = run(['-d', 'serialize_precision=17', script, '--fixture-wire']);
  const snapshot = await readSnapshot(new Response(wire, { headers: { 'content-type': 'application/json' } }));
  assert.equal(readSnapshot.input(snapshot), wire);
  const consumer = String.raw`
    require $argv[1];
    $data=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$matches=[];
    foreach($data['tables'] as $name=>$part)$matches[$name]=hash_equals($part['sha256'],hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($part['rows'])));
    echo json_encode($matches,JSON_THROW_ON_ERROR);
  `;
  const preserved = JSON.parse(run(['-d', 'serialize_precision=17', '-r', consumer, bootstrap], readSnapshot.input(snapshot)));
  assert.ok(Object.values(preserved).every(Boolean));
  const former = JSON.parse(run(['-d', 'serialize_precision=17', '-r', consumer, bootstrap], JSON.stringify(snapshot)));
  for (const name of ['negative_zero', 'integral_float', 'large_integer']) assert.equal(former[name], false);
  assert.equal(former.decimal_string, true);
});

test('the synthetic diagnostic refuses unsupported arguments with a fixed data-free failure', t => {
  if (!available(t)) return;
  const result = spawnSync(php, ['-n', script, '--unrecognized'], options);
  assert.ifError(result.error); assert.equal(result.status, 1); assert.equal(result.stdout, '');
  assert.equal(result.stderr, 'GO_ERP_SYNTHETIC_NUMERIC_TRANSPORT_FAILED\n');
});
