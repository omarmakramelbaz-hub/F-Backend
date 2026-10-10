# Desktop synchronization cadence

Automatic reconciliation starts at the first 30-second boundary after the desktop application is ready. One shared scheduler runs the retained POS ledger, uncertain server-outcome recovery and dashboard outbox sequentially. It never starts overlapping cycles or replays missed timer slots in a burst. If a cycle spans a boundary, that boundary is skipped.

Local writes remain immediate and durable. The renderer no longer requests synchronization after each action. Both native synchronization buttons queue the next scheduled cycle and return immediately; repeated requests share that cycle. Commands added while the dashboard outbox batch is being submitted wait for a later cycle, retaining their UUIDs and acknowledgment rules.

Initial pairing may immediately read health and menu data, but this bootstrap cannot submit existing paid operations. Desktop preparation waits for the shared scheduled cycle before checking retained POS writes and open invoices. Shutdown cancels queued requests and future cycles, aborts active transports and preserves unacknowledged operations.

The existing 60-second throttle on complete dashboard snapshot refresh remains in place. This cadence governs background reconciliation; original online dashboard navigation, reads and original server form submissions retain their existing behavior. Returning to the server after a confirmed refresh retains the existing policy.

Local acceptance covers exact timer boundaries, concurrent requests, slow and delayed cycles, failures, shutdown, arrival during an outbox batch, actual main-process pairing and packaged dashboard/preparation wiring. A new Windows build must execute acceptance against this source before installer delivery.
