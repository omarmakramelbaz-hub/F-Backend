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
  // Version 1.1: expenses and cash put in or taken out of the drawer, kept until the dashboard has them.
  `ALTER TABLE users ADD COLUMN can_approve_expenses INTEGER NOT NULL DEFAULT 0;
   ALTER TABLE users ADD COLUMN can_close_shift INTEGER NOT NULL DEFAULT 0;
   CREATE TABLE journal (
     id TEXT PRIMARY KEY, kind TEXT NOT NULL, branch TEXT NOT NULL, actor_id INTEGER NOT NULL, actor_name TEXT NOT NULL,
     business_date TEXT NOT NULL, amount_cents INTEGER NOT NULL, data TEXT NOT NULL, created_at TEXT NOT NULL,
     sync_status TEXT NOT NULL, sync_error TEXT, server_ref TEXT);
   CREATE INDEX journal_day ON journal (branch, business_date);
   ALTER TABLE outbox ADD COLUMN journal_id TEXT;`,
  // Version 1.3: customers this device served, so a returning phone number fills its name and address.
  `CREATE TABLE customers (
     phone TEXT PRIMARY KEY, name TEXT NOT NULL, address TEXT NOT NULL, orders INTEGER NOT NULL, total_cents INTEGER NOT NULL,
     last_order_at TEXT NOT NULL);`,
  // Version 1.4: the server's attendance for today and yesterday, to show punches made on other devices.
  `ALTER TABLE branches ADD COLUMN attendance TEXT;`,
  // Version 1.6: the dashboard's own cashier page works offline. Its receipts need a number (seq), the one-time
  // first print of accounts that cannot reprint, when the dashboard got each invoice, and the branch's day so far.
  `ALTER TABLE tickets ADD COLUMN seq INTEGER;
   ALTER TABLE tickets ADD COLUMN first_print TEXT;
   ALTER TABLE tickets ADD COLUMN synced_at TEXT;
   UPDATE tickets SET seq = rowid WHERE status = 'paid';
   UPDATE tickets SET synced_at = updated_at WHERE sync_status = 'synced';
   INSERT OR REPLACE INTO counters (name, value) SELECT 'receipt_seq', COALESCE(MAX(seq), 0) FROM tickets;
   CREATE UNIQUE INDEX tickets_seq ON tickets (seq);
   ALTER TABLE branches ADD COLUMN today TEXT;`,
  // Version 1.6.1: the dashboard's expenses page lists expenses saved on this device; they need a number too.
  `ALTER TABLE journal ADD COLUMN seq INTEGER;
   ALTER TABLE journal ADD COLUMN synced_at TEXT;
   UPDATE journal SET seq = rowid;
   UPDATE journal SET synced_at = created_at WHERE sync_status = 'synced';
   INSERT OR REPLACE INTO counters (name, value) SELECT 'journal_seq', COALESCE(MAX(seq), 0) FROM journal;
   CREATE UNIQUE INDEX journal_seq ON journal (seq);`,
  // Version 1.7: the dashboard's dine-in and delivery pages offline. The branch's tables and open orders as the server
  // last sent them, the page's own operation numbers, kitchen tickets printed here, and what this device did to an
  // order open on the server (its bill printed).
  `ALTER TABLE branches ADD COLUMN service TEXT;
   ALTER TABLE branches ADD COLUMN service_hash TEXT;
   CREATE TABLE page_commands (key TEXT PRIMARY KEY, kind TEXT NOT NULL, ticket_ref TEXT NOT NULL, revision INTEGER NOT NULL,
     actor_id INTEGER NOT NULL, branch TEXT NOT NULL, meta TEXT, created_at TEXT NOT NULL);
   CREATE TABLE kitchen_prints (seq INTEGER PRIMARY KEY AUTOINCREMENT, ticket_ref TEXT NOT NULL, revision INTEGER NOT NULL, snapshot TEXT NOT NULL,
     created_at TEXT NOT NULL);
   CREATE TABLE service_overlays (ticket_id INTEGER PRIMARY KEY, data TEXT NOT NULL);`,
  // Version 1.9: new delivery orders on the dashboard's page offline. The branches' saved delivery customers (from the
  // dashboard, and those saved here), and the kitchen tickets the branch printer prints from this device.
  `CREATE TABLE directory (
     id INTEGER PRIMARY KEY, branch TEXT NOT NULL, name TEXT NOT NULL, phone TEXT NOT NULL, phone_key TEXT NOT NULL, address TEXT NOT NULL,
     area TEXT NOT NULL DEFAULT '', delivery_notes TEXT NOT NULL DEFAULT '', latitude REAL, longitude REAL, revision INTEGER NOT NULL,
     local INTEGER NOT NULL DEFAULT 0, updated_at TEXT);
   CREATE INDEX directory_phone ON directory (branch, phone_key);
   ALTER TABLE kitchen_prints ADD COLUMN branch TEXT;
   ALTER TABLE kitchen_prints ADD COLUMN job_status TEXT;
   ALTER TABLE kitchen_prints ADD COLUMN claim_token TEXT;`,
  // Version 1.17: the app orders board offline. Each action taken on an order without the internet (accept,
  // «مندوب الفرع», delivered...) with the card as it shows it; sent to the board's own action once online.
  `CREATE TABLE board_commands (
     id TEXT PRIMARY KEY, actor_id INTEGER NOT NULL, actor_name TEXT NOT NULL, source TEXT NOT NULL, order_id INTEGER NOT NULL,
     order_number TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, fields TEXT NOT NULL, card TEXT NOT NULL, from_group TEXT NOT NULL,
     to_group TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', error TEXT, attempts INTEGER NOT NULL DEFAULT 0,
     created_at TEXT NOT NULL, updated_at TEXT NOT NULL, done_at TEXT);
   CREATE INDEX board_commands_status ON board_commands (status, created_at);`,
];

/** A dated copy of the database once a day; the newest seven are kept. */
function backup(db, folder, day, keep = 7) {
  const fs = require('node:fs');
  const path = require('node:path');
  fs.mkdirSync(folder, { recursive: true });
  const file = path.join(folder, `goerp-${day}.sqlite`);
  if (!fs.existsSync(file)) db.exec(`VACUUM INTO '${file.replace(/'/g, "''")}'`);
  const copies = fs.readdirSync(folder).filter((name) => /^goerp-\d{4}-\d{2}-\d{2}\.sqlite$/.test(name)).sort();
  for (const name of copies.slice(0, Math.max(0, copies.length - keep))) fs.rmSync(path.join(folder, name), { force: true });
  return file;
}

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

module.exports = { open, transaction, backup };
