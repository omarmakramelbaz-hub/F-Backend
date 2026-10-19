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
