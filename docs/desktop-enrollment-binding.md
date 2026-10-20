# Immutable authenticated enrollment

The central dashboard locks and compares the exact authenticated device ID, actor ID, enrollment nonce hash, token hash and UTC enrollment timestamp before reconciliation, data export, media delivery, native reservation/recovery, session identity proof and print-outcome recording. Authentication objects must carry all fields from the original token lookup. Equivalent database timestamps and timezone-aware date objects use the same UTC second representation. Missing, disabled or replaced enrollments fail before a cached receipt or new business work. Display name, last-seen time and current branch/created-scope grants are not substituted for this immutable identity.

Central reconciliation stores a versioned enrollment fingerprint in its existing encrypted journal wrapper. A newly authenticated replacement using the same device UUID cannot inherit an older ordinary receipt. Native reservations store the fingerprint in the existing encrypted `response_cipher` from the ready/cancelled state onward; committed results and transport aliases preserve it. The per-request capability also includes the versioned binding, so deleting and recreating the same reservation UUID/path under another enrollment does not revive its old capability. The API response shape and database schema are unchanged.

Old receipts or reservations without a recognized binding fail with a review-required 503. They are neither deleted nor interpreted as cancelled. Original committed financial and business rows stay intact. Native uncertainty remains recorded until its actual central result is reviewed. Opaque media tickets also bind their enrollment; a newly linked device cannot reuse an earlier enrollment's ticket.

Central print outcomes additionally lock their actual originating central journal
command and verify its encrypted enrollment binding before accepting either a
first outcome or a saved acknowledgement. A token-only rotation cannot inherit
that older command even when the nonce, enrollment time and BASE branch set are
unchanged. Missing, unreadable or unbound journal evidence requires review with
503; a different recognized enrollment returns 401. The ownership row, original
financial rows and unresolved outcome stay intact. This adds no schema change
and leaves the durable local print ledger's existing enrollment identity intact.

`tests/desktop_dashboard_runtime/enrollment-run.php` runs the existing original cash integration setup and `enrollment-races.php`, then the existing native CRM controller/rollback/recovery regression. The enrollment fixture uses real authenticated objects and an independent PDO connection to replace enrollment beneath a warmed repeatable-read snapshot. It covers nonce/token/time/actor mutation, same-UUID delete/re-enroll, unchanged exact replies, UTC date equivalence, mutable display names, new credential versus old receipt/media/reservation denial, native capability identity, and preservation of unknown ready/committed outcomes. The CRM fixture retains the original encrypted ready context across failures and restores its actual initial owner status, supporting both nullable legacy and captured deployed schemas.

This identity guard does not replace each module's current role, original controller and enrolled branch authorizer. Current generic scope checks are a separate requirement; no missing branch is inferred for older branchless receipts. Direct central actions outside reviewed native reservations retain their separate uncertainty protocol.
