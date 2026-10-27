'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs/promises'), os = require('node:os'), path = require('node:path'), crypto = require('node:crypto');
const AccountProfiles = require('../src/account-profiles.cjs');
const { GenerationStore } = require('../src/dashboard-generation.cjs');
const { WorkspaceMigrationInventory, COUNTS, HOLDS } = require('../src/workspace-migration.cjs');
const { WorkspaceMigrationController, KEY } = require('../src/workspace-migration-controller.cjs');
const { receiptHash } = require('../src/dashboard-print-effects.cjs');
const Store = require('../src/store.cjs');
const { encryption, record, hash, digest } = require('./helpers/workspace-fixture.cjs');
async function fixture(t) {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'workspace-migration-controller-')), safeStorage = encryption();
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const profiles = new AccountProfiles({ root, safeStorage }); await profiles.bind('default', { actorId: 101, serverOrigin: 'https://dashboard.example.test' });
  const created = await profiles.create(); await profiles.bind(created.id, { actorId: 202, serverOrigin: 'https://dashboard.example.test' });
  const accounts = [await profiles.account('default'), await profiles.account(created.id)], states = new Map(), metadata = new GenerationStore(root, safeStorage), calls = [], accepted = new Set();
  let group = null, running = 0, maximum = 0, lostCommand = false, lostFence = false, refuse = false, stop = false, cancelledDuringStatus = false, commitBeforeB = false;
  for (const account of accounts) {
    const prepared = structuredClone(record().candidate.prepared); delete prepared.workspaceProtocol; delete prepared.workspace; delete prepared.importReceiptSha256;
    prepared.actorId = account.actorId; prepared.deviceId = crypto.randomUUID(); prepared.token = hash('old actor token ' + account.actorId);
    states.set(account.id, { account, prepared, bound: true, queue: [], acknowledged: 0, conflicts: 0, outcomes: [], unknownPrints: 0, nativePrints: 0, held: null, sequence: 17, foreign: false, holdMeta: false });
  }
  const stateOf = value => ({ format: 1, kind: 'native-legacy-migration-state', device_id: value.prepared.deviceId, actor_id: value.prepared.actorId,
    snapshot_id: value.prepared.snapshotId, schema_hash: value.prepared.schemaHash, database: value.prepared.database, held: Boolean(value.held), sequence: value.sequence,
    journal: { pending: value.queue.length, conflicts: value.conflicts, acknowledged: value.acknowledged },
    prints: { prepared: 0, uncertain: value.unknownPrints, unacknowledged: value.outcomes.length, orphans: 0 },
    identity_sha256: digest({ queue: value.queue, acknowledged: value.acknowledged, outcomes: value.outcomes, unknown: value.unknownPrints }) });
  const inspector = { safeStorage, inspectProfile: async account => {
    const value = states.get(account.id), counts = Object.fromEntries(COUNTS.map(k => [k, 0])), holds = Object.fromEntries(HOLDS.map(k => [k, false]));
    counts.pending = value.queue.length; counts.conflicts = value.conflicts; counts.printEffects = value.unknownPrints + value.nativePrints + value.outcomes.length;
    holds.refresh = Boolean(value.held || value.foreign); holds.delta = value.holdMeta;
    const file = path.join(account.profile, 'fasakhansta-pos.sqlite'); let legacyProof = null;
    try { const { DatabaseSync } = require('node:sqlite'), db = new DatabaseSync(file, { readOnly: true });
      const row = db.prepare('SELECT COUNT(*) AS n,COALESCE(SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END),0) AS errors FROM outbox WHERE acked=0').get();
      counts.legacyPending = row.n; counts.legacyConflicts = row.errors; counts.openOrders = db.prepare("SELECT COUNT(*) AS n FROM orders WHERE status NOT IN ('paid','cancelled')").get().n; db.close(); legacyProof = hash(await fs.readFile(file));
    } catch (cause) { if (cause.code !== 'ERR_SQLITE_ERROR' && cause.code !== 'ENOENT') throw cause; }
    return { format: 1, profileId: account.id, prepared: value.bound, counts, holds,
      identitySha256: digest({ prepared: value.prepared, state: stateOf(value), legacyProof, bound: value.bound, holdMeta: value.holdMeta }) };
  }, withRuntime: async (account, _options, work) => {
    running++; maximum = Math.max(maximum, running); calls.push(['start', account.actorId]); const value = states.get(account.id);
    const fence = () => ({ held: Boolean(value.held), device_id: value.prepared.deviceId, actor_id: value.prepared.actorId, snapshot_id: value.prepared.snapshotId,
      schema_hash: value.prepared.schemaHash, refresh_id: value.held?.id, fenced_sequence: value.sequence });
    const runtime = { async control(input) {
      calls.push([input.action, account.actorId, input.command_id || input.refresh_id || null]);
      if (input.action === 'pending') return { commands: value.queue.slice(0, 1), counts: stateOf(value).journal, print_protocol: 1 };
      if (input.action === 'acknowledge') { assert.equal(input.command_id, value.queue[0].command_id); assert.equal(input.receipt.command_id, input.command_id); value.queue.shift(); value.acknowledged++; return { counts: stateOf(value).journal }; }
      if (input.action === 'failed') { if (input.conflict) value.conflicts++; return {}; }
      if (input.action === 'print-outcomes') return { outcomes: value.outcomes, pending: value.outcomes.length };
      if (input.action === 'print-acknowledge') { value.outcomes = value.outcomes.filter(o => o.effect_id !== input.receipt.effect_id); return input.receipt; }
      if (input.action === 'refresh-begin') {
        const intent = await metadata.read(KEY), planned = intent.entries.find(row => row.profileId === account.id);
        assert.equal(planned.id, input.refresh_id); assert.equal(planned.token, input.token); assert.equal(planned.state, 'planned');
        if (value.foreign) throw Object.assign(Error('foreign hold'), { status: 409 }); value.held = { id: input.refresh_id, token: input.token };
        if (commitBeforeB) { const ordered = [...accounts].sort((a, b) => a.id.localeCompare(b.id));
          if (account.id === ordered[0].id) { states.get(ordered[1].id).acknowledged++; commitBeforeB = false; } }
        if (lostFence) { lostFence = false; throw Error('lost fence reply after commit'); } return fence();
      }
      if (input.action === 'refresh-status') {
        if (cancelledDuringStatus) { group = { candidate: { prepared: { workspaceProtocol: 2 }, snapshotId: crypto.randomUUID() } }; }
        if (value.foreign) throw Object.assign(Error('foreign hold'), { status: 409 });
        if (!value.held) return { exists: false, held: false }; assert.equal(value.held.id, input.refresh_id); assert.equal(value.held.token, input.token); return { exists: true, ...fence() };
      }
      if (input.action === 'refresh-cancel') { assert.equal(value.held.id, input.refresh_id); assert.equal(value.held.token, input.token); value.held = null; return { held: false }; }
      throw Error('unreviewed native action');
    } };
    try { return await work({ runtime, prepared: structuredClone(value.prepared), account, readState: async () => stateOf(value), nativePrints: async () => ({ count: value.nativePrints }),
      inventorySnapshot: async () => ({ snapshot: await inspector.inspectProfile(account), state: stateOf(value),
        businessSha256: digest({ prepared: value.prepared, state: { ...stateOf(value), held: false }, nativePrints: value.nativePrints }) }) }); }
    finally { calls.push(['stop', account.actorId]); running--; }
  } };
  const remote = async (command, credential, endpoint) => {
    calls.push(['remote-' + endpoint, credential.actorId || credential.deviceId, command?.command_id || command?.event?.id || null]);
    if (endpoint === 'commands') {
      assert.equal(credential.actorId, command.actor_id); assert.equal(credential.token, hash('old actor token ' + command.actor_id));
      if (refuse) throw Object.assign(Error('original server rejected'), { status: 409 });
      accepted.add(command.command_id); if (lostCommand) { lostCommand = false; throw Error('lost command reply after server commit'); }
      return { committed: true, command_id: command.command_id, device_id: credential.deviceId };
    }
    if (endpoint === 'print-outcomes') return { effect_id: command.effect_id, invocation_id: command.invocation_id, state: command.state, receipt_sha256: receiptHash(command) };
    if (endpoint === 'legacy-pos/sync') { assert.equal(credential.serverOrigin, 'https://legacy.example.test'); assert.equal(credential.token, 'p'.repeat(64)); accepted.add(command.event.id); return { id: command.event.id, committed: true }; }
    if (endpoint === 'legacy-pos/health') return {};
    if (endpoint === 'legacy-pos/snapshot') return { id: 'legacy-snapshot', generated_at: new Date().toISOString(), products: [] };
    throw Error('Unreviewed endpoint');
  };
  const registry = { workspaceId: '11111111-1111-4111-8111-111111111111', metadata, current: async () => group };
  const create = () => new WorkspaceMigrationController({ profiles, inspector, registry, metadata, remote, stopped: () => stop });
  const inventory = new WorkspaceMigrationInventory({ profiles, inspectProfile: inspector.inspectProfile });
  const enqueue = account => { const value = states.get(account.id), command = { command_id: crypto.randomUUID(), actor_id: account.actorId, route_name: 'orders.store', payload: { original: account.actorId }, dependencies: [] };
    value.queue.push(command); return command; };
  return { root, profiles, accounts, states, metadata, inspector, registry, calls, accepted, create, inventory, enqueue,
    get maximum() { return maximum; }, setLostCommand(v) { lostCommand = v; }, setLostFence(v) { lostFence = v; }, setRefuse(v) { refuse = v; }, setStopped(v) { stop = v; },
    activate() { group = { candidate: { prepared: { workspaceProtocol: 2 }, snapshotId: crypto.randomUUID() } }; }, setActivateDuringStatus(v) { cancelledDuringStatus = v; }, setCommitBeforeB(v) { commitBeforeB = v; } };
}

test('original A and B dashboard queues drain serially with their original UUID/private bearer and metadata-only print outcomes', async t => {
  const f = await fixture(t), a = f.enqueue(f.accounts[0]), b = f.enqueue(f.accounts[1]), owner = f.states.get(f.accounts[0].id);
  owner.outcomes.push({ effect_id: hash('effect'), invocation_id: crypto.randomUUID(), origin_command_id: a.command_id, native_session_id: crypto.randomUUID(),
    claim_token: crypto.randomUUID(), enrollment_id: hash('enrollment'), permit_hash: hash('permit'), actor_id: 101, revision: 1, branch: 'f:100', state: 'invoked', reason: 'spooler_accepted' });
  const result = await f.create().drain(); assert.equal(result.ready, true); assert.deepEqual([...f.accepted], [a.command_id, b.command_id]); assert.equal(f.maximum, 1);
  assert.equal(f.calls.some(c => c[0] === 'print-prepare' || c[0] === 'print-burn'), false); assert.equal(owner.outcomes.length, 0);
});

test('lost command confirmation retries the exact old UUID and never changes its actor or applies a second server effect', async t => {
  const f = await fixture(t), command = f.enqueue(f.accounts[0]); f.setLostCommand(true);
  await assert.rejects(f.create().drain(), { code: 'WORKSPACE_MIGRATION_DRAIN_DEFERRED' });
  assert.equal(f.states.get(f.accounts[0].id).queue[0].command_id, command.command_id);
  await f.create().drain(); assert.equal(f.accepted.size, 1);
  assert.deepEqual(f.calls.filter(c => c[0] === 'remote-commands').map(c => c[2]), [command.command_id, command.command_id]);
});

test('conflicts, foreign holds and unknown prints defer without cancellation, conflict clearing or any printer invocation', async t => {
  const f = await fixture(t), controller = f.create(); f.states.get(f.accounts[1].id).holdMeta = true;
  await assert.rejects(controller.drain(), { code: 'WORKSPACE_MIGRATION_FOREIGN_HOLD' });
  f.states.get(f.accounts[1].id).holdMeta = false; f.states.get(f.accounts[1].id).unknownPrints = 1;
  await assert.rejects(controller.drain(), { code: 'WORKSPACE_MIGRATION_OUTCOME_REVIEW_REQUIRED' });
  f.states.get(f.accounts[1].id).unknownPrints = 0; f.enqueue(f.accounts[0]); f.setRefuse(true);
  await assert.rejects(controller.drain(), { code: 'WORKSPACE_MIGRATION_REVIEW_REQUIRED' });
  assert.equal(f.states.get(f.accounts[0].id).conflicts, 1); assert.equal(f.calls.some(c => c[0] === 'refresh-cancel'), false);
});

test('final barrier saves each capability before acquire and release cancels only those exact owned fences', async t => {
  const f = await fixture(t), controller = f.create(), inventory = await f.inventory.inspectAll(), ticket = await controller.seal(inventory);
  const intent = await f.metadata.read(KEY); assert.equal(intent.phase, 'sealed'); assert.equal(intent.entries.length, 2); assert.ok([...f.states.values()].every(v => v.held));
  await controller.release(ticket); assert.ok([...f.states.values()].every(v => v.held === null)); assert.equal((await f.metadata.read(KEY)).phase, 'released');
  assert.equal(f.calls.filter(c => c[0] === 'refresh-cancel').length, 2);
});

test('a lost begin reply is resolved with the durable original cap before any group activation', async t => {
  const f = await fixture(t), controller = f.create(); f.setLostFence(true);
  await assert.rejects(controller.seal(await f.inventory.inspectAll()), /lost fence reply/);
  assert.ok([...f.states.values()].every(v => v.held === null)); assert.equal((await f.metadata.read(KEY)).phase, 'released');
  assert.equal(f.calls.filter(c => c[0] === 'refresh-cancel').length, 1);
});

test('partial rollback keeps a foreign refusal and still releases every other owned capability, then resumes the original remaining capability', async t => {
  const f = await fixture(t), controller = f.create(), ticket = await controller.seal(await f.inventory.inspectAll());
  f.states.get(f.accounts[1].id).foreign = true;
  await assert.rejects(controller.release(ticket), { code: 'WORKSPACE_MIGRATION_RELEASE_REQUIRED' });
  assert.equal(f.states.get(f.accounts[0].id).held, null);
  assert.ok(f.states.get(f.accounts[1].id).held); assert.equal(f.states.get(f.accounts[1].id).foreign, true);
  assert.deepEqual(f.calls.filter(c => c[0] === 'refresh-cancel').map(c => c[1]), [101]);
  f.states.get(f.accounts[1].id).foreign = false; await controller.release(ticket);
  assert.equal(f.states.get(f.accounts[1].id).held, null);
  assert.deepEqual(f.calls.filter(c => c[0] === 'refresh-cancel').map(c => c[1]), [101, 202]);
  assert.equal((await f.metadata.read(KEY)).phase, 'released');
});

test('after the authoritative group pointer commits, cancellation or a late failure can never release old archive fences', async t => {
  const f = await fixture(t), controller = f.create(), ticket = await controller.seal(await f.inventory.inspectAll()); f.activate();
  await controller.committed(ticket); assert.deepEqual(await controller.release(ticket), { retained: true, committed: true });
  assert.ok([...f.states.values()].every(v => v.held)); assert.equal(f.calls.filter(c => c[0] === 'refresh-cancel').length, 0);
});

test('activation observed after a status reply still wins over cancellation and preserves the owned fence', async t => {
  const f = await fixture(t), controller = f.create(), ticket = await controller.seal(await f.inventory.inspectAll()); f.setActivateDuringStatus(true);
  assert.deepEqual(await controller.release(ticket), { retained: true, committed: true });
  assert.ok([...f.states.values()].every(v => v.held)); assert.equal(f.calls.filter(c => c[0] === 'refresh-cancel').length, 0);
});

test('new acknowledged work invalidates the final inventory before any legacy fence can be acquired', async t => {
  const f = await fixture(t), inventory = await f.inventory.inspectAll(); f.states.get(f.accounts[1].id).acknowledged++;
  await assert.rejects(f.create().seal(inventory), { code: 'WORKSPACE_MIGRATION_INVENTORY_CHANGED' }); assert.equal(f.calls.some(c => c[0] === 'refresh-begin'), false);
});

test('an external B producer that commits and acknowledges between the final scan and serial B fence invalidates the downloaded union', async t => {
  const f = await fixture(t), inventory = await f.inventory.inspectAll(), ordered = inventory.rows.map(row => f.states.get(row.profileId)); f.setCommitBeforeB(true);
  await assert.rejects(f.create().seal(inventory), { code: 'WORKSPACE_MIGRATION_INVENTORY_CHANGED' });
  assert.equal(ordered[1].acknowledged, 1);
  assert.deepEqual(f.calls.filter(c => c[0] === 'refresh-begin').map(c => c[1]), [ordered[0].account.actorId]);
  assert.deepEqual(f.calls.filter(c => c[0] === 'refresh-cancel').map(c => c[1]), [ordered[0].account.actorId]);
  assert.ok([...f.states.values()].every(v => v.held === null));
  assert.equal((await f.metadata.read(KEY)).phase, 'released');
});

test('legacy SQLite POS uses original Store/Sync and original paired bearer while immutable event bytes survive acknowledgement', async t => {
  const f = await fixture(t); for (const value of f.states.values()) value.bound = false;
  const file = path.join(f.accounts[0].profile, 'fasakhansta-pos.sqlite'), store = new Store(file), event = { id: crypto.randomUUID(), order_id: 'old-order', kind: 'sale', revision: 1 };
  store.saveSnapshot({ id: 'legacy-snapshot', products: [] }); store.set('connection', { origin: 'https://legacy.example.test', device_id: 7, token_cipher: f.inspector.safeStorage.encryptString('p'.repeat(64)).toString('base64') });
  store.db.prepare('INSERT INTO outbox(id,order_id,payload) VALUES(?,?,?)').run(event.id, event.order_id, JSON.stringify(event)); store.close();
  await f.create().drain(); const reopened = new Store(file); try { const row = reopened.db.prepare('SELECT id,payload,acked FROM outbox').get();
    assert.equal(row.id, event.id); assert.equal(row.payload, JSON.stringify(event)); assert.equal(row.acked, 1);
  } finally { reopened.close(); }
  assert.ok(f.calls.some(c => c[0] === 'remote-legacy-pos/sync' && c[1] === 7));
});
