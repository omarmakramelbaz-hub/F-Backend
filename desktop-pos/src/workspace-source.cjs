'use strict';
const source = require('./dashboard-source.cjs');
const failure = () => Object.assign(Error('لم يتأكد مصدر تجهيز الجهاز؛ النسخة الحالية محفوظة.'), { code: 'WORKSPACE_SOURCE_REJECTED' });
/** The unchanged release grammar/high-water verifier owns publication authority. */
class WorkspaceSourceAuthority {
  constructor({ updater }) {
    if (typeof updater?.manifest !== 'function' || typeof updater?.highwater !== 'function') throw failure(); this.updater = updater;
  }
  async authorize({ context, credential, snapshot }, { signal } = {}) {
    if (signal?.aborted) throw failure();
    const approved = await this.updater.manifest({ serverOrigin: credential.serverOrigin, token: credential.token }, signal || new AbortController().signal);
    const release = approved?.release;
    if (approved?.state !== 'ready' || !Number.isSafeInteger(release?.sequence) || release.sequence < 1
      || release.source_revision !== context?.manifest?.sourceRevision || !source.same(release.source, context.sourceFingerprint)
      || !source.same(approved.server_source, snapshot.source) || !source.same(snapshot.source, context.sourceFingerprint)) throw failure();
    await this.updater.highwater({ serverOrigin: credential.serverOrigin, token: credential.token }, approved);
    if (signal?.aborted) throw failure();
    return { sequence: release.sequence, sourceRevision: release.source_revision, sourceFingerprint: release.source };
  }
}
/** Only the native group updater uses this wrapper, with the private root bearer. */
function groupUpdateTransport(transport) {
  if (typeof transport !== 'function') throw failure();
  return (url, options) => transport(url, { ...options, headers: { ...options.headers, 'X-Fasakhansta-Update-Protocol': '2' } });
}
module.exports = { WorkspaceSourceAuthority, groupUpdateTransport };
