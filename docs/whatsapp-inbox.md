# WhatsApp dashboard inbox

This release adds an encrypted, read-only inbox for Fasakhansta's real WhatsApp account (`468336579702269`) and phone-number ID (`515388018324075`). The existing signed webhook receiver continues capturing raw events. The consumer projects supported inbound messages and outbound copies into conversations; it never calls Meta, changes conversation control, sends a reply, saves an ERP customer, creates an order, charges a payment, or invokes an AI provider.

The sidebar entry **رسائل واتساب / WhatsApp inbox** is available to the central Owner and administrative Admin through the existing persisted dashboard access checks. Branch accounts cannot access full chat history. Branch assignment and access to an assigned order segment require a later implementation; a customer's shared WhatsApp history must not become visible to a branch because a later order is assigned there. Local desktop mode reports the inbox unavailable.

## Supported storage and processing

- `whatsapp_inbox_conversations` stores account/phone IDs, a keyed identity hash and encrypted customer metadata.
- `whatsapp_inbox_messages` stores a unique account/phone-scoped SHA-256 message identity and encrypted normalized content. Copies and replayed webhook events do not create duplicate messages.
- `whatsapp_inbox_ingestion_failures` stores the raw event ID, fixed reason code and retry count. Unsupported or conflicting records remain encrypted in the raw capture table and are retained unprocessed for review. Their failure markers prevent them starving later valid events.
- Raw `processed_at` is updated in the same database transaction as the inbox projection. A failed event transaction does not leave a partial conversation or message.

Customer identity must come from documented payload identifiers. Similar message text, a reused test code, or approximately matching timestamps is insufficient to merge conversations. The system does not label every outbound echo as an AI reply; it preserves the actual source and direction.

The bounded command is:

```bash
runuser -u fasakha -- php /home/fasakha/public_html/artisan whatsapp:consume-inbox --limit=100
```

The command prints only fixed numeric JSON counters. Exit `0` is clean, `2` indicates quarantined events, and `1` indicates an error. Ordinary runs skip already quarantined events. After a reviewed normalizer correction, an explicit `--retry-quarantined` run can retry them. Do not delete the encrypted raw events to clear a warning.

No recurring worker or schedule is installed by this release. After reloading and checking the persisted central user's permissions, each inbox list or message request processes a bounded batch of up to 20 pending captured events before reading the saved projection. Opening the inbox and its regular refresh therefore advance ingestion while it is in use. The explicit command can process a larger bounded batch without opening the page. Runtime queue configuration being `database` alone does not establish that a worker is running. Dedicated background scheduling and its production verification remain a separate step. External AI calls must remain outside the webhook acknowledgment path.

## Safe additive installation

Run `deployment/install_whatsapp_inbox.py` as Unix user `fasakha`, with `--root /home/fasakha/public_html` and `--release` set to the reviewed full 40-character commit SHA. The caller fetches the approved branch without changing the checkout and verifies the installer blob before executing the helper. Do not pass an unpinned branch name or replace the current server checkout.

The helper stages a fixed manifest directly from the pinned Git commit. It rejects symlink entries, missing files, altered existing additions, an unreviewed dashboard core, and unexpected route/menu content. It lints staged PHP and runs the standalone protocol and consumer fixtures before writing project files.

Only two existing files receive additive changes: one `require` at the end of `routes/admin.php` and one sidebar include before the unique settings marker. Their precise patched versions are accepted on repeat runs. All unrelated bytes and existing file ownership, group and permissions are preserved. The legacy dashboard services, console Kernel, webhook capture, `.env` and runtime configuration are not changed.

Private backups are created under `/home/fasakha/whatsapp-release-backups/inbox-*`, with a receipt recording the pinned release, file hashes and metadata. A lock prevents simultaneous installer runs. Before each write and before declaring success, the helper checks that the protected file snapshots still match.

Linux `renameat2` support and a shared filesystem for the project and private backup are required; the helper fails closed if either is unavailable. It records publication before each atomic action and blocks termination signals through that critical section. Existing files are atomically exchanged while their actual displaced inodes remain private, then checked against the protected snapshot. This catches an edit arriving after the earlier guard. Restoration uses no-overwrite moves, preserving any newer destination instead of replacing it.

The helper clears only the route cache, runs only the new inbox migration through its explicit `--path`, and performs a read-only smoke check for schema, command registration, route middleware and guest denial. It does not run every pending migration, clear application data, use a production user's identity, or execute an ERP order operation.

An optional `--consume 100` argument performs one bounded projection batch after the installation smoke check. Projection quarantine or error output reports a review-required status while retaining the working installation and raw capture. This flag does not configure recurring processing.

## Recovery

If a file write, migration or smoke check fails, the helper restores only files whose content and metadata still match its own writes. Concurrent edits are preserved and reported for review. It restores legacy route/menu anchors first; if either cannot be fully restored safely, all new inbox dependencies remain available so a retained include cannot point at deleted code. Newly created schema and any stored data are retained; the helper never drops tables or calls `migrate:rollback`. If a migration stopped after creating part of its schema, review the retained schema and the migration receipt before retrying. Do not remove production tables or bypass guards to force another install.

Restore or diagnose using the reported private backup receipt. A route-cache recheck is required if its cleanup failed. Never paste environment files, tokens, decrypted customer conversations, or raw exception output into troubleshooting messages.

## Validation

The standalone Python installer fixtures use temporary directories and mocked subprocesses; they exercise additive anchors, exact repeat installation, metadata preservation, missing and altered core files, symlink rejection, staged-file type and lint failures, migration/smoke rollback, concurrent edits and permissions, atomic new-file creation, and quarantine reporting. Explicit race fixtures cover a competing edit immediately before exchange, interruption immediately after exchange or a new-file link, and another edit during rollback; retained anchors keep their dependencies. They also verify termination signals are blocked during publication and missing atomic-exchange support stops before project mutation. They use no PHP process, network, server credentials or production database.

The PHP protocol and consumer fixtures separately validate payload identity/direction, safe malformed-event handling, transactional replay and rollback. The installer runs them from the pinned staged release. Production smoke proves the local route/schema wiring; it does not prove browser rendering, ongoing processing or AI order dispatch. Those checks must be reported separately.

AI extraction, customer persistence and order dispatch are not implemented by this inbox release. A later integration must use the existing branch customer, catalog, delivery and POS quote contracts; an agent's approximate total cannot replace authoritative product/stock/tax/delivery validation.
