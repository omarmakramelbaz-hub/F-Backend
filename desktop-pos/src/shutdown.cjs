'use strict';

// Electron can emit before-quit more than once. Keep SQLite open until accepted
// work finishes, and close it only after the final window-closing phase.
module.exports = class Shutdown {
  constructor(app, stop, close, drain = async () => {}) {
    this.stopping = false;
    this.ready = false;
    this.pending = new Set();
    app.on('before-quit', event => {
      if (this.ready) return;
      event.preventDefault();
      if (this.stopping) return;
      this.stopping = true;
      stop();
      Promise.allSettled([...this.pending]).then(async () => {
        try { await drain(); } catch {} finally { this.ready = true; app.quit(); }
      });
    });
    app.on('window-all-closed', () => { if (!this.stopping) app.quit(); });
    app.once('will-quit', close);
  }
  async run(fn) {
    if (this.stopping) throw Error('البرنامج يُغلق الآن. الطلبات المحفوظة متاحة عند إعادة فتحه.');
    const task = Promise.resolve().then(fn);
    this.pending.add(task);
    try { return await task; }
    finally { this.pending.delete(task); }
  }
};
