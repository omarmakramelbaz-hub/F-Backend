# GO ERP automatic release checks

GO ERP checks the enrolled HTTPS server for an accepted desktop release every
60 seconds. Data reconciliation keeps its existing separate schedule. A code
update is a complete installer, including its matching offline runtime; the
client does not copy live PHP files into a running database generation.

The first installation containing this feature is `0.4.4-preview.6`. The existing
`0.4.4-preview.5` installer does not gain an updater merely because the server
changes. Install the new accepted installer once, under the existing Windows
account and application directory. Subsequent accepted releases are downloaded
and installed automatically when the dashboard is idle on its home page.

The client downloads only from its enrolled server, authenticates with its
native device token, refuses redirects, checks exact size and SHA-256, and
revalidates the release before applying. Release sequences and versions cannot
go backwards. No update token, executable path or installer command is exposed
to a page. The **حالة تحديث البرنامج** native menu reports progress.

Installation waits for editing, child windows, printing, pending local commands,
conflicts, uncertain connected requests and old open orders to finish. The view
is held against new input and writes, and the local database takes its existing
durable generation fence before setup starts. Setup waits for the old process
to exit; shutdown drains accepted work and stops MariaDB first. Previous runtimes
and journals remain available for recovery. A source or database incompatibility
continues to refuse activation rather than reinterpret an old journal.

When an installed release changes only external GUI files, its legacy snapshot
fingerprint can equal the retained runtime's fingerprint. Promoting that bundle
requires a fresh authenticated release check for the exact installed revision,
version and source. An unapproved manually installed bundle cannot replace the
active same-source runtime. Normal snapshot fencing and database compatibility
checks still apply to the promotion.

## Build and publish one source

The full Windows workflow builds and tests a fresh runtime for the current Git
revision. Its final installer job creates `go-erp-update-release.json` only after
the original runtime, connected Electron and installed Windows checks pass.
The receipt binds the source fingerprint, the complete immediate
`public/js/dashboard-*.js` and `public/css/dashboard-*.css` roster, revision,
version, monotonically increasing run ID and exact installer bytes. GUI-only
changes invalidate the published release even when the legacy snapshot source
fingerprint is unchanged. It is a reviewed CI receipt, not a
digital signature or a substitute for inspecting the associated successful run.
The old workflow that reuses the immutable run189 runtime is intentionally not
an update-release publisher.

Publish a release only after that same source is installed successfully in
`/home/fasakha/public_html` on `srv1836524`. From root, using the reviewed receipt
and installer downloaded from the same successful full Windows workflow:

```bash
python3 -I -B /path/to/reviewed/deployment/publish_go_erp_update.py \
  --installer /private/release/GO-ERP-Preview-Setup.exe \
  --receipt /private/release/go-erp-update-release.json
```

Keep the two pinned read-only source helpers beside the publisher. The command
checks actual live source twice, including raw source identity stability, and
refuses a receipt for a different source. It writes only root-owned release
files under `storage/app/desktop-updates`, then atomically publishes `current.json`.
It does not migrate, reset, check out or replace application files.

The earlier one-off `deployment/go_erp_ci_publish_root.sh` entry combines pinned
activation/postflight and publication from the authorized root console, and
remains tied to the frozen `codex/go-erp-minute-updates-20261020` branch. It is
not the current activation entry or the live watcher's publication path.
For that one-off path, download the installer and receipt from the same independently checked
successful full Windows run into a root-owned directory with mode `0700` named
`/var/tmp/go-erp-ci-<8-to-32-alphanumeric-characters>`, using the fixed filenames
`GO-ERP-Preview-Setup.exe` and `go-erp-update-release.json`. Invoke the verified
entry with `--release <full-commit-sha> --run-id <successful-run-id>
--artifact-directory <private-directory>`. It rejects superseded branch heads,
different run/source/version/installer bytes, and its own unpinned contents.
Its receipt is still operator-reviewed evidence, not proof that GitHub passed.
No unattended SSH job is enabled by adding this console entry.

The authenticated feed advertises a release only while its fingerprint matches
the actual server. An unbuilt server edit therefore reports “unavailable” until
its matching desktop release has passed and been published. A minute is the
check interval, not a promise that a build, a large download, or installation
finishes in one minute. Every released code change must have a new desktop
version; unrelated server maintenance does not require a new application.

The current legacy deployment workflow targets
`/home/fasakhaninja/public_html`. It is not a valid publication trigger for the
above production root. A production deployment integration must call the
publisher after its actual GO ERP server-ready receipt and the matching Windows
acceptance; it must not announce success just because a GitHub push succeeded.
The existing deployment-only SSH identity cannot provide the operator-console
capability required here. Do not reuse it to attempt activation or publication.
The server watcher and its one-time root setup are described in
[go-erp-live-source-watcher.md](go-erp-live-source-watcher.md). Once configured,
it captures live edits, commits a higher preview version, waits for fresh
acceptance and invokes the source-guarded publisher. Adding its code does not
enable the production timer or configure GitHub authorization.

## Activation status

The server console confirmed recovery of 305 Git metadata ownership entries.
That repair preserved application contents. A 16:01 UTC source-only diagnosis
was followed by approved menu/shift and POS silent-quote updates. The 16:15
`POS_SILENT_QUOTE_INSTALLED files=1` receipt did not make the old source cohort
current: the prior activation entry stopped with
`GO_ERP_COMPLETE_UNREVIEWED_SERVER_STATE_NO_APPLICATION_CHANGES`.

A fresh 17:31 UTC read-only diagnosis measured 1164 files at fingerprint
`a6adf27f343f61a5264fd6b65c0aa8978f2825a0ed3e3379d522c89aac4ddfa6`,
with unchanged production HEAD
`ee52f1798a542d2c5625c863112315a8aaba02cf`. Its nine source differences from the
preceding activation baseline include the approved menu/shift changes and
silent-quote behavior. The revised cohort uses this actual full inventory and
matched raw hashes. The report confirms no deployment, metadata change, PHP
bootstrap or database read. Full source and protected GUI classification still
has to match exactly on the server.

The refreshed activation entry uses
`codex/go-erp-current-activation-20261021-r2` and exact release/helper pins. It
classifies the full measured source and protected GUI bytes before any repair
or publication. A newer server edit causes a safe stop and requires a new
reviewed baseline. The previously accepted release branches remain unchanged.
Preparing a refreshed release does not prove successful production activation;
that requires the root console's final readiness and timer receipts.

The accepted `0.4.4-preview.6` installer remains an updater-capable bootstrap.
A refreshed server baseline cannot relabel that installer's source evidence.
After activation, the watcher prepares a higher version from the actual server,
requires fresh native Windows and messaging acceptance, and publishes that
candidate only while its complete source still matches. Matching code becomes
available locally after that newer installer has been accepted and installed.

Matching source does not increase offline coverage: the preview continues to
have bounded offline support and uses the connected dashboard for the complete
live dashboard.
