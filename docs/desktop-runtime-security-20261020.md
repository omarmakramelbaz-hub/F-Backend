# Bundled engine security and server compatibility — 2026-10-20

The Windows dependency graph is isolated in `desktop-pos/runtime`. The server keeps
its existing Laravel 8.83.29 vendor directory and Composer configuration. This change
never runs Composer or replaces dependencies on the production server.

The reviewed Windows graph uses Laravel **12.70.0**, Spatie Media Library
**11.23.10**, Guzzle **7.16.0**, Flysystem **3.36.0**, Protobuf **5.36.2**, and Carbon
**3.15.0** on PHP **8.2.34**. The new lock SHA-256 is
`b9a263c91270ecc0357aaa5fefd178f41ea68f87b2ae2ab4bd4b2c44c752d93c`.
A fresh `composer audit --locked --no-dev --format=json` completed with exit 0,
zero advisories, zero abandoned packages, and no filters or ignored advisories.
The Windows workflow retains that exact audit and now fails on any nonzero audit
exit. Security blocking remains enabled when installing the new runtime.

Unused development/legacy bootstrap packages are omitted from this Windows-only
graph: Debugbar, Tinker, PHP CKEditor integration, Laravel Collective, and Linux
wkhtml binaries. The actual captured forms already use NativeDeleteForm; the
original browser editor assets and the pinned native Windows PDF component remain.
Official renamed proxy/CORS/permission middleware are bridged by an explicit
Composer autoload compatibility file. This file is copied into and hashed with
the retained runtime, independently of the captured application source.

`desktop/source-contract.json` states the captured application contract
**8.83.29**, and separately enumerates the reviewed actual engines **8.83.29** and
**12.70.0**. Both the PHP fingerprint and native fingerprint reject unknown engines.
The source fingerprint continues to bind the shared application contract, while
`manifest.runtimeFramework` and the installer receipt identify the actual bundled
engine. Packaging checks its lock against Composer's installed graph. No code
replaces or overrides `Application::version()`.

The limited source compatibility edits preserve existing behavior on both engines:

- Proxy headers retain the exact old Symfony mask, 94.
- The native CLI constructs the captured HTTP kernel before controller replay so
  the original middleware aliases and priority are registered.
- Permission migration pivots use the existing configured/default column names.
- Role form numeric permission selections retain their original database-ID meaning.
- The media filesystem override adopts the new void return type; the old parent
  already returned no value.
- Calendar/day calculations retain integer absolute days, and menu-copy duration
  checks explicitly retain the original absolute comparison.
- Reconciliation explicitly validates and preserves the immutable `payload.facts`
  array under the newer framework's nested-array validation rules. All existing
  fact, authority, consent, identity and replay guards still run.

Validation performed locally against the actual original application:

- 523 Laravel/MariaDB core checks on each engine, including current authority,
  financial/stock/payroll transactions, ID drift, retained generations, CRM/STOP,
  durable recovery, attachments and native reply loss.
- Unchanged HomeOverview feature tests: 16 tests / 413 assertions on each engine.
- Unchanged BranchExpenses feature tests: 26 tests / 234 assertions on each engine.
- 276 native Node tests, zero skips, with the actual official Windows PDF payload.
- The new engine proof passes 24 checks on each engine: independent lock/installed
  identity, captured class binding, source contract, original permission middleware,
  proxy mask, calendar buckets, the original five-table permission migration, and
  canonical ID/named permission selection without numeric-name truncation, and
  Arabic malformed-array validation without a framework formatting error.
- All 22 strict frozen original delete-form DOM contracts (48 checks) on each engine.
- Actual native profile environment/restart/refresh secret tests and an actual
  Node-to-PHP 12 JWT guard proof, including rejection of a missing-secret control.

CI keeps the production Laravel 8 financial feature suite and adds that engine
proof to it. The Windows original-runtime job independently runs the same proof
against its actual Laravel 12 bundle. Complete-schema/browser/native Windows and
installer acceptance must pass on the final combined revision before delivery.

Primary references:

- [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade)
- [Laravel debug-page security advisory and fixed versions](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8)
- [Laravel 12.70 published dependency metadata](https://packagist.org/packages/laravel/framework#v12.70.0)
- [Spatie Media Library published releases](https://packagist.org/packages/spatie/laravel-medialibrary)

The native runtime derives its local JWT signing secret from the encrypted
per-profile APP_KEY with the domain-separated HMAC purpose
`fasakhansta-desktop-local-jwt:v1`. It validates the key, never inherits a central
JWT secret, never uses APP_KEY itself as the JWT secret, and keeps the same private
signing identity across restart, generation refresh, and retained code changes.
The actual API guard can resolve its ordinary anonymous state without importing
a central authentication key or inventing an API login.

The bundled Settings 3 repository uses a small runtime-only UPDATE bridge for
the captured nonunique settings schema. The server keeps the original Settings 2
repository. Real original-migration InnoDB tests pass 60 checks on the bundled
engine and 58 on the server engine: all 48 GeneralSettings properties keep their
49 original row identities (including a separate private sentinel), locked values,
unchanged payloads, missing-property UPDATE-only semantics, rollback and refresh.
The unchanged newer upstream repository is also tested as a negative control and
reproduces duplicate insertion inside a rolled-back private transaction. No new
unique index or production schema rewrite is introduced.
