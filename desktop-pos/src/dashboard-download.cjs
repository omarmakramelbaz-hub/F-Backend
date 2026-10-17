'use strict';
/** Bound native snapshot downloads before parsing or handing bytes to PHP. */
module.exports = async function readSnapshot(response, limit = 256 * 1024 * 1024) {
  if (Number(response.headers.get('content-length')) > limit || !/application\/json/i.test(response.headers.get('content-type') || ''))
    throw Error('نسخة بيانات السيرفر غير صالحة أو أكبر من الحد المسموح.');
  const reader = response.body?.getReader();
  if (!reader) throw Error('نسخة بيانات السيرفر فارغة.');
  const parts = []; let bytes = 0;
  try {
    while (true) {
      const part = await reader.read(); if (part.done) break;
      bytes += part.value.byteLength;
      if (bytes > limit) { await reader.cancel(); throw Error('نسخة بيانات السيرفر أكبر من الحد المسموح.'); }
      parts.push(Buffer.from(part.value));
    }
    return JSON.parse(Buffer.concat(parts, bytes).toString('utf8'));
  } finally { reader.releaseLock(); }
};
