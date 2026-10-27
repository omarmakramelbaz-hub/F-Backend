'use strict';
/** Exact global counters, never a bounded recent-print/actor-specific list. */
class WorkspaceGuard {
  constructor({ workspace, remoteState, stopped = () => false }) { Object.assign(this, { workspace, remoteState, stopped }); }
  async check({ operation } = {}) {
    if (this.stopped()) return false;
    if (operation === 'remote-write' && this.remoteState.blocked) return false;
    // Cached login has already started this owner. Online-only mutations may
    // start it after the server view is visible; no optional source upgrade.
    await this.workspace.start(); if (this.stopped()) return false;
    const local = await this.workspace.control({ action: 'pending' }), state = await this.remoteState.read();
    const summary = local?.print_state;
    if (!local?.counts || !['pending', 'conflicts'].every(key => Number.isSafeInteger(local.counts[key]) && local.counts[key] >= 0)
      || !Array.isArray(local.commands) || !summary || Object.keys(summary).sort().join(',') !== 'prepared,unacknowledged,uncertain'
      || !['prepared', 'uncertain', 'unacknowledged'].every(key => Number.isSafeInteger(summary[key]) && summary[key] >= 0)
      || !Array.isArray(state?.pending)) return false;
    return !this.stopped() && local.counts.pending === 0 && local.counts.conflicts === 0 && local.commands.length === 0
      && summary.prepared === 0 && summary.uncertain === 0 && summary.unacknowledged === 0 && state.pending.length === 0;
  }
}
module.exports = { WorkspaceGuard };
