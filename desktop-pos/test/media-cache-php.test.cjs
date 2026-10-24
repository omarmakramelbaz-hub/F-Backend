'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
test('shared server media checksum metadata never bypasses scope, descriptor binding or actual download hashing', t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php', options = { encoding: 'utf8', timeout: 15000, windowsHide: true };
  // The configured CLI may live elsewhere than the compiled -n extension_dir.
  const probe = spawnSync(php, ['-r', 'echo json_encode(["binary"=>PHP_BINARY,"directory"=>ini_get("extension_dir")],JSON_THROW_ON_ERROR);'], options);
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && probe.error?.code === 'ENOENT') { t.skip('PHP is unavailable'); return; }
  assert.ifError(probe.error); assert.equal(probe.status, 0, probe.stderr);
  const actual = JSON.parse(probe.stdout);
  assert.equal(typeof actual.binary, 'string'); assert.ok(path.isAbsolute(actual.binary));
  assert.equal(typeof actual.directory, 'string');
  const resolvedPhp = fs.realpathSync(actual.binary);
  const extensionName = process.platform === 'win32' ? 'php_fileinfo.dll' : 'fileinfo.so';
  const privateRoot = resolvedPhp.match(/^(.*)\/usr\/bin\/[^/]+$/)?.[1];
  const candidates = [path.join(path.dirname(resolvedPhp), 'ext', extensionName)];
  if (path.isAbsolute(actual.directory)) {
    // Portable extracted Linux binaries may retain an absolute compiled prefix.
    if (privateRoot) candidates.push(path.join(privateRoot, actual.directory, extensionName));
    candidates.push(path.join(actual.directory, extensionName));
  }
  const extension = process.env.DESKTOP_TEST_PHP_FILEINFO || candidates.find(file => fs.existsSync(file)) || 'fileinfo';
  const result = spawnSync(php, ['-n', '-d', 'extension=' + extension, path.resolve(__dirname, '../../tests/desktop_dashboard_media_cache_test.php')], options);
  assert.ifError(result.error); assert.equal(result.status, 0, result.stdout + result.stderr); assert.match(result.stdout, /PASS [0-9]+ shared media checksum cache checks/);
});
test('batched manifest media owners match original per-ticket authorization without using FK-expanded account rows', t => {
  const php = process.env.DESKTOP_TEST_PHP || 'php';
  const result = spawnSync(php, ['-n', path.resolve(__dirname, '../../tests/desktop_dashboard_media_owners_test.php')], { encoding: 'utf8', timeout: 15000, windowsHide: true });
  if (!process.env.DESKTOP_TEST_PHP && !process.env.CI && result.error?.code === 'ENOENT') { t.skip('PHP is unavailable'); return; }
  assert.ifError(result.error); assert.equal(result.status, 0, result.stdout + result.stderr); assert.match(result.stdout, /PASS [0-9]+ exact scoped media owner checks/);
});
