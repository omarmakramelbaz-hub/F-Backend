'use strict';
const crypto = require('node:crypto');
const { DashboardLiveSocial, route: originalRoute, cleanHeaders, enrollment, NEEDS_LOGIN, NEEDS_INTERNET } = require('./dashboard-live-social.cjs');
const { rootBinding, actorBinding, serverSession } = require('./workspace-mode.cjs');
const { canonical, authorityHeaders } = require('./workspace-protocol.cjs');
const failure = (message, code) => Object.assign(Error(message), { code });
const SESSION_PATH = '/admin/desktop-dashboard/workspace/session';
const CHALLENGE_PATH = '/admin/desktop-dashboard/workspace/challenge';
function route(url, method, origin, type) {
  if (url.origin === origin && url.protocol === 'https:' && !url.username && !url.password && !url.hash && !url.search) {
    if (method === 'POST' && url.pathname === SESSION_PATH) return 'probe';
    if (method === 'GET' && url.pathname === CHALLENGE_PATH) return 'challenge';
  }
  const result = originalRoute(url, method, origin, type);
  // The old GET session endpoint and Connected-* authority never apply to a
  // workspace, even if a page or copied cookie asks for them explicitly.
  return result === 'probe' ? null : result;
}
function headers(current) { return authorityHeaders(current.root, current.actor, current.prepared.token); }
/**
 * Group social adapter retains the original window, cookie, CDN, draft and
 * microphone restrictions. Its runtime view is only an identity fingerprint;
 * it never changes the physical prepared descriptor or selects central ERP.
 * probeSession is a trusted native fresh-challenge + group POST session client,
 * using this isolated HTTPS browser and the real authenticated B cookie.
 */
class WorkspaceLiveSocial extends DashboardLiveSocial {
  constructor({ workspace, actor, probeSession, ...options }) {
    if (!workspace || typeof probeSession !== 'function') throw Error('Invalid native workspace social configuration.');
    const current = async () => {
      const prepared = await workspace.connection(), root = rootBinding(prepared), effective = await actorBinding(actor, root);
      return { prepared, root, actor: effective };
    };
    const runtime = { isPrepared: async () => await workspace.isPrepared() && await actor.authenticated(),
      connection: async () => {
        const bound = await current();
        // Deliberately not a bearer or prepared connection. This native-only
        // fingerprint lets the unchanged original UI detect membership/source
        // changes while authority headers use the actual private root below.
        return { serverOrigin: bound.root.serverOrigin, deviceId: bound.root.workspaceId, actorId: bound.actor.actorId,
          token: crypto.createHash('sha256').update(canonical({ root: bound.root, actor: bound.actor })).digest('hex') };
      } };
    super({ ...options, runtime });
    Object.assign(this, { workspace, actor, probeSession, workspaceCurrent: current });
  }
  async authorize(details, context = this.context) {
    if (!context || context.closed || this.quitting()) return { cancel: true };
    let url; try { url = new URL(details.url); } catch { return { cancel: true }; }
    const method = String(details.method || 'GET').toUpperCase(), kind = route(url, method, context.credential.serverOrigin, details.resourceType);
    const outgoing = cleanHeaders(details.requestHeaders, url.origin !== context.credential.serverOrigin);
    if (!kind) return { cancel: true };
    if (kind === 'probe' || kind === 'challenge') {
      if (!context.probing || details.webContentsId > 0) return { cancel: true };
      try {
        const current = await this.workspaceCurrent();
        if (enrollment(await this.runtime.connection()) !== context.enrollment || context.closed || this.context !== context || this.quitting()) return { cancel: true };
        if (kind === 'probe') outgoing.Authorization = 'Bearer ' + current.prepared.token;
        return { requestHeaders: outgoing };
      } catch { return { cancel: true }; }
    }
    if (!this.window || details.webContentsId !== this.window.webContents.id) return { cancel: true };
    if (kind === 'asset') return context.verified ? { requestHeaders: outgoing } : { cancel: true };
    try {
      if (method === 'POST' || !context.verified) await this.verify(context);
      const current = await this.workspaceCurrent();
      if (enrollment(await this.runtime.connection()) !== context.enrollment) throw failure(NEEDS_LOGIN, 'LIVE_LOGIN_REQUIRED');
      if (context.closed || this.context !== context || this.quitting() || !this.window || this.window.isDestroyed()
        || details.webContentsId !== this.window.webContents.id) return { cancel: true };
      return { requestHeaders: { ...outgoing, ...headers(current) } };
    } catch (error) {
      context.verified = false;
      if (!context.closed && this.context === context) this.notice(error.code === 'LIVE_NETWORK_UNAVAILABLE' ? NEEDS_INTERNET : NEEDS_LOGIN);
      return { cancel: true };
    }
  }
  async probe(context) {
    context.probing = true;
    try {
      const current = await this.workspaceCurrent();
      const proof = await this.probeSession({ browser: context.session, root: current.prepared, actor: current.actor,
        signal: AbortSignal.any([context.controller.signal, AbortSignal.timeout(12000)]) });
      if (context.closed || this.context !== context || this.quitting()) throw failure(NEEDS_LOGIN, 'LIVE_LOGIN_REQUIRED');
      serverSession(proof, current.root, current.actor);
      if (proof.connected_request_protocol !== 2 || enrollment(await this.runtime.connection()) !== context.enrollment)
        throw failure(NEEDS_LOGIN, 'LIVE_LOGIN_REQUIRED');
      if (context.closed || this.context !== context || this.quitting()) throw failure(NEEDS_LOGIN, 'LIVE_LOGIN_REQUIRED');
      context.verified = true;
    } catch (error) {
      context.verified = false;
      if (error.code === 'LIVE_LOGIN_REQUIRED') throw error;
      if (/ERR_(?:INTERNET|CONNECTION|NETWORK|NAME_NOT_RESOLVED|TIMED_OUT|ADDRESS_UNREACHABLE)|Failed to fetch|fetch failed/i.test(error.message || '')
        || ['TypeError', 'TimeoutError'].includes(error.name)) throw failure(NEEDS_INTERNET, 'LIVE_NETWORK_UNAVAILABLE');
      throw failure(NEEDS_LOGIN, 'LIVE_LOGIN_REQUIRED');
    } finally { context.probing = false; }
  }
}
module.exports = { WorkspaceLiveSocial, route, headers, SESSION_PATH, CHALLENGE_PATH };
