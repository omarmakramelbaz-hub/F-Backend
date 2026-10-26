'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const { isDeepStrictEqual } = require('node:util');
const media = require('./dashboard-media.cjs');

// Installed native source is supplied as a literal argv item, never a shell
// command, path within a retained application, or downloaded executable code.
async function bindingCode() {
  const source = await fs.readFile(path.join(__dirname, 'runtime-media-binding.php'), 'utf8');
  if (!source.startsWith('<?php\n') || Buffer.byteLength(source) > 16000) throw Error('فحص ملفات الحساب غير متوافق.');
  return source.slice(6);
}

async function verify({ active, sourceFingerprint, storage, readBinding, stopped = () => false }) {
  if (active?.format !== 2 || !isDeepStrictEqual(active.sourceFingerprint, sourceFingerprint))
    throw Error('مصدر البرنامج لا يطابق بيانات الحساب؛ البيانات محفوظة.');
  if (stopped()) throw Error('البرنامج يُغلق الآن.');
  const value = await readBinding(await bindingCode(), active);
  if (stopped()) throw Error('البرنامج يُغلق الآن.');
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || Object.keys(value).sort().join(',') !== 'actorId,files,format,kind,snapshotId,sourceFingerprint'
      || value.format !== 1 || value.kind !== 'native-runtime-media-binding'
      || value.actorId !== active.actorId || value.snapshotId !== active.snapshotId
      || !isDeepStrictEqual(value.sourceFingerprint, sourceFingerprint))
    throw Error('سجل صور الحساب لا يطابق نسخة الجهاز؛ البيانات محفوظة.');
  return media.verify(path.join(storage, 'app/public'), value.files,
    { privateRoot: path.join(storage, 'app'), stopped });
}

module.exports = { verify };
