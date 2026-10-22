'use strict';
// Preserve PHP's hashed row representation, including integral floats and
// 64-bit integers. Parsed metadata and importer input must remain bound.
const inputs = new WeakMap();
function retain(snapshot, input) {
  if (snapshot && typeof snapshot === 'object') {
    const pending = [snapshot];
    while (pending.length) {
      const value = pending.pop();
      for (const child of Object.values(value))
        if (child && typeof child === 'object') pending.push(child);
      Object.freeze(value);
    }
    inputs.set(snapshot, input);
  }
  return snapshot;
}
/** Bound native snapshot downloads before parsing or handing bytes to PHP. */
async function readSnapshot(response, limit = 256 * 1024 * 1024) {
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
    const input = Buffer.concat(parts, bytes).toString('utf8');
    return retain(JSON.parse(input), input);
  } finally { reader.releaseLock(); }
}
// Constructed fixtures keep their existing API. Cloning deliberately loses the
// private association; caller-supplied raw/json properties carry no authority.
readSnapshot.input = snapshot => inputs.get(snapshot) ?? JSON.stringify(snapshot);
module.exports = readSnapshot;
