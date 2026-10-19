# Original nonsecret settings forms

The bounded desktop adapter executes the original `SettingsController::update` and
`update_advertising` routes. It accepts ordinary text settings and advertising
restaurant selection, using each original PUT form's retained operation UUID.
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
(`updatePaymentActivation`) and settings image upload remain connected-only.
The local original views hide these controls with explicit guidance. An upload
sent directly is rejected before original work. This is a text-only increment,
not full-dashboard or promotion readiness.

Validation uses the actual original controller and generated application on
Laravel 8 and isolated Laravel 12, enabled shared settings cache and disposable
MariaDB/InnoDB. The fixture covers protected different central balances,
complete before/after facts, typed identity, full rollback on a real journal
INSERT failure, local exact retries, central replay/conflicts, current RR grant
and cohort changes, native reservation aliases/lost-response recovery and delayed
cancellation. Node tests execute the actual original-form script and native
reservation module. The Windows workflow runs the same PHP fixture; actual
combined Windows installation/browser evidence remains a release gate.
