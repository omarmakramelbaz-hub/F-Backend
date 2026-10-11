'use strict';

/** One shared background cycle every 30 seconds; busy slots are skipped. */
class SyncSchedule {
  constructor({ work, intervalMs = 30000, now = () => performance.now(), setTimer = setTimeout, clearTimer = clearTimeout, onError = () => {} }) {
    if (typeof work !== 'function' || !Number.isFinite(intervalMs) || intervalMs <= 0) throw Error('Invalid synchronization schedule.');
    Object.assign(this, { work, intervalMs, now, setTimer, clearTimer, onError });
    this.active = false; this.running = null; this.waiting = null; this.timer = null; this.refreshPending = false;
  }
  start() {
    if (this.active) return;
    this.active = true; this.due = this.now() + this.intervalMs; this.arm();
  }
  arm() { this.timer = this.setTimer(() => this.tick(), Math.max(0, this.due - this.now())); }
  request({ refresh = false } = {}) {
    if (!this.active) return Promise.reject(Error('Synchronization is stopped.'));
    if (this.running) {
      // The caller still joins the accepted cycle; a stronger refresh request
      // stays queued for a future scheduled slot, never an immediate rerun.
      this.refreshPending ||= refresh;
      return this.running;
    }
    if (!this.waiting) {
      let resolve, reject;
      const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
      this.waiting = { promise, resolve, reject, refresh: false };
    }
    this.waiting.refresh ||= refresh;
    return this.waiting.promise;
  }
  tick() {
    this.timer = null;
    if (!this.active) return;
    // A delayed timer skips missed slots instead of launching a catch-up burst.
    do { this.due += this.intervalMs; } while (this.due <= this.now());
    this.arm();
    if (this.running) return;
    const waiting = this.waiting; this.waiting = null;
    const refresh = Boolean(waiting?.refresh || this.refreshPending); this.refreshPending = false;
    const task = Promise.resolve().then(() => this.work({ refresh }));
    this.running = task.finally(() => { this.running = null; });
    this.running.then(waiting?.resolve, error => {
      waiting?.reject(error);
      if (this.active) this.onError(error);
    });
  }
  stop() {
    this.active = false;
    if (this.timer !== null) this.clearTimer(this.timer);
    this.timer = null;
    this.refreshPending = false;
    this.waiting?.reject(Error('Synchronization is stopped.')); this.waiting = null;
  }
}
module.exports = SyncSchedule;
