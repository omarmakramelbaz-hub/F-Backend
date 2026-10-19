# Branch employee wallet phone entry

The central employee-profile restriction also made the inline Vodafone Cash field read-only for branch staff. This patch restores the existing inline wallet-phone operation for employees in an authorized branch. Employee creation and profile editing remain restricted to central Owner/admin accounts; bonus creation remains primary-Owner-only.

The wallet operation continues to normalize Egyptian phone numbers, refresh the persisted actor and branch authorization, find the employee within that branch, require the current revision, and write through the existing idempotent operation log. It changes only the wallet phone and ordinary revision/audit fields. Salary, employment details, attendance, payroll and cash balances are outside this operation.

`apply_employee_wallet_branch_root.sh` verifies pinned Git blobs and live source anchors, runs PHP/JavaScript syntax checks, stores private copies of the three original files, and publishes incremental transforms. The installer is separate from the one-time launch reset. Existing unrelated source changes are retained; the patch can be applied again safely.

After deployment, refresh the employee page so its versioned JavaScript asset is loaded. The branch wallet field saves automatically when editing finishes.
