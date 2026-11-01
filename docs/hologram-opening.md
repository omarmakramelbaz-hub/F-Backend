# Holographic opening — abstract energy robot

The opening replaces the solar scene with an abstract futuristic energy robot
and a live background. Review revision V4 uses a faceless cyan energy envelope,
a turbulent orange/yellow core and visibly moving internal blue/orange currents.
It retains V3's approved single-emitter construction, arrival, turn and scatter
timing, together with the accepted local MP3 recordings and greeting delay.
It does not introduce a realistic human face or a solid robot helmet.
Section counts retain account authorization and branch scope. The
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

The full-size and sidebar characters use the same native WebGL 1 engine. This
V4 art revision retains renderer API version 3. The main character has 33,684 mesh vertices, 14,000 surface
particles and one startup emitter. The compact sidebar uses a lower-density
mesh and 1,100 surface particles.
The engine builds head, torso and ear surfaces from procedural coordinates and
renders the moving wire contours and lighting directly. No character bitmap,
external model file, or framed image rectangle is drawn. The translucent main
canvas and its live background span the viewport.

After the 5.6-second construction completes, a separate pass adds 5,400 bounded
internal energy points in four short trails. Orange and blue currents remain
inside the envelope and move independently of the original construction stream.
The sidebar uses 900 internal current points. Procedural surface turbulence and
generated bloom give the core an energy appearance without a character texture.

Construction starts from one cyan emitter near the bottom centre. Only that
point is visible during approximately the first 0.48 seconds. Branching, hooked
streams deposit the left torso, neck, head edge, face and opposite shoulder in
sequence; the abstract energy form finishes assembling at about 5.6 seconds.
The startup emitter then fades between 5.6 and 7.2 seconds. Full-viewport ambient
scatter begins after construction rather than competing with the single source.
The compact sidebar and reduced-motion scene begin in their completed state.

Head/neck rotation can reach both 90-degree side profiles, with a visible turn
scheduled every 60 seconds. The lower-face energy surface deforms from the recording's
measured amplitude envelope rather than an unrelated repeating speech animation.
Background strands/particles remain live. Rendering targets 30 fps on the main
scene and 15 fps on the sidebar; hidden scenes pause and reduced-motion users get
the static completed geometry. A WebGL-unavailable browser retains section entry
controls rather than blocking the dashboard.

## Local spoken greeting

The greeting is English. Accounts whose name starts with `Omar` or `عمر` receive
the recording that addresses Omar; other accounts receive the generic recording.
The visible account label still uses the authenticated account's actual name.
A one-shot server session marker is set after successful password login and
consumed by the first dashboard response. Refresh, section navigation and idle
return do not replay it. Playback respects the browser's user-gesture/audio
restrictions, and the mute control stops it.

For the actual API-v3 main renderer with motion enabled, the approved recording waits
6.2 seconds so construction completes first. Early pointer or keyboard input
cannot bypass that gate. Leaving or hiding the opening cancels pending playback.
Reduced-motion and older fallback paths do not wait for the construction effect.
If browser autoplay is blocked, the opening offers a visible play-greeting control;
a successful retry stays audible, and the same control then becomes mute.

Both MP3 recordings were generated locally with speaker conditioning from the
user-supplied reference recording. This uses the reference voice as conditioning;
an identical timbre is not guaranteed and remains subject to user review. The
browser does not use native/system text-to-speech or make a remote voice request.
The accompanying JSON envelopes contain 50 amplitude samples per second and
synchronize mouth deformation with MP3 playback time. The generic recording is
about 7.60 seconds; the Omar recording is about 7.88 seconds.

Only four small MP3/envelope assets and the voice selector service ship with this
revision. The roughly 1.5 GB synthesis model weights, development dependencies
and intermediate reference clips stay in scratch. No Windows model installation,
runtime synthesis package, or desktop installer rebuild is included.

## Bounded activation

`deployment/hologram/build_manifest.py` packages only the explicit source path
allowlist against the confirmed V1 revision
`39ea43ff587d5e8f1285c811f7e11b02a137ecc2` and pins the
manifest and installer hashes into `deployment/run_hologram_opening_root.sh`.
Build after the final reviewed source changes. Rebuild the manifest after any
payload/installer change. The final operator command must pin the launcher hash
and a complete immutable commit revision.

This is an incremental 13-file V4 review package against the live V1 baseline;
neither unactivated V2 nor V3 is a deployment precondition. Review publication
does not activate this package. It does not republish the V1 counter
SQL or replace its metric service. Existing branch/count authorization remains
unchanged. The old V1 bitmap may remain on disk but the new main/sidebar views
and renderer do not reference or load it.

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
installed hashes. It also checks both local voice selections and their envelopes.
Route and view caches are cleared as `fasakha`.

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

Run `python3 deployment/hologram/verify_manifest.py` after building the manifest
to replay every packaged file against the immutable Git baseline in disposable
directories. It verifies actual installation, repeat/no-op behavior, rollback,
byte-exact binary assets and preservation of an unrelated live controller edit.
It also refuses stale manifests that no longer match the reviewed source.
