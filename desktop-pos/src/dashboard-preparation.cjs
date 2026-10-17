'use strict';
const crypto = require('node:crypto');
const { prepared } = require('./dashboard-generation.cjs');
const media = require('./dashboard-media.cjs');
const sourceCode = require('./dashboard-source.cjs');

const uuid = value => /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value || '');
const hash = value => /^[a-f0-9]{64}$/.test(value || '');
function serverOrigin(value) {
  const url = new URL(value);
  if (url.protocol !== 'https:' || url.username || url.password || url.origin !== value) throw Error('رابط السيرفر غير صحيح.');
  return url.origin;
}
function enrollment(value, deviceId) {
  if (!value || value.format !== 1 || value.deviceId !== deviceId || !uuid(deviceId) || !hash(value.nonce)) return false;
  try { serverOrigin(value.serverOrigin); } catch { return false; }
  return value.enrolled !== true || (hash(value.token) && Number.isSafeInteger(value.actorId) && value.actorId > 0
    && Array.isArray(value.branches) && value.branches.length > 0);
}

/** The original signed-in dashboard starts preparation. Credentials remain in native encrypted metadata. */
class DashboardPreparation {
  constructor({ runtime, enroll, download, beforePrepare = async () => {}, onState = () => {}, onPrepared = async () => {} }) {
    this.runtime = runtime; this.metadata = runtime.metadata; this.enroll = enroll; this.download = download;
    this.onState = onState; this.onPrepared = onPrepared; this.state = { phase: 'idle', progress: 0, error: '' };
    this.beforePrepare = beforePrepare;
  }
  report(value) { Object.assign(this.state, value); this.onState({ ...this.state }); }
  async credential() {
    if (await this.runtime.isPrepared()) return this.runtime.connection();
    const device = await this.runtime.settings(), value = await this.metadata.read('enrollment');
    if (!enrollment(value, device.deviceId) || value.enrolled !== true) throw Error('الجهاز يحتاج تجهيزًا من حساب الداشبورد.');
    return value;
  }
  async status() {
    const ready = await this.runtime.isPrepared();
    return { available: true, prepared: ready, ...this.state, ...(ready && !this.running ? { phase: 'ready', progress: 100, error: '' } : {}) };
  }
  prepare(origin, csrf) {
    if (this.running) return this.running;
    this.running = this.initialize(origin, csrf).finally(() => { this.running = null; });
    return this.running;
  }
  async initialize(origin, csrf) {
    serverOrigin(origin);
    if (typeof csrf !== 'string' || !csrf || csrf.length > 256) throw Error('أعد فتح صفحة الداشبورد قبل تجهيز الجهاز.');
    if (await this.runtime.isPrepared()) {
      const current = await this.runtime.connection();
      if (current.serverOrigin !== origin) throw Error('الجهاز مجهّز لحساب سيرفر آخر؛ سجلاته محفوظة.');
      return this.status();
    }
    try {
      await this.beforePrepare();
      const device = await this.runtime.settings();
      let value = await this.metadata.read('enrollment');
      if (value && (!enrollment(value, device.deviceId) || value.serverOrigin !== origin))
        throw Error('ربط الجهاز الحالي لا يطابق السيرفر؛ بيانات الجهاز محفوظة.');
      if (!value) {
        value = { format: 1, deviceId: device.deviceId, serverOrigin: origin, nonce: crypto.randomBytes(32).toString('hex'), enrolled: false };
        // Persist the same nonce BEFORE contacting the server, including a lost enrollment reply.
        await this.metadata.write('enrollment', value);
      }
      this.report({ phase: 'enrolling', progress: 5, error: '' });
      // Retry through the current browser session even after an earlier enrollment succeeded.
      // The server rejects another account; a cached bearer must not prepare that account's window.
      const linked = await this.enroll(origin, { device_id: value.deviceId, name: 'جهاز فسخانستا', nonce: value.nonce }, csrf);
      if (linked.device_id !== device.deviceId || linked.protocol !== 1 || !hash(linked.token)
        || !Number.isSafeInteger(linked.actor_id) || linked.actor_id < 1 || !Array.isArray(linked.branches) || !linked.branches.length)
        throw Error('لم يؤكد السيرفر ربط حساب الجهاز.');
      value = { ...value, enrolled: true, actorId: linked.actor_id, token: linked.token, branches: linked.branches };
      await this.metadata.write('enrollment', value);
      this.report({ phase: 'downloading', progress: 15 });
      const snapshot = await this.download(value);
      if (snapshot.format !== 1 || snapshot.kind !== 'initial-dashboard-data' || !uuid(snapshot.snapshot_id)
        || snapshot.device_id !== value.deviceId || snapshot.actor_id !== value.actorId || !hash(snapshot.schema_hash)
        || !Array.isArray(snapshot.branches) || !snapshot.branches.length)
        throw Error('بيانات التجهيز لا تخص حساب الجهاز.');
      if (snapshot.coverage?.full_dashboard !== true || snapshot.coverage?.media !== true || !Array.isArray(snapshot.media))
        throw Error('تجهيز كل أقسام الداشبورد لم يكتمل بعد؛ يمكنك الاستمرار على السيرفر.');
      media.manifest(snapshot.media);
      if (!sourceCode.valid(snapshot.source)) throw Error('مصدر نسخة السيرفر لم يتأكد بعد؛ بيانات الجهاز محفوظة.');
      const id = crypto.randomBytes(8).toString('hex'), refreshId = crypto.randomUUID();
      await this.metadata.write('preparation', { format: 1, generation: id, refreshId, deviceId: value.deviceId, actorId: value.actorId, snapshotId: snapshot.snapshot_id });
      this.report({ phase: 'starting', progress: 30 });
      await this.runtime.start();
      this.report({ phase: 'verifying', progress: 45 });
      const candidate = await this.runtime.stage(snapshot, id);
      if (!sourceCode.same(snapshot.source, candidate.sourceFingerprint)) throw Error('مصدر برنامج التجهيز لا يطابق نسخة السيرفر.');
      const next = { format: 2, deviceId: value.deviceId, actorId: value.actorId, token: value.token, serverOrigin: origin,
        schemaHash: snapshot.schema_hash, branches: snapshot.branches, generation: id, database: candidate.database,
        snapshotId: snapshot.snapshot_id, refreshId, sourceRevision: candidate.sourceRevision,
        sourceFingerprint: candidate.sourceFingerprint,
        fullCoverage: true, mediaVerified: candidate.mediaVerified };
      if (!prepared(next, value.deviceId)) throw Error('بيانات التجهيز المحلية لم تكتمل.');
      await this.metadata.write('generations/' + refreshId, { format: 1, initial: true, next, receipt: candidate.receipt });
      this.report({ phase: 'activating', progress: 95 });
      await this.runtime.activate(next, () => this.metadata.write('prepared', next));
      await this.onPrepared(next);
      this.report({ phase: 'ready', progress: 100, error: '' });
      return { available: true, prepared: true, ...this.state };
    } catch (error) {
      // Partial databases/images and enrollment capabilities are retained for recovery.
      this.report({ phase: 'failed', error: error.message });
      throw error;
    }
  }
}
module.exports = { DashboardPreparation, enrollment, serverOrigin };
