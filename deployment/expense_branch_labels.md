# Expense branch labels and editable stock category names

Expense records now expose the shared `category_base_name` and a branch-qualified `category_name`, so list, details, CSV and printed reports distinguish the actual receiving branch. Form choices follow the selected receiving branch, and category management indicates the selected branch scope without storing that suffix in the shared category name.

The owner can rename all six ingredient purchase categories. Their stable keys and original aliases retain the inventory mapping even after an arbitrary display rename; kilograms remain mandatory and the ingredient ledger is unchanged by a rename. Existing category permissions, replay protection, revision checks and shared-group updates are preserved. Stock identities remain protected from deletion. A compact quantity-field margin keeps the desktop form visible at 1024 by 650 pixels.

The pinned root wrapper invokes a targeted installer based on commit `24b22fed1c1a0bf06eea88a4c26f8fac00ce1535`. It applies only the six declared runtime file hunks, preserving the observed legacy attachment implementation and unrelated live changes. It creates private backups, supports a read-only application check, verifies complete candidates, handles reruns and performs guarded code rollback on failure. It runs no migrations and does not require or modify desktop export files.

Verification: 275 actual-service SQLite checks pass for both the standard and patched legacy expense class; 11 real-source installer tests pass with actual PHP and JavaScript parsing; 28 DOM checks pass. Chromium checks passed at 1366x768, 1024x650, 1920x1080 and 390x844, with no desktop form scrolling or horizontal page overflow. Live activation is performed separately on the server.
