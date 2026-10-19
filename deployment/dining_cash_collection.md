# Show paid amount only at final collection

Baseline: `e1f65ec1b1066ddaf4bc228595c61b1b74749006`.

The dining paid-amount field starts hidden and disabled in the Blade markup. It remains hidden while preparing, sending to the kitchen, loading a table, and printing or reopening a bill. The existing final collection button becomes available once the bill has been printed. Clicking it, or pressing a distinct Enter on that button, reveals and focuses an empty paid-amount field without making a payment request. Enter in the visible amount field then uses the existing cash settlement validation and immutable operation flow. A second click on the final collection button can confirm the entered amount as well.

Kitchen submission no longer asks for cash. Consent Enter focuses the kitchen action before bill printing, the collection action after bill printing, and the paid amount once collection has been opened. Invalid or insufficient cash, held Enter, modifiers, composition, permissions, bill locks, and uncertain writes retain their guards. Successful settlement or switching/reloading a ticket clears and hides the field. Uncertain settlement keeps the field visible and frozen, and retry reuses the original operation and idempotency key.

The release patches only `public/dashboard/js/dining-pos.js` and `resources/views/admin/dining/index.blade.php`. The root wrapper requires `srv1836524`, verifies the Python installer blob, and runs as `fasakha`. The installer builds exact-context hunks from the pinned baseline and release, merges them into staged live snapshots, preserves compatible independent edits, rejects conflicts, and publishes the merged bytes atomically per file. Private original-file backups and hashed receipts are saved under `/home/fasakha/dining-cash-collection-backups/`. Repeats and per-file partial installs are supported. Owned changes are restored after publication failure; concurrent operator edits are retained for review.

No PHP service, route, database, migration, stored consent, or cache changes run. Blade uses its normal source modification check, and the existing JavaScript URL includes `filemtime`.

Validation:

```sh
node --check public/dashboard/js/dining-pos.js
node --check tests/browser/dining-compact.cjs
python3 -m unittest discover -s tests -p 'test_dining_cash_collection_update.py' -v
bash -n deployment/apply_dining_cash_collection_root.sh
CRM_TEST_PHP=/path/to/php CRM_TEST_AUTOLOAD=/path/to/Laravel/autoload.php node tests/browser/dining-compact.cjs
```

Run the hash-verified root wrapper with the full release SHA as its sole argument. The success marker is `DINING_CASH_COLLECTION_OK`; direct Python `--check` mode stages the patch without publishing application files.

Silent printing for dining, takeaway, and delivery continues through their existing shared `DashboardPrint` helper. The native desktop bridge uses silent printing. For the Windows Chrome cashier, the existing direct-print launcher activates `--kiosk-printing` in a dedicated profile and checks the Windows default printer. Download it from `https://fasakhaninja.com/dashboard/start-branch-pos-printing.cmd`, run it on the branch PC, then always open the created `Fasakhansta POS` desktop shortcut. The browser window shown in the report must be replaced by that configured cashier window. The server-only collection update does not rewrite the independently updated printer launcher or global print helper. Physical paper output still requires the actual branch printer and local Windows setup.

Compatibility correction after the first server preflight: the live JavaScript keeps independent all-branches/table-display changes adjacent to `resetInvoice` and `hydrate`. The installer now trims only that identified hunk to the unchanged `validTicket`/`mayDiscard` context and the full old/new reset/hydrate lines. It validates the hunk structure and requires exactly one old or new retained block, rejecting duplicated, mixed, and unknown overlapping edits. All other hunks still use their original three-line context; ordinary `git apply` remains in use. No source file is copied wholesale, and source line endings are not normalized.

The inspected live asset had SHA256 `7172f8733978e89156635291f98828f0654eb0eeaa4e1be1c1d1f7e993a82741`; the reviewed merged output had SHA256 `a6cd60d392f32fde807b6dca42cfd0add95423f521997dd5ca67c5022c6fd46e`. An actual-source fixture verified preflight, exact merged bytes, original backups, and repeat installation. Fourteen deployment fixture checks passed, including adjacent layout edits and ambiguous retained blocks. The browser suite also passed on the merged live JavaScript; its redundant table-number fixture assertion was replaced by the selected-table title assertion to follow the independent live layout, with all payment/Enter/consent/retry/recovery assertions retained.
