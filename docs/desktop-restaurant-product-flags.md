# Original restaurant product links offline

This increment supports the existing restaurant product **visibility** and **highest rated** links through their original Laravel controllers. Their original dashboard page, labels, nullable enum behavior, and branch redirect are retained.

| Original route | Original request | Original field |
| --- | --- | --- |
| `resturant_products.update.status` | `GET /admin/resturant_products/{id}/update/status` | `status`: `show`, `hide`, or absent/NULL |
| `resturant_products.update.highest_rated` | `GET /admin/resturant_products/{id}/update/highest_rated` | `highest_rated`: `yes`, `no`, or absent/NULL |

The desktop records one immutable command UUID before sending either link. A lost local redirect returns the retained local result. Its original same-branch search context (`q`, `resturant_id`, and `page`) is preserved with the exact encoded query; the restaurant filter may refer to the parent instead of the displayed branch. Replay translates only that named restaurant filter if its typed ID changed. A native online transmission reserves an independent attempt UUID; recovery determines whether that transmission committed or was terminally cancelled. A second transmission keeps the same logical UUID. Changed data cannot reuse a saved operation.

The adapter pins the original route, controller action, URI, method list, and `IsAdmin` middleware. Current account status, device enrollment, enrolled F branch, restaurant ownership, and original controller middleware are checked before returning any saved local, reconciliation, or native result. A product moved to a different branch cannot use its earlier saved scope. HEAD probes cannot execute desktop product writes.

The encrypted before-state includes all 13 inspected `resturant_products` columns, including its creation identity, its branch ownership/parent relationship, complete original catalog/category rows, product feature rows, and all associated Spatie media database rows. Replay checks those facts before invoking the original controller. Capture verifies that only the requested enum and its update timestamp changed. Reconciliation compares the actual complete server/local outcomes and rolls back on disagreement. The controller write timestamp alone is excluded from comparison because it may precede the recorded command occurrence by one second; all other business values, creation timestamps, and relation metadata remain exact.

Only explicit typed product, restaurant, catalog, user, feature, and media references are translated. IDs stored in prices or other text are untouched. Acceptance tests exercise an independent server product/media ID collision and prove the unrelated server integer is preserved.

These two actions perform no upload, delete, broadcast, or provider call. Existing media bytes are neither opened nor modified by either original controller. Media-row identity and metadata are verified unchanged; this increment does not claim byte-level media transfer support for product writes.

**Still unsupported:** original product creation, full product editing, product deletion, menu copying, and SVG/image upload or replacement. Full editing clears media files outside the SQL transaction and broadcasts to a provider; copying may replicate several products and conversion files. Those actions need a durable media operation protocol and transactional provider outbox before they can be accepted offline. Full-dashboard readiness remains false.

Validation is in `tests/desktop_dashboard_runtime/restaurant-products.php` and `restaurant-products-remote.php`, integrated into the real Laravel/MariaDB fixture. The original anchor transport and native attempt path are also covered by the desktop Node tests.
