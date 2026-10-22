# GO ERP offline capacity plan — 2026-10-23

Status: draft for review; no feature implementation or production capacity approval.
Code baseline: `c0cc8ac28e36f201751ed74545d7ee9a00ad3c55` (projected import validation).
Target: 15–20 devices, full authorized dashboard offline, substantial but unmeasured daily activity.

## القرار باختصار

الجديد يتسجل على الجهاز ويُرسل عند رجوع النت؛ هذا المسار موجود بالفعل للعمليات المدعومة.
التطوير المطلوب هو تنزيل البيانات على دفعات، ثم تنزيل التغييرات فقط، وإعادة استخدام الصور بدل تكرار النسخة كاملة.
تقارير كل الأجهزة تحتاج تفاصيل المعاملات في قاعدة السيرفر؛ الرام تُستخدم مؤقتًا لمعالجتها، بينما التخزين يحتفظ بالسجلات.
العمل الكامل بدون نت يحتاج أيضًا نسخة محلية من البيانات اللازمة للعرض والتقارير، وليس العمليات الجديدة وحدها.
سعة ٢٠ جهازًا لم تُقَس بعد؛ اعتمادها يتوقف على اختبارات الحمل والمساحة وتغطية كل أقسام البرنامج.

## Current behavior and limits

These are code limits and observed test results, not supported-workload guarantees.

| Area | Current behavior / evidence |
|---|---|
| Local writes | [`DesktopDashboardJournal::execute()`](../app/Services/Dashboard/DesktopDashboardJournal.php) commits supported business writes and the encrypted outbox together. `pending()` exposes one ordered command; `acknowledge()` requires its committed device/UUID receipt. |
| Upload/retry | [`DashboardSync::flush()`](../desktop-pos/src/dashboard-sync.cjs) sends sequentially, up to 1,000 initially pending commands per cycle. UUID/request-hash replay protection is shared with [`DesktopDashboardReconciliation::ingest()`](../app/Services/Dashboard/DesktopDashboardReconciliation.php). This is not coverage of every dashboard write. |
| Incoming data | `DashboardSync` makes refresh eligible every 60 seconds; [`SyncSchedule`](../desktop-pos/src/sync-schedule.cjs) uses 30-second slots and skips busy work. [`DashboardGeneration::refresh()`](../desktop-pos/src/dashboard-generation.cjs) downloads a full snapshot and stages a new generation after pending/conflicting commands and print effects settle. |
| Server export | [`DesktopDashboardData::rows()`](../app/Services/Dashboard/DesktopDashboardData.php) uses cursors but retains the complete scoped dataset. [`DesktopDashboardBootstrap::export()`](../app/Services/Dashboard/DesktopDashboardBootstrap.php) requires a request-local PHP memory limit of at least 512 MiB, caps rows at 500,000 and row JSON at 256 MiB; the [controller](../app/Http/Controllers/Api/DesktopDashboardController.php) serializes the complete export. That configured minimum does not measure actual memory usage. |
| Client import | [`readSnapshot()`](../desktop-pos/src/dashboard-download.cjs) retains raw JSON plus a frozen parsed graph, bounded by 256 MiB wire bytes. [`desktop/import.php`](../desktop/import.php) decodes the full input; [`LocalRuntime::stage()`](../desktop-pos/src/local-runtime.cjs) uses PHP 256 MiB and a 300-second import deadline. Wire size is not decoded-memory size. |
| Narrow memory fix | [`go_erp_import_memory_test.php`](../tests/go_erp_import_memory_test.php): real Laravel 8.83.29/12.70.0, 119 tables, 34,000 synthetic rows, 26,110,294 wire bytes; old root validation exhausts 256 MiB, projected pre-DDL import peaks at 195,981,312 bytes. This isolated test does not prove full kernel/database completion or larger-volume capacity. |
| Media | [`DesktopDashboardMedia`](../app/Services/Dashboard/DesktopDashboardMedia.php) and [`dashboard-media.cjs`](../desktop-pos/src/dashboard-media.cjs) cap files at 16 MiB each, 20,000 files and 2 GiB total. Each new generation downloads every required file again; file transfers are streamed but not reused across generations. |
| Retention | `GenerationStore` explicitly preserves databases and previous journals. [`DesktopDashboardArchive::seal()`](../app/Services/Dashboard/DesktopDashboardArchive.php) retains source receipts and carries replay identities forward. There is no bounded generation garbage collector. |
| Concurrency | Device locks, serial local synchronization and [per-token request throttles](../app/Providers/RouteServiceProvider.php) exist. They do not establish aggregate RAM, database, disk or request capacity for 20 devices. |
| Coverage | `DesktopDashboardBootstrap::export()` still advertises `full_dashboard=false`; [`dashboard-coverage.cjs`](../desktop-pos/src/dashboard-coverage.cjs) binds scoped preview coverage. Full dashboard acceptance remains a separate requirement. |

Server storage must hold authoritative business details for cross-device reporting, audit and recovery.
Uploading only new operations avoids resending history; it does not remove the resulting central business rows.
Device-specific receipts, snapshot packages, logs and backups are additional storage classes that need their own bounds.

## Unknowns to measure before choosing a capacity envelope

- Actual daily operations and peak operations/second, command/attachment size distributions, table growth and report queries.
- Longest supported disconnection, backlog per device, simultaneous reconnects and required catch-up time.
- Authorized scope sizes, reference closure, image churn, historical reporting range and source/schema diversity.
- Minimum Windows RAM/free disk/CPU, server RAM/FPM workers/CPU/IOPS/free disk, database size and backup retention.
- Current online request latency/error baseline under ordinary traffic. No server resource inventory has been inferred.

Record these as a reviewed load profile `W`; test its peak, maximum offline backlog and at least 2× its measured burst.
Do not assign a daily-volume guarantee from device count or the 34,000-row fixture.

## Proposed design and staged implementation

### 0. Establish the baseline and retain recovery boundaries

Run the full Windows/private-MariaDB import and independent verification at PHP 256 MiB, including print enrollment,
real source/schema binding, activation, restart and numeric transport fidelity. Keep the current pending/conflict fence
and atomic prepared pointer. Instrument counts, durations, queue depth, bytes, memory and disk without payloads or tokens.
This qualifies the narrow validation improvement only; it does not enable full coverage or automatic deletion.

### 1. Bounded, consistent initial snapshots

- Replace full-array export/JSON decode with a scoped export job and page importer; preserve redaction, reference closure,
  exact DDL allowlists, row/schema/source hashes, actor/enrollment checks and media verification.
- Produce immutable canonical row-byte pages, then seal a manifest containing snapshot/source/schema/scope identities,
  table order/count/hash, page sequence/count/bytes/hash and media references. Preserve floats and 64-bit integers;
  JavaScript must not parse and reserialize authoritative hashed rows.
- Initial page target: at most 1 MiB encoded and 1,000 rows. Oversized rows fail with a classified result;
  support for larger rows/blob references requires an explicit bounded format, never truncation.
- Establish one database-consistent cut `H` for rows and subsequent changes. A repeatable-read export transaction lasts
  only for its admitted job, not while devices download; enforce a deadline and bound database undo/temp-space growth.
  Reference discovery must use bounded disk/indexed sets rather than reconstructing the entire graph in PHP RAM.
- Materialize pages into a quota-controlled temporary package, seal only after all hashes/counts succeed, and release
  the database snapshot. Serve only sealed bytes. Resuming a page never re-queries mutable live rows.
- Persist client progress after validated durable writes; replay the same page idempotently after a crash.
  Recheck current device/scope authority on access. Expiry, schema/source mismatch or revocation cannot mix packages:
  abandon the inactive candidate and retain the working generation and journals.
- Verify complete table hashes/counts, references and manifests in an independent process before atomic activation.
  Scope-equivalent packages may share content bytes only after authorization equivalence is proved; no cross-scope cache leaks.

### 2. Incoming changes instead of recurring full rebuilds

- Add durable commit-ordered changes for every admitted writer: dashboard, APIs, jobs, bulk actions and legacy/direct SQL.
  Application observers alone are insufficient when writers bypass them; review database change capture where needed.
- Changes carry immutable identity, entity version, transaction boundary and explicit deletion tombstones.
  `updated_at` polling alone cannot prove deletion capture, ordering or snapshot consistency.
- Bind snapshot `H` to the exact change-stream cut with a verified database protocol; reading `MAX(id)` does not establish
  commit order. Apply complete transactions after `H`; persist the cursor in the same local transaction as their changes.
- Track scope epochs, grants/revocations and rows entering/leaving scope, including dependent references and media.
  Recheck authority on connection; quarantine revoked access while preserving unsent evidence for authorized recovery.
- Reconcile incoming versions against local pending writes and typed ID mappings. Preserve their UUIDs, dependencies,
  receipts and print ownership; surface conflicts without overwriting pending facts or blocking unrelated local work.
  Until this is proved, retain the existing pending/conflict refresh gate and its visible freshness limitations.
- Checkpoint/compact the stream only behind the slowest supported cursor and agreed offline window. An expired cursor
  requires a new bounded snapshot, without discarding the local outbox. Use a jittered delta poll, not a full export every minute.

### 3. Media reuse and bounded retention

- Store verified immutable content by digest/length/type; manifests map authorized logical paths to content.
  Download only missing content, verify before publishing, and isolate private expense attachments from public routes.
  Reuse must not introduce writable hard-link aliases between generations or lose current authorization checks.
- Retain active + one verified rollback generation + at most one staging candidate as the normal target.
  This is not permission to delete extra generations: unresolved journals, receipts, print effects, runtime pins,
  recovery intents or unverified migrations pin them until an audited recovery/archival decision is complete.
- Garbage collection must be crash-resumable and reference-aware, with a durable plan and recheck immediately before
  deletion. Never delete pending/conflicting/uncertain operations or replay evidence to satisfy a disk quota.
- Preflight local space: active DB + candidate DB + rollback DB + unique media + pinned journals/runtimes +
  measured temporary overhead + 20% reserve. Refuse a new candidate early when insufficient; keep local work available.
- If the journal or write reserve itself reaches its bound, visibly refuse new writes before acceptance and preserve
  every already accepted operation. Finite storage cannot guarantee unlimited writes during prolonged disconnection.
- Give server packages a shared content budget, expiry and lease-aware cleanup; do not keep one full historical copy
  per device per poll. Central business retention and backups remain a separately reviewed business requirement.

### 4. Admission control and resource budgets

- Start with one snapshot builder globally, one job per scope/device, a bounded fair queue of 20 requests,
  two transfer requests per device and four globally. These are proposed conservative limits, not measured throughput.
- Reserve an online command lane before allowing exports; add jitter, bounded retries/backoff and explicit retry timing.
  Admission must account for RAM, worker slots, database load, temporary disk and package expiry, not bearer rate alone.
- Provisional acceptance targets: server export PHP heap ≤128 MiB and worker RSS ≤192 MiB; native import PHP peak
  ≤192 MiB within its 256 MiB limit. Measure total Electron/PHP/MariaDB RSS on the minimum supported device;
  its agreed budget and server free-RAM/disk floors must be fixed before pilot admission.
- Persistent storage is bounded by retention and growth rates, not by RAM limits. Publish measured per-job/page,
  per-device backlog and central business/receipt/backup costs; alert before reserves are exhausted.

## Acceptance gates and rollout

1. **Correctness:** full native large import completes at 256 MiB; row/schema/source/media hashes, actor/scope/enrollment,
   integral DOUBLE and unsafe BIGINT values match. Every supported original route/report has offline equivalence;
   remaining routes and external-provider intents stay explicitly unprepared until independently covered.
   A full-dashboard release requires a reviewed inventory with no uncovered authorized route, data or attachment path;
   capacity acceptance of a preview subset cannot satisfy that coverage gate.
2. **Faults:** disconnect and restart during export/page/import/delta/media/activation/acknowledgement/cleanup;
   no mixed snapshots, lost accepted operation, duplicate business effect or silent conflict. Resume the same immutable bytes.
3. **20 sessions:** run 20 independent devices with overlapping/disjoint scopes under `W`, including simultaneous setup,
   maximum-offline backlogs, duplicate/lost replies, conflicting writes, deletes, grants/revocations and schema upgrades.
   Verify central detail/report totals, device convergence, UUID dedupe, attachment integrity and fair backlog draining.
4. **Resources:** zero OOM/disk-full corruption; page/job and retention caps hold after repeated refreshes and a 24-hour
   soak. Critical online writes have zero additional errors and p95 latency ≤110% of the measured baseline under the same load.
   Confirm catch-up time against an agreed target; do not silently invent one while daily volume remains unknown.
5. **Deployment:** review protocol/compatibility and restore tests; canary one device, then five, then 20 only after each
   gate passes. Feature flags preserve the existing readable generation and durable journals on failure.

Full offline coverage, bounded resource use and production capacity are three separate gates; all are required.
