# Offline receipts, kitchen and shift printing: proposed durable protocol

Status: design with bounded executable state exploration. **This document does not
enable offline printing.** The application, deployed preview, route coverage,
provider restrictions, source manifest and `full_dashboard=false` are unchanged.
Source inspection baseline: `7a67016f660bcf49cc9e8c0e49f87efc80f60fe5`.

The next increment should let a device invoke its initial receipt or kitchen/shift job at most
once even if business synchronization, a restart, a lost reply or a dataset refresh
occurs first. Electron's successful callback means the operating system accepted
the request; it does not prove paper left a physical printer. A lost callback must
be displayed as requiring review, never converted into success or automatic retry.

## Why opening the existing routes is insufficient

| Existing seam | Verified behavior | Required change |
| --- | --- | --- |
| `DesktopDashboardJournal` middleware | Unregistered write routes return 501. | Add a distinct device-effect adapter; do not add claim/complete to ordinary business replay. |
| `PosServiceTicket::save`, `queueKitchen`, `action` | The business transaction creates kitchen and pending print rows. Save's command metadata does not retain the kitchen ID. `result()` reads mutable `last_kitchen_id`. | Capture immutable generated kitchen/job outputs in the same transaction, bound to that originating business UUID. |
| `PosReceiptPrinting::newSaleMetadata`, `firstPrintUrl`, `consume`; `TakeawayService::printReceipt` | A new sale stores a ten-minute first-print grant in sale till-entry metadata. A branch cashier's `takeaway.print` GET consumes that grant by updating metadata. Local middleware wraps this unregistered GET in READ ONLY, so the write fails and becomes 501. Owner/admin receipt reprinting can return before this write. | Integrate initial receipt grant consumption with the durable effect burn. Do not whitelist the GET as an unrestricted write. Replayed central sales must not create a second usable first-print grant. |
| `DesktopDashboardReferences::RESULTS` | Captures ticket/receipt/shift references, without kitchen/job references. | Add explicitly typed kitchen/job output slots and validate their exact immutable revisions. Never parse a print URL or map every integer. |
| `DesktopDashboardReconciliation::ingest` | Re-executes the original business service, whose enqueue method creates a new pending central print. | Atomically persist device print ownership and suppress that specific central enqueue before any receiver can observe it. |
| `DesktopDashboardRefresh::begin` | Fences business commands only. | Fence effect creation/claim/invocation against the same device scope and keep the effect ledger across data generations. |
| `DesktopDashboardSchema::EMPTY`, `DesktopDashboardArchive::seal` | Both print queues are deliberately empty on import. Archive transfers business command tombstones only. | Preserve a device ledger independently of replacement datasets; never copy central pending/claimed printer work into the device. |
| Electron `dashboard.cjs` / `dashboard-preload.cjs` | Native bridge accepts a same-origin receipt URL and invokes silent printing, without a durable one-use device permit. | Add a bounded typed effect action; burn a durable invocation permit before calling Electron. Protect automatic kitchen/shift URLs against generic-URL bypass. |
| `branch-print-receiver.js` | Browser claims, calls the URL bridge, then separately posts complete. | Native supervisor must own the durable burn/outcome. A page unloading between those steps cannot reset a job or manufacture success. |
| `desktop/control.php`, `desktop/database.php` | Control token is separate from browser access; DB grants cover generation databases. | Extend control with effect operations and grant only the fixed private effect schema in addition to existing local schemas. |

Changing claim routes alone would expose three concrete failures: a save can sync
before the local completion reaches the server, causing a second central print;
and an acknowledged save can be refreshed into an empty local queue before its
first print; and branch-cashier receipt delivery can remain 501 or consume a grant
outside the new burn lifecycle. All must be prevented before the capability opens.

## Permanent identity and transactional storage

Use a **fixed private MariaDB schema** (`fasakhansta_dashboard_effects`) on the
same bundled instance as all generation databases. This is a proposed architectural
change, not a schema name currently accepted by `desktop/database.php`. Business
rows, business outbox, immutable effect intent and effect payload must commit in
one InnoDB transaction on the same connection, using qualified table names. A
second PDO connection, SQLite sidecar or encrypted metadata write after commit
does not provide that atomicity. The fixed schema must be created/verified before
enabling this protocol; partial provisioning must leave the original routes blocked.

Permanent effect identity is the tuple
`(device UUID, enrollment identity, originating command UUID, kind, slot)`.
`kind` is an enum (`sale_receipt`, `phone_kitchen`, `shift_report`); `slot` is a bounded named
slot, initially `primary`. A deterministic effect UUID may encode that tuple, but
the complete tuple must also have a database unique constraint. The enrollment
identity binds the device's original actor, enrollment hash/time and permitted
branch set. It is a digest of persisted enrollment facts, not the bearer token.

Each immutable effect stores the original actor/branch, originating route and
request hash, business occurrence, generation/database identity, snapshot revision,
canonical receipt snapshot and hash, and exact typed local references:

```json
{
  "protocol": 1,
  "kind": "phone_kitchen",
  "slot": "primary",
  "device_id": "<enrolled device UUID>",
  "enrollment_id": "<digest of persisted enrollment facts>",
  "origin_command_id": "<original business command UUID>",
  "branch": "f:100",
  "actor_id": 10,
  "revision": 3,
  "local_references": {"ticket": 41, "kitchen.primary": 57, "print_job.primary": 63},
  "snapshot_sha256": "<canonical immutable receipt data hash>"
}
```

A shift effect instead names `shift` and `shift_print_job.primary`; it cannot be
resolved by a ticket mapping. An initial receipt effect names `receipt` and
`sale_till_entry.primary`, created by the original checkout/settlement command.
Its original cashier, checkout permission, branch, nonce and original expiry must
be checked when burning. Expiry cannot be extended by restart or synchronization.
The stored grant consumption and effect burn must be atomic, including when the
native grant reply is lost. Local and server app keys/nonces are different: never
copy, reuse or compare their bearer print tokens as business identity.

Reprints have a fresh originating reprint command
UUID and a reference to the reviewed effect. They never replace an old effect's
identity, clear its burn or change an uncertain result to pending. An owner still
needs the original management permission to request a reprint; automatic receiving
still needs the original exact branch receiver account/device.

Mutable lifecycle fields include state/revision, claim token hash, claimant actor,
native invocation UUID, native boot/session identity, durable burn time, native
outcome, pending server receipt and acknowledged server receipt hash. Keep these
separate from the immutable business envelope so a completion arriving later cannot
change the original command's request hash.

One stable device-scope row fences every local business/effect/refresh operation.
Lock order is fixed: stable device scope, current generation local-state row,
business branch/till, then effect row. Effect completion needs stable scope/effect
locks but no new business write. Do not introduce the opposite lock order through
queue controllers or acknowledgement code. This ordering must be verified against
all existing business and refresh callers before implementation is enabled.

## Local lifecycle and native bridge

| State | Allowed next step | Restart or lost reply |
| --- | --- | --- |
| `queued` | Authorized original cashier for an initial receipt, or exact branch receiver for a queued kitchen/shift job, claims it under the device fence. | Still queued; no invocation occurred. |
| `claimed` | Native bridge consumes the claim once. | Review; do not recycle a claim automatically. |
| `burnt` | One native invocation may use the live one-use permit issued by that successful burn. | Review; the process cannot reconstruct a permit. |
| `invoked` | Retry the immutable server outcome receipt only. | Remains invoked; never invoke again. |
| `review` | Management may create an explicitly separate reprint command. | Remains review; never invoke again. |

The native action accepts a typed effect identity and claim token from an owned
dashboard frame. It does not accept a renderer-selected URL, actor, snapshot or
printer-success flag. The control endpoint rechecks local device/enrollment,
original actor and kind-specific branch permission, current generation binding and claim state, then
returns the immutable snapshot/derived receipt URL and one-use permit **only after
the burn transaction commits**. The supervisor checks the installed printer and
prepares the receipt before burning where possible. Initial receipt preparation
must render the immutable snapshot through a read-only capability; it cannot call
the current consuming `takeaway.print?initial_print=...` GET first. Burning must still precede
the first call to `webContents.print`.

On a lost burn reply, the caller has no usable permit and returns an uncertain
failure. Retrying or querying that same burn cannot issue another permit or report
that it succeeded. On a crash between burn and invocation, the job may have printed
zero times, but safety requires review. On a crash after invocation and before a
callback/outcome transaction, it may have printed once and also requires review.
An Electron rejection/timeout after entering the print method cannot justify an
automatic retry. A known accepted callback is persisted as `invoked` before the
native action reports success. A failed outcome persistence reports uncertainty.

The existing generic receipt bridge remains available for its original authorized
manual receipt reprints. Initial sale receipts and automatic kitchen/shift snapshots
must require this typed permit, including when addressed via a URL; otherwise a
renderer could bypass the burn. Owner/admin reprint permissions remain the original
permissions; a reprint creates a separate audited effect. Provider
messaging and its media/capture routes stay blocked locally.

## Synchronization and central suppression

1. The business command carries immutable effect intents alongside immutable typed
   outputs. These were captured during the original local transaction. Lifecycle
   state is **not** part of that business envelope.
2. Ingest locks and re-reads the actual enabled device enrollment. It compares the
   actor, enrollment identity, exact allowed branch, original command UUID/request
   hash, supported protocol, kind/slot, revision and typed references. A stale
   caller's previously loaded object cannot authorize the operation. The existing
   general branch check currently passes `$device`; this effect path must use the
   freshly locked `$locked` enrollment throughout.
3. The original business service runs in a scoped, nest-safe replay context. Its
   enqueue method records a **device-owned central shadow**, not a central pending
   job, for each declared effect. This ownership is recorded even if the local job
   is still queued and even if no completion has arrived. Suppression therefore
   cannot race completion. For a sale, `newSaleMetadata` instead creates a
   device-owned receipt shadow and `firstPrintUrl` refuses a central first-print
   grant. That is ownership suppression, not a fabricated `consumed_at` asserting
   a physical print. No receiver can observe an intermediate pending job or usable
   central initial-print grant. TTL expiry is not a substitute for suppression.
4. Exact generated server ticket/kitchen/job or closing/job outputs are matched to
   the declared effect slots and revision, then stored as typed local-to-server
   mappings in the committed business receipt. Any mismatch rolls back the whole
   business transaction and ownership shadow. No partial suppression may survive.
5. A separate authenticated effect-outcome endpoint accepts an immutable receipt
   keyed by device/enrollment/effect/invocation. Before business ingest has committed,
   it returns a retryable dependency response without changing business state. After
   ingest, it verifies the locked enrollment and exact typed mapping, records the
   receipt idempotently, and never enqueues or invokes a printer. A conflicting
   receipt is rejected; an accepted receipt is not overwritten by a later timeout.
6. Local acknowledgement atomically compares the complete receipt identity and
   effect revision before marking that receipt delivered. A reply for another
   device, actor, command, kind, revision or effect is not success. The native
   scheduler retries delivery, not the physical action.

The server never automatically transfers a device-owned effect to a branch queue
because the device is offline, revoked, replaced or silent. That would duplicate an
unreported local print. A manager reviews it and explicitly creates a new effect.
The protocol can prevent duplicate application invocations, not printer firmware
or spooler duplicating a single accepted request.

Old clients without effect intent cannot be silently treated as participants.
Negotiate a versioned capability and require matching source/schema contracts for
print-producing replay. Until upgraded, keep local claim routes blocked. A central
deployment cannot infer whether an old client printed from an arbitrary receipt URL.

## Refresh, archive, late completion and revocation

The fixed ledger remains the canonical owner when generation A is replaced by B.
It is not populated from bootstrap's central queues and is not copied from A into B.
The refresh transaction fences new claims/burns, verifies all business dependencies
are acknowledged, and records B's binding to the same ledger identity/schema.
Queued effects retain their own immutable snapshot and origin references even if
B's business row numbers are different. Pending delivery receipts and uncertain
effects also remain. Archive keeps command UUID tombstones and records the ledger
binding/count/hash needed to verify retention; it must never reset lifecycle state.

Native transition serialization must drain known active invocations before switch.
If a bounded drain cannot determine an outcome, the durable burn remains reviewable.
A late callback/outcome receipt resolves by permanent effect/invocation identity,
not an HTTP port, current dataset row ID or old generation connection. It may finish
the existing invocation after B is active; it cannot issue a new permit. While a
known invocation's callback remains pending across a bounded drain, review may be
displayed, but a terminal immutable outcome receipt must not be sent and then
replaced by a contradictory callback result. Either wait for a bounded
callback/timeout decision or retain terminal review and append late evidence
separately. Completion
and refresh must serialize on the stable device row so neither overwrites the other.
If stage verification, archive verification or activation fails, A and its ledger
remain authoritative. Rollback of a dataset pointer never rolls back a burn.

Offline operation can enforce only the enrollment last verified/imported locally.
A remote revocation cannot be learned during an actual network partition. The
protocol must state that limitation rather than promise instantaneous offline
revocation. On reconnect, both ingest and outcome delivery use current locked
server enrollment; revoked/reassigned/stale branch scope is rejected and retained
locally for review. A foreign actor/device cannot claim or report another device's
effect. Re-enrollment cannot adopt old uncertain work or renew its consumed permits.

## Verification and remaining implementation work

Run the bounded executable specification with:

```sh
node --test tests/desktop_dashboard_runtime/protocol/offline-print-interleavings.test.cjs
```

It explores enabled interleavings, with state deduplication, of local generation,
claim/burn/invocation/outcome, sync, reboot, refresh and stale-enrollment changes for
kitchen, shift and initial receipt effects. Receipt expiry is modeled as a monotonic
eligibility bit, not a real clock. It also mutation-checks the important properties by deliberately replacing suppression
with completion-dependent suppression and by erasing the durable ledger on refresh.
Those faulty alternatives must produce counterexamples. The model is a design
check, not implementation or hardware certification. Its atomic transitions assume
InnoDB commit/locking works as specified and do not test SQL, Electron, Windows
power-loss durability, TLS, credential isolation, printer drivers or network code.
Each saturated state search is bounded to one effect, one crash/restart, one dataset
replacement, one later edit and one server enrollment change. Idempotent duplicate
messages are self-loops. It does not prove multiple-effect ordering, transactional
lock ordering/deadlock recovery, an arbitrary number of failures, eventual delivery
or management reprint authorization. Those need implementation-level verification.
The checked search reaches a fixed point at depth 15 or less: 5,348 states and
16,667 enabled transitions across the three effect kinds. All ten tests pass. The
mutation cases independently find duplicate calls from completion-dependent central
suppression and from restoring a consumed permit, and detect refresh erasure and
rebinding to a later kitchen revision.

Implementation must change all seams in the first table together, then add actual
MariaDB transaction/race tests and native bridge tests with injected exits at every
boundary. Minimum integration cases: sync-before-print; print-before-sync; save and
later edit producing distinct immutable kitchen revisions; lost claim/burn/outcome
replies; native rejection and callback loss; two claimers; revoked/reassigned device
and stale branch scope; stage/activation failure; queued/review/outcome-pending work
across refresh; callback and server acknowledgement after refresh; generic URL bypass;
first receipt GET staying read-only until a controlled atomic burn; grant expiry;
central sale replay never issuing a usable second first-print grant; and reprint
requiring original management permission and a new command UUID.

Before enabling capability coverage, rerun the existing source/deployment and native
Windows/MariaDB suites against an exact pinned source bundle. Physical printer tests
must separately verify the chosen driver and default printer behavior. No design or
bounded model result can set `full_dashboard=true` or certify those remaining modules.
