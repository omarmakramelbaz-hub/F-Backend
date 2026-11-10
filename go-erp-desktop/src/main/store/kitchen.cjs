'use strict';
// Store: Commands of the dashboard pages and the kitchen prints they make.

module.exports = {
  pageCommand(key) {
    const row = this.db.prepare('SELECT rowid AS seq, * FROM page_commands WHERE key = ?').get(key);
    return row ? { ...row, meta: row.meta ? JSON.parse(row.meta) : {} } : null;
  },

pageCommandBySeq(seq) {
    const row = this.db.prepare('SELECT rowid AS seq, * FROM page_commands WHERE rowid = ?').get(seq);
    return row ? { ...row, meta: row.meta ? JSON.parse(row.meta) : {} } : null;
  },

setPageCommandMeta(key, meta) {
    this.db.prepare('UPDATE page_commands SET meta = ? WHERE key = ?').run(JSON.stringify(meta), key);
  },

/** The page's own operation number (idempotency_key) with what it did, so a retry gets the same answer. */
  recordPageCommand(key, kind, ticketRef, revision, actor, branch, meta = {}) {
    const result = this.db.prepare('INSERT INTO page_commands (key, kind, ticket_ref, revision, actor_id, branch, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
      .run(key, kind, String(ticketRef), revision, actor.id, branch, JSON.stringify(meta), this.now().toISOString());
    return this.pageCommand(key) || { seq: Number(result.lastInsertRowid) };
  },

/** A kitchen ticket printed here; job: the branch printer prints it (delivery orders, like the dashboard's print queue). */
  addKitchenPrint(ticketRef, revision, snapshot, { job = false, branch = null } = {}) {
    const result = this.db.prepare('INSERT INTO kitchen_prints (ticket_ref, revision, snapshot, created_at, branch, job_status) VALUES (?, ?, ?, ?, ?, ?)')
      .run(String(ticketRef), revision, JSON.stringify(snapshot), this.now().toISOString(), branch, job ? 'pending' : null);
    return Number(result.lastInsertRowid);
  },

/** The branch printer's waiting kitchen tickets of orders placed here (PosBranchPrinting::listing). */
  kitchenJobs(branch) {
    return this.db.prepare("SELECT seq, ticket_ref, job_status FROM kitchen_prints WHERE branch = ? AND job_status IS NOT NULL ORDER BY seq").all(branch);
  },

claimKitchenJob(seq, token) {
    return this.db.prepare("UPDATE kitchen_prints SET job_status = 'claimed', claim_token = ? WHERE seq = ? AND job_status = 'pending'").run(token, seq).changes > 0;
  },

completeKitchenJob(seq, token, result) {
    const row = this.db.prepare('SELECT job_status, claim_token FROM kitchen_prints WHERE seq = ?').get(seq);
    if (!row || row.claim_token !== token || !['claimed', result].includes(row.job_status)) return false;
    this.db.prepare('UPDATE kitchen_prints SET job_status = ? WHERE seq = ?').run(result, seq);
    return true;
  },

kitchenPrint(seq) {
    const row = this.db.prepare('SELECT * FROM kitchen_prints WHERE seq = ?').get(seq);
    return row ? { ...row, snapshot: JSON.parse(row.snapshot) } : null;
  },
};
