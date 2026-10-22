'use strict';
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const sourceCode = require('./dashboard-source.cjs');

const hash = value => /^[a-f0-9]{64}$/.test(value || '');
function canonical(value, depth = 0) {
  if (depth > 32) throw Error('بيانات نطاق التجهيز غير صالحة.');
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return JSON.stringify(value);
  if (typeof value === 'number' && Number.isFinite(value)) return JSON.stringify(value);
  if (Array.isArray(value)) return '[' + value.map(item => canonical(item, depth + 1)).join(',') + ']';
  if (value && typeof value === 'object' && [Object.prototype, null].includes(Object.getPrototypeOf(value)))
    return '{' + Object.keys(value).sort().map(key => JSON.stringify(key) + ':' + canonical(value[key], depth + 1)).join(',') + '}';
  throw Error('بيانات نطاق التجهيز غير صالحة.');
}
function digest(value) {
  const encoded = canonical(value);
  if (Buffer.byteLength(encoded) > 32 * 1024 ** 2) throw Error('بيانات نطاق التجهيز أكبر من الحد المسموح.');
  return crypto.createHash('sha256').update(encoded).digest('hex');
}
function routes(value) {
  if (!Array.isArray(value) || !value.length || value.length > 500 || value.some(name => typeof name !== 'string' || !/^[A-Za-z0-9_.-]{1,160}$/.test(name))
      || new Set(value).size !== value.length) throw Error('قائمة عمليات التجهيز غير صالحة.');
  return [...value].sort();
}
function previewManifest(manifest) {
  try {
    return manifest?.format === 1 && manifest.platform === 'win32-x64' && manifest.channel === 'preview' && manifest.fullDashboard === false
      && /^[a-f0-9]{40}$/.test(manifest.sourceRevision || '') && sourceCode.valid(manifest.sourceFingerprint)
      && routes(manifest.previewWriteRoutes).length > 0;
  } catch { return false; }
}
function mediaReceipt(items) {
  return items.map(item => ({ path: item.path, sha256: item.sha256, bytes: item.bytes, mime: item.mime,
    ...(item.area === 'private' ? { area: 'private' } : {}) }));
}
function binding(value) {
  return digest({ format: 1, deviceId: value.deviceId, actorId: value.actorId, schemaHash: value.schemaHash,
    snapshotId: value.snapshotId, branches: value.branches, sourceRevision: value.sourceRevision,
    sourceFingerprint: value.sourceFingerprint, coverage: value.coverage, mediaManifestHash: value.mediaManifestHash });
}
function previewPrepared(value, manifest) {
  try {
    return value?.format === 2 && value.fullCoverage === false && value.previewOnly === true && previewManifest(manifest)
      && value.sourceRevision === manifest.sourceRevision && sourceCode.same(value.sourceFingerprint, manifest.sourceFingerprint)
      && value.coverage?.full_dashboard === false && value.coverage.media === true && hash(value.mediaManifestHash)
      && JSON.stringify(routes(value.coverage.write_routes)) === JSON.stringify(routes(manifest.previewWriteRoutes))
      && hash(value.coverageBinding) && value.coverageBinding === binding(value);
  } catch { return false; }
}
function snapshotCoverage(snapshot, manifest, allowPreview = false) {
  if (snapshot.coverage?.media !== true && Array.isArray(snapshot.media_issues)
      && snapshot.media_issues.some(item => item?.reason === 'media-capacity-exceeded'))
    throw Error('صور الحساب تتجاوز سعة تجهيز الجهاز؛ يلزم مراجعة حجم الصور قبل استكمال التجهيز.');
  if (snapshot.coverage?.media !== true || !Array.isArray(snapshot.media)) throw Error('قائمة صور التجهيز لم تكتمل بعد.');
  if (snapshot.coverage.full_dashboard === true) return { fullCoverage: true };
  if (allowPreview !== true || snapshot.coverage.full_dashboard !== false || !previewManifest(manifest)
      || !sourceCode.same(snapshot.source, manifest.sourceFingerprint)
      || JSON.stringify(routes(snapshot.coverage.write_routes)) !== JSON.stringify(routes(manifest.previewWriteRoutes)))
    throw Error('تجهيز هذا النطاق غير معتمد في نسخة البرنامج؛ بيانات الجهاز محفوظة.');
  return { fullCoverage: false, previewOnly: true, coverage: structuredClone(snapshot.coverage),
    mediaManifestHash: digest(mediaReceipt(snapshot.media)) };
}
function preparedCoverage(snapshot, candidate, manifest, allowPreview = false) {
  const scope = snapshotCoverage(snapshot, manifest, allowPreview);
  if (scope.previewOnly) {
    const receipt = candidate.receipt;
    if (candidate.sourceRevision !== manifest.sourceRevision || !sourceCode.same(candidate.sourceFingerprint, manifest.sourceFingerprint)
        || receipt?.verified !== true || receipt.device_id !== snapshot.device_id || receipt.actor_id !== snapshot.actor_id
        || receipt.schema_hash !== snapshot.schema_hash || receipt.snapshot_id !== snapshot.snapshot_id
        || !sourceCode.same(receipt.source, snapshot.source) || digest(receipt.coverage) !== digest(snapshot.coverage)
        || digest(receipt.branches) !== digest(snapshot.branches) || digest(receipt.media) !== scope.mediaManifestHash)
      throw Error('نطاق التجهيز لا يطابق قاعدة الجهاز التي تم التحقق منها.');
  }
  return scope;
}

// Accept only the two reviewed literal PHP constant grammars. Expressions or a
// changed representation require an explicit update, rather than a guessed scope.
async function packagedRoutes(application) {
  const constants = await fs.readFile(path.join(application, 'app/Services/Dashboard/DesktopDashboardRoutes.php'), 'utf8');
  const literal = constants.match(/public\s+const\s+WRITES\s*=\s*\[([\s\S]*?)\];/);
  if (!literal) throw Error('The packaged write-route constant is missing.');
  const direct = [...literal[1].matchAll(/'([A-Za-z0-9_.-]+)'/g)].map(match => match[1]);
  if (literal[1].replace(/'([A-Za-z0-9_.-]+)'|\s|,/g, '')) throw Error('The packaged write-route constant is not a reviewed literal list.');
  const legacy = await fs.readFile(path.join(application, 'app/Services/Dashboard/DesktopDashboardLegacy.php'), 'utf8');
  const legacyLiteral = legacy.match(/public\s+const\s+ROUTES\s*=\s*\[([\s\S]*?)\n\s*\];/);
  if (!legacyLiteral) throw Error('The packaged legacy route constant is missing.');
  const lines = legacyLiteral[1].split(/\r?\n/).filter(line => line.trim());
  const legacyRoutes = lines.map(line => {
    const match = line.match(/^\s*'([A-Za-z0-9_.-]+)'\s*=>\s*\[(?:'(?:model|entity|table|method|action|parameter)'\s*=>\s*(?:'[A-Za-z0-9_.-]+'|[\\A-Za-z0-9_]+::class|null)\s*,?\s*)+\],\s*$/);
    if (!match) throw Error('The packaged legacy route constant is not a reviewed literal map.');
    return match[1];
  });
  return routes([...direct, ...legacyRoutes]);
}
module.exports = { digest, routes, previewManifest, previewPrepared, snapshotCoverage, preparedCoverage, binding, packagedRoutes, mediaReceipt };
