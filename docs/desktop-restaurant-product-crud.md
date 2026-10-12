# Original restaurant-product forms in the desktop journal

`DesktopDashboardRestaurantProductCrud` supports the three original forms through `Dashboard\ResturantController`, its existing FormRequest and repository. The original multipart forms, content validation, permissions and Arabic success messages remain the user interface. Desktop fact-capture boundaries may reject an operation before the original FormRequest runs. The adapter does not expose a separate product editor. `full_dashboard` remains `false`; copy-menu and the other unsupported dashboard writes remain outside this increment.

| Original route | Effective method | Native physical transport | Original action |
| --- | --- | --- | --- |
| `resturant_products.store` | POST | POST | `resturantProductsCreate` |
| `resturant_products.update` | PUT | POST with `_method=put`, or PUT | `resturantProductsUpdate` |
| `resturant_products.destroy` | DELETE | POST with `_method=delete`, or DELETE | `resturantProductsDelete` |

The update target comes from the original `id` form field. Delete retains the original `ResturantProduct` implicit binding. The effective route is established before desktop controls and `_method` are removed from the controller's mass-assignment input. Native reservation retains the physical HTTP method and never reads or rebuilds the multipart body. File bytes and paths do not enter reservation metadata or IPC.

## Original semantics

The original custom product actions inherit `IsAdmin`; the controller's resource permission lists do not include these methods, and the original product FormRequest authorizes them. The adapter checks current account status, enrollment and authority over both the saved product's branch and the submitted destination branch before a cached response or write. It then runs the original route middleware. An existing submitted `added_by` is retained, as the original FormRequest permits; it is not silently replaced with the current actor.

The original create image rule reads `request()->method` as a property, rather than calling `method()`. The adapter preserves the observed optional-image behavior. The original six extras are always written in this order: `extra_combo`, `extra_large`, `extra_medium`, `extra_clean`, `extra_clear`, `extra_vacuim`. Missing values remain JSON `null`. MySQL's original update grammar serializes the repository's array assignments; the model does not acquire a new cast. Update retains the source `AddSuccessfully` message; delete retains `DeleteSuccessfully`.

The original delete is a query delete. It therefore retains product media database rows and physical files. Desktop replacement clears only the original `product_image` collection's database rows and creates the new row through Spatie's original FileAdder. Every other collection remains unchanged.

## Complete facts and receipts

The authoritative deployed schema fixture has 13 product columns and 18 media columns. The adapter rejects unknown columns rather than dropping them. A fresh submission naming a missing creator, branch, category or catalog relationship cannot produce complete before-facts and fails closed with HTTP 409 before the original FormRequest, without a business mutation or accepted journal entry. Unsupported relationship shapes likewise fail at fact capture. When complete supported facts are available, the original FormRequest continues to validate the submitted content and return its original responses; exact original status parity is not claimed for invalid foreign-key submissions blocked by the desktop fact boundary. It captures the complete product row, its branch ownership links, creator identity, category, catalog product, every catalog feature, every associated media row, and bounded physical image proofs. Selected destination relationships are separately captured even when they differ from the old product's relationships.

The payload shape is:

- `parameters`: empty for create/update, or the original `resturant` product ID for delete.
- `values`: original supported form values plus the immutable `idempotency_key`.
- `files`: empty, or `product_image` with `name`, detected `mime`, `sha256` and immutable `base64`.
- `facts.restaurant_product_crud_before`: `product`, `target`, `images`, `delete_rules`.
- `facts.restaurant_product_crud_return`: the original encoded search/page query and its explicitly typed restaurant filter.

`product` is the existing nine-part product state, or null on create. `target` contains branch, creator, owner, parent, category, catalog and features, or null before delete. Each image proof contains the complete raw media `row` and a `files` list of `disk`, `relative`, `path`, `bytes`, `sha256`, and `mime`.

A receipt's `after` contains `product`, `target`, `media`, `images`, and `delete_rules`. After delete, `product` is null and `target` retains the unchanged old relationships. `media` and `images` retain the original orphaned rows and bytes. Output references are the branch, the surviving product, and explicitly numbered media slots. Existing media slots follow the submitted before-fact order, with a new upload last, so mapped server IDs cannot change slot meaning.

Typed translation names only restaurant products, restaurant branches, user identities, catalog categories/products/features, and product media. It never rewrites every integer or a price. Image proof paths are translated only at the validated leading media-ID component. All remaining file names, collection metadata, digests, byte counts and MIME types remain exact. Original return links preserve encoded `q`, `resturant_id`, and `page` from the same branch page and origin; only the named restaurant filter may be mapped.

## Transaction, media and replay boundaries

MySQL with InnoDB is required for every used product, media, branch, catalog, user/permission and journal table. Optional local/refresh/native-outcome tables are checked when present. Missing authority tables or nontransactional tables reject the operation before business work. Root callers wrap the entire journal transaction, including validation, original controller work, after-facts and outcome writes, in `DesktopDashboardProductImages::guard` and `DesktopDashboardProductEffects::guard`. Current authority is checked before image authorization or deletion suppression. The file helper creates a disposable UploadedFile from immutable captured bytes for each original-controller attempt and restores the original request bags afterward.

Reviewed uploads are PNG, JPEG or WebP, at most 5 MiB, with checked decoded dimensions and actual MIME type. SVG, unsupported disks/path generators, unreviewed media models, excessive collections/files and unsupported storage layouts fail before acceptance. Existing supported media has bounded primary/derived file proofs. Original ordinary online upload limits are unchanged.

Media writes use checked temporary files and atomic rename under the original local `products` disk. Fresh paths cannot overwrite different bytes. Obsolete authorized physical files remain retained even when the original collection database rows are replaced. This protects both a rolled-back outer transaction and a saved response later replayed after another replacement. Failed fresh writes may leave inert orphan files; reuse of a conflicting physical path fails visibly. Automatic cleanup is outside this increment.

A new guarded desktop media row receives a valid deterministic UUID from the immutable command UUID and its one upload slot. Ordinary online Spatie UUID generation is unchanged. For local create/edit commands, the journal records occurrence from the original product write timestamp, converted from the application timezone to UTC. Slow after-fact image verification therefore does not make a valid accepted command unreplayable. `replayContext(name, localResult, originalPayload, occurredAt, work)` validates source-generated product/media timestamps within five seconds of that recorded command occurrence, interprets their original naive strings in the application timezone (`Africa/Cairo` in the original source), pins them during the original server write, and restores the clock and scoped state afterward. A new image also retains its positive original `order_column`, validated above the retained product media orders outside the replaced collection; unrelated server media cannot change that identity fact. Existing and newly created UUIDs, creation times and subsequent complete before-facts are compared exactly. They are not excluded to hide drift.

A delete inspects live `INFORMATION_SCHEMA` inbound rules. Only the reviewed `carts.resturant_product_id` restrictive/no-action relationship, or no inbound relationship, is accepted. Unknown relationships, `CASCADE` and `SET NULL` are rejected before deletion. A restrictive cart prevents deletion through the original database constraint and rolls back the journal operation.

The immutable logical UUID identifies create, edit and delete; an attempt UUID identifies each prepared native transport. Reusing a logical UUID with changed fields or image bytes conflicts. Saved replies are checked against current enrollment/branch authority and recorded physical image proofs. A lost response can return the committed receipt without another product, media row or provider event.

Original product update broadcasts are skipped for local desktop writes. Prepared remote/replay writes use the existing provider delivery boundary after the outer transaction commits; ordinary online behavior remains unchanged. This is deferred delivery, not a durable provider outbox, and no durable provider-delivery guarantee is claimed.

## Acceptance evidence

The backend suites are `restaurant-product-crud.php` and `restaurant-product-crud-remote.php`; the real original browser pair is `restaurant-product-crud-browser-http.php` and `restaurant-product-crud-browser.cjs`. They exercise actual multipart routes, optional-image create/update, replacement and deletion, six extras, unrelated collection preservation, native lost responses, immutable UUID conflicts, authorization revocation, complete before-state conflicts, image loss/rollback, restrictive deletion, and explicit typed ID drift across a dependent image chain. Required backend markers are `RESTAURANT_PRODUCT_CRUD_COMPLETE` and `RESTAURANT_PRODUCT_CRUD_REMOTE_COMPLETE`.

This workspace has no usable local PHP runtime. PHP/MariaDB/original Edge acceptance must pass in the required Windows workflow before this increment is reported as accepted. Node native transport tests run locally.
