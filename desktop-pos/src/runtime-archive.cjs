'use strict';
const fs = require('node:fs/promises');
const { createReadStream } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const revision = value => /^[a-f0-9]{40}$/.test(value || '');

/** Retain the exact application and native binaries required by an existing local journal. */
class RuntimeArchive {
  constructor(profile, metadata) { this.root = path.join(profile, 'runtimes'); this.metadata = metadata; }
  async inventory(directory) {
    const files = {}, cases = new Set(), pending = new Set(); let total = 0, count = 0, failure;
    const inspect = async name => {
      const file = path.join(directory, name), stat = await fs.stat(file), hash = crypto.createHash('sha256');
      total += stat.size;if (total > 4 * 1024 ** 3) throw Error('حزمة التشغيل أكبر من الحد المسموح.');
      let read = 0;for await (const bytes of createReadStream(file)) { read += bytes.length;hash.update(bytes); }
      if (read !== stat.size) throw Error('ملف التشغيل تغير أثناء الفحص.');
      files[name] = hash.digest('hex');
    };
    const walk = async relative => {
      for (const entry of await fs.readdir(path.join(directory, relative), { withFileTypes: true })) {
        const name = relative ? relative + '/' + entry.name : entry.name;
        if (entry.isSymbolicLink() || /[\x00-\x1f:]/.test(name) || cases.has(name.toLowerCase())) throw Error('حزمة التشغيل تحتوي مسارًا غير صالح.');
        cases.add(name.toLowerCase());
        if (entry.isDirectory()) await walk(name);
        else if (entry.isFile()) {
          if (++count > 100000) throw Error('حزمة التشغيل أكبر من الحد المسموح.');
          const task = inspect(name).catch(error => { failure ||= error; }).finally(() => pending.delete(task));pending.add(task);
          if (pending.size >= 16) await Promise.race(pending);
        } else throw Error('حزمة التشغيل تحتوي ملفًا غير صالح.');
      }
    };
    await walk('');await Promise.all(pending);if(failure)throw failure;return files;
  }
  async manifest(directory) {
    const value = JSON.parse(await fs.readFile(path.join(directory, 'manifest.json'), 'utf8'));
    if (value.format !== 1 || value.platform !== 'win32-x64' || !revision(value.sourceRevision)
        || !value.sourceHashes || typeof value.sourceHashes !== 'object' || Array.isArray(value.sourceHashes)) throw Error('حزمة التشغيل غير متوافقة.');
    return value;
  }
  async verify(directory, receipt) {
    if (receipt?.format !== 1 || !revision(receipt.revision) || !receipt.files || typeof receipt.files !== 'object') throw Error('سجل نسخة التشغيل غير صالح؛ العمليات المحلية محفوظة.');
    const actual = await this.inventory(directory);
    const expected = Object.keys(receipt.files).sort(), names = Object.keys(actual).sort();
    if (JSON.stringify(names) !== JSON.stringify(expected) || names.some(name => actual[name] !== receipt.files[name])) throw Error('نسخة التشغيل المحفوظة غير مكتملة؛ احتفظ ببيانات الجهاز لاسترجاعها.');
    const manifest = await this.manifest(directory);
    if (manifest.sourceRevision !== receipt.revision) throw Error('نسخة التشغيل لا تطابق سجل الجهاز.');
    return directory;
  }
  async retain(bundle) {
    const manifest = await this.manifest(bundle), id = manifest.sourceRevision;
    const destination = path.join(this.root, id), key = 'runtimes/' + id;
    const previous = await this.metadata.read(key);
    if (previous) return this.verify(destination, previous);
    const files = await this.inventory(bundle);
    for (const [name, hash] of Object.entries(manifest.sourceHashes)) {
      if (files['application/' + name] !== hash) throw Error('مصدر الداشبورد لا يطابق حزمة التشغيل.');
    }
    await fs.mkdir(this.root, { recursive: true, mode: 0o700 });
    try { await fs.access(destination); throw Error('توجد نسخة تشغيل لم يكتمل حفظها؛ العمليات المحلية محفوظة.'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    const temporary = destination + '.stage-' + crypto.randomBytes(8).toString('hex');
    await fs.cp(bundle, temporary, { recursive: true, errorOnExist: true, force: false });
    const receipt = { format: 1, revision: id, files };
    await this.verify(temporary, receipt);
    await fs.rename(temporary, destination);
    await this.metadata.write(key, receipt);
    return destination;
  }
  async select(bundle, active) {
    const manifest = await this.manifest(bundle);
    if (!active?.sourceRevision || active.sourceRevision === manifest.sourceRevision) return this.retain(bundle);
    if (!revision(active.sourceRevision)) throw Error('نسخة بيانات الجهاز غير صالحة.');
    const receipt = await this.metadata.read('runtimes/' + active.sourceRevision);
    if (!receipt) throw Error('يلزم استرجاع نسخة التشغيل السابقة قبل فتح بيانات الجهاز؛ العمليات محفوظة.');
    // Do not start a new MariaDB binary against the old data directory during an app update.
    return this.verify(path.join(this.root, active.sourceRevision), receipt);
  }
}
module.exports = RuntimeArchive;
