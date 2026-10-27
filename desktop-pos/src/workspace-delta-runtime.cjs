'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const LocalRuntime = require('./local-runtime.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const download = require('./dashboard-download.cjs');
const source = require('./dashboard-source.cjs');
const media = require('./dashboard-media.cjs');
const policy = require('./workspace-policy.cjs');
const prepared = require('./workspace-prepared.cjs');
const { canonical, digest, record: checkedRecord } = require('./workspace-profile.cjs');
const { generation, database } = require('./dashboard-generation.cjs');
const fail = code => Object.assign(Error('تعذر تحديث مساحة العمل؛ بيانات وعمليات كل الحسابات محفوظة.'), { code: 'WORKSPACE_DELTA_RUNTIME_' + code });
const need = (value, code) => { if (!value) throw fail(code); };
const same = (a, b) => canonical(a) === canonical(b);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const script = (context, name) => {
  policy.programs(context.manifest);
  need(['workspace-delta.php', 'workspace-verify.php', 'workspace-archive.php', 'database.php'].includes(name)
    && hash(context.manifest.sourceHashes?.['desktop/' + name]), 'PROGRAM_UNVERIFIED');
  return path.join(context.application, 'desktop', name);
};

/** Typed composition adapter. No protocol-one delta script ever sees the shared database. */
class WorkspaceDeltaRuntime {
  constructor({ workspace, verifySource }) {
    need(workspace?.registry && workspace.physical && typeof workspace.nativeRun === 'function'
      && typeof workspace.authorizeSource === 'function', 'CONFIGURATION_INVALID');
    this.workspace = workspace; this.physical = workspace.physical; this.metadata = workspace.metadata;
    need(verifySource === undefined || typeof verifySource === 'function', 'CONFIGURATION_INVALID');
    this.verifySource = verifySource || (async context => {
      const archive = new RuntimeArchive(this.physical.profile, this.metadata, { stopped: () => this.stopped });
      await archive.verify(context.bundle, await this.metadata.read('runtimes/' + context.manifest.sourceRevision));
      need(source.same(await archive.sourceFingerprint(context.bundle), context.sourceFingerprint), 'SOURCE_CHANGED'); return true;
    });
    this.captures = new Map(); this.stages = new Map(); this.controller = new AbortController(); this.stopped = false;
  }
  check() { need(!this.stopped && !this.workspace.stopping && !this.controller.signal.aborted, 'CANCELLED'); }
  stop() { this.stopped = true; this.controller.abort(); }
  static input(action, value, delta) {
    const header = canonical({ format: 2, kind: 'desktop-workspace-delta-input', action, ...value });
    need(Buffer.byteLength(header) <= 128 * 1024 ** 2, 'INPUT_TOO_LARGE');
    if (action !== 'reconstruct') { need(delta === undefined, 'FRAME_INVALID'); return header + '\n'; }
    const raw = download.input(delta); need(Buffer.byteLength(raw) <= 256 * 1024 ** 2, 'INPUT_TOO_LARGE');
    return header + '\n' + raw;
  }
  async context(previous) {
    this.check(); checkedRecord(previous, this.workspace.registry.workspaceId);
    const context = await this.physical.deltaContext(previous.candidate.prepared);
    policy.programs(context.manifest); script(context, 'workspace-archive.php');
    const approved = await this.authorize(context, previous); this.check();
    return { ...context, workspaceApproval: approved };
  }
  async authorize(context, record) {
    this.check();
    need(await this.verifySource(context) === true, 'SOURCE_CHANGED'); this.check();
    const value = await this.workspace.authorizeSource({ snapshot: { source: context.sourceFingerprint }, context,
      credential: record.credential, manifest: record.manifest }, { signal: this.controller.signal });
    need(Number.isSafeInteger(value?.sequence) && value.sequence >= record.candidate.sourceSequence
      && value.sourceRevision === context.manifest.sourceRevision && source.same(value.sourceFingerprint, context.sourceFingerprint), 'PUBLICATION_REJECTED');
    return value;
  }
  async describe(context, id, sourceDatabase) {
    need(generation(id) && database(sourceDatabase), 'DATABASE_INVALID');
    const value = { generation: id, database: 'fasakhansta_dashboard_stage_' + id };
    const env = { ...LocalRuntime.contextEnvironment(context, this.physical.environmentFor(value)),
      DESKTOP_DASHBOARD_WORKSPACE_LOCAL: 'true', DESKTOP_DASHBOARD_WORKSPACE_ENABLED: 'true',
      DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE: sourceDatabase };
    return { context, value, root: path.join(this.physical.profile, 'generations', id), args: LocalRuntime.phpArguments(context),
      options: { env, cwd: context.application, signal: this.controller.signal } };
  }
  async create(context, id, sourceDatabase) {
    this.check(); const stage = await this.describe(context, id, sourceDatabase);
    await this.workspace.registry.directory(path.dirname(stage.root), true); await fs.mkdir(stage.root, { mode: 0o700 });
    for (const directory of ['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'bootstrap/cache', 'private'])
      await this.workspace.registry.directory(path.join(this.physical.storageFor(stage.value), directory), true);
    const settings = await this.workspace.settings(); this.check();
    await this.workspace.nativeRun(context.php, [...stage.args, script(context, 'database.php'), 'stage'], stage.options,
      JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }));
    this.check(); return stage;
  }
  async capture(context, previous, id, token) {
    this.check(); need(hash(token), 'TOKEN_INVALID');
    const stage = await this.create(context, id, previous.candidate.database), p = previous.candidate.prepared;
    const input = WorkspaceDeltaRuntime.input('capture', { source_database: p.database, device_id: p.deviceId, actor_id: p.actorId,
      snapshot_id: p.snapshotId, schema_hash: p.schemaHash, source: p.sourceFingerprint, capture_token: token,
      expected_binding: prepared.expectedBinding(previous.credential, previous.manifest) });
    const retained = { ...stage, token, previous, capture: null }; this.captures.set(id, retained);
    const capture = JSON.parse(await this.workspace.nativeRun(context.php,
      [...stage.args, '-d', 'memory_limit=-1', script(context, 'workspace-delta.php')], stage.options, input, 300000, 128 * 1024 ** 2));
    this.check(); need(capture?.format === 2 && capture.kind === 'desktop-workspace-delta-capture'
      && capture.capture_database === stage.value.database && capture.source_database === p.database, 'CAPTURE_REJECTED');
    retained.capture = capture; return capture;
  }
  async stage(context, previous, capture, delta, manifest, id, token) {
    this.check(); const retained = this.captures.get(capture.capture_database.slice(-16));
    need(retained && retained.token === token && same(retained.capture, capture) && same(retained.previous, previous), 'CAPTURE_FOREIGN');
    media.manifest(delta.media);
    const stage = await this.create(context, id, previous.candidate.database), expected = prepared.expectedBinding(previous.credential, manifest);
    const candidate = { generation: id, database: stage.value.database };
    const keep = { ...stage, token, previous, capture, candidate, manifest, finalized: false }; this.stages.set(id, keep);
    const receipt = JSON.parse(await this.workspace.nativeRun(context.php,
      [...stage.args, '-d', 'memory_limit=-1', script(context, 'workspace-delta.php')], stage.options,
      WorkspaceDeltaRuntime.input('reconstruct', { capture_database: capture.capture_database, capture_token: token, capture, expected_binding: expected }, delta), 300000));
    keep.receipt = receipt; this.check();
    const activeStorage = this.physical.storageFor(previous.candidate.prepared);
    const images = await media.download(path.join(this.physical.storageFor(stage.value), 'app/public'), delta.media, this.physical.downloadMedia,
      () => this.stopped || this.workspace.stopping, path.join(this.physical.storageFor(stage.value), 'app'),
      { publicRoot: path.join(activeStorage, 'app/public'), privateRoot: path.join(activeStorage, 'app'), cacheRoot: path.join(this.physical.profile, 'media-cache') });
    this.check();
    const result = JSON.parse(await this.workspace.nativeRun(context.php,
      [...stage.args, script(context, 'workspace-verify.php')], stage.options,
      JSON.stringify({ action: 'preverify', receipt, expected_binding: expected, handoff_token: token }), 300000));
    const { media_seal: mediaSeal, archive_handoff: archiveHandoff, ...checked } = result;
    Object.assign(candidate, { workspaceId: previous.manifest.workspace_id, snapshotId: delta.snapshot_id, schemaHash: delta.schema_hash,
      sourceRevision: context.manifest.sourceRevision, sourceFingerprint: checked.source, sourceSequence: context.workspaceApproval.sequence,
      manifestRevision: manifest.revision, rosterSha256: manifest.roster_sha256, receipt: checked, mediaVerified: images.verified,
      mediaSeal, archiveHandoff });
    this.validateCandidate(candidate, previous, capture, manifest);
    media.verifyReceipt(checked.media, images.files); this.check();
    keep.receipt = checked; this.physical.stagedContexts.set(id, context); return candidate;
  }
  validateCandidate(candidate, previous, capture, manifest) {
    prepared.receipt(candidate.receipt, manifest, candidate);
    need(prepared.deltaReceipt(candidate.receipt, manifest, candidate, previous.credential) !== null, 'DELTA_RECEIPT_REQUIRED');
    need(candidate.mediaVerified === true && candidate.mediaSeal && candidate.archiveHandoff?.format === 2
      && candidate.archiveHandoff.kind === 'desktop-workspace-preverified-handoff' && hash(candidate.archiveHandoff.signature)
      && candidate.archiveHandoff.payload?.database === candidate.database
      && candidate.archiveHandoff.payload.source_database === previous.candidate.database
      && candidate.archiveHandoff.payload.sequence === capture.sequence
      && same(candidate.archiveHandoff.payload.replay, capture.replay)
      && same(candidate.receipt.delta_replay, capture.replay)
      && candidate.receipt.delta_stage.capture_database === capture.capture_database
      && candidate.receipt.delta_stage.capture_id === capture.capture_id
      && candidate.receipt.actor_id === previous.candidate.prepared.actorId
      && same(candidate.receipt.delta_source_identity, { snapshot_id: previous.candidate.snapshotId,
        schema_hash: previous.candidate.schemaHash, workspace: prepared.workspaceBinding(previous.manifest) })
      && same(candidate.receipt.delta_source_binding, prepared.expectedBinding(previous.credential, previous.manifest)), 'STAGE_REJECTED');
  }
  async restore(checkpoint) {
    this.check(); const previous = checkedRecord(checkpoint.previous, this.workspace.registry.workspaceId);
    const expected = checkpoint.context; let context;
    if (this.physical.manifest?.sourceRevision === expected.sourceRevision && source.same(this.physical.sourceFingerprint, expected.sourceFingerprint)) context = this.physical.context();
    else {
      need(/^[a-f0-9]{40}$/.test(expected.sourceRevision), 'CONTEXT_INVALID');
      const archive = new RuntimeArchive(this.physical.profile, this.metadata, { stopped: () => this.stopped });
      const bundle = path.join(this.physical.profile, 'runtimes', expected.sourceRevision), retained = await this.metadata.read('runtimes/' + expected.sourceRevision);
      await archive.verify(bundle, retained); const manifest = await archive.manifest(bundle), fingerprint = await archive.sourceFingerprint(bundle);
      need(manifest.sourceRevision === expected.sourceRevision && source.same(fingerprint, expected.sourceFingerprint), 'SOURCE_CHANGED');
      context = { bundle, manifest, sourceFingerprint: fingerprint, application: path.join(bundle, 'application'), php: path.join(bundle, 'php', 'php.exe'),
        phpIni: await LocalRuntime.phpIniFor(bundle, manifest, this.physical.profile) };
    }
    policy.programs(context.manifest); script(context, 'workspace-archive.php');
    need(await this.verifySource(context) === true, 'SOURCE_CHANGED'); this.check();
    context = { ...context, workspaceApproval: { sequence: expected.sourceSequence, sourceRevision: expected.sourceRevision, sourceFingerprint: expected.sourceFingerprint } };
    const captureStage = await this.describe(context, checkpoint.captureGeneration, previous.candidate.database);
    this.captures.set(checkpoint.captureGeneration, { ...captureStage, previous, token: checkpoint.token, capture: checkpoint.capture || null });
    if (checkpoint.candidateGeneration) {
      const stage = await this.describe(context, checkpoint.candidateGeneration, previous.candidate.database);
      const candidate = checkpoint.candidate || { generation: checkpoint.candidateGeneration, database: stage.value.database };
      this.stages.set(checkpoint.candidateGeneration, { ...stage, candidate, previous, capture: checkpoint.capture,
        token: checkpoint.token, receipt: candidate.receipt || null, manifest: checkpoint.record?.manifest, finalized: false });
      if (checkpoint.record) {
        checkedRecord(checkpoint.record, this.workspace.registry.workspaceId);
        this.validateCandidate(candidate, previous, checkpoint.capture, checkpoint.record.manifest);
        this.physical.stagedContexts.set(checkpoint.candidateGeneration, context);
        await this.workspace.registry.directory(stage.root, false);
      }
    }
    return context;
  }
  async finalize(candidate, intent, record) {
    const stage = this.stages.get(candidate.generation);
    need(!this.workspace.stopping && stage && same(stage.candidate, candidate) && same(record.candidate.receipt, candidate.receipt), 'STAGE_MISSING');
    // Once the durable fence is accepted, cancellation cannot interrupt its settlement.
    const result = JSON.parse(await this.workspace.nativeRun(stage.context.php,
      [...stage.args, script(stage.context, 'workspace-archive.php')], { env: stage.options.env, cwd: stage.options.cwd },
      JSON.stringify({ receipt: candidate.receipt, expected_binding: prepared.expectedBinding(record.credential, record.manifest),
        source: intent.previous.candidate.database, refresh_id: intent.id, token: intent.token,
        source_identity: candidate.receipt.delta_source_identity, media_seal: candidate.mediaSeal,
        archive_handoff: candidate.archiveHandoff, handoff_token: stage.token }), 90000));
    need(result && Object.keys(result).join(',') === 'archived_commands'
      && result.archived_commands === candidate.receipt.delta_replay.rows, 'ARCHIVE_REJECTED');
    stage.finalized = digest(record); return result;
  }
  async activate(record, ticket) {
    const stage = this.stages.get(record.candidate.generation);
    need(stage && stage.finalized === digest(record), 'HANDOFF_NOT_VERIFIED');
    await this.physical.activate(record.candidate.prepared, () => this.workspace.registry.activate(ticket,
      { verifyCandidate: async value => this.stages.get(record.candidate.generation) === stage && !stage.released
        && stage.finalized === digest(value) && same(value, record) }));
    this.workspace.sessions?.clear(); return this.workspace.registry.current();
  }
  async exists(stage) {
    const settings = await this.workspace.settings();
    const value = JSON.parse(await this.workspace.nativeRun(stage.context.php,
      [...stage.args, script(stage.context, 'database.php'), 'stage-status'], stage.options,
      JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }), 15000));
    need(value?.format === 1 && value.database === stage.value.database && typeof value.exists === 'boolean', 'DATABASE_STATUS_INVALID'); return value.exists;
  }
  async release(checkpoint) {
    return this.workspace.registry.serialize(() => this.releaseLocked(checkpoint));
  }
  async releaseLocked(checkpoint) {
    this.check(); const active = await this.workspace.registry.current(); need(active, 'ACTIVE_MISSING');
    for (const [kind, id] of [['stage', checkpoint.candidateGeneration], ['capture', checkpoint.captureGeneration]]) {
      if (!id) continue;
      const entry = kind === 'stage' ? this.stages.get(id) : this.captures.get(id);
      need(entry && entry.token === checkpoint.token, 'CLEANUP_UNVERIFIED');
      if (entry.value.database === active.candidate.database) {
        // The accepted DB remains. Its large temporary capture/index objects
        // no longer belong in the native supervisor after the pointer commits.
        entry.released = true; entry.finalized = false;
        if (kind === 'stage') this.stages.delete(id); else this.captures.delete(id);
        continue;
      }
      entry.released = true; entry.finalized = false;
      this.check();
      const input = kind === 'stage' ? { stage_database: entry.value.database, source_database: checkpoint.previous.candidate.database,
        active_database: active.candidate.database, capture_database: 'fasakhansta_dashboard_stage_' + checkpoint.captureGeneration,
        capture_token: checkpoint.token, ...(entry.receipt ? { receipt: entry.receipt } : {}) }
        : { capture_database: entry.value.database, source_database: checkpoint.previous.candidate.database,
          active_database: active.candidate.database, capture_token: checkpoint.token, ...(entry.capture ? { capture: entry.capture } : {}) };
      const exists = await this.exists(entry);
      need(same(await this.workspace.registry.current(), active), 'ACTIVE_CHANGED');
      if (exists) {
        const result = JSON.parse(await this.workspace.nativeRun(entry.context.php,
          [...entry.args, script(entry.context, 'workspace-delta.php')], { ...entry.options,
            env: { ...entry.options.env, DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE: active.candidate.database } },
          WorkspaceDeltaRuntime.input('discard-' + kind, input), 90000));
        need(result?.discarded === true && result.database === entry.value.database, 'CLEANUP_REJECTED');
      }
      this.check(); need((await this.workspace.registry.current()).candidate.database !== entry.value.database, 'ACTIVE_CHANGED');
      try { await this.workspace.registry.directory(entry.root, false); await fs.rm(entry.root, { recursive: true, force: false }); }
      catch (error) { if (error.code !== 'ENOENT') throw error; }
      if (kind === 'stage') { this.stages.delete(id); this.physical.stagedContexts.delete(id); } else this.captures.delete(id);
    }
    return true;
  }
}
module.exports = { WorkspaceDeltaRuntime, fail };
