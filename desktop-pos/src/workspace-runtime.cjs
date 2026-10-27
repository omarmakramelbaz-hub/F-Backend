'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const LocalRuntime = require('./local-runtime.cjs');
const { GenerationStore, generation } = require('./dashboard-generation.cjs');
const { manifest: memberManifest, canonical, digest } = require('./workspace-profile.cjs');
const prepared = require('./workspace-prepared.cjs');
const source = require('./dashboard-source.cjs');
const media = require('./dashboard-media.cjs');
const readSnapshot = require('./dashboard-download.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const failure = code => Object.assign(Error('The shared workspace runtime could not be verified.'), { code: 'WORKSPACE_RUNTIME_' + code });
const need = (value, code) => { if (!value) throw failure(code); };
const check = (owner, signal) => need(!owner.stopping && !signal?.aborted, 'CANCELLED');
const same = (left, right) => canonical(left) === canonical(right);

/** One prepared pointer: all physical-runtime reads derive from Registry.active. */
class WorkspaceMetadata {
  constructor(registry, storage) { this.registry = registry; this.storage = storage; }
  async read(name) { return name === 'prepared' ? (await this.registry.current())?.candidate.prepared || null : this.storage.read(name); }
  async write(name, value) { need(name !== 'prepared', 'POINTER_WRITE_FORBIDDEN'); return this.storage.write(name, value); }
  async clearIntent() { return this.storage.clearIntent(); }
}
class WorkspacePhysicalRuntime extends LocalRuntime {
  nativeEnvironment(settings, storage) {
    return { ...super.nativeEnvironment(settings, storage), DESKTOP_DASHBOARD_WORKSPACE_LOCAL: 'true', DESKTOP_DASHBOARD_WORKSPACE_ENABLED: 'true' };
  }
}
/**
 * Feature-disabled protocol-2 supervisor. It owns ONE MariaDB/PHP pair, archive,
 * generation and DB. Actor facades own only cookie/signout state and cannot stop
 * physical children. authorizeSource/verifyActive are mandatory native hooks;
 * callbacks and private records must never be exposed through renderer IPC.
 */
class WorkspaceRuntime {
  constructor({ registry, bundle, safeStorage, downloadMedia, authorizeSource, verifyActive, authorizeInstalled,
    platform = process.platform, onFailure, onPhase, nativeRun = LocalRuntime.runNative }) {
    need(registry && typeof registry.current === 'function' && typeof registry.directory === 'function'
      && typeof authorizeSource === 'function' && typeof verifyActive === 'function' && typeof nativeRun === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { registry, safeStorage, authorizeSource, verifyActive, nativeRun });
    const root = path.join(registry.workspaceProfile, 'dashboard');
    this.metadata = new WorkspaceMetadata(registry, new GenerationStore(root, safeStorage));
    this.physical = new WorkspacePhysicalRuntime({ bundle, profile: registry.workspaceProfile, safeStorage, downloadMedia,
      authorizeInstalled, platform, onFailure, onPhase, expectedDeviceId: registry.workspaceId, metadata: this.metadata });
    this.stopping = false; this.actorFacades = new Map();
  }
  async directories() { await this.registry.directory(this.registry.workspaceProfile, true); await this.registry.directory(this.physical.profile, true); }
  async settings() { await this.directories(); return this.physical.settings(); }
  async isPrepared() { const active = await this.registry.current(); return active !== null && this.physical.isPrepared(); }
  async connection() { const active = await this.registry.current(); need(active, 'NOT_PREPARED'); return active.candidate.prepared; }
  start() {
    if (this.starting) return this.starting;
    this.starting = (async () => {
      check(this); await this.settings(); const active = await this.registry.current();
      if (active) { need(await this.verifyActive(active) === true, 'ACTIVE_REJECTED'); check(this); }
      return this.physical.start();
    })();
    return this.starting;
  }
  async actor(actorId) {
    const descriptor = await this.registry.actorSession(actorId), active = await this.registry.current();
    need(active && active.candidate.database === descriptor.database, 'STATE_CHANGED');
    let facade = this.actorFacades.get(actorId);
    if (!facade) {
      const owner = this, metadata = new GenerationStore(descriptor.profile, this.safeStorage);
      facade = Object.freeze({ protocol: 2, metadata, workspaceMetadata: this.metadata,
        start: () => owner.start(), isPrepared: () => owner.isPrepared(), connection: () => owner.connection(),
        authority: async () => {
          const member = await owner.registry.member(actorId), current = await owner.registry.current();
          need(member && current, 'ACTOR_DENIED');
          return { protocol: 2, workspaceId: owner.registry.workspaceId, actorId, role: member.role,
            manifestRevision: current.manifest.revision, grant: member.grant, workspace: prepared.workspaceBinding(current.manifest) };
        },
        session: () => owner.registry.actorSession(actorId) });
      this.actorFacades.set(actorId, facade);
    }
    return { descriptor, runtime: facade };
  }
  static importInput(snapshot, binding) {
    const header = canonical({ format: 2, kind: 'desktop-workspace-import-input', expected_binding: binding });
    const body = readSnapshot.input(snapshot);
    need(Buffer.byteLength(header) <= 65536 && Buffer.byteLength(body) <= 256 * 1024 ** 2, 'INPUT_TOO_LARGE');
    return header + '\n' + body;
  }
  async stage(snapshot, id, enrollment, { signal, onMediaProgress } = {}) {
    check(this, signal); need(generation(id) && this.physical.environment, 'NOT_STARTED');
    const m = memberManifest(enrollment.manifest, this.registry.workspaceId), credential = enrollment.credential;
    const binding = prepared.expectedBinding(credential, m), root = snapshot.workspace_enrollment;
    need(snapshot.format === 2 && snapshot.kind === 'initial-workspace-data' && snapshot.device_id === this.registry.workspaceId
      && same(snapshot.workspace_manifest, m) && root?.format === 2 && root.workspace_id === this.registry.workspaceId
      && root.custodian_actor_id === snapshot.actor_id && /^[a-f0-9]{64}$/.test(root.enrollment_hash || '')
      && root.token_hash === binding.token_hash
      && digest({ format: 2, kind: 'desktop-dashboard-workspace-root', workspace_id: root.workspace_id,
        custodian_actor_id: root.custodian_actor_id, enrollment_hash: root.enrollment_hash, token_hash: root.token_hash, enrolled_at: root.enrolled_at }) === binding.root_fingerprint, 'SNAPSHOT_REJECTED');
    media.manifest(snapshot.media);
    const runtime = this.physical, context = await runtime.candidate(snapshot); check(this, signal);
    const approved = await this.authorizeSource({ snapshot, context, credential, manifest: m }, { signal }); check(this, signal);
    need(Number.isSafeInteger(approved?.sequence) && approved.sequence > 0 && approved.sourceRevision === context.manifest.sourceRevision
      && source.same(approved.sourceFingerprint, context.sourceFingerprint) && source.same(snapshot.source, context.sourceFingerprint), 'SOURCE_REJECTED');
    const database = 'fasakhansta_dashboard_stage_' + id, value = { generation: id, database };
    const env = LocalRuntime.contextEnvironment(context, runtime.environmentFor(value)), options = { env, cwd: context.application, ...(signal ? { signal } : {}) };
    const stageRoot = path.join(runtime.profile, 'generations', id);
    await this.registry.directory(path.dirname(stageRoot), true); await fs.mkdir(stageRoot, { mode: 0o700 });
    for (const directory of ['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'bootstrap/cache', 'private'])
      await this.registry.directory(path.join(runtime.storageFor(value), directory), true);
    const settings = await this.settings(), args = LocalRuntime.phpArguments(context);
    check(this, signal);
    await this.nativeRun(context.php, [...args, path.join(context.application, 'desktop/database.php'), 'stage'], options,
      JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }));
    check(this, signal);
    const receipt = JSON.parse(await this.nativeRun(context.php, [...args, '-d', 'memory_limit=-1', path.join(context.application, 'desktop/workspace-import.php')], options,
      WorkspaceRuntime.importInput(snapshot, binding), 300000));
    check(this, signal);
    const images = await media.download(path.join(runtime.storageFor(value), 'app/public'), snapshot.media, runtime.downloadMedia,
      () => this.stopping || signal?.aborted, path.join(runtime.storageFor(value), 'app'), undefined, { onProgress: onMediaProgress });
    const checked = JSON.parse(await this.nativeRun(context.php, [...args, path.join(context.application, 'desktop/workspace-verify.php')], options,
      JSON.stringify({ receipt, expected_binding: binding }), 90000));
    check(this, signal);
    const candidate = { workspaceId: this.registry.workspaceId, database, generation: id, snapshotId: snapshot.snapshot_id, schemaHash: snapshot.schema_hash,
      sourceRevision: context.manifest.sourceRevision, sourceFingerprint: checked.source, sourceSequence: approved.sequence,
      manifestRevision: m.revision, rosterSha256: m.roster_sha256 };
    prepared.receipt(checked, m, candidate); media.verifyReceipt(checked.media, images.files);
    need(same(checked.coverage, snapshot.coverage) && same(checked.branches, snapshot.branches) && checked.actor_id === snapshot.actor_id
      && source.same(checked.source, snapshot.source) && images.verified === true, 'IMPORT_REJECTED');
    runtime.stagedContexts.set(id, context);
    return { ...candidate, receipt: checked, mediaVerified: true, sourceManifest: context.manifest };
  }
  async verifyCandidate(record, { signal } = {}) {
    check(this, signal);
    const c = record.candidate, context = this.physical.stagedContexts.get(c.generation);
    need(context && context.manifest.sourceRevision === c.sourceRevision, 'STAGE_MISSING');
    const archive = new RuntimeArchive(this.physical.profile, this.metadata, { stopped: () => this.stopping || signal?.aborted });
    await archive.verify(context.bundle, await this.metadata.read('runtimes/' + c.sourceRevision));
    need(source.same(await archive.sourceFingerprint(context.bundle), c.sourceFingerprint), 'SOURCE_REJECTED');
    check(this, signal);
    const result = JSON.parse(await this.nativeRun(context.php, [...LocalRuntime.phpArguments(context), path.join(context.application, 'desktop/workspace-verify.php')],
      { env: LocalRuntime.contextEnvironment(context, this.physical.environmentFor(c.prepared)), cwd: context.application, ...(signal ? { signal } : {}) },
      JSON.stringify({ receipt: c.receipt, expected_binding: prepared.expectedBinding(record.credential, record.manifest) }), 90000));
    check(this, signal); prepared.validate(c.prepared, result, record.manifest, record.credential, c);
    need(same(result, c.receipt), 'RECEIPT_CHANGED'); return true;
  }
  async stop() { this.stopping = true; this.physical.cancelStartup(); return this.physical.stop(); }
}
module.exports = { WorkspaceRuntime, WorkspaceMetadata };
