'use strict';

/** A response from the server does not update the inactive local database. */
class DashboardRemoteState {
  constructor(metadata) { this.metadata = metadata; this.tail = Promise.resolve(); this.blocked = false; }
  serialize(work) {
    const task = this.tail.then(work); this.tail = task.catch(() => {}); return task;
  }
  async read() {
    const value = await this.metadata.read('remote-writes');
    if (!value) return { format: 1, dirty: false, pending: [] };
    if (value.format !== 1 || typeof value.dirty !== 'boolean' || !Array.isArray(value.pending)
        || value.pending.some(id => typeof id !== 'string')) throw Error('تعذر التحقق من آخر عمليات السيرفر؛ بيانات الجهاز محفوظة.');
    return value;
  }
  begin(id) {
    return this.serialize(async () => {
      if (this.blocked) throw Error('يجري تحديث بيانات الجهاز؛ أعد إرسال العملية بعد اكتماله.');
      const value = await this.read();
      value.dirty = true;
      if (!value.pending.includes(String(id))) value.pending.push(String(id));
      await this.metadata.write('remote-writes', value);
    });
  }
  browse(){
    return this.serialize(async()=>{
      if(this.blocked)throw Error('يجري تحديث بيانات الجهاز؛ انتظر اكتماله.');
      const value=await this.read();
      // Every central read waits for this durable fence before transmission.
      // Once dirty, more navigation/polling cannot make the local copy dirtier;
      // keep validating the record but do not queue identical encrypted fsyncs.
      // A successful snapshot clears the fence and requires the next write again.
      if(value.dirty)return;
      value.dirty=true;await this.metadata.write('remote-writes',value);
    });
  }
  complete(id) {
    return this.serialize(async () => {
      const value = await this.read();
      value.pending = value.pending.filter(item => item !== String(id));
      await this.metadata.write('remote-writes', value);
    });
  }
  assertClean() {
    return this.serialize(async () => {
      const value = await this.read();
      if (value.pending.length) throw Error('لم تتأكد نتيجة عملية أُرسلت للسيرفر؛ يلزم مراجعتها قبل العمل المحلي.');
      if (value.dirty) throw Error('آخر عمليات السيرفر لم تُحدّث في الجهاز بعد؛ يلزم اكتمال تحديث البيانات قبل العمل المحلي.');
    });
  }
  async snapshot(work) {
    // Flip the gate before draining accepted writes. Their records must be flushed before export.
    if (this.blocked) throw Error('يجري تحديث بيانات الجهاز.');
    this.blocked = true;
    try {
      await this.serialize(async () => {
        if ((await this.read()).pending.length) throw Error('نتيجة آخر عملية على السيرفر غير مؤكدة؛ تعذر تحديث نسخة الجهاز.');
      });
      const result = await work();
      await this.serialize(() => this.metadata.write('remote-writes', { format: 1, dirty: false, pending: [] }));
      return result;
    } finally { this.blocked = false; }
  }
}
module.exports = DashboardRemoteState;
