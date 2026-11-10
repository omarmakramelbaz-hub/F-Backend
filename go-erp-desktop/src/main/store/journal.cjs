'use strict';
// Store: The branch journal: expenses and their reviews, stock received, employee entries, shift closings and the till.
const { transaction } = require('../db.cjs');
const money = require('../money.cjs');
const rules = require('../rules.cjs');

module.exports = {
  // ---- expenses and cash put in or taken out of the drawer ----

  /** The branch's active expense categories, as last synced with its menu. */
  expenseCategories(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.expense_categories) ? catalog.expense_categories : [];
  },

/**
   * Same checks as the dashboard's expenses page, so the expense is not refused later. options.id: the dashboard
   * page's operation number; saving it again returns the same expense.
   */
  recordExpense(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'expense' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const category = this.expenseCategories(branch).find((item) => item.key === values.category);
    if (!category) throw new Error('اختر بند المصروف.');
    const amount = money.minor(String(values.amount ?? '').trim());
    if (amount <= 0 || amount > 100000000) throw new Error('قيمة المصروف أكبر من صفر وبحد أقصى مليون جنيه.');
    const text = (value, max) => String(value ?? '').trim().slice(0, max);
    const description = text(values.description, 500);
    let quantity = text(values.quantity_kg, 14).replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace('٫', '.');
    if (category.stock) {
      if (!/^[0-9]{1,7}(\.[0-9]{1,3})?$/.test(quantity) || Number(quantity) <= 0 || Number(quantity) > 1000000) {
        throw new Error('أدخل عدد الكيلوات لهذا البند (حتى ٣ منازل عشرية).');
      }
    } else {
      if (quantity) throw new Error('عدد الكيلوات متاح لبنود مشتريات البضاعة فقط.');
      quantity = '';
      const material = rules.stockMaterial(description);
      if (branch.startsWith('f:') && ['purchases', 'other'].includes(category.key) && material) {
        throw new Error(`اختر بند مشتريات ${material} وأدخل الكمية بالكيلو لإضافتها إلى البضاعة.`);
      }
    }
    const approve = !!values.approve;
    if (approve && !actor.can_approve_expenses) throw new Error('اعتماد المصروفات للمالك والإدارة فقط.');
    const data = { category: category.key, description, amount: money.decimal(amount), supplier: text(values.supplier, 150),
      notes: text(values.notes, 1000), quantity_kg: quantity, approve };
    return this.addJournal(actor, branch, 'expense', amount, data, { category_name: category.name }, options.id);
  },

/**
   * An expense approved, rejected or cancelled on the expenses page while offline (BranchExpenses::review), sent to the
   * dashboard as expense_review under the page's operation number: a dashboard expense by its number and revision, one
   * saved on this device by the operation that saved it. shown: what the device shows of it until the dashboard has it.
   */
  recordExpenseReview(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'expense_review' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_approve_expenses');
    const data = { action: values.action, reason: String(values.reason || ''),
      ...(values.expense_key ? { expense_key: values.expense_key } : { expense_id: Number(values.expense_id), expected_revision: Number(values.expected_revision) }) };
    return this.addJournal(actor, branch, 'expense_review', Number(values.amount_cents) || 0, data, values.shown || {}, options.id, 'expense_review');
  },

/** Expense reviews made on this device for these branches, oldest first. */
  expenseReviews(branches) {
    if (!branches.length) return [];
    return this.db.prepare(`SELECT * FROM journal WHERE kind = 'expense_review' AND branch IN (${branches.map(() => '?').join(', ')}) ORDER BY seq`)
      .all(...branches).map((row) => ({ ...row, data: JSON.parse(row.data) }));
  },

/**
   * Goods received on the dashboard's stock page offline (BranchInventory::receive; the page checked the item and the
   * quantity), sent to the dashboard as stock_receive under the page's operation number.
   */
  recordStockReceipt(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'stock' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const data = { ingredient_id: Number(values.ingredient_id), unit: values.unit, quantity: String(values.quantity),
      supplier: String(values.supplier || ''), notes: String(values.notes || '') };
    return this.addJournal(actor, branch, 'stock', 0, data, { units: values.units, name: values.name, unit_label: values.unit_label },
      options.id, 'stock_receive');
  },

/**
   * A deduction, bonus or advance recorded on the dashboard's employees page offline (the page checked it), sent to the
   * dashboard as employee_entry under the page's operation number.
   */
  recordEmployeeEntry(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'employee_entry' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const data = { employee_id: Number(values.employee_id), day: String(values.day), kind: values.kind, amount: String(values.amount),
      reason: String(values.reason), notes: String(values.notes || '') };
    return this.addJournal(actor, branch, 'employee_entry', money.minor(values.amount), data, { employee_name: String(values.employee_name || '') },
      options.id, 'employee_entry');
  },

/**
   * Deductions, bonuses and advances recorded on this device for a branch, on one day or in one month (YYYY-MM), oldest
   * first. One the dashboard refused is left out: it is not on the dashboard.
   */
  employeeEntries(branch, { day = null, month = null } = {}) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'employee_entry' AND branch = ? AND sync_status != 'rejected' ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }))
      .filter((entry) => (!day || entry.data.day === day) && (!month || String(entry.data.day).slice(0, 7) === month));
  },

/**
   * A shift an owner or admin closed on the dashboard's shift page offline (the page checked it), sent to the dashboard
   * as shift_close: alone, after everything recorded before it and before anything recorded after it.
   */
  recordShiftClose(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'shift' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم الإغلاق مستخدم لبيانات مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_close_shift');
    const data = { previous_closing_id: Number(values.previous_closing_id), counted_cash: String(values.counted_cash), notes: String(values.notes || '') };
    // The report shown and printed here until the dashboard's own arrives stays on the device (never sent).
    return this.addJournal(actor, branch, 'shift', 0, data, { started_at: values.started_at || '', ...(values.report ? { report: values.report } : {}) },
      options.id, 'shift_close');
  },

/** Shifts closed on this device for a branch, oldest first. */
  deviceShiftClosings(branch) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'shift' AND branch = ? ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }));
  },

/** Shift closings waiting to be sent. */
  waitingShiftClose() {
    return this.db.prepare("SELECT COUNT(*) AS c FROM outbox WHERE kind = 'shift_close' AND status = 'pending'").get().c;
  },

/** Goods received on this device for a branch, oldest first. */
  deviceStockReceipts(branch) {
    return this.db.prepare("SELECT * FROM journal WHERE kind = 'stock' AND branch = ? ORDER BY seq").all(branch)
      .map((row) => ({ ...row, data: JSON.parse(row.data) }));
  },

/** Cash a manager puts in or takes out of the drawer, with its reason. */
  recordTill(actor, branch, values) {
    this.requireBranch(actor, branch, 'can_manage');
    const direction = values.direction === 'out' ? 'out' : 'in';
    const amount = money.minor(String(values.amount ?? '').trim());
    if (amount <= 0 || amount > 100000000) throw new Error('المبلغ أكبر من صفر وبحد أقصى مليون جنيه.');
    const note = String(values.note ?? '').trim().slice(0, 500);
    if (!note) throw new Error('اكتب سبب العملية.');
    return this.addJournal(actor, branch, 'till', amount, { direction, amount: money.decimal(amount), note }, {});
  },

/** The record and the operation that sends it to the dashboard are saved together. */
  /** operationKind: what the server applies, when it differs from the journal's kind (a leave is an attendance entry). */
  addJournal(actor, branch, kind, amount, data, extra, givenId = null, operationKind = kind) {
    return transaction(this.db, () => {
      const now = this.now();
      const stamp = now.toISOString();
      const id = givenId || this.uuid();
      const operation = { id, kind: operationKind, actor_id: actor.id, branch, occurred_at: stamp, data };
      this.db.prepare(`INSERT INTO journal (id, kind, branch, actor_id, actor_name, business_date, amount_cents, data, created_at, sync_status, seq)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)`)
        .run(id, kind, branch, actor.id, actor.name, rules.operatingDay(now), amount, JSON.stringify({ ...data, ...extra }), stamp, this.nextCounter('journal_seq'));
      this.db.prepare(`INSERT INTO outbox (id, kind, journal_id, payload, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?)`)
        .run(id, operationKind, id, JSON.stringify(operation), stamp, stamp);
      return this.journalEntry(id);
    });
  },

journalEntry(id) {
    const row = this.db.prepare('SELECT * FROM journal WHERE id = ?').get(id);
    return row ? { ...row, data: JSON.parse(row.data) } : null;
  },

journalBySeq(seq) {
    const row = this.db.prepare('SELECT * FROM journal WHERE seq = ?').get(seq);
    return row ? { ...row, data: JSON.parse(row.data) } : null;
  },

/** Expenses saved on this device for the given branches between two working days, newest first. */
  deviceExpenses(branches, from, to) {
    if (!branches.length) return [];
    const marks = branches.map(() => '?').join(', ');
    return this.db.prepare(`SELECT * FROM journal WHERE kind = 'expense' AND branch IN (${marks}) AND business_date BETWEEN ? AND ?
      ORDER BY business_date DESC, seq DESC`).all(...branches, from, to).map((row) => ({ ...row, data: JSON.parse(row.data) }));
  },

journal(branch, day, kinds = ['expense', 'till']) {
    return this.db.prepare(`SELECT * FROM journal WHERE branch = ? AND business_date = ? AND kind IN (${kinds.map(() => '?').join(', ')})
      ORDER BY created_at DESC`).all(branch, day, ...kinds).map((row) => ({ ...row, data: JSON.parse(row.data) }));
  },
};
