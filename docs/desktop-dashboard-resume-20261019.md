# Desktop dashboard continuation — measured 19 October 2026

The requested continuation includes every dashboard update in the Windows application, with the existing dashboard appearance and permissions, durable local operation and reconciliation. The earlier Windows preview is not evidence that the current production dashboard is fully supported offline.

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

## Complete measured report and missing source bytes

The compressed report received later on 19 October passed its length and SHA-256 checks. Its source map can now be reconstructed completely: 1,152 files, with all 275 differences present and the same normalized `c38f636cebcab54300358c4c039a2d18fe60e0b3df259200c8423f033a08ae65` fingerprint. Schema inspection at `2026-10-19T13:23:57+00:00` reports PHP 8.2.34, Laravel 8.83.29, MariaDB 10.11.19, 156 tables and 669 routes. Only the original desktop POS migration is recorded (batch 47); full dashboard enrollment/activation is still unavailable.

Exact current bytes are retrievable for 1,106 source paths: 1,099 from repository objects and seven from existing release ZIPs, whose member bytes were checked against both observed hashes. Forty-six source versions remain unavailable; an identity report cannot reproduce their code. The catalog records retrievable blob identities, including the current custom frontend assets. It contains no captured production source or business rows.

`deployment/capture_desktop_dashboard_source_root.sh <reviewed-commit>` reruns the existing complete inspection and packages only source blobs absent from that catalog. It also includes the complete source map and schema report so that the received bytes can be verified and reconstructed without inventing missing routes or services. Files changed since the earlier report are measured again. Source and frontend snapshots must remain identical throughout capture; a concurrent update stops the operation.

The output is a mode-0600 ZIP in an application-owned mode-0700 `desktop-source-evidence-*` directory directly under `/home/fasakha`, outside the website. The script prints its exact filename and SHA-256; it creates no download endpoint. Environment/key/credential/cache paths and business storage are excluded by the pinned reader. Existing archives are never overwritten or deleted. The operator can download this private code evidence through their authenticated server file manager and attach it to continue integration. Captured production bytes must be reviewed privately before considering repository publication.

The capture tests use disposable filesystem fixtures to verify exact bytes, private permissions, content-based deduplication, exclusion of credentials and business storage, stale-report and concurrent-write rejection, existing-file preservation, path/symlink rejection and bounded output. These checks do not establish Windows installation, production activation or complete offline module coverage.
