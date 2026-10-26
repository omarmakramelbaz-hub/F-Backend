'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const compact = require('../build-vendor-phar.cjs');

function fixture() {
  return { format: 1, kind: 'go-erp-google-services-phar', scope: compact.SCOPE,
    archive: { file: 'google-services.phar', sha256: 'a'.repeat(64), bytes: 100 },
    files: { [compact.SCOPE + 'Drive.php']: 'b'.repeat(64), [compact.SCOPE + 'Drive/DriveFile.php']: 'c'.repeat(64) } };
}
test('compact Google package index cannot authorize other package or application files', () => {
  assert.equal(compact.validate(fixture()).scope, compact.SCOPE);
  for (const field of ['vendor/laravel/framework/src/A.php', 'app/Http/Kernel.php', compact.SCOPE + '../escape.php',
    compact.SCOPE + 'nested//escape.php', compact.SCOPE + 'nested/./escape.php', compact.SCOPE + 'bad\\name.php']) {
    const value = fixture(); value.files[field] = 'd'.repeat(64);
    assert.throws(() => compact.validate(value), /VENDOR_PHAR_INDEX_INVALID/);
  }
  const collision = fixture(); collision.files[compact.SCOPE + 'drive.php'] = 'd'.repeat(64);
  assert.throws(() => compact.validate(collision), /VENDOR_PHAR_INDEX_INVALID/);
  for (const change of [value => value.scope = 'vendor/', value => value.kind = 'other',
    value => value.archive.file = '../evil.phar', value => value.archive.bytes = -1,
    value => value.archive.bytes = Number.MAX_SAFE_INTEGER, value => value.files = {},
    value => value.files[compact.SCOPE + 'Drive.php'] = 'BAD']) {
    const value = fixture(); change(value);
    assert.throws(() => compact.validate(value), /VENDOR_PHAR_INDEX_INVALID/);
  }
});
test('legacy compatibility requires every packed byte hash from the authenticated old receipt', () => {
  const index = fixture();
  const receipt = { format: 1, files: Object.fromEntries(Object.entries(index.files).map(([name, hash]) => ['application/' + name, hash])) };
  receipt.files['application/vendor/autoload.php'] = 'e'.repeat(64);
  receipt.files['application/vendor/google/apiclient-services/autoload.php'] = 'f'.repeat(64);
  receipt.files['application/app/Http/Kernel.php'] = '1'.repeat(64);
  const before = structuredClone(receipt);
  assert.equal(compact.matchesReceipt(index, receipt), true);
  assert.deepEqual(receipt, before);
  const changed = structuredClone(receipt); changed.files['application/' + compact.SCOPE + 'Drive.php'] = '0'.repeat(64);
  assert.equal(compact.matchesReceipt(index, changed), false);
  const missing = structuredClone(receipt); delete missing.files['application/' + compact.SCOPE + 'Drive.php'];
  assert.equal(compact.matchesReceipt(index, missing), false);
  const added = structuredClone(receipt); added.files['application/' + compact.SCOPE + 'Extra.php'] = '2'.repeat(64);
  assert.equal(compact.matchesReceipt(index, added), false);
  assert.equal(compact.matchesReceipt(index, { format: 2, files: receipt.files }), false);
});
