# Baladi bread expense inventory link

Adds the protected `purchase_baladi_bread` choice (`خبز بلدي`) to restaurant branch expense catalogs. It receives whole loaves into the existing `خبز بلدي` stock ingredient in `piece` units, within the existing expense transaction. Approval does not receive stock twice; rejection/void reverses the original receipt. Existing kilogram purchases retain decimal quantities.

The form labels bread quantities as loaves and validates whole numbers in both client and server. The legacy `quantity_kg` / `stock_quantity_kg` API keys are retained for client compatibility; `stock_unit` distinguishes pieces from kilograms. Pre-existing unlinked expenses retain their original replay behavior and never generate historical stock receipts.

## Production activation

Run `RUN_ROOT.sh FULL_COMMIT_SHA` as root on `srv1836524`, or add `--check` for a read-only preflight. The launcher verifies the installer digest, then executes as `fasakha`, with PHP 8.2 and `/home/fasakha/public_html`.

The installer applies exact source-context edits, preserving unrelated live changes, and stages/lints candidate services before application bootstrap. It checks the current primary business only; separate owner databases are not updated. It requires exactly one existing bread stock ingredient in pieces and does not create/convert ingredients or stock balances. It enumerates current restaurant branches from the existing access service, enables hidden bread choices, and hides old duplicate expense aliases. A private source/catalog backup is saved before changes. No sample expense or financial transaction is created during activation.

A source publication failure restores the original files. After source publication, catalog/setup failures leave the unit-aware source installed and can be resumed by rerunning the same command. A fresh PHP process verifies every branch after activation. Success ends with `BALADI_BREAD_APPLIED_AND_VERIFIED` and reports `BALADI_BREAD_ALL_BRANCHES_VERIFIED` with the actual branch count.

## Validation

- `tests/baladi_bread_purchases.php`: real Laravel 8 expense, cash, inventory, catalog and transaction behavior against isolated SQLite; access and stock-notification dependencies are test doubles. Requires `BREAD_TEST_AUTOLOAD` pointing to a Laravel 8 autoloader and PDO SQLite.
- `python3 tests/test_baladi_bread_install.py`: exact patches, reruns, dry run, conflict rejection, publication rollback, neighboring expense review edits, and pinned payloads.
- `tests/browser/baladi-bread.cjs`: real Blade expense form with Playwright scenarios at desktop/mobile widths, quantity labels/validation, submission and stock detail display. Requires `CRM_TEST_AUTOLOAD` and optionally `CRM_TEST_PHP` / `DESKTOP_TEST_CHROMIUM`. Chromium execution is restricted in the authoring environment; equivalent DOM logic was verified using the rendered Blade form and jsdom.
