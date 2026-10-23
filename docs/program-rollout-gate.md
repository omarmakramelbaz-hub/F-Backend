# Program center rollout

The immutable root wrapper has two modes:

```sh
bash run_go_erp_program_root.sh REVIEWED_PACKAGE_COMMIT --prepare
bash run_go_erp_program_root.sh REVIEWED_PACKAGE_COMMIT --publish /var/lib/go-erp-live-updates/acceptance/receipt.json
```

Replace the commit with the final pinned release. The package deliberately refuses placeholder pins. The wrapper verifies the downloaded revision, quiesces the existing worker and leaves its timer paused through preparation and every later failure. Its private downloaded package is retained after a failure.

Preparation installs the reviewed application files, applies only the pinned employee migration as `fasakha` through ea-php82, verifies all nine nullable columns and migration ledger, and upgrades the exact supported Preview35/wire watcher cohort without resetting version or publication sequence floors. Schema changes are reported separately from reversible source/watcher changes. A failed or uncertain DDL step prevents the watcher upgrade; its audit and private logs remain available. Nullable additions are never dropped as a rollback shortcut.

The application's existing `application-*` receipts describe file exchanges only. The enclosing audit additionally records `employee-schema-result.json` and an aggregate `program-result.json` or `program-failed.json`, preserving `database_changed` as true or unknown even if source/watcher rollback succeeds.

A root-owned `0600` `program-rollout-hold.json` exists before the new watcher policy is installed. After successful preparation, the wrapper requests one worker execution to create/push candidate A and let ordinary CI run. The timer remains paused. Repeating preparation may request another tick for the same candidate; successful CI alone cannot publish it while the hold is pending. Candidate/CI failure and server source changes retain the hold and require evidence for the currently desired candidate.

The held worker retains the actual accepted installer artifact and independently downloads the `original-employee-identity-windows` artifact from the same authenticated GitHub workflow run and candidate. It verifies the artifact repository, revision, branch, digest, size and single receipt before retaining that receipt. Only after the actual browser/Windows evidence validates does it write a hash/reference envelope at `/var/lib/go-erp-live-updates/pending-program-acceptance.json`. It never invents passed flags or authorizes publication by collecting evidence. If CI or evidence is still pending, repeat preparation to request another held tick.

The publication command consumes that externally evidenced acceptance envelope. Use `/var/lib/go-erp-live-updates/pending-program-acceptance.json` as its receipt argument. This repository provides no command that manufactures a passed browser or Windows receipt. The acceptance receipt and evidence files must be root-owned `0600` files in root-owned `0700` directories, with safe ancestors and no symlinks or hard links. Its exact fields are:

| Field | Required binding |
|---|---|
| `format`, `kind` | `1`, `go-erp-program-acceptance` |
| `core`, `source`, `inventory_sha256` | Exact final reviewed core, live source fingerprint and candidate inventory |
| `candidate_revision`, `version` | The persisted, pushed `state.desired` candidate and version; also `state.last_remote_sha` |
| `installer` | Exact SHA-256 and byte count from the accepted CI release |
| `ci` | Private artifact directory plus actual `run_id` and `run_attempt` |
| `employee_evidence` | Private employee browser/Windows evidence path and SHA-256 |

The installed worker's normal `verify_proof` validates the complete accepted installer/native artifact bundle. The separate employee artifact must be the actual `original-employee-identity-browser` receipt from the Windows Edge original-runtime fixture, with exact source/candidate/fixture identities and all private-image, branch-scope, consent, replay and cleanup checks. The gate rejects injected DOM, replaced providers, a claim of full-dashboard coverage, missing checks, changed evidence or a different installer. The fixture covers its stated employee flows; it does not certify every dashboard page or the user's physical device.

If CI is rerun for the same still-unpublished candidate, a new publication command may replace its authorization only after fully validating the new run and installer evidence. The original authorization and immutable receipt remain in the private audit directory. The candidate and version stay unchanged. Once that candidate appears in durable published state or the canonical feed, its authorization cannot be replaced.

Authorization permits only A. The resumed worker independently checks GitHub CI provenance and reuses A from durable state. The wrapper may then start the normal timer, but the hold still rejects every other candidate. After publication, the gate rereads the durable state and canonical feed. It retires only when the exact authorized release appears together in `state.published`, `state.desired` with published status, history, sequence/version floors and the canonical `current.json`. It retains a private completion record. A feed file alone or a partial publication is insufficient. A crash before confirmation leaves the hold in force; every later tick checks for confirmed publication before scanning a new source or replacing the desired candidate.

Repeating either wrapper mode after confirmed completion verifies the retained evidence and current durable publication, returns an explicit no-worker result and restores the timer only if it was active before that invocation. It neither requests another release nor leaves normal automatic updates paused. A missing or mismatched canonical publication fails closed for review.

After that one rollout is confirmed, subsequent server changes follow the existing automatic source-bound CI publication policy. The final seed must retain the reviewed `.github/workflows/desktop-dashboard-tests.yml`, including its mandatory employee original-runtime fixture and artifact. The minute-update policy template remains mapped to `.github/workflows/go-erp-update-tests.yml`; it must not replace the desktop workflow. Final identity checks must prove the desktop workflow bytes survive core → seed → candidate unchanged.

Old installer files and release history are preserved. An older feed is never relabeled as a new source: update endpoints already hide releases whose source/GUI fingerprint differs from the running server. No browser, VPS migration or Windows run should be reported as passed until its actual evidence exists.
