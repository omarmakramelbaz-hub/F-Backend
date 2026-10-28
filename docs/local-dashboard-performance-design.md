# Local dashboard performance and incremental synchronization

Status: proposed design for the current application. This document does not implement or deploy the synchronization protocol below.

## Required behavior

Use the existing persistent local MariaDB database and the dashboard's existing business rules, screens, permissions and workflows. Local reads and accepted writes must work without waiting for the server. When online, one background cycle per physical workspace pushes accepted local operations and pulls server changes on a 30-second schedule. Offline work remains durable and resumes in order when connectivity returns. This is not a proposal for a second business database or a server-only desktop wrapper.

## Current findings and limits

- The supplied trace records `startup-ready`, then `runtime-archive`, with no subsequent phase. Its elapsed value is the time of entry into archive selection, not the duration of the archive operation. Local startup has not yet reached the `runtime-source`, database or web markers in that trace. The exact time spent hashing, copying or converting the archive is not yet measured.
- `LocalRuntime.boot()` awaits `RuntimeArchive.select()` before starting MariaDB. Selection includes fresh retained-file verification and can include the first compact-vendor conversion. Packaged PHAR artifacts and generated native pins already exist in the current build process; whether conversion completed on this device needs verification.
- The incoming workspace refresh captures/copies the local business tables before obtaining and evaluating a delta. Its no-change certificate can avoid a later database replacement, but does not avoid that initial full capture.
- The order board can exclude automatic update handoff while it is open. Some navigation GET/script waits are unbounded, and unsupported local routes still return `501`. A smooth shell alone would not establish local feature parity.

These findings locate work to remove; they do not establish a measured end-to-end performance improvement.

## Local transaction and outbox boundary

Keep one physical workspace database with actor-scoped access. Each accepted business command commits its business changes and its durable outbox record in the same local MariaDB transaction. The record carries the original command UUID, actor/grant binding, global workspace sequence, dependencies, canonical payload hash and expected server entity version where relevant. UI success follows this local commit, not an upload acknowledgement.

Preserve current command identities, actor attribution, uncertainty fences and journal/reconciliation rules. Account changes must not relabel or filter another actor's accepted commands. Acknowledgement and printing are separate effects: receiving or replaying an acknowledgement must not print again.

## Ordered idempotent push

Drain the durable global outbox in dependency order. The server authenticates the workspace and validates each command's original authority and business preconditions. In the server business transaction, enforce a unique command/effect identity and store its payload hash and stable result. The same UUID and hash returns the original result after retries; the same UUID with a different hash is rejected.

Persist acknowledgements and local-to-server ID mappings atomically on the client. Advance the acknowledged prefix only when every preceding accepted command is settled. Lost HTTP replies remain uncertain until a status lookup or idempotent retry establishes the result. Explicit conflicts retain their original commands and block dependent operations from being silently reordered.

## Server change journal and pull contract

Add a server change journal beside the existing business tables. Every supported writer, including imports and background jobs, records its affected entity keys, versions, transaction grouping and tombstones in the same transaction as the business change. An uncovered writer makes incremental pull incomplete and blocks rollout for that table.

The cursor must describe a contiguous committed publication prefix. A raw auto-increment value allocated before commit is insufficient: a slower transaction could otherwise commit behind an already advanced cursor. Use a transactional publication counter/commit barrier, acquired in a consistent lock order and held through commit, or an equivalent proven commit-ordered log. Read a stable high-water mark and return bounded pages of whole transaction groups through that mark.

Each response binds the workspace/root, schema and protocol version, authority epoch, cursor range and batch identity. It contains only authorized entity projections, their server versions, explicit deletions and required relationship/ID mappings. Advance across examined unauthorized log entries so a filtered workspace does not poll the same range forever. Authenticate the response through the existing transport and authority model; cursors are not credentials.

For a current cursor with no relevant changes, return a small cursor/authority response. Do not export every table, hash all business rows or create a database copy to prove this no-op.

## Branch scope, membership and revocation

Check current authority before serving a pull page and include an authority epoch/control result even when there are no business changes. A changed roster or branch grant invalidates stale sessions and projections before new data is exposed. Refresh actor-scoped views and caches from the authenticated authority result.

Because the database is shared locally, revoking one actor must not delete data still authorized to another workspace member. Remove a branch's materialized data only when it leaves the authorized workspace union and a verified purge plan permits removal. Preserve outstanding commands and audit evidence for server adjudication; never discard financial work to make a purge appear successful. Existing offline authority-expiry policy remains explicit: remote revocation cannot be observed instantaneously while disconnected.

## Atomic incremental apply and reconciliation

Introduce durable local inbox/batch and sync-metadata tables in the same existing MariaDB instance. Receive bounded pages, validate their bindings and store them transactionally. Track separate received and applied cursors: receiving a batch is not proof that every conflict has been resolved.

Apply transaction groups in order using short MariaDB transactions. In one commit, record batch/event deduplication, apply eligible row changes/tombstones and advance the contiguous applied cursor. A crash before commit leaves the prior cursor; replay after commit is a no-op. Never advance past a missing batch or pretend an unresolved group is applied. Media downloads use their own verified durable work list and must not hold the business transaction open.

Pending local commands must not be overwritten by incoming rows. Recognize confirmed echoes of this device's own command through the command/effect identity and reconcile them with its acknowledgement and ID mapping. For a competing remote change, validate the command's expected base version and use only an explicit domain-specific reconciliation rule. Otherwise quarantine the affected transaction/entity in the durable conflict inbox, retain local work, and show its unresolved status. Later versions of the same entity stay ordered behind that conflict. Payments, balances, inventory and order transitions must not use generic last-write-wins or silent financial merging.

Ordinary pull applies changed entities in place. Full snapshot/import or generation reconstruction is reserved for initial setup, an incompatible schema/source contract, an expired cursor outside journal retention, or explicit verified recovery. Such a fallback is visible and resumable; it is not the routine 30-second path.

## Scheduling and UI behavior

Keep a single workspace scheduler, not one timer per account or page. Every 30 seconds, when online and eligible, coalesce a cycle: recover uncertain effects, push a bounded ordered batch, then pull/apply bounded pages. Never overlap cycles or accumulate a catch-up queue of missed timers. Use bounded network deadlines, cancellation and backoff; reconnect can request an immediate coalesced cycle. The schedule defines when work becomes eligible, not a guarantee that an arbitrarily large backlog finishes within 30 seconds.

Background synchronization must not make the entire page inert. Publish only affected entity/count invalidations after a local apply transaction. The open order board should receive incremental changes without a page replacement or an idle requirement that blocks synchronization for the whole session. Active drafts remain intact. Preserve short domain locks around actual conflicting operations rather than serializing navigation behind the entire synchronization cycle.

## Startup and short-term changes

Keep fresh integrity checks before executing retained PHP/native code. Do not replace byte verification with a timestamp-only cache or run an unverified runtime while checking it in the background. Measure archive selection as distinct inventory, copy and compact-conversion stages, including file/byte counts and cancellation progress; log only allowlisted timing/count metadata.

The packaging improvement is to prepare and independently verify the executable compact runtime during the build/install pipeline, reducing small-file work at first foreground launch while preserving source, native-binary, PHAR-index and receipt bindings. Existing operations and active MariaDB files remain untouched. Treat this as a tested packaging change; the current trace alone does not prove its achievable duration on the user's hardware.

Short-term frontend/native changes can bound safe GET and script-loading waits, cancel superseded read navigation, clean up a failed load, avoid overlapping board polls and keep the 30-second scheduler independent from page idleness. They must not replay POSTs, enable uninitialized mutation controls, erase drafts or claim to implement incremental replication. Removing a spinner or adding a timeout is not itself a speed measurement.

## Local parity and deployment boundary

Maintain an explicit route/workflow matrix for local reads, local journaled mutations and genuinely external services. A currently unsupported local route is an implementation gap, not a completed offline feature. Preserve original validation, branch scoping, receipt/PDF/printing behavior and user-visible results as each route gains local support. Functions requiring an external service need a truthful pending/online state rather than fabricated offline success.

Negotiate the incremental protocol, schema and cursor support before enabling it. Keep existing checkpoints recoverable while the new journal covers all supported writers and migration tests pass. Reconcile the accepted outbox and establish a consistent initial cursor before cutover. Do not change the active source/data pointer mid-command or delete retained recovery evidence to force adoption.

## Acceptance targets (not measured results)

| Area | Required evidence |
| --- | --- |
| Foreground local work | Opening supported pages and committing supported operations need no server response; local business mutation and outbox commit are atomic. Establish and record p50/p95 budgets from the same device and representative owner workload before claiming improvement. |
| Startup | Record completion times for archive/inventory/copy/compact, database, web and first interactive page. Demonstrate improvement on the actual blocked stage; do not infer duration from a phase-entry timestamp. |
| No-change pull | A 30-second cycle creates no business-database clone, performs no full business-table export/hash and applies no business-row writes. Response/work scales with cursor/control metadata. |
| Changed pull | Payload, reads and writes scale with authorized changed entities and required relationships, not total database size. An open order board and active draft remain responsive and intact. |
| Ordering and duplicates | Duplicate sends/replies, reconnects, cross-actor commands and ID dependencies produce one business effect in the accepted global order and no duplicate print/payment. |
| Crash recovery | Fault injection before/after local commit, server commit, acknowledgement, inbox storage, apply and cursor commit preserves operations and resumes without skipped changes. |
| Concurrent change | Conflicting remote/local financial or inventory changes are explicit and auditable; unresolved work is not silently overwritten or marked synchronized. |
| Authority/deletion | Tombstones, branch removal, actor revocation and workspace-union changes are enforced with pending work preserved and no unauthorized cross-actor exposure. |
| Coverage | Every server writer for enabled tables is journaled; every advertised local route has role/branch/side-effect parity tests. Unsupported cases remain explicit. |
| Limits | Large owner datasets, slow disks, concurrent polling, offline intervals, expired cursors and cancelled shutdowns have bounded memory/work, cancellation and observable progress. No universal opening-time guarantee is asserted without measurement. |
