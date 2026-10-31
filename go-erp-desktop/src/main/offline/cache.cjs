'use strict';
// Copies of the dashboard kept on this device so its pages open without the internet. Files every page uses (styles,
// scripts, fonts, public images) are shared; each account's pages and data are kept under that account and are only
// ever opened by it. A separate database file, so the daily backups of the sales database stay small.
const { DatabaseSync } = require('node:sqlite');

const LIMIT_BYTES = 500 * 1024 * 1024;

/** The same address however it was asked for: no fragment, and no jQuery cache-busting parameter. */
function key(url) {
  const value = new URL(url);
  value.hash = '';
  value.searchParams.delete('_');
  return value.href;
}

class PageCache {
  constructor(file, { now = () => new Date() } = {}) {
    this.db = new DatabaseSync(file, { timeout: 5000 });
    this.now = now;
    this.db.exec(`PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;
      CREATE TABLE IF NOT EXISTS entries (scope TEXT NOT NULL, kind TEXT NOT NULL, url TEXT NOT NULL, path TEXT NOT NULL,
        content_type TEXT NOT NULL, body BLOB NOT NULL, bytes INTEGER NOT NULL, stored_at TEXT NOT NULL, used_at TEXT NOT NULL,
        PRIMARY KEY (scope, kind, url));
      CREATE INDEX IF NOT EXISTS entries_path ON entries (scope, kind, path);
      CREATE INDEX IF NOT EXISTS entries_used ON entries (used_at);`);
    this.writes = 0;
  }

  put(scope, kind, url, contentType, body) {
    const address = key(url);
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO entries (scope, kind, url, path, content_type, body, bytes, stored_at, used_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(scope, kind, url) DO UPDATE SET content_type = excluded.content_type, body = excluded.body, bytes = excluded.bytes,
        stored_at = excluded.stored_at, used_at = excluded.used_at`)
      .run(scope, kind, address, new URL(address).pathname, contentType, body, body.length, stamp, stamp);
    if (++this.writes % 50 === 0) this.trim();
  }

  /** The copy of exactly this address, or (pathOnly) the newest copy of the same page with any parameters. */
  get(scope, kind, url, { pathOnly = false } = {}) {
    const address = key(url);
    let row = this.db.prepare('SELECT url, content_type, body, stored_at FROM entries WHERE scope = ? AND kind = ? AND url = ?').get(scope, kind, address);
    if (!row && pathOnly) {
      row = this.db.prepare('SELECT url, content_type, body, stored_at FROM entries WHERE scope = ? AND kind = ? AND path = ? ORDER BY stored_at DESC LIMIT 1')
        .get(scope, kind, new URL(address).pathname);
    }
    if (!row) return null;
    this.db.prepare('UPDATE entries SET used_at = ? WHERE scope = ? AND kind = ? AND url = ?').run(this.now().toISOString(), scope, kind, row.url);
    return { url: row.url, contentType: row.content_type, body: Buffer.from(row.body), storedAt: row.stored_at };
  }

  /** Whether a copy of exactly this address was saved less than maxAge ago. */
  fresh(scope, kind, url, maxAge) {
    const row = this.db.prepare('SELECT stored_at FROM entries WHERE scope = ? AND kind = ? AND url = ?').get(scope, kind, key(url));
    return !!row && this.now().getTime() - Date.parse(row.stored_at) < maxAge;
  }

  hasUrl(scope, kind, url) {
    return !!this.db.prepare('SELECT 1 FROM entries WHERE scope = ? AND kind = ? AND url = ? LIMIT 1').get(scope, kind, key(url));
  }

  has(scope, kind, path) {
    return !!this.db.prepare('SELECT 1 FROM entries WHERE scope = ? AND kind = ? AND path = ? LIMIT 1').get(scope, kind, path);
  }

  /** The path of the account's most recently used saved page. */
  latest(scope, kind) {
    const row = this.db.prepare('SELECT path FROM entries WHERE scope = ? AND kind = ? ORDER BY used_at DESC LIMIT 1').get(scope, kind);
    return row ? row.path : null;
  }

  count(scope, kind) {
    return this.db.prepare('SELECT COUNT(*) AS count FROM entries WHERE scope = ? AND kind = ?').get(scope, kind).count;
  }

  /** An account's saved pages (when the account is gone from this device, or the device is set up again). */
  forgetScope(scope) {
    this.db.prepare('DELETE FROM entries WHERE scope = ?').run(scope);
  }

  scopes() {
    return this.db.prepare('SELECT DISTINCT scope FROM entries').all().map((row) => row.scope);
  }

  forgetAll() {
    this.db.prepare('DELETE FROM entries').run();
  }

  /** Keeps the copy under its size limit by dropping what was used longest ago. */
  trim(limit = LIMIT_BYTES) {
    const total = this.db.prepare('SELECT COALESCE(SUM(bytes), 0) AS bytes FROM entries').get().bytes;
    if (total <= limit) return 0;
    let excess = total - Math.floor(limit * 0.8);
    let removed = 0;
    for (const row of this.db.prepare('SELECT scope, kind, url, bytes FROM entries ORDER BY used_at LIMIT 2000').all()) {
      if (excess <= 0) break;
      this.db.prepare('DELETE FROM entries WHERE scope = ? AND kind = ? AND url = ?').run(row.scope, row.kind, row.url);
      excess -= row.bytes;
      removed++;
    }
    return removed;
  }

  close() {
    this.db.close();
  }
}

module.exports = { PageCache, cacheKey: key };
