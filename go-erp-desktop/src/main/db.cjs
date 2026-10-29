'use strict';
// The device's own database (SQLite file on the hard disk). Everything the cashier does is saved here first.
const { DatabaseSync } = require('node:sqlite');

const MIGRATIONS = [
  `CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
   CREATE TABLE users (
     id INTEGER PRIMARY KEY, name TEXT NOT NULL, mobile TEXT NOT NULL, email TEXT NOT NULL,
     account_type TEXT NOT NULL, password_hash TEXT NOT NULL, branches TEXT NOT NULL,
     can_checkout INTEGER NOT NULL, can_manage INTEGER NOT NULL, can_reprint INTEGER NOT NULL);
   CREATE TABLE branches (
     value TEXT PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL, address TEXT NOT NULL, phone TEXT NOT NULL,
     available INTEGER NOT NULL DEFAULT 1, snapshot_id TEXT, snapshot_hash TEXT, catalog TEXT, updated_at TEXT);
   CREATE TABLE tickets (
     id TEXT PRIMARY KEY, branch TEXT NOT NULL, channel TEXT NOT NULL, status TEXT NOT NULL,
     actor_id INTEGER NOT NULL, actor_name TEXT NOT NULL, label TEXT NOT NULL DEFAULT '', data TEXT NOT NULL,
     local_number TEXT, business_date TEXT, snapshot_id TEXT, totals TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
     paid_at TEXT, server_id INTEGER, server_number TEXT, sync_status TEXT, sync_error TEXT);
   CREATE INDEX tickets_open ON tickets (branch, status);
   CREATE INDEX tickets_day ON tickets (branch, business_date);
   CREATE TABLE outbox (
     id TEXT PRIMARY KEY, kind TEXT NOT NULL, ticket_id TEXT, payload TEXT NOT NULL, status TEXT NOT NULL,
     attempts INTEGER NOT NULL DEFAULT 0, result TEXT, last_error TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
   CREATE INDEX outbox_pending ON outbox (status, created_at);
   CREATE TABLE counters (name TEXT PRIMARY KEY, value INTEGER NOT NULL);`,
];

function open(file) {
  const db = new DatabaseSync(file);
  db.exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = FULL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
  const version = db.prepare('PRAGMA user_version').get().user_version;
  for (let index = version; index < MIGRATIONS.length; index++) {
    transaction(db, () => {
      db.exec(MIGRATIONS[index]);
      db.exec(`PRAGMA user_version = ${index + 1}`);
    });
  }
  return db;
}

/** All statements succeed together or none is saved. */
function transaction(db, work) {
  db.exec('BEGIN IMMEDIATE');
  try {
    const result = work();
    db.exec('COMMIT');
    return result;
  } catch (error) {
    try { db.exec('ROLLBACK'); } catch { /* already rolled back */ }
    throw error;
  }
}

module.exports = { open, transaction };
