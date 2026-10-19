# Desktop dashboard continuation — integrated production capture, 19 October 2026

The requested continuation includes every dashboard update in the Windows application, with the existing dashboard appearance and permissions, durable local operation and reconciliation. The earlier Windows preview is not evidence that the current production dashboard is fully supported offline.

## Current integration

The exact uploaded `desktop-source-for-pc.zip` passed SHA-256 verification (`29c4aa7daaf0a0fe80f2b122f570b3fea863917dbda34cca5c8fb7e0a7798285`). Its 17:31:56 UTC capture measures **1,157** source files, fingerprint `2ef5e2b7cae03101046094627e9f19d94165d75c7981cbff097de14eb13fab9f`, and **156** production table schemas. All source bytes were recovered, including the 56 captured live composites. The older inspection below remains historical evidence.

The Windows source now includes the captured dashboard changes: employee wallet details and monthly net totals, part-time schedules and branch hours, inventory purchase linkage, base recipes, stock minimums, carried shift cash and numbering, the CRM/consent interfaces, order editing, and current branding/alerts. Shared-file conflicts retain the desktop journal, offline assets, permissions and saved-address behavior. Bulk deletion retains its submitted selection during an in-flight response.

The initial dataset adds twelve explicitly reviewed table shapes. CRM customer identities follow the enrolled branch's source/purchase links; central admin status does not widen those links. Existing provider alert jobs and print claims are imported empty. Desktop scheduler registration is disabled locally. Stock minimum/base recipe writes use the original idempotent business services; checkout replay requires the live service-consent contract. Payroll reconciliation includes the branch/employee-hour facts. New category exports retain legacy global definitions and exclude categories owned by other branches. New branch-category replay and remote reservations check the enrolled device branch; reconciliation uses the locked current enrollment, including stale-device requests.

Validation completed locally: **247 Node tests**, **227 Laravel/MariaDB core checks**, **97 focused checks of the final data scope and stale-device protection**, and **104 deployment/source checks** (48 ownership-dependent checks skipped in this host). PHP syntax passed for 757 application files. The wider legacy UI suite and Windows/physical-print checks remain separate gates.

Preview version: **0.4.4-preview.1**. Full dashboard coverage stays **false**. Provider-backed messaging, unregistered write actions and physical printer behavior are not certified offline. New print claim/complete actions remain outside journal coverage pending a separate device-print lifecycle; closing the shift itself is still journaled. An installer requires the Windows source-bound checks and dependency release audit. No production activation or completed Windows installer is claimed by this source integration.

The SMS API secret previously embedded in a legacy API controller is replaced by `SMS_MISR_USERNAME`, `SMS_MISR_PASSWORD`, and `SMS_MISR_SENDER` configuration. Any future server application must provision those settings before that legacy SMS endpoint is used; offline mode never invokes it.


## Earlier inspection

The supplied read-only server report retained HEAD `ee52f1798a542d2c5625c863112315a8aaba02cf`, but measured **1,152 source files** with normalized fingerprint `c38f636cebcab54300358c4c039a2d18fe60e0b3df259200c8423f033a08ae65`. It found **275** differences from the previously measured baseline. Only the first **250** were present because the original reader truncated its output. No desktop publication, permission repair, PHP bootstrap or SQL read was performed by that inspection.

The supplied report cannot yet establish a complete release baseline. Source identities in this range span CRM and consent, stock minimums, basic recipes, inventory-linked expenses, part-time attendance/payroll, shift printing and order sequences, storefront fulfillment, order tracking, and messaging changes. Several current source blobs are surgical live composites rather than exact reachable repository blobs. Preserve them; do not substitute the nearest feature branch merely because its filename matches.

## Complete inspection

`deployment/inspect_desktop_dashboard_updates_root.sh <reviewed-commit>` stages independently pinned readers outside the application. It reads all source differences, inventories immediate `dashboard-*.js/css` files under `public/js` and `public/css`, and reads actual table/column/index/reference, route, desktop migration and activation metadata. The source is rechecked after schema inspection; changed evidence fails closed. It does not publish application files, repair application ownership, run migrations, enable desktop mode, export business rows, or print environment/configuration/source contents. Laravel's temporary package/service cache files stay in the disposable inspection directory.

The source reader has 14 filesystem tests, including more than 250 differences, new/removed frontend assets, concurrent file and directory changes, private-path exclusion, symlinks and nonregular files. The root launcher is syntax-checked and all seven staged payload hashes are verified locally. Its actual server execution is still required; the local environment has no production PHP runtime or server session.

## Integration requirements

- Reconstruct the complete current source map and obtain exact content for unmatched live composites before merging. Retain all later server behavior while carrying over desktop instrumentation in shared controllers, services, routes and layouts.
- Review the actual current schema and scope new branch/customer relationships before adding them to desktop export/import. Copying migration files does not prove existing tables match or that the snapshot contains their data.
- Align the newer takeaway checkout consent requirement with desktop replay. Current server controllers pass the explicit consent argument; the older desktop command dispatcher does not. Review its captured inputs and customer identity dependencies before enabling that replay against the updated service.
- Review numbering, stock/expense linkage, payroll facts and command references together. Preserve UUID idempotency and changed-fact conflict checks. Local schedulers and provider sends must not duplicate central jobs or external effects.
- Keep the existing incomplete coverage flag and release gates until module, Windows installation, outage/restart and physical printing checks establish the requested behavior.

No updated Windows installer or production activation is delivered at this inspection checkpoint.
