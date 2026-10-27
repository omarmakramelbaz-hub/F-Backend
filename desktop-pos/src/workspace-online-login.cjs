'use strict';
const crypto = require('node:crypto'), path = require('node:path');
const { originalSignin, installCookies } = require('./account-login.cjs');
const { GenerationStore } = require('./dashboard-generation.cjs');
const { manifest } = require('./workspace-profile.cjs');
const { workspaceBinding } = require('./workspace-prepared.cjs');
const signout = require('./account-signout.cjs');
const { withDeadline } = require('./native-deadline.cjs');
const { WorkspaceActor } = require('./workspace-actor.cjs');
const { authenticateServerWithLease } = require('./workspace-password-lease.cjs');
const failed = () => Object.assign(Error('تعذر تأكيد جلسة حساب السيرفر.'), { code: 'WORKSPACE_ONLINE_LOGIN_FAILED' });
/**
 * Original server password login without consulting/starting a retained v1 DB.
 * A fresh server grant remains private, temporary authority. It never creates
 * offline membership or changes the Registry's source/data/prepared pointer.
 */
class WorkspaceOnlineLogin {
  constructor({ workspace, http, createSession, stopped = () => false, onLocked, markServerView }) {
    if (!workspace?.registry || !http || typeof createSession !== 'function' || typeof onLocked !== 'function' || typeof markServerView !== 'function') throw failed();
    Object.assign(this, { workspace, registry: workspace.registry, http, createSession, stopped, onLocked, markServerView });
    this.controllers = new Set(); this.leases = new Set(); this.cancelled = false;
  }
  check(signal) { if (this.cancelled || this.stopped() || signal?.aborted) throw Object.assign(failed(), { code: 'ACCOUNT_LOGIN_CANCELLED' }); }
  async authenticate(input, { signal } = {}) {
    this.discardCredentials();
    const identifier = input.email, remember = input.remember;
    const credentials = { email: identifier, password: input.password, remember }, controller = new AbortController();
    this.controllers.add(controller);
    const abort = () => controller.abort(); signal?.addEventListener('abort', abort, { once: true });
    if (signal?.aborted) controller.abort();
    const joined = controller.signal; let retained = false, lease;
    const browser = this.createSession('go-erp-workspace-server-auth-' + crypto.randomUUID());
    try {
      this.check(joined); const active = await this.registry.current(); if (!active) throw failed();
      const authenticated = await authenticateServerWithLease({ browser, origin: active.credential.serverOrigin, ...credentials, signal: joined });
      const actor = authenticated.actor; lease = authenticated.lease; this.leases.add(lease); this.check(joined);
      const profile = path.join(this.registry.root, 'workspace-actors', this.registry.workspaceId, String(actor.actorId));
      await this.registry.directory(profile, true); this.check(joined);
      const descriptor = { protocol: 2, onlineOnly: true, workspaceId: this.registry.workspaceId, actorId: actor.actorId, role: actor.role,
        id: 'workspace-' + this.registry.workspaceId + '-' + actor.actorId, serverOrigin: active.credential.serverOrigin,
        profile, workspaceProfile: this.registry.workspaceProfile, partition: 'persist:go-erp-workspace-' + this.registry.workspaceId + '-' + actor.actorId };
      const metadata = new GenerationStore(profile, this.workspace.safeStorage), target = this.createSession(descriptor.partition);
      // The shared root is fenced/marked before any business document can use
      // this ordinary online cookie; old per-account runtimes remain archives.
      await this.markServerView({ actor, descriptor, active }); this.check(joined);
      const undo = await installCookies(browser, target, active.credential.serverOrigin, false, { active: () => !this.cancelled && !this.stopped() && !joined.aborted });
      let accepted = false, authority, resolveAuthority, rejectAuthority;
      const ready = new Promise((yes, no) => { resolveAuthority = yes; rejectAuthority = no; }); ready.catch(() => {});
      const owner = this;
      const runtime = new WorkspaceActor({ workspace: this.workspace, descriptor,
        serverAuthenticated: async () => accepted && !owner.cancelled && !owner.stopped(),
        serverAuthority: async () => { owner.check(); return authority || await ready; } });
      try {
        await signout.signedIn(runtime, { active: () => !this.cancelled && !this.stopped() && !joined.aborted }); this.check(joined);
        // Server success after a local-password refusal is evidence that the
        // old offline verifier is stale. It stays blocked until verified data
        // catches up; the server-only session does not authorize old passwords.
        const old = await this.registry.member(actor.actorId);
        if (old && input.cachedPasswordRejected === true) await metadata.write('workspace-server-rejection', {
          format: 2, kind: 'workspace-server-rejection', actorId: actor.actorId, manifestRevision: active.manifest.revision,
          passwordBinding: old.password_binding, reason: 'cached-password-stale' });
        accepted = true;
      } catch (error) { await undo(); throw error; }
      let job;
      const afterView = () => job ||= (async () => {
        let receivedFresh = false;
        try {
          this.check(); const received = await this.http.root('manifest', undefined, active.credential, { signal: this.lifeSignal() });
          receivedFresh = true;
          const fresh = manifest(received, this.registry.workspaceId), state = await this.registry.read();
          if (fresh.root_fingerprint !== active.manifest.root_fingerprint || fresh.revision < state.memberHighWater.revision) throw failed();
          const member = fresh.members.find(m => m.actor_id === actor.actorId);
          if (!member || member.role !== actor.role) throw failed();
          authority = { protocol: 2, workspaceId: this.registry.workspaceId, actorId: actor.actorId, role: actor.role,
            manifestRevision: fresh.revision, grant: member.grant, workspace: workspaceBinding(fresh) };
          this.check(); resolveAuthority(authority); return { authorityReady: true };
        } catch (error) {
          rejectAuthority(error);
          if (receivedFresh || [401, 403].includes(error.status)) { accepted = false; await signout.signedOut(runtime); await this.onLocked({ actorId: actor.actorId, reason: 'server-authority' }); }
          throw error;
        }
      })();
      const discardCredentials = () => { lease?.dispose(); this.leases.delete(lease); controller.abort(); this.controllers.delete(controller); };
      const authenticateLocal = async () => {
        this.check(joined); const email = identifier;
        const temporary = this.createSession('go-erp-workspace-local-bridge-' + crypto.randomUUID());
        try {
          return await lease.consume(async password => {
            this.check(joined); const current = await this.registry.current(), member = await this.registry.member(actor.actorId);
            if (!current || !member || member.role !== actor.role || current.manifest.root_fingerprint !== active.manifest.root_fingerprint) throw failed();
            const local = await this.workspace.start(); this.check(joined);
            const actual = await originalSignin({ browser: temporary, origin: local.origin, localToken: local.token, email, password, remember, signal: joined });
            if (actual.actorId !== actor.actorId || actual.role !== actor.role) throw failed();
            const proof = await this.http.localSession({ browser: temporary, local, active: current }, { signal: joined }); this.check(joined);
            const latest = await this.registry.current();
            if (proof.actor_id !== actor.actorId || proof.role !== actor.role || latest.manifest.revision !== current.manifest.revision
              || latest.manifest.roster_sha256 !== current.manifest.roster_sha256) throw failed();
            await this.workspace.acceptSession(actual, proof);
            const undoLocal = await installCookies(temporary, target, local.origin, true, { active: () => !this.cancelled && !this.stopped() && !joined.aborted });
            try {
              await signout.signedIn(runtime, { active: () => !this.cancelled && !this.stopped() && !joined.aborted });
              await runtime.metadata.write('workspace-server-rejection', null);
              await runtime.metadata.write('workspace-login', { format: 2, kind: 'workspace-login', actorId: actor.actorId,
                manifestRevision: current.manifest.revision, remember });
              await this.workspace.metadata.write('workspace-last-login', { format: 2, actorId: actor.actorId, remember });
              this.check(joined); await runtime.promote(); return true;
            } catch (error) { this.workspace.revokeSession(actor.actorId); await undoLocal(); throw error; }
          });
        } finally {
          discardCredentials();
          try { await withDeadline(() => temporary.clearStorageData?.(), 5000, 'تعذر إغلاق جلسة الدخول المؤقتة.'); } catch {}
        }
      };
      // The request's cancellation scope ends here. The independent native lease
      // remains tied to logout/account change/shutdown until its one local POST.
      signal?.removeEventListener('abort', abort); retained = true;
      return { account: descriptor, runtime, get local() { return !runtime.onlineOnly; }, get onlineOnly() { return runtime.onlineOnly; },
        get origin() { return runtime.onlineOnly ? active.credential.serverOrigin : owner.workspace.physical.origin; },
        get localToken() { return runtime.onlineOnly ? '' : owner.workspace.physical.token; }, actor,
        afterView, refreshAuthority: () => { job = null; return afterView(); }, discardCredentials,
        localPasswordAvailable: () => lease.valid(), authenticateLocal };

    } finally {
      credentials.password = ''; signal?.removeEventListener('abort', abort);
      if (!retained) { lease?.dispose(); this.leases.delete(lease); controller.abort(); this.controllers.delete(controller); }
      try { await withDeadline(() => browser.clearStorageData?.(), 5000, 'تعذر إغلاق جلسة الدخول المؤقتة.'); } catch {}
    }
  }
  lifeSignal() { this.background ||= new AbortController(); return this.background.signal; }
  discardCredentials() { for (const lease of this.leases) lease.dispose(); this.leases.clear(); for (const controller of this.controllers) controller.abort(); this.controllers.clear(); }
  cancel() { this.discardCredentials(); this.cancelled = true; this.background?.abort(); for (const controller of this.controllers) controller.abort(); }
  async stop() { this.cancel(); }
}
module.exports = { WorkspaceOnlineLogin };
