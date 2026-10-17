# Phone customer Enter flow and branch header

This release applies to the confirmed deployed revision `642dbfe4be0dc1097435bdb81e2606804b8b8955`. On the customer details step, Enter in the name field focuses location confirmation. Enter on that confirmation button confirms the location and advances to products only after validation succeeds. Mouse confirmation keeps its existing behavior. Branch header styling makes the selected branch easier to read.

Step controls are enabled before focus moves, so the products search receives focus after customer saving. Confirmation failures return focus to the pin button; invalid customer details keep the existing validation. Repeated, modified and composing Enter events cannot confirm or advance. Native and Selectize branch labels use larger text, and placeholder changes target only the empty option so an enhanced selector cannot rename the active branch.

The entire production source manifest is two modified files:

```text
M public/dashboard/js/phone-orders.js
M public/dashboard/css/phone-orders.css
```

## Install

Fetch the reviewed source revision and verify the root wrapper against its published blob hash. Run it as root with the full 40-character revision:

```bash
bash apply_phone_customer_enter_flow_root.sh FULL_RELEASE_REVISION --check
bash apply_phone_customer_enter_flow_root.sh FULL_RELEASE_REVISION
```

The wrapper verifies the Python installer blob from that exact revision and runs it as `fasakha` using the configured cPanel PHP 8.2 executable for `artisan view:clear`. The installer verifies ancestry, the exact source manifest and release modes, stages both targeted patches, and rechecks live bytes and modes before publication. It preserves unrelated local JavaScript and CSS changes. Overlapping changes, missing sources or symlinks stop publication. No earlier installer, PHP source change, migration, worktree reset or index update is included.

`--check` leaves application files unchanged and does not clear views. Reverse checks recognize an already installed patch before checking forward applicability, so a rerun creates no further backup and does not repeat cache clearing.

Private backups in `phone-customer-enter-flow-backups` beside the application contain exact prior bytes, the applied patches and a receipt with SHA-256 hashes. After publication, the installer verifies live sources and clears views. A failure restores exact original bytes and modes. Independently changed live source is preserved with the coherent source group retained and reported for review.

Success marker: `PHONE_CUSTOMER_ENTER_FLOW_OK files=2; BACKUP=...`. A rerun prints `PHONE_CUSTOMER_ENTER_FLOW_OK already_current=1 files=0`. Rollback markers are `PHONE_CUSTOMER_ENTER_FLOW_CODE_RESTORED` and `PHONE_CUSTOMER_ENTER_FLOW_ROLLBACK_REVIEW_REQUIRED`.

## Verification

```bash
python3 tests/phone_customer_enter_flow_installer_test.py
PHONE_INSTALLER_TEST_PHP=/absolute/path/to/php python3 tests/phone_customer_enter_flow_installer_test.py
bash -n deployment/apply_phone_customer_enter_flow_root.sh
```

Disposable repositories cover preserved unrelated changes, a later CSS conflict preventing JavaScript publication, check-only, idempotence, rollback of both files with original modes, concurrent edits and symlinks. The optional actual-source test starts from the pinned deployed baseline and checks a real overlapping hunk conflict, preflight without source changes, preservation of local JavaScript/CSS, publication and rerun. Its Laravel cache command is stubbed; no application or production cache is accessed.
