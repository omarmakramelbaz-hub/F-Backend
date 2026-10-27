'use strict';
const path = require('node:path');
const { GenerationStore } = require('../../src/dashboard-generation.cjs');
const { WorkspaceMode } = require('../../src/workspace-mode.cjs');
const { WorkspaceConnected } = require('../../src/workspace-connected.cjs');
const { fixture } = require('./workspace-fixture.cjs');
async function modeFixture(t) {
  const f = await fixture(t), calls = [], metadata = new GenerationStore(path.join(f.root, 'mode-state'), f.safeStorage);
  let current = structuredClone(f.value.candidate.prepared), local = true, held = null, remotePending = 0, printPending = 0,
    localPending = 0, probeActor = null, generations = 0, loseBeginReply = false, verifyReturn = false;
  const workspace = { metadata, physical: { origin: 'http://127.0.0.1:39451', token: 'native-local-token' },
    connection: async () => structuredClone(current), isPrepared: async () => true,
    start: async () => { calls.push({ action: 'start' }); return { origin: workspace.physical.origin, token: workspace.physical.token }; },
    async control(value) {
      calls.push(structuredClone(value));
      const result = () => ({ format: 1, device_id: current.deviceId, actor_id: current.actorId, snapshot_id: current.snapshotId,
        schema_hash: current.schemaHash, held: Boolean(held), refresh_id: held?.id || null, fenced_sequence: 17 });
      if (value.action === 'refresh-begin') {
        const persisted = await metadata.read('workspace-return');
        if (persisted?.id !== value.refresh_id || persisted.token !== value.token) throw Error('The native capability was not durable first.');
        if (held && (held.id !== value.refresh_id || held.token !== value.token)) throw Error('foreign fence');
        held = { id: value.refresh_id, token: value.token };
        if (loseBeginReply) { loseBeginReply = false; throw Error('lost reply after accepted fence'); }
        return result();
      }
      if (value.action === 'refresh-status') {
        if (!held) return { exists: false, held: false };
        if (held.id !== value.refresh_id || held.token !== value.token) throw Error('foreign fence');
        return { exists: true, ...result() };
      }
      if (value.action === 'refresh-cancel') {
        if (!held || held.id !== value.refresh_id || held.token !== value.token) throw Error('foreign fence');
        held = null; return result();
      }
      if (value.action === 'pending') return { commands: [], counts: { pending: localPending, conflicts: 0 } };
      if (value.action === 'print-outcomes') return { outcomes: [], pending: printPending };
      throw Error('Unexpected physical control.');
    } };
  const actor = id => ({ authenticated: async () => true, authority: async () => {
    const member = f.value.manifest.members.find(m => m.actor_id === id);
    return { protocol: 2, workspaceId: current.deviceId, actorId: id, role: member.role,
      manifestRevision: current.workspace.manifest_revision, grant: member.grant, workspace: structuredClone(current.workspace) };
  } });
  const remoteState = { async assertClean() { if (remotePending) throw Error('Unknown remote A outcome.'); }, async browse() { calls.push({ action: 'remote-browse' }); },
    async snapshot(work) { if (remotePending) throw Error('Unknown remote A outcome.'); calls.push({ action: 'snapshot-start' }); const value = await work(); calls.push({ action: 'snapshot-finish' }); return value; } };
  const guard = async ({ operation }) => {
    const outbox = await workspace.control({ action: 'pending' }), prints = await workspace.control({ action: 'print-outcomes' });
    return remotePending === 0 && prints.pending === 0 && (!operation.startsWith('central') || outbox.counts.pending === 0);
  };
  const probe = async (root, effective) => ({ protocol: 2, workspace_id: root.deviceId, custodian_actor_id: root.actorId,
    actor_id: probeActor ?? effective.actorId, role: (probeActor ?? effective.actorId) === 101 ? 'admin' : 'vendor',
    manifest_revision: root.workspace.manifest_revision, root_fingerprint: root.workspace.root_fingerprint, connected_request_protocol: 2 });
  const generation = { run: async () => { generations++; calls.push({ action: 'generation' }); return workspace.connection(); } };
  const view = { current: () => ({ local }), canRefresh: async () => true, open: async () => { calls.push({ action: 'view-open' }); },
    reveal() { calls.push({ action: 'view-reveal' }); }, async refresh(work) { calls.push({ action: 'view-refresh' }); return work(); },
    async switchTo(target, work = async () => {}) { calls.push({ action: 'view-switch', target }); const result = await work(); local = Boolean(target.localToken); return result; },
    async reauthenticate(_origin, work) { return work(); } };
  const remoteAttempts = { async recover() { if (remotePending) throw Error('Unknown remote A outcome.'); } };
  const modes = new Map(), connected = new Map();
  const createMode = id => { const instance = new WorkspaceMode({ workspace, actor: actor(id), generations: generation, view, probe, remoteState, remoteAttempts, guard,
    verifyReturn: async () => verifyReturn }); modes.set(id, instance); return instance; };
  const createConnected = id => { const mode = modes.get(id) || createMode(id); const instance = new WorkspaceConnected({ workspace, actor: actor(id), view: () => view,
    mode: () => mode, generations: () => generation, remoteState, remoteAttempts, probe,
    synchronize: async () => { calls.push({ action: 'synchronize' }); if (localPending) throw Error('Unconfirmed local A commands.'); }, guard,
    verifyReturn: async () => verifyReturn }); connected.set(id, instance); return instance; };
  return { ...f, metadata, calls, workspace, actor, remoteState, guard, probe, generation, view, createMode, createConnected,
    get current() { return structuredClone(current); }, setCurrent(value) { current = structuredClone(value); }, get held() { return held; }, get generations() { return generations; },
    setLocal(value) { local = value; }, setPrintPending(value) { printPending = value; }, setRemotePending(value) { remotePending = value; },
    setLocalPending(value) { localPending = value; }, setProbeActor(value) { probeActor = value; }, setVerifyReturn(value) { verifyReturn = value; },
    setLoseBeginReply(value) { loseBeginReply = value; } };
}
module.exports = { modeFixture };
