'use strict';
const fs = require('node:fs/promises');
const { createReadStream } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const nativePdf = require('./native-pdf.cjs');
const sourceCode = require('./dashboard-source.cjs');
const revision = value => /^[a-f0-9]{40}$/.test(value || '');

/** Retain the exact application and native binaries required by an existing local journal. */
class RuntimeArchive {
  constructor(profile, metadata, { stopped = () => false } = {}) {
    this.root = path.join(profile, 'runtimes'); this.metadata = metadata; this.verified = new Map(); this.stopped = stopped;
  }
  assertRunning() { if (this.stopped()) throw Error('البرنامج يُغلق الآن.'); }
  static databaseCompatible(current, candidate) {
    const entries = receipt => Object.entries(receipt?.files || {}).filter(([name]) => name.startsWith('mariadb/')).sort(([a],[b])=>a.localeCompare(b));
    const oldFiles=entries(current),newFiles=entries(candidate);
    for(const name of ['mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe'])
      if(!oldFiles.some(([key,value])=>key===name&&/^[a-f0-9]{64}$/.test(value)))throw Error('نسخة قاعدة البيانات الحالية لم تتأكد؛ بيانات الجهاز محفوظة.');
    if(JSON.stringify(oldFiles)!==JSON.stringify(newFiles))throw Error('ترقية ملفات قاعدة البيانات تحتاج تجهيزًا منفصلًا؛ النسخة الحالية وسجلاتها محفوظة.');
  }
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
  async manifest(directory) {
    const value = JSON.parse(await fs.readFile(path.join(directory, 'manifest.json'), 'utf8'));
    if (value.format !== 1 || value.platform !== 'win32-x64' || !revision(value.sourceRevision)
        || !value.sourceHashes || typeof value.sourceHashes !== 'object' || Array.isArray(value.sourceHashes)) throw Error('حزمة التشغيل غير متوافقة.');
    return value;
  }
  async verify(directory, receipt) {
    this.verified.delete(path.resolve(directory));
    if (receipt?.format !== 1 || !revision(receipt.revision) || !receipt.files || typeof receipt.files !== 'object') throw Error('سجل نسخة التشغيل غير صالح؛ العمليات المحلية محفوظة.');
    const actual = await this.inventory(directory);
    const expected = Object.keys(receipt.files).sort(), names = Object.keys(actual).sort();
    if (JSON.stringify(names) !== JSON.stringify(expected) || names.some(name => actual[name] !== receipt.files[name])) throw Error('نسخة التشغيل المحفوظة غير مكتملة؛ احتفظ ببيانات الجهاز لاسترجاعها.');
    const manifest = await this.manifest(directory);
    if (manifest.sourceRevision !== receipt.revision) throw Error('نسخة التشغيل لا تطابق سجل الجهاز.');
    // The authenticated receipt already binds every retained native byte, including
    // its original PDF policy. A future engine update must not invalidate old journals.
    this.verified.set(path.resolve(directory), { receipt, manifest });
    return directory;
  }
  async sourceFingerprint(directory) {
    this.assertRunning();
    // Only reuse a fingerprint after every native/application byte has just
    // matched its encrypted receipt. This is not a file-stat trust shortcut.
    const checked = this.verified.get(path.resolve(directory));
    if (!checked) throw Error('يلزم التحقق من نسخة التشغيل قبل قراءة مصدرها.');
    const { receipt, manifest } = checked;
    if (sourceCode.valid(receipt.sourceFingerprint)) {
      if (manifest.sourceFingerprint && !sourceCode.same(manifest.sourceFingerprint, receipt.sourceFingerprint))
        throw Error('مصدر الداشبورد لا يطابق حزمة التشغيل.');
      return structuredClone(receipt.sourceFingerprint);
    }
    const fingerprint = await sourceCode.fingerprint(path.join(directory, 'application'));
    this.assertRunning();
    if (manifest.sourceFingerprint && !sourceCode.same(manifest.sourceFingerprint, fingerprint))
      throw Error('مصدر الداشبورد لا يطابق حزمة التشغيل.');
    const updated = { ...receipt, sourceFingerprint: fingerprint };
    await this.metadata.write('runtimes/' + receipt.revision, updated);
    checked.receipt = updated;
    return structuredClone(fingerprint);
  }
  async retain(bundle) {
    this.assertRunning();
    const manifest = await this.manifest(bundle), id = manifest.sourceRevision;
    const destination = path.join(this.root, id), key = 'runtimes/' + id;
    const previous = await this.metadata.read(key);
    if (previous) return this.verify(destination, previous);
    const files = await this.inventory(bundle);
    if (manifest.pdfRuntime) await nativePdf.verify(bundle,manifest.pdfRuntime,files);
    else if (files['application/config/snappy.php']) throw Error('حزمة البرنامج غير مكتملة لتشغيل ملفات PDF؛ بيانات الجهاز محفوظة.');
    for (const [name, hash] of Object.entries(manifest.sourceHashes)) {
      if (files['application/' + name] !== hash) throw Error('مصدر الداشبورد لا يطابق حزمة التشغيل.');
    }
    await fs.mkdir(this.root, { recursive: true, mode: 0o700 });
    try { await fs.access(destination); throw Error('توجد نسخة تشغيل لم يكتمل حفظها؛ العمليات المحلية محفوظة.'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    const temporary = destination + '.stage-' + crypto.randomBytes(8).toString('hex');
    const receipt = { format: 1, revision: id, files };
    this.assertRunning();
    try {
      await fs.cp(bundle, temporary, { recursive: true, errorOnExist: true, force: false });
      await this.verify(temporary, receipt);
      this.assertRunning();
      await fs.rename(temporary, destination);
    } catch (error) {
      // This path belongs to this copy attempt only, never an active runtime.
      await fs.rm(temporary, { recursive: true, force: true }).catch(() => {});
      throw error;
    }
    await this.metadata.write(key, receipt);
    this.verified.delete(path.resolve(temporary));
    this.verified.set(path.resolve(destination), { receipt, manifest });
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
