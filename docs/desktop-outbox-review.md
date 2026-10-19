# Retained synchronization review

The desktop menu and the existing offline status dialog open a native review of
the first unconfirmed dashboard command. It identifies the original UUID, route,
occurrence, attempt count and stored server error without exposing its encrypted
payload, database credentials or enrollment token. The saved server error remains
visible to the enrolled account. Later
commands remain ordered behind this command, and every conflict still blocks
snapshot replacement. The old POS archive is a separate retained ledger.

A conflict offers an explicit, default-cancelled, two-step retry after its reason
has been addressed. Retry queues the exact original command; it does not execute
the local operation again, alter before-facts, create another UUID, acknowledge a
result, remove the local effect or discard a dependent command. The central
ingestion path rechecks all current feature permissions, scope and original
facts. A genuine business-state mismatch remains blocked and needs a deliberate
business resolution; this interface does not manufacture an accepted receipt or
pretend that every conflict can be resolved by retrying.

The native supervisor first writes a separate encrypted audit record through the
same flushed, atomic metadata store as generation receipts. Each explicit retry
has its own UUID and records the reviewed state, device/account/snapshot/source,
previous error, attempt count and outcome. A control timeout leaves an uncertain
audit receipt. Interrupted review intents are never automatically retried. Audit
failure before the private control leaves the command untouched; failure after
the control retains the requested receipt and requires inspecting current state.

The private control locks current local state, device, actor and the first
unconfirmed command. A disabled/mismatched account or device, refresh fence,
revoked enrolled branch, changed error/attempt/status or stale review token
prevents retry. Only `conflict` becomes `pending`; immutable envelope, local
result, identities, dependency list, original occurrence and last server error
remain intact. Saved older runtime sources without this capability display
update guidance and remain unchanged.

Source-bound PHP tests exercise actual database transactions and retained rows;
Node tests exercise native confirmation, metadata persistence, uncertain replies,
generation drift and failure gates. These tests do not certify resolution of an
actual accounting dispute or substitute for the combined Windows acceptance run.
The Windows workflow additionally runs `outbox-review-native.cjs` through the real
Electron executable, actual BrowserWindow/preload/IPC and Windows DPAPI audit.
Its modal answer adapter and private PHP response boundary are explicitly synthetic;
the actual original PHP/InnoDB fixture tests those controls separately.
