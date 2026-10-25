'use strict';
// Test-only frozen preview52 inventory. Keep this method byte-identical to
// afb333bbddd9dbc908e3c3674d99c766ed5c4053:desktop-pos/src/runtime-archive.cjs.
const fs = require('node:fs/promises');
const { createReadStream } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
class RuntimeInventoryBaseline {
  constructor({ stopped = () => false } = {}) { this.stopped = stopped; }
  assertRunning() { if (this.stopped()) throw Error('البرنامج يُغلق الآن.'); }
  async inventory(directory) {
    const files = {}, cases = new Set(), pending = new Set(); let total = 0, count = 0, failure;
    const inspect = async name => {
      this.assertRunning();
      const file = path.join(directory, name), stat = await fs.lstat(file), hash = crypto.createHash('sha256');
      if (!stat.isFile() || stat.isSymbolicLink()) throw Error('حزمة التشغيل تحتوي ملفًا غير صالح.');
      total += stat.size;if (total > 4 * 1024 ** 3) throw Error('حزمة التشغيل أكبر من الحد المسموح.');
      // Most of Laravel's retained dependencies are tiny files. A stream for
      // each one opens extra handles/events and is particularly expensive on
      // Windows with antivirus inspection. Bound memory by keeping large
      // binaries streaming and only reading small files at the existing limit.
      let read = 0;
      if (stat.size <= 1024 * 1024) { const bytes = await fs.readFile(file); read = bytes.length; hash.update(bytes); }
      else for await (const bytes of createReadStream(file)) { this.assertRunning(); read += bytes.length;hash.update(bytes); }
      this.assertRunning();
      if (read !== stat.size) throw Error('ملف التشغيل تغير أثناء الفحص.');
      files[name] = hash.digest('hex');
    };
    const walk = async relative => {
      this.assertRunning();
      for (const entry of await fs.readdir(path.join(directory, relative), { withFileTypes: true })) {
        this.assertRunning();
        const name = relative ? relative + '/' + entry.name : entry.name;
        if (entry.isSymbolicLink() || /[\x00-\x1f:]/.test(name) || cases.has(name.toLowerCase())) throw Error('حزمة التشغيل تحتوي مسارًا غير صالح: ' + name);
        cases.add(name.toLowerCase());
        if (entry.isDirectory()) await walk(name);
        else if (entry.isFile()) {
          if (++count > 100000) throw Error('حزمة التشغيل أكبر من الحد المسموح.');
          const task = inspect(name).catch(error => { failure ||= error; }).finally(() => pending.delete(task));pending.add(task);
          if (pending.size >= 16) await Promise.race(pending);
        } else throw Error('حزمة التشغيل تحتوي ملفًا غير صالح.');
      }
    };
    // In-flight reads must settle even when cancellation stops the directory
    // walk, before startup's accepted-work barrier can complete.
    try { await walk(''); } finally { await Promise.all(pending); }
    if(failure)throw failure;return files;
  }
}
module.exports = RuntimeInventoryBaseline;
