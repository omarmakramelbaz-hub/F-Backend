'use strict';
const fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const policy = require('./workspace-policy.cjs');
const { GenerationStore } = require('./dashboard-generation.cjs');
const { WorkspaceRegistry } = require('./workspace-profile.cjs');
const { WorkspaceRuntime } = require('./workspace-runtime.cjs');
const { WorkspaceLogin } = require('./workspace-login.cjs');
const { WorkspacePreparation } = require('./workspace-preparation.cjs');
const { WorkspaceMigrationInventory } = require('./workspace-migration.cjs');
const { WorkspaceHTTP } = require('./workspace-http.cjs');
const { WorkspaceSourceAuthority, groupUpdateTransport } = require('./workspace-source.cjs');
const { DashboardUpdater } = require('./dashboard-updater.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const { WorkspaceOnlineLogin } = require('./workspace-online-login.cjs');
const { WorkspaceRemoteState } = require('./workspace-remote-state.cjs');
const { WorkspaceRemoteAttempts } = require('./workspace-remote-attempts.cjs');
const { WorkspaceMode, rootBinding, same } = require('./workspace-mode.cjs');
const { WorkspaceConnected } = require('./workspace-connected.cjs');
const { WorkspaceLiveSocial } = require('./workspace-live-social.cjs');
const { WorkspaceDelta } = require('./workspace-delta.cjs');
const { WorkspaceSync } = require('./workspace-sync.cjs');
const { WorkspaceGuard } = require('./workspace-guard.cjs');
const { WorkspaceOutboxReview } = require('./workspace-outbox-review.cjs');
const ConnectedReview = require('./dashboard-connected-review.cjs');
const synchronizePrintOutcomes = require('./dashboard-print-sync.cjs');
const { digest } = require('./workspace-profile.cjs');
const failure = () => Object.assign(Error('تعذر تأكيد إعداد مساحة الجهاز؛ البيانات الحالية محفوظة.'), { code: 'WORKSPACE_NATIVE_CONFIGURATION_INVALID' });
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
/** Native main-process factory. No feature preference or renderer IPC enables it. */
async function createWorkspaceNative(options) {
  const proof = await policy.verifyInstalled(options?.bundle); if (!proof) return null;
  const value = new WorkspaceNative({ ...options, policyProof: proof }); await value.initialize(); return value;
}
class WorkspaceNative {
  constructor(options) {
    const required = ['createSession', 'transport', 'serverSession', 'inspectLegacyProfile', 'transition', 'relaunch', 'ownRuntime', 'stopped', 'onLocked'];
    if (!options || required.some(name => typeof options[name] !== 'function') || !options.profiles || !options.legacyBroker
      || typeof options.root !== 'string' || !path.isAbsolute(options.root) || typeof options.currentVersion !== 'string') throw failure();
    this.options = options; this.life = new AbortController(); this.onState = () => {};
    this.viewReady = new Promise(resolve => { this.resolveView = resolve; });
  }
  async initialize() {
    const o = this.options;
    let cursor = path.parse(o.root).root;
    for (const component of o.root.slice(cursor.length).split(path.sep).filter(Boolean)) {
      cursor = path.join(cursor, component); const entry = await fs.lstat(cursor);
      if (!entry.isDirectory() || entry.isSymbolicLink()) throw failure();
    }
    if (path.resolve(o.root) !== o.root || (await fs.realpath(o.root)).toLowerCase() !== o.root.toLowerCase()) throw failure();
    const deviceMetadata = new GenerationStore(o.root, o.safeStorage);
    try { const file = await fs.lstat(path.join(o.root, 'workspace-device.enc'));
      if (!file.isFile() || file.isSymbolicLink() || file.nlink !== 1 || file.size < 1 || file.size > 4096) throw failure();
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
    let device = await deviceMetadata.read('workspace-device');
    if (!device) {
      try { await fs.lstat(path.join(o.root, 'workspace-registry.enc')); throw failure(); } catch (error) { if (error.code !== 'ENOENT') throw error; }
      device = { format: 2, kind: 'desktop-workspace-physical-device', workspaceId: crypto.randomUUID() };
      await deviceMetadata.write('workspace-device', device);
    }
    if (device.format !== 2 || device.kind !== 'desktop-workspace-physical-device' || !uuid(device.workspaceId)
      || Object.keys(device).sort().join(',') !== 'format,kind,workspaceId') throw failure();
    this.http = new WorkspaceHTTP({ serverOrigin: o.serverOrigin, transport: o.transport, serverSession: o.serverSession, stopped: o.stopped });
    this.registry = new WorkspaceRegistry({ root: o.root, workspaceId: device.workspaceId, safeStorage: o.safeStorage,
      verifyAuthority: (record, options) => this.http.verifyAuthority(record, options) });
    await this.registry.read();
    let sourceAuthority;
    this.workspace = new WorkspaceRuntime({ registry: this.registry, bundle: o.bundle, safeStorage: o.safeStorage,
      downloadMedia: async ticket => this.http.media(ticket, this.pendingCredential || (await this.registry.current())?.credential, { signal: this.life.signal }),
      authorizeSource: (value, options = {}) => sourceAuthority.authorize(value, { signal: AbortSignal.any([this.life.signal, ...(options.signal ? [options.signal] : [])]) }),
      verifyActive: async value => value.candidate.prepared.workspaceProtocol === 2,
      authorizeInstalled: manifest => this.updater.authorizeInstalled(manifest), onPhase: o.onPhase, onFailure: o.onFailure });
    // Register before any preparation/login call can allocate or start children.
    o.ownRuntime(this);
    const installed = () => new RuntimeArchive(this.workspace.physical.profile, this.workspace.metadata).manifest(o.bundle);
    this.updater = new DashboardUpdater({ profile: this.workspace.physical.profile, metadata: this.workspace.metadata,
      credential: async () => this.pendingCredential || (await this.registry.current())?.credential,
      fetch: groupUpdateTransport(o.transport), currentVersion: o.currentVersion,
      currentSource: async () => (await installed()).sourceFingerprint, currentRevision: async () => (await installed()).sourceRevision,
      canApply: () => typeof o.canApplyUpdate === 'function' ? o.canApplyUpdate() : false, apply: value => o.applyUpdate(value), onState: o.onState });
    sourceAuthority = this.sourceAuthority = new WorkspaceSourceAuthority({ updater: this.updater });
    this.remoteState = new WorkspaceRemoteState({ workspace: this.workspace, verifySnapshot: details => this.verifySnapshot(details) });
    this.onlineLogin = new WorkspaceOnlineLogin({ workspace: this.workspace, http: this.http, createSession: o.createSession, stopped: o.stopped,
      onLocked: o.onLocked, markServerView: () => this.remoteState.browse() });
    this.accountLogin = new WorkspaceLogin({ workspace: this.workspace, createSession: o.createSession, online: o.online, stopped: o.stopped,
      verifyLocalSession: (value, options) => this.http.localSession(value, options), onState: value => this.onState(value), onLocked: o.onLocked,
      onlineFallback: (credentials, options) => this.onlineLogin.authenticate(credentials, options) });
    const accountLogin = this.accountLogin, legacy = o.legacyBroker, current = () => this.registry.current();
    const priorState = legacy.onState; legacy.onState = value => { priorState?.(value); this.onState(value); };
    this.login = { onState: undefined,
      authenticate: async input => {
        if (!await current()) return legacy.authenticate(input);
        const remote = await this.remoteState.read(), central = await this.workspace.metadata.read('workspace-central-mode');
        if (remote.dirty || remote.pending.length || central) {
          if (o.online?.() === false) throw Object.assign(failure(), { code: 'WORKSPACE_SERVER_SETTLEMENT_REQUIRED' });
          return this.onlineLogin.authenticate(input);
        }
        return accountLogin.authenticate(input);
      },
      resumeRemembered: async () => (await current()) ? null : legacy.resumeRemembered(),
      userEditing: () => { accountLogin.userEditing?.(); legacy.userEditing?.(); },
      handoff: result => result.account.protocol === 2 ? Promise.resolve(result) : legacy.handoff(result),
      cancel: () => { accountLogin.cancel(); legacy.cancel(); }, stop: async () => { await Promise.all([accountLogin.stop(), legacy.stop()]); } };
    this.onState = value => this.login.onState?.(value);
    const migration = new WorkspaceMigrationInventory({ profiles: o.profiles, inspectProfile: o.inspectLegacyProfile });
    this.preparation = new WorkspacePreparation({ workspace: this.workspace, serverOrigin: o.serverOrigin, migration,
      challenge: (serverOrigin, options) => this.http.challenge(serverOrigin, options), enroll: (...args) => this.http.enroll(...args),
      download: async (enrollment, options) => { this.pendingCredential = enrollment.credential; return this.http.root('bootstrap', undefined, enrollment.credential, options); },
      transition: o.transition, relaunch: o.relaunch, onState: o.onState, allowPreview: o.allowPreview });
    const prepare = this.preparation.prepare.bind(this.preparation);
    this.preparation.prepare = (...args) => prepare(...args).finally(() => { this.pendingCredential = null; });
    return this;
  }
  async verifyReturn({ previousRootBinding, currentRootBinding, currentPrepared }) {
    const active = await this.registry.current();
    if (!active || !same(rootBinding(active.candidate.prepared), currentRootBinding) || !same(currentPrepared, active.candidate.prepared)) return false;
    const audit = await this.workspace.metadata.read('workspace-generations/' + currentPrepared.refreshId);
    return audit?.format === 2 && audit.kind === 'desktop-workspace-generation-transition'
      && same(rootBinding(audit.previous), previousRootBinding) && same(rootBinding(audit.next), currentRootBinding)
      && audit.recordSha256 === digest(active) && audit.receiptSha256 === digest(active.candidate.receipt)
      && audit.refreshId === currentPrepared.refreshId && audit.fence?.device_id === currentPrepared.deviceId
      && audit.fence.custodian_actor_id === currentPrepared.actorId && audit.fence.refresh_id === audit.refreshId;
  }
  async verifySnapshot({ previousRootBinding, currentRootBinding, result }) {
    const currentPrepared = await this.workspace.connection();
    if (same(previousRootBinding, currentRootBinding)) {
      const proof = result?.verifiedUnion, active = await this.registry.current();
      if (result?.unchanged !== true || proof?.format !== 2 || proof.kind !== 'desktop-workspace-union-check' || !uuid(proof.id)
        || proof.recordSha256 !== digest(active) || proof.manifestSha256 !== digest(active.manifest)) return false;
      return same(await this.workspace.metadata.read('workspace-union-checks/' + proof.id), proof);
    }
    return this.verifyReturn({ previousRootBinding, currentRootBinding, currentPrepared });
  }
  async renewActor(actor, browser) {
    const active = await this.registry.current(), local = await this.workspace.start(), authority = await actor.authority();
    const proof = await this.http.localSession({ browser, local, active });
    if (proof.actor_id !== authority.actorId || proof.role !== authority.role) throw failure();
    await this.workspace.acceptSession({ actorId: proof.actor_id, role: proof.role }, proof);
  }
  attach({ result, view, dialog, sourceSession, createWindow, confirmMicrophone, notify, synchronize, onState = this.options.onState }) {
    if (this.services) return this.services;
    const workspace = this.workspace, actor = result.runtime, remoteState = this.remoteState;
    const guarded = new WorkspaceGuard({ workspace, remoteState, stopped: this.options.stopped }), guard = async details => {
      if (details?.operation === 'remote-write') { await this.viewReady; await this.afterViewJob; }
      return guarded.check(details);
    };
    let sync, mode, connected, attempts;
    const probe = async () => { await this.viewReady; await this.afterViewJob; return this.http.probe(actor, (await this.registry.current()).credential); };
    const verifyReturn = details => this.verifyReturn(details);
    const delta = new WorkspaceDelta({ workspace, view, remoteState, guard,
      download: (index, previous, options) => this.http.root('delta', index, previous.credential, options),
      manifest: (credential, options) => this.http.root('manifest', undefined, credential, options),
      handoff: work => sync.withRefreshHandoff(work),
      onActivated: async () => {
        try { if (result.onlineOnly) await result.refreshAuthority(); else await this.renewActor(actor, sourceSession()); }
        catch (error) { await this.options.onLocked({ actorId: result.actor.actorId, reason: 'membership-changed' }); throw error; }
      },
      onState, allowPreview: this.options.allowPreview });
    const generations = { run: () => delta.refresh(), recover: () => delta.recover(), get running() { return delta.running; } };
    mode = new WorkspaceMode({ workspace, actor, view, generations, probe, remoteState,
      remoteAttempts: { recover: () => attempts.recover() }, guard, verifyReturn, onState });
    connected = new WorkspaceConnected({ workspace, actor, view: () => view, mode: () => mode, generations: () => generations,
      remoteState, remoteAttempts: { recover: () => attempts.recover() }, probe, synchronize, guard, verifyReturn, onState });
    attempts = new WorkspaceRemoteAttempts({ workspace, actor, state: remoteState,
      request: (body, credential) => this.http.root('remote-attempts', body, credential), direct: details => connected.authorizeDirect(details), guard });
    sync = new WorkspaceSync({ workspace, remote: (body, credential) => this.http.root('commands', body, credential),
      refresh: () => connected.deferRefresh().then(defer => defer ? { deferred: true } : mode.serialize('workspace-incoming-delta', () => delta.run())),
      effects: async ({ printProtocol }) => {
        if (!synchronizePrintOutcomes.activeProtocol(printProtocol)) return { pending: 0, deferred: false };
        if (view.current().local) await view.recoverPrintEffects();
        return synchronizePrintOutcomes({ local: value => workspace.control(value),
          remote: async value => this.http.root('print-outcomes', value, (await this.registry.current()).credential), stopped: this.options.stopped });
      }, onState });
    const liveSocial = new WorkspaceLiveSocial({ workspace, actor, sourceSession, createSession: this.options.createSession,
      createWindow, quitting: this.options.stopped, confirmMicrophone, notify,
      probeSession: ({ browser, root, actor: authority, signal }) => this.http.actorSession({ browser, root, authority, signal }) });
    const socialOpen = liveSocial.open.bind(liveSocial); liveSocial.open = async kind => { await this.viewReady; await this.afterViewJob; return socialOpen(kind); };
    const outboxReview = new WorkspaceOutboxReview({ workspace, actor, dialog, synchronize });
    // This adapter is only the native review UI's readiness view. It never
    // changes Registry or grants local DB access to an online-only account.
    const reviewActor = { isPrepared: () => workspace.isPrepared(), connection: () => workspace.connection() };
    const connectedReview = new ConnectedReview({ attempts, connected, runtime: reviewActor, dialog });
    this.services = { mode, connected, delta, generations, sync, remoteState, attempts, liveSocial, outboxReview, connectedReview, guard };
    return this.services;
  }
  visible(result) {
    if (this.visibleStarted) return this.afterViewJob;
    this.visibleStarted = true;
    this.afterViewJob = Promise.resolve().then(() => result.afterView?.());
    this.afterViewJob.catch(() => {}); this.resolveView(); return this.afterViewJob;
  }
  async selectionStatus() {
    const selected = await this.workspace.metadata.read('workspace-central-mode');
    if (!selected) return false;
    if (!this.services) throw failure(); this.services.connected.record(selected); return true;
  }
  cancelStartup() { this.life.abort(); this.http?.stop(); this.updater?.stop(); this.accountLogin?.cancel(); this.onlineLogin?.cancel(); this.services?.delta.stop(); this.services?.sync.stop(); this.services?.liveSocial.close(); this.resolveView?.(); this.workspace?.cancelStartup(); }
  async stop() { this.cancelStartup(); await Promise.all([this.accountLogin?.stop(), this.onlineLogin?.stop()]); await this.workspace?.stop(); }
}
module.exports = { createWorkspaceNative };
