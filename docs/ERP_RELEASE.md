# ERP production release handoff

The owner authorized backing up the live application before a direct production trial on 2026-09-30. No deployment or backup has been performed from ChatGPT. The available GitHub SSH identity is documented as deploy-only in `.github/workflows/go-services-launch-check.yml` and `deployment/launch_go_services.sh`. Do not use it to obtain an operator shell, put privileged commands into migrations, or merge to trigger deployment before the backup is confirmed.

## 1. Operator-console backup

Run the helper from an authorized hosting terminal as the application owner. Fetching this feature branch does not switch the live checkout. For the application path used in the most recent operator transcript:

```bash
runuser -u fasakha -- bash -c 'set -e; cd /home/fasakha/public_html; git fetch origin codex/fasakhansta-erp-foundation-20260930; erp_backup_script=$(mktemp); trap '\''rm -f "$erp_backup_script"'\'' EXIT; git show FETCH_HEAD:deployment/erp_backup.php > "$erp_backup_script"; php "$erp_backup_script" "$PWD"'
```

Confirm that this is the real application directory before running. The older GitHub deployment workflow names `/home/fasakhaninja/public_html`; this mismatch must be resolved from the actual server before release. The helper validates the repository root and MySQL connection and stops if the path is invalid.

The backup remains in a new mode-0700 directory at `../erp-release-backups/<UTC timestamp>-<random>/`, outside the web root. It includes:

- `application.tar.gz`: current code including local edits, Git metadata, `.env`, dependencies and uploads. Only logs, debugbar and regenerable Laravel caches, compiled views and sessions are excluded. External/broken symlinks cause a stop rather than an incomplete archive.
- `database.sql.gz`: consistent InnoDB snapshot including schema, data, triggers, routines and events. Nontransactional tables, split/custom DB connections, missing permissions or inadequate disk space cause a stop.
- `manifest.json`, `SHA256SUMS` and `backup.ok`: previous commit, integrity checks and completion marker. Dump success, gzip integrity and required archive entries are verified; this is **not** a full restore rehearsal or a VPS/OS snapshot.

Only a successful run prints `ERP_BACKUP_VERIFIED` and `NO_DEPLOYMENT_PERFORMED`. Failed directories are retained for diagnosis but have no `backup.ok`. Database credentials exist only in a private temporary client file and are deleted on completion/error. Do not upload archives, environment files or database contents to GitHub/CI/chat. The receipt and owner candidate IDs/names are sufficient for the next step.

## 2. Deployment gates after the receipt

Verify the actual application root and previous commit against the intended ERP release. Review any tracked server edits before changing code. Identify the platform owner's real admin ID from the candidate list; never assume ID 1. Check the current PHP/Laravel runtime and pending migrations on the live system. Existing tests exercise an isolated runtime; actual legacy schema/dependency compatibility must also be checked.

Apply only the two reviewed additive ERP migrations and enable `ERP_ENABLED=true` with the verified `ERP_OWNER_USER_ID` as described in `ERP_FOUNDATION.md`. The current main workflow runs **all** pending migrations automatically; it must not be used blindly for this release. Keep the new schema if a subsequent feature rollback is needed. Do not run the demo seeder or import sample financial data into production. Do not guess opening cash or inventory.

Verify `/admin/login`, `/erp` as the real owner, `/erp/login` as a newly created authorized staff account, and existing application/GO behavior. The owner must reconcile and initialize the accounting opening state before stock or cash posting. Preserve the backup receipt and record the deployed commit and activation checks before announcing the live URL.

## 3. Recovery

If ERP alone has a problem, disable its feature flag and clear cached configuration first. Preserve all order/customer/ERP tables and new transactions. If application code needs to return to the saved version, restore the backed-up code/environment after reviewing current changes; preserve new uploads and ongoing order data. Review dependencies and cache state with the operator.

Do **not** automatically import the old full database into the running application: orders and payments created after the snapshot would be lost. A full database recovery requires stopping writes, taking another current snapshot, rehearsing restore into a separate database and reconciling the intervening records before switching. No destructive database-restore command is provided by this helper.
