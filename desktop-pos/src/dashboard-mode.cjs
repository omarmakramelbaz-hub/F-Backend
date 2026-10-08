'use strict';
const crypto = require('node:crypto');
const identity = value => JSON.stringify([value.deviceId, value.actorId, value.database, value.snapshotId, value.schemaHash, value.serverOrigin]);
const uuid = value => /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value || '');

/** Retain a durable inactive-local write fence while the original window uses the server. */
class DashboardMode {
  constructor({ runtime, generations, view, probe, remoteState, onState = () => {} }) {
    this.runtime = runtime; this.metadata = runtime.metadata; this.generations = generations; this.view = view;
    this.probe = probe; this.remoteState = remoteState; this.onState = onState; this.tail = Promise.resolve(); this.accepted = new Map();
  }
  serialize(name, work) {
    if (this.accepted.has(name)) return this.accepted.get(name);
    const task = this.tail.then(work);
    this.tail = task.catch(() => {});
    this.accepted.set(name, task);
    task.finally(() => this.accepted.delete(name)).catch(() => {});
    return task;
  }
  async hold() {
    const current = await this.runtime.connection();
    let intent = await this.metadata.read('return');
    if (intent) {
      if (intent.format !== 1 || !uuid(intent.id) || !/^[a-f0-9]{64}$/.test(intent.token || '')
          || identity(intent.previous || {}) !== identity(current)) throw Error('حالة الانتقال لا تطابق بيانات الجهاز؛ العمليات محفوظة.');
      const previous = await this.runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
      if (previous.exists && previous.held) return intent;
      if (previous.exists) { await this.metadata.write('return', null); intent = null; }
    }
    if (!intent) {
      intent = { format: 1, id: crypto.randomUUID(), token: crypto.randomBytes(32).toString('hex'), previous: current };
      await this.metadata.write('return', intent);
    }
    const fence = await this.runtime.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token });
    if (!fence.held || fence.device_id !== current.deviceId || fence.actor_id !== current.actorId || fence.schema_hash !== current.schemaHash)
      throw Error('لم يكتمل إيقاف الكتابة في نسخة الجهاز.');
    return intent;
  }
  async cancelHold() {
    const intent = await this.metadata.read('return');
    if (!intent) return;
    const current = await this.runtime.connection();
    if (intent.format !== 1 || !uuid(intent.id) || !/^[a-f0-9]{64}$/.test(intent.token || '')
        || identity(intent.previous || {}) !== identity(current)) throw Error('تعذر استرجاع الانتقال بين الجهاز والسيرفر.');
    const state = await this.runtime.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    if (state.exists && state.held) {
      const cancelled = await this.runtime.control({ action: 'refresh-cancel', refresh_id: intent.id, token: intent.token });
      if (cancelled.held) throw Error('الكتابة في الجهاز لا تزال متوقفة لحين استرجاع الانتقال.');
    }
    // Flushed encrypted null also survives a lost directory-delete flush on Windows.
    await this.metadata.write('return', null);
  }
  recover() { return this.serialize('recover', () => this.cancelHold()); }
  refresh() {
    return this.serialize('refresh', async () => {
      try {
        await this.cancelHold();
        if (this.remoteState) await this.remoteState.snapshot(() => this.generations.run());
        else await this.generations.run();
        await this.hold(); // The same InnoDB lock refuses a new command that raced the refresh.
        const current = await this.runtime.connection();
        const session = await this.probe(current);
        if (session.actor_id !== current.actorId || session.device_id !== current.deviceId)
          throw Error('حساب السيرفر المفتوح لا يطابق حساب الجهاز؛ استمر على النسخة المحلية.');
        if (this.view.current().local) await this.view.switchTo({ origin: current.serverOrigin, localToken: '' });
        this.onState({ mode: 'server', error: '' });
        return current;
      } catch (error) {
        try {
          if (this.view.current().local) await this.cancelHold();
          else await this.hold();
        } catch { throw Error(error.message + ' أعد فتح البرنامج لاسترجاع الانتقال؛ العمليات محفوظة.'); }
        throw error;
      }
    });
  }
  local(failure = {}) {
    return this.serialize('local', async () => {
      if (this.view.current().local) return;
      if (!['GET', 'HEAD'].includes(String(failure.method || 'GET').toUpperCase()))
        throw Error('لم يصل رد آخر عملية من السيرفر؛ يلزم التأكد من نتيجتها قبل الانتقال للعمل المحلي.');
      if (!(await this.runtime.isPrepared())) throw Error('الجهاز لم يُجهّز للعمل بدون إنترنت بعد.');
      await this.remoteState?.assertClean();
      await this.runtime.start();
      await this.view.switchTo({ origin: this.runtime.origin, localToken: this.runtime.token }, () => this.cancelHold());
      this.onState({ mode: 'local', error: '' });
    });
  }
}
module.exports = DashboardMode;
