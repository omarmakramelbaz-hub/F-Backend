'use strict';
const readSnapshot = require('./dashboard-download.cjs');
const { manifest, canonical } = require('./workspace-profile.cjs');
const { MAX_ENVELOPE_BYTES } = require('./workspace-enrollment-seal.cjs');
const rootEndpoints = new Set(['manifest', 'bootstrap', 'commands', 'delta', 'print-outcomes', 'remote-attempts']);
const failure = status => Object.assign(Error('تعذر تأكيد الاتصال بالسيرفر؛ العمليات المحلية محفوظة.'), { code: 'WORKSPACE_HTTP_FAILED', ...(status ? { status } : {}) });
function origin(value) {
  const parsed = new URL(value);
  if (parsed.protocol !== 'https:' || parsed.origin !== value || parsed.username || parsed.password) throw failure(); return value;
}
/** Fixed native HTTPS endpoints. No renderer URL, header or bearer is forwarded. */
class WorkspaceHTTP {
  constructor({ serverOrigin, transport = fetch, serverSession, stopped = () => false }) {
    if (typeof transport !== 'function' || typeof serverSession !== 'function') throw failure();
    this.serverOrigin = origin(serverOrigin); this.transport = transport; this.serverSession = serverSession; this.stopped = stopped;
    this.controllers = new Set(); this.cancelled = false;
  }
  assert() { if (this.cancelled || this.stopped()) throw Object.assign(failure(), { code: 'ACCOUNT_LOGIN_CANCELLED' }); }
  credential(value) {
    this.assert(); if (!value || value.serverOrigin !== this.serverOrigin || !/^[a-f0-9]{64}$/.test(value.token || '')) throw failure(); return value;
  }
  async withRequest(work, { signal, timeout = 25000 } = {}) {
    this.assert(); const controller = new AbortController(); this.controllers.add(controller);
    const joined = AbortSignal.any([controller.signal, AbortSignal.timeout(timeout), ...(signal ? [signal] : [])]);
    try { this.assert(); return await work(joined); }
    finally { controller.abort(); this.controllers.delete(controller); }
  }
  async checked(response, limit, signal) {
    if (!response.ok) { await response.body?.cancel().catch(() => {}); throw failure(response.status); }
    const parsed = await readSnapshot(response, limit, { signal }); this.assert(); return parsed;
  }
  challenge(_origin, options = {}) {
    if (_origin !== this.serverOrigin) throw failure();
    return this.withRequest(async signal => {
      const browser = options.browser || this.serverSession(); if (!browser) throw failure();
      const response = await browser.fetch(this.serverOrigin + '/admin/desktop-dashboard/workspace/challenge', {
        method: 'GET', credentials: 'include', headers: { Accept: 'application/json' }, redirect: 'error', signal });
      return this.checked(response, 65536, signal);
    }, options);
  }
  enroll(_origin, input, csrf, options = {}) {
    if (_origin !== this.serverOrigin || Object.keys(input).sort().join(',') !== 'device_id,name,nonce,public_key'
      || !/^[A-Za-z0-9_-]{16,256}$/.test(csrf || '')) throw failure();
    return this.withRequest(async signal => {
      const browser = this.serverSession(); if (!browser) throw failure();
      const response = await browser.fetch(this.serverOrigin + '/admin/desktop-dashboard/workspace/enroll', { method: 'POST', credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(input), redirect: 'error', signal });
      const envelope = await this.checked(response, MAX_ENVELOPE_BYTES, signal);
      // readSnapshot retains the exact canonical wire body; the seal receiver
      // independently rejects duplicate fields and noncanonical serialization.
      return readSnapshot.input(envelope);
    }, options);
  }
  localSession({ browser, local, active }, options = {}) {
    const parsed = new URL(local.origin);
    if (parsed.protocol !== 'http:' || parsed.hostname !== '127.0.0.1' || parsed.origin !== local.origin || !parsed.port || !local.token) throw failure();
    this.credential(active.credential);
    return this.withRequest(async signal => this.checked(await browser.fetch(local.origin + '/admin/desktop-dashboard/workspace/local-session', {
      method: 'GET', credentials: 'include', headers: { Accept: 'application/json', 'X-Fasakhansta-Desktop': local.token,
        Authorization: 'Bearer ' + active.credential.token }, redirect: 'error', signal }), 65536, signal), options);
  }
  root(endpoint, input, credential, options = {}) {
    this.credential(credential); if (!rootEndpoints.has(endpoint)) throw failure();
    const download = endpoint === 'bootstrap' || endpoint === 'delta', get = endpoint === 'bootstrap' || endpoint === 'manifest';
    let body;
    if (!get) {
      body = JSON.stringify(input);
      if (Buffer.byteLength(body) > (endpoint === 'delta' ? 64 : 20) * 1024 ** 2) throw failure();
    }
    return this.withRequest(async signal => {
      const response = await this.transport(this.serverOrigin + '/api/desktop-dashboard/workspace/' + endpoint, {
        method: get ? 'GET' : 'POST', headers: { Accept: 'application/json',
          'Content-Type': endpoint === 'delta' ? 'application/octet-stream' : 'application/json', Authorization: 'Bearer ' + credential.token },
        body, redirect: 'error', signal });
      return this.checked(response, download ? 256 * 1024 ** 2 : endpoint === 'manifest' ? 16 * 1024 ** 2 : 20 * 1024 ** 2, signal);
    }, { ...options, timeout: download ? 128000 : 25000 });
  }
  async media(ticket, credential, { signal } = {}) {
    this.credential(credential);
    if (typeof ticket !== 'string' || !ticket || ticket.length > 8192) throw failure();
    const controller = new AbortController(); this.controllers.add(controller);
    const joined = AbortSignal.any([controller.signal, AbortSignal.timeout(60000), ...(signal ? [signal] : [])]);
    const finish = () => { this.controllers.delete(controller); controller.abort(); };
    try {
      const response = await this.transport(this.serverOrigin + '/api/desktop-dashboard/workspace/media?ticket=' + encodeURIComponent(ticket), {
        method: 'GET', headers: { Accept: 'image/*,application/pdf', Authorization: 'Bearer ' + credential.token }, redirect: 'error', signal: joined });
      if (!response.ok || Number(response.headers.get('content-length')) > 16 * 1024 ** 2) {
        await response.body?.cancel().catch(() => {}); throw failure(response.status);
      }
      const reader = response.body?.getReader(); if (!reader) throw failure(); let bytes = 0, finished = false;
      const abort = () => { reader.cancel().catch(() => {}); }; joined.addEventListener('abort', abort, { once: true });
      const done = () => { if (!finished) { finished = true; joined.removeEventListener('abort', abort); reader.releaseLock(); finish(); } };
      const stream = new ReadableStream({
        async pull(target) {
          try {
            joined.throwIfAborted(); const part = await reader.read(); joined.throwIfAborted();
            if (part.done) { done(); target.close(); return; }
            bytes += part.value.byteLength; if (bytes > 16 * 1024 ** 2) { await reader.cancel(); throw failure(); }
            target.enqueue(part.value);
          } catch (error) { try { await reader.cancel(); } finally { done(); target.error(error); } }
        }, async cancel() { try { await reader.cancel(); } finally { done(); } }
      });
      return new Response(stream, { status: response.status, headers: response.headers });
    } catch (error) { finish(); throw error; }
  }
  async verifyAuthority(record, options = {}) {
    const fresh = await this.root('manifest', undefined, record.credential, options);
    return canonical(manifest(fresh, record.credential.workspaceId)) === canonical(record.manifest);
  }
  async actorSession({ browser, root, authority, signal }) {
    this.credential(root);
    const challenge = await this.challenge(this.serverOrigin, { signal, browser });
    if (challenge.actor_id !== authority.actorId || challenge.protocol !== 2 || challenge.role !== authority.role
      || challenge.server_origin !== this.serverOrigin || !/^[A-Za-z0-9_-]{16,256}$/.test(challenge.csrf_token || '')) throw failure(403);
    return this.withRequest(async requestSignal => this.checked(await browser.fetch(this.serverOrigin + '/admin/desktop-dashboard/workspace/session', {
      method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': challenge.csrf_token,
        Authorization: 'Bearer ' + root.token }, body: JSON.stringify({ actor_id: authority.actorId, manifest_revision: authority.manifestRevision, grant: authority.grant }),
      redirect: 'error', signal: requestSignal }), 65536, requestSignal), { signal });
  }
  async probe(actor, credential, options = {}) {
    return this.actorSession({ browser: options.browser || this.serverSession(), root: credential, authority: await actor.authority(), signal: options.signal });
  }
  stop() { this.cancelled = true; for (const controller of this.controllers) controller.abort(); }
}
module.exports = { WorkspaceHTTP };
