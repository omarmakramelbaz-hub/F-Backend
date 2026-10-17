# Phone delivery menu layout and single consent step

This release applies to the confirmed deployed revision `4189747822205224ced35b7980abe511af534540`. It updates the menu layout and removes the duplicate follow-up consent step from delivery collection dialogs. The required initial dispatch approval remains. Its entire production source manifest is two modified files:

```text
M public/dashboard/css/phone-orders.css
M public/dashboard/js/phone-orders.js
```

During product selection, desktop invoices use 260–340px and the quantity panel uses 180–240px according to viewport width; the menu takes the remaining space. Cards are at least 150px wide on desktop, with larger images, 14px names/prices and 11px stock text. Names and stock wrap without truncation. Small screens retain two columns and a taller menu. Customer entry, tracking visibility, quantities, pricing and send behavior keep the deployed workflow.

The installer uses targeted Git patches and preserves unrelated local CSS and JavaScript changes. Overlapping changes, missing source files, unexpected release modes and symlinks stop the installation before publication. No earlier policy installer, PHP source change, database migration, worktree reset or index update is included.

## Install

Fetch the reviewed release revision and verify the root wrapper against the published blob hash. Run it as root with the full 40-character source revision:

```bash
bash apply_phone_delivery_menu_layout_root.sh FULL_RELEASE_REVISION --check
bash apply_phone_delivery_menu_layout_root.sh FULL_RELEASE_REVISION
```

The wrapper verifies the Python installer blob from that exact revision and runs it as `fasakha` using the configured cPanel PHP 8.2 executable for `artisan view:clear`. The installer verifies ancestry, the exact two-file manifest and release modes, stages every patch, and rechecks live bytes and modes before publication. `--check` leaves application files unchanged and does not clear views.

Reverse checks recognize already installed patches before a forward check can reinsert an append-only CSS block. A rerun creates no further backup and does not repeat cache clearing. A partial file group completes only if all remaining patches pass preflight. Private backups in `phone-delivery-menu-layout-backups` beside the application contain exact prior bytes, the applied patches and a receipt with SHA-256 hashes.

After publication, live source is checked against its preflight result and views are cleared. Failure restores exact original bytes and modes. If another editor independently changed either file during installation, that change is preserved with the coherent source group retained, and the backup receipt reports that review is required.

Success marker: `PHONE_DELIVERY_MENU_LAYOUT_OK files=2; BACKUP=...`. A rerun prints `PHONE_DELIVERY_MENU_LAYOUT_OK already_current=1 files=0`. Rollback markers are `PHONE_DELIVERY_MENU_LAYOUT_CODE_RESTORED` or `PHONE_DELIVERY_MENU_LAYOUT_ROLLBACK_REVIEW_REQUIRED`.

## Verification

```bash
python3 tests/phone_delivery_menu_layout_installer_test.py
PHONE_INSTALLER_TEST_PHP=/absolute/path/to/php python3 tests/phone_delivery_menu_layout_installer_test.py
bash -n deployment/apply_phone_delivery_menu_layout_root.sh
```

Disposable repositories cover preservation of unrelated CSS and other files, conflict without publication, check-only, append-only idempotence, rollback of both files and modes, concurrent edits and symlinks. The optional actual-source test starts from the pinned deployed baseline and verifies preflight, publication and rerun with the actual release. Its Laravel view-cache command is stubbed; no application or production cache is accessed.
