'use strict';
const uuid = value => /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(value || '');
const hash = value => /^[a-f0-9]{64}$/.test(value || '');

/** Native reservations bind delayed original HTTP writes to a terminal server decision. */
class DashboardRemoteAttempts {
  constructor({ state, metadata, credential, request }) {
    this.state = state; this.metadata = metadata; this.credential = credential; this.request = request; this.active = new Set();
  }
  supported(details) {
    const path = new URL(details.url).pathname, method = String(details.method).toUpperCase();
    if(method==='POST'&&(/^\/admin\/takeaway\/(?:checkout|till\/(?:movements|settings))$/.test(path)
      || /^\/admin\/(?:dining|phone-orders)\/tickets\/(?:save|[1-9][0-9]{0,18}\/(?:action|settle))$/.test(path)
      || /^\/admin\/dining\/(?:tables|settings)$/.test(path)
      || /^\/admin\/phone-orders\/(?:dispatch-company|finish-batch)$/.test(path)
      || /^\/admin\/(?:customers|delivery-companies)\/save$/.test(path)
      || /^\/admin\/employees\/(?:save|attendance|attendance-rules|entry|wallet|daily-notes|void-entry|close|pay)$/.test(path)
      || /^\/admin\/branch-stock\/(?:receive|recipes)$/.test(path)
      || /^\/admin\/branch-expenses\/[1-9][0-9]{0,18}\/review$/.test(path)
      || path==='/admin/branch-expenses/categories'
      || path==='/admin/branch-shifts/close'))return true;
    return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)
      && (/^\/admin\/(?:areas|categorys|products)(?:\/[1-9][0-9]{0,18})?$/.test(path)
        || (method === 'DELETE' && /^\/admin\/(?:areas|categorys|products)DeleteAll$/.test(path)));
  }
  validate(receipt, record) {
    if (receipt?.format !== 1 || receipt.id !== record.id || receipt.device_id !== record.deviceId || receipt.actor_id !== record.actorId
        || receipt.method !== record.method || receipt.path !== record.path || !['ready', 'committed', 'cancelled'].includes(receipt.status))
      throw Error('رد نتيجة السيرفر لا يطابق عملية الجهاز؛ العملية محفوظة.');
    return receipt;
  }
  async begin(id, details) {
    this.active.add(id);
    try {
      let credential;
      if (this.supported(details)) credential = await this.credential();
      if (!credential) { await this.state.begin(id); return {}; }
      const record = { format: 1, id, deviceId: credential.deviceId, actorId: credential.actorId, serverOrigin: credential.serverOrigin,
        method: String(details.method).toUpperCase(), path: new URL(details.url).pathname };
      if (!uuid(id) || new URL(details.url).origin !== credential.serverOrigin) throw Error('طلب السيرفر لا يخص ربط الجهاز.');
      // The binding survives a crash before reservation, including a lost reservation reply.
      await this.metadata.write('remote-attempts/' + id, record);
      await this.state.begin(id);
      const receipt = this.validate(await this.request({ action: 'reserve', id, method: record.method, path: record.path }, credential), record);
      if (receipt.status !== 'ready' || !hash(receipt.capability)) throw Error('الطلب السابق اكتملت نتيجته؛ أعد فتح الصفحة قبل عملية جديدة.');
      return { 'X-Fasakhansta-Remote-Attempt': id, 'X-Fasakhansta-Remote-Capability': receipt.capability };
    } catch (error) { this.active.delete(id); throw error; }
  }
  async settle(id) {
    const record = await this.metadata.read('remote-attempts/' + id);
    if (!record) return false; // Earlier versions have no reservation: never infer their outcome.
    const credential = await this.credential();
    if(!credential)throw Error('تعذر استرجاع ربط عملية السيرفر؛ سجلها محفوظ.');
    if (record.format !== 1 || record.id !== id || !uuid(id) || record.deviceId !== credential.deviceId
        || record.actorId !== credential.actorId || record.serverOrigin !== credential.serverOrigin)
      throw Error('عملية السيرفر المحفوظة لا تخص ربط الجهاز الحالي.');
    const receipt = this.validate(await this.request({ action: 'settle', id, method: record.method, path: record.path }, credential), record);
    if (!['committed', 'cancelled'].includes(receipt.status)) throw Error('السيرفر لم يحسم نتيجة العملية بعد.');
    await this.metadata.write('remote-attempts/' + id, { ...record, receipt });
    await this.state.complete(id); // Keep dirty=true until the complete dataset refresh succeeds.
    return true;
  }
  async complete(id) {
    this.active.delete(id);
    if (!(await this.settle(id))) await this.state.complete(id);
  }
  failed(id) { this.active.delete(id); }
  async recover() {
    for (const id of (await this.state.read()).pending) if (!this.active.has(id)) await this.settle(id);
  }
}
module.exports = DashboardRemoteAttempts;
