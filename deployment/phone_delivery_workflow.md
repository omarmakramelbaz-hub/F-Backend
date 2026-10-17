# Branch delivery tariffs and phone workflow

This release applies to the confirmed deployed revision `9f4cce8792467d1899927066f2c951cb2fa10cb5`. It updates branch tariff calculation and the phone intake/queue workflow through existing endpoints. No database migration or earlier installer is run.

## Tariff compatibility

The reference is [F-customer checkout at ecec873](https://github.com/omarmakramelbaz-hub/F-customer/blob/ecec8734d3ec039022d7cdd854f9b38ba73350a3/lib/view/layout/cart/screen/execute_the_order_screen.dart), using geolocator 14.0.2 / geolocator_platform_interface 4.2.6. The direct Haversine distance uses a 6,378,137-metre radius; the original app's nearest-kilometre tier selection is preserved. Rounded 0 uses `default_0_1`, rounded 1 uses `default_1_2`, and rounded 2 or 3 uses this branch's `default_2_3`. The rounded-3 case intentionally reads the branch rather than the app's old global setting, as requested. Longer distances use the full unrounded direct distance times the branch's `km_price`. A zero per-kilometre rate retains the app's free-delivery behavior.

Empty/invalid selected branch tiers reject the quote rather than borrowing global prices or silently charging zero. Older schemas with no tier columns retain their explicit branch per-kilometre rate. Authorized linked-store origins supply their own tariffs while fulfillment, stock and printing remain scoped to the physical branch. Existing explicit per-order manual fees remain available and audited.

Road geometry and road distance remain visible but do not determine the payable fee or invalidate its hash when only the visual route changes. Every branch tariff field is included in the authoritative quote snapshot, so changed rates require a fresh confirmation. Existing saved/paid order amounts are not rewritten.

The tracking board is visible for phone/customer entry and hidden during product selection. An empty or zero pending quantity defaults to one product unit; explicit positive quantities retain their validation. Confirmed sends reset the draft to the new-customer step and restore tracking. Failed or uncertain sends retain the product step and draft.

The exact source manifest contains one added helper and seven modified files:

```text
A app/Services/Dashboard/BranchDeliveryTariff.php
M app/Services/Dashboard/PhoneDelivery.php
M app/Services/Dashboard/FasakhanstaDeliveryOrigin.php
M public/dashboard/js/phone-orders.js
M public/dashboard/css/phone-orders.css
M resources/lang/ar/phone_orders.php
M resources/lang/en/phone_orders.php
M resources/views/admin/pos_service/context.blade.php
```

## Install

Fetch the reviewed release commit and verify the root wrapper against its published blob hash. Run it as root with the full 40-character source revision:

```bash
bash apply_phone_delivery_workflow_root.sh FULL_RELEASE_REVISION --check
bash apply_phone_delivery_workflow_root.sh FULL_RELEASE_REVISION
```

The wrapper verifies the Python installer blob from that revision and runs it as `fasakha` using the configured cPanel PHP 8.2 executable. The installer verifies ancestry and the source manifest, stages every file, applies targeted patches preserving unrelated local edits, and lints all changed PHP before publishing. Existing invoice policy files are outside this update and do not gate it. Overlapping edits, an unrelated existing tariff helper, missing required files or symlinks stop the release before live source changes.

`--check` changes no application source and does not clear views. An exact reverse patch check recognizes installed files; reruns do not duplicate inserted code, replace the helper, create another backup, or clear views again. A partially installed file group is completed only when every remaining patch passes the same preflight.

Private backups beside the application in `phone-delivery-workflow-backups` contain exact prior bytes, the applied patch and a receipt with SHA-256 hashes. The receipt records that the added helper did not previously exist. Live source and modes are rechecked immediately before publication. Final checks run PHP lint and `artisan view:clear`.

Failure restores original bytes and modes and removes the added helper if this installation created it. Independently changed files are preserved and flagged for review with the coherent source group retained.

Success marker: `PHONE_DELIVERY_WORKFLOW_OK files=8; BACKUP=...`. A rerun prints `PHONE_DELIVERY_WORKFLOW_OK already_current=1 files=0`. Rollback markers distinguish `PHONE_DELIVERY_WORKFLOW_CODE_RESTORED` from `PHONE_DELIVERY_WORKFLOW_ROLLBACK_REVIEW_REQUIRED`.

## Verification

```bash
python3 tests/phone_delivery_workflow_installer_test.py
PHONE_INSTALLER_TEST_PHP=/absolute/path/to/php python3 tests/phone_delivery_workflow_installer_test.py
bash -n deployment/apply_phone_delivery_workflow_root.sh
```

Disposable repository tests exercise unrelated local and invoice changes, conflicts before publication, helper creation and protection, partial groups, idempotence, check-only, PHP syntax failure, cache failure rollback, exact modes, concurrent changes and symlink rejection. The optional actual-source test uses the pinned deployed baseline, real PHP syntax checks and an isolated repository. Only its Laravel view-cache command is stubbed.
