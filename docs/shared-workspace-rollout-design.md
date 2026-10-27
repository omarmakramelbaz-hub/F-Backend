# Protocol 2 rollout design and operator integration points

Status: concrete root loader, production source/migration/configuration actuators,
original native collector integration and disposable tests are implemented. Final
native, seed and web release pins remain unavailable, and both entrypoints refuse
execution before any effect. No production deployment, SQL, candidate allocation,
installer build, publication or activation was performed while implementing these files.
The shared policy identifier is fixed; final source and acceptance evidence must be
compiled only after CI. The feature worktree remains independent.

## Evidence and current checkpoint

The user's last confirmed publication is `0.4.4-preview.59`, canonical live-source
branch `codex/go-erp-live-source-20261021`, commit
`5becb9bab38a91998d1b1341670b2b398e4ba70f`. The prior performance-only `.60`
command was sent without a result. Its server counter, desired candidate, rollout
journal, installation, and artifact may already exist. Absence must not be inferred
from missing chat output. The next root operation must inspect and resume the same
checkpoint; it must not start a fresh reconstruction or overwrite a reserved artifact.

The performance-only operator package is
`8db5adb538834a2152f74f6e55336223a4c95908`; its known cohort is:

| Binding | Known performance-only pin |
| --- | --- |
| Native core | `9867dc29357e16290b3a1eeda996d916af701915` |
| Seed | `f3a8b4af4ec27042a93328c1fde200cfd8fe1da3` |
| Website source | `be797004831cbd8ccfb7c011aac855d6c2964829` |
| Policy | `historical-baseline-v2-native-close-reopen-program-contract-direct-login-accounts-v5` |

These are predecessor evidence, not protocol-2 release pins. The feature CI branch
is `codex/go-erp-shared-device-offline-20261027`. A branch head or foundations-only
green result is insufficient evidence of a finished native lifecycle or final release.

## Existing architecture to retain

`deployment/run_go_erp_owner_fast_open_root.sh` fetches exact immutable commits into
a private bare repository, checks the host/root identity, invokes a pinned upgrade
coordinator and filters output to bounded reason codes. Failure retains the package
for the same-command retry. Its output parser currently asserts `.60` and
`database_changed=false`; both assertions are specific to the old performance release.

`deployment/upgrade_go_erp_owner_fast_open_root.py` loads the frozen
`deployment/upgrade_go_erp_warm_start_root.py` from
`12d74b4f35737a2b1e271657b159137538c3edaf` by SHA-256. It binds exact old/new cohorts
while preserving the original install lock, manual worker lock, watcher lock,
state migration, source snapshot, remote lease, one-allocation budget, fresh candidate
CI verification, installer verification, program authorization, employee evidence,
publication and download archive. `Context.previous_proof()` verifies the original
`.59` completed rollout, its authorization receipt and exact installer. `complete()`
retains the candidate and pending acceptance; `review_existing_ci()` may review fresh
successful runs for that same `.60`, retaining failure evidence and history.

`deployment/go_erp_owner_fast_open_source_root.py` atomically replaces exactly seven
existing source files. It requires before/after hashes, root-only intent and backups,
strict parent/inode/owner/mode checks, held-lock verification, targeted Blade cache
invalidation, and verified PHP-FPM reload on apply or an owned rollback. It deliberately
does not support new files, new directories, migrations, environment edits or broad
cache cleanup. Do not merely enlarge its allowlist and pass missing files as empty bytes.

`deployment/go_erp_owner_fast_open_manual_root.py` accepts only a nonce/actor/time
request. `Broker.process()` stores `prepare_started` before invoking the collector,
latches candidate/inventory/version, archives the last publication before a source
scan and stops on acceptance review. The manual tick loads hash-pinned immutable
runtime files and binds configuration seed, unit hash, source inventory and policy;
its prepare guard permits one allocation only. Neither path enables the minute timer.

`deployment/go_erp_owner_fast_open_payloads.py` derives exact worker bytes, preserving
all original verification before adding an installed-ASAR startup receipt. That receipt
explicitly does not claim actual HTTPS publication or the full dashboard. Protocol 2
requires a separate, stronger receipt and cannot reinterpret the old proof flags.

## Read-only scaffolding supplied here

`deployment/go_erp_shared_workspace_plan.py` reads existing immutable Git objects,
never fetches and never executes release source. Its CLI generates a draft manifest
of production file additions/replacements, separates native/test/workflow material,
rejects deletes, unsafe modes, dependency changes and other database source changes,
and records both exact production migrations. Added files have a null before hash,
meaning proved absence, not an empty-file match. Every plan keeps activation,
allocation and publication false and enumerates pending final pins.

Example, after reviewed final commits exist locally:

```bash
python -I -B deployment/go_erp_shared_workspace_plan.py \
  --git-dir /absolute/path/to/repository.git \
  --baseline EXACT_OBSERVED_PREDECESSOR_COMMIT \
  --release EXACT_REVIEWED_PROTOCOL2_WEB_COMMIT
```

Arguments must be full existing commit SHAs. No output from this draft CLI is an
operator allowlist until independently reviewed and embedded in a pinned coordinator.
Migration source checks are a preliminary table-roster check; they do not prove PHP
semantics, absence of side effects or production database safety.

`checkpoint_advice()` consumes already-proved private JSON snapshots and returns
only advisory decisions. It cannot replace the root reader, locks, provenance or
acceptance proof. `workspace_env_bytes()` is a pure byte transformation of exactly
`DESKTOP_DASHBOARD_WORKSPACE_ENABLED`; it performs no I/O, preserves all other bytes
and rejects duplicates or dynamic/unrecognized values. Neither helper prints secrets.

## First root read and checkpoint branches

Before any write, prove `srv1836524`, UID/GID `fasakha:1001:1003`, root paths, real
regular files and original locks. Read bounded private records without emitting their
contents: current state, published feed, old/new rollout jobs, source transaction
intent/commit receipt, native migration audit, installation receipt and units,
program rollout hold/completed/pending authorization, retained installer and archive,
configuration cohort and canonical branch lease. Do not print config tokens, `.env`,
SQL credentials, members' password material, raw CI downloads or private evidence.

| Observed state | Required continuation |
| --- | --- |
| Exact published `.59`, old cohort, no performance job | Verify original `.59` acceptance; record a read-only snapshot and use it to select a new reviewed predecessor. This observation alone grants no allocation. |
| `.59` plus existing performance preparation/job or mixed native slots | Resume/recover that exact recorded performance checkpoint and verify its source transaction. Do not assume no allocation can have happened after the snapshot. |
| Counter `.60`, desired bound to performance job | Resume the same candidate, inventory, version, policy, CI and retained acceptance. Never replace desired, decrement counter, clear failure history, delete its artifact or allocate another `.60`. |
| Published `.60` | Verify original publication, installer, acceptance and archive before embedding `.60` as the new protocol-2 predecessor. Do not rewrite old `.59` acceptance to refer to it. |
| Missing journal, changed lease, unknown policy, higher counter, unbound artifact or partial source | Stop with a bounded review code and retain all evidence. Read-only triage must establish ownership and continuation first. |

A new protocol-2 job needs its own content-addressed source/migration/config plan and
its own core-bound path. Preserve the old job, source intent and counter. The selected
predecessor and next version must come from verified server state and immutable receipt
pins; this design intentionally does not assign `.60`, `.61` or another target.

## New source transaction and native source guard

Generate the complete exact source manifest from the selected accepted predecessor
to the final web release. Review all production paths, including existing financial
controllers changed only to enforce workspace actor/scope. Preserve server-only source
outside the manifest. Bind the source manifest hash, release commit, CI workflow bytes,
run ID/attempt and every required successful job into a private pre-write receipt.

Implement a new source helper by reusing the old transaction's strict reads, audit
writes, inode comparisons and owned rollback rules. Required additions:

1. Allow an exact reviewed roster of replacements and additions, with separate
   `before_sha256=null` absence evidence. Reject symlinks, hardlinks, unexpected existing
   files, deleted/renamed files and new source directories not in the recorded plan.
2. Record directory absence/ownership and every new-file inode before fsync. Create
   only reviewed missing source directories as application UID/GID `1001:1003`, mode
   `0755`; create regular source files mode `0644`. Preserve metadata of replacements.
3. On failure, restore only this invocation's replaced inodes and remove only its
   own added files/directories. Never remove a peer file or a nonempty directory.
   A crash-resume verifies before/after hashes plus the original intent instead of
   rewriting all files again. A committed receipt requires every final source hash.
4. Increase audit/manifest limits by a reviewed bound for the full source roster,
   retain content-addressed failure records and all raw backups privately. Invalidate
   only compiled Blade paths whose exact source path and Laravel marker are proved.
5. Keep source/audit directory validation strict. Permit `0775` only for the exact
   trusted application storage/cache chain with UID/GID `1001:1003`, as established
   by the prior repair. Do not relax source ancestry or root audit permissions.

The old coordinator's `checked_native_core()` rejects *all* backend differences
between old and new cores. Protocol 2 necessarily changes backend and native files.
Replace that condition with two exact comparisons: new native core's packaged backend
tree equals the reviewed web-source tree, and native-only changes equal the separately
reviewed native manifest. Keep dependencies and historical baseline/removal authority
unchanged unless individually reviewed. A broad disabled guard is unacceptable.

The captured live-source inventory must be taken after the reviewed source commit
and migration/config readiness proof, then retained through seed migration and
candidate creation. Preserve original removal sets, packaged sets, historical source,
published history, version/sequence floors and remote lease. A migration/config retry
must reuse those recorded inputs and cannot trigger another native prepare permit.

## Production database changes

The only new production migrations are the exact pinned files:

| Migration | New production tables |
| --- | --- |
| `2026_10_27_030000_create_desktop_dashboard_workspaces.php` | `desktop_dashboard_workspaces`, `desktop_dashboard_workspace_members`, `desktop_dashboard_workspace_manifests` |
| `2026_10_27_030010_create_workspace_command_authorities.php` | `desktop_dashboard_ws_command_auth` |

The second table is an immutable command-authority sidecar; its records must survive
membership/snapshot changes. The three `workspace_local_*` authority/history tables
come only from `DesktopDashboardWorkspaceLocalSchema::ensure()` on the native PC.
Never invoke that class, workspace import, private staging schema or seed SQL on production.

Before DDL, prove the actual production DB identity from private application config,
all four table absences or exact prior owned migration receipts, migration-file hashes,
and the migration ledger. Retain a private schema-only preflight and approved recovery
checkpoint; never log credentials or export business rows into a public artifact.
Keep the feature flag false. Execute only the two exact pinned migration paths using
`/opt/cpanel/ea-php82/root/usr/bin/php` as the application account in the verified root,
ordered `030000` then `030010`; never run unrestricted pending migrations or migrate:fresh.

MySQL DDL is not one atomic transaction. The first migration creates three tables
and Laravel records its ledger entry only after `up()` completes. A crash can leave
some tables without a migration row. Blind rerun, automatic rollback or drop/recreate
would destroy the checkpoint. The new migration helper must journal each DDL boundary
and exact resulting schema. If partial tables are attributable to this immutable
intent and their full normalized schema matches, an explicitly reviewed recovery path
can continue only the remaining DDL and record completion once all shapes are proved.
Otherwise hold for review. Do not mark a migration complete from table names alone.

Verify columns, types, nullability, defaults, primary/unique/index names and InnoDB
engine; verify both ledger records and absence of the three local-only tables. No
update/delete/import/backfill of existing accounts, sessions, journals, financial
tables, balances, wallet data or business records is part of this deployment.
Never invoke migration `down()` as automatic recovery after live activation. A rollback
disables the feature and restores owned source/config, retaining additive schema and
immutable sidecar evidence for a separately reviewed recovery.

The final success record must say schema was changed and financial data was not.
It must not inherit the old wrapper's `database_changed=false` claim.

## Exact feature activation and PHP serving cache

Keep `config/desktop_dashboard.php` default false. `DESKTOP_DASHBOARD_WORKSPACE_ENABLED`
is an application `.env` flag; it does not belong in `/etc/go-erp/live-updates.json`
and must not replace that root-only updater config or its token.

The new config helper needs its own held-lock transaction and private before/after
hash receipt. Prove `.env` ownership/mode/regular-file/link count and inode, retain
original bytes privately, transform only the single flag, preserve every other byte
and metadata, atomically rename with parent fsync and verify the result. Reject
duplicate/unrecognized assignments instead of resolving them silently.

After source and schema readiness, run targeted Laravel `config:cache` as the
application user with the exact PHP binary. A route cache, if present, also needs an
explicit inspected, pinned rebuild policy for the new workspace routes. Avoid broad
`optimize:clear`, application cache flushes or session removal. Private config cache
can contain secrets; keep it under the existing trusted owner/mode policy and never
emit it. Prove effective `desktop_dashboard.workspace_enabled` and `local=false`
through a bounded probe that prints only expected booleans/config digest.

Reuse the pinned cPanel PHP-handler attestor, including its reviewed ProxyPass rules,
before and after `systemctl reload ea-php82-php-fpm.service`. Revalidate handler
configuration identity and production HTTPS origin `https://fasakhaninja.com`.
CLI `opcache_reset()` is not evidence that FPM serves the new source or config.
Record each config/cache/FPM phase durably. On owned activation failure restore the
flag/config state, rebuild the matching config cache and reload the proved FPM unit;
retain schema, artifacts and receipts. Unknown source/config bytes require review.

Enable the feature only after complete final-source CI and the native lifecycle
acceptance below are satisfied, with source, schema and serving-process proofs bound
to the same final release. Prepared source or foundations CI alone must leave it off.

## Final release and native acceptance blockers

The final coordinator cannot be published until the release pins enumerated by
`REQUIRED_RELEASE_PINS` are independently supplied. Also pin each exact required
workflow path/hash, repository ID, branch, event, head SHA, run ID/attempt, job names,
job conclusions, proof artifacts and their hashes. Latest relevant run/attempt must
be successful; cancelled, skipped, stale, manually substituted or foundations-only
jobs do not satisfy the contract. The actual allocated candidate still requires
the original desktop/update/WhatsApp candidate CI and original program gate.

Retain all previous installed-ASAR, original financial/print, shutdown/reopen,
historical source, exact installer and employee acceptance gates. Add explicit
protocol-2 installed-code evidence for the complete lifecycle:

1. Prepare from each eligible admin/vendor/`resturant_owner` custodian on fresh devices;
   one physical DB and one deviceRoot per device, union membership of all active
   eligible accounts, passwords and own permissions enforced for each member.
2. Offline login without prior per-account online preparation; role-specific local
   view displayed before any synchronization starts; offline path makes no HTTP.
3. Actual online workspace enrollment/manifest, download/archive verification,
   staging/import/activation and failure/resume with no destructive snapshot fallback.
4. Cross-member A/B commands and reads, outbox retries/remote attempts, deltas,
   immutable acceptance provenance, created scope, media, financial and print authority;
   wrong password, disabled/member permission changes and cross-actor data denied.
5. Native close/reopen/restart, migration of existing installed accounts, same-source
   fast reopen, preserved queued work and original records; no second physical DB or
   per-user deviceRoot quietly introduced.
6. Full dashboard/user lifecycle and actual server/HTTPS behavior separately proved;
   synthetic fixtures may not set these flags true. Installer must contain the exact
   accepted protocol-2 ASAR/runtime/source fingerprints.

Extend the collector only by a reviewed byte-exact derivation from the trusted worker.
Add the new proof to the artifact roster and validation after all original checks;
pin proof capability/source hashes and bind desired candidate, version, inventory,
protocol and installed-ASAR identity. Do not edit CI failure counters, retry budgets,
artifact contents or authorization receipt to force acceptance. Manual owner requests
must keep the same durable nonce/candidate allocation guards and successful installer
archive. The automatic minute timer stays disabled in every outcome.

## Verification completed for this scaffolding

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest \
  tests/test_go_erp_shared_workspace_plan.py \
  tests/test_go_erp_shared_workspace_source_root.py \
  tests/test_go_erp_shared_workspace_states.py \
  tests/test_go_erp_shared_workspace_coordinator.py \
  tests/test_go_erp_shared_workspace_journal.py -v
```

The disposable tests cover Git commit-only reads, full 110-plus-path manifests and before-file
absence, both new migrations, unsafe/unknown paths and modes, dependency/deletion
rejection, native private-table separation, duplicate/nonfinite JSON, observed `.59`,
prepared native migration, consumed `.60` before parent latching, blocked/published
`.60`, source/feed drift, zero allocation permission, exact environment byte preservation,
CRLF, comments, export syntax, duplicates and dynamic-value rejection. They additionally
exercise real filesystem mutations at each per-operation write-ahead/publish boundary,
crash resume, owned rollback and rollback interruption, same-byte peer replacement,
atomic no-clobber additions, unrelated files within owned directories, strict cache
permissions/compiler markers and exact migration/activation proof transitions. They do not
claim production deployment, SQL migration, full lifecycle or native acceptance.

## Implemented source API and recovery boundaries

`deployment/go_erp_shared_workspace_source_root.py` is the new standalone importable
engine. It does not import or alter the old seven-path operator. `FINAL_RELEASE_PINS`
is explicitly `None`. Its `apply()`/`rollback()` functions require a trusted coordinator's
full commit pin, source payload bytes, exact `{path: {before: sha-or-null, after: sha}}`
manifest, exact absent-before `new_directories` roster, trusted UID/GID and a callable
verifier for the held original locks. The coordinator must convert reviewed draft
manifest fields explicitly; request input must never supply the applied manifest.

Every transaction has a content-addressed root-only audit with immutable intent and
before-byte backups. Source attempts have their own immutable audit directory, operation
write-ahead records and terminal receipts. A staged file/directory is fsynced, its inode
is recorded privately and fsynced, then it is published. Replacements use atomic rename
with an exact original inode check; additions use Linux `renameat2(RENAME_NOREPLACE)`
and fail closed if unavailable. Final targets must retain the recorded inode, bytes,
owner/group and mode. Only the exact same-GID `storage/framework/views` cache chain
may use `0775`; targeted compiled Blade files require their exact compiler path marker.

Resume after a recorded write-ahead/publish boundary reuses the same owned inode and
source attempt. Explicit rollback restores replacements from the private backups and
removes only owned added files and empty owned directories. A peer replacement with
identical bytes is still foreign because its inode differs. Unrelated content in a
new directory prevents commit/removal and survives. Interrupted rollback must finish
before apply can continue. A completed rollback permits a new **source attempt** under
the same plan without allocating a native version or rewriting earlier evidence.

A process can die after creating a temporary staged inode but before its ownership
record is durable. That unpublished stage is conservatively retained for review;
its filename/hash alone is insufficient ownership proof. The source target has not
changed at that boundary. Committed-source rollback also requires separate coordinator
review and is rejected by this default API. These cases must not be bypassed through
recursive deletion, inode adoption, new candidate allocation or financial rollback.

## Implemented pure migration and activation APIs

`deployment/go_erp_shared_workspace_states.py` performs no I/O or SQL. Its
`migration_intent()` binds the final release, source manifest, private database identity,
two migration hashes and full normalized schemas for the four allowed tables.
`table_intent()`/`table_receipt()` and `ledger_receipt()` describe the durable records
that a future trusted SQL executor must write and verify. `migration_checkpoint()`
returns `absent`, `owned-partial`, `owned-complete` or `foreign-conflict`. It never
permits DROP or financial-row updates. Matching tables without owned completion
receipts remain conflicts; native private authority tables in production also conflict.

`activation_intent()` and `advance_activation()` implement durable pending/completed
phases for the exact flag, config cache, optional route cache, attested FPM reload,
HTTPS verification and owned disable/cache/FPM rollback. Action proof hashes bind the
exact prior checkpoint and private effect receipt. Unrelated environment bytes and
source/config identities must match. Unknown ownership enters `review-required`.
The compiled activation gate remains `None`, so activation cannot progress by default.
The final coordinator may inject only a reviewed code-pinned gate; request values or
foundations CI cannot fill that role. Original program/employee gates, full native
lifecycle and installed-ASAR acceptance all remain mandatory.

The agreed policy is
`historical-baseline-v2-native-close-reopen-program-contract-shared-workspace-v1`.
The final runtime's tiny `desktop/workspace-contract.json` must bind the agreed five
program hashes; its final contract/candidate fingerprints are still unavailable here.
No feature worktree or contract bytes were modified by these implementation modules.

## Dependency ordering and serving-process rollback boundary

The source plan now records an `addition_order` and `replacement_order`. Every new
class/program/route file is published before the first replacement, including Kernel,
IsAdmin, controllers and desktop control entrypoints. Default replacement ordering
places services before controllers and controllers before views. The final coordinator
may pin a finer complete replacement order, but cannot put views before controllers.
The order is included in the source plan digest and coordinator receipt binding.

Rollback restores replacements in reverse dependency order while every added class
remains present. It invalidates restored Blade referrers before the serving boundary.
Deleting a visible added file requires a root-private write-ahead intent plus an approved
`addition_removal_barrier` callback and expected handler configuration fingerprint.
Its receipt must bind the exact restored-referrer inode snapshot and source attempt,
prove the feature is false, restored compiled/route-cache referrers, attested FPM
capture of the old FPM generation before reload and complete drainage before addition
removal. A simple successful
reload is insufficient. Each addition deletion rechecks the restored source snapshot.
Missing ownership, cache or drain evidence leaves additions available for in-flight
autoload. A crash after the durable approved boundary resumes it without a new reload.

## Concrete coordinator and private journal

`deployment/go_erp_shared_workspace_coordinator.py` implements one effect per durable
step through an exact `ApprovedActuators` roster. Each actuator has independently
verified code bytes, an `invoke` function and a read-only `recover` function. Root loader
code supplies these callables; neither requests nor artifact JSON may supply them.
Every effect gets a persisted pending action and digest before invocation. A restart
must recover the same owned action receipt or stop for review; it never blindly repeats
source installation, migration DDL or a flag write. The journal history binds the full
checkpoint body, source plan, compiled pins and prior history record.

The implemented sequence is verified predecessor observation, additions-first source,
observed/owned migrations, exact full candidate CI, healthy HTTPS with the flag still
false, then the existing exact flag/config-cache/route-cache/FPM/HTTPS activation
states. The required original candidate workflows remain mandatory:
`desktop-dashboard-tests.yml`, `go-erp-update-tests.yml` and
`whatsapp-integrated-regressions.yml`. Full lifecycle, installed-ASAR, original program
and employee acceptance are separately required. Workflow pins bind repository, push
branch/path/head, workflow bytes, latest run/attempt and successful required jobs.

A reserved performance `.60` enters `predecessor-resume-required` and performs no
new workspace source or migration action. The concrete root loader resumes the pinned
original operator at its retained checkpoint, then proves and captures its exact
accepted predecessor. The pure coordinator exposes
no allocation, counter reset, artifact replacement, installer publication or automatic
timer action. `COMPILED_PINS=None` rejects `step()` before journal/actuator writes;
`inspect_predecessor()` is available independently as a pure read-only branch model.

Migration actions contain one of the two reviewed paths and its exact source hash,
private intent and checkpoint digests, only its remaining/owned tables, per-table
write-ahead intents, exact PHP/root and UID/GID `1001:1003`, bounded private output,
and explicit prohibitions on unrestricted migrate, down, DROP and financial-row edits.
Owned partial continuation additionally binds the approved resume-helper code hash.
A recovered effect must demonstrate owned schema/ledger progress; a no-op or unknown
post-DDL state cannot clear pending and trigger another migration attempt. The pure
coordinator executes no SQL; the concrete migration actuator below supplies actual
restricted PHP effects and never equates caller-supplied assertions with ownership.

`deployment/go_erp_shared_workspace_journal.py` supplies the real filesystem CAS
adapter for that interface. It uses the pinned source engine's strict root-private
reads, writes an immutable content-addressed checkpoint before atomically updating
the current pointer, fsyncs file/parent, verifies readback and rejects stale pointers.
The current checkpoint must match its immutable record. Its paths are separate from
native update state/counters/artifacts. Tempdir tests cover crashes before/after pointer
rename and integrate the real private journal with the coordinator's fake approved
effects. No server, SQL or service was used by those tests.

## Concrete production root loader and release completion

`go_erp_shared_workspace_root.py` and `run_go_erp_shared_workspace_root.sh` are the
actual unified command implementation. Both keep final pins unavailable. The loader
checks public source/contract/workflow/dependency pins before any production mutation,
then uses the original install/manual-worker/watcher lock order. Its independent
root-private immutable checkpoints do not edit the native counter or published artifact.

Read-only bootstrap proves published `.59` or known performance `.60` with the original
byte-verifying worker, program/employee receipts, publication/history/floors, fresh
original CI and canonical remote lease. Runtime-only private hashes are captured to
immutable WAL and revalidated; they are not missing human inputs. A pending old `.60`,
an old job still using the `.59` seed, or the exact seven-file performance source audit
routes through the original `8db5adb` resumable operator. Foreign state stops with a
bounded diagnostic. An owned workspace job resumes from its captured predecessor
without attempting to rediscover `.59` after its own candidate has been allocated.

Preparation first owns the false `.env` inode and original secret bytes in private WAL,
then publishes all additions before replacements. `prepare_disabled()` targets config
cache, an existing route cache, and the attested FPM generation only. The loader checks
that receipt again even if a committed source transaction skipped its callback on retry.
Environment staging resumes from its exact durable inode rather than recreating or
adopting a peer file. Owned rollback restores referrers before the cache/FPM drain
boundary and only then removes owned additions; the four production tables are never
dropped during source rollback.

`go_erp_shared_workspace_migration.php` executes only the two exact reviewed migration
classes. A restricted Schema facade permits one selected CREATE from their reviewed
ordered roster; no arbitrary Schema call, down, DROP, backfill or business-row write is
available. The PHP process runs as `1001:1003` with bounded output retained privately.
Root WAL owns each completed table and migration ledger row. A CREATE whose completion
receipt was not persisted remains review-required. The result reports
`database_changed=true` and four new tables, with financial/user rows unchanged.
The private CLI sets and verifies only its SESSION timestamp profile; no global setting
changes. Production charset/collation must match the reviewed utf8mb4/unicode profile.

The normalizer removes only unzerofilled integer display widths, distinguishes
MariaDB SQL NULL from quoted literals, and normalizes only unquoted temporal
CURRENT_TIMESTAMP expression spelling and its MySQL DEFAULT_GENERATED marker.
ON UPDATE, fractional precision, generated expressions, indexes, constraints,
literal defaults and signedness remain exact; enum/set and unreviewed escaped defaults
are refused. Expected schemas are exported by a separate CI workflow from exact source
`73bf0c716566f59f4a10e83b1732000a2366aa18` under real MySQL 8 and MariaDB 11.4,
with timestamp defaults OFF and ON. Its comparison job publishes
`shared-workspace-schemas-accepted` only when all four profiles agree. This JSON,
helper/source hashes and successful run/jobs must be reviewed and compiled into final
pins before any persistent production CREATE.

`go_erp_shared_workspace_native_root.py` constructs the actual frozen original Context.
Native payload and manual helper derivation preserves original functions and validates
every final blob/size/SHA triple. The original installer, program/employee approval,
same-candidate CI retry, archive and publisher remain mandatory. Only the new shared
policy adds the exact every-file `packagedNativeHashes` installed-ASAR proof. An already
ready candidate skips retired-hold reauthorization and is independently revalidated.

`go_erp_shared_workspace_acceptance_root.py` requires fresh original three workflows,
foundation and actual shared-workspace native workflow on the captured candidate,
with exact source/workflow/verifier hashes and successful latest run/attempt/jobs.
An in-progress run waits up to two hours on the same candidate; a failed latest run
cannot reuse an older success. The bounded native artifact must prove real PHP/SQL,
one physical database, first-ever offline B/owner/admin, actor-preserving A/A/B retry,
close/reopen/logout/shutdown, and the three actual CLI/web opcode facts. Its honest
productionMain/productionProfile false values do not replace original installed-native
and Main shutdown acceptance. Full original acceptance is required before the flag.

Only after all five workflows, native/ASAR/program/employee gates, migration ownership
and false-flag HTTPS readiness does the loader activate the exact flag, config cache,
conditional route cache and attested FPM reload/drain. Every successful invocation,
including an already-active resume, obtains a fresh HTTPS witness. The final public
marker appears only after those checks, reports the four additive tables, and confirms
automatic updates remain disabled. No final user command is supplied while pins are null.

The combined 207 tests include actual temporary source/environment/checkpoint inodes,
mutation failures and resume, real original-template/native/manual byte derivation for
both predecessors, pure PHP metadata fixtures, and the root loader driving actual
activation/WAL code with injected fake PHP/FPM/HTTPS services. They execute no server,
installer build or production SQL. The separate schema-export workflow is CI-only.

The successful schema export is retained in `deployment/pins/` with its exact public
run/job/artifact provenance, member/ZIP digests, migration/helper bytes and four-profile
semantic schema digest. `shared_workspace_prerequisites_r13.json` materializes those
accepted schemas and the exact five-program contract; final core/seed/web/native,
source plans, routes and candidate acceptance pins remain explicitly null. Those
prerequisites cannot enable either production entrypoint.

The offline final binding compiler and its exact stages are documented separately in
`shared-workspace-final-bindings.md`. Its seed transform preserves the final core's
current three policy files, verifies the actual returned seed lineage, derives complete
ASAR/native source maps, and materializes root/wrapper review files only after the
parent's actual final-core acceptance record is available. No final core, seed or
production command has been generated while those acceptance gates remain pending.
