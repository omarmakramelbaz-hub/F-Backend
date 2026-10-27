'use strict';
const { digest } = require('./workspace-profile.cjs');
const { rootBinding, actorBinding, serverSession, same } = require('./workspace-mode.cjs');
const error = code => Object.assign(Error('تعذر تأكيد وضع الداشبورد المتصلة؛ سجلات الجهاز محفوظة.'), { code: 'WORKSPACE_CONNECTED_' + code });
const need = (value, code) => { if (!value) throw error(code); };
const CENTRAL_KEY = 'workspace-central-mode';
/** The central latch belongs to one physical workspace and one authenticated actor. */
class WorkspaceConnected {
  constructor({ workspace, actor, view, mode, generations, remoteState, remoteAttempts, probe, synchronize, guard, verifyReturn, onState = () => {} }) {
    need(workspace?.metadata && typeof view === 'function' && typeof mode === 'function' && typeof generations === 'function'
      && typeof probe === 'function' && typeof synchronize === 'function' && typeof guard === 'function' && typeof verifyReturn === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, actor, view, mode, generations, remoteState, remoteAttempts, probe, synchronize, guard, verifyReturn, onState });
    this.metadata = workspace.metadata; this.transitioning = false;
  }
  async binding() {
    const current = await this.workspace.connection(), root = rootBinding(current);
    return { format: 2, root, actor: await actorBinding(this.actor, root) };
  }
  async guarded(operation, binding) { need(await this.guard({ operation, root: binding.root, actor: binding.actor }) === true, 'GLOBAL_WORK_UNSETTLED'); }
  record(value) {
    need(value?.format === 2 && value.kind === 'desktop-workspace-central' && ['central', 'returning'].includes(value.phase)
      && Object.keys(value).sort().join(',') === 'binding,format,kind,phase' && value.binding?.format === 2
      && Object.keys(value.binding).sort().join(',') === 'actor,format,root' && value.binding.root?.format === 2
      && ['actorId,grant,manifestRevision,role', 'actorId,grant,manifestRevision,onlineOnly,role'].includes(Object.keys(value.binding.actor || {}).sort().join(','))
      && (value.binding.actor.onlineOnly === undefined || value.binding.actor.onlineOnly === true)
      && Number.isSafeInteger(value.binding.actor?.actorId) && value.binding.actor.actorId > 0
      && ['admin', 'vendor', 'resturant_owner'].includes(value.binding.actor.role)
      && Number.isSafeInteger(value.binding.actor.manifestRevision)
      && (value.binding.actor.onlineOnly === true ? value.binding.actor.manifestRevision >= value.binding.root.manifestRevision : value.binding.actor.manifestRevision === value.binding.root.manifestRevision)
      && /^[a-f0-9]{64}$/.test(value.binding.actor.grant || ''), 'LATCH_INVALID');
    return value;
  }
  async selection({ recover = false } = {}) {
    const record = await this.metadata.read(CENTRAL_KEY); if (!record) return null;
    this.record(record); const current = await this.binding();
    if (!same(record.binding.root, current.root)) {
      need(recover && record.phase === 'returning' && await this.verifyReturn({ previousRootBinding: record.binding.root,
        currentRootBinding: current.root, currentPrepared: await this.workspace.connection() }) === true, 'LATCH_FOREIGN');
      await this.remoteState.assertClean(); await this.guarded('recover-return', current);
      // The new union DB and all global fences were proven. Finish an accepted
      // return without adopting the old actor's direct-write authority.
      await this.metadata.write(CENTRAL_KEY, null); return null;
    }
    return { record, current, foreignActor: record.binding.actor.actorId !== current.actor.actorId };
  }
  async selected(options) { return await this.selection(options) !== null; }
  async deferRefresh() { return this.transitioning || await this.selected(); }
  async browse() { if (this.actor.onlineOnly === true || await this.selected()) await this.remoteState.browse(); }
  admitOnlineOnly() { return this.mode().serialize('workspace-online-only-admission', () => this.admitOnlineLocked()); }
  async admitOnlineLocked() {
    need(this.actor.onlineOnly === true, 'ONLINE_AUTHORITY_REQUIRED');
    const selected = await this.selection(), current = await this.binding();
    if (selected && !selected.foreignActor) { await this.sameActor(current); return { central: true, onlineOnly: true }; }
    await this.remoteAttempts?.recover(); await this.guarded('central-online-admission', current);
    // A settled server-to-server account change keeps the one physical DB
    // fenced. It changes only the CURRENT server actor, never any historical
    // request/grant, and performs no source/database replacement before view.
    await this.mode().hold(); await this.sameActor(current);
    need(same(current, await this.binding()), 'BINDING_CHANGED');
    if (selected) {
      const name = 'workspace-central-history/' + digest(selected.record), old = await this.metadata.read(name);
      need(old === null || same(old, selected.record), 'HISTORY_INVALID');
      if (old === null) await this.metadata.write(name, selected.record);
    }
    await this.remoteState.browse(); await this.writeLatch('central', current);
    if (this.view().current().local) await this.view().switchTo({ origin: current.root.serverOrigin, localToken: '' });
    this.onState(); return { central: true, onlineOnly: true };
  }
  async sameActor(binding) {
    let current = binding;
    if (!current?.root || !current?.actor) {
      current = await this.binding();
      if (binding) need(same(rootBinding(binding), current.root), 'BINDING_CHANGED');
    }
    const proof = serverSession(await this.probe(await this.workspace.connection(), current.actor), current.root, current.actor);
    need(proof.connected_request_protocol === 2, 'SERVER_PROTOCOL_UNSUPPORTED'); return current;
  }
  async authorizeDirect(details) {
    let selected = await this.selection();
    if (this.actor.onlineOnly === true && (!selected || selected.foreignActor)) { await this.admitOnlineOnly(); selected = await this.selection(); }
    if (!selected) return false;
    need(!selected.foreignActor, 'OTHER_ACTOR');
    const method = String(details.method || '').toUpperCase(), target = new URL(details.url);
    need(method !== 'HEAD' && target.origin === selected.current.root.serverOrigin && !target.username && !target.password, 'REQUEST_FOREIGN');
    await this.guarded('central-write', selected.current);
    // The exact sign-in exception is checked again by the server middleware
    // against the resulting original PHP session. Other business writes prove B.
    if (!(method === 'POST' && target.pathname === '/admin/signin' && !target.search && !target.hash)) await this.sameActor(selected.current);
    return selected.current;
  }
  async idle() { need(!this.view().canRefresh || await this.view().canRefresh(), 'VIEW_BUSY'); }
  async writeLatch(phase, binding) { await this.metadata.write(CENTRAL_KEY, { format: 2, kind: 'desktop-workspace-central', phase, binding }); }
  reauthenticate() {
    return this.mode().serialize('workspace-server-login', async () => {
      need(await this.workspace.isPrepared(), 'NOT_PREPARED'); await this.idle();
      const selected = await this.selection(); need(!selected?.foreignActor, 'OTHER_ACTOR');
      const original = await this.binding(); this.transitioning = true;
      try {
        await this.view().reauthenticate(original.root.serverOrigin, async () => {
          need(same(original, await this.binding()), 'BINDING_CHANGED'); await this.sameActor(original);
          need(same(original, await this.binding()), 'BINDING_CHANGED');
        }); return { authenticated: true };
      } finally { this.transitioning = false; }
    });
  }
  open() {
    return this.mode().serialize('workspace-full-central', async () => {
      if (!(await this.workspace.isPrepared())) { await this.view().open(); return { prepared: false }; }
      if (this.actor.onlineOnly === true) return this.admitOnlineLocked();
      const selected = await this.selection(); need(!selected?.foreignActor, 'OTHER_ACTOR');
      if (selected && !this.view().current().local) { await this.sameActor(selected.current); this.view().reveal?.(); return { central: true }; }
      await this.idle(); this.transitioning = true;
      try {
        const original = await this.binding();
        await this.view().switchTo({ origin: original.root.serverOrigin, localToken: '' }, async () => {
          await this.remoteAttempts?.recover();
          if (this.view().current().local) {
            // Upload the original shared queue first. A pending command may be
            // drained under its captured actor; it is never a reason to adopt
            // that actor's central session or to replace its DB prematurely.
            await this.synchronize(); await this.guarded('central-after-sync', await this.binding());
            await this.mode().cancelHold(); await this.remoteState.snapshot(() => this.generations().run());
          } else await this.guarded('central', original);
          await this.mode().hold(); const current = await this.sameActor();
          await this.writeLatch('central', current);
        }); this.onState(); return { central: true };
      } catch (cause) {
        if (this.view().current().local) {
          await this.remoteState.assertClean(); await this.guarded('central-failure', await this.binding());
          await this.mode().cancelHold(); await this.metadata.write(CENTRAL_KEY, null);
        }
        else await this.mode().hold(); throw cause;
      } finally { this.transitioning = false; }
    });
  }
  local() {
    return this.mode().serialize('workspace-full-central-return', async () => {
      need(await this.workspace.isPrepared(), 'NOT_PREPARED');
      need(this.actor.onlineOnly !== true || typeof this.actor.offlineAuthenticated === 'function' && await this.actor.offlineAuthenticated() === true, 'LOCAL_PASSWORD_REQUIRED');
      if (this.view().current().local && !(await this.selected())) { this.view().reveal?.(); return { local: true }; }
      await this.idle(); const previous = await this.metadata.read(CENTRAL_KEY); if (previous) this.record(previous); this.transitioning = true;
      try {
        // B may restore a fresh union after A's central session ONLY after every
        // original actor's global remote/print uncertainty has settled.
        const original = await this.sameActor(); await this.remoteAttempts?.recover(); await this.guarded('central-return', original);
        const local = await this.workspace.start();
        await this.view().switchTo({ origin: local.origin, localToken: local.token }, async () => {
          await this.remoteAttempts?.recover(); await this.guarded('central-return-handoff', await this.binding());
          await this.writeLatch('returning', await this.binding()); await this.mode().cancelHold();
          await this.remoteState.snapshot(() => this.generations().run());
          await this.sameActor(); await this.metadata.write(CENTRAL_KEY, null);
        }); this.onState(); return { local: true };
      } catch (cause) {
        if (!this.view().current().local) {
          await this.mode().hold(); const current = await this.binding();
          if (previous && same(previous.binding.root, current.root)) await this.metadata.write(CENTRAL_KEY, previous);
          else await this.writeLatch('central', current);
        }
        throw cause;
      } finally { this.transitioning = false; }
    });
  }
}
module.exports = { WorkspaceConnected, CENTRAL_KEY };
