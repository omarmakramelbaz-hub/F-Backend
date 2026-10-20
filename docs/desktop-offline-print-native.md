# Native dashboard printing

The desktop supervisor executes local `sale_receipt`, `bill_receipt`,
`phone_kitchen`, `dining_kitchen`, `delivery_batch` and `shift_report` effects through the private
PHP control endpoint. The local server supplies the immutable document and
rechecks the enrolled actor, branch, original operation and current permission.
The renderer supplies an effect ID or an original receipt route; it cannot
supply the HTML, actor, printer outcome or a one-use permit.

The original online receipt and central receiver paths retain their existing
behavior. For a prepared local dashboard, the generic native receipt action
resolves the exact original route to a typed effect; it never fetches the old
consuming receipt GET. Local browser queue claim/complete calls are replaced by
the supervisor action. An owner closing a local shift triggers the same bounded
native queue after the business result has been confirmed. An explicit shift
reprint retains its request UUID while its result is uncertain. Non-sale original
URLs always resolve their existing effect, including after a burn or uncertain
result. Only the explicit management action sends `explicit_reprint=true`; an
automatic poll followed by the original URL cannot mint another copy.

Before burning, the supervisor verifies the document SHA-256, loads it in an
isolated hidden Electron window with scripts and navigation blocked, waits for
fonts and images, and checks the selected printer. It flushes an encrypted
`planned` record, requests a single durable server burn and flushes its `burnt`
record before entering `webContents.print`. The raw permit remains in memory
only. An already saved effect cannot enter Electron again after a lost reply,
a repeated click, a process restart or a dataset replacement.

A successful Electron callback means the operating system accepted the request;
it does not prove physical paper was printed. A rejection, exception, timeout,
lost burn reply or interruption requires review. An uncertain effect is never
automatically retried. A late callback cannot replace a saved terminal review
outcome. A distinct management reprint requires a new authorized operation.

The native outcome is encrypted and flushed before success is reported. The
fixed PHP ledger retains the canonical outcome independently of dataset
replacement. Native synchronization sends that immutable receipt to the central
server only after the original business commands are durably acknowledged. A
409 dependency response retains it for a later cycle. The effect, invocation,
terminal state and canonical receipt hash must match both the central response
and the local acknowledgement; delivery retries never invoke a printer.

The active runtime's verified source manifest negotiates support. Installing a
new executable while an older retained dataset is active cannot grant the old
runtime the new print actions. Its normal guarded dataset upgrade remains
available. Every dataset switch is blocked while a native action is active;
uncertain work retains its permanent identity and remains reviewable.

## Checks

`desktop-pos/test/dashboard-print-effects.test.cjs` exercises crash boundaries,
lost replies, disk flush failures, duplicate requests, descriptor tampering,
terminal timeouts and late callbacks, plus actual encrypted file retention.
`dashboard-print-sync.test.cjs` checks immutable delivery, current dependency
handling, exact acknowledgement and business-before-effect-before-refresh order.
The dashboard bridge tests cover owned frames and consuming-GET bypass rejection.

`desktop-pos/test/print-effects-native.cjs` must run in actual Windows Electron:

```powershell
& desktop-pos/node_modules/electron/dist/electron.exe desktop-pos/test/print-effects-native.cjs desktop-pos/dist/offline-print-native.json
```

It exercises actual BrowserWindows, sandboxed preload IPC, restrictive receipt
loading, DPAPI-encrypted journal reconstruction and refresh blocking. Its printer
adapter is explicitly intercepted inside the test process, so it makes no claim
about a printer driver, spooler hardware or physical paper. It contains no test
adapter installed in production source. The JSON receipt records that limitation.
