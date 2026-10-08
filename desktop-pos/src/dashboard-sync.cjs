'use strict';

/** The native bridge reads the PHP outbox and confirms one original business command at a time. */
class DashboardSync {
  constructor({ local, remote, onState = () => {} }) {
    this.local = local; this.remote = remote; this.onState = onState; this.stopped = false;
    this.state = { online: false, pending: 0, conflicts: 0, acknowledged: 0, error: '' };
  }
  stop() { this.stopped = true; }
  run() {
    if (this.running) return this.running;
    if (this.stopped) return Promise.resolve(this.state);
    this.running = this.flush().finally(() => { this.running = null; });
    return this.running;
  }
  report(values) { Object.assign(this.state, values); if (!this.stopped) this.onState({ ...this.state }); }
  async flush() {
    try {
      for (let count = 0; count < 1000 && !this.stopped; count++) {
        const outbox = await this.local({ action: 'pending' });
        this.report(outbox.counts);
        if (this.stopped || !outbox.commands.length) return this.state;
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
