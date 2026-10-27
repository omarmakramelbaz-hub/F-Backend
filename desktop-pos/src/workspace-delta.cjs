'use strict';
const crypto = require('node:crypto');
const source = require('./dashboard-source.cjs');
const coverage = require('./dashboard-coverage.cjs');
const media = require('./dashboard-media.cjs');
const prepared = require('./workspace-prepared.cjs');
const { WorkspaceDeltaRuntime } = require('./workspace-delta-runtime.cjs');
const { unchanged } = require('./workspace-delta-unchanged.cjs');
const { manifest: checkManifest, record: checkRecord, canonical, digest } = require('./workspace-profile.cjs');
const { generation } = require('./dashboard-generation.cjs');
const KEY = 'workspace-delta';
const INTENT = 'workspace-refresh';
const UNION_INTENT = 'workspace-union-intent';
const SAFE_ERROR = 'تعذر تحديث بيانات الجهاز الآن؛ بيانات وعمليات كل الحسابات محفوظة. ستُستكمل المحاولة من النقطة الآمنة.';
const fail = code => Object.assign(Error(SAFE_ERROR), { code: 'WORKSPACE_DELTA_' + code });
const need = (value, code) => { if (!value) throw fail(code); };
const same = (a, b) => canonical(a) === canonical(b);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const count = value => Number.isSafeInteger(value) && value >= 0;
const contextIdentity = context => ({ sourceRevision: context.manifest.sourceRevision, sourceFingerprint: context.sourceFingerprint,
  sourceSequence: context.workspaceApproval.sequence });

/** One physical data refresh, independent of the account currently viewing the shared DB. */
class WorkspaceDelta {
  constructor({ workspace, view, download, manifest, freshManifest, remoteState, handoff, guard, onActivated = async () => {},
    onState = () => {}, allowPreview = false, adapter }) {
    need(workspace?.registry && workspace.metadata && typeof download === 'function' && typeof (manifest || freshManifest) === 'function'
      && typeof handoff === 'function' && typeof guard === 'function' && typeof view?.current === 'function'
      && typeof view?.refresh === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, registry: workspace.registry, metadata: workspace.metadata, view, download,
      manifest: manifest || freshManifest, remoteState, handoff, guard, onActivated, onState });
    this.adapter = adapter || new WorkspaceDeltaRuntime({ workspace }); this.allowPreview = allowPreview === true;
    this.state = { phase: 'idle', deferred: false, error: '', restartRequired: false }; this.running = null; this.stopped = false;
  }
  stop() { this.stopped = true; this.adapter.stop(); }
  check() { need(!this.stopped, 'CANCELLED'); }
  report(value) { Object.assign(this.state, value); this.onState({ ...this.state }); }
  run() { return this.schedule({ managedHandoff: false, allowCentral: false, remoteSnapshot: false }); }
  /** WorkspaceMode already owns the settled view/outbox/remote snapshot handoff. */
  refresh() { return this.schedule({ managedHandoff: true, allowCentral: true, remoteSnapshot: true }); }
  schedule(options) {
    if (this.running) return this.running;
    if (this.stopped) return Promise.resolve({ deferred: true });
    this.running = this.update(options).finally(() => { this.running = null; }); return this.running;
  }
  async readCheckpoint() {
    const value = await this.metadata.read(KEY); if (value === null) return null;
    need(value && Object.keys(value).sort().join(',') === 'candidate,candidateGeneration,capture,captureGeneration,context,format,kind,previous,record,ticket,token'
      && value.format === 2 && value.kind === 'desktop-workspace-delta-checkpoint' && hash(value.token)
      && generation(value.captureGeneration) && (value.candidateGeneration === null || generation(value.candidateGeneration))
      && value.candidateGeneration !== value.captureGeneration && value.context
      && /^[a-f0-9]{40}$/.test(value.context.sourceRevision || '') && source.valid(value.context.sourceFingerprint)
      && Number.isSafeInteger(value.context.sourceSequence) && value.context.sourceSequence > 0, 'CHECKPOINT_INVALID');
    checkRecord(value.previous, this.registry.workspaceId);
    need(value.context.sourceSequence >= value.previous.candidate.sourceSequence, 'SOURCE_ROLLBACK');
    if (value.capture !== null) {
      this.capture(value.capture, value.previous, value.context);
      need(value.capture.capture_database === 'fasakhansta_dashboard_stage_' + value.captureGeneration, 'CAPTURE_FOREIGN');
    }
    if (value.record !== null) {
      checkRecord(value.record, this.registry.workspaceId);
      need(value.capture && value.candidate && value.candidateGeneration === value.record.candidate.generation
        && value.candidate.generation === value.candidateGeneration && value.candidate.database === value.record.candidate.database
        && same(value.candidate.receipt, value.record.candidate.receipt)
        && value.record.credential.enrollmentId === value.previous.credential.enrollmentId
        && same(value.record.credential, value.previous.credential)
        && value.record.candidate.prepared.actorId === value.previous.candidate.prepared.actorId
        && value.record.candidate.sourceRevision === value.context.sourceRevision
        && value.record.candidate.sourceSequence === value.context.sourceSequence
        && source.same(value.record.candidate.sourceFingerprint, value.context.sourceFingerprint), 'CHECKPOINT_FOREIGN');
    }
    return value;
  }
  capture(value, previous, context) {
    const p = previous.candidate.prepared, identity = value?.identity, index = value?.index, replay = value?.replay;
    need(value?.format === 2 && value.kind === 'desktop-workspace-delta-capture' && hash(value.capture_id)
      && /^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(value.capture_database || '') && value.capture_database !== p.database
      && value.source_database === p.database && count(value.sequence) && identity?.device_id === p.deviceId
      && identity.actor_id === p.actorId && identity.snapshot_id === p.snapshotId && identity.schema_hash === p.schemaHash
      && same(identity.branches, p.branches) && source.same(identity.source, p.sourceFingerprint)
      && same(identity.workspace, prepared.workspaceBinding(previous.manifest))
      && index?.format === 2 && index.kind === 'desktop-workspace-delta-index' && index.device_id === p.deviceId && index.actor_id === p.actorId
      && same(index.workspace, { workspace_id: p.deviceId, root_fingerprint: previous.manifest.root_fingerprint,
        revision: previous.manifest.revision, roster_sha256: previous.manifest.roster_sha256 }) && source.same(index.source, context.sourceFingerprint)
      && index.tables && typeof index.tables === 'object' && !Array.isArray(index.tables)
      && replay?.format === 2 && replay.workspace_id === p.deviceId && replay.root_fingerprint === previous.manifest.root_fingerprint
      && replay.source_database === p.database && replay.sequence === value.sequence && count(replay.rows) && hash(replay.sha256), 'CAPTURE_REJECTED');
  }
  async safe(options, operation, previous) {
    const pending = await this.workspace.control({ action: 'pending' });
    need(pending && Array.isArray(pending.commands) && count(pending.counts?.pending) && count(pending.counts?.conflicts), 'GLOBAL_QUEUE_UNKNOWN');
    if (pending.commands.length || pending.counts.pending || pending.counts.conflicts) return false;
    if (this.remoteState) {
      if (options.remoteSnapshot) {
        const value = await this.remoteState.read();
        need(this.remoteState.blocked === true && Array.isArray(value.pending) && value.pending.length === 0, 'REMOTE_UNCERTAIN');
      } else await this.remoteState.assertClean();
    }
    return await this.guard({ operation, record: previous, root: prepared.workspaceBinding(previous.manifest) }) === true;
  }
  async current(previous) { return same(await this.registry.current(), previous); }
  async save(value) { await this.metadata.write(KEY, value); return value; }
  async intent() {
    const value = await this.metadata.read(INTENT); if (value === null) return null;
    need(value && Object.keys(value).sort().join(',') === 'format,generation,id,kind,previous,record_sha256,ticket,token'
      && value.format === 2 && value.kind === 'desktop-workspace-refresh' && uuid(value.id) && hash(value.token)
      && generation(value.generation) && hash(value.record_sha256), 'INTENT_INVALID');
    checkRecord(value.previous, this.registry.workspaceId); return value;
  }
  async stagedTicket(cp) {
    const state = await this.registry.read(true);
    need(state.staged && same(state.staged.record, cp.record), 'REGISTRY_STAGE_CHANGED');
    const ticket = this.registry.ticket(state);
    if (cp.ticket !== null) need(ticket.stageId === cp.ticket.stageId && ticket.digest === cp.ticket.digest, 'REGISTRY_STAGE_CHANGED');
    return ticket;
  }
  recover() {
    if (this.recovering) return this.recovering;
    this.recovering = this.recoverCheckpoint().finally(() => { this.recovering = null; }); return this.recovering;
  }
  async recoverCheckpoint() {
    const cp = await this.readCheckpoint(), intent = await this.intent();
    const union = await this.metadata.read(UNION_INTENT);
    if (union !== null) {
      need(intent === null, 'RECOVERY_UNBOUND');
      await this.recoverUnion(union, cp);
    }
    if (!intent) {
      if (cp?.record && same(await this.registry.current(), cp.record)) {
        await this.transition(cp); await this.adapter.restore(cp); await this.adapter.release(cp); await this.metadata.write(KEY, null);
        if (!this.stopped) await this.onActivated({ record: cp.record, recovered: true }); return cp.record.candidate.prepared;
      }
      if (cp && !cp.record) { await this.adapter.restore(cp); await this.adapter.release(cp); await this.metadata.write(KEY, null); }
      return;
    }
    need(cp?.record && cp.candidate && intent.record_sha256 === digest(cp.record)
      && intent.id === cp.record.candidate.prepared.refreshId && cp.ticket !== null && same(intent.ticket, cp.ticket)
      && intent.generation === cp.record.candidate.generation && same(intent.previous, cp.previous), 'RECOVERY_UNBOUND');
    const active = await this.registry.current();
    if (same(active, cp.record)) {
      await this.transition(cp);
      await this.metadata.write(INTENT, null); await this.adapter.restore(cp); await this.adapter.release(cp); await this.metadata.write(KEY, null);
      if (!this.stopped) await this.onActivated({ record: active, recovered: true }); return active.candidate.prepared;
    }
    need(same(active, cp.previous), 'RECOVERY_POINTER_CHANGED');
    const status = await this.workspace.control({ action: 'refresh-status', refresh_id: intent.id, token: intent.token });
    need(typeof status?.exists === 'boolean' && typeof status.held === 'boolean', 'RECOVERY_FENCE_UNKNOWN');
    if (!status.exists || !status.held) {
      if (status.exists) {
        const state = await this.registry.read(true);
        need(state.staged && same(state.staged.record, cp.record), 'REGISTRY_STAGE_CHANGED');
        await this.registry.rollback(this.registry.ticket(state));
        // A cancelled refresh UUID cannot be reacquired. Preserve the exact
        // preverified DB and renew only its next acceptance attempt, online.
        cp.record.candidate.prepared.refreshId = crypto.randomUUID(); cp.ticket = null; await this.save(cp);
      }
      await this.metadata.write(INTENT, null); return;
    }
    this.fence(status, cp, intent);
    // This is settlement of already accepted work: use exactly the staged
    // ticket and private preverification. No HTTP or rebuild runs after a crash.
    await this.adapter.restore(cp); const ticket = await this.stagedTicket(cp);
    return this.commit(cp, intent, ticket, true);
  }
  fence(value, cp, intent) {
    this.fenceIdentity(value, cp, intent);
    need(value.fenced_sequence === cp.capture.sequence, 'FENCE_CHANGED');
  }
  fenceIdentity(value, cp, intent) {
    const p = cp.previous.candidate.prepared;
    need(value?.held === true && value.device_id === p.deviceId && value.actor_id === p.actorId
      && value.snapshot_id === p.snapshotId && value.schema_hash === p.schemaHash && value.refresh_id === intent.id
      && count(value.fenced_sequence), 'FENCE_CHANGED');
  }
  unionIntent(value, cp) {
    need(value && Object.keys(value).sort().join(',') === 'audit,format,id,kind,previous,token'
      && value.format === 2 && value.kind === 'desktop-workspace-union-intent' && uuid(value.id) && hash(value.token), 'UNION_INTENT_INVALID');
    checkRecord(value.previous, this.registry.workspaceId);
    const audit = value.audit;
    need(cp?.capture && cp.record === null && cp.candidate === null && cp.candidateGeneration === null
      && same(cp.previous, value.previous) && audit?.format === 2 && audit.kind === 'desktop-workspace-union-check'
      && Object.keys(audit).sort().join(',') === 'captureSha256,deltaSha256,format,id,indexSha256,kind,manifestSha256,recordSha256,sequence,serverSnapshotId,sourceFingerprint,sourceRevision,sourceSequence'
      && audit.id === value.id && audit.recordSha256 === digest(cp.previous) && audit.manifestSha256 === digest(cp.previous.manifest)
      && audit.indexSha256 === digest(cp.capture.index) && audit.captureSha256 === digest(cp.capture) && hash(audit.deltaSha256)
      && audit.sequence === cp.capture.sequence && uuid(audit.serverSnapshotId)
      && audit.sourceRevision === cp.context.sourceRevision && audit.sourceSequence === cp.context.sourceSequence
      && source.same(audit.sourceFingerprint, cp.context.sourceFingerprint), 'UNION_INTENT_UNBOUND');
    return value;
  }
  async cancelUnion(value, cp) {
    need(await this.current(value.previous), 'RECOVERY_POINTER_CHANGED');
    const status = await this.workspace.control({ action: 'refresh-status', refresh_id: value.id, token: value.token });
    need(typeof status?.exists === 'boolean' && typeof status.held === 'boolean', 'RECOVERY_FENCE_UNKNOWN');
    if (status.held) {
      need(status.exists, 'RECOVERY_FENCE_UNKNOWN'); this.fenceIdentity(status, cp, value);
      const cancelled = await this.workspace.control({ action: 'refresh-cancel', refresh_id: value.id, token: value.token });
      need(cancelled?.held === false && cancelled.refresh_id === value.id, 'FENCE_UNCERTAIN');
      const settled = await this.workspace.control({ action: 'refresh-status', refresh_id: value.id, token: value.token });
      need(settled?.exists === true && settled.held === false, 'FENCE_UNCERTAIN');
    }
  }
  async recoverUnion(raw, cp) {
    const value = this.unionIntent(raw, cp);
    await this.cancelUnion(value, cp); await this.metadata.write(UNION_INTENT, null);
    // An unchanged check never owns a candidate or advances the Registry. Its
    // only accepted work is releasing its short-lived source read fence.
  }
  async unchanged(cp, delta, context, options) {
    const approved = await this.adapter.authorize(context, cp.previous);
    need(same(approved, context.workspaceApproval), 'PUBLICATION_CHANGED');
    const cutover = async () => {
      this.check(); need(await this.current(cp.previous), 'POINTER_CHANGED');
      if (!(await this.safe(options, 'unchanged-cutover', cp.previous))) return this.deferred();
      const id = crypto.randomUUID(), audit = { format: 2, kind: 'desktop-workspace-union-check', id,
        recordSha256: digest(cp.previous), manifestSha256: digest(cp.previous.manifest), indexSha256: digest(cp.capture.index),
        deltaSha256: digest(delta), captureSha256: digest(cp.capture), sequence: cp.capture.sequence,
        sourceRevision: cp.context.sourceRevision, sourceFingerprint: cp.context.sourceFingerprint,
        sourceSequence: cp.context.sourceSequence, serverSnapshotId: delta.snapshot_id };
      const intent = { format: 2, kind: 'desktop-workspace-union-intent', id, token: crypto.randomBytes(32).toString('hex'),
        previous: cp.previous, audit };
      this.unionIntent(intent, cp); await this.metadata.write(UNION_INTENT, intent);
      let fence;
      try { fence = await this.workspace.control({ action: 'refresh-begin', refresh_id: id, token: intent.token }); }
      catch (error) {
        if (error.status !== 409) throw error;
        await this.recoverUnion(intent, cp); return this.deferred();
      }
      try { this.fence(fence, cp, intent); }
      catch {
        // A new acknowledged command invalidates this captured union. Its
        // own fence may still be cancelled, but no certificate is issued.
        need(fence?.held === true && fence.refresh_id === id, 'FENCE_UNCERTAIN');
        const cancelled = await this.workspace.control({ action: 'refresh-cancel', refresh_id: id, token: intent.token });
        need(cancelled?.held === false, 'FENCE_UNCERTAIN'); await this.metadata.write(UNION_INTENT, null);
        await this.discard(cp); return this.deferred();
      }
      const key = 'workspace-union-checks/' + id;
      need(await this.current(cp.previous), 'POINTER_CHANGED');
      need(await this.metadata.read(key) === null, 'UNION_AUDIT_CHANGED'); await this.metadata.write(key, audit);
      await this.cancelUnion(intent, cp); await this.metadata.write(UNION_INTENT, null);
      await this.adapter.release(cp); await this.metadata.write(KEY, null);
      need(await this.current(cp.previous), 'POINTER_CHANGED');
      return { unchanged: true, verifiedUnion: audit };
    };
    const result = options.managedHandoff ? await cutover() : await this.handoff(() => this.view.refresh(cutover));
    if (result?.deferred) return this.deferred();
    this.report({ phase: 'ready', deferred: false, error: '' }); return result;
  }
  async transition(cp) {
    const next = cp.record.candidate.prepared, value = await this.metadata.read('workspace-generations/' + next.refreshId);
    need(value?.format === 2 && value.kind === 'desktop-workspace-generation-transition'
      && same(value.previous, cp.previous.candidate.prepared) && same(value.next, next)
      && value.recordSha256 === digest(cp.record) && value.receiptSha256 === digest(cp.candidate.receipt)
      && value.refreshId === next.refreshId && value.fence?.refresh_id === next.refreshId
      && value.fence.fenced_sequence === cp.capture.sequence, 'TRANSITION_CHANGED'); return value;
  }
  async commit(cp, intent, ticket, recovered = false) {
    await this.adapter.finalize(cp.candidate, intent, cp.record);
    const previous = cp.previous.candidate.prepared, next = cp.record.candidate.prepared;
    const audit = { format: 2, kind: 'desktop-workspace-generation-transition', previous, next,
      recordSha256: digest(cp.record), receiptSha256: digest(cp.candidate.receipt), refreshId: intent.id,
      fence: { format: 2, device_id: previous.deviceId, custodian_actor_id: previous.actorId,
        snapshot_id: previous.snapshotId, schema_hash: previous.schemaHash, refresh_id: intent.id,
        fenced_sequence: cp.capture.sequence, token_sha256: crypto.createHash('sha256').update(intent.token).digest('hex') } };
    const name = 'workspace-generations/' + next.refreshId, existing = await this.metadata.read(name);
    need(existing === null || same(existing, audit), 'TRANSITION_CHANGED');
    if (existing === null) await this.metadata.write(name, audit);
    await this.adapter.activate(cp.record, ticket);
    need(same(await this.registry.current(), cp.record), 'POINTER_UNCERTAIN');
    await this.metadata.write(INTENT, null);
    await this.adapter.release(cp); await this.metadata.write(KEY, null);
    if (!this.stopped) await this.onActivated({ record: cp.record, recovered });
    return cp.record.candidate.prepared;
  }
  async discard(cp) {
    const state = await this.registry.read(true);
    if (state.staged) {
      need(cp.record && same(state.staged.record, cp.record), 'REGISTRY_STAGE_CHANGED');
      await this.registry.rollback(this.registry.ticket(state));
    }
    await this.adapter.restore(cp); await this.adapter.release(cp); await this.metadata.write(KEY, null);
  }
  async update(options) {
    let cp;
    try {
      const recovered = await this.recover(); if (recovered) return recovered;
      this.check();
      if (!options.allowCentral && !this.view.current().local) return this.deferred();
      const previous = await this.registry.current(); need(previous, 'NOT_PREPARED');
      if (!(await this.safe(options, 'capture', previous))) return this.deferred();
      this.check(); cp = await this.readCheckpoint();
      if (cp?.record) {
        need(await this.current(cp.previous), 'POINTER_CHANGED');
        const manifest = checkManifest(await this.manifest(previous.credential, { signal: this.adapter.controller?.signal }), this.registry.workspaceId);
        if (!same(manifest, cp.record.manifest)) { await this.discard(cp); cp = null; }
        else {
          const context = await this.adapter.restore(cp), approved = await this.adapter.authorize(context, previous);
          need(approved.sequence === cp.context.sourceSequence && approved.sourceRevision === cp.context.sourceRevision
            && source.same(approved.sourceFingerprint, cp.context.sourceFingerprint), 'PUBLICATION_CHANGED');
          cp.ticket = await this.registry.stage(cp.record, { signal: this.adapter.controller?.signal }); await this.save(cp);
        }
      }
      if (!cp) {
        this.report({ phase: 'checking', deferred: false, error: '' });
        const context = await this.adapter.context(previous); this.check();
        need(context?.manifest && /^[a-f0-9]{40}$/.test(context.manifest.sourceRevision || '') && source.valid(context.sourceFingerprint)
          && Number.isSafeInteger(context.workspaceApproval?.sequence) && context.workspaceApproval.sequence >= previous.candidate.sourceSequence,
        'SOURCE_ROLLBACK');
        cp = await this.save({ format: 2, kind: 'desktop-workspace-delta-checkpoint', previous, token: crypto.randomBytes(32).toString('hex'),
          context: contextIdentity(context), captureGeneration: crypto.randomBytes(8).toString('hex'), candidateGeneration: null,
          capture: null, candidate: null, record: null, ticket: null });
        this.report({ phase: 'capturing' });
        cp.capture = await this.adapter.capture(context, previous, cp.captureGeneration, cp.token);
        this.capture(cp.capture, previous, cp.context); await this.save(cp); this.check();
        this.report({ phase: 'downloading' });
        const delta = await this.download(cp.capture.index, previous, { signal: this.adapter.controller?.signal }); this.check();
        const manifest = checkManifest(await this.manifest(previous.credential, { signal: this.adapter.controller?.signal }), this.registry.workspaceId);
        need(delta?.format === 2 && delta.kind === 'workspace-data-delta' && delta.device_id === previous.manifest.workspace_id
          && delta.actor_id === previous.candidate.prepared.actorId && uuid(delta.snapshot_id) && hash(delta.schema_hash)
          && source.same(delta.source, context.sourceFingerprint) && same(delta.workspace_manifest, manifest)
          && manifest.root_fingerprint === previous.manifest.root_fingerprint && manifest.revision >= previous.manifest.revision
          && (manifest.revision !== previous.manifest.revision || same(manifest, previous.manifest))
          && delta.workspace_enrollment?.custodian_actor_id === previous.candidate.prepared.actorId
          && delta.workspace_enrollment.token_hash === prepared.expectedBinding(previous.credential, manifest).token_hash, 'DELTA_REJECTED');
        media.manifest(delta.media); coverage.snapshotCoverage(delta, context.manifest, this.allowPreview);
        need(await this.current(previous), 'POINTER_CHANGED');
        if (unchanged(cp.capture, delta, previous, context)) return await this.unchanged(cp, delta, context, options);
        cp.candidateGeneration = crypto.randomBytes(8).toString('hex'); await this.save(cp);
        this.report({ phase: 'staging' });
        cp.candidate = await this.adapter.stage(context, previous, cp.capture, delta, manifest, cp.candidateGeneration, cp.token);
        const c = cp.candidate;
        cp.record = { credential: previous.credential, manifest, candidate: { workspaceId: this.registry.workspaceId,
          database: c.database, generation: c.generation, snapshotId: c.snapshotId, schemaHash: c.schemaHash,
          sourceRevision: c.sourceRevision, sourceFingerprint: c.sourceFingerprint, sourceSequence: c.sourceSequence,
          manifestRevision: manifest.revision, rosterSha256: manifest.roster_sha256, receipt: c.receipt } };
        cp.record.candidate.prepared = prepared.create({ credential: previous.credential, manifest,
          candidate: c, refreshId: crypto.randomUUID(), sourceManifest: context.manifest, allowPreview: this.allowPreview });
        checkRecord(cp.record, this.registry.workspaceId); await this.save(cp); this.check();
        const approved = await this.adapter.authorize(context, previous);
        need(same(approved, context.workspaceApproval), 'PUBLICATION_CHANGED');
        cp.ticket = await this.registry.stage(cp.record, { signal: this.adapter.controller?.signal }); await this.save(cp);
      }
      this.check(); this.report({ phase: 'waiting', deferred: false, error: '' });
      const cutover = async () => {
        this.check(); need(await this.current(cp.previous), 'POINTER_CHANGED');
        if (!(await this.safe(options, 'cutover', cp.previous))) return this.deferred();
        const intent = { format: 2, kind: 'desktop-workspace-refresh', id: cp.record.candidate.prepared.refreshId,
          token: crypto.randomBytes(32).toString('hex'), generation: cp.candidateGeneration, previous: cp.previous,
          record_sha256: digest(cp.record), ticket: cp.ticket };
        await this.metadata.write(INTENT, intent);
        let fence;
        try { fence = await this.workspace.control({ action: 'refresh-begin', refresh_id: intent.id, token: intent.token }); }
        catch (error) {
          if (error.status !== 409) throw error;
          await this.recover(); return this.deferred();
        }
        try { this.fence(fence, cp, intent); }
        catch (error) {
          const cancelled = await this.workspace.control({ action: 'refresh-cancel', refresh_id: intent.id, token: intent.token });
          need(cancelled?.held === false, 'FENCE_UNCERTAIN'); await this.metadata.write(INTENT, null); await this.discard(cp); cp = null;
          return this.deferred();
        }
        this.report({ phase: 'activating' }); return this.commit(cp, intent, cp.ticket);
      };
      const result = options.managedHandoff ? await cutover() : await this.handoff(() => this.view.refresh(cutover));
      if (result?.deferred) return this.deferred();
      this.report({ phase: 'ready', deferred: false, error: '' }); return result;
    } catch (error) {
      // The exact accepted intent is settled first. If settlement cannot be
      // proven, retain its capability and prevent a second generation/rebuild.
      if ((await this.metadata.read(INTENT)) || (await this.metadata.read(UNION_INTENT))) {
        try { const value = await this.recover(); if (value) return value; }
        catch { this.report({ phase: 'failed', deferred: false, error: SAFE_ERROR, restartRequired: true }); throw fail('RECOVERY_REQUIRED'); }
      } else if (!this.stopped) {
        try { const pending = await this.readCheckpoint(); if (pending && !pending.record) await this.discard(pending); }
        catch { this.report({ phase: 'failed', deferred: false, error: SAFE_ERROR, restartRequired: true }); throw fail('RECOVERY_REQUIRED'); }
      }
      this.report({ phase: 'failed', deferred: false, error: SAFE_ERROR }); throw error?.code?.startsWith('WORKSPACE_') ? error : fail('FAILED');
    }
  }
  deferred() { this.report({ phase: 'deferred', deferred: true, error: '' }); return { deferred: true }; }
}
module.exports = { WorkspaceDelta, KEY, INTENT, UNION_INTENT, SAFE_ERROR };
