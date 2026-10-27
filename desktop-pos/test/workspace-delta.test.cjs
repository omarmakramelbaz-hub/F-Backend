'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const { WorkspaceDelta, KEY, INTENT, UNION_INTENT } = require('../src/workspace-delta.cjs');
const prepared = require('../src/workspace-prepared.cjs');
const { fixture, digest, hash } = require('./helpers/workspace-fixture.cjs');

async function setup(t, unchangedFixture = null) {
  const f = await fixture(t, { activate: false }), records = new Map(), calls = [], previous = structuredClone(unchangedFixture?.previous || f.value);
  await f.registry.activate(await f.registry.stage(previous), { verifyCandidate: async () => true });
  let pending = 0, sequence = 7, idle = true, local = true, held = null, failFinalize = false, failAfterCommit = false;
  const attempts = new Map(), signal = new AbortController();
  const metadata = { read: async key => records.has(key) ? structuredClone(records.get(key)) : null,
    write: async (key, value) => { assert.notEqual(key, 'prepared', 'Registry owns the only prepared pointer'); calls.push('write:' + key); records.set(key, structuredClone(value)); } };
  const context = unchangedFixture ? structuredClone(unchangedFixture.context) : { manifest: { sourceRevision: previous.candidate.sourceRevision }, sourceFingerprint: previous.candidate.sourceFingerprint,
    workspaceApproval: { sequence: previous.candidate.sourceSequence, sourceRevision: previous.candidate.sourceRevision, sourceFingerprint: previous.candidate.sourceFingerprint } };
  let nextManifest = structuredClone(previous.manifest), capture, currentCandidate;
  const target = () => unchangedFixture ? (Object.isFrozen(unchangedFixture.delta) ? unchangedFixture.delta : structuredClone(unchangedFixture.delta)) : ({ format: 2, kind: 'workspace-data-delta', device_id: previous.manifest.workspace_id,
    actor_id: previous.candidate.prepared.actorId, snapshot_id: '44444444-4444-4444-8444-444444444444', schema_hash: hash('next schema'),
    source: structuredClone(context.sourceFingerprint), branches: [...new Set(nextManifest.members.flatMap(member => member.branches))].sort(),
    workspace_manifest: structuredClone(nextManifest), workspace_enrollment: { format: 2, workspace_id: previous.manifest.workspace_id,
      custodian_actor_id: previous.candidate.prepared.actorId, token_hash: prepared.expectedBinding(previous.credential, nextManifest).token_hash },
    coverage: { full_dashboard: true, media: true, workspace_protocol: 2, root_fingerprint: nextManifest.root_fingerprint,
      manifest_revision: nextManifest.revision, roster_sha256: nextManifest.roster_sha256 }, media: [], tables: { users: { ddl: 'fixture', delta: { add: [] } } } });
  let wireMutate = value => value;
  const workspace = { registry: f.registry, metadata, stopping: false,
    control: async input => {
      calls.push(input.action);
      if (input.action === 'pending') return { commands: [], counts: { pending, conflicts: 0 } };
      const attempt = attempts.get(input.refresh_id);
      if (attempt && attempt.token !== input.token) throw Object.assign(Error('wrong fence owner'), { status: 409 });
      if (input.action === 'refresh-begin') {
        if (pending) throw Object.assign(Error('B still has a pending command'), { status: 409 });
        const result = { held: true, device_id: previous.manifest.workspace_id, actor_id: previous.candidate.prepared.actorId,
          snapshot_id: previous.candidate.snapshotId, schema_hash: previous.candidate.schemaHash,
          refresh_id: input.refresh_id, fenced_sequence: sequence };
        held = input.refresh_id; attempts.set(held, { token: input.token, result }); return result;
      }
      if (input.action === 'refresh-status') return attempt ? { exists: true, ...attempt.result } : { exists: false, held: false };
      if (input.action === 'refresh-cancel') { attempt.result.held = false; held = null; return attempt.result; }
      throw Error('unexpected control ' + input.action);
    } };
  const adapter = { controller: signal, stop() { calls.push('stop'); signal.abort(); },
    context: async () => { calls.push('context'); return context; },
    authorize: async () => { calls.push('publication'); return context.workspaceApproval; },
    capture: async (_context, old, id) => {
      calls.push('capture'); const p = old.candidate.prepared;
      if (unchangedFixture) {
        capture = structuredClone(unchangedFixture.capture); capture.capture_database = 'fasakhansta_dashboard_stage_' + id;
        return capture;
      }
      capture = { format: 2, kind: 'desktop-workspace-delta-capture', capture_id: hash('capture'),
        capture_database: 'fasakhansta_dashboard_stage_' + id, source_database: p.database, sequence,
        identity: { device_id: p.deviceId, actor_id: p.actorId, snapshot_id: p.snapshotId, schema_hash: p.schemaHash,
          branches: p.branches, source: p.sourceFingerprint, workspace: prepared.workspaceBinding(old.manifest) },
        index: { format: 2, kind: 'desktop-workspace-delta-index', device_id: p.deviceId, actor_id: p.actorId,
          source: context.sourceFingerprint, workspace: { workspace_id: p.deviceId, root_fingerprint: old.manifest.root_fingerprint,
            revision: old.manifest.revision, roster_sha256: old.manifest.roster_sha256 }, tables: { users: {} } },
        replay: { format: 2, workspace_id: p.deviceId, root_fingerprint: old.manifest.root_fingerprint,
          source_database: p.database, sequence, rows: 5, sha256: hash('A B and retired commands with proofs') } };
      return capture;
    },
    stage: async (_context, old, baseline, delta, manifest, id) => {
      calls.push('stage');
      const receipt = { ...structuredClone(old.candidate.receipt), snapshot_id: delta.snapshot_id, schema_hash: delta.schema_hash,
        source: delta.source, branches: delta.branches, coverage: delta.coverage, workspace: prepared.workspaceBinding(manifest),
        delta_source_binding: prepared.expectedBinding(old.credential, old.manifest), delta_replay: baseline.replay,
        delta_source_identity: { snapshot_id: old.candidate.snapshotId, schema_hash: old.candidate.schemaHash, workspace: prepared.workspaceBinding(old.manifest) } };
      receipt.delta_stage = { format: 2, kind: 'desktop-workspace-delta-stage', stage_id: hash('stage'), stage_database: 'fasakhansta_dashboard_stage_' + id,
        source_database: old.candidate.database, capture_database: baseline.capture_database, capture_id: baseline.capture_id,
        device_id: old.manifest.workspace_id, actor_id: receipt.actor_id, snapshot_id: receipt.snapshot_id,
        workspace: receipt.workspace, receipt_sha256: hash('exact PHP bytes') };
      currentCandidate = { workspaceId: old.manifest.workspace_id, generation: id, database: receipt.delta_stage.stage_database,
        snapshotId: receipt.snapshot_id, schemaHash: receipt.schema_hash, sourceRevision: context.manifest.sourceRevision,
        sourceFingerprint: context.sourceFingerprint, sourceSequence: context.workspaceApproval.sequence,
        manifestRevision: manifest.revision, rosterSha256: manifest.roster_sha256, receipt, mediaVerified: true,
        mediaSeal: { format: 1 }, archiveHandoff: { format: 2, kind: 'desktop-workspace-preverified-handoff' } };
      return currentCandidate;
    },
    restore: async cp => { calls.push('restore'); currentCandidate = cp.candidate; return { ...context, workspaceApproval: context.workspaceApproval }; },
    release: async () => { calls.push('release'); return true; },
    finalize: async (candidate, intent) => { calls.push('finalize:' + candidate.generation); assert.equal(held, intent.id);
      if (failFinalize) throw Error('process closed before the final reply'); return { archived_commands: 5 }; },
    activate: async (record, ticket) => { calls.push('activate:' + record.candidate.generation);
      await f.registry.activate(ticket, { verifyCandidate: async candidate => digest(candidate) === digest(record) });
      if (failAfterCommit) throw Error('reply lost after the single pointer committed');
    } };
  let guardResult = true, manifestCalls = 0, downloads = 0, afterView = async () => {}, activated = 0;
  const view = { current: () => ({ local }), refresh: async work => { calls.push('view'); await afterView(); return idle ? work() : { deferred: true }; } };
  const create = options => new WorkspaceDelta({ workspace, adapter, view,
    download: async index => { calls.push('download'); downloads++; assert.deepEqual(index, capture.index); assert.equal(index.capture_token, undefined); return wireMutate(target()); },
    manifest: async () => { calls.push('manifest'); manifestCalls++; return structuredClone(nextManifest); },
    handoff: async work => { calls.push('handoff'); return work(); }, guard: async () => { calls.push('global-guard'); return guardResult; },
    onActivated: async () => { calls.push('session-renew'); activated++; }, ...options });
  return { ...f, previous, workspace, adapter, records, calls, context, view, create, attempts,
    candidate: () => currentCandidate, held: () => held, counts: () => ({ downloads, manifestCalls, activated }),
    setPending: value => { pending = value; }, setSequence: value => { sequence = value; }, setIdle: value => { idle = value; },
    setLocal: value => { local = value; }, setGuard: value => { guardResult = value; }, setFinalizeFailure: value => { failFinalize = value; },
    setLostCommit: value => { failAfterCommit = value; }, setBeforeView: work => { afterView = work; },
    mutateWire: work => { wireMutate = work; }, setManifest: value => { nextManifest = value; } };
}

test('one v2 delta stages all actors before fencing and activates only the Registry pointer', async t => {
  const f = await setup(t), next = await f.create().run();
  assert.notEqual(next.database, f.previous.candidate.database); assert.equal(next.actorId, 101);
  assert.equal((await f.registry.current()).candidate.database, next.database);
  assert.ok(f.calls.indexOf('stage') < f.calls.indexOf('view'));
  assert.ok(f.calls.indexOf('view') < f.calls.indexOf('refresh-begin'));
  assert.ok(f.calls.findIndex(value => value.startsWith('finalize:')) < f.calls.findIndex(value => value.startsWith('activate:')));
  assert.equal(f.records.get(INTENT), null); assert.equal(f.records.get(KEY), null); assert.equal(f.counts().activated, 1);
  const audit = f.records.get('workspace-generations/' + next.refreshId);
  assert.deepEqual(audit.previous, f.previous.candidate.prepared); assert.deepEqual(audit.next, next);
  assert.equal(audit.recordSha256, digest(await f.registry.current())); assert.equal(audit.refreshId, next.refreshId);
  assert.ok(f.calls.indexOf('write:workspace-generations/' + next.refreshId) < f.calls.findIndex(value => value.startsWith('activate:')));
  assert.equal(f.previous.candidate.receipt.actor_id, 101);
});

test('pending B commands and unknown global print/remote work block A refresh before any capture', async t => {
  const f = await setup(t); f.setPending(1);
  assert.deepEqual(await f.create().run(), { deferred: true }); assert.equal(f.calls.includes('capture'), false);
  f.setPending(0); f.setGuard(false);
  assert.deepEqual(await f.create().run(), { deferred: true }); assert.equal(f.calls.includes('capture'), false);
  assert.deepEqual(await f.registry.current(), f.previous);
});

test('a deferred view resumes the same preverified generation without download or rebuild', async t => {
  const f = await setup(t), delta = f.create(); f.setIdle(false);
  assert.deepEqual(await delta.run(), { deferred: true }); const cp = f.records.get(KEY), generation = cp.candidateGeneration;
  assert.equal(f.calls.filter(value => value === 'stage').length, 1); assert.equal(f.held(), null);
  f.setIdle(true); const next = await delta.run(); assert.equal(next.generation, generation);
  assert.equal(f.counts().downloads, 1); assert.equal(f.calls.filter(value => value === 'capture').length, 1);
  assert.equal(f.calls.filter(value => value === 'stage').length, 1);
});

test('newly acknowledged work at final handoff cancels only its own fence and keeps old data', async t => {
  const f = await setup(t); f.setBeforeView(async () => f.setSequence(8));
  assert.deepEqual(await f.create().run(), { deferred: true });
  assert.equal(f.held(), null); assert.equal(f.calls.some(value => value.startsWith('finalize:')), false);
  assert.equal(f.records.get(KEY), null); assert.deepEqual(await f.registry.current(), f.previous);
});

test('an accepted fence crash settles the same generation offline with no new HTTP or build', async t => {
  const f = await setup(t); f.setFinalizeFailure(true);
  await assert.rejects(f.create().run(), { code: 'WORKSPACE_DELTA_RECOVERY_REQUIRED' });
  const cp = structuredClone(f.records.get(KEY)), intent = structuredClone(f.records.get(INTENT));
  assert.equal(f.held(), intent.id); f.setFinalizeFailure(false);
  const fresh = f.create({ download: async () => { throw Error('no redownload during recovery'); }, manifest: async () => { throw Error('offline'); } });
  const next = await fresh.recover(); assert.equal(next.generation, cp.candidateGeneration);
  assert.equal(f.counts().downloads, 1); assert.equal(f.calls.filter(value => value === 'stage').length, 1);
  assert.equal(f.records.get(INTENT), null); assert.equal(f.calls.includes('refresh-cancel'), false);
});

test('a lost reply after Registry commit never cancels the old source or commits another generation', async t => {
  const f = await setup(t); f.setLostCommit(true); const next = await f.create().run();
  assert.equal((await f.registry.current()).candidate.generation, next.generation);
  assert.equal(f.calls.filter(value => value.startsWith('activate:')).length, 1);
  assert.equal(f.calls.includes('refresh-cancel'), false); assert.equal(f.records.get(INTENT), null);
});

test('cancelled fence recovery renews acceptance UUID while retaining the exact preverified DB', async t => {
  const f = await setup(t); f.setFinalizeFailure(true);
  await assert.rejects(f.create().run()); const intent = f.records.get(INTENT), cp = f.records.get(KEY);
  await f.workspace.control({ action: 'refresh-cancel', refresh_id: intent.id, token: intent.token }); f.setFinalizeFailure(false);
  const retry = f.create(); await retry.recover(); const retained = f.records.get(KEY);
  assert.equal(retained.candidateGeneration, cp.candidateGeneration); assert.notEqual(retained.record.candidate.prepared.refreshId, intent.id);
  const next = await retry.run(); assert.equal(next.generation, cp.candidateGeneration);
  assert.equal(f.calls.filter(value => value === 'stage').length, 1);
});

test('mode-managed refresh handles dirty remote snapshot only inside its trusted blocked gate', async t => {
  const f = await setup(t), remoteState = { blocked: true, read: async () => ({ dirty: true, pending: [] }),
    assertClean: async () => { throw Error('remote data needs replacement'); } };
  f.setLocal(false); const next = await f.create({ remoteState }).refresh();
  assert.notEqual(next.database, f.previous.candidate.database); assert.equal(f.calls.includes('view'), false);
  const other = await setup(t); await assert.rejects(other.create({ remoteState: { ...remoteState, blocked: false } }).refresh(), { code: 'WORKSPACE_DELTA_REMOTE_UNCERTAIN' });
});

test('membership refresh can retire custody A while keeping physical custody and old replay history', async t => {
  const f = await setup(t), manifest = structuredClone(f.value.manifest);
  manifest.revision++; manifest.members = manifest.members.filter(member => member.actor_id !== 101);
  manifest.roster_sha256 = digest(manifest.members); manifest.signature = hash('new roster with retired custody'); f.setManifest(manifest);
  const next = await f.create().run(), current = await f.registry.current();
  assert.equal(next.actorId, 101); assert.equal(current.manifest.members.some(member => member.actor_id === 101), false);
  assert.equal(current.candidate.receipt.delta_replay.rows, 5);
  assert.equal(current.candidate.receipt.delta_source_binding.revision, 1); assert.equal(current.manifest.revision, 2);
});

test('foreign root, source and custody cannot replace the active shared generation', async t => {
  for (const mutate of [wire => { wire.actor_id = 202; return wire; }, wire => { wire.source.sha256 = hash('foreign source'); return wire; },
    wire => { wire.workspace_manifest.root_fingerprint = hash('foreign root'); return wire; },
    wire => { wire.workspace_enrollment.custodian_actor_id = 202; return wire; }]) {
    const f = await setup(t); f.mutateWire(mutate); await assert.rejects(f.create().run()); assert.deepEqual(await f.registry.current(), f.previous);
    assert.equal(f.calls.some(value => value.startsWith('activate:')), false);
  }
});

test('source rollback is rejected before creating a private capture', async t => {
  const f = await setup(t); f.context.workspaceApproval.sequence--;
  await assert.rejects(f.create().run(), { code: 'WORKSPACE_DELTA_SOURCE_ROLLBACK' });
  assert.equal(f.calls.includes('capture'), false); assert.deepEqual(await f.registry.current(), f.previous);
});

test('late B work at the final gate defers without acquiring a durable active fence', async t => {
  const f = await setup(t); f.setBeforeView(async () => f.setPending(1));
  assert.deepEqual(await f.create().run(), { deferred: true }); assert.equal(f.calls.includes('refresh-begin'), false);
  assert.ok(f.records.get(KEY).record); assert.deepEqual(await f.registry.current(), f.previous);
});

test('stopping before accepted fence retains a ready checkpoint and prevents a second download', async t => {
  const f = await setup(t), delta = f.create(); f.setBeforeView(async () => delta.stop());
  await assert.rejects(delta.run(), { code: 'WORKSPACE_DELTA_CANCELLED' });
  const cp = f.records.get(KEY); assert.ok(cp.record); assert.equal(f.held(), null);
  f.setBeforeView(async () => {}); const next = await f.create({ adapter: { ...f.adapter, controller: new AbortController() } }).run(); assert.equal(next.generation, cp.candidateGeneration);
  assert.equal(f.counts().downloads, 1); assert.equal(f.calls.filter(value => value === 'stage').length, 1);
});

test('cleanup crash after intent was cleared recognizes the committed target instead of rebuilding', async t => {
  const f = await setup(t), release = f.adapter.release; let failCleanup = true;
  f.adapter.release = async cp => { if (failCleanup) throw Error('cleanup interrupted'); return release(cp); };
  await assert.rejects(f.create().run());
  const cp = f.records.get(KEY); assert.ok(cp.record); assert.equal(f.records.get(INTENT), null);
  assert.deepEqual(await f.registry.current(), cp.record); failCleanup = false;
  const next = await f.create({ download: async () => { throw Error('no new capture after committed pointer'); } }).recover();
  assert.equal(next.generation, cp.candidateGeneration); assert.equal(f.records.get(KEY), null);
  assert.equal(f.calls.filter(value => value === 'capture').length, 1); assert.equal(f.calls.includes('refresh-cancel'), false);
});

test('a verified unchanged v2 union needs no stage, import or Registry write', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const f = await setup(t, unchangedFixture()), before = await f.registry.read(true), result = await f.create().run();
  assert.equal(result.unchanged, true); assert.equal(result.verifiedUnion.kind, 'desktop-workspace-union-check');
  assert.deepEqual(result.verifiedUnion, f.records.get('workspace-union-checks/' + result.verifiedUnion.id));
  assert.equal(result.verifiedUnion.recordSha256, digest(f.previous));
  assert.equal(result.verifiedUnion.manifestSha256, digest(f.previous.manifest));
  assert.equal(result.verifiedUnion.sourceSequence, f.previous.candidate.sourceSequence);
  assert.equal(result.verifiedUnion.sequence, 7); assert.equal(result.verifiedUnion.token, undefined);
  assert.deepEqual(await f.registry.read(true), before); assert.deepEqual(await f.registry.current(), f.previous);
  assert.equal(f.calls.includes('stage'), false); assert.equal(f.calls.some(call => call.startsWith('finalize:') || call.startsWith('activate:')), false);
  assert.equal(f.calls.filter(call => call === 'publication').length, 1); assert.equal(f.counts().activated, 0);
  assert.equal(f.held(), null); assert.equal(f.records.get(KEY), null); assert.equal(f.records.get(UNION_INTENT), null);
  assert.ok(f.calls.indexOf('write:workspace-union-checks/' + result.verifiedUnion.id) < f.calls.indexOf('refresh-cancel'));
});

test('an all-zero summary with a changed table hash still requires verified reconstruction', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const value = unchangedFixture(); value.delta.tables.users.delta.target_hash = hash('changed server union');
  const f = await setup(t, value), next = await f.create().run();
  assert.notEqual(next.database, f.previous.candidate.database); assert.equal(f.calls.includes('stage'), true);
  assert.equal([...f.records.keys()].some(key => key.startsWith('workspace-union-checks/')), false);
});

test('new acknowledged work during unchanged handoff invalidates the certificate without staging', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const f = await setup(t, unchangedFixture()); f.setBeforeView(async () => f.setSequence(8));
  assert.deepEqual(await f.create().run(), { deferred: true }); assert.equal(f.held(), null);
  assert.equal(f.calls.includes('stage'), false); assert.deepEqual(await f.registry.current(), f.previous);
  assert.equal(f.records.get(KEY), null); assert.equal(f.records.get(UNION_INTENT), null);
  assert.equal([...f.records.keys()].some(key => key.startsWith('workspace-union-checks/')), false);
});

test('unchanged settlement after a lost cancellation can recover offline without activating any generation', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const f = await setup(t, unchangedFixture()), control = f.workspace.control; let refuseCancel = true;
  f.workspace.control = input => {
    if (input.action === 'refresh-cancel' && refuseCancel) throw Error('connection lost before cancellation reply');
    return control(input);
  };
  await assert.rejects(f.create().run(), { code: 'WORKSPACE_DELTA_RECOVERY_REQUIRED' });
  const intent = f.records.get(UNION_INTENT); assert.equal(f.held(), intent.id); assert.equal(f.records.get(KEY).candidateGeneration, null);
  refuseCancel = false;
  await f.create({ download: async () => { throw Error('offline recovery must not download'); },
    manifest: async () => { throw Error('offline recovery must not fetch a manifest'); } }).recover();
  assert.equal(f.held(), null); assert.equal(f.records.get(UNION_INTENT), null); assert.equal(f.records.get(KEY), null);
  assert.equal(f.calls.includes('stage'), false); assert.equal(f.calls.some(call => call.startsWith('activate:')), false);
  assert.deepEqual(await f.registry.current(), f.previous);
});

test('late cancellation still releases an accepted unchanged fence and never imports', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const f = await setup(t, unchangedFixture()), control = f.workspace.control, delta = f.create();
  f.workspace.control = async input => { const result = await control(input); if (input.action === 'refresh-begin') delta.stop(); return result; };
  const result = await delta.run(); assert.equal(result.unchanged, true); assert.equal(f.held(), null);
  assert.equal(f.calls.includes('stage'), false); assert.deepEqual(await f.registry.current(), f.previous);
});

test('a forged unchanged recovery audit cannot cancel a physical source fence', async t => {
  const { fixture: unchangedFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const f = await setup(t, unchangedFixture()), control = f.workspace.control;
  f.workspace.control = input => input.action === 'refresh-cancel' ? Promise.reject(Error('crash')) : control(input);
  await assert.rejects(f.create().run());
  const raw = f.records.get(UNION_INTENT); raw.audit.recordSha256 = hash('other active root'); f.records.set(UNION_INTENT, raw);
  const before = f.calls.filter(call => call === 'refresh-status').length;
  await assert.rejects(f.create().recover(), { code: 'WORKSPACE_DELTA_UNION_INTENT_UNBOUND' });
  assert.equal(f.calls.filter(call => call === 'refresh-status').length, before);
});

test('recovery rejects a substituted acceptance id or Registry ticket before touching its fence', async t => {
  for (const mutate of [value => { value.id = '55555555-5555-4555-8555-555555555555'; },
    value => { value.ticket.digest = hash('other candidate'); }]) {
    const f = await setup(t); f.setFinalizeFailure(true); await assert.rejects(f.create().run());
    const intent = f.records.get(INTENT); mutate(intent); f.records.set(INTENT, intent);
    const before = f.calls.filter(call => call === 'refresh-status').length;
    await assert.rejects(f.create().recover(), { code: 'WORKSPACE_DELTA_RECOVERY_UNBOUND' });
    assert.equal(f.calls.filter(call => call === 'refresh-status').length, before);
    assert.deepEqual(await f.registry.current(), f.previous);
  }
});

test('nonempty catalog equality skips import and pointer writes using the private capture receipt and exact server bytes', async t => {
  const { catalogFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const wire = require('../../tests/fixtures/workspace-catalog-wire-v2.json');
  const value = await catalogFixture(wire.created_scopes_json), f = await setup(t, value), before = await f.registry.read(true);
  const result = await f.create().run(); assert.equal(result.unchanged, true);
  assert.equal(f.calls.includes('stage'), false); assert.equal(f.calls.some(call => call.startsWith('activate:')), false);
  assert.deepEqual(await f.registry.read(true), before); assert.equal(f.records.get(UNION_INTENT), null);
  const audit = f.records.get('workspace-union-checks/' + result.verifiedUnion.id);
  assert.deepEqual(audit, result.verifiedUnion); assert.equal(audit.recordSha256, digest(f.previous));
});

test('catalog bytes losing PHP integral floats and large integers force verified staging', async t => {
  const { catalogFixture } = require('./helpers/workspace-unchanged-fixture.cjs');
  const value = await catalogFixture(); value.delta = structuredClone(value.delta);
  const f = await setup(t, value), result = await f.create().run();
  assert.notEqual(result.database, f.previous.candidate.database); assert.equal(f.calls.includes('stage'), true);
  assert.equal([...f.records.keys()].some(key => key.startsWith('workspace-union-checks/')), false);
});

test('startup recovery skips every unaccepted inactive checkpoint before the first view', async t => {
  const f = await setup(t); f.records.set(KEY, { deliberately: 'unread inactive cleanup state' });
  const before = [...f.calls]; await f.create({ download: async () => { throw Error('no startup download'); },
    manifest: async () => { throw Error('no startup manifest'); } }).recoverAccepted();
  assert.deepEqual(f.calls, before); assert.deepEqual(f.records.get(KEY), { deliberately: 'unread inactive cleanup state' });
  assert.deepEqual(await f.registry.current(), f.previous);
});

test('startup recovery still settles a durable accepted source fence offline in its same generation', async t => {
  const f = await setup(t); f.setFinalizeFailure(true); await assert.rejects(f.create().run());
  const cp = f.records.get(KEY); f.setFinalizeFailure(false);
  const result = await f.create({ download: async () => { throw Error('no startup download'); },
    manifest: async () => { throw Error('no startup manifest'); } }).recoverAccepted();
  assert.equal(result.generation, cp.candidateGeneration); assert.equal(f.records.get(INTENT), null);
  assert.equal(f.calls.filter(call => call === 'stage').length, 1);
});
