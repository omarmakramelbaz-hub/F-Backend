'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path');
const { execFileSync } = require('node:child_process');
const build = require('../build-runtime.cjs');

test('the actual source bundle excludes renamed production credentials and keeps original public dashboard assets', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'dashboard-package-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const source = path.join(root, 'source'), dependencyRoot = path.join(root, 'deps'), target = path.join(root, 'bundle');
  await fs.mkdir(source);
  const write = async (base, file, text) => { const full = path.join(base, file); await fs.mkdir(path.dirname(full), { recursive: true }); await fs.writeFile(full, text); };
  const files = {
    'artisan': '<?php /* original entry point */',
    'app/Controllers.php': '<?php /* original business code */',
    'public/firebase_credentials.json': '{"type":"service_account"}',
    'public/firebase_credentials.json-old': '{"private_key":"SYNTHETIC_KEY"}',
    'public/firebase_credentials_old.json': '{"private_key":"SYNTHETIC_KEY"}',
    'public/firebase_credentials_test_backup.json': '{"private_key":"SYNTHETIC_KEY"}',
    'public/old_firebase_credentials.json': '{"private_key":"SYNTHETIC_KEY"}',
    'public/FIREBASE_CREDENTIALS-UPPER.JSON': '{"private_key":"SYNTHETIC_KEY"}',
    'public/client-config.json': '{"arbitrary":{"client_secret":"SYNTHETIC_SECRET"}}',
    'public/account-backup.json': '{"nested":[{"type":"service_account"}]}',
    'public/signing.pem.backup': 'SYNTHETIC_SIGNING_KEY',
    'config/.env-backup': 'SYNTHETIC_DATABASE_PASSWORD',
    'public/firebase-messaging-sw.js': '/* original public service worker */',
    'config/firebase.php': '<?php return [];',
    'public/catalog.json': '{"items":[{"name":"رنجة","price":100}]}',
    'public/dashboard/vendor/desktop-external/manifest.json': JSON.stringify({ format: 1, complete: true, assets: {}, licenses: {}, distributions: {} })
  };
  for (const [file, bytes] of Object.entries(files)) await write(source, file, bytes);
  await write(dependencyRoot, 'vendor/autoload.php', '<?php');
  await write(dependencyRoot, 'composer.json', '{}');
  await write(dependencyRoot, 'composer.lock', JSON.stringify({ packages: [{ name: 'laravel/framework', version: 'v8.83.29' }] }));
  const git = args => execFileSync('git', args, { cwd: source, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  git(['init']); git(['add', '.']); git(['-c','user.name=Fixture','-c','user.email=fixture@test.invalid','commit','-m','synthetic package fixture']);
  await build({ source, target, dependencyRoot, revision: git(['rev-parse', 'HEAD']).trim() });
  const manifest = JSON.parse(await fs.readFile(path.join(target, 'manifest.json'), 'utf8'));
  for (const file of ['artisan','app/Controllers.php','config/firebase.php','public/firebase-messaging-sw.js','public/catalog.json']) {
    assert.ok(manifest.sourceHashes[file]); assert.equal(await fs.readFile(path.join(target, 'application', file), 'utf8'), files[file]);
  }
  for (const file of Object.keys(files).filter(file => /credentials|client-config|account-backup|signing\.pem|\.env-backup/i.test(file))) {
    assert.equal(manifest.sourceHashes[file], undefined); await assert.rejects(fs.stat(path.join(target, 'application', file)), { code: 'ENOENT' });
  }
});
