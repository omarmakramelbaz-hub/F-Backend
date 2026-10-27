# Workspace snapshot and private staging contract

Protocol two remains disabled until the full acceptance contract passes. These
interfaces do not alter protocol-one imports, table digests or financial rules.

## Snapshot identity

The initial payload has `format:2`, `kind:initial-workspace-data` and the
original top-level `tables`, source, schema, media and coverage fields.
`device_id` is the immutable physical workspace UUID. `actor_id` is the
initiating custodian for the physical ledger's audit identity; it is never reassigned
to the account opening the application.

`workspace_manifest` is the authority's complete signed member manifest.
`workspace_enrollment` contains exactly `format:2`, `workspace_id`,
`custodian_actor_id`, `enrollment_hash`, `token_hash` and `enrolled_at`.
`enrollment_hash` hashes the enrollment nonce. It is **not** the root
fingerprint. The latter hashes the canonical envelope
`{format:2,kind:desktop-dashboard-workspace-root,workspace_id,custodian_actor_id,enrollment_hash,token_hash,enrolled_at}`.

Branches are the sorted union of signed financial BASE grants, including a
valid empty union for nonfinancial members. Catalog-created stores are a
separate overlay and do not enlarge those financial grants.

The dataset is selected once from actual validated member models and the
original query/FK policy. Notifications and permission pivots cover all members.
Only current members retain valid password verifiers; each must match its signed
`password_binding`. Other credential columns and nested secrets use the
original redactor. Custody and retired-creator audit rows do not grant media or
business access. Their verifiers remain disabled. An already deleted nonmember
custodian is retained only as immutable metadata; the importer never fabricates
a user row.

## Native expectations and import

The native process independently derives this six-field `expected_binding` from
its sealed credential and accepted manifest:

`{workspace_id,root_fingerprint,revision,roster_sha256,manifest_sha256,token_hash}`.

The last field hashes the actual native bearer. Expectations must never be
derived from public snapshot fields. The full manifest digest uses the same
canonical UTF-8 encoding as the server and native registry.

`workspace-import.php` reads a private JSON header (at most 64 KiB), LF, then the
exact server snapshot JSON (at most 256 MiB). The header is
`{format:2,kind:desktop-workspace-import-input,expected_binding}`. The original
raw JSON row walker still sees `root.tables`, preserving SQL numeric bytes.

The importer compares the private expectations, compound root, roster, member
verifiers, source, exact table hashes, DDL/schema/FK contracts and catalog
evidence before issuing a nonserializable import provenance capability. Only
that capability can install local authority in the inactive staging database.
Local authority uses the PC's own APP_KEY MAC; the server signing key is never
transported. Catalog restoration and physical print binding occur after this
local authority exists.

The receipt keeps original fields plus `format:2`,
`kind:desktop-workspace-import-receipt` and
`workspace:{workspace_id,root_fingerprint,manifest_revision,roster_sha256,manifest_sha256}`.
`workspace-verify.php` receives `{receipt,expected_binding}` in an independent
process and verifies actual database/member/source/media facts. The native
registry atomically commits the complete prepared descriptor, receipt and roster
as its sole active pointer. Cancellation retains the previous generation.

Specific administration modules may carry `coverage.modules` while
`full_dashboard` remains false. Each claim binds an actual signed global
administrator witness, existing transport table counts/digests and a recomputed
structural branch cohort. The source certifies every required table against an
independent complete-source count inside the same snapshot transaction; settings
use only the original editable general names. Address removal additionally needs
all source foreign-key children of `user_address` to be complete. A partial
table or missing structural table produces no claim. Import checks these claims
before SQL creation, then the existing local authority MAC seals them. Page
navigation and authentication do not repeat source scans or table hashing.

## Group deltas and cutover

The request namespace is `format:2`, `kind:desktop-workspace-delta-index`,
`device_id`, custody `actor_id`,
`workspace:{workspace_id,root_fingerprint,revision,roster_sha256}`, source and
table indexes. The response kind is `workspace-data-delta`. Every table index
and delta binds the complete workspace/root/roster context while retaining the
original SQL canonical row digests. Protocol-one actor indexes are rejected.

Roster changes require an independently authenticated predecessor manifest from
server history and an explicit forward replacement. No transition runs during
foreground login. B's password rotation changes the group identity and transports
B's verifier; it cannot be normalized away as another actor's password.

Capture/reconstruction retains the one physical sequence and every actor's
command UUID plus its exact immutable sidecar MAC. Retired actors' accepted
history is not filtered by the current roster. Reconstructed receipts retain
separate `delta_source_identity` and `delta_source_binding`; target membership
cannot substitute for the independently pinned source membership.

The private capture also commits a bounded catalog count/digest against the
same root, source snapshot/schema and physical sequence. The digest uses the
exact public `descriptor`/`creation` wire-array bytes, shared with server
coverage. Native compares the exact raw `created_scopes` JSON slice, preserving
tokens such as `1.0` and large integers. An unchanged nonempty catalog therefore
does not cause another import or Registry pointer write. Private ciphertext,
import provenance and fresh media tickets do not change this public digest.

Full verification creates a private `desktop-workspace-preverified-handoff`
proof before the active write fence. `workspace-archive.php` accepts exactly
`{receipt,expected_binding,source,refresh_id,token,source_identity,media_seal,archive_handoff,handoff_token}`.
It authenticates the v2 handoff and performs the original held-source CAS against
the custody, snapshot/schema, refresh token and complete physical sequence.
It performs no history copy, verifier scan or full attachment hash while held.
An identical retry returns the same archived count without duplicating rows.

## Connected requests and remote uncertainty

Native central requests use the six `X-Fasakhansta-Workspace-` headers
`Device`, `Token`, `Actor`, `Revision`, `Grant`, `Root`. The middleware validates
the root and fresh B grant and requires the original PHP admin session to be B.
These headers and capabilities remain outside the renderer. Legacy connected
and remote headers cannot be mixed with them.

An exact original password POST may carry only an internal issued sign-in
permit before authentication. No actor execution context exists during password
verification. A resulting session for another actor is logged out and invalidated.

Reserved writes additionally carry `Workspace-Attempt` and
`Workspace-Capability`. Known journaled mutations cannot omit this pair. Other
connected traffic checks the same physical ready-attempt fence; account
switching cannot bypass A's unresolved result. Session logout does not clear
that uncertainty.

Reservation uses a fresh issued member scope. Terminal recovery instead checks
the root and the immutable original member proof in signed history. It can only
cancel an unexecuted reservation, create a cancellation tombstone after a lost
reserve reply, or acknowledge an authenticated committed/cancelled result.
It returns no execution capability or business body and never adopts B's grant
to resolve A's history.
