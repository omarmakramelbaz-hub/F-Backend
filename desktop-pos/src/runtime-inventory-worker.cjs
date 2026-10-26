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
let cancelled, quota;
const running = () => { if (Atomics.load(cancelled, 0)) fail('CANCELLED'); };
const identity = stat => [stat.dev, stat.ino, stat.mode, stat.nlink, stat.size, stat.mtimeNs, stat.ctimeNs];
const same = (left, right) => { const expected = identity(right); return identity(left).every((value, index) => value === expected[index]); };
const shardFor = name => {
  let hash = 0x811c9dc5;
  for (const byte of Buffer.from(name, 'utf8')) hash = Math.imul(hash ^ byte, 0x01000193) >>> 0;
  return hash % 4;
};

try {
  if (!workerData || workerData.format !== 1 || !(workerData.cancel instanceof SharedArrayBuffer)
      || workerData.cancel.byteLength !== 4 || !(workerData.quota instanceof SharedArrayBuffer)
      || workerData.quota.byteLength !== 8) fail('READ_FAILED');
  if (typeof workerData.directory !== 'string' || !path.isAbsolute(workerData.directory)
      || workerData.directory !== path.resolve(workerData.directory)) fail('PATH_INVALID');
  if (workerData.shards !== 4 || !Number.isInteger(workerData.shard)
      || workerData.shard < 0 || workerData.shard >= workerData.shards) fail('READ_FAILED');
  cancelled = new Int32Array(workerData.cancel);
  quota = new BigInt64Array(workerData.quota);
  if (Atomics.load(quota, 0) < 0n) fail('READ_FAILED');
  const directory = workerData.directory, files = [], cases = new Set(), buffer = Buffer.allocUnsafe(1024 * 1024);
  const roster = crypto.createHash('sha256').update('desktop-runtime-roster:1\n');
  let total = 0, rosterCount = 0, rosterBytes = 0;
  const walk = relative => {
    running();
    const folder = path.join(directory, relative), before = fs.lstatSync(folder, { bigint: true });
    if (!before.isDirectory() || before.isSymbolicLink()) fail('PATH_INVALID');
    const entries = fs.readdirSync(folder, { withFileTypes: true })
      .map(entry => ({ entry, order: Buffer.from(entry.name, 'utf8') }))
      .sort((left, right) => Buffer.compare(left.order, right.order));
    for (const { entry } of entries) {
      running();
      const name = relative ? relative + '/' + entry.name : entry.name;
      if (entry.isSymbolicLink() || /[\x00-\x1f:\\]/.test(name) || cases.has(name.toLowerCase())) fail('PATH_INVALID');
      cases.add(name.toLowerCase());
      rosterBytes += Buffer.byteLength(name, 'utf8') + 80;
      if (rosterBytes > MAX_RESULT_BYTES) fail('LIMIT');
      if (entry.isDirectory()) {
        roster.update('d\0' + name + '\n');
        walk(name);
      }
      else if (entry.isFile()) {
        if (++rosterCount > MAX_FILES) fail('LIMIT');
        roster.update('f\0' + name + '\n');
        // All four workers inspect the same complete roster. Exactly one owns
        // each filename's bytes; no retained code or cached hash is executed.
        if (shardFor(name) !== workerData.shard) continue;
        const file = path.join(directory, name);
        // The typed directory roster rejects links. Validate the opened handle
        // itself before any read, then match it to the pathname after hashing.
        // This removes one metadata round trip per file on Windows without
        // reusing a content hash or trusting timestamps in place of bytes.
        const fd = fs.openSync(file, fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW || 0)
          | (fs.constants.O_NONBLOCK || 0));
        try {
          const opened = fs.fstatSync(fd, { bigint: true });
          if (!opened.isFile() || opened.isSymbolicLink()) fail('FILE_INVALID');
          if (opened.size < 0n || opened.size > BigInt(MAX_BYTES - total)) fail('LIMIT');
          // Reserve before reading. Failed reservations remain consumed so no
          // concurrent shard can read past the original global 4 GiB cutoff.
          const reserved = Atomics.add(quota, 0, opened.size);
          if (reserved < 0n || reserved + opened.size > BigInt(MAX_BYTES)) fail('LIMIT');
          total += Number(opened.size);
          const hash = crypto.createHash('sha256');
          let read = 0;
          while (true) {
            running();
            const count = fs.readSync(fd, buffer, 0, buffer.length, null);
            if (!count) break;
            read += count;
            if (read > Number(opened.size)) fail('CHANGED');
            hash.update(buffer.subarray(0, count));
          }
          running();
          if (read !== Number(opened.size) || !same(opened, fs.fstatSync(fd, { bigint: true }))) fail('CHANGED');
          const current = fs.lstatSync(file, { bigint: true });
          if (!current.isFile() || current.isSymbolicLink() || !same(opened, current)) fail('CHANGED');
          files.push([name, hash.digest('hex')]);
        } finally { fs.closeSync(fd); }
      } else fail('FILE_INVALID');
    }
    running();
    if (!same(before, fs.lstatSync(folder, { bigint: true }))) fail('CHANGED');
  };
  walk('');
  running();
  parentPort.postMessage({ format: 1, kind: 'runtime-inventory', files, bytes: total,
    rosterCount, rosterSha256: roster.digest('hex') });
} catch (error) {
  const codes = new Set(['CANCELLED', 'PATH_INVALID', 'FILE_INVALID', 'LIMIT', 'CHANGED']);
  parentPort.postMessage({ format: 1, kind: 'runtime-inventory-error', code: codes.has(error?.inventoryCode) ? error.inventoryCode : 'READ_FAILED' });
} finally { parentPort.close(); }
