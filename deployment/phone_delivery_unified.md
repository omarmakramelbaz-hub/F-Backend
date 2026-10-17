# Unified phone intake and delivery queues

The new screen keeps phone intake and the preparing, courier and finished queues visible together. It uses the existing phone order endpoints and permissions. Existing `?view=orders` links focus the same workspace. No database migration is required by this update.

## Supported installed versions

- `fe1d8499df06d72d3d482c18dc30792c043d7840`: preparation choices and required phone dispatch consent are already installed. Only the five new UI source paths are patched.
- `43dd121c06c37076a086e452a21b32d151df91f9`: the first-print policy is installed. The eight fixed preparation/consent prerequisite files are staged with the unified screen and published in one transaction. No earlier installer writes before this release finishes its complete preflight.
- An already installed unified screen is recognized through reverse patch checks. A rerun does not duplicate insertion-only hunks, create another backup, or repeat cache clearing.

The release source manifest is exactly:

```text
public/dashboard/js/phone-orders.js
public/dashboard/css/phone-orders.css
resources/views/admin/phone_orders/index.blade.php
resources/lang/ar/phone_orders.php
resources/lang/en/phone_orders.php
```

When required, prerequisite patches also update these fixed paths from the pinned `fe1d8499df06d72d3d482c18dc30792c043d7840` commit:

```text
app/Services/Dashboard/PosServiceTicket.php
app/Http/Controllers/Dashboard/PosServiceController.php
app/Services/Dashboard/TakeawayCatalog.php
```

All 13 first-print policy files must pass a full hunk proof after normalization of temporary copies. Missing or conflicting earlier policy changes stop installation with `PHONE_DELIVERY_UNIFIED_FIRST_PRINT_PREREQUISITE_REQUIRED` or a specific missing-file/review marker. They are not silently replaced.

## Installation and checks

Fetch the reviewed release commit, verify the root wrapper blob against its published hash, then run the wrapper as root with the full 40-character source revision:

```bash
bash apply_phone_delivery_unified_root.sh FULL_RELEASE_REVISION --check
bash apply_phone_delivery_unified_root.sh FULL_RELEASE_REVISION
```

The wrapper verifies the Python installer blob from that exact revision, uses the configured cPanel PHP 8.2 executable, and runs application work as `fasakha`. The Python installer validates both fixed prerequisite manifests and the five-file new source manifest. It stages every changed file, preserves unrelated local changes through targeted Git patches, rejects overlapping changes and symlinks, lints staged PHP, and proves prerequisites before writing live source. `--check` performs this preflight without changing application files or clearing views.

Private backups are written beside the application in `phone-delivery-unified-backups` with mode `0700`. Each backup includes the exact prior bytes, applied patch and receipt with SHA-256 hashes. Live files are rechecked before publication. Successful publication runs PHP lint and `artisan view:clear`. A failure restores exact prior files and modes. An independently changed file is preserved and reported for review with the coherent source group retained.

Success marker: `PHONE_DELIVERY_UNIFIED_OK files=N; BACKUP=...`. A rerun prints `PHONE_DELIVERY_UNIFIED_OK already_current=1 files=0`. Rollback markers distinguish `PHONE_DELIVERY_UNIFIED_CODE_RESTORED` from `PHONE_DELIVERY_UNIFIED_ROLLBACK_REVIEW_REQUIRED`.

## Verification

```bash
python3 tests/phone_delivery_unified_installer_test.py
PHONE_INSTALLER_TEST_PHP=/absolute/path/to/php python3 tests/phone_delivery_unified_installer_test.py
bash -n deployment/apply_phone_delivery_unified_root.sh
```

The disposable Git repository tests cover both baselines, unrelated local edits, conflict before any publication, partial file groups, idempotence, missing first-print policy, staged lint failure, rollback and modes, concurrent edits, and symlinks. The optional integration case uses the actual pinned source history, a real PHP executable for syntax checks and an isolated clone; only its `artisan view:clear` call is stubbed.
