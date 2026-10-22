'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const readSnapshot = require('../src/dashboard-download.cjs');

function response(text) {
  const bytes = Buffer.from(text, 'utf8');
  return new Response(new ReadableStream({
    start(controller) {
      // Split multibyte text and numeric tokens across response chunks.
      for (let offset = 0; offset < bytes.length; offset += 3) controller.enqueue(bytes.subarray(offset, offset + 3));
      controller.close();
    }
  }), { headers: { 'content-type': 'application/json' } });
}

test('downloaded snapshots retain exact JSON and freeze the parsed metadata used before import', async () => {
  const wire = ' { "tables": { "sample": { "rows": [{"whole":1.0,"negative":-0.0,"label":"رنجة"}] } }, "coverage": { "routes": ["customers.save"] } }\n';
  const snapshot = await readSnapshot(response(wire));
  assert.equal(readSnapshot.input(snapshot), wire);
  assert.notEqual(JSON.stringify(snapshot), wire);
  assert.deepEqual(Reflect.ownKeys(snapshot), ['tables', 'coverage']);
  for (const value of [snapshot, snapshot.tables, snapshot.tables.sample, snapshot.tables.sample.rows,
    snapshot.tables.sample.rows[0], snapshot.coverage, snapshot.coverage.routes]) assert.ok(Object.isFrozen(value));
  assert.throws(() => { snapshot.tables.sample.rows[0].whole = 2; }, TypeError);
  assert.throws(() => { snapshot.coverage.routes.push('unreviewed.operation'); }, TypeError);
  assert.throws(() => { snapshot.coverage = { routes: [] }; }, TypeError);
  assert.equal(readSnapshot.input(snapshot), wire);
});

test('ordinary constructed snapshots keep their current serialization and cannot spoof captured input', () => {
  const spoof = '{"different":"untrusted input"}';
  const snapshot = { format: 1, rows: [{ amount: 1.25 }], raw: spoof, rawJson: spoof, json: spoof, input: spoof };
  assert.equal(readSnapshot.input(snapshot), JSON.stringify(snapshot));
  assert.notEqual(readSnapshot.input(snapshot), spoof);
  snapshot.rows[0].amount = 2.5;
  assert.equal(readSnapshot.input(snapshot), JSON.stringify(snapshot));
});

test('spreading or cloning a downloaded snapshot intentionally loses its original input association', async () => {
  const wire = '{"rows":[{"whole":1.0,"large":9007199254740993}],"coverage":{"media":true}}';
  const snapshot = await readSnapshot(response(wire));
  for (const clone of [{ ...snapshot }, structuredClone(snapshot)]) {
    assert.equal(readSnapshot.input(clone), JSON.stringify(clone));
    assert.notEqual(readSnapshot.input(clone), wire);
  }
  assert.equal(readSnapshot.input(snapshot), wire);
});

test('PHP canonical table hashes survive the actual downloaded input path through JavaScript', async t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php';
  const options = { encoding: 'utf8', timeout: 10000, windowsHide: true, maxBuffer: 1024 * 1024 };
  const available = spawnSync(php, ['-n', '-r', 'echo PHP_VERSION_ID;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && available.error?.code === 'ENOENT') {
    t.skip('PHP is unavailable; set DESKTOP_TEST_PHP to run the PHP/JavaScript transport regression.');
    return;
  }
  assert.ifError(available.error);
  assert.equal(available.status, 0, 'The configured PHP executable must start.');
  assert.ok(Number(available.stdout) >= 80000, 'The transport fixture requires PHP 8 or newer.');
  const bootstrap = path.resolve(__dirname, '../../app/Services/Dashboard/DesktopDashboardBootstrap.php');
  function phpJson(code, input) {
    const result = spawnSync(php, ['-n', '-r', code, bootstrap], { ...options, input });
    assert.ifError(result.error);
    assert.equal(result.status, 0, 'The isolated PHP transport fixture must complete.');
    return result.stdout;
  }
  const wire = phpJson(String.raw`
    require $argv[1];
    $cases = [
      'integral_float' => [['value' => 1.0]],
      'negative_zero' => [['value' => -0.0]],
      'large_integer' => [['value' => 9007199254740993]],
      'decimal_control' => [['value' => 1.25, 'text' => 'حالة عادية']],
      'exponent_control' => [['small' => 1.0e-7, 'large' => 1.0e21, 'fraction' => 6.02214076e23]],
    ];
    $tables = [];
    foreach ($cases as $name => $rows) {
      $tables[$name] = ['rows' => $rows, 'sha256' => hash('sha256', \App\Services\Dashboard\DesktopDashboardBootstrap::json($rows))];
    }
    echo \App\Services\Dashboard\DesktopDashboardBootstrap::json(['tables' => $tables]);
  `);
  const verify = String.raw`
    require $argv[1];
    $snapshot = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $matches = [];
    foreach ($snapshot['tables'] as $name => $table) {
      $matches[$name] = hash_equals($table['sha256'], hash('sha256', \App\Services\Dashboard\DesktopDashboardBootstrap::json($table['rows'])));
    }
    echo json_encode([
      'matches' => $matches,
      'wholeIsFloat' => is_float($snapshot['tables']['integral_float']['rows'][0]['value']),
      'negativeZeroPreserved' => \App\Services\Dashboard\DesktopDashboardBootstrap::json($snapshot['tables']['negative_zero']['rows'][0]['value']) === '-0.0',
      'largeIntegerExact' => $snapshot['tables']['large_integer']['rows'][0]['value'] === 9007199254740993,
    ], JSON_THROW_ON_ERROR);
  `;
  const snapshot = await readSnapshot(response(wire));
  const preserved = JSON.parse(phpJson(verify, readSnapshot.input(snapshot)));
  assert.deepEqual(preserved, {
    matches: { integral_float: true, negative_zero: true, large_integer: true, decimal_control: true, exponent_control: true },
    wholeIsFloat: true, negativeZeroPreserved: true, largeIntegerExact: true
  });
  // This is the former production boundary, not a hash recomputed from JavaScript values.
  const former = JSON.parse(phpJson(verify, JSON.stringify(snapshot)));
  assert.deepEqual(former, {
    matches: { integral_float: false, negative_zero: false, large_integer: false, decimal_control: true, exponent_control: true },
    wholeIsFloat: false, negativeZeroPreserved: false, largeIntegerExact: false
  });
});
