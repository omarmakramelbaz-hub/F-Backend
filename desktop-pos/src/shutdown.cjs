'use strict';

// Electron can emit before-quit more than once. Keep SQLite open until accepted
// work finishes, and close it only after the final window-closing phase.
module.exports = class Shutdown {
  constructor(app, stop, close, drain = async () => {}, { canRelaunch = () => true } = {}) {
    this.stopping = false;
    this.ready = false;
    this.pending = new Set();
    this.relaunchRequested = false;
    this.canRelaunch = canRelaunch;
    this.errors = [];
    app.on('web-contents-created', (_event, contents) => {
      contents.on('will-prevent-unload', event => {
        // A page may protect drafts during navigation. After accepted work and
        // runtime shutdown finish, it must not strand the app halfway through quit.
        if (this.ready) event.preventDefault();
      });
    });
    app.on('second-instance', () => {
      if (this.stopping && canRelaunch()) this.relaunchRequested = true;
    });
    app.on('before-quit', event => {
      if (this.ready) return;
      event.preventDefault();
      if (this.stopping) return;
      this.stopping = true;
      // A failed cancellation must not skip the accepted-work barrier or strand
      // a hidden process. Cancellation hooks may also finish asynchronously.
      let stopped;
      try { stopped = stop(); } catch (error) { this.errors.push({ phase: 'stop', error }); }
      Promise.allSettled([...this.pending, Promise.resolve(stopped).catch(error => {
        this.errors.push({ phase: 'stop', error });
      })]).then(async () => {
        try { await drain(); }
        catch (error) { this.errors.push({ phase: 'drain', error }); }
        finally { this.ready = true; app.quit(); }
      });
    });
    app.on('window-all-closed', () => { if (!this.stopping) app.quit(); });
    app.once('will-quit', () => {
      // Electron's will-quit handler must return even if the final SQLite close
      // reports an error. Outstanding work has already settled; process exit
      // releases the connection without discarding an accepted operation.
      let closed = false;
      try { close(); closed = true; }
      catch (error) { this.errors.push({ phase: 'close', error }); }
      if (closed && this.relaunchRequested && canRelaunch()) {
        try { app.relaunch(); }
        catch (error) { this.errors.push({ phase: 'relaunch', error }); }
      }
    });
  }
  requestRelaunch() {
    if (this.stopping || this.ready || !this.canRelaunch()) throw Error('تعذر إعادة تشغيل البرنامج أثناء العملية الحالية.');
    this.relaunchRequested = true;
  }
  cancelRelaunch() { if (!this.stopping) this.relaunchRequested = false; }
  async run(fn) {
    if (this.stopping) throw Error('البرنامج يُغلق الآن. الطلبات المحفوظة متاحة عند إعادة فتحه.');
    const task = Promise.resolve().then(fn);
    this.pending.add(task);
    try { return await task; }
    finally { this.pending.delete(task); }
  }
};
