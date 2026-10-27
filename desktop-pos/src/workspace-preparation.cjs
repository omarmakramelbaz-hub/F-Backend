'use strict';

const crypto = require('node:crypto');
const { createRecipient, decryptEnrollment } = require('./workspace-enrollment-seal.cjs');
const workspacePrepared = require('./workspace-prepared.cjs');
const { WorkspaceMigrationInventory } = require('./workspace-migration.cjs');
const { canonical } = require('./workspace-profile.cjs');
const fail = code => Object.assign(Error('تعذر استكمال تجهيز الجهاز؛ البيانات الحالية محفوظة.'), { code: 'WORKSPACE_PREPARATION_' + code });
const need = (value, code) => { if (!value) throw fail(code); };
const key = 'workspace-preparation';
function origin(value) { const parsed = new URL(value); need(parsed.protocol === 'https:' && parsed.origin === value && !parsed.username && !parsed.password, 'ORIGIN_INVALID'); return value; }

/**
 * One visible setup operation from the current authenticated dashboard session.
 * All arguments except origin+CSRF are native-owned. transition MUST acquire the
 * original global accepted-work/DB/print fence; inventory is not a substitute.
 * The native sealed recipient/challenge is persisted before any request. Only
 * Registry.activate commits prepared state. Old profiles/DBs are never removed.
 * Successful activation stops owned children and asks the app to relaunch into
 * ordinary login, avoiding mutation/copy of the initiating actor's sessions.
 */
class WorkspacePreparation {
  constructor({ workspace, serverOrigin, challenge, enroll, download, migration, transition, relaunch, onState = () => {},
    allowPreview = false, now = () => performance.now() }) {
    need(workspace?.registry && typeof challenge === 'function' && typeof enroll === 'function' && typeof download === 'function'
      && typeof migration?.inspectAll === 'function' && typeof migration?.unchangedAndClean === 'function'
      && typeof transition === 'function' && typeof relaunch === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, registry: workspace.registry, serverOrigin: origin(serverOrigin), challenge, enroll, download, migration,
      transition, relaunch, onState, allowPreview, now });
    this.state = { phase: 'idle', progress: 0, error: '' }; this.started = null; this.finished = null;
  }
  timing() { return this.started === null ? null : { elapsed_ms: Math.max(0, Math.floor((this.finished ?? this.now()) - this.started)), running: this.finished === null }; }
  report(values) { Object.assign(this.state, values); this.onState({ ...this.state, setup_timing: this.timing() }); }
  check(signal) { need(!signal?.aborted, 'CANCELLED'); }
  async status() { const publicState = await this.registry.publicState(); return { available: true, ...publicState, ...this.state, setup_timing: this.timing() }; }
  prepare(requestOrigin, csrf, { signal } = {}) {
    if (this.running) return this.running;
    this.started = this.now(); this.finished = null;
    this.running = this.initialize(requestOrigin, csrf, signal).finally(() => {
      this.finished = this.now(); this.running = null; this.report({});
    }); return this.running;
  }
  async initialize(requestOrigin, csrf, signal) {
    need(requestOrigin === this.serverOrigin, 'SESSION_INVALID');
    this.check(signal);
    if (await this.registry.current()) return this.status();
    try {
      this.report({ phase: 'checking', progress: 2, error: '' });
      const inventory = await this.migration.inspectAll({ signal }); this.check(signal);
      const migrationState = WorkspaceMigrationInventory.publicState(inventory);
      need(migrationState.ready === true, 'LEGACY_WORK_PENDING');
      await this.workspace.settings(); this.check(signal);
      let pending = await this.registry.metadata.read(key);
      if (!pending) {
        pending = { format: 2, kind: 'workspace-preparation', workspaceId: this.registry.workspaceId, serverOrigin: this.serverOrigin,
          nonce: crypto.randomBytes(32).toString('hex'), recipient: await createRecipient() };
        this.check(signal); await this.registry.metadata.write(key, pending);
      }
      need(pending.format === 2 && pending.kind === 'workspace-preparation' && pending.workspaceId === this.registry.workspaceId
        && pending.serverOrigin === this.serverOrigin && /^[a-f0-9]{64}$/.test(pending.nonce || '')
        && pending.recipient?.kind === 'desktop-dashboard-workspace-enrollment-recipient', 'RECOVERY_REQUIRED');
      this.report({ phase: 'enrolling', progress: 5 }); this.check(signal);
      const currentSession = await this.challenge(this.serverOrigin, { signal }); this.check(signal);
      need(currentSession?.protocol === 2 && Number.isSafeInteger(currentSession.actor_id) && currentSession.actor_id > 0
        && ['admin', 'vendor', 'resturant_owner'].includes(currentSession.role) && currentSession.server_origin === this.serverOrigin
        && typeof currentSession.csrf_token === 'string' && /^[A-Za-z0-9_-]{16,256}$/.test(currentSession.csrf_token), 'SESSION_INVALID');
      const wire = await this.enroll(this.serverOrigin, { device_id: pending.workspaceId, name: 'GO ERP', nonce: pending.nonce, public_key: pending.recipient.publicKey }, currentSession.csrf_token, { signal });
      this.check(signal);
      const enrollment = decryptEnrollment(wire, { recipient: pending.recipient, workspaceId: pending.workspaceId, nonce: pending.nonce, serverOrigin: this.serverOrigin });
      // Keep no public copy of the bearer, roster or recipient. A retry always
      // obtains fresh current-session authority rather than trusting this cache.
      let record, ticket;
      const state = await this.registry.read();
      if (state.staged) {
        const retained = state.staged.record;
        if (canonical(retained.credential) === canonical(enrollment.credential) && canonical(retained.manifest) === canonical(enrollment.manifest)
          && pending.migrationDigest === inventory.digest && pending.generation === retained.candidate.generation
          && pending.snapshotId === retained.candidate.snapshotId && pending.refreshId === retained.candidate.prepared.refreshId) {
          this.report({ phase: 'resuming', progress: 45, members: enrollment.manifest.members.length });
          await this.workspace.start(); this.check(signal);
          await this.workspace.restoreStage(retained, { signal }); this.check(signal);
          // Re-enter the trusted current authority hook; restaging the exact
          // record returns the same ticket without a download/import/rebuild.
          ticket = await this.registry.stage(retained, { signal }); record = retained;
        } else {
          // Fresh membership changed. The obsolete inactive generation remains
          // as retained evidence; only its non-active native ticket is retired.
          await this.transition(() => this.registry.rollback(this.registry.ticket(state), { signal }), { signal });
        }
      }
      if (!record) {
        // Bind this export to the complete legacy state BEFORE downloading. A
        // newly acknowledged legacy write between retries requires a fresh
        // union even when credentials and membership have not changed.
        pending = { ...pending, migrationDigest: inventory.digest };
        await this.registry.metadata.write(key, pending); this.check(signal);
        this.report({ phase: 'downloading', progress: 15, members: enrollment.manifest.members.length });
        const snapshot = await this.download(enrollment, { signal }); this.check(signal);
        this.report({ phase: 'starting', progress: 30 }); await this.workspace.start(); this.check(signal);
        const generation = crypto.randomBytes(8).toString('hex'), refreshId = crypto.randomUUID();
        await this.registry.metadata.write(key, { ...pending, generation, refreshId, snapshotId: snapshot.snapshot_id }); this.check(signal);
        this.report({ phase: 'verifying', progress: 45 });
        const staged = await this.workspace.stage(snapshot, generation, enrollment, { signal, onMediaProgress: ({ done, total }) => {
          if (total > 0) this.report({ progress: 45 + Math.floor(45 * done / total), media: { done, total } });
        } }); this.check(signal);
        const candidate = { workspaceId: staged.workspaceId, database: staged.database, generation, snapshotId: staged.snapshotId,
          schemaHash: staged.schemaHash, sourceRevision: staged.sourceRevision, sourceFingerprint: staged.sourceFingerprint,
          sourceSequence: staged.sourceSequence, manifestRevision: staged.manifestRevision, rosterSha256: staged.rosterSha256,
          prepared: workspacePrepared.create({ ...enrollment, candidate: staged, refreshId, sourceManifest: staged.sourceManifest, allowPreview: this.allowPreview }),
          receipt: staged.receipt };
        record = { ...enrollment, candidate }; ticket = await this.registry.stage(record, { signal }); this.check(signal);
      }
      delete this.state.media; this.report({ phase: 'activating', progress: 95 });
      await this.transition(async () => {
        this.check(signal);
        need(await this.migration.unchangedAndClean(inventory, { signal }) === true, 'LEGACY_WORK_CHANGED'); this.check(signal);
        await this.registry.activate(ticket, { signal, verifyCandidate: value => this.workspace.verifyCandidate(value, { signal }) });
        // Once the pointer is durable, do not cancel/delete the accepted new DB.
        // Stop physical children before relaunch even if a UI disconnects here.
        await this.workspace.stop();
      }, { signal });
      need(canonical((await this.registry.current())?.candidate) === canonical(record.candidate), 'ACTIVATION_UNCERTAIN');
      await this.registry.metadata.write(key, null);
      this.report({ phase: 'ready', progress: 100, error: '' });
      const result = await this.status(); await this.relaunch(); return result;
    } catch (error) {
      delete this.state.media;
      await this.workspace.stop().catch(() => {});
      this.report({ phase: 'failed', error: 'تعذر استكمال تجهيز الجهاز؛ البيانات الحالية محفوظة.' }); throw error;
    }
  }
}
module.exports = { WorkspacePreparation };
