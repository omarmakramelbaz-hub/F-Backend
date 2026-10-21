# GO ERP live source updates

The server watcher checks the live dashboard every 60 seconds. It creates a new
desktop version when application source changes, waits for fresh Windows and
messaging acceptance, and publishes the matching installer on the enrolled
server. GO ERP then downloads the accepted release on its next minute check and
installs when its existing idle and journal guards permit. Building, downloading
and installing can take longer than the check interval.

## One-time setup

First complete the pinned dashboard server activation. The permission repair
entry `deployment/diagnose_go_erp_activation_permissions_root.sh --repair`
removes only group/other write bits from exact approved publication files. Its
default invocation only inspects metadata. A failed or changed cohort must be
resolved before activation; do not recursively chmod or chown the project.

Install an accepted updater-enabled Windows version once under the existing
Windows account and application directory. Versions before
`0.4.4-preview.6` do not contain the updater.

From the authorized root console on `srv1836524`, export and hash-check
`deployment/activate_go_erp_complete_root.sh` from the reviewed immutable
`codex/go-erp-activation-view-fix-20261021-r3` release. Run it with `--release` and that full
commit SHA. It classifies the exact approved before/after source state, repairs
only eligible permission bits and activates the dashboard when needed, then
performs fresh application/authentication checks before installing the timer.
Unknown source states stop without forcing a baseline.

The lower-level `deployment/install_go_erp_live_updates_root.sh` entry only
installs the timer. It requires the three reviewed update API source files
already installed in `/home/fasakha/public_html`.

Give the installer a fine-grained GitHub token restricted to
`omarmakramelbaz-hub/F-Backend`: repository contents read/write for the dedicated
branch and Actions read for acceptance artifacts. Workflow files remain fixed
at the reviewed base. Configure the token privately through the hidden server
console prompt, or supply `--github-token-file` with a root-owned regular
single-link mode `0600` file beneath safe root-owned directories. Never paste
the token into chat, a command argument, source, or a workflow log. The existing
deployment-only VPS SSH key is not used.

GitHub permission references:
[token management](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens),
[artifact access](https://docs.github.com/en/rest/actions/artifacts).

The installer validates repository identity and Actions access, stores private
configuration at `/etc/go-erp/live-updates.json`, and installs immutable reviewed
helpers under `/opt/go-erp-live-updates/releases/<commit>`. It enables
`go-erp-live-updates.timer`. It does not report an accepted release merely
because the timer started. The first release remains pending until its fresh
CI run passes and the actual publisher succeeds.

## Source and acceptance boundaries

The watcher reads application roots `app`, `bootstrap`, `config`, `database`,
`desktop`, `public`, `resources`, `routes`, plus `artisan`. It uses the approved
Git tree as its initial roster, independent of the stale production Git HEAD.
Existing approved static assets and new classified source files are included.
An approved asset initially absent from the server can remain as a trusted
runtime resource; once observed live, a later disappearance is recorded as a
removal. Missing critical application files stop the candidate.

Production environment files, credentials, storage, uploads and linked paths
are excluded. Newly uploaded public binary media follows the existing data/media
sync path rather than becoming a source commit. Composer/vendor/native runtime
dependencies remain pinned to the reviewed desktop base. Four dependency
manifest hashes are kept privately; a dependency change invalidates the feed
and requires a reviewed seed upgrade instead of silently mixing runtimes.

Two descriptor-based source scans must agree. Candidate Git operations run in a
root-owned isolated checkout with controlled configuration and hooks disabled.
The watcher changes neither production application files nor their Git HEAD.
It commits a strictly higher preview version in both desktop package manifests
and writes the source authority inventory before pushing only
`codex/go-erp-live-source-20261021` with a checked branch lease.

Publication requires successful fresh jobs from the full Windows workflow,
minute update contracts, and integrated messaging regressions for the exact
candidate. The accepted artifact binds the repository, branch, revision, run,
attempt, captured inventory, all 2,000+ packaged source bytes, native receipts,
update receipt and installer size/hash. Download redirects lose credentials
outside the fixed GitHub API origin; archive bytes, members and expanded sizes
are bounded and verified before extraction.

The actual live inventory is checked during long operations and around the
existing root publisher's source checks. A newer source supersedes the build.
The feed is invalidated while the matching release is unavailable; an obsolete
installer cannot become the current release. Local commands and database
generations retain their existing client fences and recovery behavior.

## Operation

The oneshot timer does not overlap its own service. Long operations recheck live
identity through heartbeats; a separate lock also protects manual worker runs.
State and candidate files are private under `/var/lib/go-erp-live-updates`.
The service has read-only application access except for its root-owned update
publication directory. It never migrates, resets or executes the live PHP app.

Check the timer and redacted worker status from the server console:

```bash
systemctl is-active go-erp-live-updates.timer
systemctl list-timers go-erp-live-updates.timer --no-pager
journalctl -u go-erp-live-updates.service -n 30 --no-pager
```

Disk space is checked before large work. Private downloads and extraction are
removed after success, failure or supersession. Publication retention removes
only old installer hashes recorded by this worker, keeping the current release
and two predecessors; unrelated operator files are retained.

Repeated network or CI failures back off and remain visible in the journal.
Private version state preserves the complete validated release version before
feed invalidation, including manually published higher versions. An incompatible
stable or numeric version prefix requires a reviewed seed upgrade. The installer
also refuses to overwrite a different existing service configuration or base.

## Current evidence

The permission repair and root service have descriptor/race/privacy tests. The
source and worker suites exercise real isolated Git candidates and the actual
publisher. A full `0dc8f94e015af515980924004d6bb0dfcb253936` source fixture
verified 2,532 packaged files and 78,067,470 bytes, including existing Unicode
and space-containing image names. Python and JavaScript inventory hashes agree.
This is source/integration evidence; Windows execution is certified only by the
separate successful native Windows workflow for each released candidate.

## Refreshed activation after live edits

The refreshed operator entry and setup use the fixed branch
`codex/go-erp-activation-view-fix-20261021-r3`. Their payload checks still require the
exact requested revision and exact helper bytes. The unattended candidate
branch remains `codex/go-erp-live-source-20261021`, preserving its existing
source inventory, workflow and acceptance boundaries.

The 17:31 UTC root console diagnosis measured the current source fingerprint
`a6adf27f343f61a5264fd6b65c0aa8978f2825a0ed3e3379d522c89aac4ddfa6` across
1164 files. It reported nine source differences from the preceding activation
baseline, covering the menu/shift update and POS silent-quote behavior. The
revised cohort is grounded in that fresh full inventory and matched raw hashes.

The earlier 16:01 inventory predated the approved POS updates. After the 16:15
`POS_SILENT_QUOTE_INSTALLED files=1` receipt, the prior activation entry correctly
stopped with `GO_ERP_COMPLETE_UNREVIEWED_SERVER_STATE_NO_APPLICATION_CHANGES`.
The fresh diagnosis performed no deployment, metadata change, PHP bootstrap or
database read. The complete classifier still requires the exact full source
inventory and every protected GUI blob to match before permission repair or
activation. Any additional live edit causes another safe stop. The diagnosis
and installation receipts do not prove that activation or the update timer ran.

The subsequent failed-activation inspection confirmed the exact BEFORE source,
zero pending ownership repairs, and restoration of the environment, config and
routes files. Activation stopped when the deployment helper rejected the first
Blade view because its PHP path regular expression lacked a closing delimiter.
The same defect also blocked the read-only runtime probe at its MANIFEST phase.

The r3 payload corrects those deployment-only guards and tests the actual PHP
regular expressions against every manifest view. The application manifest stays
`da47b5e5dc50676d6046a6511b3905a0cd053ed1`; the target remains
`ef2f7d28823d3c447b71e052df49e9eba1b4a0f3edb7ab44e9c4d42417e150b3`.
Future activation failures retain bounded private stage metadata and forward
only reviewed fixed failure receipts to the console. A successful r3 activation
and timer receipt is still required before claiming the service is active.

The existing accepted `0.4.4-preview.6` Windows installer can supply the first
updater. It cannot stand in for acceptance of a new source revision. A new
watcher candidate needs a higher version and all fresh Windows, contract,
messaging and source proofs before publication. The worker and publisher do
not reuse the old installer's source receipt.
