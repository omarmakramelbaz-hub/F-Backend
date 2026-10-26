'use strict';
// Actual bundle conversion for the existing PHP/HTTP acceptance fixtures. The
// metadata adapter below is synthetic; native DPAPI acceptance is separate.
const fs = require('node:fs/promises');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const RuntimeArchive = require('../src/runtime-archive.cjs');
const source = require('../src/dashboard-source.cjs');
const packaging = require('../installer-runtime.cjs');
const { PREFIX } = require('../src/runtime-compact.cjs');

async function run(bundle, profile, php) {
  bundle = path.resolve(bundle); profile = path.resolve(profile);
  await fs.mkdir(profile, { recursive: false, mode: 0o700 });
  await fs.writeFile(path.join(profile, 'disposable-compact-probe.marker'), 'GO_ERP_DISPOSABLE_COMPACT_PROBE_ONLY\n', { flag: 'wx' });
  // This independently verifies native executables, original source, every
  // package class, PHAR entry bytes and the compiler/index relation.
  const packaged = await packaging.verifyBundle(bundle);
  assert.ok(packaged.compactVendorPins, 'The actual bundle must contain its verified compact artifact.');
  const metadataValues = new Map();
  const metadata = { read: async name => structuredClone(metadataValues.get(name) || null),
    write: async (name, value) => { metadataValues.set(name, structuredClone(value)); } };
  const before = { format: 1, revision: packaged.manifest.sourceRevision, files: packaged.files,
    sourceFingerprint: packaged.manifest.sourceFingerprint };
  await metadata.write('runtimes/' + before.revision, before);
  const archive = new RuntimeArchive(profile, metadata, { compactPins: packaged.compactVendorPins });
  const compact = await archive.compactor.select(bundle, bundle, before);
  assert.notEqual(compact, bundle);
  const after = await metadata.read('compact-runtimes/' + packaged.manifest.sourceRevision);
  assert.deepEqual(await metadata.read('runtimes/' + packaged.manifest.sourceRevision), before);
  assert.equal(Object.keys(after.files).some(name => name.startsWith(PREFIX) || name.startsWith('compact-vendor/')), false);
  assert.deepEqual(await archive.sourceFingerprint(compact), packaged.manifest.sourceFingerprint);
  assert.deepEqual(await source.fingerprint(path.join(compact, 'application')), packaged.manifest.sourceFingerprint);
  assert.deepEqual(await archive.inventory(bundle), before.files, 'Conversion must not change the actual original normal bundle.');
  const originalEnvironment = { ...process.env, DESKTOP_DASHBOARD_LOCAL: 'true',
    DESKTOP_DASHBOARD_VENDOR_PHAR: path.join(compact, 'application/google-services.phar') };
  const phpProbe = JSON.parse(execFileSync(php, ['-r',
    "$loader=require $argv[1].'/vendor/autoload.php';if(!class_exists('Google\\\\Service\\\\Drive')||!class_exists('Illuminate\\\\Foundation\\\\Application'))throw new RuntimeException('COMPACT_PHP_CLASS_MISSING');echo json_encode(['actualPhp'=>true,'googleArchive'=>str_starts_with((new ReflectionClass('Google\\\\Service\\\\Drive'))->getFileName(),'phar://'),'laravelPhysical'=>realpath((new ReflectionClass('Illuminate\\\\Foundation\\\\Application'))->getFileName())!==false]);",
    path.join(compact, 'application')], { env: originalEnvironment, encoding: 'utf8', timeout: 60000, windowsHide: true }));
  assert.equal(phpProbe.googleArchive, true); assert.equal(phpProbe.laravelPhysical, true);
  // A later boot relies on its established receipt, even if a newer installer
  // has no compact artifact for this older, still active source revision.
  const reopened = new RuntimeArchive(profile, metadata, { compactPins: null });
  assert.equal(await reopened.select(bundle, { sourceRevision: packaged.manifest.sourceRevision }), compact);
  if (process.env.CI !== 'true' || process.env.GITHUB_SHA !== packaged.manifest.sourceRevision) {
    throw Error('A disposable exact-source CI checkout is required to publish fixture native pins.');
  }
  // Test-only generated asset. The production installer independently writes
  // and verifies the same fixed ASAR asset after its own source/audit gates.
  await fs.writeFile(path.resolve(__dirname, '../src/compact-vendor-pins.json'), JSON.stringify(packaged.compactVendorPins) + '\n');
  const proof = { format: 1, kind: 'go-erp-runtime-compact-php-probe', sourceRevision: packaged.manifest.sourceRevision,
    application: path.join(compact, 'application'), runtime: compact,
    filesBefore: Object.keys(before.files).length, filesAfter: Object.keys(after.files).length,
    googleFilesCompacted: Object.keys(before.files).filter(name => name.startsWith(PREFIX)).length,
    unchangedBusinessSource: true, originalReceiptUnchanged: true, originalBundleUnchanged: true, reopenedWithoutNewInstallerPins: true,
    actualPhp: phpProbe.actualPhp, actualGoogleArchiveClass: true, actualPhysicalLaravelClass: true, generatedFixtureNativePins: true,
    actualBundle: true, independentArchiveEntryVerification: true, actualEncryptedMetadata: false,
    productionProfile: false, databaseRead: false, completed: true };
  await fs.writeFile(path.join(profile, 'proof.json'), JSON.stringify(proof, null, 2) + '\n', { flag: 'wx' });
  process.stdout.write('GO_ERP_RUNTIME_COMPACT_PHP_PROBE ' + JSON.stringify(proof) + '\n');
  return proof;
}
module.exports = run;
if (require.main === module) {
  const [bundle, profile, php] = process.argv.slice(2);
  if (!bundle || !profile || !php) throw Error('Usage: runtime-compact-php-acceptance actual-bundle new-disposable-profile php');
  run(bundle, profile, php).catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
}
