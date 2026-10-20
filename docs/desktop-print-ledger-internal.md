# Durable local print intents and native permissions

The device now provisions `fasakhansta_dashboard_effects` explicitly before activating a snapshot with `coverage.print_protocol=1`. The scoped bootstrap exports only the persisted enrollment digest and time, beside its existing device, actor and branch identity. It does not export a server bearer token. Import stores that authoritative identity in the local generation and binds it to a permanent encrypted scope. Old snapshots without this capability retain their prior behavior and cannot open the native effect API.

The permanent schema contains immutable scopes, business command intent sets and print descriptors, plus separate native attempts and audited management reprints. It is granted only to the bundled loopback application user alongside its private generation databases. Provisioning refuses ambient transactions; every table's exact columns, engine, collation and unique indexes are verified. There is no destructive repair migration.

`DesktopDashboardRefresh::lock()` acquires the permanent device scope before generation state. Original journal writes, new intent capture, native claim/burn/outcome and refresh use that ordering. A refresh cannot overtake a transaction already accepted under the scope. The permanent schema survives replacement or removal of a generation database. A URL integer is resolved only against its current originating generation; retained effects use their immutable typed identity instead.

The original Laravel journal commits business rows, encrypted outbox and immutable intents in one cross-schema InnoDB transaction. An intent failure rolls back cash, sale and original journal together. The original manual `atomic()` primitive remains available for the independent component fixture. Historical unowned commands cannot be retroactively adopted.

| Kind | Origin | Immutable typed outputs |
| --- | --- | --- |
| `sale_receipt` | Checkout or individual settlement | `receipt`, `sale_till_entry.primary` |
| `phone_kitchen` | Branch receiver's phone save/send | `ticket`, `kitchen.primary`, `print_job.primary` |
| `dining_kitchen` | Dining send-to-kitchen | `ticket`, `kitchen.primary` |
| `bill_receipt` | Phone/dining request bill | `ticket`, `bill_command.primary` |
| `shift_report` | Original authorized owner/admin closing | `shift`, `shift_print_job.primary` |
| `delivery_batch` | Combined courier collection | `delivery_batch`, submitted-order `receipt.N` slots |

Phone automatic receiving still requires the exact original branch receiver authority. A call-center's phone kitchen keeps its central branch queue. An initial shift report is owned by its original authorized closing actor, retaining `canClose`; it is not granted the separate phone receiver permission. The native application skips automatic sale, unpaid bill and combined-batch invocation because their original form completion explicitly requests the print. Non-sale original URLs always resolve the same original effect, including after claim or burn; an explicit management reprint flag is required for another copy. Management reprints have a distinct UUID and permanent audited effect; they never reset an old burn or overwrite an uncertain outcome.

`desktop/control.php` exposes native-only `print-list`, `print-resolve`, `print-prepare`, `print-burn`, `print-outcome`, `print-review`, `print-outcomes`, `print-acknowledge` and `print-audit` operations. The existing separate supervisor credential remains required. There is no browser grant/claim/complete replay route. Protected local print GETs are refused; the resolver accepts exact original paths and returns a typed effect instead of fetching a mutating URL. Other pages and online print routes retain their original behavior.

Prepare validates current persisted enrollment, actor, branch and kind authority, then persists one claim and returns the original view rendered from an immutable snapshot. The logo is embedded, scripts and event handlers are removed, and the result is bound by a render digest. An exact lost prepare reply may recover the same claim. A foreign token/session cannot steal it.

Print authority uses current locking reads of the account, role assignments, role records, direct permissions, role permissions and permission catalog. It also locks current branch relationships. It does not trust an earlier repeatable-read snapshot or cached Spatie permission. The original journal applies this guard before business work and before returning a saved print-producing reply. A disable or permission revocation committed while a caller waits on the fixed print scope therefore denies prepare, burn and new intent creation without consuming the original grant. A new direct or role grant committed during the same wait is read currently, so an old cached denial cannot reject an otherwise valid prepare or burn. Global `TakeawayAccess` behavior is unchanged.

Burn checks all descriptor, claim, session, render and snapshot bindings. The initial sale expiry is never extended. The original till-entry first-print grant and the durable native burn commit together. When the old generation no longer exists, its permanent effect remains authoritative. Only the first committed burn returns a volatile random permit; even an identical repeated burn returns 409. No recovery path reconstructs permission.

A lost burn reply or a previous-session claim/burn becomes `review`. The application may recover an immutable evidence receipt, never a fresh permit. A successful Electron callback means the operating system accepted submission; it does not prove paper delivery. Native outcomes are immutable and retryable, and late contradictory callbacks cannot overwrite the first result. The permanent ledger retains pending outcome receipts until a matching canonical central acknowledgement is verified.

Central replay validates the immutable enrollment/effect tuple and typed references before invoking the original business service. Within that same transaction, `DesktopDashboardPrintOwnership` marks the exact phone/shift queue rows `device_owned` and disables the separately generated central first-sale grant. Device-owned combined collection disables its nested individual grants. The additive `2026_10_20_000001_create_desktop_dashboard_print_ownership.php` migration stores the permanent local-to-central typed output mappings and immutable outcome acknowledgement. Replaying or refreshing cannot expose a second pending central queue job for that owned effect. Ordinary online work and call-center-owned central jobs keep their original paths.

## Verification

Run the original Laravel/MariaDB component and lifecycle fixture against a fresh disposable bundled instance:

```sh
python3 tests/desktop_dashboard_runtime/native-run.py <mariadb-root> <php-executable> <application-directory> --print-ledger
```

The fixture checks actual original checkout, phone/dining/kitchen/bill/shift/collection services; cross-schema rollback; independent process contention including a warm repeatable-read snapshot and cached permission followed by committed account/direct/role revocation; durable grant burn; changed identity/render/receipt rejection; separate management reprints; restart review; generation replacement with reused integers; typed central output drift; and duplicate central grant/queue suppression. Native JavaScript tests and the Windows original runtime/installer workflow must also pass for the final assembled source. No physical printer or production activation is implied by these fixtures.
