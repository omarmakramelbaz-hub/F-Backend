# Holographic opening

The approved opening replaces the solar scene with a cyan humanoid and a live
background. Section counts retain account authorization and branch scope. The
opening never blocks access to the regular dashboard. The sidebar uses the same
character at a smaller scale. The spoken English greeting belongs to sign-in,
not refresh, ordinary navigation, or an idle return to the opening.

## Counts and refresh

Counters cover stored records across operating days; the opening does not apply
the ordinary home page's day/date filter. Branch accounts remain restricted to
their authorized branch. Owners/admins aggregate only their authorized branches
inside the current installation's database; independent businesses are never
combined. Section links still require the existing account abilities.

- Application orders show active/non-ended orders and, for aggregate accounts,
  a separate ended total matching the existing order board's completed tab.
  That tab includes the cancellation/failure states already classified as ended;
  it is not a paid-sales total. Website checkouts use the same application-order
  source rules and are not counted again under branch phone delivery.
- Takeaway shows completed, payment-confirmed POS receipts; cancelled, failed,
  refunded and reversed receipt states are excluded. Delivery and dining show
  unpaid, non-cancelled service tickets.
- Expenses show approved records with a separate pending total. The dedicated
  pending-expenses card shows only records awaiting approval. Its destination
  retains the pending status and includes the older pending dates.
- Employees are counted within the permitted branch scope. Customers use the
  existing shared customer directory within this installation. Stock shows
  distinct goods plus distinct products/ingredients that have reached or fallen
  below a configured branch minimum. A healthy branch does not suppress another
  branch's alert for the same item. Shift closing shows the permitted branch
  count.

The visible opening requests the count-only `section=hologram` response every
15 seconds, without reloading the dashboard. Polling stops while the opening or
document is hidden; failures retain prior values with a freshness warning. A
missing optional data source appears as unavailable, not a fabricated zero.

The full-size and sidebar characters remain animated. A visible natural head
and shoulder turn is scheduled every 60 seconds, with continuously moving
background strands/particles. Hidden scenes pause, and reduced-motion users get
the static completed figure.

## Spoken greeting limitation

The greeting is English and uses the signed-in account name. A one-shot server
session marker is set after successful password login and consumed by the first
dashboard response. Refresh, section navigation and idle return do not replay
it. Playback also respects the browser's user-gesture/audio restrictions.

The present implementation uses native browser English speech synthesis as a
fallback, preferring an available male English voice with a lower pitch. It has
not been verified to reproduce the attached reference video's exact voice.
An approved recorded/generated narration or identified matching voice remains
necessary before claiming an identical reference voice. Native voices depend on
the browser/device and may require an installed English speech package.

## Bounded activation

`deployment/hologram/build_manifest.py` packages only the explicit source path
allowlist against revision `f50a603b95f94b4168eb40fe0ac7ff26528f1d1b` and pins the
manifest and installer hashes into `deployment/run_hologram_opening_root.sh`.
Build after the final reviewed source changes. Rebuild the manifest after any
payload/installer change. The final operator command must pin the launcher hash
and a complete immutable commit revision.

The installer accepts `--check` for a source-only preflight or `--apply` for the
approved activation, only as root on `srv1836524`. It targets
`/home/fasakha/public_html`. It does not merge, reset, switch branches, rebuild the
Windows desktop, run migrations, alter business records, or restart services.

Existing text files receive unique reviewed fragments. Their baseline and result
hashes are recorded; compatible live changes outside those fragments remain
intact. A changed/ambiguous fragment stops the entire preflight. New files must be
absent or byte-identical to the approved payload. Symbolic paths, duplicate files,
unexpected destinations, payload hash mismatches and concurrent source writes
are refused. Installation uses same-directory atomic renames, retains existing
file ownership/modes, records pre-install bytes in root-only backups and restores
the modified files if validation fails. A concurrent change during rollback is
left intact and reported as a rollback conflict.

PHP candidates are linted before source changes. After installation, the runtime
compiles and lints changed Blade templates, checks the home route and rechecks all
installed hashes. Route and view caches are cleared as `fasakha`.

## Independent owner installations

Owner installations share the reviewed application source and the entire
`public/dashboard` static asset directory. Each installation retains its own
environment, database, application keys, sessions, storage and compiled caches.
The installer discovers already-provisioned runtime files through their fixed
tenant directories. It bootstraps each context in a separate PHP process, checks
that the configured SQL database/user match that tenant's trusted runtime, and
clears only that context's routes/views. It never copies environment files,
prints credentials, queries business data, or runs tenant provisioning. A tenant
still awaiting its initial config cache compiles the new shared views during its
normal first bootstrap.

`GO_HOLOGRAM_OPENING_READY` confirms bounded source activation and runtime
validation. It does not claim that a Windows installer was built, nor substitute
for authenticated browser verification of each role and its live counts.

## Local deployment verification

Run `python3 deployment/hologram/test_deploy.py` for isolated tests of source
drift refusal, exact payload hashes, idempotence, preservation of unrelated live
edits, rollback, concurrent changes, CRLF source and symlink/path boundaries.
