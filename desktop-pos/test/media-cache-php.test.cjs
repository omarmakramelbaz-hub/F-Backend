'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
test('shared server media checksum metadata never bypasses scope, descriptor binding or actual download hashing', t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php', options = { encoding: 'utf8', timeout: 15000, windowsHide: true };
  const probe = spawnSync(php, ['-n', '-r', 'echo PHP_EXTENSION_DIR;'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && probe.error?.code === 'ENOENT') { t.skip('PHP is unavailable'); return; }
  assert.ifError(probe.error); assert.equal(probe.status, 0, probe.stderr);
  let extension = process.env.DESKTOP_TEST_PHP_FILEINFO || 'fileinfo';
  const dll = path.join(path.dirname(php), 'ext', 'php_fileinfo.dll');
  const resolvedPhp = fs.existsSync(php) ? fs.realpathSync(php) : php;
  const privateRoot = resolvedPhp.match(/^(.*)\/usr\/bin\/[^/]+$/)?.[1];
  const extracted = privateRoot && path.join(privateRoot, probe.stdout.trim(), 'fileinfo.so');
  if (fs.existsSync(dll)) extension = dll; else if (extracted && fs.existsSync(extracted)) extension = extracted;
  const result = spawnSync(php, ['-n', '-d', 'extension=' + extension, path.resolve(__dirname, '../../tests/desktop_dashboard_media_cache_test.php')], options);
  assert.ifError(result.error); assert.equal(result.status, 0, result.stdout + result.stderr); assert.match(result.stdout, /PASS [0-9]+ shared media checksum cache checks/);
});
test('batched manifest media owners match original per-ticket authorization without using FK-expanded account rows', t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php';
  const result = spawnSync(php, ['-n', path.resolve(__dirname, '../../tests/desktop_dashboard_media_owners_test.php')], { encoding: 'utf8', timeout: 15000, windowsHide: true });
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && result.error?.code === 'ENOENT') { t.skip('PHP is unavailable'); return; }
  assert.ifError(result.error); assert.equal(result.status, 0, result.stdout + result.stderr); assert.match(result.stdout, /PASS [0-9]+ exact scoped media owner checks/);
});
