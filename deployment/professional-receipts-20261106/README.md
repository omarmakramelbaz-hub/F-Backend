# Compact professional receipts

The approved monochrome layout uses a centered 80 px logo, a 58 mm content card, readable wrapping, and wider numeric columns. Branch identity, saved quantities, preparation options, references, and financial totals remain in the receipt. Kitchen copies contain no prices or collection summary. Raw GPS and tariff implementation details stay on the order screen.

The takeaway receipt is also used for paid dine-in and phone orders and for unpaid bills. App/website board printing and the legacy `printReceipt` entry point use the same layout. The interactive app-order details page keeps its existing editor, action buttons, and scripts. Print markers, first-print grants, and automatic-print guards remain in place.

## Validation

Install the isolated dependencies under `tests/receipt_runtime` and run:

```bash
php tests/receipt_runtime/render.php "$PWD" "$PWD/tests/receipt_runtime/vendor/autoload.php" /tmp/receipt-render
python3 -m unittest discover -s tests -p test_professional_receipt_deployment.py -v
```

The fixture renderer runs without application bootstrap or database access. It renders 17 order states in Arabic and English using Laravel 8 Blade, including mixed payments, failed payments, unpaid bills, long addresses, large amounts, and escaped notes.

## Server rollout

Run `RUN_ROOT.sh RELEASE_SHA --check` or `RUN_ROOT.sh RELEASE_SHA --apply` as root on `srv1836524`. It downloads pinned, hash-verified source and verifies the staged layout with the server's existing vendor autoloader before installing any file.

Only the 12 allowlisted view and translation paths are eligible. Unknown source changes and symlinks stop the release. Existing file modes and ownership are preserved. The release makes a bounded code backup in `/home/fasakha/go-receipt-layout-backups`, clears the Blade view cache, rerenders all fixtures, and automatically restores the original files if verification fails. Repeating an already applied release creates no duplicate code backup. It makes no order, payment, stock, or till database changes.

Staged source directories are explicitly chmodded to 0755 and source files to 0444 so the site's account can render them even with the root launcher's umask 077. Backups and render output remain private. Verification failures identify the phase and a bounded synthetic fixture error; raw application stderr is not printed.

The Windows printer profile supplies the physical paper size. The content has safe outer margins on narrower profiles. Actual paper output must be checked on a branch printer after the server command completes.
