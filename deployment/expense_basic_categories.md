# Basic expense categories and optional description

Based on the activated expense branch-label release `c9014fb6a050e8b469e0a8368f509116fc8eaec9`.

The branch expense toolbar includes “استخدم بنود المصروفات الأساسية” beside Add category. The existing primary administrator category permission also controls this action. Select one restaurant branch first; All branches and GO stores cannot be destinations. The server resolves exactly one Mahalla branch and records the destination's adoption of its active canonical catalog with the existing category command UUID mechanism.

Categories already form a global shared vocabulary. The operation uses that catalog and records an auditable snapshot; it does not create duplicate names, restore removed categories, or create expense, inventory, or cash entries. Expense labels continue to show the destination branch. Repeating an uncertain request with its UUID returns the original snapshot alongside current choices.

Expense description is optional in the form and service. Missing, null and whitespace-only descriptions normalize to the existing non-null empty string. The 500-character limit and required positive kilograms for linked purchases remain enforced.

Deploy using `apply_expense_basic_categories_root.sh` pinned to the release revision. It applies only six reviewed source paths, preserves the activated server's legacy attachment implementation, and needs no migration or desktop bootstrap files. Preflight checks, private backups, original file permissions, idempotent reruns and inverse-hunk rollback are retained.

Validation: 365 real-service integration checks pass on both the normal and reviewed legacy attachment implementation; 11 installer tests cover preflight, rerun, unrelated live changes and rollback. Browser checks cover the actual Blade/JavaScript page and desktop/mobile layouts.
