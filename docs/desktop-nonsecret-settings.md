# Original nonsecret settings forms

The bounded desktop adapter executes the original `SettingsController::update` and
`update_advertising` routes. It accepts ordinary text settings, logo/favicon image replacement and advertising
restaurant/image selection, using each original PUT form's retained operation UUID.
It does not enable the promotion resource CRUD routes.

`GeneralSettings::save()` rewrites all 48 original properties. The adapter locks
and compares all 47 public nonfinancial rows before first work, and protects the
fresh locked `app_balance` inside both the original request and the Spatie
SavingSettings properties. The masked local balance is absent from immutable
commands and saved results. The local view explains this boundary; connected
views retain their original values and controls.

The root database transaction includes original settings writes, the encrypted
command/outcome and reconciliation acknowledgment. A scoped event dispatcher
holds only GeneralSettings SettingsSaved until that root commit. Enabled shared
settings caches and mutable resolved settings objects are cleared before and
after the operation, including rollback. The isolated Laravel 12 runtime's
reviewed UPDATE-only repository compatibility seam retains the original settings
schema and row identities; no unique index or data migration is introduced.
`config/settings.php` names the real fully qualified GeneralSettings class, so
cache clearing also works with actual Spatie Settings 3.

Current locking reads independently check the device, central administrative
actor, role/direct grants, permission catalog and complete structural F/GO
cohort, including GO owners temporarily declined. Authorization happens before
returning a cached local, reconciled or native result. Advertising restaurant
identities use named `menu_restaurant` references. A changed public before-state
retains the command as a conflict; it cannot overwrite newer central settings.

Provider credentials (`updateEnv`), payment-provider activation
(`updatePaymentActivation`) remain connected-only.
The local original views hide these controls with explicit guidance.
Image envelopes are limited to PNG/JPEG/WebP, 5 MiB total and 4096 pixels per
dimension; other formats and unverifiable legacy bytes are rejected before work.
This does not establish full-dashboard or promotion CRUD readiness.

Validation uses the actual original controller and generated application on
Laravel 8 and isolated Laravel 12, enabled shared settings cache and disposable
MariaDB/InnoDB. The fixture covers protected different central balances,
complete before/after facts, typed identity, full rollback on a real journal
INSERT failure, local exact retries, central replay/conflicts, current RR grant
and cohort changes, native reservation aliases/lost-response recovery and delayed
cancellation. Node tests execute the actual original-form script and native
reservation module. The Windows workflow runs the same PHP fixture; actual
combined Windows installation/browser evidence remains a release gate.

Image storage keeps the original controller and upload helper. A scoped Laravel
upload supplies deterministic slot/UUID/content names; its saved URLs use
canonical media-manifest paths. Old image bytes are compared and retained.
Known callback failures remove only files this command successfully created; a
failure after COMMIT was attempted retains the files. Both actual database and
public-media roots are separate in the offline/replay fixture. Local journal and
native outcome trigger failures prove atomic rollback of settings plus owned
bytes. Saved local, central and native replies verify the actual output bytes.

The frozen image increment passes 67 actual controller/InnoDB checks on Laravel
8 and 12 on Linux (the symbolic-link rejection is Linux-specific; Windows runs
the other 66). The source-bound Node cohort passes 49 tests without skips.
These are controller/bridge and original Blade-section checks, not an original
settings Edge browser UI acceptance claim.

Legacy connected images preserve their raw database paths. Only the exact
`/uploadedImages/<safe filename>` form and the observed original helper output
`/uploadedImages//settings-<timestamp>.<accepted image extension>` map to a
canonical media destination. Other doubled separators, foreign folders, schemes,
encoded paths and traversal remain rejected. The encrypted ticket binds the raw
source path; changing that path invalidates the previous ticket even when both
paths name the same file. SettingsController now passes its exact controlled
folder without the trailing slash, so future ordinary connected uploads avoid
the original helper's doubled separator. Other upload helper consumers keep
their original behavior.

The combined settings fixture passes 113 actual checks on both Laravel engines
(112 on Windows, excluding the Linux symbolic-link check). It uses actual legacy
helper bytes, scoped tickets, separate source and staging databases/media roots,
the actual importer/verifier, the original settings Blade image fragment and the
protected local HTTP gateway. This proves that a verified imported legacy image
renders without rewriting its database setting. The focused settings, native
reservation, media and source Node cohort passes 37 tests without skips.
