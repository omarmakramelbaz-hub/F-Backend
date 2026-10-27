# Reproducible final release bindings

`deployment/compile_go_erp_shared_workspace_bindings.py` is an offline compiler.
It reads existing Git objects, writes a new review directory, and performs no Git
fetch/write, ref update, deployment, SQL, build, allocation or publication. These
are preparation instructions for the release maintainer, not a production command.
No actual final core or seed is supplied in this document.

The accepted final core must be the exact newer feature revision whose latest
five workflows and actual Windows artifacts have passed strict verification.
Intermediate R13–R17 diagnostic heads must not be substituted merely because
their production PHP/CJS bytes happen to match. Fixture, workflow and verifier
bytes are also release inputs.

## 1. Prepare the historical seed

Read the accepted core object into a local repository before invoking the compiler.
Use `seed-files` with `--git-dir`, `--core` and a fresh `--output` directory. It emits:

- The core's current `deployment/verify_go_erp_preview31_seed.py`, changing only
  its `CORE` and `POLICY` assignment literals.
- The core's current `tests/test_go_erp_preview31_seed.py`, changing only the two
  corresponding expected literals.
- The core's current `.github/workflows/go-erp-update-tests.yml`, changing only
  the SHA beneath `Load the exact reviewed private import diagnostic core`.
- `seed-plan.json`, with the required parent, three blob/SHA hashes, fixed shared
  policy and an explicitly unavailable seed revision.

The old verifier/test/fetch values must agree before transformation. The snapshot
transport fetch, feature branch triggers and every original test/function remain
byte-identical. Publish these three `100644` files as a single direct child of the
accepted final core. Seed commit SHA depends on actual commit metadata; the compiler
never guesses it from the tree. Retain the actual returned seed SHA and read it back.

## 2. Compute source, runtime and proof bindings

Use `bindings` with `--git-dir`, `--core`, the actual `--seed`, and a fresh `--output`.
`--web` defaults to the core itself; a separate reviewed web release is supported
only when its complete backend tree is exactly equal to the core. The compiler
requires the seed to have exactly one parent, exactly the three allowed modified
regular files, and exactly the bytes produced by stage 1.

The outputs are `root-pins.json`, `seed-lineage.json` and `binding-manifest.json`.
The compiler computes:

| Binding | Source and verification |
| --- | --- |
| Source plan 59 | Exact production diff from confirmed candidate `5becb9b…` |
| Source plan 60 | Exact production diff from known performance web `be79700…` |
| New directories | Ancestors absent from each baseline, ordered parent first |
| Source ordering | All additions first; replacement dependencies and controllers before views |
| Schema | Accepted real MySQL/MariaDB four-profile JSON and exact two unchanged migration bytes |
| Contract | Exact five program bytes and `desktop/workspace-contract.json` |
| Native runtime | All seven blob/size/SHA triples derived from verified immutable 59 bytes and actual seed |
| Startup proof | Actual core's helper, generation fixture and native module bytes |
| ASAR proof | Every immediate `desktop-pos/src/*.cjs` basename and actual bytes |
| Workspace proof | The final core verifier's exported `sourceFiles()` roster and all actual bytes |
| CI | Final native/foundation workflow and verifier hashes; original three remain mandatory |
| Electron | Exact installed Electron version in the core package lock |
| Routes | Eleven exact workspace URI/method/controller rows, with strict provider/include/group structure |
| Helpers | Actual frozen operator module bytes and exact original legacy resumer |

Release-only branch files are not deployed; a differing/deleted branch-only test or
workflow does not become a website source operation. Production deletions, existing
database-source changes, modes outside `100644`, dependency drift, a true default
feature flag, changed migration/program bytes or malformed route/source rosters stop
the compiler. The actual root loader's public-package validator runs before output.

The cached previous native payload file is public immutable predecessor code, not
server state. Its own SHA is fixed and all seven original blob/size/SHA triples were
verified when exported. Decompression is bounded; the existing payload transformer
checks the original source/worker hashes before any substitution.

The verifier roster is evaluated with a virtual source-directory listing and only
the reviewed built-in imports. It cannot read arbitrary repository paths through
the provided filesystem adapter. The full returned roster must be unique and consist
of existing regular files. This preserves R15 shared-roster and R16 generation fixture
changes without duplicating a stale list inside the compiler.

## 3. Materialize the root and wrapper for review

The optional `--materialize --approval` arguments additionally require the parent's
reviewed final-core acceptance record. This is a trusted release-maintainer input
assembled only after authoritative GitHub run/job and artifact checks. It is never a
production request or a substitute for the root operator's fresh candidate checks.
Its shape is:

```json
{
  "kind": "go-erp-reviewed-final-core-acceptance",
  "core": "ACTUAL_ACCEPTED_CORE_SHA",
  "all_latest_required_ci_accepted": true,
  "actual_native_artifact_strictly_verified": true,
  "installed_asar_artifact_strictly_verified": true,
  "native_receipt_sha256": "ACTUAL_NATIVE_RECEIPT_SHA256",
  "native_artifact_sha256": "ACTUAL_ARTIFACT_SHA256",
  "installed_asar_receipt_sha256": "ACTUAL_ASAR_RECEIPT_SHA256",
  "workflows": {
    "EACH_OF_THE_FIVE_EXACT_WORKFLOW_FILENAMES": {
      "head_sha": "ACTUAL_ACCEPTED_CORE_SHA",
      "event": "push",
      "status": "completed",
      "conclusion": "success",
      "path": ".github/workflows/EXACT_FILENAME",
      "id": "ACTUAL_INTEGER_RUN_ID",
      "attempt": "ACTUAL_INTEGER_ATTEMPT"
    }
  }
}
```

The five filenames are `desktop-dashboard-tests.yml`, `go-erp-update-tests.yml`,
`whatsapp-integrated-regressions.yml`, `go-erp-shared-workspace-tests.yml`, and
`go-erp-shared-workspace-native.yml`. The script rejects missing/failed/rebound runs
and unavailable receipt digests. It materializes only the root `PINS` constant and
the wrapper's three exact empty release placeholders in the review directory,
retaining the acceptance record and hashes of every output. It emits no production
invocation. Publish and independently inspect the resulting immutable operator commit
before preparing the single user-facing command.

Private predecessor receipts, handler/cache/environment fingerprints, source capture,
database identity and candidate version are deliberately runtime observations. The
root operator captures and revalidates those under the original locks. A known old
60 reservation is resumed through its original checkpoint; this compiler does not
select, reserve or overwrite a server counter or artifact.
