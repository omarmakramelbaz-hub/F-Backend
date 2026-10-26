'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');
const SCOPE = 'vendor/google/apiclient-services/src/';
const PREFIX = 'application/' + SCOPE;
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const revision = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const exact = (value, keys) => value && typeof value === 'object' && !Array.isArray(value)
  && Object.keys(value).sort().join(',') === keys.slice().sort().join(',');
const invalid = () => Error('تعذر التحقق من حزمة التشغيل المضغوطة؛ ملفات الجهاز الأصلية محفوظة.');
const identity = value => [value.dev, value.ino, value.mode, value.nlink, value.size, value.mtimeMs, value.ctimeMs].join(':');
const safeName = name => typeof name === 'string' && name && Buffer.byteLength(name) <= 4096
  && !/[\x00-\x1f:\\]/.test(name) && name.split('/').every(part => part && part !== '.' && part !== '..');

function validateIndex(value) {
  if (!exact(value, ['format', 'kind', 'scope', 'archive', 'files']) || value.format !== 1
      || value.kind !== 'go-erp-google-services-phar' || value.scope !== SCOPE
      || !exact(value.archive, ['file', 'sha256', 'bytes']) || value.archive.file !== 'google-services.phar'
      || !hash(value.archive.sha256) || !Number.isSafeInteger(value.archive.bytes)
      || value.archive.bytes < 1 || value.archive.bytes > 4 * 1024 ** 3
      || !value.files || typeof value.files !== 'object' || Array.isArray(value.files)) throw invalid();
  const entries = Object.entries(value.files), cases = new Set();
  if (!entries.length || entries.length > 100000) throw invalid();
  for (const [name, value] of entries) {
    if (!safeName(name) || !name.startsWith(SCOPE) || !hash(value) || cases.has(name.toLowerCase())) throw invalid();
    cases.add(name.toLowerCase());
  }
  return value;
}
function validateFiles(receipt) {
  if (!receipt || receipt.format !== 1 || !revision(receipt.revision) || !receipt.files
      || typeof receipt.files !== 'object' || Array.isArray(receipt.files)) throw invalid();
  const entries = Object.entries(receipt.files), cases = new Set();
  if (!entries.length || entries.length > 100000) throw invalid();
  for (const [name, value] of entries) {
    if (!safeName(name) || !hash(value) || cases.has(name.toLowerCase())) throw invalid();
    cases.add(name.toLowerCase());
  }
  return receipt;
}
function matchesReceipt(index, receipt) {
  validateIndex(index); validateFiles(receipt);
  const names = Object.keys(receipt.files).filter(name => name.startsWith(PREFIX)).sort();
  const wanted = Object.keys(index.files).sort();
  return names.length === wanted.length && names.every((name, i) => name === 'application/' + wanted[i]
    && receipt.files[name] === index.files[wanted[i]]);
}
function receiptDigest(receipt) {
  validateFiles(receipt);
  return sha(JSON.stringify({ format: 1, revision: receipt.revision,
    files: Object.fromEntries(Object.entries(receipt.files).sort(([a], [b]) => Buffer.compare(Buffer.from(a), Buffer.from(b)))) }));
}
function validatePins(value) {
  if (!exact(value, ['format', 'kind', 'producerRevision', 'scope', 'indexSha256', 'archiveSha256', 'archiveBytes', 'loaderSha256'])
      || value.format !== 1 || value.kind !== 'go-erp-compact-vendor-pins' || !revision(value.producerRevision)
      || value.scope !== SCOPE || !hash(value.indexSha256) || !hash(value.archiveSha256) || !hash(value.loaderSha256)
      || !Number.isSafeInteger(value.archiveBytes) || value.archiveBytes < 1 || value.archiveBytes > 4 * 1024 ** 3) throw invalid();
  return value;
}
async function plainDirectory(directory) {
  const absolute = path.resolve(directory); let cursor = path.parse(absolute).root;
  for (const component of absolute.slice(cursor.length).split(path.sep).filter(Boolean)) {
    cursor = path.join(cursor, component);
    const stat = await fs.lstat(cursor);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw invalid();
  }
}
async function fileHash(file, { maximum = 4 * 1024 ** 3, stopped = () => false, collect = false } = {}) {
  await plainDirectory(path.dirname(file));
  const before = await fs.lstat(file);
  if (!before.isFile() || before.isSymbolicLink() || before.nlink < 1 || before.size < 1 || before.size > maximum) throw invalid();
  const opened = await fs.open(file, 'r'); const chunks = [], digest = crypto.createHash('sha256');
  let bytes = 0;
  try {
    if (identity(before) !== identity(await opened.stat())) throw invalid();
    const buffer = Buffer.allocUnsafe(1024 * 1024);
    while (true) {
      if (stopped()) throw Error('البرنامج يُغلق الآن.');
      const read = await opened.read(buffer, 0, Math.min(buffer.length, before.size - bytes + 1), bytes);
      if (!read.bytesRead) break;
      bytes += read.bytesRead;
      if (bytes > before.size) throw invalid();
      const part = buffer.subarray(0, read.bytesRead); digest.update(part);
      if (collect) chunks.push(Buffer.from(part));
    }
    if (bytes !== before.size || identity(before) !== identity(await opened.stat())
        || identity(before) !== identity(await fs.lstat(file))) throw invalid();
    return { sha256: digest.digest('hex'), bytes, ...(collect ? { raw: Buffer.concat(chunks) } : {}) };
  } finally { await opened.close(); }
}
async function artifactPins(bundle, stopped = () => false) {
  const directory = path.join(bundle, 'compact-vendor');
  const indexRead = await fileHash(path.join(directory, 'vendor-phar-index.json'), { maximum: 32 * 1024 ** 2, stopped, collect: true });
  const index = validateIndex(JSON.parse(indexRead.raw));
  const archive = await fileHash(path.join(directory, 'google-services.phar'), { stopped });
  const loader = await fileHash(path.join(directory, 'autoload.php'), { maximum: 128 * 1024, stopped, collect: true });
  if (archive.sha256 !== index.archive.sha256 || archive.bytes !== index.archive.bytes) throw invalid();
  return { index, indexRaw: indexRead.raw, loaderRaw: loader.raw, pins: {
    format: 1, kind: 'go-erp-compact-vendor-pins', scope: SCOPE,
    indexSha256: indexRead.sha256, archiveSha256: archive.sha256, archiveBytes: archive.bytes, loaderSha256: loader.sha256
  } };
}

class RuntimeCompact {
  constructor(archive, { pins = undefined } = {}) { this.archive = archive; this.suppliedPins = pins; }
  check() { this.archive.assertRunning(); }
  async pins() {
    if (this.suppliedPins !== undefined) return this.suppliedPins ? validatePins(this.suppliedPins) : null;
    let raw;
    try { raw = await fs.readFile(path.join(__dirname, 'compact-vendor-pins.json'), 'utf8'); }
    catch (error) { if (error.code === 'ENOENT') return null; throw error; }
    const value = JSON.parse(raw); return value === null ? null : validatePins(value);
  }
  async select(bundle, legacy, receipt) {
    this.check(); validateFiles(receipt);
    const digest = receiptDigest(receipt), root = path.join(path.dirname(this.archive.root), 'compact-runtimes');
    const destination = path.join(root, receipt.revision), key = 'compact-runtimes/' + receipt.revision;
    const previous = await this.archive.metadata.read(key);
    if (previous) {
      const marker = previous.compactVendor;
      if (!marker || marker.format !== 1 || marker.kind !== 'go-erp-compact-vendor'
          || marker.originalReceiptSha256 !== digest || marker.sourceRevision !== receipt.revision
          || previous.revision !== receipt.revision) throw invalid();
      await this.archive.verify(destination, previous);
      const manifest = await this.archive.manifest(destination);
      if (!isDeepStrictEqual(manifest.compactVendor, marker)) throw invalid();
      const index = validateIndex(JSON.parse(await fs.readFile(path.join(destination, 'application/google-services-index.json'), 'utf8')));
      if (!matchesReceipt(index, receipt)) throw invalid();
      return destination;
    }
    const trusted = await this.pins();
    if (!trusted) return null;
    const manifest = await this.archive.manifest(bundle);
    if (manifest.sourceRevision !== trusted.producerRevision || !isDeepStrictEqual(manifest.compactVendor, trusted)) return null;
    const artifact = await artifactPins(bundle, () => this.archive.stopped());
    if (!isDeepStrictEqual({ ...artifact.pins, producerRevision: manifest.sourceRevision }, trusted)) throw invalid();
    if (!matchesReceipt(artifact.index, receipt)) return null;
    await plainDirectory(legacy);
    await fs.mkdir(root, { recursive: true, mode: 0o700 }); await plainDirectory(root);
    try { await fs.lstat(destination); return null; } catch (error) { if (error.code !== 'ENOENT') throw error; }
    const temporary = destination + '.stage-' + crypto.randomBytes(8).toString('hex');
    const expected = Object.fromEntries(Object.entries(receipt.files).filter(([name]) => !name.startsWith(PREFIX) && !name.startsWith('compact-vendor/')));
    if (Object.hasOwn(expected, 'application/vendor/autoload.original.php')
        || Object.hasOwn(expected, 'application/google-services.phar')
        || Object.hasOwn(expected, 'application/google-services-index.json')) return null;
    let renamed = false;
    try {
      // Reject the entire generated-class directory before cp enumerates it.
      // No old Google class file is opened or read during this conversion.
      const scope = path.join(legacy, ...PREFIX.slice(0, -1).split('/'));
      const compiler = path.join(legacy, 'compact-vendor');
      await fs.cp(legacy, temporary, { recursive: true, dereference: false, errorOnExist: true, force: false,
        filter: file => { this.check(); return file !== scope && !file.startsWith(scope + path.sep)
          && file !== compiler && !file.startsWith(compiler + path.sep); } });
      const copied = await this.archive.inventory(temporary);
      if (!isDeepStrictEqual(copied, expected)) throw invalid();
      await plainDirectory(path.join(temporary, 'application/vendor'));
      const oldManifestRaw = await fs.readFile(path.join(temporary, 'manifest.json'));
      const oldManifest = JSON.parse(oldManifestRaw);
      if (oldManifest.sourceRevision !== receipt.revision) throw invalid();
      const marker = { format: 1, kind: 'go-erp-compact-vendor', sourceRevision: receipt.revision,
        originalReceiptSha256: digest, indexSha256: trusted.indexSha256, archiveSha256: trusted.archiveSha256,
        archiveBytes: trusted.archiveBytes, loaderSha256: trusted.loaderSha256, scope: SCOPE };
      const nextManifest = { ...oldManifest, compactVendor: marker };
      const manifestRaw = Buffer.from(JSON.stringify(nextManifest, null, 2) + '\n');
      await fs.rename(path.join(temporary, 'application/vendor/autoload.php'), path.join(temporary, 'application/vendor/autoload.original.php'));
      await fs.writeFile(path.join(temporary, 'application/vendor/autoload.php'), artifact.loaderRaw, { flag: 'wx', mode: 0o600 });
      await fs.copyFile(path.join(bundle, 'compact-vendor/google-services.phar'), path.join(temporary, 'application/google-services.phar'));
      await fs.writeFile(path.join(temporary, 'application/google-services-index.json'), artifact.indexRaw, { flag: 'wx', mode: 0o600 });
      await fs.writeFile(path.join(temporary, 'manifest.json'), manifestRaw);
      const files = { ...expected,
        'application/vendor/autoload.php': trusted.loaderSha256,
        'application/vendor/autoload.original.php': expected['application/vendor/autoload.php'],
        'application/google-services.phar': trusted.archiveSha256,
        'application/google-services-index.json': trusted.indexSha256,
        'manifest.json': sha(manifestRaw) };
      const next = { format: 1, revision: receipt.revision, files, compactVendor: marker,
        ...(receipt.sourceFingerprint || oldManifest.sourceFingerprint ? { sourceFingerprint: receipt.sourceFingerprint || oldManifest.sourceFingerprint } : {}) };
      await this.archive.verify(temporary, next);
      this.check(); await fs.rename(temporary, destination); renamed = true;
      await this.archive.metadata.write(key, next);
      this.archive.verified.delete(path.resolve(temporary));
      this.archive.verified.set(path.resolve(destination), { receipt: next, manifest: nextManifest });
      return destination;
    } finally {
      if (!renamed) await fs.rm(temporary, { recursive: true, force: true }).catch(() => {});
    }
  }
}
module.exports = { RuntimeCompact, artifactPins, validatePins, validateIndex, matchesReceipt, receiptDigest, SCOPE, PREFIX, fileHash };
