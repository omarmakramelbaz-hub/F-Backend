# Complete remaining dashboard service follow-up choices

The original batch applied to the pinned legacy import. This separate correction
also includes current customer-list records outside that import, matching the
central administrator's `all` branches, `all` sources, and empty-search scope.
The live permitted branches come from `BranchOperations::branches('all', actor)`;
global contacts and contacts without source rows follow the existing list rules.
Contacts associated only with inaccessible or stale branches are excluded.

Only default, valid-phone records without an existing recorded service decision
are activated. Current service approvals, global opt-outs, prior withdrawals,
marketing preferences, customer details, and unrelated tables are retained.
The audit evidence records an owner-directed bulk change without claiming an
individual verbal approval. No message, campaign, queue, or purchase is created.

The server entry stages code privately and runs it as fasakha. It defaults to
read-only checks. Apply requires a private before snapshot, uses a transaction
with customer row locks, and changes only service approval fields and updated_at.
Repeated application keeps existing choices. A ceiling of 500 newly eligible
records detects an unexpectedly broad operation before any data changes; the
reported remaining difference at preparation time was 155.

Validation: 11 local PDO/SQLite tests passed, including a complete synthetic list
with 43,160 visible records, 43,005 approved, and 155 default pending. Apply changes
exactly 155 and replay changes zero. Tests also cover inaccessible branches,
existing choices and STOP, marketing preservation, zero-write checks, private
backups, whitespace evidence, guard refusal, and full rollback on a later failure.
No production update is claimed until the server's apply output is available.
