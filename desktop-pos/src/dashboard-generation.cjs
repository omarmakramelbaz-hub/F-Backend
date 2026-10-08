'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');

const uuid = value => /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value || '');
const hash = value => /^[a-f0-9]{64}$/.test(value || '');
const generation = value => /^[a-f0-9]{16}$/.test(value || '');
const database = value => value === 'fasakhansta_dashboard' || /^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(value || '');
function identity(value) {
  return JSON.stringify([value.deviceId, value.actorId, value.schemaHash, value.database || 'fasakhansta_dashboard', value.snapshotId || null, value.refreshId || null, value.serverOrigin, value.token]);
}
function prepared(value, deviceId) {
  let origin;
  try { origin = new URL(value.serverOrigin); } catch { return false; }
  if (![1, 2].includes(value.format) || value.deviceId !== deviceId || !uuid(deviceId)
      || !Number.isSafeInteger(value.actorId) || value.actorId < 1 || !hash(value.schemaHash)
      || value.fullCoverage !== true || !hash(value.token) || origin.protocol !== 'https:'
      || origin.username || origin.password || origin.origin !== value.serverOrigin) return false;
  if (value.format === 1) return value.database === undefined && value.generation === undefined;
  return generation(value.generation) && value.database === 'fasakhansta_dashboard_stage_' + value.generation
    && uuid(value.snapshotId) && uuid(value.refreshId) && /^[a-f0-9]{40}$/.test(value.sourceRevision || '')
    && value.mediaVerified === true;
}

/** Only encrypted metadata moves. Databases and previous journals are never removed. */
class GenerationStore {
  constructor(profile, safeStorage) { this.profile = profile; this.safeStorage = safeStorage; }
  async read(name) {
    try { return JSON.parse(this.safeStorage.decryptString(await fs.readFile(path.join(this.profile, name + '.enc')))); }
    catch (error) { if (error.code === 'ENOENT') return null; throw error; }
  }
  async write(name, value) {
    if (!this.safeStorage.isEncryptionAvailable()) throw Error('تعذر حماية بيانات الجهاز.');
    const target = path.join(this.profile, name + '.enc');
    await fs.mkdir(path.dirname(target), { recursive: true, mode: 0o700 });
    const temporary = target + '.' + crypto.randomBytes(8).toString('hex') + '.tmp';
    const file = await fs.open(temporary, 'wx', 0o600);
    try { await file.writeFile(this.safeStorage.encryptString(JSON.stringify(value))); await file.sync(); }
    finally { await file.close(); }
    try { await fs.rename(temporary, target); }
    catch (error) { await fs.unlink(temporary).catch(() => {}); throw error; }
    // Windows does not expose directory fsync; file contents are flushed before atomic rename.
    let directory;
    try { directory = await fs.open(path.dirname(target), 'r'); await directory.sync(); }
    catch (error) { if (process.platform !== 'win32') throw error; }
    finally { await directory?.close(); }
  }
  async clearIntent() { await fs.unlink(path.join(this.profile, 'refresh.enc')).catch(error => { if (error.code !== 'ENOENT') throw error; }); }
}

/** A refresh is accepted only after the original outbox has been durably confirmed. */
class DashboardGeneration {
  constructor({ runtime, metadata, download, transition = async work => work() }) {
    this.runtime = runtime; this.metadata = metadata; this.download = download; this.transition = transition;
  }
  recover() {
    if (this.running) return this.running;
    this.running = this.recoverIntent().finally(() => { this.running = null; });
    return this.running;
  }
  async recoverIntent() {
    const intent = await this.metadata.read('refresh');
    if (!intent) return;
    if (intent.format !== 1 || !uuid(intent.id) || !hash(intent.token) || !generation(intent.generation)
        || !intent.previous || !database(intent.previous.database || 'fasakhansta_dashboard')) throw Error('تعذر استرجاع تحديث بيانات الجهاز؛ السجلات محفوظة.');
    const active = await this.runtime.connection();
    if (active.refreshId === intent.id && active.database === 'fasakhansta_dashboard_stage_' + intent.generation) {
      // The pointer committed before a lost restart/reply. Never cancel the archived fence.
      await this.metadata.clearIntent(); return;
    }
    if (identity(active) !== identity(intent.previous)) throw Error('نقطة استرجاع التحديث لا تطابق قاعدة الجهاز الحالية.');
    const state = await this.runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    if (state.exists && state.held) await this.runtime.control({ action: 'refresh-cancel', refresh_id: intent.id, token: intent.token });
    await this.metadata.clearIntent();
  }
  run() {
    if (this.running) return this.running;
    this.running = this.refresh().finally(() => { this.running = null; });
    return this.running;
  }
  async refresh() {
    await this.recoverIntent();
    const previous = await this.runtime.connection();
    const intent = { format: 1, id: crypto.randomUUID(), token: crypto.randomBytes(32).toString('hex'),
      generation: crypto.randomBytes(8).toString('hex'), previous };
    // Save the capability before acquiring a durable database fence, including lost replies.
    await this.metadata.write('refresh', intent);
    try {
      const fence = await this.runtime.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token });
      if (!fence.held || fence.device_id !== previous.deviceId || fence.actor_id !== previous.actorId
          || fence.schema_hash !== previous.schemaHash || fence.refresh_id !== intent.id) throw Error('حالة تجهيز الجهاز غير متطابقة.');
      const snapshot = await this.download(previous);
      if (snapshot.format !== 1 || snapshot.kind !== 'initial-dashboard-data' || !uuid(snapshot.snapshot_id)
          || snapshot.device_id !== previous.deviceId || snapshot.actor_id !== previous.actorId
          || snapshot.schema_hash !== previous.schemaHash || !Array.isArray(snapshot.branches) || !snapshot.branches.length)
        throw Error('نسخة السيرفر لا تطابق حساب الجهاز أو مخططه.');
      if (snapshot.coverage?.full_dashboard !== true || snapshot.coverage?.media !== true
          || !Array.isArray(snapshot.media) || snapshot.media.length !== 0)
        throw Error('نسخة الداشبورد لم تكتمل بعد؛ بيانات الجهاز الحالية محفوظة.');
      // Uploaded media requires its own verified download contract before nonempty manifests can activate.
      const candidate = await this.runtime.stage(snapshot, intent.generation, intent);
      const next = { ...previous, format: 2, generation: intent.generation, database: candidate.database,
        snapshotId: snapshot.snapshot_id, schemaHash: snapshot.schema_hash, branches: snapshot.branches,
        fullCoverage: true, mediaVerified: true, refreshId: intent.id, sourceRevision: candidate.sourceRevision };
      if (!prepared(next, previous.deviceId)) throw Error('نسخة التجهيز المحلية غير مكتملة.');
      await this.metadata.write('generations/' + intent.id, { format: 1, previous, next, fence, receipt: candidate.receipt });
      await this.transition(() => this.runtime.activate(next, () => this.metadata.write('prepared', next)));
      await this.metadata.clearIntent();
      return next;
    } catch (error) {
      // On an uncertain cancellation retain the encrypted intent and the durable write fence.
      // On an uncertain activation recovery reads the committed pointer, never guesses.
      try { await this.recoverIntent(); }
      catch { throw Error(error.message + ' يلزم استرجاع التحديث عند إعادة فتح البرنامج؛ العمليات محفوظة.'); }
      throw error;
    }
  }
}
module.exports = { DashboardGeneration, GenerationStore, prepared, database, generation };
