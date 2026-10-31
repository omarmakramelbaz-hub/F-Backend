'use strict';
// Every 30 seconds while the internet is available: send the queued operations, then fetch what changed.
const { EventEmitter } = require('node:events');

class SyncEngine extends EventEmitter {
  constructor({ store, api, token, intervalMs = 30000, refreshEveryMs = 5 * 60 * 1000, now = () => Date.now() }) {
    super();
    this.store = store;
    this.api = api;
    this.token = token; // () => the device token, decrypted only when needed
    this.intervalMs = intervalMs;
    this.refreshEveryMs = refreshEveryMs;
    this.now = now;
    this.timer = null;
    this.running = null;
    this.lastRefresh = 0;
    this.state = { online: null, last_sync_at: store.get('last_sync_at'), last_error: null, device_revoked: false };
  }

  start() {
    this.stopped = false;
    this.schedule(1000);
  }

  stop() {
    this.stopped = true;
    clearTimeout(this.timer);
  }

  schedule(delay) {
    clearTimeout(this.timer);
    if (this.stopped) return;
    this.timer = setTimeout(() => { this.run().catch(() => {}); }, delay);
  }

  /** Ask for a sync soon, e.g. right after a sale, without waiting for the next 30-second tick. */
  kick() {
    if (!this.running) this.schedule(1500);
  }

  status() {
    return { ...this.state, ...this.store.outboxStats(), enrolled: !!this.store.device(), running: !!this.running };
  }

  async run({ refresh = false } = {}) {
    if (this.running) return this.running;
    this.running = this.cycle(refresh).finally(() => {
      this.running = null;
      this.emit('status', this.status());
    });
    return this.running;
  }

  async cycle(forceRefresh) {
    let next = this.intervalMs;
    try {
      const device = this.store.device();
      if (!device || this.state.device_revoked) return;
      // A server from before expenses and cash moves only knew sales.
      const operations = this.store.pendingOperations(50, this.store.get('server_kinds', ['sale']));
      const refresh = forceRefresh || !this.lastRefresh || this.now() - this.lastRefresh >= this.refreshEveryMs || this.store.userCount() === 0;
      this.emit('status', { ...this.status(), running: true });
      let response;
      try {
        response = await this.api.sync(device, this.token(), {
          operations, want_users: refresh, users_hash: this.store.usersHash(), want_catalogs: refresh, catalog_hashes: this.store.catalogHashes(),
        });
      } catch (error) {
        if (process.env.GOERP_DEBUG_SYNC) console.log(`[goerp] ${new Date().toISOString().slice(11, 19)} sync failed: ${error.status} ${error.cause || ''}`);
        this.state.online = false;
        this.state.last_error = error.message;
        if (error.status === 401) this.state.device_revoked = true;
        return;
      }
      this.store.applyResults(response.results || []);
      if (Array.isArray(response.kinds)) this.store.set('server_kinds', response.kinds);
      if (response.users) this.store.replaceUsers(response.users, response.users_hash);
      if (response.branches) this.store.applyBranches(response.branches);
      if (refresh) this.lastRefresh = this.now();
      const syncedAt = new Date(this.now()).toISOString();
      this.store.set('last_sync_at', syncedAt);
      if (response.server_time) this.store.set('server_offset_ms', Date.parse(response.server_time) - this.now());
      this.state = { ...this.state, online: true, last_sync_at: syncedAt, last_error: null };
      // A full batch means more operations are waiting: continue right away.
      if (operations.length >= 50) next = 1000;
    } finally {
      this.schedule(next);
    }
  }
}

module.exports = { SyncEngine };
