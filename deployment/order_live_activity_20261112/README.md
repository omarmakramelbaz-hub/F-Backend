# Order Live Activity stage repair

The production inspection on 2026-11-12 found an existing, different live-activity implementation. Its model stores `activity_id_hash`, and its table includes retry fields and hashes absent from the new client contract. The original package correctly refused to replace that model. No production files had changed.

## Compatible activation

The new typed ActivityKit client registers at `POST /api/user/order-activity-subscriptions`; unregister uses the same path plus `/unregister`. It uses a dedicated encrypted registration model and the additive `order_activity_subscriptions` table. The existing `order_live_activities` table, model, controller and event remain byte-for-byte untouched by this publisher, whether the old table is empty or receives records after inspection. Existing legacy clients and workers continue to use their own registration path and state format.

The application code has one shared delivery service, observer, job and retry command. Its new private user event uses the same `status-updated` event name listened to by the app. The previous GitHub endpoint is an alias in source for clients built before the dedicated URL; the production alias/controller is never overwritten. Pair with the customer repository update to `Urls.liveActivities`. No widget or native ActivityKit changes are needed for this compatibility adjustment.

Use the existing authorized root console on `srv1836524`, app root `/home/fasakha/public_html`. Download `run_root.sh` from the reviewed commit, verify SHA-256 and pass the same commit with `apply`. `check` is read-only. The package verifies payload hashes, accepts only missing new files or exact previously published payloads, preserves unrelated production code, lints complete proposed files, backs up changes, runs only `2026_11_12_153000_create_order_activity_subscriptions_table.php`, clears caches, verifies authenticated routes and runs reconciliation once. It never resets the checkout or modifies the legacy table. FCM credentials reuse the existing private server file.

On failure file changes roll back; the additive new table is preserved. The printed backup can be passed as `rollback BACKUP_PATH` to the same pinned script. Concurrent edits are never overwritten by rollback. The minute scheduler retries failed delivery and reconciles bulk order changes; no extra worker is required. Activity credentials expire after eight hours, and final delivered/cancelled content remains for two minutes.

## Verification

Feature tests exercise the real authenticated endpoint, registration ownership, encryption, token rotation, stage updates, retries, terminal state and scheduler catch-up. A populated copy of the reported legacy schema is present during these tests and must remain unchanged. Publisher tests cover unknown legacy source files, PHP closing tags, repeat activation, rollback, unknown collisions, incomplete routes, syntax validation and symlinks.

Device acceptance still requires the activated backend and installed updated customer app: place a test order, lock the iPhone, change pending → shipped → completed in the dashboard, then confirm stage changes and final state. No App Store/TestFlight publication or real iPhone APNs acceptance is implied by source tests.
