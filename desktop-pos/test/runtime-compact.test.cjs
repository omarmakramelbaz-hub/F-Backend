'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const RuntimeArchive = require('../src/runtime-archive.cjs');
const LocalRuntime = require('../src/local-runtime.cjs');
const { artifactPins, receiptDigest, SCOPE, PREFIX } = require('../src/runtime-compact.cjs');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

async function fixture(t) {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-compact-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const profile = path.join(root, 'profile'), old = path.join(root, 'old'), installed = path.join(root, 'installed');
  await fs.mkdir(profile);
  const values = new Map(), metadata = { read: async name => structuredClone(values.get(name) || null),
    write: async (name, value) => { values.set(name, structuredClone(value)); } };
  const write = async (directory, name, raw) => {
    const target = path.join(directory, ...name.split('/')); await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.writeFile(target, raw);
  };
  const a = 'a'.repeat(40), b = 'b'.repeat(40);
  const manifest = { format: 1, platform: 'win32-x64', sourceRevision: a, sourceHashes: {},
    sourceFingerprint: { format: 1, files: 1, framework: '8.83.29', sha256: 'c'.repeat(64) } };
  await write(old, 'manifest.json', JSON.stringify(manifest));
  await write(old, 'application/vendor/autoload.php', '<?php /* original Composer autoload */');
  await write(old, PREFIX + 'Drive.php', '<?php /* original Google Drive */');
  await write(old, PREFIX + 'Drive/DriveFile.php', '<?php /* original Google resource */');
  await write(old, 'application/app/Business.php', '<?php /* same original business code */');
  await write(old, 'php/php.exe', 'synthetic preserved native binary');
  await write(old, 'mariadb/bin/mariadbd.exe', 'synthetic preserved database binary');
  const original = new RuntimeArchive(profile, metadata), legacy = await original.retain(old);
  const receipt = await metadata.read('runtimes/' + a);
  await fs.cp(old, installed, { recursive: true });
  const archive = Buffer.from('synthetic opaque PHAR fixture; actual PHP is tested independently');
  const index = { format: 1, kind: 'go-erp-google-services-phar', scope: SCOPE,
    archive: { file: 'google-services.phar', sha256: sha(archive), bytes: archive.length },
    files: Object.fromEntries(Object.entries(receipt.files).filter(([name]) => name.startsWith(PREFIX)).map(([name, hash]) => [name.slice(12), hash])) };
  await write(installed, 'compact-vendor/vendor-phar-index.json', JSON.stringify(index) + '\n');
  await write(installed, 'compact-vendor/google-services.phar', archive);
  await write(installed, 'compact-vendor/autoload.php', '<?php /* trusted installed native adapter */');
  const artifact = await artifactPins(installed), pins = { ...artifact.pins, producerRevision: b };
  await write(installed, 'manifest.json', JSON.stringify({ ...manifest, sourceRevision: b, compactVendor: pins }));
  return { root, profile, old, installed, values, metadata, legacy, receipt, pins, index, a, b, write };
}

test('compact conversion uses authenticated package hashes without reading old generated classes or changing the original runtime', async t => {
  const f = await fixture(t), before = structuredClone(f.receipt), calls = [];
  // A changed old class is not adopted: the fresh compiled pack contains the
  // exact ORIGINAL hashes, so this potentially slow file is never opened.
  const altered = path.join(f.legacy, PREFIX + 'Drive.php');
  await fs.writeFile(altered, '<?php /* changed but never executed */');
  const originalCp = fs.cp;
  fs.cp = async (from, to, options) => originalCp(from, to, { ...options, filter: async source => {
    calls.push(source); return options.filter(source);
  } });
  t.after(() => { fs.cp = originalCp; });
  const archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  const selected = await archive.select(f.installed, { sourceRevision: f.a });
  assert.notEqual(selected, f.legacy);
  const scopeDirectory = path.join(f.legacy, ...PREFIX.slice(0, -1).split('/'));
  assert.ok(calls.includes(scopeDirectory));
  assert.equal(calls.some(name => name.startsWith(scopeDirectory + path.sep)), false);
  assert.equal(await fs.readFile(altered, 'utf8'), '<?php /* changed but never executed */');
  assert.deepEqual(await f.metadata.read('runtimes/' + f.a), before);
  const receipt = await f.metadata.read('compact-runtimes/' + f.a);
  assert.equal(receipt.revision, f.a); assert.equal(receipt.compactVendor.originalReceiptSha256, receiptDigest(before));
  assert.equal(Object.keys(receipt.files).some(name => name.startsWith(PREFIX)), false);
  assert.equal(receipt.files['application/vendor/autoload.original.php'], before.files['application/vendor/autoload.php']);
  assert.deepEqual(await archive.sourceFingerprint(selected), JSON.parse(await fs.readFile(path.join(selected, 'manifest.json'))).sourceFingerprint);
  assert.equal(archive.verified.get(path.resolve(selected)).manifest.sourceRevision, f.a);
});

test('an established compact receipt remains authoritative when a later installer has different or missing native pins', async t => {
  const f = await fixture(t), first = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  const selected = await first.select(f.installed, { sourceRevision: f.a });
  for (const compactPins of [null, { ...f.pins, producerRevision: 'd'.repeat(40) }]) {
    const next = new RuntimeArchive(f.profile, f.metadata, { compactPins });
    assert.equal(await next.select(f.installed, { sourceRevision: f.a }), selected);
  }
});

test('a newly prepared runtime retains one active pack and does not rehash compiler artifact copies on later launches', async t => {
  const f = await fixture(t), normal = new RuntimeArchive(f.profile, f.metadata);
  await normal.retain(f.installed);
  const original = await f.metadata.read('runtimes/' + f.b);
  assert.ok(original.files['compact-vendor/google-services.phar']);
  const archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  const selected = await archive.select(f.installed, { sourceRevision: f.b });
  const receipt = await f.metadata.read('compact-runtimes/' + f.b);
  assert.equal(receipt.files['application/google-services.phar'], f.pins.archiveSha256);
  assert.equal(Object.keys(receipt.files).some(name => name.startsWith('compact-vendor/')), false);
  assert.equal(Object.keys(receipt.files).some(name => name.startsWith(PREFIX)), false);
  await assert.rejects(fs.access(path.join(selected, 'compact-vendor')));
  assert.deepEqual(await f.metadata.read('runtimes/' + f.b), original);
});

test('every warm compact launch freshly rejects same-length same-mtime PHAR tampering and preserves the altered file', async t => {
  const f = await fixture(t), archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  const selected = await archive.select(f.installed, { sourceRevision: f.a });
  const file = path.join(selected, 'application/google-services.phar'), before = await fs.stat(file), bytes = await fs.readFile(file);
  bytes[0] ^= 1; await fs.writeFile(file, bytes); await fs.utimes(file, before.atime, before.mtime);
  const next = new RuntimeArchive(f.profile, f.metadata, { compactPins: null });
  await assert.rejects(next.select(f.installed, { sourceRevision: f.a }));
  assert.deepEqual(await fs.readFile(file), bytes);
  assert.ok(await f.metadata.read('runtimes/' + f.a));
});

test('a different Google package falls back to freshly verifying the original runtime without creating a compact receipt', async t => {
  const f = await fixture(t);
  const indexPath = path.join(f.installed, 'compact-vendor/vendor-phar-index.json');
  f.index.files[SCOPE + 'Drive.php'] = 'e'.repeat(64);
  await fs.writeFile(indexPath, JSON.stringify(f.index) + '\n');
  const artifact = await artifactPins(f.installed), pins = { ...artifact.pins, producerRevision: f.b };
  const manifest = JSON.parse(await fs.readFile(path.join(f.installed, 'manifest.json')));
  await fs.writeFile(path.join(f.installed, 'manifest.json'), JSON.stringify({ ...manifest, compactVendor: pins }));
  const archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: pins });
  assert.equal(await archive.select(f.installed, { sourceRevision: f.a }), f.legacy);
  assert.equal(await f.metadata.read('compact-runtimes/' + f.a), null);
});

test('artifact rewriting cannot change native adoption authority, and a missing native pin retains legacy behavior', async t => {
  const f = await fixture(t), archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  await fs.writeFile(path.join(f.installed, 'compact-vendor/autoload.php'), '<?php /* untrusted replacement */');
  await assert.rejects(archive.select(f.installed, { sourceRevision: f.a }));
  assert.equal(await f.metadata.read('compact-runtimes/' + f.a), null);
  const legacy = new RuntimeArchive(f.profile, f.metadata, { compactPins: null });
  assert.equal(await legacy.select(f.installed, { sourceRevision: f.a }), f.legacy);
});

test('an artifact directory link cannot redirect compact adoption outside the installed bundle', async t => {
  const f = await fixture(t), original = path.join(f.installed, 'compact-vendor'), external = path.join(f.root, 'external-compiler');
  await fs.rename(original, external); await fs.symlink(external, original, 'junction');
  const archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins });
  await assert.rejects(archive.select(f.installed, { sourceRevision: f.a }));
  assert.deepEqual(await f.metadata.read('runtimes/' + f.a), f.receipt);
  assert.equal(await f.metadata.read('compact-runtimes/' + f.a), null);
});

test('cancelled compact copying removes only its incomplete stage and leaves original receipt and runtime untouched', async t => {
  const f = await fixture(t); let stopped = false, copies = 0;
  const before = await new RuntimeArchive(f.profile, f.metadata).inventory(f.legacy), originalCp = fs.cp;
  fs.cp = async (from, to, options) => originalCp(from, to, { ...options, filter: async source => {
    if (++copies === 3) stopped = true;
    return options.filter(source);
  } });
  t.after(() => { fs.cp = originalCp; });
  const archive = new RuntimeArchive(f.profile, f.metadata, { compactPins: f.pins, stopped: () => stopped });
  await assert.rejects(archive.select(f.installed, { sourceRevision: f.a }), /يُغلق/);
  assert.deepEqual(await new RuntimeArchive(f.profile, f.metadata).inventory(f.legacy), before);
  assert.deepEqual(await f.metadata.read('runtimes/' + f.a), f.receipt);
  assert.equal(await f.metadata.read('compact-runtimes/' + f.a), null);
  assert.equal((await fs.readdir(path.join(f.profile, 'compact-runtimes'))).some(name => name.includes('.stage-')), false);
});

test('runtime context handoffs never carry an old compact PHAR path into another native source', () => {
  const old = { DB_DATABASE: 'retained', DESKTOP_DASHBOARD_VENDOR_PHAR: '/other/profile/private.phar' };
  const normal = LocalRuntime.contextEnvironment({ bundle: '/native/plain', application: '/native/plain/application', manifest: {} }, old);
  assert.equal(Object.hasOwn(normal, 'DESKTOP_DASHBOARD_VENDOR_PHAR'), false);
  const compact = LocalRuntime.contextEnvironment({ bundle: '/native/compact', application: '/native/compact/application',
    manifest: { compactVendor: { kind: 'go-erp-compact-vendor' } } }, old);
  assert.equal(compact.DESKTOP_DASHBOARD_VENDOR_PHAR, path.join('/native/compact/application', 'google-services.phar'));
  assert.equal(old.DESKTOP_DASHBOARD_VENDOR_PHAR, '/other/profile/private.phar');
});

test('only a verified compact runtime preloads its own archive and clears stale compiler cache paths', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-phar-ini-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const bundle = path.join(root, 'runtime'), profile = path.join(root, 'profile');
  for (const name of ['application', 'php/ext', 'php/ssl']) await fs.mkdir(path.join(bundle, name), { recursive: true });
  await fs.mkdir(profile);
  await fs.writeFile(path.join(bundle, 'php/php.ini'), 'phar.cache_list=C:/untrusted/other.phar\n');
  await fs.writeFile(path.join(bundle, 'php/ssl/cacert.pem'), 'synthetic certificate');
  const filename = path.join(__dirname, '../src/local-runtime.cjs');
  const context = vm.createContext({ module: { exports: {} }, require: createRequire(filename), Buffer, process, URL, Error, JSON,
    AbortSignal, fetch, setTimeout, clearTimeout });
  vm.runInContext(await fs.readFile(filename, 'utf8') + '\nmodule.exports={phpIniFor};', context, { filename });
  const build = context.module.exports.phpIniFor;
  for (const compactVendor of [undefined, { kind: 'go-erp-compact-vendor' }]) {
    const target = await build(bundle, { sourceRevision: 'f'.repeat(40), compactVendor }, profile);
    const configuration = await fs.readFile(target, 'utf8');
    const lists = [...configuration.matchAll(/^phar\.cache_list=(.*)$/gm)];
    assert.equal(lists.at(-1)[1], compactVendor ? '"google-services.phar"' : '');
    if (compactVendor) {
      assert.match(configuration, /^phar\.readonly=1$/m);
      assert.match(configuration, /^phar\.require_hash=1$/m);
    }
  }
  assert.equal(await fs.readFile(path.join(bundle, 'php/php.ini'), 'utf8'), 'phar.cache_list=C:/untrusted/other.phar\n');
});
