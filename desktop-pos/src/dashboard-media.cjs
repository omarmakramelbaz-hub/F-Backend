'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');

const MIMES = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', ico: 'image/x-icon' };
const MAX_FILE = 16 * 1024 * 1024, MAX_TOTAL = 1024 * 1024 * 1024, MAX_FILES = 20000;
function safePath(value) {
  if (typeof value !== 'string' || !value || Buffer.byteLength(value) > 500 || /[\\\x00-\x1f\x7f:*?"<>|%]/u.test(value)) return false;
  return value.split('/').every(part => part && part !== '.' && part !== '..' && Buffer.byteLength(part) <= 255
    && !/[. ]$/.test(part) && !/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i.test(part))
    && Boolean(MIMES[path.posix.extname(value).slice(1).toLowerCase()]);
}
function manifest(value) {
  if (!Array.isArray(value) || value.length > MAX_FILES) throw Error('قائمة الصور المحلية غير صالحة.');
  const seen = new Set(); let total = 0;
  for (const item of value) {
    if (!safePath(item.path) || !/^[a-f0-9]{64}$/.test(item.sha256 || '') || !Number.isSafeInteger(item.bytes)
        || item.bytes <= 0 || item.bytes > MAX_FILE || item.mime !== MIMES[path.posix.extname(item.path).slice(1).toLowerCase()]
        || typeof item.ticket !== 'string' || !item.ticket || item.ticket.length > 8192)
      throw Error('بيانات صورة محلية غير صالحة.');
    const folded = item.path.toLowerCase();
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
async function directory(root, relative) {
  const stat = await fs.lstat(root); if (!stat.isDirectory() || stat.isSymbolicLink()) throw Error('مجلد الصور غير آمن.');
  let current = root;
  for (const segment of relative.split('/').slice(0, -1)) {
    current = path.join(current, segment);
    await fs.mkdir(current, { mode: 0o700 }).catch(error => { if (error.code !== 'EEXIST') throw error; });
    const item = await fs.lstat(current); if (!item.isDirectory() || item.isSymbolicLink()) throw Error('مسار الصور غير آمن.');
  }
  return path.join(root, ...relative.split('/'));
}
/** Write only verified images into the inactive generation; tickets never reach dashboard pages. */
async function download(root, files, request, stopped = () => false) {
  manifest(files);
  if (files.length && typeof request !== 'function') throw Error('تنزيل صور الحساب لم يُجهّز بعد.');
  const receipt = [];
  for (const item of files) {
    if (stopped()) throw Error('البرنامج يُغلق الآن.');
    const target = await directory(root, item.path);
    // A staging directory must be new; never silently replace an existing or linked image.
    try { await fs.lstat(target); throw Error('مسار صورة التجهيز موجود بالفعل.'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    const response = await request(item.ticket);
    if (!response.ok || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== item.mime
        || (response.headers.has('content-length') && Number(response.headers.get('content-length')) !== item.bytes)) {
      await response.body?.cancel().catch(() => {});
      throw Error('لم يكتمل تنزيل الصورة المصرح بها.');
    }
    const reader = response.body?.getReader(); if (!reader) throw Error('الصورة التي أرسلها السيرفر فارغة.');
    const temporary = target + '.' + crypto.randomBytes(8).toString('hex') + '.part';
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
      await fs.rename(temporary, target); complete = true;
      receipt.push({ path: item.path, bytes, mime: item.mime, sha256: item.sha256 });
    } finally {
      if (!complete) await reader.cancel().catch(() => {});
      reader.releaseLock(); await file?.close();
      if (!complete) await fs.unlink(temporary).catch(error => { if (error.code !== 'ENOENT') throw error; });
    }
  }
  return { verified: true, files: receipt };
}
module.exports = { download, manifest, safePath, verifyReceipt, MAX_FILE, MAX_TOTAL };
