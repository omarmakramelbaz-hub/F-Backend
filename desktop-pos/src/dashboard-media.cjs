'use strict';
const fs = require('node:fs/promises');
const constants = require('node:fs').constants;
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');

const MIMES = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', ico: 'image/x-icon' };
const PRIVATE_MIMES = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', pdf: 'application/pdf' };
const MAX_FILE = 16 * 1024 * 1024, MAX_TOTAL = 2 * 1024 * 1024 * 1024, MAX_FILES = 20000;
function safePath(value, area = 'public') {
  if (typeof value !== 'string' || !value || Buffer.byteLength(value) > 500 || /[\\\x00-\x1f\x7f:*?"<>|%]/u.test(value)) return false;
  if (!['public', 'private'].includes(area) || (area === 'private' && !value.startsWith('branch-expenses/'))) return false;
  return value.split('/').every(part => part && part !== '.' && part !== '..' && Buffer.byteLength(part) <= 255
    && !/[. ]$/.test(part) && !/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i.test(part))
    && Boolean((area === 'private' ? PRIVATE_MIMES : MIMES)[path.posix.extname(value).slice(1).toLowerCase()]);
}
function manifest(value) {
  if (!Array.isArray(value) || value.length > MAX_FILES) throw Error('قائمة الصور المحلية غير صالحة.');
  const seen = new Set(); let total = 0;
  for (const item of value) {
    const area = item?.area === undefined ? 'public' : item.area;
    if (!item || !safePath(item.path, area) || !/^[a-f0-9]{64}$/.test(item.sha256 || '') || !Number.isSafeInteger(item.bytes)
        || item.bytes <= 0 || item.bytes > MAX_FILE || item.mime !== (area === 'private' ? PRIVATE_MIMES : MIMES)[path.posix.extname(item.path).slice(1).toLowerCase()]
        || typeof item.ticket !== 'string' || !item.ticket || item.ticket.length > 8192)
      throw Error('بيانات صورة محلية غير صالحة.');
    const folded = area + '\0' + item.path.toLowerCase();
    if (seen.has(folded)) throw Error('مساران للصور يتعارضان على ويندوز.');
    seen.add(folded); total += item.bytes;
    if (total > MAX_TOTAL) throw Error('صور الحساب تتجاوز حجم التجهيز المسموح.');
  }
  return value;
}
function verifyReceipt(checked, downloaded) {
  // PHP and JavaScript may emit object fields in different orders. Array order,
  // field names, types and every value must still match the verified download.
  if (!Array.isArray(checked) || !isDeepStrictEqual(checked, downloaded))
    throw Error('قائمة الصور التي تم التحقق منها تختلف عن ملفات التجهيز.');
}
const unsafe = () => Error('مسار ملفات الصور يحتاج التحقق؛ بيانات الجهاز محفوظة.');
const normalize = value => process.platform === 'win32' ? path.resolve(value).toLowerCase() : path.resolve(value);
const sameNode = (left, right) => left.dev === right.dev && left.ino === right.ino && left.mode === right.mode;
const sameFile = (left, right) => sameNode(left, right) && left.size === right.size && left.mtimeNs === right.mtimeNs && left.ctimeNs === right.ctimeNs;
const sameContent = (left, right) => sameNode(left, right) && left.size === right.size && left.mtimeNs === right.mtimeNs;
const missing = error => error.code === 'ENOENT';
const linkUnsupported = error => ['EXDEV', 'EPERM', 'EACCES', 'EINVAL', 'ENOSYS', 'ENOTSUP', 'EOPNOTSUPP', 'EMLINK'].includes(error.code);
async function checkedDirectories(directory, create = false) {
  if (typeof directory !== 'string' || !path.isAbsolute(directory) || path.resolve(directory) !== directory) throw unsafe();
  const parsed = path.parse(directory), segments = directory.slice(parsed.root.length).split(path.sep).filter(Boolean), chain = [];
  let cursor = parsed.root;
  for (const segment of ['', ...segments]) {
    if (segment) cursor = path.join(cursor, segment);
    if (create && segment) await fs.mkdir(cursor, { mode: 0o700 }).catch(error => { if (error.code !== 'EEXIST') throw error; });
    const stat = await fs.lstat(cursor, { bigint: true });
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw unsafe();
    chain.push({ path: cursor, stat });
  }
  if (normalize(await fs.realpath(directory)) !== normalize(directory)) throw unsafe();
  return chain;
}
async function checkChain(chain) {
  for (const entry of chain) {
    const stat = await fs.lstat(entry.path, { bigint: true });
    if (!stat.isDirectory() || stat.isSymbolicLink() || !sameNode(stat, entry.stat)) throw unsafe();
  }
}
async function location(root, relative, create = false) {
  const target = path.join(root, ...relative.split('/'));
  const chain = await checkedDirectories(path.dirname(target), create);
  return { path: target, chain };
}
async function fresh(target) {
  await checkChain(target.chain);
  try { await fs.lstat(target.path); throw Error('مسار صورة التجهيز موجود بالفعل.'); } catch (error) { if (!missing(error)) throw error; }
}
async function opened(source) {
  await checkChain(source.chain);
  const before = await fs.lstat(source.path, { bigint: true });
  if (!before.isFile() || before.isSymbolicLink()) throw unsafe();
  const file = await fs.open(source.path, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
  try {
    const actual = await file.stat({ bigint: true });
    if (!actual.isFile() || !sameFile(actual, before)) throw unsafe();
    await checkChain(source.chain);
    return { file, stat: actual };
  } catch (error) { await file.close(); throw error; }
}
async function hashOpened(file, expected, stopped, output = null) {
  const digest = crypto.createHash('sha256'), buffer = Buffer.allocUnsafe(65536); let bytes = 0;
  while (true) {
    if (stopped()) throw Error('البرنامج يُغلق الآن.');
    const result = await file.read(buffer, 0, buffer.length, bytes);
    if (!result.bytesRead) break;
    bytes += result.bytesRead;
    if (bytes > expected.bytes) return false;
    const part = buffer.subarray(0, result.bytesRead); digest.update(part);
    if (output) await output.writeFile(part);
  }
  return bytes === expected.bytes && digest.digest('hex') === expected.sha256;
}
async function unchanged(source, file, before, linked = false) {
  await checkChain(source.chain);
  const actual = await file.stat({ bigint: true }), named = await fs.lstat(source.path, { bigint: true });
  const equal = linked ? sameContent : sameFile;
  if (!actual.isFile() || named.isSymbolicLink() || !equal(actual, before) || !sameFile(actual, named)) throw unsafe();
}
async function removeOwned(target, stat) {
  try {
    await checkChain(target.chain);
    const named = await fs.lstat(target.path, { bigint: true });
    if (!named.isSymbolicLink() && sameNode(named, stat)) await fs.unlink(target.path);
  } catch (error) { if (!missing(error)) throw error; }
}
/** The reviewed local adapters append new operation-owned paths; none modify an existing media inode. */
async function materialize(source, target, expected, stopped) {
  const input = await opened(source); let owned = null, output;
  try {
    if (input.stat.size !== BigInt(expected.bytes) || !(await hashOpened(input.file, expected, stopped))) return false;
    await unchanged(source, input.file, input.stat); await fresh(target);
    try {
      // Never replace a destination. NTFS hardlinks keep unchanged media at one physical copy.
      await fs.link(source.path, target.path);
      owned = await fs.lstat(target.path, { bigint: true });
      if (!owned.isFile() || owned.isSymbolicLink() || !sameContent(owned, input.stat)) throw unsafe();
      await unchanged(source, input.file, input.stat, true);
    } catch (error) {
      if (owned || !linkUnsupported(error)) throw error;
      // Volumes without hardlinks get an independent, exclusively created verified copy.
      await fresh(target); output = await fs.open(target.path, 'wx', 0o600); owned = await output.stat({ bigint: true });
      if (!(await hashOpened(input.file, expected, stopped, output))) throw unsafe();
      await output.sync(); await output.close(); output = null;
      await unchanged(source, input.file, input.stat);
    }
    await checkChain(target.chain);
    const named = await fs.lstat(target.path, { bigint: true });
    if (!named.isFile() || named.isSymbolicLink() || !sameNode(named, owned) || named.size !== BigInt(expected.bytes)) throw unsafe();
    return true;
  } catch (error) {
    await output?.close(); output = null;
    if (owned) await removeOwned(target, owned);
    throw error;
  } finally { await output?.close(); await input.file.close(); }
}
async function candidate(root, relative) {
  if (!root) return null;
  try {
    const result = await location(root, relative);
    const stat = await fs.lstat(result.path, { bigint: true });
    if (!stat.isFile() || stat.isSymbolicLink()) throw unsafe();
    return result;
  } catch (error) { if (missing(error)) return null; throw error; }
}
function disjoint(left, right) {
  const a = normalize(left), b = normalize(right);
  return a !== b && !a.startsWith(b + path.sep) && !b.startsWith(a + path.sep);
}
async function reuseOptions(value, root, privateRoot) {
  if (value === undefined) return null;
  if (!value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).some(key => !['publicRoot', 'privateRoot', 'cacheRoot'].includes(key))) throw unsafe();
  const result = { ...value };
  for (const key of Object.keys(result)) {
    if (typeof result[key] !== 'string' || !path.isAbsolute(result[key])) throw unsafe();
    result[key] = path.resolve(result[key]);
    if (key !== 'cacheRoot') await checkedDirectories(result[key]);
  }
  if (result.privateRoot && (!result.publicRoot || normalize(result.publicRoot) !== normalize(path.join(result.privateRoot, 'public')))) throw unsafe();
  for (const old of [result.publicRoot, result.privateRoot].filter(Boolean)) {
    if ([root, privateRoot].filter(Boolean).some(current => normalize(current) === normalize(old))) throw unsafe();
  }
  if (result.cacheRoot) {
    for (const other of [root, privateRoot, result.publicRoot, result.privateRoot].filter(Boolean)) if (!disjoint(result.cacheRoot, other)) throw unsafe();
    await checkedDirectories(result.cacheRoot, true);
    for (const area of ['public', 'private']) await checkedDirectories(path.join(result.cacheRoot, area), true);
  }
  return result;
}
const cacheName = item => item.sha256 + '.' + path.posix.extname(item.path).slice(1).toLowerCase();
/** Write verified files to their own inactive storage area; tickets never reach dashboard pages. */
async function download(root, files, request, stopped = () => false, privateRoot, reuse) {
  manifest(files);
  root = path.resolve(root); if (privateRoot !== undefined) privateRoot = path.resolve(privateRoot);
  if (files.some(item => item.area === 'private')) {
    // The private disk is the parent of the public disk, never the HTTP-served root.
    if (typeof privateRoot !== 'string' || path.resolve(root) !== path.join(path.resolve(privateRoot), 'public'))
      throw Error('مجلد المرفقات الخاصة لم يُجهّز بعد.');
    const [privatePath, publicPath] = await Promise.all([fs.realpath(privateRoot), fs.realpath(root)]);
    if (publicPath !== path.join(privatePath, 'public')) throw Error('مسار المرفقات الخاصة غير آمن.');
  }
  const previous = reuse === undefined ? null : await reuseOptions(reuse, root, privateRoot);
  if (files.length && typeof request !== 'function') throw Error('تنزيل صور الحساب لم يُجهّز بعد.');
  const receipt = []; let reusedFiles = 0, downloadedFiles = 0, downloadedBytes = 0;
  for (const item of files) {
    if (stopped()) throw Error('البرنامج يُغلق الآن.');
    const area = item.area === 'private' ? 'private' : 'public';
    const target = await location(area === 'private' ? privateRoot : root, item.path, true);
    // A staging directory must be new; never silently replace an existing or linked image.
    await fresh(target);
    const sources = [];
    if (previous?.cacheRoot) sources.push(await candidate(path.join(previous.cacheRoot, area), cacheName(item)));
    sources.push(await candidate(area === 'private' ? previous?.privateRoot : previous?.publicRoot, item.path));
    let reused = false;
    for (const source of sources) if (source && await materialize(source, target, item, stopped)) { reused = true; break; }
    if (reused) {
      reusedFiles++;
      receipt.push({ path: item.path, bytes: item.bytes, mime: item.mime, sha256: item.sha256, ...(area === 'private' ? { area: 'private' } : {}) });
      continue;
    }
    const response = await request(item.ticket);
    if (!response.ok || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== item.mime
        || (response.headers.has('content-length') && Number(response.headers.get('content-length')) !== item.bytes)) {
      await response.body?.cancel().catch(() => {});
      throw Error('لم يكتمل تنزيل الصورة المصرح بها.');
    }
    const reader = response.body?.getReader(); if (!reader) throw Error('الصورة التي أرسلها السيرفر فارغة.');
    const temporary = target.path + '.' + crypto.randomBytes(8).toString('hex') + '.part';
    let file, complete = false, bytes = 0; const digest = crypto.createHash('sha256');
    try {
      file = await fs.open(temporary, 'wx', 0o600);
      while (true) {
        if (stopped()) throw Error('البرنامج يُغلق الآن.');
        const part = await reader.read(); if (part.done) break;
        bytes += part.value.byteLength;
        if (bytes > item.bytes) throw Error('حجم الصورة مختلف عن النسخة المصرح بها.');
        digest.update(part.value); await file.writeFile(part.value);
      }
      if (bytes !== item.bytes || digest.digest('hex') !== item.sha256) throw Error('بصمة الصورة مختلفة؛ لم تُفعّل نسخة التجهيز.');
      await file.sync(); await file.close(); file = null;
      await checkChain(target.chain);
      const staged = { path: temporary, chain: target.chain };
      if (!(await materialize(staged, target, item, stopped))) throw unsafe();
      await fs.unlink(temporary); complete = true;
      downloadedFiles++; downloadedBytes += bytes;
      if (previous?.cacheRoot) {
        const cacheRoot = path.join(previous.cacheRoot, area), cached = await candidate(cacheRoot, cacheName(item));
        if (!cached) {
          const cacheTarget = await location(cacheRoot, cacheName(item), true);
          try { await materialize(target, cacheTarget, item, stopped); }
          catch (error) { if (error.code !== 'EEXIST') throw error; }
        }
      }
      receipt.push({ path: item.path, bytes, mime: item.mime, sha256: item.sha256, ...(item.area === 'private' ? { area: 'private' } : {}) });
    } finally {
      if (!complete) await reader.cancel().catch(() => {});
      reader.releaseLock(); await file?.close();
      if (!complete) await fs.unlink(temporary).catch(error => { if (error.code !== 'ENOENT') throw error; });
    }
  }
  return { verified: true, files: receipt, transfer: { reusedFiles, downloadedFiles, downloadedBytes } };
}
module.exports = { download, manifest, safePath, verifyReceipt, MAX_FILE, MAX_TOTAL };
