'use strict';
// Store: Dine-in and delivery orders kept from the dashboard (the running orders and the service floor).
const { transaction } = require('../db.cjs');

module.exports = {
  /** The branches' tables and open orders as the server last sent them (only when they changed). */
  applyService(entries) {
    transaction(this.db, () => {
      for (const entry of entries || []) {
        if (!entry || !entry.value || !Array.isArray(entry.tickets)) continue;
        this.db.prepare('UPDATE branches SET service = ?, service_hash = ? WHERE value = ?')
          .run(JSON.stringify({ tables: entry.tables || [], policy: entry.policy || {}, tickets: entry.tickets, delivery: entry.delivery ?? null,
            shift: entry.shift ?? null, finished: Array.isArray(entry.finished) ? entry.finished : [], received_at: this.now().toISOString() }),
            entry.hash, entry.value);
      }
    });
  },

/**
   * The open shift's figures as the dashboard would report its closing (sent every two minutes when they changed), and
   * when they arrived: this device's records synced up to then are in them, the later ones are not.
   */
  applyRunning(entries) {
    const known = this.get('shift_running', {}) || {};
    const next = {};
    for (const entry of entries || []) {
      if (!entry || typeof entry.value !== 'string' || typeof entry.hash !== 'string') continue;
      if (entry.report && typeof entry.report === 'object') next[entry.value] = { hash: entry.hash, report: entry.report, received_at: this.now().toISOString() };
      else if (known[entry.value] && known[entry.value].hash === entry.hash) next[entry.value] = known[entry.value];
    }
    this.set('shift_running', next);
  },

runningHashes() {
    const known = this.get('shift_running', {}) || {};
    return Object.fromEntries(Object.entries(known).map(([branch, copy]) => [branch, copy.hash]));
  },

running(branch) {
    const known = this.get('shift_running', {}) || {};
    return known[branch] || null;
  },

/**
   * This device's records of a branch since a moment that the dashboard may not count yet (not sent, or sent after
   * `after`): paid invoices (always cash offline), approved expenses and drawer moves. Rejected ones never count.
   */
  shiftRecords(branch, since, after) {
    const fresh = (row) => row.sync_status === 'pending' || (row.sync_status === 'synced' && (!after || !row.synced_at || row.synced_at > after));
    const sales = this.db.prepare(`SELECT channel, totals, sync_status, synced_at FROM tickets WHERE branch = ? AND status = 'paid' AND paid_at >= ?`)
      .all(branch, since).filter(fresh).map((row) => ({ channel: row.channel, totals: JSON.parse(row.totals) }));
    const journal = this.db.prepare(`SELECT kind, amount_cents, data, sync_status, synced_at FROM journal WHERE branch = ?
      AND kind IN ('expense', 'till', 'expense_review') AND created_at >= ?`).all(branch, since).filter(fresh)
      .map((row) => ({ kind: row.kind, amount_cents: row.amount_cents, data: JSON.parse(row.data) }));
    // Approved expenses leave the drawer; one approved here leaves it when approved, and a cancelled one comes back.
    const expenses = journal.filter((row) => row.kind === 'expense' && row.data.approve)
      .concat(journal.filter((row) => row.kind === 'expense_review' && ['approve', 'void'].includes(row.data.action))
        .map((row) => ({ ...row, amount_cents: row.data.action === 'void' ? -row.amount_cents : row.amount_cents })));
    return { sales, expenses, till: journal.filter((row) => row.kind === 'till') };
  },

serviceHashes() {
    const hashes = {};
    for (const row of this.db.prepare('SELECT value, service_hash FROM branches WHERE service IS NOT NULL').all()) hashes[row.value] = row.service_hash;
    return hashes;
  },

service(branch) {
    const row = this.db.prepare('SELECT service FROM branches WHERE value = ?').get(branch);
    return row && row.service ? JSON.parse(row.service) : null;
  },

/**
   * What this device did to an order open on the dashboard (sent to the kitchen, bill printed, paid), shown on top of
   * the server's copy until the dashboard has it: once none of its operations waits to be sent, the copy is the truth.
   */
  serviceOverlay(ticketId) {
    const row = this.db.prepare('SELECT data FROM service_overlays WHERE ticket_id = ?').get(ticketId);
    if (!row) return null;
    const overlay = JSON.parse(row.data);
    const ops = overlay.ops || [];
    const waiting = ops.length && this.db.prepare(`SELECT COUNT(*) AS count FROM outbox WHERE status = 'pending' AND id IN (${ops.map(() => '?').join(', ')})`)
      .get(...ops).count;
    if (waiting) return overlay;
    this.db.prepare('DELETE FROM service_overlays WHERE ticket_id = ?').run(ticketId);
    return null;
  },

/** Saves the overlay of a dashboard order together with the operation that tells the dashboard. */
  overlayServerTicket(ticketId, overlay, operation) {
    return this.atomically(() => {
      const stamp = this.now().toISOString();
      const payload = { ...operation, occurred_at: stamp };
      this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, ?, NULL, ?, 'pending', ?, ?)`)
        .run(operation.id, operation.kind, JSON.stringify(payload), stamp, stamp);
      const data = { ...overlay, ops: [...(overlay.ops || []), operation.id] };
      this.db.prepare('INSERT INTO service_overlays (ticket_id, data) VALUES (?, ?) ON CONFLICT(ticket_id) DO UPDATE SET data = excluded.data')
        .run(ticketId, JSON.stringify(data));
      return data;
    });
  },
};
