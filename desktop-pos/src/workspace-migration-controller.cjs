'use strict';
const crypto = require('node:crypto'), path = require('node:path');
const DashboardSync = require('./dashboard-sync.cjs');
const PrintSync = require('./dashboard-print-sync.cjs');
const Store = require('./store.cjs'), Sync = require('./sync.cjs');
const { WorkspaceMigrationInventory } = require('./workspace-migration.cjs');
const { canonical, digest } = require('./workspace-profile.cjs');
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const id = value => value === 'default' || uuid(value);
const same = (a, b) => canonical(a) === canonical(b);
const fail = code => Object.assign(Error('يلزم تأكيد العمليات القديمة قبل تفعيل تجهيز الجهاز؛ جميع السجلات محفوظة.'), { code: 'WORKSPACE_MIGRATION_' + code });
const need = (value, code) => { if (!value) throw fail(code); };
const KEY = 'workspace-legacy-migration';
/** Original accepted queues are drained serially; this controller never opens a printer. */
class WorkspaceMigrationController {
  constructor({ profiles, inspector, registry, metadata = registry?.metadata, remote, stopped = () => false, onState = () => {} }) {
    need(profiles && inspector && typeof inspector.inspectProfile === 'function' && typeof inspector.withRuntime === 'function'
      && registry && typeof registry.current === 'function' && metadata && typeof remote === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { profiles, inspector, registry, metadata, remote, stopped, onState });
    this.inventory = new WorkspaceMigrationInventory({ profiles, inspectProfile: inspector.inspectProfile });
  }
  check(signal) { need(!this.stopped() && !signal?.aborted, 'CANCELLED'); }
  report(phase, profiles = 0) { this.onState({ phase, profiles }); }
  record(value) {
    need(value?.format === 2 && value.kind === 'desktop-workspace-legacy-migration' && value.workspaceId === this.registry.workspaceId && uuid(value.id)
      && hash(value.inventoryDigest) && ['sealing','sealed','released','committed'].includes(value.phase) && Array.isArray(value.entries) && value.entries.length <= 64
      && new Set(value.entries.map(row => row.profileId)).size === value.entries.length, 'INTENT_INVALID');
    for (const row of value.entries) need(id(row.profileId) && uuid(row.id) && hash(row.token) && hash(row.dataSha256) && hash(row.businessSha256)
      && Number.isSafeInteger(row.sequence) && row.sequence >= 0 && ['planned','held','released'].includes(row.state)
      && row.prepared?.format === 2 && uuid(row.prepared.deviceId) && Number.isSafeInteger(row.prepared.actorId) && row.prepared.actorId > 0, 'INTENT_INVALID');
    return value;
  }
  ticket(value) {
    this.record(value); return { format: 2, kind: 'desktop-workspace-legacy-migration-ticket', id: value.id, workspaceId: value.workspaceId,
      inventoryDigest: value.inventoryDigest, digest: digest({ id: value.id, workspaceId: value.workspaceId, inventoryDigest: value.inventoryDigest,
        entries: value.entries.map(({ state, ...row }) => row) }) };
  }
  match(value, ticket) { need(same(this.ticket(value), ticket), 'TICKET_STALE'); }
  async save(value) { this.record(value); await this.metadata.write(KEY, value); }
  async read() { const value = await this.metadata.read(KEY); return value === null ? null : this.record(value); }
  async drain({ signal } = {}) {
    if (this.draining) return this.draining;
    this.draining = this.drainAll(signal).finally(() => { this.draining = null; }); return this.draining;
  }
  async drainAll(signal) {
    this.check(signal); need(await this.registry.current() === null, 'ALREADY_ACTIVE');
    const previous = await this.read(); if (previous && !['released','committed'].includes(previous.phase)) await this.release(this.ticket(previous));
    const listed = await this.profiles.list(); need(Array.isArray(listed) && listed.length > 0 && listed.length <= 64, 'PROFILE_LIST_INVALID');
    let done = 0; this.report('draining', done);
    for (const row of listed) {
      this.check(signal); const account = await this.profiles.account(row.id), before = await this.inspector.inspectProfile(account, { signal });
      need(!Object.values(before.holds).some(Boolean), 'FOREIGN_HOLD');
      need(before.counts.conflicts === 0 && before.counts.legacyConflicts === 0 && before.counts.openOrders === 0, 'REVIEW_REQUIRED');
      if (before.counts.legacyPending) await this.drainPOS(account, signal);
      if (before.prepared) await this.inspector.withRuntime(account, { signal }, async lease => {
        const local = value => lease.runtime.control(value), credential = lease.prepared;
        const sync = new DashboardSync({ local, remote: command => this.remote(command, credential, 'commands'),
          effects: async ({ printProtocol }) => {
            need(PrintSync.activeProtocol(printProtocol) === true, 'PRINT_PROOF_UNAVAILABLE');
            return PrintSync({ local, remote: value => this.remote(value, credential, 'print-outcomes'), stopped: () => this.stopped() || signal?.aborted });
          } });
        try {
          let previousPending = Infinity;
          for (let batch = 0; batch < 1024; batch++) {
            this.check(signal); const pending = await local({ action: 'pending' });
            need(pending?.counts && Number.isSafeInteger(pending.counts.pending) && pending.counts.pending >= 0 && pending.counts.conflicts === 0, 'REVIEW_REQUIRED');
            await sync.run(); this.check(signal);
            const state = await lease.readState(); need(state.journal.conflicts === 0, 'REVIEW_REQUIRED');
            if (state.journal.pending === 0) break;
            need(!sync.state.error && state.journal.pending < previousPending, 'DRAIN_DEFERRED'); previousPending = state.journal.pending;
            need(batch < 1023, 'DRAIN_CAPACITY');
          }
          const final = await lease.readState(), prints = await lease.nativePrints();
          need(final.journal.pending === 0 && final.journal.conflicts === 0 && Object.values(final.prints).every(value => value === 0) && prints.count === 0, 'OUTCOME_REVIEW_REQUIRED');
        } finally { sync.stop(); }
      });
      done++; this.report('draining', done);
    }
    const inventory = await this.inventory.inspectAll({ signal }); need(inventory.ready === true, 'DRAIN_DEFERRED'); this.report('drained', done); return inventory;
  }
  async drainPOS(account, signal) {
    // The read-only inspector already proved an existing ledger. Original Store
    // and Sync retain original UUID/ACK/error behavior; no empty DB is created.
    const store = new Store(path.join(account.profile, 'fasakhansta-pos.sqlite')); let sync;
    try {
      const saved = store.get('connection'); need(saved && typeof saved.origin === 'string' && typeof saved.token_cipher === 'string', 'POS_PAIRING_REQUIRED');
      const origin = new URL(saved.origin); need(origin.protocol === 'https:' && origin.origin === saved.origin && !origin.username && !origin.password, 'POS_PAIRING_INVALID');
      const token = this.inspector.safeStorage.decryptString(Buffer.from(saved.token_cipher, 'base64'));
      need(typeof token === 'string' && token.length >= 32 && token.length <= 512, 'POS_PAIRING_INVALID');
      const credential = { serverOrigin: origin.origin, deviceId: saved.device_id, token };
      sync = new Sync(store, (method, endpoint, body) => this.remote(body ?? null, credential, 'legacy-pos/' + endpoint));
      for (let batch = 0; batch < 10000; batch++) {
        this.check(signal); const before = store.counts(); if (before.pending === 0) break; need(before.errors === 0, 'POS_REVIEW_REQUIRED');
        await sync.run(); this.check(signal); const after = store.counts(); need(!sync.error && (after.errors === 0 || after.errors === null) && after.pending < before.pending, 'POS_DRAIN_DEFERRED');
        need(batch < 9999, 'DRAIN_CAPACITY');
      }
    } finally { sync?.stop(); store.close(); }
  }
  fence(value, row) {
    need(value?.held === true && value.device_id === row.prepared.deviceId && value.actor_id === row.prepared.actorId
      && value.snapshot_id === row.prepared.snapshotId && value.schema_hash === row.prepared.schemaHash && value.refresh_id === row.id
      && value.fenced_sequence === row.sequence, 'FENCE_MISMATCH');
  }
  async seal(inventory, { signal } = {}) {
    this.check(signal); need(await this.registry.current() === null, 'ALREADY_ACTIVE');
    need(await this.inventory.unchangedAndClean(inventory, { signal }) === true, 'INVENTORY_CHANGED');
    const prior = await this.read(); need(prior === null || prior.phase === 'released', 'RECOVERY_REQUIRED');
    const intent = { format: 2, kind: 'desktop-workspace-legacy-migration', workspaceId: this.registry.workspaceId,
      id: crypto.randomUUID(), inventoryDigest: inventory.digest, phase: 'sealing', entries: [] };
    await this.save(intent); this.report('sealing');
    try {
      for (const known of inventory.rows) {
        this.check(signal); if (!known.snapshot.prepared) continue;
        const account = await this.profiles.account(known.profileId);
        await this.inspector.withRuntime(account, { signal }, async lease => {
          need(typeof lease.inventorySnapshot === 'function', 'INVENTORY_PROOF_UNAVAILABLE');
          const bound = await lease.inventorySnapshot(); need(same(bound.snapshot, known.snapshot) && hash(bound.businessSha256), 'INVENTORY_CHANGED');
          const state = bound.state, native = await lease.nativePrints();
          need(!state.held && state.journal.pending === 0 && state.journal.conflicts === 0 && Object.values(state.prints).every(value => value === 0) && native.count === 0, 'INVENTORY_CHANGED');
          const row = { profileId: account.id, id: crypto.randomUUID(), token: crypto.randomBytes(32).toString('hex'), prepared: lease.prepared,
            sequence: state.sequence, dataSha256: state.identity_sha256, businessSha256: bound.businessSha256, state: 'planned' };
          intent.entries.push(row); await this.save(intent); this.check(signal);
          // The original capability is durable before the first request, even
          // when the DB accepts a fence and its HTTP response is lost.
          this.fence(await lease.runtime.control({ action: 'refresh-begin', refresh_id: row.id, token: row.token }), row);
          row.state = 'held'; await this.save(intent); this.check(signal);
          const checked = await lease.inventorySnapshot(), after = checked.state, prints = await lease.nativePrints();
          need(after.held && after.sequence === row.sequence && after.identity_sha256 === row.dataSha256 && after.journal.pending === 0
            && after.journal.conflicts === 0 && Object.values(after.prints).every(value => value === 0) && prints.count === 0
            && checked.businessSha256 === row.businessSha256, 'FENCE_DATA_CHANGED');
        });
      }
      intent.phase = 'sealed'; await this.save(intent); this.report('sealed', intent.entries.length); return this.ticket(intent);
    } catch (cause) {
      if (await this.registry.current() === null) {
        try { const saved = await this.read(); if (saved) await this.release(this.ticket(saved)); }
        catch { throw fail('RELEASE_REQUIRED'); }
      }
      throw cause;
    }
  }
  async release(ticket) {
    const intent = await this.read(); need(intent, 'INTENT_MISSING'); this.match(intent, ticket);
    // An authoritative committed group pointer wins over every late cancellation
    // or callback failure. Old held databases remain authenticated archives.
    if (await this.registry.current() !== null || intent.phase === 'committed') return { retained: true, committed: true };
    if (intent.phase === 'released') return { retained: false, committed: false };
    const failures = [];
    for (const row of [...intent.entries].reverse()) {
      if (await this.registry.current() !== null) return { retained: true, committed: true };
      try {
      if (row.state === 'released') continue; const account = await this.profiles.account(row.profileId);
      await this.inspector.withRuntime(account, {}, async lease => {
        need(same(lease.prepared, row.prepared), 'FENCE_FOREIGN');
        const state = await lease.runtime.control({ action: 'refresh-status', refresh_id: row.id, token: row.token });
        need(typeof state?.exists === 'boolean' && typeof state.held === 'boolean', 'FENCE_MISMATCH');
        if (await this.registry.current() !== null) return;
        if (state.exists && state.held) {
          this.fence(state, row); const result = await lease.runtime.control({ action: 'refresh-cancel', refresh_id: row.id, token: row.token });
          need(result?.held === false, 'RELEASE_REQUIRED');
        }
        if (await this.registry.current() === null) { row.state = 'released'; await this.save(intent); }
      });
      } catch (cause) { failures.push(cause); }
    }
    if (await this.registry.current() !== null) return { retained: true, committed: true };
    need(failures.length === 0, 'RELEASE_REQUIRED');
    intent.phase = 'released'; await this.save(intent); this.report('released', intent.entries.length); return { retained: false, committed: false };
  }
  async committed(ticket) {
    const intent = await this.read(); need(intent, 'INTENT_MISSING'); this.match(intent, ticket);
    const active = await this.registry.current(); need(active?.candidate?.prepared?.workspaceProtocol === 2, 'NOT_COMMITTED');
    need(['sealed','committed'].includes(intent.phase) && intent.entries.every(row => row.state === 'held'), 'FENCE_NOT_HELD');
    intent.phase = 'committed'; intent.groupSnapshotId = active.candidate.snapshotId; await this.save(intent); this.report('committed', intent.entries.length);
    return { retained: true, committed: true };
  }
}
module.exports = { WorkspaceMigrationController, KEY };
