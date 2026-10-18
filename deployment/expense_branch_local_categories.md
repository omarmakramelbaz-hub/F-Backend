# Independent branch expense categories

Base: activated release `c63d40490aedf9400e0e971b0abcb0874a3b4cb8`.

Expense category creation, renaming and removal now require one authorized branch. Each operation changes that branch's name, availability and revision only. The existing global definitions and names become a read-only baseline; historical expense keys and all six inventory material identities remain unchanged.

“Use basic expense categories” copies Mahalla's active canonical choices into the selected restaurant branch. Copies are independent. Repeating the operation adds missing choices without replacing local names, restoring locally removed choices, or duplicating existing names. The previous release's completed adoption receipts can materialize their recorded catalog once on retry. Completed legacy global edits and creates can replay read-only; new branchless edits are rejected.

The form and category manager use the selected branch's catalog. The manager heading identifies its branch. All-branch listings, details and exports resolve each expense's name from its own branch. Form drafts and acknowledged names survive listing refresh failures. Description remains optional; linked purchases still require positive kilograms.

The scoped additive migration adds `origin_branch` to custom definitions and a unique `(branch, category_key)` override table. It does not backfill or change expenses, cash, stock, existing names or revisions. Its down method intentionally preserves user data.

Deployment applies the migration before six mandatory caller files. Five optional desktop services are patched only when already present: schema enrollment, branch-scoped export, and device branch authorization keep the same isolation in desktop data and reconciliation. Missing desktop services are never created. The reviewed live attachment variant is retained. Private backups, file ownership/modes, safe reruns and inverse-hunk code rollback are preserved; additive schema is not dropped on failure.

Validation includes real SQLite integration for two-way branch rename isolation, local definitions, copy/reapply behavior, optimistic revisions, UUID replay, SQL failure rollback, per-row names, cash/stock preservation and desktop export scoping; actual Blade/JavaScript DOM tests; Chromium desktop/mobile layouts; and real-source installer preflight, partial migration, optional service and rollback tests.
