'use strict';
const fs = require('node:fs/promises');
const { constants, lstatSync } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn: nativeSpawn } = require('node:child_process');

async function realAncestors(file) {
  let cursor = path.resolve(file);
  for (;;) {
    const item = await fs.lstat(cursor);
    if (item.isSymbolicLink() || (cursor === file ? !item.isFile() || item.nlink !== 1 : !item.isDirectory()))
      throw Error('ملف تحديث البرنامج أو مساره غير صالح؛ النسخة الحالية محفوظة.');
    const parent = path.dirname(cursor);
    if (parent === cursor) break;
    cursor = parent;
  }
}

/** Start only the verified local NSIS handoff; it waits for this process to exit. */
function createUpdateInstall({ profile, execPath = process.execPath, pid = process.pid,
  platform = process.platform, spawn = nativeSpawn } = {}) {
  return async ({ file, release }) => {
    const installer = { sha256: release?.installer?.sha256, bytes: release?.installer?.bytes };
    if (platform !== 'win32' || !Number.isSafeInteger(pid) || pid < 1 || pid > 2147483647
        || typeof profile !== 'string' || !path.isAbsolute(profile)
        || typeof file !== 'string' || !path.isAbsolute(file)
        || path.resolve(file) !== file || path.dirname(file) !== path.resolve(profile, 'updates')
        || !/^[a-f0-9]{64}$/.test(installer?.sha256 || '')
        || !Number.isSafeInteger(installer?.bytes) || installer.bytes < 1 || installer.bytes > 1024 ** 3
        || path.basename(file) !== installer.sha256 + '.exe')
      throw Error('تثبيت التحديث غير متاح لهذه النسخة؛ سجلات الجهاز محفوظة.');
    // /D is NSIS's final, deliberately unquoted remainder of the command line.
    // No shell runs. Refuse quotes/control characters before using verbatim args.
    const destination = path.win32.dirname(execPath);
    if (!/^[A-Za-z]:\\/.test(execPath) || /["\x00-\x1f]/.test(execPath)
        || path.win32.basename(execPath) !== 'GO ERP.exe' || destination.endsWith('\\'))
      throw Error('مسار نسخة البرنامج لا يسمح بالتحديث التلقائي.');
    await realAncestors(file);
    // Recheck the actual bytes after the caller's asynchronous page/database
    // guards. No earlier downloader hash can authorize a later replacement.
    const descriptor = await fs.open(file, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
    const identical = (left, right) => ['dev','ino','mode','nlink','size','mtimeNs','ctimeNs'].every(key => left[key] === right[key]);
    let verified;
    try {
      verified = await descriptor.stat({ bigint: true });
      if (!verified.isFile() || verified.nlink !== 1n || verified.size !== BigInt(installer.bytes))
        throw Error('ملف تحديث البرنامج غير مكتمل.');
      const hash = crypto.createHash('sha256'); let length = 0;
      for await (const bytes of descriptor.createReadStream({ autoClose: false, highWaterMark: 1024 * 1024 })) {
        length += bytes.length;
        if (length > installer.bytes) throw Error('ملف تحديث البرنامج تغير أثناء الفحص.');
        hash.update(bytes);
      }
      if (length !== installer.bytes || hash.digest('hex') !== installer.sha256
          || !identical(verified, await descriptor.stat({ bigint: true })))
        throw Error('ملف تحديث البرنامج تغير أثناء الفحص.');
    } finally { await descriptor.close(); }
    // This final named-file check and spawn share one JavaScript turn. In
    // particular there is no await between this check and native process launch.
    const named = lstatSync(file, { bigint: true });
    if (named.isSymbolicLink() || !named.isFile() || !identical(verified, named))
      throw Error('ملف تحديث البرنامج تغير قبل التثبيت.');
    return new Promise((resolve, reject) => {
      let child;
      try {
        child = spawn(file, ['/S', '/GOERPAUTOUPDATE', '/GOERPWAITPID=' + pid, '/D=' + destination], {
          detached: true, stdio: 'ignore', windowsHide: true, windowsVerbatimArguments: true, shell: false
        });
      } catch (error) { reject(error); return; }
      child.once('error', reject);
      child.once('spawn', () => { child.unref(); resolve({ pid: child.pid }); });
    });
  };
}

module.exports = createUpdateInstall;
