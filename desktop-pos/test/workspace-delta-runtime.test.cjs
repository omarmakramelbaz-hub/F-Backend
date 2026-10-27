'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), path = require('node:path');
const { execFileSync } = require('node:child_process');
const { WorkspaceDeltaRuntime } = require('../src/workspace-delta-runtime.cjs');
const prepared = require('../src/workspace-prepared.cjs');
const readSnapshot = require('../src/dashboard-download.cjs');
const { fixture, hash, digest } = require('./helpers/workspace-fixture.cjs');
const token = hash('private capture token'), captureGeneration = 'd'.repeat(16), targetGeneration = 'c'.repeat(16);
const scriptName = args => path.basename(args.find(value => typeof value === 'string' && value.endsWith('.php')) || '');
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

async function runtime(t, customize = {}) {
  const f = await fixture(t), calls = [], authorizations = [], integrityChecks = [], sourceOrder = [];
  const application = path.join(f.root, 'bundle', 'application');
  const context = { bundle: path.dirname(application), application, php: path.join(f.root, 'bundle', 'php', 'php.exe'),
    phpIni: path.join(application, 'private-runtime.ini'), sourceFingerprint: f.value.candidate.sourceFingerprint,
    manifest: { sourceRevision: f.value.candidate.sourceRevision, sourceFingerprint: f.value.candidate.sourceFingerprint,
      sourceHashes: Object.fromEntries(['workspace-import.php', 'workspace-verify.php', 'workspace-delta.php', 'workspace-control.php',
        'workspace-archive.php', 'database.php', 'delta.php', 'archive.php'].map(name => ['desktop/' + name, hash(name)])) } };
  const physical = { profile: path.join(f.registry.workspaceProfile, 'dashboard'), stagedContexts: new Map(),
    environmentFor: value => ({ DESKTOP_DASHBOARD_LOCAL: 'true', DESKTOP_DASHBOARD_DATABASE: value.database }),
    storageFor: value => path.join(f.registry.workspaceProfile, 'dashboard', 'generations', value.generation, 'storage'),
    deltaContext: async previous => { assert.deepEqual(previous, f.value.candidate.prepared); return context; },
    activate: async (_next, commit) => commit() };
  const workspace = { registry: f.registry, metadata: f.registry.metadata, physical, stopping: false,
    settings: async () => ({ rootPassword: 'synthetic-private-root', appPassword: 'synthetic-private-app' }),
    authorizeSource: async (value, options) => { sourceOrder.push('publication');authorizations.push({ value, options }); return { sequence: f.value.candidate.sourceSequence,
      sourceRevision: context.manifest.sourceRevision, sourceFingerprint: context.sourceFingerprint }; },
    nativeRun: async (php, args, options, input, timeout, limit) => {
      const call = { php, args, options, input, timeout, limit, script: scriptName(args) }; calls.push(call);
      if (customize.run) { const answer = await customize.run(call); if (answer !== undefined) return answer; }
      if (call.script === 'database.php') {
        if (args.at(-1) === 'stage-status') return JSON.stringify({ format: 1, database: options.env.DESKTOP_DASHBOARD_DATABASE, exists: false });
        assert.equal(args.at(-1), 'stage'); return '{}';
      }
      if (call.script === 'workspace-delta.php') {
        const header = JSON.parse(input.split('\n')[0]);
        if (header.action === 'capture') return JSON.stringify({ format: 2, kind: 'desktop-workspace-delta-capture',
          capture_database: options.env.DESKTOP_DASHBOARD_DATABASE, source_database: header.source_database,
          capture_id: hash('capture marker'), sequence: 0 });
        if (header.action.startsWith('discard-')) return JSON.stringify({ discarded: true,
          database: header.stage_database || header.capture_database });
      }
      throw Error('Unexpected native script ' + call.script);
    } };
  const adapter = new WorkspaceDeltaRuntime({ workspace, verifySource: async value => {
    sourceOrder.push('integrity');integrityChecks.push(value);return customize.verifySource?customize.verifySource(value):true;
  } });
  return { ...f, calls, authorizations, integrityChecks, sourceOrder, context, physical, workspace, adapter };
}

function nextRecord(previous, { advanceMembership = false } = {}) {
  const value = structuredClone(previous), candidate = value.candidate;
  candidate.generation = targetGeneration; candidate.database = 'fasakhansta_dashboard_stage_' + targetGeneration;
  candidate.snapshotId = '44444444-4444-4444-8444-444444444444'; candidate.receipt.snapshot_id = candidate.snapshotId;
  if(advanceMembership){
    value.manifest.revision++;value.manifest.signature=hash('new signed roster');candidate.manifestRevision=value.manifest.revision;
    candidate.receipt.workspace=prepared.workspaceBinding(value.manifest);candidate.receipt.coverage.manifest_revision=value.manifest.revision;
  }
  candidate.receipt.delta_source_binding = prepared.expectedBinding(previous.credential, previous.manifest);
  candidate.receipt.delta_source_identity = { snapshot_id: previous.candidate.snapshotId, schema_hash: previous.candidate.schemaHash,
    workspace: prepared.workspaceBinding(previous.manifest) };
  candidate.receipt.delta_replay = { format: 2, workspace_id: value.manifest.workspace_id, root_fingerprint: value.manifest.root_fingerprint,
    source_database: previous.candidate.database, sequence: 0, rows: 0, sha256: hash('all actor history') };
  candidate.receipt.delta_stage = { format: 2, kind: 'desktop-workspace-delta-stage', stage_id: hash('stage marker'),
    stage_database: candidate.database, source_database: previous.candidate.database,
    capture_database: 'fasakhansta_dashboard_stage_' + captureGeneration, capture_id: hash('capture marker'),
    device_id: value.manifest.workspace_id, actor_id: previous.candidate.prepared.actorId, snapshot_id: candidate.snapshotId,
    workspace: prepared.workspaceBinding(value.manifest), receipt_sha256: hash('opaque exact PHP receipt bytes') };
  candidate.prepared = prepared.create({ credential: value.credential, manifest: value.manifest,
    candidate: { ...candidate, mediaVerified: true }, refreshId: '55555555-5555-4555-8555-555555555555' });
  return value;
}

test('workspace reconstruction framing retains exact JSON integer and integral-float bytes', async () => {
  const raw = '{"format":2,"kind":"workspace-data-delta","tables":{"orders":{"delta":{"add":[{"id":9007199254740999,"amount":1.0}]}}}}';
  const delta = await readSnapshot(new Response(raw, { headers: { 'content-type': 'application/json' } }));
  const expected = { workspace_id: '11111111-1111-4111-8111-111111111111', root_fingerprint: hash('root'), revision: 3,
    roster_sha256: hash('roster'), manifest_sha256: hash('manifest'), token_hash: hash('bearer') };
  const framed = WorkspaceDeltaRuntime.input('reconstruct', { capture_database: 'fasakhansta_dashboard_stage_' + captureGeneration,
    capture_token: token, capture: { format: 2, sequence: 0 }, expected_binding: expected }, delta);
  const newline = framed.indexOf('\n');
  assert.equal(framed.slice(newline + 1), raw);
  assert.deepEqual(JSON.parse(framed.slice(0, newline)).expected_binding, expected);
  assert.equal(JSON.parse(framed.slice(0, newline)).kind, 'desktop-workspace-delta-input');
  assert.throws(() => WorkspaceDeltaRuntime.input('capture', {}, delta), { code: 'WORKSPACE_DELTA_RUNTIME_FRAME_INVALID' });
  assert.throws(() => WorkspaceDeltaRuntime.input('discard-stage', {}, delta), { code: 'WORKSPACE_DELTA_RUNTIME_FRAME_INVALID' });
});

test('oversized private headers are rejected before they can reach a PHP subprocess', () => {
  // Run the real 128 MiB boundary in a short-lived process so the parent test
  // runner does not retain large strings beside other native regression files.
  const modulePath = require.resolve('../src/workspace-delta-runtime.cjs');
  const code = `const {WorkspaceDeltaRuntime:R}=require(${JSON.stringify(modulePath)});try{R.input('capture',{oversized:'x'.repeat(128*1024**2)});process.exitCode=1;}catch(error){if(error.code!=='WORKSPACE_DELTA_RUNTIME_INPUT_TOO_LARGE')throw error;process.stdout.write('bounded');}`;
  assert.equal(execFileSync(process.execPath, ['--max-old-space-size=768', '-e', code], { encoding: 'utf8', timeout: 30000 }), 'bounded');
});

test('capture uses only the group entry and pinned six-field expectation with physical custody A', async t => {
  const f = await runtime(t), context = await f.adapter.context(f.value);
  const capture = await f.adapter.capture(context, f.value, captureGeneration, token);
  assert.equal(capture.capture_database, 'fasakhansta_dashboard_stage_' + captureGeneration);
  assert.equal(f.authorizations.length, 1);
  assert.deepEqual(f.sourceOrder, ['integrity', 'publication']);
  assert.deepEqual(f.authorizations[0].value.credential, f.value.credential);
  assert.deepEqual(f.calls.map(call => call.script), ['database.php', 'workspace-delta.php']);
  const call = f.calls[1], header = JSON.parse(call.input.trimEnd());
  assert.equal(header.actor_id, 101); assert.notEqual(header.actor_id, 202);
  assert.equal(header.device_id, f.registry.workspaceId);
  assert.equal(header.source_database, f.value.candidate.database);
  assert.deepEqual(header.expected_binding, prepared.expectedBinding(f.value.credential, f.value.manifest));
  assert.equal(Object.keys(header.expected_binding).length, 6);
  assert.equal(call.options.env.DESKTOP_DASHBOARD_WORKSPACE_LOCAL, 'true');
  assert.equal(call.options.env.DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE, f.value.candidate.database);
  assert.equal(call.limit, 128 * 1024 ** 2);
  assert.deepEqual(await f.registry.current(), f.value);
});

test('retained source integrity must pass before publication or any native command', async t => {
  const rejection=Object.assign(Error('The synthetic retained source changed.'),{code:'SYNTHETIC_SOURCE_CHANGED'});
  const f=await runtime(t,{verifySource:async()=>{throw rejection;}});
  await assert.rejects(f.adapter.context(f.value),error=>error===rejection);
  assert.deepEqual(f.sourceOrder,['integrity']);assert.deepEqual(f.authorizations,[]);assert.deepEqual(f.calls,[]);
  assert.deepEqual(await f.registry.current(),f.value);
});

test('a false source integrity receipt also fails before publication or native execution', async t => {
  const f=await runtime(t,{verifySource:async()=>false});
  await assert.rejects(f.adapter.context(f.value),{code:'WORKSPACE_DELTA_RUNTIME_SOURCE_CHANGED'});
  assert.deepEqual(f.sourceOrder,['integrity']);assert.deepEqual(f.authorizations,[]);assert.deepEqual(f.calls,[]);
});

test('complete group staging preserves the raw delta and verifies the target manifest before retaining private handoff proof', async t => {
  let f, target;
  const wire=[];
  f=await runtime(t,{run:async call=>{
    if(call.script==='workspace-delta.php'){
      const newline=call.input.indexOf('\n'),header=JSON.parse(call.input.slice(0,newline));
      if(header.action==='capture')return JSON.stringify({format:2,kind:'desktop-workspace-delta-capture',
        capture_database:call.options.env.DESKTOP_DASHBOARD_DATABASE,source_database:f.value.candidate.database,
        capture_id:target.candidate.receipt.delta_stage.capture_id,sequence:0,replay:target.candidate.receipt.delta_replay});
      if(header.action==='reconstruct'){
        wire.push({header,raw:call.input.slice(newline+1)});
        const imported=structuredClone(target.candidate.receipt);delete imported.verified;return JSON.stringify(imported);
      }
    }
    if(call.script==='workspace-verify.php'){
      const input=JSON.parse(call.input);wire.push({verify:input});
      assert.equal(input.action,'preverify');assert.equal(input.receipt.verified,undefined);
      return JSON.stringify({...target.candidate.receipt,media_seal:{format:1,manifest_sha256:hash('empty media manifest'),files:[]},
        archive_handoff:{format:2,kind:'desktop-workspace-preverified-handoff',signature:hash('private PHP handoff'),
          payload:{database:target.candidate.database,source_database:f.value.candidate.database,sequence:0,
            source_identity:target.candidate.receipt.delta_source_identity,replay:target.candidate.receipt.delta_replay}}});
    }
  }});
  target=nextRecord(f.value,{advanceMembership:true});
  await f.registry.directory(path.join(f.physical.storageFor(f.value.candidate.prepared),'app/public'),true);
  const context=await f.adapter.context(f.value),capture=await f.adapter.capture(context,f.value,captureGeneration,token);
  const raw='{"format":2,"kind":"workspace-data-delta","snapshot_id":'+JSON.stringify(target.candidate.snapshotId)
    +',"schema_hash":'+JSON.stringify(target.candidate.schemaHash)+',"media":[],"tables":{"orders":{"delta":{"add":[{"id":9007199254740999,"amount":1.0}]}}}}';
  const delta=await readSnapshot(new Response(raw,{headers:{'content-type':'application/json'}}));
  const candidate=await f.adapter.stage(context,f.value,capture,delta,target.manifest,targetGeneration,token);
  assert.deepEqual(f.calls.map(call=>call.script),['database.php','workspace-delta.php','database.php','workspace-delta.php','workspace-verify.php']);
  assert.equal(wire[0].raw,raw);
  assert.deepEqual(wire[0].header.expected_binding,prepared.expectedBinding(target.credential,target.manifest));
  assert.equal(wire[0].header.expected_binding.revision,2);
  assert.deepEqual(wire[1].verify.expected_binding,wire[0].header.expected_binding);
  assert.equal(wire[1].verify.handoff_token,token);
  assert.equal(candidate.mediaVerified,true);assert.equal(candidate.receipt.actor_id,101);
  assert.deepEqual(candidate.receipt.delta_source_binding,prepared.expectedBinding(f.value.credential,f.value.manifest));
  assert.equal(candidate.receipt.delta_source_binding.revision,1);
  assert.equal(candidate.manifestRevision,2);
  assert.notEqual(prepared.deltaReceipt(candidate.receipt,target.manifest,candidate,target.credential),null);
  assert.equal(f.adapter.stages.get(targetGeneration).candidate,candidate);
  assert.equal(f.physical.stagedContexts.get(targetGeneration),context);
  assert.deepEqual(await f.registry.current(),f.value);
});

test('cancellation prevents source approval, database creation and all new capture calls', async t => {
  const f = await runtime(t); f.adapter.stop();
  await assert.rejects(f.adapter.context(f.value), { code: 'WORKSPACE_DELTA_RUNTIME_CANCELLED' });
  await assert.rejects(f.adapter.capture(f.context, f.value, captureGeneration, token), { code: 'WORKSPACE_DELTA_RUNTIME_CANCELLED' });
  assert.deepEqual(f.calls, []); assert.deepEqual(f.authorizations, []);
  assert.deepEqual(await f.registry.current(), f.value);
});

test('cancellation during database creation cannot continue into capture or replace the active pointer', async t => {
  let f;
  f = await runtime(t, { run: async call => { if (call.script === 'database.php') { f.adapter.stop(); return '{}'; } } });
  await assert.rejects(f.adapter.capture(f.context, f.value, captureGeneration, token), { code: 'WORKSPACE_DELTA_RUNTIME_CANCELLED' });
  assert.deepEqual(f.calls.map(call => call.script), ['database.php']);
  assert.deepEqual(await f.registry.current(), f.value);
});

for (const name of ['workspace-import.php', 'workspace-verify.php', 'workspace-delta.php', 'workspace-control.php', 'workspace-archive.php'])
  test('unverified ' + name + ' cannot enter the shared delta context even when v1 scripts are present', async t => {
    const f = await runtime(t); delete f.context.manifest.sourceHashes['desktop/' + name];
    await assert.rejects(f.adapter.context(f.value), error => ['WORKSPACE_PROGRAMS_UNVERIFIED', 'WORKSPACE_DELTA_RUNTIME_PROGRAM_UNVERIFIED'].includes(error.code));
    assert.deepEqual(f.calls, []); assert.deepEqual(f.authorizations, []);
  });

test('a publication sequence rollback cannot create an inactive database', async t => {
  const f = await runtime(t);
  f.workspace.authorizeSource = async () => ({ sequence: f.value.candidate.sourceSequence - 1,
    sourceRevision: f.context.manifest.sourceRevision, sourceFingerprint: f.context.sourceFingerprint });
  await assert.rejects(f.adapter.context(f.value), { code: 'WORKSPACE_DELTA_RUNTIME_PUBLICATION_REJECTED' });
  assert.deepEqual(f.calls, []);
});

test('finalize refuses missing or substituted private stages before invoking any script', async t => {
  const f = await runtime(t), record = nextRecord(f.value), candidate = record.candidate;
  const intent = { id: candidate.prepared.refreshId, token, previous: f.value };
  await assert.rejects(f.adapter.finalize(candidate, intent, record), { code: 'WORKSPACE_DELTA_RUNTIME_STAGE_MISSING' });
  const stage = await f.adapter.describe(f.context, targetGeneration, f.value.candidate.database);
  f.adapter.stages.set(targetGeneration, { ...stage, token, candidate: { ...candidate, database: f.value.candidate.database } });
  await assert.rejects(f.adapter.finalize(candidate, intent, record), { code: 'WORKSPACE_DELTA_RUNTIME_STAGE_MISSING' });
  f.adapter.stages.set(targetGeneration, { ...stage, token, candidate });
  const foreign = structuredClone(record); foreign.candidate.receipt.workspace.root_fingerprint = hash('foreign');
  await assert.rejects(f.adapter.finalize(candidate, intent, foreign), { code: 'WORKSPACE_DELTA_RUNTIME_STAGE_MISSING' });
  assert.deepEqual(f.calls, []);
});

test('once fenced, finalization uses the group archive and settles without the cancelled read signal', async t => {
  const recordHolder = {}, f = await runtime(t, { run: async call => {
    if (call.script === 'workspace-archive.php') {
      const body = JSON.parse(call.input); recordHolder.body = body;
      return JSON.stringify({ archived_commands: body.receipt.delta_replay.rows });
    }
  } });
  const record = nextRecord(f.value), candidate = { ...record.candidate, mediaSeal: { format: 1 },
    archiveHandoff: { format: 2, kind: 'desktop-workspace-preverified-handoff', signature: hash('private proof') } };
  const stage = await f.adapter.describe(f.context, targetGeneration, f.value.candidate.database);
  f.adapter.stages.set(targetGeneration, { ...stage, token, candidate });
  f.adapter.stop();
  await f.adapter.finalize(candidate, { id: record.candidate.prepared.refreshId, token: hash('refresh token'), previous: f.value }, record);
  assert.deepEqual(f.calls.map(call => call.script), ['workspace-archive.php']);
  assert.equal(f.calls[0].options.signal, undefined);
  assert.deepEqual(recordHolder.body.expected_binding, prepared.expectedBinding(record.credential, record.manifest));
  assert.equal(recordHolder.body.source, f.value.candidate.database);
  assert.deepEqual(recordHolder.body.source_identity, record.candidate.receipt.delta_source_identity);
  assert.equal(f.adapter.stages.get(targetGeneration).finalized, digest(record));
});

test('cleanup never sends discard or removes the generation named by the current prepared pointer', async t => {
  const f = await runtime(t), id = f.value.candidate.generation;
  const entry = await f.adapter.describe(f.context, id, f.value.candidate.database);
  await f.registry.directory(entry.root, true); await fs.writeFile(path.join(entry.root, 'accepted.marker'), 'preserve');
  f.adapter.captures.set(id, { ...entry, token, previous: f.value, capture: null });
  assert.equal(await f.adapter.release({ previous: f.value, token, captureGeneration: id }), true);
  assert.equal(await fs.readFile(path.join(entry.root, 'accepted.marker'), 'utf8'), 'preserve');
  assert.deepEqual(f.calls, []); assert.deepEqual(await f.registry.current(), f.value);
});

test('cleanup serializes with activation and invalidates a discarded private proof before a queued pointer commit', async t => {
  const statusEntered = deferred(), finishStatus = deferred(), activationEntered = deferred(), finishActivation = deferred();
  const f = await runtime(t, { run: async call => {
    if (call.script === 'database.php' && call.args.at(-1) === 'stage-status') {
      if (call.options.env.DESKTOP_DASHBOARD_DATABASE.endsWith(targetGeneration)) {
        statusEntered.resolve(); await finishStatus.promise;
        return JSON.stringify({ format: 1, database: call.options.env.DESKTOP_DASHBOARD_DATABASE, exists: true });
      }
    }
  } });
  const record = nextRecord(f.value), ticket = await f.registry.stage(record);
  const stage = await f.adapter.describe(f.context, targetGeneration, f.value.candidate.database);
  const capture = await f.adapter.describe(f.context, captureGeneration, f.value.candidate.database);
  for (const entry of [stage, capture]) await f.registry.directory(entry.root, true);
  f.adapter.stages.set(targetGeneration, { ...stage, token, previous: f.value, candidate: record.candidate,
    receipt: record.candidate.receipt, finalized: digest(record) });
  f.adapter.captures.set(captureGeneration, { ...capture, token, previous: f.value, capture: null });
  f.physical.activate = async (_next, commit) => { activationEntered.resolve(); await finishActivation.promise; return commit(); };
  const activation = f.adapter.activate(record, ticket).then(() => null, error => error);
  await activationEntered.promise;
  const cleanup = f.adapter.release({ previous: f.value, token, candidateGeneration: targetGeneration, captureGeneration });
  await statusEntered.promise;
  finishActivation.resolve();
  assert.deepEqual(await f.registry.current(), f.value);
  finishStatus.resolve(); await cleanup;
  const error = await activation;
  assert.equal(error?.code, 'WORKSPACE_CANDIDATE_REJECTED');
  assert.deepEqual(await f.registry.current(), f.value);
  assert.equal(f.adapter.stages.has(targetGeneration), false);
  const discarded = f.calls.filter(call => call.script === 'workspace-delta.php').map(call => JSON.parse(call.input.trimEnd()));
  assert.deepEqual(discarded.map(header => header.action), ['discard-stage']);
  assert.equal(discarded[0].active_database, f.value.candidate.database);
  assert.notEqual(discarded[0].stage_database, f.value.candidate.database);
});
