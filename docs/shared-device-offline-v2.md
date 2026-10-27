# Shared-device offline protocol 2

This is the implementation contract for the requested one-time, device-wide
preparation. It is not evidence that the feature has been deployed. Legacy
account-scoped protocol 1 remains supported until migration completes.

## Product behavior

- Any active account accepted by the ordinary dashboard login can start one
  preparation while online. There is no Owner account-selection step.
- The server derives the eligible active dashboard accounts and their actual
  permissions. The caller supplies no authority, role or branch list.
- Preparation produces one physical workspace and one canonical business
  database. Accounts do not receive separate stale copies of sales or drawers.
- After preparation, another cached account can authenticate offline even if
  it has never logged into this PC. Each account retains its own permissions.
- An offline sale or expense created by A is immediately visible to an
  authorized B in the same branch. Other-branch access remains denied.
- Every account follows the fast opening path: no roster download, source
  migration, database clone, full report or network wait before opening.
- Source/data/account updates use guarded background synchronization. Accounts
  first created after disconnection become available after synchronization.

## Identity and ownership

There is one immutable physical workspace UUID, database, generation, source
archive, command sequence, reference namespace and refresh fence. The original
initiating actor is an audit/custody fact, not the authority of later sessions.

Workspace membership grants bind an actor, accepted dashboard role, username
aliases, permitted branches/modules, password-verifier fingerprint and manifest
revision. They come from fresh persisted server state. Inactive accounts and
consumer accounts receive no offline login grant. No plaintext password is
stored or exported.

The native process owns the workspace credential and membership material.
Browser pages receive progress and the current authenticated actor's UI data;
they never receive the group bearer, another account's bearer or verifier.

The initial implementation uses the physical workspace UUID for all new
commands/references. Command identity also retains the actual authenticated
actor. A typed authority facade presents that actor's validated scope to the
existing business adapters while retaining the root enrollment fingerprint.
The persisted custody actor is never overwritten during account switching.

## Server interfaces

New code lives alongside the account-scoped implementation and is selected
only by explicit protocol-2 state. The rollout must keep workspace preparation
disabled until native, server, recovery, read-scope and printing acceptance pass.

- `DesktopDashboardWorkspaceAuthority` owns enrollment, enabled state,
  membership validation, root-versus-effective identity and fresh actor scope.
- `DesktopDashboardWorkspaceScope` is a typed internal facade. Its root
  identity is not accepted from request fields or deserialized client objects.
- `DesktopDashboardWorkspaceBootstrap` / `WorkspaceData` export one validated
  union dataset and member manifest, preserving nonmember secret redaction.
- `DesktopDashboardWorkspaceDelta` refreshes the union. An actor-scoped delta
  must never be applied to this database.
- `DesktopDashboardWorkspaceState` owns the common refresh/remote-write fence.
- `DesktopDashboardWorkspaceReconciliation` validates the actual actor grant
  before invoking original financial, inventory, reference and print adapters.

Original checks are extended through the authority facade, not removed.
Sensitive legacy GET routes require explicit branch/financial isolation tests:
the previous protection from a filtered per-actor snapshot no longer suffices.
Membership changes must participate in snapshot/delta hashing; another actor's
password change cannot be normalized to an always-disabled placeholder.

## Native interfaces

- `WorkspaceRuntime` owns physical database/source/generation state and native
  children. Lightweight actor facades own cookie partitions, explicit sign-out
  markers and HTTPS sessions. Shared data does not merge those private states.
- `WorkspaceLogin` uses original password authentication in a restricted local
  authentication context, validates the resulting actor's cached grant, and
  lazily creates actor session metadata. It does not create N runtimes or scan
  all actor directories on each login.
- Preparation stages the common snapshot and roster together and activates
  them atomically. It exposes elapsed time/progress and never reports complete
  for a partially imported member set.
- Synchronization drains every actor's original commands in shared sequence
  order, regardless of the currently logged-in actor. Lost replies preserve
  original IDs, actor attribution, reference dependencies and receipts.
- The shared fence includes uncertain remote writes and print outcomes from
  every actor. Logging in as B cannot clear or ignore A's unresolved capability.

## Legacy migration

Inventory every retained protocol-1 profile. Preserve all original databases,
credentials, journals, references, source receipts and uncertain print records.
Do not merge potentially colliding local IDs from independent databases.
Activation waits until every retained profile's pending/conflicting commands,
uncertain prints and generation/remote fences are settled; existing profiles
remain usable while that prerequisite is unresolved. Then import a fresh
server union into an inactive workspace, atomically activate it, and retain
the legacy databases as authenticated archives.

## Required acceptance

1. Branch A starts one preparation; offline B, Owner and A can then log in with
   zero HTTP and without another preparation.
2. A creates an offline order/expense; same-branch B sees it immediately and can
   perform only permitted actions. Other-branch C cannot read or modify it.
3. B finishes A's new order. Synchronization preserves A then B dependencies,
   amounts, actor identities and exactly-once receipts after lost replies.
4. Forged actor/role/branch fields, wrong passwords, unknown users, disabled
   members and stale/rolled-back manifest revisions are rejected.
5. Password/permission updates appear only after a valid fresh synchronization;
   rejected online credentials cannot fall back to stale offline authentication.
6. A's pending/uncertain print or remote write survives logout, B login,
   cancellation, crash and restart without replay or capability adoption.
7. Interrupted preparation/migration leaves every accepted legacy byte and the
   previous active workspace usable; no partial state is presented as ready.
8. Existing protocol-1 financial, branch, native, logout, generation and print
   suites remain valid. New immutable installer/source acceptance is required.
