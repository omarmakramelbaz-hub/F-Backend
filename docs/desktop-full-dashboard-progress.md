# Original dashboard local runtime — implementation checkpoint

The requested product is the complete original dashboard in an installed Windows application: the same Blade pages, account permissions and business services, local operation during an outage, and complete reconciliation when service returns. The released 0.2.1 installer does not implement that product. This checkpoint is unfinished and must not be described or deployed as a completed full offline dashboard.

## Implemented and verified

- The complete original Laravel 8.83.29 application boots with its original routes in a private desktop storage directory. Production environment files and caches are excluded.
- Original business changes and encrypted command records commit in the same InnoDB transaction. A forced journal insert failure rolls back stock and financial changes.
- A separate account enrollment credential retains current server branch and account authorization. Revocation is checked before replaying an old result.
- Server imports dispatch to original business services, deduplicate stable command UUIDs, and map locally created entity IDs to server IDs. Seven linked flows are verified: goods receipt, cash sale, approved cash expense, employee creation, attendance, payroll advance and shift closing.
- Shift import compares the complete mapped financial review before reissuing its server HMAC. The local and server application keys are different. Local secrets do not become production secrets.
- Initial data export uses explicit table and branch rules and a consistent read transaction. Other branches, device journals, pairing credentials and server sessions are excluded. Only the enrolled user's password hash is retained for the original login mechanism; other included accounts cannot log in.
- Import validates data and schema hashes and foreign references in a new local staging database. Setup refuses to overwrite a populated database. The imported database gets its own empty encrypted journal.
- The actual loopback PHP HTTP gateway requires the application credential. Native outbox control needs an additional credential that is never injected into dashboard pages. Browser headers cannot acknowledge a sale. Private headers are stripped from external requests.
- Electron owns a PHP/MariaDB supervisor and native reconciliation loop. Shutdown drains accepted work before stopping local services. Local activation requires a completed preparation marker, which this incomplete checkpoint does not issue.

## Validation

`tests/desktop_dashboard_runtime/native-run.py` starts and deletes a disposable loopback MariaDB instance and runs the complete original Laravel application. The current fixture passes **62 checks**, including actual scoped export/import, financial rollback, ID collisions, lost responses, different application keys, permissions, revocation and protected HTTP control. It does not connect to production.

Electron's embedded Node test runner passes **37 tests**, including original limited-POS regressions, reconciliation ordering, lost acknowledgement retries, shutdown, printer response contracts and local header isolation. These are Node and mocked Electron contracts, not a physical Windows installation or printer test.

## Required remaining work

1. Inspect the actual legacy production schema. Repository migrations do not fully describe all existing tables. `deployment/desktop_dashboard_inspect.php` is a read-only structure/route inspector and excludes business rows, column defaults, configuration values and credentials.
2. Extend data scoping and reconciliation to all original administration modules and every write route, including legacy GET mutations, attachments and settings. The present route registry covers 29 core write routes; only the seven linked flows above have full integration coverage. Unknown local writes are rejected before execution.
3. Preserve authorized price and inventory snapshots when remote catalogs change; remap payroll and phone-delivery review facts before server authorization. A changed server revision currently remains a visible retained conflict.
4. Implement the original dashboard's preparation experience, download media and offline assets, stage/activate the verified dataset and recover pending work across upgrades. Initial export explicitly reports `full_dashboard: false` and `media: false`.
5. Refresh local data coherently after reconciliation before switching back to server operation. The native sync loop currently confirms commands; it does not implement that complete refresh or automatic server switch.
6. Finish the Windows PHP/MariaDB bundle, PHP configuration, VC runtime and Windows PDF dependencies, then test actual Windows initialization, original dashboard navigation, printer behavior, outages and restart recovery.

The legacy application dependencies required a test-only installation override of Composer's advisory blocking. The pinned runtime remains based on an old application stack and needs dependency review before distribution; do not silently disable this gate in a release build. No new Windows installer has been generated for this checkpoint.

## Deployment boundary

`desktop_dashboard.enabled` and `desktop_dashboard.local` default to false. Normal server dashboard requests keep their existing behavior. The prior POS root deployment helper does not apply the new full-dashboard journal migration or enable this feature. Do not run it to claim this checkpoint is enabled. Preserve production data and first review the exact deployed commit and schema.
