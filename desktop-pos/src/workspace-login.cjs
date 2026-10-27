'use strict';

const crypto = require('node:crypto');
const { originalSignin, installCookies, networkFailure } = require('./account-login.cjs');
const { withDeadline } = require('./native-deadline.cjs');
const signout = require('./account-signout.cjs');
const rejected = () => Object.assign(Error('تعذر تسجيل الدخول. تأكد من بيانات الحساب.'), { code: 'WORKSPACE_CREDENTIALS_REJECTED' });
const cancelled = () => Object.assign(Error('تم إلغاء تسجيل الدخول.'), { code: 'ACCOUNT_LOGIN_CANCELLED' });
const currentMember = (member, actor) => member && actor?.actorId === member.actor_id && actor.role === member.role;
const rejectionKey = 'workspace-server-rejection';

/**
 * Native-only actor login over ONE physical runtime. Password authentication is
 * the original PHP login; aliases/grants alone never establish a session.
 * verifyLocalSession must read the fixed authenticated workspace/local-session
 * endpoint with native private authority, after originalSignin. It is not IPC.
 * afterView starts optional HTTPS reauthentication only once the business view
 * is visible. Explicit server credential refusal is durably locked per actor.
 */
class WorkspaceLogin {
  constructor({ workspace, createSession, verifyLocalSession, online = () => true, stopped = () => false,
    onState = () => {}, onLocked, onBackgroundError = () => {}, onlineFallback }) {
    if (!workspace?.registry || typeof createSession !== 'function' || typeof verifyLocalSession !== 'function' || typeof onLocked !== 'function' || typeof onlineFallback !== 'function')
      throw Error('Invalid native workspace login configuration.');
    Object.assign(this, { workspace, registry: workspace.registry, createSession, verifyLocalSession, online, stopped, onState, onLocked, onBackgroundError, onlineFallback });
    this.controllers = new Set(); this.pendingCredentials = new Set(); this.sequence = 0; this.cancelled = false;
  }
  assert(signal) { if (this.cancelled || this.stopped() || signal?.aborted) throw cancelled(); }
  report(phase) { this.onState({ phase }); }
  async fresh(work, external) {
    const controller = new AbortController(), browser = this.createSession('go-erp-workspace-auth-' + crypto.randomUUID());
    this.controllers.add(controller);
    const signal = AbortSignal.any([controller.signal, AbortSignal.timeout(120000), ...(external ? [external] : [])]);
    try { this.assert(signal); return await work(browser, signal); }
    finally {
      controller.abort(); this.controllers.delete(controller);
      try { await withDeadline(() => browser.clearStorageData?.(), 5000, 'تعذر إغلاق جلسة الدخول المؤقتة.'); } catch {}
    }
  }
  async authenticate(input) {
    if (this.running) throw Error('جارٍ تسجيل الدخول بالفعل.');
    if (!input || typeof input.email !== 'string' || !input.email.trim() || input.email.length > 320 || /[\x00-\x1f\x7f]/.test(input.email)
      || typeof input.password !== 'string' || !input.password || input.password.length > 4096 || typeof input.remember !== 'boolean') throw rejected();
    this.sequence++;
    const credentials = { email: input.email.trim(), password: input.password, remember: input.remember };
    this.pendingCredentials.add(credentials);
    const task = this.login(credentials).catch(error => { credentials.password = ''; this.pendingCredentials.delete(credentials); throw error; })
      .finally(() => { if (this.running === task) this.running = null; });
    this.running = task; return task;
  }
  async login(credentials) {
    this.assert(); this.report('authenticating');
    const member = await this.registry.findActor(credentials.email), active = await this.registry.current();
    if (!member || !active) {
      if (this.online() === false) throw rejected();
      // The trusted caller runs original server password authentication and
      // installs only that authenticated actor's server cookies. A missing
      // cached grant prevents local access, never ordinary online sign-in.
      return this.serverOnly(credentials);
    }
    // Lookup is only a proposed identity. No actor directory or persistent
    // cookie exists until PHP accepts the actual password and native context.
    const local = await this.workspace.start(); this.assert();
    let result;
    try { result = await this.fresh(async (browser, signal) => {
      let actor;
      try { actor = await originalSignin({ browser, origin: local.origin, localToken: local.token, ...credentials, signal }); }
      catch (error) { if (error.code === 'ACCOUNT_CREDENTIALS_REJECTED') error.workspaceLocalPasswordRejected = true; throw error; }
      this.assert(signal);
      if (!currentMember(member, actor)) throw rejected();
      const proof = await this.verifyLocalSession({ browser, local, actor, active }, { signal }); this.assert(signal);
      const latest = await this.registry.current(), now = await this.registry.member(actor.actorId);
      if (!currentMember(now, actor) || latest?.manifest.revision !== active.manifest.revision
        || latest.manifest.roster_sha256 !== active.manifest.roster_sha256
        || proof?.protocol !== 2 || proof.workspace_id !== active.manifest.workspace_id || proof.actor_id !== actor.actorId
        || proof.role !== actor.role || proof.custodian_actor_id !== active.candidate.prepared.actorId
        || proof.manifest_revision !== active.manifest.revision || proof.root_fingerprint !== active.manifest.root_fingerprint) throw rejected();
      const selected = await this.workspace.actor(actor.actorId); this.assert(signal);
      const refused = await selected.runtime.metadata.read(rejectionKey);
      if (refused) {
        if (refused.format !== 2 || refused.kind !== 'workspace-server-rejection' || refused.actorId !== actor.actorId) throw rejected();
        // A freshly imported, independently verified changed verifier plus
        // its actual successful password POST can retire an older refusal.
        // A network failure against the same cached verifier cannot do so.
        if (refused.passwordBinding !== now.password_binding && Number.isSafeInteger(refused.manifestRevision)
          && refused.manifestRevision < latest.manifest.revision) await selected.runtime.metadata.write(rejectionKey, null);
        else {
          if (this.online() === false) throw rejected();
          if (await this.serverAuthenticate(credentials, { member: now, active: latest, selected, signal }) !== true) throw rejected(); this.assert(signal);
        }
      }
      await this.workspace.acceptSession(actor, proof); this.assert(signal);
      const target = this.createSession(selected.descriptor.partition), activeLogin = () => !this.cancelled && !this.stopped() && !signal.aborted;
      const undo = await installCookies(browser, target, local.origin, true, { active: activeLogin });
      try {
        await signout.signedIn(selected.runtime, { active: activeLogin }); this.assert(signal);
        await selected.runtime.metadata.write('workspace-login', { format: 2, kind: 'workspace-login', actorId: actor.actorId,
          manifestRevision: latest.manifest.revision, remember: credentials.remember }); this.assert(signal);
      } catch (error) { this.workspace.revokeSession(actor.actorId); await undo(); throw error; }
      return { ...selected, local: true, origin: local.origin, localToken: local.token, actor: { ...actor }, member: now, active: latest };
    }); } catch (error) {
      if (error.workspaceLocalPasswordRejected === true && this.online() !== false) return this.serverOnly(credentials, { cachedPasswordRejected: true });
      throw error;
    }
    let started = false;
    const forget = () => { credentials.password = ''; this.pendingCredentials.delete(credentials); };
    const afterView = () => {
      if (started) return background; started = true;
      // Never start this optional request from login() or a hidden preflight.
      background = (async () => {
        if (this.online() === false || !credentials.password) return;
        try { await this.serverAuthenticate(credentials, { member: result.member, active: result.active, selected: result, signal: AbortSignal.timeout(5000) }); }
        catch (error) { if (!this.cancelled) this.onBackgroundError({ code: error.code || 'WORKSPACE_SERVER_UNAVAILABLE' }); }
      })().finally(forget);
      return background;
    };
    let background;
    return { account: result.descriptor, runtime: result.runtime, local: true, origin: result.origin, localToken: result.localToken,
      actor: result.actor, afterView, discardCredentials: forget };
  }
  async serverOnly(credentials, extra = {}) {
    const controller = new AbortController(), fallbackCredentials = { ...credentials, ...extra }; this.controllers.add(controller);
    try {
      const active = await this.registry.current();
      const result = await this.onlineFallback(fallbackCredentials, { signal: AbortSignal.any([controller.signal, AbortSignal.timeout(120000)]) });
      this.assert(); if (result?.local !== false || active && result.origin !== active.credential.serverOrigin) throw rejected(); return result;
    } finally { controller.abort(); this.controllers.delete(controller); fallbackCredentials.password = ''; credentials.password = ''; this.pendingCredentials.delete(credentials); }
  }
  async serverAuthenticate(credentials, { member, active, selected, signal }) {
    return this.fresh(async (browser, requestSignal) => {
      let actor;
      try { actor = await originalSignin({ browser, origin: active.credential.serverOrigin, ...credentials, signal: requestSignal }); this.assert(requestSignal); }
      catch (error) {
        if (error.code === 'ACCOUNT_CREDENTIALS_REJECTED') await this.lock(selected, member, active, 'credentials');
        if (networkFailure(error) || ['ACCOUNT_AUTH_TIMED_OUT', 'NATIVE_OPERATION_TIMEOUT'].includes(error.code)) return null;
        throw error;
      }
      if (!currentMember(member, actor)) { await this.lock(selected, member, active, 'identity'); throw rejected(); }
      const latest = await this.registry.member(actor.actorId), current = await this.registry.current(); this.assert(requestSignal);
      if (!currentMember(latest, actor) || current?.manifest.revision !== active.manifest.revision || latest.password_binding !== member.password_binding) throw rejected();
      const target = this.createSession(selected.descriptor.partition);
      await installCookies(browser, target, active.credential.serverOrigin, false, { active: () => !this.cancelled && !this.stopped() && !requestSignal.aborted });
      this.assert(requestSignal); await selected.runtime.metadata.write(rejectionKey, null); this.assert(requestSignal);
      return true;
    }, signal);
  }
  async lock(selected, member, active, reason) {
    this.workspace.revokeSession(member.actor_id);
    // Persist before UI lock/cleanup. Lost replies cannot erase a refusal.
    await selected.runtime.metadata.write(rejectionKey, { format: 2, kind: 'workspace-server-rejection', actorId: member.actor_id,
      manifestRevision: active.manifest.revision, passwordBinding: member.password_binding, reason });
    await signout.signedOut(selected.runtime);
    await this.onLocked({ actorId: member.actor_id, reason });
  }
  cancel() {
    this.cancelled = true; this.sequence++;
    for (const controller of this.controllers) controller.abort();
    for (const credentials of this.pendingCredentials) credentials.password = '';
    this.pendingCredentials.clear();
  }
  async stop() { this.cancel(); if (this.running) await this.running.catch(() => {}); }
}
module.exports = { WorkspaceLogin };
