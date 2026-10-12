# Original parent-menu copy in the desktop journal

`DesktopDashboardRestaurantMenuCopy` prepares the original `copy_menu` form: POST `/admin/resturant/copy/items`, handled by `Api\V1\Vendor\ResturantProductController@copy_menu` and `CopyMenuRequest`. The Blade modal, checkbox order, multipart browser submission, CSRF and original success redirect remain in use. It has no upload field or method override. One immutable logical UUID identifies the whole ordered batch; native attempt UUIDs identify individual transmissions. Native code never reads or rebuilds the multipart body.

This supports the original HTML parent-to-child form. Every selected source product must belong to the destination's distinct `parent_id`, and both parent and child must be currently enrolled and owned by the device actor. General vendor API copying, arbitrary source branches, self-copy and JSON success responses remain outside the prepared route. `full_dashboard` remains `false`.

## Source behavior retained

The original action inherits `web`, `lang` and `IsAdmin`. Its API controller has no additional permission middleware, and `CopyMenuRequest::authorize()` returns true. The desktop adapter adds the existing current enrollment/account/branch boundaries before a write or saved outcome. It checks the original scoped destination model and uses its freshly authorized admin actor as the recorded creator. This prepared HTML path does not initialize the API JWT guard or require an API signing secret; the desktop runtime intentionally has no local API JWT secret. Vendor API copying remains outside this adapter and retains its API actor.

The controller reads the destination's `resturant_products` relationship once, ordered by `category_id`. For each submitted source ID, it finds the source and checks that cached destination collection for the source `product_id`:

- An existing match receives only `product_price`. Names, description, six-extra JSON, flags, creator, category, catalog and images remain unchanged.
- A missing match replicates every source business field except ID and timestamps, changes destination and creator, then saves. It copies only the source's first `product_image`, rather than copying every collection or its metadata.
- A new replica never enters the cached collection. Repeated selections for an initially absent catalog therefore produce separate replicas. Repeated existing matches receive prices in the submitted order; the last selected source determines the final price.

The adapter preserves these duplicate and ordered semantics. It rejects ambiguous duplicate catalog matches already present in the destination. It also rejects ties for the first source image's `order_column`, because a translated ID can change an otherwise unspecified first-row choice. Source and destination are distinct branches, so this original form does not involve a source row being mutated by an earlier destination update. Existing equal-price operations can leave an old `updated_at` untouched; a no-op still receives a new accepted batch UUID and a current batch occurrence.

The action directly uses the models. It does not call the product repository or emit its product-update broadcast. Normal Spatie file events remain in use; the product model registers no conversions. No durable provider outbox or full dashboard coverage is claimed.

## Reviewed original image-copy correction

The original image block converted a generated URL into a path on the `public` disk and then copied through `tempnam()`. Named `products` storage has a nested physical root, while ordinary disk URL configuration need not include that prefix. A URL-derived public path could therefore fail or name different bytes. Temporary basenames also lost the source image extension and name, preventing a subsequent complete offline image manifest.

The controller now resolves the same first media through `getFirstMediaPath('product_image', 'thumb')`, preserves the source file, and passes its original `name` and `file_name` to FileAdder. Media lookup still occurs after replication and save, preserving the original absence of loaded media relations on the replica. The fix applies to the original online action as well as prepared requests. It removes the copy operation's dependence on URL-to-disk guessing and temporary files; it does not redefine the application's unrelated public URL configuration.

Inside the desktop image guard, `configureCopies(command, orderedPlan)` lists only selections that actually create a product with an image. Each entry contains `selection`, `source_product`, the exact selected image proof, and optional trusted replay metadata. `withMenuCopy(selection, source, destination, callback)` checks the actual first media, canonical physical path, source bytes and authorized fresh destination before the original FileAdder runs. `assertCopiesComplete()` confirms the plan was consumed exactly once.

Each new media row receives a deterministic valid UUID with slot `menu_copy:<selection>:product_image:0`. It retains source name, file name, MIME and bytes, while its fresh metadata comes from the original FileAdder. Existing source/custom media metadata is not copied wholesale. Replay pins only the recorded new creation timestamps and positive ordered media counter; it does not ignore UUID, path, creation-time or metadata differences.

## Complete facts and identity translation

Before-facts contain:

| Key | Contents |
| --- | --- |
| `target` | Complete 29-column restaurant row, creator, owner and parent identity |
| `actor` | Current recorded creator identity |
| `products` | Complete original destination membership in observed relationship order, with all 13 product columns and branch/user/category/catalog/feature/media relationships |
| `sources` | One complete product state per submitted selection, including duplicates |
| `source_images` | Physical image proofs aligned with each source selection |
| `target_images` | Physical image proofs aligned with each initial destination product |

These are under `facts.restaurant_menu_copy_before`. `facts.restaurant_menu_copy_return` preserves the original encoded `q`, `resturant_id` and `page` return context from the same child page and origin. Only the explicitly named restaurant filter may be translated.

A result's `after` contains `target`, `products`, flattened `images`, ordered `steps`, `started_at`, and `completed_at`. Destination products follow their immutable initial before-fact order, followed by new products in selection order. Each step records its selection, source, target, `create`/`price` mode, source media and new media. Output slots are `menu_restaurant`, `menu_restaurant_product.<selection>` for every step, and `menu_product_media.<selection>` only for actual copies. Database ID order does not choose slot meaning.

Translation rewrites only declared product, restaurant, user, area, catalog category/product/feature and media identities. Media paths are rebased only at their validated leading media-ID component. The submitted source list and steps preserve order and duplicates. Complete destination membership comparisons tolerate a changed numeric ID order while rejecting additions, removals or business-data drift.

## Atomicity, limits and replay clocks

All root flows wrap the complete database transaction in the existing product image guard. Used authority, product, media, catalog and journal tables must be MySQL/InnoDB. Before-facts and their physical proofs are compared before controller work. Original controller errors and validation-error flashes are propagated before checking successful image-copy completeness, and the surrounding transaction rolls back. After-facts prove that source products and images remain unchanged, old destination media remains intact, and the whole destination membership matches precisely the planned replicas and price updates. A later row/journal/outcome failure rolls back database effects while retaining old bytes. New files use durable temporary writes and checked atomic rename, with conflicting-path reuse rejected. Orphan cleanup remains outside this increment.

Selected batches are capped at 100 entries, with at most 500 initial destination products. Aggregate known image proofs are bounded to 2,000 files and 256 MiB; catalog feature facts are bounded to 10,000. Actual copied images are PNG, JPEG or WebP, at most 5 MiB each and 50 MiB across the batch. Unsupported schemas, disks, path generators, malformed media, generated unregistered thumbnails, incomplete relationships and larger batches fail closed before acceptance. Missing or unsupported relationships may therefore return a desktop HTTP 409 before the original FormRequest. Complete supported facts retain the original content validation and success response.

The journal records current UTC time immediately before batch work. Capture records UTC start before repeated proof hashing, and completion after complete after-facts, with a maximum ten-minute window. This occurrence is independent of old target timestamps. `replayContext(name, localResult, originalPayload, occurredAt, work)` requires start within five seconds of the immutable envelope and validates new source-model timestamps in their original application timezone against the recorded batch window. Scoped listeners pin new product times and final changed-target times. Repeated price writes suppress automatic timestamps only on the active model and restore its setting after save or failure. True no-ops preserve their old timestamp. The clock and listener state are restored in `finally`.

Saved outcomes recheck current parent/child authority and physical destination image proofs. If a surviving destination product, including one created by this batch, moves outside its recorded branch, cached/recovered output is denied. Losing a response therefore replays one complete original receipt, without additional products, images or price writes. Changing destination, selection order, selected IDs or duplicates under that UUID conflicts.

## Verification boundary

The PHP and original browser fixtures execute the actual controller, request, models, FileAdder, MySQL schema, CSRF and original checkbox form. They cover multiple images, image-free clones, existing-price updates, duplicate semantics, exact hash/name/metadata preservation, typed product/media drift, current authority, source changes, rollback, lost replies and cancellation. Required CI evidence is the Windows PHP/MariaDB/original Edge workflow; this workspace has no usable local PHP runtime. Native and renderer transport tests run locally. The work remains unaccepted until the exact candidate passes the required workflow.
