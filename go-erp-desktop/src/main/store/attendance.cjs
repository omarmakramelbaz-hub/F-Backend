'use strict';
// Store: Employees and attendance.
const rules = require('../rules.cjs');

module.exports = {
  // ---- employee attendance, recorded with the device's (server-corrected) time, also offline ----

  employees(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.employees) ? catalog.employees : [];
  },

attendanceShifts(branch) {
    const catalog = this.catalog(branch);
    return catalog && Array.isArray(catalog.attendance_shifts) ? catalog.attendance_shifts : [];
  },

/** Each employee's day: what the server last said, overlaid with this device's own punches. */
  attendanceBoard(branch, day) {
    const row = this.db.prepare('SELECT attendance FROM branches WHERE value = ?').get(branch);
    const server = new Map((row && row.attendance ? JSON.parse(row.attendance) : []).filter((item) => item.day === day)
      .map((item) => [item.employee_id, item]));
    const punches = this.journal(branch, day, ['attendance']).reverse();
    return this.employees(branch).map((employee) => {
      const known = server.get(employee.id) || {};
      const state = { employee, status: known.status || 'unrecorded', checked_in_at: known.checked_in_at || null,
        checked_out_at: known.checked_out_at || null, pending: false, error: null };
      for (const punch of punches.filter((item) => item.data.employee_id === employee.id)) {
        if (punch.sync_status === 'rejected') { state.error = punch.sync_error; continue; }
        if (punch.sync_status === 'pending') state.pending = true;
        state.status = punch.data.status;
        if (punch.data.action === 'check_in' && !state.checked_in_at) state.checked_in_at = punch.created_at;
        if (punch.data.action === 'check_out' && !state.checked_out_at) state.checked_out_at = punch.created_at;
      }
      return state;
    });
  },

/**
   * Same order and shift rules as the dashboard; the server applies its deductions from these times. A leave or an
   * absence for the day (set_status) follows the dashboard's rules too: the first status of the day stays. options.id:
   * the dashboard page's operation number; saving it again returns the same punch.
   */
  recordPunch(actor, branch, values, options = {}) {
    if (options.id) {
      const existing = this.journalEntry(options.id);
      if (existing) {
        if (existing.kind !== 'attendance' || existing.actor_id !== actor.id || existing.branch !== branch) {
          const error = new Error('رقم العملية مستخدم لعملية مختلفة.');
          error.status = 409;
          throw error;
        }
        return { ...existing, replayed: true };
      }
    }
    this.requireBranch(actor, branch, 'can_checkout');
    const day = rules.operatingDay(this.now());
    const action = ['check_out', 'set_status'].includes(values.action) ? values.action : 'check_in';
    const state = this.attendanceBoard(branch, day).find((item) => item.employee.id === Number(values.employee_id));
    if (!state) throw new Error('الموظف غير موجود في هذا الفرع. اتصل بالإنترنت لتحديث قائمة الموظفين.');
    const partTime = state.employee.schedule_type === 'part_time';
    let status = values.status;
    if (action === 'set_status') {
      // BranchPayroll::attendance: the shift whose rule prices an absence, and the salary a leave is valued from.
      const employee = state.employee;
      if (!['preapproved_leave', 'unauthorized_absence'].includes(status)) throw new Error('اختر حالة الإجازة أو الغياب.');
      if (state.status !== 'unrecorded') {
        const error = new Error('تم تثبيت حالة الحضور لهذا اليوم؛ لا يمكن تغييرها بعد التسجيل.');
        error.status = 409;
        throw error;
      }
      if (partTime && !(employee.attendance_start && employee.attendance_end && ['morning', 'evening'].includes(employee.attendance_shift))) {
        throw new Error('أكمل مواعيد الدوام الجزئي في بيانات الموظف.');
      }
      const shift = partTime ? employee.attendance_shift : ['evening', 'مسائي', 'مساء'].includes(employee.shift) ? 'evening' : 'morning';
      if (status === 'unauthorized_absence' && !this.attendanceShifts(branch).some((rule) => rule.shift === shift)) {
        throw new Error('يجب أن يحدد الأونر مواعيد وخصومات هذا الفرع أولًا.');
      }
      if (status === 'preapproved_leave' && employee.has_salary === false) throw new Error('سجّل راتب الموظف لحساب قيمة يوم الإجازة.');
    } else if (action === 'check_in') {
      if (state.checked_in_at) throw new Error('تم تسجيل حضور ' + state.employee.name + ' اليوم بالفعل.');
      if (['preapproved_leave'].includes(state.status)) throw new Error('الموظف عليه إجازة مسجلة اليوم.');
      if (state.status === 'unauthorized_absence') throw new Error('تم تثبيت غياب ' + state.employee.name + ' اليوم؛ لا يمكن تغييره بعد التسجيل.');
      if (partTime) status = 'part_time';
      else if (!['morning', 'evening'].includes(status)) throw new Error('اختر حضور صباحي أو مسائي.');
    } else {
      if (!state.checked_in_at) throw new Error('سجّل حضور ' + state.employee.name + ' أولًا.');
      if (state.checked_out_at) throw new Error('تم تسجيل انصراف ' + state.employee.name + ' اليوم بالفعل.');
      status = state.status;
    }
    const data = { employee_id: state.employee.id, day, status, action };
    const notes = String(values.notes ?? '').trim().slice(0, 1000);
    if (notes) data.notes = notes;
    return this.addJournal(actor, branch, 'attendance', 0, data, { employee_name: state.employee.name }, options.id,
      action === 'set_status' ? 'attendance_status' : 'attendance');
  },
};
