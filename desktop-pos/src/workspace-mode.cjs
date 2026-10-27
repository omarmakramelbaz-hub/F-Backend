'use strict';
const crypto = require('node:crypto');
const source = require('./dashboard-source.cjs');
const { canonical } = require('./workspace-profile.cjs');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const positive = value => Number.isSafeInteger(value) && value > 0;
const same = (left, right) => canonical(left) === canonical(right);
const error = code => Object.assign(Error('تعذر تأكيد الانتقال بين نسخة الجهاز والسيرفر؛ العمليات محفوظة.'), { code: 'WORKSPACE_MODE_' + code });
const need = (value, code) => { if (!value) throw error(code); };
function rootBinding(value) {
  let origin; try { origin = new URL(value?.serverOrigin); } catch { throw error('ROOT_INVALID'); }
  const workspace = value.workspace;
  need(value.workspaceProtocol === 2 && value.format === 2 && uuid(value.deviceId) && positive(value.actorId)
    && hash(value.token) && origin.protocol === 'https:' && origin.origin === value.serverOrigin && !origin.username && !origin.password
    && /^[a-f0-9]{40}$/.test(value.sourceRevision || '') && source.valid(value.sourceFingerprint)
    && uuid(value.snapshotId) && uuid(value.refreshId) && hash(value.schemaHash) && /^[a-f0-9]{16}$/.test(value.generation || '')
    && value.database === 'fasakhansta_dashboard_stage_' + value.generation && workspace?.workspace_id === value.deviceId
    && hash(workspace.root_fingerprint) && positive(workspace.manifest_revision) && hash(workspace.roster_sha256) && hash(workspace.manifest_sha256), 'ROOT_INVALID');
  return { format: 2, workspaceId: value.deviceId, custodianActorId: value.actorId, serverOrigin: value.serverOrigin,
    tokenHash: crypto.createHash('sha256').update(value.token).digest('hex'), rootFingerprint: workspace.root_fingerprint,
    manifestRevision: workspace.manifest_revision, rosterSha256: workspace.roster_sha256, manifestSha256: workspace.manifest_sha256,
    sourceRevision: value.sourceRevision, sourceFingerprint: structuredClone(value.sourceFingerprint), snapshotId: value.snapshotId,
    refreshId: value.refreshId, schemaHash: value.schemaHash, database: value.database, generation: value.generation };
}
async function actorBinding(actor, root) {
  need(typeof actor?.authenticated === 'function' && await actor.authenticated() === true, 'ACTOR_UNAUTHENTICATED');
  const authority = await actor.authority();
  const onlineOnly = actor.onlineOnly === true;
  need(authority?.protocol === 2 && authority.workspaceId === root.workspaceId && positive(authority.actorId)
    && ['admin', 'vendor', 'resturant_owner'].includes(authority.role)
    && positive(authority.manifestRevision) && (onlineOnly ? authority.manifestRevision >= root.manifestRevision : authority.manifestRevision === root.manifestRevision)
    && hash(authority.grant) && authority.workspace?.workspace_id === root.workspaceId
    && authority.workspace.root_fingerprint === root.rootFingerprint && authority.workspace.manifest_revision === authority.manifestRevision
    && hash(authority.workspace.roster_sha256) && hash(authority.workspace.manifest_sha256)
    && (onlineOnly || authority.workspace.roster_sha256 === root.rosterSha256 && authority.workspace.manifest_sha256 === root.manifestSha256), 'ACTOR_INVALID');
  return { actorId: authority.actorId, role: authority.role, manifestRevision: authority.manifestRevision, grant: authority.grant, ...(onlineOnly ? { onlineOnly: true } : {}) };
}
function serverSession(session, root, actor) {
  need(session?.protocol === 2 && session.workspace_id === root.workspaceId && session.custodian_actor_id === root.custodianActorId
    && session.actor_id === actor.actorId && session.role === actor.role && session.manifest_revision === actor.manifestRevision
    && session.root_fingerprint === root.rootFingerprint, 'SERVER_ACTOR_MISMATCH');
  return session;
}
const queues = new WeakMap();
const RETURN_KEY = 'workspace-return';
/** Shared physical fence owner. Effective actor checks are separate from custody. */
class WorkspaceMode {
  constructor({ workspace, actor, generations, view, probe, remoteState, remoteAttempts, guard, verifyReturn, onState = () => {} }) {
    need(workspace && typeof workspace.connection === 'function' && typeof workspace.control === 'function'
      && workspace.metadata && typeof guard === 'function' && typeof probe === 'function' && typeof verifyReturn === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, actor, generations, view, probe, remoteState, remoteAttempts, guard, verifyReturn, onState });
    this.metadata = workspace.metadata; this.jobs = new Map(); this.instance = crypto.randomUUID();
    if (!queues.has(workspace)) queues.set(workspace, { tail: Promise.resolve(), fenceTail: Promise.resolve(), accepted: new Map() });
    this.queue = queues.get(workspace); this.accepted = this.queue.accepted;
  }
  serialize(name, work) {
    if (this.jobs.has(name)) return this.jobs.get(name);
    const key = this.instance + ':' + name, task = this.queue.tail.then(work);
    this.queue.tail = task.catch(() => {}); this.jobs.set(name, task); this.accepted.set(key, task);
    task.finally(() => { this.jobs.delete(name); this.accepted.delete(key); }).catch(() => {});
    return task;
  }
  async binding() { const current = await this.workspace.connection(), root = rootBinding(current); return { current, root, actor: await actorBinding(this.actor, root) }; }
  async requireLocalPassword() {
    need(this.actor.onlineOnly !== true || typeof this.actor.offlineAuthenticated === 'function' && await this.actor.offlineAuthenticated() === true, 'LOCAL_PASSWORD_REQUIRED');
  }
  async guarded(operation, details) { need(await this.guard({ operation, ...details }) === true, 'GLOBAL_WORK_UNSETTLED'); }
  fenceSerialize(work) {
    const task = this.queue.fenceTail.then(work); this.queue.fenceTail = task.catch(() => {}); return task;
  }
  hold() { return this.fenceSerialize(() => this.holdLocked()); }
  async holdLocked() {
    const current = await this.workspace.connection(), root = rootBinding(current);
    let intent = await this.metadata.read(RETURN_KEY);
    if (intent) {
      this.intent(intent); need(same(intent.root, root), 'FENCE_FOREIGN');
      const state = await this.workspace.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
      this.status(state);
      if (state.exists && state.held) { this.fence(state, current, intent.id); return intent; }
      if (state.exists) { await this.metadata.write(RETURN_KEY, null); intent = null; }
    }
    if (!intent) {
      intent = { format: 2, kind: 'desktop-workspace-return', id: crypto.randomUUID(), token: crypto.randomBytes(32).toString('hex'), root };
      await this.metadata.write(RETURN_KEY, intent);
    }
    const fence = await this.workspace.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token });
    this.fence(fence, current, intent.id); return intent;
  }
  intent(value) {
    need(value?.format === 2 && value.kind === 'desktop-workspace-return' && uuid(value.id) && hash(value.token)
      && Object.keys(value).sort().join(',') === 'format,id,kind,root,token' && value.root?.format === 2, 'FENCE_INVALID');
  }
  status(value) { need(typeof value?.exists === 'boolean' && typeof value.held === 'boolean' && (value.exists || !value.held), 'FENCE_INVALID'); }
  fence(value, current, id) {
    need(value?.held === true && value.device_id === current.deviceId && value.actor_id === current.actorId
      && value.snapshot_id === current.snapshotId && value.schema_hash === current.schemaHash && value.refresh_id === id, 'FENCE_INVALID');
  }
  cancelHold() { return this.fenceSerialize(() => this.cancelHoldLocked()); }
  async cancelHoldLocked() {
    const intent = await this.metadata.read(RETURN_KEY); if (!intent) return;
    this.intent(intent); const current = await this.workspace.connection(), actual = rootBinding(current);
    if (!same(intent.root, actual)) {
      need(await this.verifyReturn({ previousRootBinding: intent.root, currentRootBinding: actual, currentPrepared: current }) === true, 'FENCE_FOREIGN');
      // The old held generation is archived evidence. Its old capability must
      // never be sent against the replacement DB to guess a cancellation.
      await this.metadata.write(RETURN_KEY, null); return;
    }
    const state = await this.workspace.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token }); this.status(state);
    if (state.exists && state.held) {
      this.fence(state, current, intent.id);
      const cancelled = await this.workspace.control({ action: 'refresh-cancel', refresh_id: intent.id, token: intent.token });
      need(cancelled?.held === false, 'FENCE_STILL_HELD');
    }
    await this.metadata.write(RETURN_KEY, null);
  }
  recover() { return this.serialize('recover', async () => { await this.remoteState?.assertClean(); await this.guarded('recover', { root: rootBinding(await this.workspace.connection()) }); await this.cancelHold(); }); }
  refresh({ stayLocal = false } = {}) {
    return this.serialize(stayLocal ? 'local-refresh' : 'refresh', async () => {
      try {
        if (stayLocal) await this.requireLocalPassword();
        if (stayLocal && this.view.canRefresh && !(await this.view.canRefresh())) return { deferred: true };
        const before = await this.binding(); await this.remoteAttempts?.recover();
        await this.guarded('refresh', before);
        const update = async () => { await this.cancelHold(); return this.remoteState ? this.remoteState.snapshot(() => this.generations.run()) : this.generations.run(); };
        if (stayLocal) {
          const local = await this.workspace.start();
          const result = this.view.current().local ? await this.view.refresh(update) : await this.view.switchTo({ origin: local.origin, localToken: local.token }, update);
          if (result?.deferred) return result;
          this.onState({ mode: 'local', error: '' }); return this.workspace.connection();
        }
        await update(); await this.hold();
        const fresh = await this.binding();
        serverSession(await this.probe(fresh.current, fresh.actor), fresh.root, fresh.actor);
        if (this.view.current().local) await this.view.switchTo({ origin: fresh.root.serverOrigin, localToken: '' });
        this.onState({ mode: 'server', error: '' }); return fresh.current;
      } catch (cause) {
        try {
          if (this.view.current().local) {
            await this.remoteState?.assertClean(); await this.guarded('refresh-failure', await this.binding()); await this.cancelHold();
          } else await this.hold();
        }
        catch { throw error('RECOVERY_REQUIRED'); }
        throw cause;
      }
    });
  }
  local(failure = {}) {
    return this.serialize('local', async () => {
      await this.requireLocalPassword(); if (this.view.current().local) return;
      need(['GET', 'HEAD'].includes(String(failure.method || 'GET').toUpperCase()), 'WRITE_OUTCOME_UNKNOWN');
      need(await this.workspace.isPrepared() === true, 'NOT_PREPARED');
      const bound = await this.binding(); await this.remoteState?.assertClean(); await this.guarded('local', bound);
      const local = await this.workspace.start();
      await this.view.switchTo({ origin: local.origin, localToken: local.token }, async () => {
        // Recheck under the settled-page handoff so a different actor's late
        // uncertain reply cannot be hidden by an earlier clean read.
        await this.remoteState?.assertClean(); await this.guarded('local-handoff', await this.binding()); await this.cancelHold();
      });
      this.onState({ mode: 'local', error: '' });
    });
  }
}
module.exports = { WorkspaceMode, rootBinding, actorBinding, serverSession, same, RETURN_KEY };
