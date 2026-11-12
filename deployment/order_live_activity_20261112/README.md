# Order Live Activity stage repair

Paired customer change: F-customer PR #22. The iOS app must be rebuilt and installed; a web deployment cannot update a lock-screen extension.

The release registers the actual native activity ID, Apple update token and FCM registration token for the authenticated order owner. Tokens are encrypted at rest. FCM HTTP v1 sends typed ActivityKit content state directly to iOS while the app is suspended or terminated. Foreground tracking uses the shared authenticated user socket and order REST refresh. Completed/cancelled activities carry their final state and dismiss after two minutes.

Model status changes dispatch after the response. The existing minute scheduler reconciles bulk updates and retries failed delivery. It only sends to active, owned, unexpired registrations; registration lifetime is at most eight hours. No extra queue worker is required.

## Server activation

Use the existing authorized root console on `srv1836524`, app root `/home/fasakha/public_html`. Download `run_root.sh` from the reviewed commit, verify its SHA-256 and invoke it with that commit and `apply`. `check` performs a read-only preflight. The package verifies payload hashes and the event baseline, inserts four bounded hooks while preserving unrelated edits, lints proposed files, backs up changed files, runs only the additive activity-table migration, clears caches, verifies routes and runs reconciliation once. It does not reset the checkout or run unrelated migrations. Existing FCM server credentials are required.

On failure the package restores only its file changes. The additive registration table is preserved. The printed backup can be supplied as `rollback BACKUP_PATH` to the same pinned script. Files changed since publication are not overwritten by rollback.

## Verification

Backend tests cover registration ownership, encryption, token rotation, stages, terminal state, retries and scheduler catch-up. Publisher tests cover repeat execution, rollback, unknown baselines, preservation of unrelated changes and symlink refusal. iOS CI compiles the real app and extension.

Final device acceptance: install the updated iOS build, place one test order, lock the phone, change pending → shipped → completed from the dashboard, and confirm each stage and the final two-minute completed state. Repeat with cancellation and two orders. This acceptance requires an actual iPhone and activated backend; a successful unsigned build alone does not prove APNs delivery.
