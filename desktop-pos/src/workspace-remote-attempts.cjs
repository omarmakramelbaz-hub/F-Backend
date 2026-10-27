'use strict';
const crypto = require('node:crypto');
const OriginalRemoteAttempts = require('./dashboard-remote-attempts.cjs');
const getPolicy = require('./dashboard-get-policy.cjs');
const { rootBinding, actorBinding, same } = require('./workspace-mode.cjs');
const { canonical, authorityHeaders } = require('./workspace-protocol.cjs');
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const instant = value => typeof value === 'string' && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString() === value;
const digest = value => crypto.createHash('sha256').update(canonical(value)).digest('hex');
const error = code => Object.assign(Error('لم تتأكد نتيجة عملية السيرفر؛ سجلها الأصلي محفوظ ولا يمكن اعتماد حساب آخر بدلًا منه.'), { code: 'WORKSPACE_REMOTE_' + code });
const need = (value, code) => { if (!value) throw error(code); };
const shared = new WeakMap();
const requestKey = id => 'workspace-remote-attempts/' + id;
const receiptKey = id => 'workspace-remote-receipts/' + id;
const reviewKey = id => 'workspace-remote-reviews/' + id;
const observationKey = id => 'workspace-remote-responses/' + id;
/** Original request evidence is immutable; decisions/observations are separate encrypted receipts. */
class WorkspaceRemoteAttempts {
  constructor({ workspace, actor, state, request, direct, guard }) {
    need(workspace?.metadata && state && typeof state.initialize === 'function' && typeof request === 'function' && typeof direct === 'function' && typeof guard === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, actor, state, metadata: workspace.metadata, request, direct, guard });
    if (!shared.has(workspace)) shared.set(workspace, { active: new Set(), locks: new Map(), beginTail: Promise.resolve() });
    this.shared = shared.get(workspace); this.active = this.shared.active; this.directLocks = this.shared.locks;
  }
  supported(details) { return OriginalRemoteAttempts.prototype.supported.call(this, details); }
  async binding() { const prepared = await this.workspace.connection(), root = rootBinding(prepared); return { prepared, binding: { format: 2, root, actor: await actorBinding(this.actor, root) } }; }
  async begin(id, details) {
    const url = new URL(details.url), method = String(details.method || '').toUpperCase();
    need(uuid(id) && !this.active.has(id), 'REQUEST_ACTIVE');
    need(!(method === 'HEAD' && getPolicy.mutation(url.pathname)), 'HEAD_MUTATION');
    this.active.add(id);
    const task = this.shared.beginTail.then(async () => {
      const current = await this.binding(); need(url.origin === current.binding.root.serverOrigin && !url.username && !url.password, 'REQUEST_FOREIGN');
      await this.state.initialize(); need((await this.state.read()).pending.length === 0, 'PREVIOUS_OUTCOME_UNKNOWN');
      need(await this.guard({ operation: 'remote-write', root: current.binding.root, actor: current.binding.actor }) === true, 'GLOBAL_WORK_UNSETTLED');
      need(await this.metadata.read(requestKey(id)) === null, 'REQUEST_EXISTS');
      const accepted = await this.direct(details), supported = this.supported(details);
      if (accepted) need(same(accepted, current.binding), 'DIRECT_BINDING_CHANGED');
      need(supported || accepted, 'ROUTE_UNSUPPORTED');
      const record = { format: 2, kind: 'desktop-workspace-remote-request', id, type: supported ? 'reserved' : 'direct', binding: current.binding,
        method, path: url.pathname, created_at: new Date().toISOString() };
      await this.metadata.write(requestKey(id), record); await this.state.begin(id);
      const headers = authorityHeaders(current.binding.root, current.binding.actor, current.prepared.token);
      if (!supported) return headers;
      const response = this.validate(await this.request(this.body(record, false), { workspaceId: current.prepared.deviceId,
        serverOrigin: current.prepared.serverOrigin, token: current.prepared.token, enrollmentId: current.binding.root.rootFingerprint }), record);
      need(response.status === 'ready' && hash(response.capability), 'REQUEST_TERMINAL');
      return { ...headers, 'X-Fasakhansta-Workspace-Attempt': id, 'X-Fasakhansta-Workspace-Capability': response.capability };
    });
    this.shared.beginTail = task.catch(() => {});
    try { return await task; } catch (cause) { this.active.delete(id); throw cause; }
  }
  body(record, cancel) {
    return { actor_id: record.binding.actor.actorId, manifest_revision: record.binding.actor.manifestRevision, grant: record.binding.actor.grant,
      attempt: { id: record.id, method: record.method, path: record.path }, cancel };
  }
  validate(value, record) {
    need(value?.format === 2 && value.kind === 'desktop-workspace-remote-attempt' && value.id === record.id
      && value.device_id === record.binding.root.workspaceId && value.actor_id === record.binding.actor.actorId
      && value.method === record.method && value.path === record.path && ['ready', 'committed', 'cancelled'].includes(value.status)
      && value.workspace?.workspace_id === record.binding.root.workspaceId && value.workspace.root_fingerprint === record.binding.root.rootFingerprint
      && value.workspace.manifest_revision === record.binding.actor.manifestRevision
      && (value.status === 'ready' ? hash(value.capability) : value.capability === null), 'RECEIPT_INVALID');
    return value;
  }
  lockDirect(id, work) {
    const previous = this.directLocks.get(id) || Promise.resolve(), task = previous.catch(() => {}).then(work); this.directLocks.set(id, task);
    task.finally(() => { if (this.directLocks.get(id) === task) this.directLocks.delete(id); }).catch(() => {}); return task;
  }
  async validateDirect(record, id) {
    const root = rootBinding(await this.workspace.connection());
    need(record?.format === 2 && record.kind === 'desktop-workspace-remote-request' && record.id === id && uuid(id)
      && ['reserved', 'direct'].includes(record.type) && ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'].includes(record.method)
      && typeof record.path === 'string' && record.path.length > 0 && record.path.length <= 200
      && (getPolicy.admin(record.path) || getPolicy.mutation(record.path)) && instant(record.created_at)
      && Object.keys(record).sort().join(',') === 'binding,created_at,format,id,kind,method,path,type'
      && record.binding?.format === 2 && Object.keys(record.binding).sort().join(',') === 'actor,format,root'
      && ['actorId,grant,manifestRevision,role', 'actorId,grant,manifestRevision,onlineOnly,role'].includes(Object.keys(record.binding.actor || {}).sort().join(','))
      && (record.binding.actor.onlineOnly === undefined || record.binding.actor.onlineOnly === true)
      && same(record.binding.root, root) && Number.isSafeInteger(record.binding.actor?.manifestRevision)
      && (record.binding.actor.onlineOnly === true ? record.binding.actor.manifestRevision >= root.manifestRevision : record.binding.actor.manifestRevision === root.manifestRevision)
      && Number.isSafeInteger(record.binding.actor.actorId) && record.binding.actor.actorId > 0
      && ['admin', 'vendor', 'resturant_owner'].includes(record.binding.actor.role) && hash(record.binding.actor.grant), 'RECORD_INVALID');
    // No current-actor comparison here: root recovery settles A after B login.
    return record;
  }
  async validateObservation(observed, record, id) {
    await this.validateDirect(record, id);
    need(observed?.format === 2 && observed.kind === 'desktop-workspace-remote-observation' && observed.observation === 'response-received'
      && observed.request_id === id && observed.request_sha256 === digest(record) && same(observed.binding, record.binding) && instant(observed.observed_at)
      && Object.keys(observed).sort().join(',') === 'binding,format,kind,observation,observed_at,request_id,request_sha256', 'OBSERVATION_INVALID'); return observed;
  }
  async validateReview(review, record, id) {
    await this.validateDirect(record, id);
    need(review?.format === 2 && review.kind === 'desktop-workspace-remote-review' && review.decision === 'human-reviewed' && review.request_id === id
      && uuid(review.audit_id) && review.actor_id === record.binding.actor.actorId && review.request_sha256 === digest(record)
      && same(review.binding, record.binding) && instant(review.reviewed_at)
      && Object.keys(review).sort().join(',') === 'actor_id,audit_id,binding,decision,format,kind,request_id,request_sha256,reviewed_at', 'REVIEW_INVALID'); return review;
  }
  async settle(id) {
    const record = await this.metadata.read(requestKey(id)); need(record !== null, 'RECORD_MISSING'); await this.validateDirect(record, id);
    return this.lockDirect(id, async () => {
      if (record.type === 'direct') {
        const observed = await this.metadata.read(observationKey(id)), reviewed = await this.metadata.read(reviewKey(id));
        if (observed) await this.validateObservation(observed, record, id); if (reviewed) await this.validateReview(reviewed, record, id);
        need(observed || reviewed, 'DIRECT_OUTCOME_UNKNOWN'); await this.state.complete(id); return true;
      }
      let receipt = await this.metadata.read(receiptKey(id));
      if (receipt) this.validate(receipt, record);
      else {
        const current = await this.workspace.connection();
        receipt = this.validate(await this.request(this.body(record, true), { workspaceId: current.deviceId,
          serverOrigin: current.serverOrigin, token: current.token, enrollmentId: record.binding.root.rootFingerprint }), record);
        need(['committed', 'cancelled'].includes(receipt.status), 'OUTCOME_UNKNOWN'); await this.metadata.write(receiptKey(id), receipt);
      }
      need(['committed', 'cancelled'].includes(receipt.status), 'OUTCOME_UNKNOWN'); await this.state.complete(id); return true;
    });
  }
  async complete(id) {
    this.active.delete(id); const record = await this.metadata.read(requestKey(id)); need(record !== null, 'RECORD_MISSING'); await this.validateDirect(record, id);
    if (record.type === 'reserved') return this.settle(id);
    return this.lockDirect(id, async () => {
      const old = await this.metadata.read(observationKey(id));
      if (old) await this.validateObservation(old, record, id);
      else await this.metadata.write(observationKey(id), { format: 2, kind: 'desktop-workspace-remote-observation', observation: 'response-received',
        request_id: id, request_sha256: digest(record), binding: record.binding, observed_at: new Date().toISOString() });
      await this.state.complete(id); return true;
    });
  }
  failed(id) { this.active.delete(id); }
  async recover() { for (const id of (await this.state.read()).pending) if (!this.active.has(id)) await this.settle(id); }
  async reviewable() {
    const current = await this.binding(), records = [];
    for (const id of (await this.state.read()).pending) {
      const record = await this.metadata.read(requestKey(id)); need(record !== null, 'RECORD_MISSING'); await this.validateDirect(record, id);
      if (record.type !== 'direct' || record.binding.actor.actorId !== current.binding.actor.actorId || this.active.has(id)) continue;
      if (!await this.metadata.read(reviewKey(id)) && !await this.metadata.read(observationKey(id))) records.push(record);
      if (records.length >= 20) break;
    } return records;
  }
  humanReview(id) {
    return this.lockDirect(id, async () => {
      need(!this.active.has(id), 'REQUEST_ACTIVE'); const record = await this.metadata.read(requestKey(id)); await this.validateDirect(record, id);
      const current = await this.binding(); need(record.type === 'direct' && record.binding.actor.actorId === current.binding.actor.actorId, 'REVIEW_OTHER_ACTOR');
      let review = await this.metadata.read(reviewKey(id)); const observed = await this.metadata.read(observationKey(id));
      if (review) await this.validateReview(review, record, id); if (observed) await this.validateObservation(observed, record, id);
      if (!review && !observed) {
        review = { format: 2, kind: 'desktop-workspace-remote-review', audit_id: crypto.randomUUID(), decision: 'human-reviewed', request_id: id,
          request_sha256: digest(record), binding: record.binding, actor_id: record.binding.actor.actorId, reviewed_at: new Date().toISOString() };
        await this.metadata.write(reviewKey(id), review);
      }
      await this.state.complete(id); return review || { observed: true };
    });
  }
}
module.exports = { WorkspaceRemoteAttempts, authorityHeaders, requestKey, receiptKey, reviewKey, observationKey };
