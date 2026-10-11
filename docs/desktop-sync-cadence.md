# Desktop synchronization cadence

Automatic reconciliation starts at the first 30-second boundary after the desktop application is ready. One shared scheduler runs the retained POS ledger, uncertain server-outcome recovery and dashboard outbox sequentially. It never starts overlapping cycles or replays missed timer slots in a burst. If a cycle spans a boundary, that boundary is skipped.

Local writes remain immediate and durable. The renderer no longer requests synchronization after each action. Both native synchronization buttons queue the next scheduled cycle and return immediately; repeated requests share that cycle. Commands added while the dashboard outbox batch is being submitted wait for a later cycle, retaining their UUIDs and acknowledgment rules.

A request made during an accepted cycle joins that cycle's promise. If it requests a complete refresh, that flag is retained for the next free scheduled boundary and shown as queued; busy boundaries stay skipped. Closing cancels that flag along with other queued work.

Initial pairing may immediately read health and menu data, but this bootstrap cannot submit existing paid operations. Desktop preparation waits for the shared scheduled cycle before checking retained POS writes and open invoices. Shutdown cancels queued requests and future cycles, aborts active transports and preserves unacknowledged operations.

After verified preparation, the original dashboard pages use the local runtime both online and offline. Preparation switches to those pages immediately after the protected snapshot completes. Background reconciliation refreshes local data without returning forms to the server or requiring an active browser server session. An uncertain legacy server write still requires recovery before its local data can be activated. An absolute production URL from a local dashboard page is blocked rather than submitting outside the scheduled cycle.

The existing 60-second minimum between successful complete dashboard snapshots remains in place; uploads continue on the shared 30-second schedule. A complete snapshot stages a database and media and is not a lightweight incremental poll. Replacement waits for the settled dashboard home page with no editing, modal, popup, native printing or outstanding page request. Other pages keep their immediate local operations while the bulk refresh is deferred. Home navigation is briefly held throughout a safe replacement and its selected report URL is retained. A failed or deferred snapshot cannot claim refreshed data or postpone the next upload slot.

Local acceptance covers exact timer boundaries, concurrent requests, slow and delayed cycles, failures, shutdown, arrival during an outbox batch, actual main-process pairing and packaged dashboard/preparation wiring. A new Windows build must execute acceptance against this source before installer delivery.
