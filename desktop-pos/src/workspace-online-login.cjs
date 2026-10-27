'use strict';
const crypto = require('node:crypto'), path = require('node:path');
const { originalSignin, installCookies } = require('./account-login.cjs');
const { GenerationStore } = require('./dashboard-generation.cjs');
const { manifest } = require('./workspace-profile.cjs');
const { workspaceBinding } = require('./workspace-prepared.cjs');
const signout = require('./account-signout.cjs');
const { withDeadline } = require('./native-deadline.cjs');
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
    this.controllers = new Set(); this.cancelled = false;
  }
  check(signal) { if (this.cancelled || this.stopped() || signal?.aborted) throw Object.assign(failed(), { code: 'ACCOUNT_LOGIN_CANCELLED' }); }
  async authenticate(input, { signal } = {}) {
    const credentials = { email: input.email, password: input.password, remember: input.remember }, controller = new AbortController();
    this.controllers.add(controller); const joined = AbortSignal.any([controller.signal, AbortSignal.timeout(120000), ...(signal ? [signal] : [])]);
    const browser = this.createSession('go-erp-workspace-server-auth-' + crypto.randomUUID());
    try {
      this.check(joined); const active = await this.registry.current(); if (!active) throw failed();
      const actor = await originalSignin({ browser, origin: active.credential.serverOrigin, ...credentials, signal: joined }); this.check(joined);
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
      const runtime = Object.freeze({ protocol: 2, onlineOnly: true, metadata, workspaceMetadata: this.workspace.metadata,
        isPrepared: async () => false, connection: () => this.workspace.connection(),
        authenticated: async () => accepted && !owner.cancelled && !owner.stopped(),
        offlineAuthenticated: () => this.workspace.hasSession(actor.actorId),
        authority: async () => { owner.check(); return authority || await ready; },
        session: async () => descriptor,
        control: value => this.workspace.actorControl(actor.actorId, value), printControl: value => this.workspace.actorControl(actor.actorId, value),
        supportsPrintEffects: () => this.workspace.physical.supportsPrintEffects(),
        get origin() { return owner.workspace.physical.origin; }, get token() { return owner.workspace.physical.token; } });
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
      return { account: descriptor, runtime, local: false, onlineOnly: true, origin: active.credential.serverOrigin, localToken: '', actor,
        afterView, refreshAuthority: () => { job = null; return afterView(); }, discardCredentials: () => {} };
    } finally {
      credentials.password = ''; controller.abort(); this.controllers.delete(controller);
      try { await withDeadline(() => browser.clearStorageData?.(), 5000, 'تعذر إغلاق جلسة الدخول المؤقتة.'); } catch {}
    }
  }
  lifeSignal() { this.background ||= new AbortController(); return this.background.signal; }
  cancel() { this.cancelled = true; this.background?.abort(); for (const controller of this.controllers) controller.abort(); }
  async stop() { this.cancel(); }
}
module.exports = { WorkspaceOnlineLogin };
