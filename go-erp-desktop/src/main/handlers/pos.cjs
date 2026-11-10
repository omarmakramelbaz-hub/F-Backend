'use strict';
// The app's own selling screens: the menu, invoices and their prints, receipts, the till and expenses, attendance and
// the sales report. ctx gives the device's store and sync (set once the app is ready) and the session helpers.
const { BrowserWindow } = require('electron');
const printing = require('../printing.cjs');
const rules = require('../rules.cjs');

function posHandlers(ctx) {
  const { requireSession, printSettings, branchInfo, now } = ctx;
  const handlers = {
    'catalog:get': () => {
      const session = requireSession();
      const catalog = ctx.store.catalog(session.branch);
      if (!catalog) throw new Error('لم يتم تنزيل مينيو هذا الفرع بعد. اتصل بالإنترنت للمزامنة مرة واحدة.');
      return catalog;
    },

    'tickets:open': () => ctx.store.openTickets(requireSession().branch),

    'tickets:save': (values) => {
      const session = requireSession();
      return ctx.store.saveTicket(session.user, { ...values, branch: session.branch });
    },

    'tickets:delete': (values) => ctx.store.deleteTicket(requireSession().user, values.id),

    'tickets:quote': (values) => {
      const session = requireSession();
      return ctx.store.quoteTicket(session.branch, values.channel, values.data).quote;
    },

    'tickets:pay': async (values) => {
      const session = requireSession();
      const ticket = ctx.store.payTicket(session.user, values.id, { cash_received: values.cash_received, data: values.data });
      ctx.sync.kick();
      let printed = null;
      const settings = printSettings();
      if (settings.auto_print) printed = await printing.print(BrowserWindow, printing.receipt(ticket, branchInfo(ticket.branch), settings), settings);
      if (settings.kitchen_auto) {
        await printing.print(BrowserWindow, printing.kitchen(ticket, ticket.totals, branchInfo(ticket.branch), settings),
          { ...settings, printer: settings.kitchen_printer || settings.printer });
      }
      return { ticket, printed };
    },

    // The bill before payment; for a delivery order it also records that the order left with the driver.
    'tickets:bill': async (values) => {
      const session = requireSession();
      let ticket = ctx.store.ticket(values.id);
      if (!ticket || ticket.branch !== session.branch || ticket.status !== 'open') throw new Error('الفاتورة غير موجودة.');
      if (values.out) ticket = ctx.store.markOut(session.user, ticket.id, values.data || {});
      const catalog = ctx.store.catalog(ticket.branch) || {};
      const quote = ctx.store.quoteTicket(ticket.branch, ticket.channel, ticket.data).quote;
      const settings = printSettings();
      const printed = await printing.print(BrowserWindow, printing.bill(ticket, quote, branchInfo(ticket.branch),
        { ...settings, companies: catalog.delivery_companies || [] }), settings);
      return { ticket, printed };
    },

    'tickets:kitchen': async (values) => {
      const session = requireSession();
      const ticket = ctx.store.ticket(values.id);
      if (!ticket || ticket.branch !== session.branch) throw new Error('الفاتورة غير موجودة.');
      const quote = ctx.store.quoteTicket(ticket.branch, ticket.channel, ticket.data).quote;
      const settings = printSettings();
      return printing.print(BrowserWindow, printing.kitchen(ticket, quote, branchInfo(ticket.branch), settings),
        { ...settings, printer: settings.kitchen_printer || settings.printer });
    },

    'receipts:list': (values) => {
      const session = requireSession();
      const day = values.day || rules.operatingDay(now());
      return { day, today: rules.operatingDay(now()), summary: ctx.store.daySummary(session.branch, day), items: ctx.store.receipts(session.branch, day) };
    },

    'receipts:print': async (values) => {
      const session = requireSession();
      const ticket = ctx.store.ticket(values.id);
      if (!ticket || ticket.status !== 'paid' || ticket.branch !== session.branch) throw new Error('الفاتورة غير موجودة.');
      if (!session.user.can_reprint) throw new Error('إعادة طباعة الفواتير متاحة للمالك والإدارة فقط.');
      return printing.print(BrowserWindow, printing.receipt(ticket, branchInfo(ticket.branch), { ...printSettings(), copy: true }), printSettings());
    },

    'cash:summary': (values) => {
      const session = requireSession();
      const day = values.day || rules.operatingDay(now());
      const user = session.user;
      return { day, today: rules.operatingDay(now()), summary: ctx.store.cashSummary(session.branch, day), items: ctx.store.journal(session.branch, day),
        categories: ctx.store.expenseCategories(session.branch),
        can: { expense: user.can_checkout, approve: user.can_approve_expenses, till: user.can_manage, shift: user.can_close_shift } };
    },

    'cash:expense': (values) => {
      const session = requireSession();
      const entry = ctx.store.recordExpense(session.user, session.branch, values);
      ctx.sync.kick();
      return entry;
    },

    'cash:till': (values) => {
      const session = requireSession();
      const entry = ctx.store.recordTill(session.user, session.branch, values);
      ctx.sync.kick();
      return entry;
    },

    'attendance:board': () => {
      const session = requireSession();
      const day = rules.operatingDay(now());
      return { day, now: now().toISOString(), shifts: ctx.store.attendanceShifts(session.branch), items: ctx.store.attendanceBoard(session.branch, day) };
    },

    'attendance:punch': (values) => {
      const session = requireSession();
      const entry = ctx.store.recordPunch(session.user, session.branch, values);
      ctx.sync.kick();
      return entry;
    },

    'reports:sales': (values) => {
      const session = requireSession();
      if (!session.user.can_manage) throw new Error('التقارير متاحة للمالك والإدارة فقط.');
      const today = rules.operatingDay(now());
      const from = values.from || today;
      const to = values.to || from;
      const report = ctx.store.salesReport(session.branch, from, to);
      if (from === to) report.cash = ctx.store.cashSummary(session.branch, from);
      return { today, report };
    },

    'reports:print': async (values) => {
      const session = requireSession();
      const { report } = handlers['reports:sales'](values);
      return printing.print(BrowserWindow, printing.report(report, branchInfo(session.branch), printSettings()), printSettings());
    },
  };
  return handlers;
}

module.exports = { posHandlers };
