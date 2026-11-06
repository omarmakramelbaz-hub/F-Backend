# Fasakhansta shift surplus menu additions — 2026-11-06

The owner requested four separate products in the existing drinks category for every
Fasakhansta physical branch and Fasakhansta retail store:

| Product | Price (EGP) | Image |
| --- | ---: | --- |
| زيادة وردية 1 جنيه | 1 | Egyptian one pound coin |
| زيادة وردية 5 جنيه | 5 | Egyptian five pound banknote |
| زيادة وردية 10 جنيه | 10 | Egyptian ten pound banknote |
| زيادة وردية 100 جنيه | 100 | Egyptian one hundred pound banknote |

The 100 EGP value was explicitly added in the same conversation. The three listed
initial values take precedence over the initial wording saying two products.

## Scope and behavior

Run only on `srv1836524`, against the primary application at
`/home/fasakha/public_html`. The live `FasakhanstaDeliveryOrigin` physical branch and
registered store constants identify the initial scope. Include their live descendants
to cover new retail stores. Exclude unrelated restaurants, GO partner catalogs, and
independent owner databases. Require exactly one visible root drinks category.

The script creates four shared catalog products and four corresponding menu entries
per scoped branch/store, with no preparation or size choices. Prices use normal EGP
menu prices, not cents. Attach each exact currency JPEG to `product_image` on the
original products media disk used by the desktop image contract. No stock quantities or
recipes are inferred for these monetary items.

`--check` reads without changing data. `--apply` holds an advisory lock and relevant
database rows, snapshots the plan outside the web root, performs all database writes
in one transaction, and validates all prices, links, and image files before commit.
An exact rerun makes no data writes. Ambiguous names, duplicate entries, conflicting
existing prices/categories, or conflicting images stop execution without overwrite.
On failure after media copying, delete only the files created by the failed attempt.

The pinned root runner downloads and checks every payload's SHA-256, runs planner
tests and a dry run before applying, then performs a fresh verification. It does not
check out application code, migrate the schema, or build the desktop application.

## Images and validation

`assets.json` records each source, author, license, and final SHA-256. The three
banknote scans are Central Bank of Egypt official documents marked public domain
on Wikimedia Commons. The coin photo was released to the public domain by Mabuhelwa.
Copies are reduced to at most 600 pixels and JPEG-compressed for menu display.

`test.php` checks the physical/store boundary, new descendants, missing branches,
all four values, exact reuse on rerun, Arabic numerals and whitespace, duplicate and
conflicting entries, category ambiguity/visibility, and all currency file hashes.
Run with PHP 8.1+; it does not load Laravel or connect to any database.

Successful final server marker: `SHIFT_SURPLUS_MENU_APPLIED_AND_VERIFIED`.

## Deployed schema compatibility

The initial server run stopped before any data writes because its preflight incorrectly
required `resturant_products.user_id`. The uploaded deployed structure fixture and
the original dashboard CRUD contract confirm that this column is absent: restaurant
ownership is `resturants.user_id`, while a menu row uses `resturant_id` and `added_by`.
The corrected release preserves those relationships, filters inserts to actual columns,
and writes the actual `product_description` column. It changes no schema.

`deployed-schema-columns.json` is a column-name-only extract of the five relevant tables
from `tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json`.
Regression tests validate this deployed shape, an older shape with the optional column,
all four insert payloads, and rejection of absent real price columns or menu tables.

## Existing image compatibility repair

The completed server release created 124 image rows on the public disk. A direct browser
request to the first coin URL succeeds, while the user's GO ERP screenshot shows all
four new items without their images. The original GO ERP product image facts and
copy/edit contract requires the products disk and canonical `products/{media_id}/{file}`
paths. New additions now follow that original contract.

`REPAIR_IMAGES_ROOT.sh` repairs only existing release-owned images. It preserves the
menu IDs, media IDs, prices, original image bytes, and working legacy URLs. It creates
verified immutable copies at the canonical products paths, updates the corresponding
media disk references and change timestamps, and verifies every image using the actual
installed `DesktopDashboardProductImages::facts` contract. Existing files with differing
bytes stop the operation. The root runner also downloads one actual generated URL per
denomination and verifies its served SHA-256. No desktop build or application code
checkout is performed. Reopening the desktop app obtains the normal updated media snapshot.

`test_image_repair.php` checks all four immutable copies, reruns, public file permissions,
preservation of originals, traversal rejection and refusal to overwrite conflicting files.
Successful repair marker: `SHIFT_SURPLUS_IMAGES_REPAIRED_AND_HTTP_VERIFIED`.
