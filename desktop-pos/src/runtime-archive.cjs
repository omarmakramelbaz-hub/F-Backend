'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { Worker } = require('node:worker_threads');
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
    this.assertRunning();
    const cancel = new Int32Array(new SharedArrayBuffer(4));
    const quota = new BigInt64Array(new SharedArrayBuffer(8));
    // Four fixed installed-application workers overlap native I/O without
    // hashing on Electron's UI thread. Each owns a 1 MiB read buffer and a full
    // roster, while every retained byte is freshly hashed by exactly one shard.
    const workers = [], exits = []; let failure, timer;
    const invalid = () => Error('تعذر تأكيد فحص حزمة التشغيل؛ بيانات الجهاز محفوظة.');
    const stop = error => {
      failure ||= error; Atomics.store(cancel, 0, 1);
      // A partial spawn or failed protocol must drain every worker already
      // created. The caller cannot settle until all their exit events arrive.
      for (const record of workers) if (!record.exited && !record.terminating) {
        record.terminating = true; record.worker.terminate().catch(() => {});
      }
    };
    const shardFor = name => {
      let hash = 0x811c9dc5;
      for (const byte of Buffer.from(name, 'utf8')) hash = Math.imul(hash ^ byte, 0x01000193) >>> 0;
      return hash % 4;
    };
    const codes = {
      CANCELLED: 'البرنامج يُغلق الآن.', PATH_INVALID: 'حزمة التشغيل تحتوي مسارًا غير صالح.',
      FILE_INVALID: 'حزمة التشغيل تحتوي ملفًا غير صالح.', LIMIT: 'حزمة التشغيل أكبر من الحد المسموح.',
      CHANGED: 'ملف التشغيل تغير أثناء الفحص.', READ_FAILED: 'تعذر قراءة حزمة التشغيل؛ بيانات الجهاز محفوظة.'
    };
    try {
      for (let shard = 0; shard < 4; shard++) {
        let worker;
        try {
          worker = new Worker(path.join(__dirname, 'runtime-inventory-worker.cjs'), {
            workerData: { format: 1, directory: path.resolve(directory), cancel: cancel.buffer,
              quota: quota.buffer, shard, shards: 4 }, execArgv: []
          });
        } catch { stop(invalid()); break; }
        const record = { worker, shard, messages: 0, exited: false, terminating: false };
        workers.push(record);
        exits.push(new Promise(resolve => {
          worker.on('message', value => {
            if (++record.messages !== 1 || !value || value.format !== 1) return stop(invalid());
            if (value.kind === 'runtime-inventory-error') {
              if (Object.keys(value).sort().join(',') !== 'code,format,kind'
                  || typeof value.code !== 'string' || !Object.hasOwn(codes, value.code)) return stop(invalid());
              record.message = value; stop(Error(codes[value.code])); return;
            }
            if (value.kind !== 'runtime-inventory' || Object.keys(value).sort().join(',') !== 'bytes,files,format,kind,rosterCount,rosterSha256'
                || !Array.isArray(value.files) || value.files.length > 100000 || !Number.isSafeInteger(value.bytes)
                || value.bytes < 0 || value.bytes > 4 * 1024 ** 3 || !Number.isSafeInteger(value.rosterCount)
                || value.rosterCount < value.files.length || value.rosterCount > 100000
                || typeof value.rosterSha256 !== 'string' || !/^[a-f0-9]{64}$/.test(value.rosterSha256)) return stop(invalid());
            const cases = new Set(); let bytes = 0;
            for (const row of value.files) {
              if (!Array.isArray(row) || row.length !== 2 || typeof row[0] !== 'string' || !row[0]
                  || row[0].split('/').some(part => !part || part === '.' || part === '..')
                  || /[\x00-\x1f:\\]/.test(row[0]) || cases.has(row[0].toLowerCase()) || shardFor(row[0]) !== shard
                  || typeof row[1] !== 'string' || !/^[a-f0-9]{64}$/.test(row[1])) return stop(invalid());
              cases.add(row[0].toLowerCase()); bytes += Buffer.byteLength(row[0], 'utf8') + 80;
              if (bytes > 32 * 1024 ** 2) return stop(invalid());
            }
            record.message = value;
          });
          worker.on('error', () => stop(invalid()));
          worker.once('exit', code => {
            record.exited = true; record.exit = code;
            if (code !== 0 || record.messages !== 1 || !record.message) stop(invalid());
            resolve();
          });
        }));
      }
      timer = setInterval(() => {
        try { this.assertRunning(); } catch (error) { stop(error); }
      }, 20);
      timer.unref();
      await Promise.all(exits);
      this.assertRunning();
      if (failure) throw failure;
      if (workers.length !== 4) throw invalid();
      const first = workers[0].message, cases = new Set(), files = []; let bytes = 0, resultBytes = 0;
      for (const record of workers) {
        const value = record.message;
        if (record.exit !== 0 || record.messages !== 1 || value?.kind !== 'runtime-inventory'
            || value.rosterCount !== first.rosterCount || value.rosterSha256 !== first.rosterSha256) throw invalid();
        bytes += value.bytes;
        if (bytes > 4 * 1024 ** 3) throw invalid();
        for (const row of value.files) {
          if (cases.has(row[0].toLowerCase())) throw invalid();
          cases.add(row[0].toLowerCase()); files.push(row);
          resultBytes += Buffer.byteLength(row[0], 'utf8') + 80;
          if (files.length > 100000 || resultBytes > 32 * 1024 ** 2) throw invalid();
        }
      }
      if (files.length !== first.rosterCount || BigInt(bytes) !== Atomics.load(quota, 0)) throw invalid();
      return Object.fromEntries(files);
    } finally { clearInterval(timer); }
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
    this.assertRunning();
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
