'use strict';
// Store: The queue of operations to send, their results, the orders board's commands and requests kept for later.
const { transaction } = require('../db.cjs');
const money = require('../money.cjs');
const { BOARD_ACTIONS, presentBoardCommand } = require('./shared.cjs');

module.exports = {
  /** An operation the dashboard pages' offline answers send (other than invoices), queued as it is. */
  queueOperation(operation) {
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, ?, NULL, ?, 'pending', ?, ?)`)
      .run(operation.id, operation.kind, JSON.stringify({ ...operation, occurred_at: stamp }), stamp, stamp);
  },

// ---- the queue of operations for the dashboard ----

  /** Only operations the server said it knows; others wait on the device until the server is updated. */
  pendingOperations(limit = 50, kinds = null) {
    const known = Array.isArray(kinds) ? kinds.filter((kind) => typeof kind === 'string') : null;
    if (known && !known.length) return [];
    const filter = known ? ` AND kind IN (${known.map(() => '?').join(', ')})` : '';
    // In the order they were done (an order changed, then its bill printed, then paid).
    const operations = this.db.prepare(`SELECT id, payload FROM outbox WHERE status = 'pending'${filter} ORDER BY created_at, rowid LIMIT ?`)
      .all(...(known || []), limit).map((row) => JSON.parse(row.payload));
    // A shift closing counts what the dashboard has when it arrives: it goes alone, once everything recorded before it
    // is in, and before anything recorded after it.
    const closing = operations.findIndex((operation) => operation.kind === 'shift_close');
    if (closing > 0) return operations.slice(0, closing);
    if (closing === 0) return operations.slice(0, 1);
    return operations;
  },

applyResults(results) {
    transaction(this.db, () => {
      const now = this.now().toISOString();
      for (const result of results || []) {
        const row = this.db.prepare('SELECT id, kind, ticket_id, journal_id FROM outbox WHERE id = ?').get(result.id);
        if (!row) continue;
        if (result.status === 'applied') {
          this.db.prepare("UPDATE outbox SET status = 'done', result = ?, last_error = NULL, updated_at = ? WHERE id = ?").run(JSON.stringify(result), now, row.id);
          const receipt = result.receipt || {};
          if (row.kind === 'ticket_batch' && result.batch && Array.isArray(result.batch.items)) {
            for (const line of result.batch.items) {
              this.db.prepare(`UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, synced_at = ? WHERE json_extract(data, '$.batch_key') = ?
                AND json_extract(data, '$.server_ticket_id') = ?`).run(line.order_id ?? null, now, row.id, line.ticket_id);
            }
          }
          if (row.ticket_id && row.kind === 'ticket_open') {
            // Moved to the dashboard: the order now lives there.
            this.db.prepare("UPDATE tickets SET status = 'moved', sync_status = 'synced', sync_error = NULL, server_id = ?, synced_at = ? WHERE id = ?")
              .run(result.ticket.id ?? null, now, row.ticket_id);
          } else if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'synced', sync_error = NULL, server_id = ?, server_number = ?, synced_at = ? WHERE id = ?")
            .run(receipt.id ?? null, receipt.number ?? null, now, row.ticket_id);
          if (row.journal_id) {
            const statuses = { approved: 'معتمد', rejected: 'مرفوض', voided: 'ملغي' };
            const reference = result.expense ? `${result.expense.number} · ${statuses[result.expense.status] || 'بانتظار الاعتماد'}`
              : result.closing && result.closing.number ? String(result.closing.number) : null;
            if (result.attendance) {
              // The server keeps the first punch of the day; show the time it recorded.
              this.db.prepare(`UPDATE journal SET data = json_set(data, '$.server_checked_in_at', ?, '$.server_checked_out_at', ?) WHERE id = ?`)
                .run(result.attendance.checked_in_at || null, result.attendance.checked_out_at || null, row.journal_id);
            }
            this.db.prepare("UPDATE journal SET sync_status = 'synced', sync_error = NULL, server_ref = ?, synced_at = ? WHERE id = ?").run(reference, now, row.journal_id);
          }
        } else if (result.status === 'rejected') {
          this.db.prepare("UPDATE outbox SET status = 'rejected', result = ?, last_error = ?, updated_at = ? WHERE id = ?")
            .run(JSON.stringify(result), result.error || 'تم رفض العملية.', now, row.id);
          if (row.ticket_id) this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.ticket_id);
          if (row.kind === 'ticket_batch') {
            this.db.prepare("UPDATE tickets SET sync_status = 'rejected', sync_error = ? WHERE json_extract(data, '$.batch_key') = ?").run(result.error || 'تم رفض العملية.', row.id);
          }
          if (row.journal_id) this.db.prepare("UPDATE journal SET sync_status = 'rejected', sync_error = ? WHERE id = ?").run(result.error || 'تم رفض العملية.', row.journal_id);
        } else {
          this.db.prepare('UPDATE outbox SET attempts = attempts + 1, updated_at = ? WHERE id = ?').run(now, row.id);
        }
      }
    });
  },

outboxStats() {
    const row = this.db.prepare(`SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected FROM outbox`).get();
    // Actions on the app orders board taken offline wait with them, and so do the pages' other operations.
    const board = this.db.prepare(`SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected FROM board_commands`).get();
    const deferred = this.db.prepare(`SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
      SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected FROM deferred_requests`).get();
    return { pending: (row.pending || 0) + (board.pending || 0) + (deferred.pending || 0),
      rejected: (row.rejected || 0) + (board.rejected || 0) + (deferred.rejected || 0) };
  },

// ---- the app orders board offline: actions taken without the internet, sent to the board's action once online ----

  /** An action on an app order taken on this device; `card` is the order's card as the board shows it now. */
  recordBoardCommand(actor, values) {
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO board_commands (id, actor_id, actor_name, source, order_id, order_number, action, fields, card, from_group, to_group,
      status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)`)
      .run(values.id, actor.id, actor.name, values.source, values.order_id, values.order_number || '', values.action, JSON.stringify(values.fields),
        values.card, values.from_group, values.to_group, stamp, stamp);
    return this.boardCommand(values.id);
  },

boardCommand(id) {
    const row = this.db.prepare('SELECT * FROM board_commands WHERE id = ?').get(id);
    return row ? presentBoardCommand(row) : null;
  },

/** The actions not yet on the dashboard, oldest first (one account's, or every account's). */
  pendingBoardCommands(actorId = null) {
    const rows = actorId === null
      ? this.db.prepare("SELECT * FROM board_commands WHERE status = 'pending' ORDER BY created_at, rowid").all()
      : this.db.prepare("SELECT * FROM board_commands WHERE status = 'pending' AND actor_id = ? ORDER BY created_at, rowid").all(actorId);
    return rows.map(presentBoardCommand);
  },

/** An account's actions the dashboard took since a time (a copy saved before then does not show them yet). */
  recentBoardCommands(actorId, since) {
    return this.db.prepare("SELECT * FROM board_commands WHERE status = 'done' AND actor_id = ? AND done_at > ? ORDER BY created_at, rowid")
      .all(actorId, String(since)).map(presentBoardCommand);
  },

/** The newest action on each order still shown on this device (pending), for the board's cards. */
  boardOverlay(actorId) {
    const latest = new Map();
    for (const command of this.pendingBoardCommands(actorId)) latest.set(command.source + ':' + command.order_id, command);
    return latest;
  },

/** The dashboard took the action (done) or refused it (rejected, with its reason); a refusal also stops the ones after it. */
  finishBoardCommand(id, status, error = null) {
    const stamp = this.now().toISOString();
    this.atomically(() => {
      const row = this.db.prepare('SELECT * FROM board_commands WHERE id = ?').get(id);
      if (!row || row.status !== 'pending') return;
      this.db.prepare('UPDATE board_commands SET status = ?, error = ?, attempts = attempts + 1, updated_at = ?, done_at = ? WHERE id = ?')
        .run(status, error, stamp, stamp, id);
      if (status !== 'rejected') return;
      this.db.prepare(`UPDATE board_commands SET status = 'rejected', error = ?, updated_at = ?, done_at = ?
        WHERE status = 'pending' AND source = ? AND order_id = ? AND actor_id = ?`)
        .run('ما اتبعتش: الإجراء اللي قبله على نفس الطلب اترفض.', stamp, stamp, row.source, row.order_id, row.actor_id);
    });
  },

/** Tried and not answered (no internet, a session to renew): tried again with the next sync. */
  retryBoardCommand(id, error = null) {
    this.db.prepare('UPDATE board_commands SET attempts = attempts + 1, error = ?, updated_at = ? WHERE id = ? AND status = ?')
      .run(error, this.now().toISOString(), id, 'pending');
  },

// ---- the dashboard pages' other operations offline: the page's own request, sent as the same account once online ----

  /**
   * A dashboard operation done offline that no offline page answers itself: the page's request (address, body, its
   * type) under the page's operation number. The same number with the same request is the same operation; with
   * another request it is refused.
   */
  deferRequest(actor, values) {
    const existing = this.db.prepare('SELECT * FROM deferred_requests WHERE id = ?').get(values.id);
    if (existing) {
      if (existing.actor_id !== actor.id || existing.url !== values.url || existing.body !== values.body) {
        const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
        error.status = 409;
        throw error;
      }
      return { ...existing, replayed: true };
    }
    const stamp = this.now().toISOString();
    this.db.prepare(`INSERT INTO deferred_requests (id, actor_id, actor_name, method, url, content_type, body, label, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`).run(values.id, actor.id, actor.name, values.method || 'POST', values.url, values.content_type || '',
      values.body, String(values.label || '').slice(0, 120), stamp, stamp);
    return { ...this.db.prepare('SELECT * FROM deferred_requests WHERE id = ?').get(values.id), replayed: false };
  },

/** An account's operations waiting for the dashboard, in the order they were done. */
  pendingDeferred(actorId) {
    return this.db.prepare("SELECT * FROM deferred_requests WHERE status = 'pending' AND actor_id = ? ORDER BY created_at, rowid").all(actorId);
  },

finishDeferred(id, status, error = null) {
    const stamp = this.now().toISOString();
    this.db.prepare(`UPDATE deferred_requests SET status = ?, error = ?, attempts = attempts + 1, updated_at = ?, done_at = ?
      WHERE id = ? AND status = 'pending'`).run(status, error, stamp, stamp, id);
  },

retryDeferred(id, error = null) {
    this.db.prepare("UPDATE deferred_requests SET attempts = attempts + 1, error = ?, updated_at = ? WHERE id = ? AND status = 'pending'")
      .run(error, this.now().toISOString(), id);
  },

rejectedOperations() {
    const labels = { sale: 'فاتورة', expense: 'مصروف', till: 'خزنة', attendance: 'حضور وانصراف', attendance_status: 'غياب أو إجازة',
      expense_review: 'اعتماد أو رفض مصروف',
      ticket_settle: 'تحصيل طلب', ticket_open: 'نقل طلب للداشبورد', ticket_action: 'إجراء على طلب', ticket_dispatch: 'تسليم طلب للمندوب',
      ticket_batch: 'تحصيل طلبات المندوب', ticket_save: 'تعديل طلب', ticket_cancel: 'إلغاء طلب', customer_save: 'بيانات عميل',
      stock_receive: 'إضافة بضاعة', shift_close: 'تقفيل وردية', employee_entry: 'خصم أو مكافأة أو سلفة موظف' };
    return this.db.prepare(`SELECT o.id, o.kind, o.last_error, o.updated_at, t.local_number, t.branch, t.totals, j.amount_cents, j.branch AS journal_branch
      FROM outbox o LEFT JOIN tickets t ON t.id = o.ticket_id LEFT JOIN journal j ON j.id = o.journal_id
      WHERE o.status = 'rejected' ORDER BY o.updated_at DESC LIMIT 200`).all()
      .map((row) => ({ id: row.id, kind: labels[row.kind] || row.kind, error: row.last_error, at: row.updated_at,
        local_number: row.local_number, branch: row.branch || row.journal_branch,
        total: row.totals ? money.decimal(JSON.parse(row.totals).total_cents) : (row.amount_cents != null ? money.decimal(row.amount_cents) : null) }))
      .concat(this.db.prepare("SELECT * FROM board_commands WHERE status = 'rejected' ORDER BY updated_at DESC LIMIT 100").all()
        .map((row) => ({ id: row.id, kind: 'طلب تطبيق: ' + (BOARD_ACTIONS[row.action] || row.action), error: row.error, at: row.updated_at,
          local_number: row.order_number || ('#' + row.order_id), branch: null, total: null })))
      .concat(this.db.prepare("SELECT * FROM deferred_requests WHERE status = 'rejected' ORDER BY updated_at DESC LIMIT 100").all()
        .map((row) => ({ id: row.id, kind: row.label || 'عملية من الداشبورد', error: row.error, at: row.updated_at, local_number: null, branch: null, total: null })))
      .sort((a, b) => String(b.at).localeCompare(String(a.at)));
  },
};
