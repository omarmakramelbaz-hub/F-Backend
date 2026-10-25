'use strict';
// This fixed application worker reads bytes only. Never execute code from the
// retained runtime before its complete inventory matches the encrypted receipt.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { isMainThread, parentPort, workerData } = require('node:worker_threads');

if (isMainThread || !parentPort) throw Error('Runtime inventory requires a worker.');
const MAX_FILES = 100000, MAX_BYTES = 4 * 1024 ** 3, MAX_RESULT_BYTES = 32 * 1024 ** 2;
const fail = code => { const error = Error(code); error.inventoryCode = code; throw error; };
let cancelled;
const running = () => { if (Atomics.load(cancelled, 0)) fail('CANCELLED'); };
const identity = stat => [stat.dev, stat.ino, stat.mode, stat.nlink, stat.size, stat.mtimeNs, stat.ctimeNs];
const same = (left, right) => { const expected = identity(right); return identity(left).every((value, index) => value === expected[index]); };

try {
  if (!workerData || workerData.format !== 1 || !(workerData.cancel instanceof SharedArrayBuffer)
      || workerData.cancel.byteLength !== 4) fail('READ_FAILED');
  if (typeof workerData.directory !== 'string' || !path.isAbsolute(workerData.directory)
      || workerData.directory !== path.resolve(workerData.directory)) fail('PATH_INVALID');
  cancelled = new Int32Array(workerData.cancel);
  const directory = workerData.directory, files = [], cases = new Set(), buffer = Buffer.allocUnsafe(1024 * 1024);
  let total = 0, resultBytes = 0;
  const walk = relative => {
    running();
    const folder = path.join(directory, relative), before = fs.lstatSync(folder, { bigint: true });
    if (!before.isDirectory() || before.isSymbolicLink()) fail('PATH_INVALID');
    for (const entry of fs.readdirSync(folder, { withFileTypes: true })) {
      running();
      const name = relative ? relative + '/' + entry.name : entry.name;
      if (entry.isSymbolicLink() || /[\x00-\x1f:\\]/.test(name) || cases.has(name.toLowerCase())) fail('PATH_INVALID');
      cases.add(name.toLowerCase());
      if (entry.isDirectory()) walk(name);
      else if (entry.isFile()) {
        if (files.length >= MAX_FILES) fail('LIMIT');
        resultBytes += Buffer.byteLength(name, 'utf8') + 80;
        if (resultBytes > MAX_RESULT_BYTES) fail('LIMIT');
        const file = path.join(directory, name), stat = fs.lstatSync(file, { bigint: true });
        if (!stat.isFile() || stat.isSymbolicLink()) fail('FILE_INVALID');
        if (stat.size > BigInt(MAX_BYTES - total)) fail('LIMIT');
        total += Number(stat.size);
        const fd = fs.openSync(file, fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW || 0));
        try {
          const opened = fs.fstatSync(fd, { bigint: true });
          if (!opened.isFile() || !same(stat, opened)) fail('CHANGED');
          const hash = crypto.createHash('sha256');
          let read = 0;
          while (true) {
            running();
            const count = fs.readSync(fd, buffer, 0, buffer.length, null);
            if (!count) break;
            read += count;
            if (read > Number(stat.size)) fail('CHANGED');
            hash.update(buffer.subarray(0, count));
          }
          running();
          if (read !== Number(stat.size) || !same(opened, fs.fstatSync(fd, { bigint: true }))
              || !same(stat, fs.lstatSync(file, { bigint: true }))) fail('CHANGED');
          files.push([name, hash.digest('hex')]);
        } finally { fs.closeSync(fd); }
      } else fail('FILE_INVALID');
    }
    running();
    if (!same(before, fs.lstatSync(folder, { bigint: true }))) fail('CHANGED');
  };
  walk('');
  running();
  parentPort.postMessage({ format: 1, kind: 'runtime-inventory', files, bytes: total });
} catch (error) {
  const codes = new Set(['CANCELLED', 'PATH_INVALID', 'FILE_INVALID', 'LIMIT', 'CHANGED']);
  parentPort.postMessage({ format: 1, kind: 'runtime-inventory-error', code: codes.has(error?.inventoryCode) ? error.inventoryCode : 'READ_FAILED' });
} finally { parentPort.close(); }
