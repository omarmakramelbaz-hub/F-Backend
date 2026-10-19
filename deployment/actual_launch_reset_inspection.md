# Actual launch reset: live inspection

Owner request, 19 October 2026: reset all recorded employee advances, deductions and bonuses; count full employee attendance from 1 to 19 October 2026; zero recorded sales, invoices, expenses, inventory and cashier drawers before actual use.

This package only inspects the live database. It has no apply mode and never changes application files or database records. The live server has independent, preserving deployments, so repository migration files are insufficient evidence for destructive SQL.

The server wrapper runs the exact revision's inspector as `fasakha`, after checking host, path, ownership and blob integrity. It loads Laravel environment/configuration without booting application providers, then uses a database-enforced read-only transaction. Output contains counts, table/column definitions and source hashes. It excludes employee/customer names, phone numbers, addresses, message contents, queue payloads and credentials. The wrapper requires the existing Git commit to have been fetched.

Review the report before implementing the reset. In particular:

- Confirm every installed relationship, including live-only tables and references without foreign keys. Counts of non-`f:*` branches and unassigned restaurant orders are separate, not silently included in a reset.
- Review employees hired after October 1 or who left before October 19. Preserve employment dates and salary schedules; resolve any conflict with the requested attendance period before changing attendance.
- Back up affected data outside the document root and verify the backup before any write. Reset closed payroll snapshots together with the specified employee ledger, not only the visible daily totals.
- Quiesce application/queue/scheduler writers for the final backup and transaction. Account for offline devices, cached snapshots and queued operations so old transactions cannot reappear.
- Account for purchase follow-up records and linked deliveries so removed transactions do not trigger stale messages. This package sends no messages.
- Preserve users, customer identities/addresses/consents, catalogs/prices/recipes, staff/salaries, branches and configuration. Do not infer authorization to clear Go service/store financial data or customer wallets from this ERP launch request.
- Use transactional DML and explicit validation. Do not truncate tables, reset primary-key counters, disable referential checks, invoke model events or replace live application source.

Running the inspector does not mean the requested reset has been performed. The live output is needed to produce the exact backup and reset command.
