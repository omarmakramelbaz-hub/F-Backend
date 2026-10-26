'use strict';
const crypto = require('node:crypto');
const { prepared } = require('./dashboard-generation.cjs');
const media = require('./dashboard-media.cjs');
const sourceCode = require('./dashboard-source.cjs');
const coverage = require('./dashboard-coverage.cjs');

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
  constructor({ runtime, enroll, download, allowPreview = false, beforePrepare = async () => {}, onState = () => {}, onEnrolled = async () => {}, onPrepared = async () => {}, now = () => performance.now() }) {
    this.runtime = runtime; this.metadata = runtime.metadata; this.enroll = enroll; this.download = download;
    this.onState = onState; this.onPrepared = onPrepared; this.state = { phase: 'idle', progress: 0, error: '' };
    this.onEnrolled = onEnrolled;
    this.beforePrepare = beforePrepare;
    this.allowPreview = allowPreview === true;
    this.now = now; this.setupStarted = null; this.setupFinished = null;
  }
  timing() {
    if (this.setupStarted === null) return null;
    return { elapsed_ms: Math.min(Number.MAX_SAFE_INTEGER, Math.max(0, Math.floor((this.setupFinished ?? this.now()) - this.setupStarted))),
      running: this.setupFinished === null };
  }
  report(value) { Object.assign(this.state, value); this.onState({ ...this.state, setup_timing: this.timing() }); }
  async credential() {
    if (await this.runtime.isPrepared()) return this.runtime.connection();
    const device = await this.runtime.settings(), value = await this.metadata.read('enrollment');
    if (!enrollment(value, device.deviceId) || value.enrolled !== true) throw Error('الجهاز يحتاج تجهيزًا من حساب الداشبورد.');
    return value;
  }
  async status() {
    const ready = await this.runtime.isPrepared();
    return { available: true, prepared: ready, ...this.state, setup_timing: this.timing(), ...(ready && !this.running ? { phase: 'ready', progress: 100, error: '' } : {}) };
  }
  prepare(origin, csrf) {
    if (this.running) return this.running;
    this.setupStarted = this.now(); this.setupFinished = null;
    this.running = this.initialize(origin, csrf).finally(() => {
      this.setupFinished = this.now(); this.running = null;
      this.onState({ ...this.state, setup_timing: this.timing() });
    });
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
      delete this.state.media;
      this.report({ phase: 'enrolling', progress: 5, error: '' });
      // Retry through the current browser session even after an earlier enrollment succeeded.
      // The server rejects another account; a cached bearer must not prepare that account's window.
      const linked = await this.enroll(origin, { device_id: value.deviceId, name: 'جهاز فسخانستا', nonce: value.nonce }, csrf);
      if (linked.device_id !== device.deviceId || linked.protocol !== 1 || !hash(linked.token)
        || !Number.isSafeInteger(linked.actor_id) || linked.actor_id < 1 || !Array.isArray(linked.branches) || !linked.branches.length)
        throw Error('لم يؤكد السيرفر ربط حساب الجهاز.');
      value = { ...value, enrolled: true, actorId: linked.actor_id, token: linked.token, branches: linked.branches };
      await this.metadata.write('enrollment', value);
      await this.onEnrolled(value);
      this.report({ phase: 'downloading', progress: 15 });
      const snapshot = await this.download(value);
      if (snapshot.format !== 1 || snapshot.kind !== 'initial-dashboard-data' || !uuid(snapshot.snapshot_id)
        || snapshot.device_id !== value.deviceId || snapshot.actor_id !== value.actorId || !hash(snapshot.schema_hash)
        || !Array.isArray(snapshot.branches) || !snapshot.branches.length)
        throw Error('بيانات التجهيز لا تخص حساب الجهاز.');
      media.manifest(snapshot.media);
      if (!sourceCode.valid(snapshot.source)) throw Error('مصدر نسخة السيرفر لم يتأكد بعد؛ بيانات الجهاز محفوظة.');
      const manifest = snapshot.coverage?.full_dashboard !== true && this.allowPreview ? await this.runtime.coverageManifest(snapshot) : null;
      coverage.snapshotCoverage(snapshot, manifest, this.allowPreview);
      const id = crypto.randomBytes(8).toString('hex'), refreshId = crypto.randomUUID();
      await this.metadata.write('preparation', { format: 1, generation: id, refreshId, deviceId: value.deviceId, actorId: value.actorId, snapshotId: snapshot.snapshot_id });
      this.report({ phase: 'starting', progress: 30 });
      await this.runtime.start();
      this.report({ phase: 'verifying', progress: 45 });
      // Images are the longest step; move the bar from 45% to 90% as they arrive.
      let shown = 0;
      const candidate = await this.runtime.stage(snapshot, id, undefined, ({ done, total }) => {
        const now = Date.now();
        if (!total || (done < total && now - shown < 250)) return;
        shown = now; this.report({ progress: 45 + Math.floor(45 * done / total), media: { done, total } });
      });
      delete this.state.media;
      if (!sourceCode.same(snapshot.source, candidate.sourceFingerprint)) throw Error('مصدر برنامج التجهيز لا يطابق نسخة السيرفر.');
      const scope = coverage.preparedCoverage(snapshot, candidate, manifest, this.allowPreview);
      const next = { format: 2, deviceId: value.deviceId, actorId: value.actorId, token: value.token, serverOrigin: origin,
        schemaHash: snapshot.schema_hash, branches: snapshot.branches, generation: id, database: candidate.database,
        snapshotId: snapshot.snapshot_id, refreshId, sourceRevision: candidate.sourceRevision,
        sourceFingerprint: candidate.sourceFingerprint,
        ...scope, mediaVerified: candidate.mediaVerified };
      if (next.previewOnly) next.coverageBinding = coverage.binding(next);
      if (!prepared(next, value.deviceId, manifest)) throw Error('بيانات التجهيز المحلية لم تكتمل.');
      await this.metadata.write('generations/' + refreshId, { format: 1, initial: true, next, receipt: candidate.receipt });
      this.report({ phase: 'activating', progress: 95 });
      await this.runtime.activate(next, () => this.metadata.write('prepared', next));
      const actor=snapshot.tables?.users?.rows?.find(row=>String(row.id)===String(next.actorId));
      const rawLabel=typeof actor?.name==='string'?actor.name:typeof actor?.email==='string'?actor.email:'';
      const accountLabel=rawLabel.replace(/[\x00-\x1f\x7f]/g,' ').trim().slice(0,80);
      const accountLogins=[...new Set([actor?.email,actor?.mobile].filter(value=>typeof value==='string'
        &&value.trim()&&value.length<=320&&!/[\x00-\x1f\x7f]/.test(value)).map(value=>value.trim()))];
      await this.onPrepared(next,{accountLabel,...(accountLogins.length?{accountLogins}:{})});
      this.report({ phase: 'ready', progress: 100, error: '' });
      return { available: true, prepared: true, ...this.state };
    } catch (error) {
      // Partial databases/images and enrollment capabilities are retained for recovery.
      delete this.state.media;
      this.report({ phase: 'failed', error: error.message });
      throw error;
    }
  }
}
module.exports = { DashboardPreparation, enrollment, serverOrigin };
