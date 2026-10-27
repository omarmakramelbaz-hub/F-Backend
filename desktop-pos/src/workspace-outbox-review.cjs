'use strict';
const OriginalReview = require('./dashboard-outbox-review.cjs');
/** Group review presents only the actual actor's retryable evidence. Global
 * queue ordering remains visible without exposing another actor's error/body.
 * The temporary original-UI context never changes the physical prepared actor. */
class WorkspaceOutboxReview {
  constructor({ workspace, actor, dialog, synchronize }) { Object.assign(this, { workspace, actor, dialog, synchronize }); }
  open(window) {
    if (this.running) return this.running;
    this.running = this.review(window).finally(() => { this.running = null; }); return this.running;
  }
  async review(window) {
    const root = await this.workspace.connection(), authority = await this.actor.authority();
    if (!await this.actor.authenticated() || authority.workspaceId !== root.deviceId) throw Error('سجّل الدخول بحساب الجهاز قبل مراجعة عملياته.');
    const value = await this.actor.control({ action: 'outbox-review' });
    if (value?.format !== 1 || value.device_id !== root.deviceId || value.actor_id !== authority.actorId || value.snapshot_id !== root.snapshotId
      || !value.counts || !['pending', 'conflicts', 'acknowledged'].every(key => Number.isSafeInteger(value.counts[key]) && value.counts[key] >= 0))
      throw Error('تعذر التحقق من سجل عمليات الجهاز؛ العمليات محفوظة.');
    if (value.first && value.first.actor_id !== authority.actorId) {
      await this.dialog.showMessageBox(window, { type: 'info', title: 'GO ERP — مراجعة المزامنة',
        message: 'أول عملية معلقة تخص حسابًا آخر.', detail: 'يحتفظ الجهاز بترتيب العمليات الأصلي. صاحب العملية يستطيع مراجعتها؛ ستستمر مزامنة بقية الحسابات بعد تأكيدها.', buttons: ['إغلاق'] });
      return { counts: value.counts, retried: false, otherActor: true };
    }
    let first = value;
    const context = { metadata: this.actor.metadata, get manifest() { return this.owner.physical.manifest; }, owner: this.workspace,
      connection: async () => ({ ...await this.workspace.connection(), actorId: authority.actorId }),
      control: async input => { if (input.action === 'outbox-review' && first) { const saved = first; first = null; return saved; }
        return this.actor.control(input); } };
    return new OriginalReview({ runtime: context, dialog: this.dialog, synchronize: this.synchronize }).open(window);
  }
}
module.exports = { WorkspaceOutboxReview };
