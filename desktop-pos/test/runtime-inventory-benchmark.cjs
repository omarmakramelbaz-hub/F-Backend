'use strict';
// This is a disposable I/O benchmark, not a test of a production profile or a
// promise about any customer's hardware. Run on Windows for comparable evidence.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { performance } = require('node:perf_hooks');
const RuntimeArchive = require('../src/runtime-archive.cjs');
const Baseline = require('./runtime-inventory-baseline.cjs');

const BASELINE_REVISION = 'afb333bbddd9dbc908e3c3674d99c766ed5c4053';
const BASELINE_FILE_SHA256 = '0692861db717f9f3f54841b82ee3140a6e5ef0aecb219b54a7a4098d811cd4cc';
const BASELINE_METHOD_SHA256 = 'b30e0b8295767530b47a186bbc4a610894f0d708e5d25da0263fdb617f4bafef';
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const canonical = files => Object.entries(files).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0);
const digest = files => sha(JSON.stringify(canonical(files)));
const repository = path.resolve(__dirname, '../..');
const workerArchive = options => new RuntimeArchive('/unused-benchmark-profile', {}, options);
const sourceFiles = [
  'desktop-pos/src/runtime-archive.cjs', 'desktop-pos/src/runtime-inventory-worker.cjs',
  'desktop-pos/test/runtime-inventory-baseline.cjs', 'desktop-pos/test/runtime-inventory-benchmark.cjs',
  '.github/workflows/desktop-runtime-inventory-benchmark.yml'
];
async function bindings() {
  const result = {};
  for (const file of sourceFiles) result[file] = sha(await fs.readFile(path.join(repository, file)));
  return result;
}

function mix(smoke) {
  if (smoke) return [
    ...Array.from({ length: 2 }, (_, i) => ({ name: `php/ext/large-${i}.dll`, bytes: 2 * 1024 ** 2 })),
    ...Array.from({ length: 98 }, (_, i) => ({ name: `application/vendor/sample-${i}.php`, bytes: 4096 + i }))
  ];
  const files = [];
  for (let i = 0; i < 6; i++) files.push({ name: `mariadb/bin/native-${i}.dll`, bytes: 64 * 1024 ** 2 });
  for (let i = 0; i < 16; i++) files.push({ name: `php/ext/extension-${i}.dll`, bytes: 8 * 1024 ** 2 });
  for (let i = 0; i < 64; i++) files.push({ name: `application/public/assets/bundle-${i}.js`, bytes: 1024 ** 2 });
  for (let i = 0; i < 2000; i++) files.push({ name: `application/vendor/mid-${Math.floor(i / 100)}/file-${i}.php`, bytes: 64 * 1024 });
  const left = 50000 - files.length;
  const remaining = 815 * 1024 ** 2 - files.reduce((sum, file) => sum + file.bytes, 0);
  const size = Math.floor(remaining / left), extra = remaining % left;
  for (let i = 0; i < left; i++) files.push({
    name: `application/vendor/tiny-${Math.floor(i / 200)}/file-${i}.php`, bytes: size + (i < extra ? 1 : 0)
  });
  assert.equal(files.length, 50000);
  assert.equal(files.reduce((sum, file) => sum + file.bytes, 0), 815 * 1024 ** 2);
  return files;
}

async function populate(directory, files) {
  const parents = new Set(files.map(file => path.dirname(file.name)));
  for (const parent of parents) await fs.mkdir(path.join(directory, parent), { recursive: true });
  // Real allocated bytes, not sparse files. Keep generation memory bounded even
  // when the fixture includes several large native-style binaries.
  const block = Buffer.alloc(1024 ** 2, 0x61);
  for (const file of files.filter(file => file.bytes > block.length)) {
    const handle = await fs.open(path.join(directory, file.name), 'wx');
    try {
      let written = 0;
      while (written < file.bytes) {
        const count = Math.min(block.length, file.bytes - written);
        const result = await handle.write(block, 0, count, written);
        assert.ok(result.bytesWritten > 0);
        written += result.bytesWritten;
      }
    } finally { await handle.close(); }
  }
  const small = files.filter(file => file.bytes <= block.length);
  let next = 0;
  await Promise.all(Array.from({ length: 16 }, async () => {
    while (next < small.length) {
      const file = small[next++];
      await fs.writeFile(path.join(directory, file.name), block.subarray(0, file.bytes), { flag: 'wx' });
    }
  }));
}

async function measure(kind, directory, expected, label) {
  const archive = kind === 'baseline' ? new Baseline() : workerArchive();
  const originalHash = crypto.createHash;
  let parentHashCalls = 0;
  crypto.createHash = function (...args) { parentHashCalls++; return originalHash.apply(this, args); };
  const gaps = []; let last = performance.now();
  const timer = setInterval(() => { const now = performance.now(); gaps.push(now - last); last = now; }, 10);
  const started = performance.now(); let files, finished;
  try { files = await archive.inventory(directory); finished = performance.now(); }
  finally { clearInterval(timer); crypto.createHash = originalHash; }
  const milliseconds = finished - started, finalGap = finished - last;
  assert.equal(Object.keys(files).length, expected.length);
  assert.deepEqual(canonical(files).map(([name]) => name), expected.map(file => file.name).sort());
  if (kind === 'worker') {
    assert.equal(parentHashCalls, 0, 'File content hashing must stay off the parent/UI event loop.');
    assert.ok(gaps.length > 0, 'The parent event loop must make progress while the worker is scanning.');
  } else assert.equal(parentHashCalls, expected.length);
  const sortedGaps = gaps.slice().sort((a, b) => a - b);
  const result = {
    label, kind, milliseconds: Math.round(milliseconds * 1000) / 1000,
    inventorySha256: digest(files), parentHashCalls, heartbeatCount: gaps.length,
    heartbeatMaximumMs: Math.round(gaps.reduce((maximum, gap) => Math.max(maximum, gap), finalGap) * 1000) / 1000,
    heartbeatP99Ms: Math.round((sortedGaps[Math.floor(sortedGaps.length * 0.99)] || 0) * 1000) / 1000
  };
  console.log('RUNTIME_INVENTORY_MEASUREMENT ' + JSON.stringify(result));
  return result;
}

async function integrityProbe(root) {
  const directory = path.join(root, 'integrity'); await fs.mkdir(directory);
  const revision = 'a'.repeat(40);
  await fs.writeFile(path.join(directory, 'manifest.json'), JSON.stringify({ format: 1, platform: 'win32-x64', sourceRevision: revision, sourceHashes: {} }));
  const executable = path.join(directory, 'php.exe'); await fs.writeFile(executable, Buffer.alloc(4096, 0x61));
  const archive = workerArchive(), original = await archive.inventory(directory);
  const receipt = { format: 1, revision, files: original };
  await archive.verify(directory, receipt);
  const before = await fs.stat(executable);
  await fs.writeFile(executable, Buffer.alloc(4096, 0x62));
  await fs.utimes(executable, before.atime, before.mtime);
  await assert.rejects(archive.verify(directory, receipt), /غير مكتملة/);
  await assert.rejects(archive.sourceFingerprint(directory), /يلزم التحقق/);
  assert.equal(await fs.readFile(executable).then(bytes => bytes[0]), 0x62, 'Rejection must not rewrite a retained file.');
  return { sameLengthAndMtimeTamperRejected: true, failedRecheckInvalidatesAuthority: true, alteredFilePreserved: true };
}

async function cancellationProbe(directory) {
  let cancelled = false;
  const archive = workerArchive({ stopped: () => cancelled });
  const pending = archive.inventory(directory);
  const timer = setTimeout(() => { cancelled = true; }, 10);
  try { await assert.rejects(pending, /البرنامج يُغلق الآن/); }
  finally { clearTimeout(timer); }
  // The public promise must settle only after its worker has stopped. The
  // implementation's dedicated regression tests cover child handle draining.
  return { cancellationRejected: true };
}

async function main() {
  const args = process.argv.slice(2);
  const smoke = args.includes('--smoke');
  const outputArgument = args.filter(value => value !== '--smoke');
  if (outputArgument.length !== 1) throw Error('Usage: node runtime-inventory-benchmark.cjs output.json [--smoke]');
  assert.equal(sha(Baseline.prototype.inventory.toString()), BASELINE_METHOD_SHA256, 'The frozen preview52 baseline method changed.');
  const rootSha = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repository, encoding: 'utf8' }).trim();
  assert.match(rootSha, /^[a-f0-9]{40}$/);
  if (process.env.GITHUB_SHA) assert.equal(rootSha, process.env.GITHUB_SHA);
  const sourceHashes = await bindings();
  const output = path.resolve(outputArgument[0]);
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-inventory-benchmark-'));
  let receipt;
  try {
    const directory = path.join(root, 'fixture'), files = mix(smoke);
    const totalBytes = files.reduce((sum, file) => sum + file.bytes, 0);
    console.log('RUNTIME_INVENTORY_FIXTURE ' + JSON.stringify({ files: files.length, bytes: totalBytes, smoke }));
    const generationStart = performance.now(); await populate(directory, files);
    const generationMs = performance.now() - generationStart;
    // Generation populates the cache. These unmeasured warmups give both
    // implementations the same complete prior pass before the ABBA comparison.
    console.log('RUNTIME_INVENTORY_WARMUP baseline');
    const baselineWarm = await new Baseline().inventory(directory);
    console.log('RUNTIME_INVENTORY_WARMUP worker');
    const workerWarm = await workerArchive().inventory(directory);
    assert.deepEqual(canonical(workerWarm), canonical(baselineWarm));
    const expectedDigest = digest(baselineWarm);
    const measurements = [];
    for (const [kind, label] of [['baseline', 'AB-1'], ['worker', 'AB-2'], ['worker', 'BA-1'], ['baseline', 'BA-2']]) {
      const result = await measure(kind, directory, files, label);
      assert.equal(result.inventorySha256, expectedDigest);
      measurements.push(result);
    }
    const cancellation = await cancellationProbe(directory);
    const integrity = await integrityProbe(root);
    assert.deepEqual(await bindings(), sourceHashes, 'Measured sources must stay unchanged throughout the benchmark.');
    const mean = kind => measurements.filter(item => item.kind === kind).reduce((sum, item) => sum + item.milliseconds, 0) / 2;
    receipt = {
      format: 1, kind: 'go-erp-runtime-inventory-benchmark', rootSha, platform: process.platform, node: process.version,
      hardware: false, productionProfile: false, syntheticFixture: true, smoke,
      baselineRevision: BASELINE_REVISION, baselineSourceSha256: BASELINE_FILE_SHA256,
      baselineMethodSha256: BASELINE_METHOD_SHA256, sourceHashes,
      fixture: { files: files.length, bytes: totalBytes, generationMs: Math.round(generationMs), realAllocatedBytes: true },
      order: ['baseline', 'worker', 'worker', 'baseline'], warmups: ['baseline', 'worker'], measurements,
      baselineMeanMs: mean('baseline'), workerMeanMs: mean('worker'),
      workerToBaselineRatio: mean('worker') / mean('baseline'), inventorySha256: expectedDigest,
      exactInventoriesEqual: true, parentContentHashingAbsent: true, parentEventLoopProgressed: true,
      ...cancellation, ...integrity, completed: true
    };
  } finally { await fs.rm(root, { recursive: true, force: true }); }
  await fs.mkdir(path.dirname(output), { recursive: true });
  await fs.writeFile(output, JSON.stringify(receipt, null, 2) + '\n', { flag: 'wx' });
  console.log('RUNTIME_INVENTORY_BENCHMARK_COMPLETE ' + JSON.stringify(receipt));
}
main().catch(error => { console.error(error); process.exitCode = 1; });
