'use strict';

/** The native bridge reads the PHP outbox and confirms one original business command at a time. */
class DashboardSync {
  constructor({ local, remote, refresh, effects, backgroundRefresh = false, refreshInterval, now = Date.now, onState = () => {} }) {
    this.local = local; this.remote = remote; this.onState = onState; this.stopped = false;
    this.backgroundRefresh = backgroundRefresh === true; this.refreshing = null; this.handoff = false;
    this.refresh = refresh; this.effects = effects; this.refreshInterval = refreshInterval ?? (this.backgroundRefresh ? 30000 : 60000); this.now = now; this.nextRefresh = 0;
    this.state = { online: false, pending: 0, conflicts: 0, acknowledged: 0, error: '', refresh_deferred: false };
  }
  stop() { this.stopped = true; }
  run() {
    if (this.running) return this.running;
    if (this.stopped || this.handoff) return Promise.resolve(this.state);
    this.running = this.flush().finally(() => { this.running = null; });
    return this.running;
  }
  report(values) { Object.assign(this.state, values); if (!this.stopped) this.onState({ ...this.state }); }
  withRefreshHandoff(work) {
    // Claim the short final cutover synchronously. No acknowledgement may
    // start or finish across replacement of its originating local database.
    if (this.stopped || this.running || this.handoff) return Promise.resolve({ deferred: true });
    this.handoff = true;
    return Promise.resolve().then(work).finally(() => { this.handoff = false; });
  }
  startRefresh() {
    if (this.stopped || this.refreshing || this.handoff) return;
    // Measure cadence from admission, rather than delaying another interval
    // after a slow download. Missed slots never create overlapping captures.
    this.nextRefresh = this.now() + this.refreshInterval;
    this.report({ refresh_running: true });
    this.refreshing = Promise.resolve().then(() => this.stopped ? { deferred: true } : this.refresh()).then(result => {
      if (this.stopped) return;
      if (result?.deferred) this.report({ refresh_deferred: true });
      else this.report({ online: true, error: '', refresh_deferred: false, refreshedAt: new Date(this.now()).toISOString() });
    }).catch(error => { this.report({ error: error.message }); }).finally(() => {
      this.refreshing = null; this.report({ refresh_running: false });
    });
  }
  async flush() {
    try {
      let batchSize;
      for (let count = 0; count < 1000 && !this.stopped; count++) {
        const outbox = await this.local({ action: 'pending' });
        this.report(outbox.counts);
        if (this.stopped) return this.state;
        // Work created during this cycle stays local until the next slot.
        if (batchSize === undefined) {
          if (!Number.isSafeInteger(outbox.counts.pending) || outbox.counts.pending < 0) throw Error('Invalid local pending count.');
          batchSize = Math.min(outbox.counts.pending, 1000);
        }
        if (!outbox.commands.length) {
          if(this.effects) {
            const effects=await this.effects({printProtocol:Object.hasOwn(outbox,'print_protocol')?outbox.print_protocol:0});this.report({print_pending:effects.pending});
            if(effects.deferred){this.report({refresh_deferred:true});return this.state;}
          }
          if (this.refresh && outbox.counts.pending === 0 && outbox.counts.conflicts === 0 && this.now() >= this.nextRefresh) {
            if (this.backgroundRefresh) { this.startRefresh(); return this.state; }
            const result = await this.refresh();
            // An active local form defers replacement, not the next scheduled
            // upload. It has not proved server connectivity or refreshed data.
            if (result?.deferred) { this.report({ refresh_deferred: true }); return this.state; }
            this.nextRefresh = this.now() + this.refreshInterval;
            this.report({ online: true, error: '', refresh_deferred: false, refreshedAt: new Date(this.now()).toISOString() });
          }
          return this.state;
        }
        if (count >= batchSize) return this.state;
        const command = outbox.commands[0];
        let receipt;
        try {
          receipt = await this.remote(command);
        } catch (error) {
          if (!this.stopped) {
            await this.local({ action: 'failed', command_id: command.command_id, message: error.message,
              conflict: [409, 422].includes(error.status) });
            this.report({ online: Boolean(error.status), error: error.message });
          }
          return this.state;
        }
        if (this.stopped) return this.state; // A lost confirmation will replay the same UUID on next launch.
        const confirmation = await this.local({ action: 'acknowledge', command_id: command.command_id, receipt });
        this.report({ ...confirmation.counts, online: true, error: '' });
      }
    } catch (error) {
      this.report({ error: error.message });
    }
    return this.state;
  }
}
module.exports = DashboardSync;
