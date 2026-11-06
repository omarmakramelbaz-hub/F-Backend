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
public media disk so its URL resolves under `/storage/`. No stock quantities or
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
