# WhatsApp orders, replies and unread state

This upgrade adds AI order drafts, central manual replies and per-user unread state to the existing WhatsApp inbox. It preserves the encrypted raw webhook events, existing conversation/message records and deployed POS/customer/delivery/printing services. It changes only the two known WhatsApp UI files and appends three route includes to the verified existing inbox route include. It does not replace shared menus or admin views.

## Installation

Run `deployment/install_whatsapp_orders.py` as `fasakha` with the confirmed application root and a full pinned release SHA:

```sh
python3 install_whatsapp_orders.py --root /home/fasakha/public_html --release FULL_40_CHARACTER_RELEASE_SHA
```

The helper requires its sibling `install_whatsapp_inbox.py` with Git blob `f09c87de0206b54b8a8ca7d7359b8e15b32ebe98`. The release handoff should verify both downloaded helpers before execution. The existing inbox baseline is release `347ac438b97221ec4b889f9d6dc31521f770c369`.

The installer checks the deployed modern POS hashes, original inbox files, existing inbox menu/include and command autoload. An existing WhatsApp index or inbox JavaScript must match the baseline or the exact pinned upgrade. Other new files must be absent or identical. It refuses unrelated edits, symlinks, wrong owners and unexpected route anchors. It never pulls, resets or checks out the divergent production repository.

It stages and lints pinned files, runs the provider's isolated extraction fixtures, and then runs only these migrations with an explicit `--path`:

- `2026_10_10_000002_create_whatsapp_order_drafts.php`: draft and scan tables.
- `2026_10_10_000003_create_whatsapp_reply_requests.php`: reply ledger.
- `2026_10_10_000004_create_whatsapp_inbox_reads.php`: per-user read positions.

It clears routes and performs read-only schema, command, route and guest-denial checks. It does not process conversations, call OpenAI/Meta, send a reply, create an ERP order, activate automation or install cron/queue workers.

## Configuration and cache

Orders and replies are disabled by default and configured separately. Order processing needs an OpenAI key, a chosen model, an explicitly selected persisted central actor, allowed branch IDs and an activation message cutoff. Reply sending needs a separate WhatsApp access token. Never paste either key into chat or shell command arguments; the separate secure configuration helper uses hidden terminal input.

After installation, run the pinned `deployment/configure_whatsapp_orders.py --root /home/fasakha/public_html` as `fasakha`. Blank credential input preserves an existing credential; a missing credential keeps its feature disabled. Initial order configuration uses review mode, captures both cutoffs after input and leaves automatic dispatch disabled. This helper does not validate keys through an API, send a message or process orders.

The installer never reads or edits `.env` values. It checks file metadata to detect concurrent edits. An uncached application remains uncached. For an existing config cache, it creates a private fresh-bootstrap candidate and replaces only the `whatsapp_orders` and `whatsapp_replies` subtrees. All other effective cached settings remain identical. It does not use `config:clear`, `config:cache` or a general optimization clear.

`WHATSAPP_ORDERS_READY_CONFIG_REQUIRED` means code and tables are ready but order configuration is incomplete. Fixed boolean readiness flags reveal no tokens, credentials or customer content. ERP schema and automatic business-hours gates have separate readiness states. Manual replies have independent `replies_enabled` and `reply_token_configured` flags; enabling orders does not configure WhatsApp sending.

## Runtime behavior

The existing inbox continues its bounded ingestion when an authorized user opens or polls it. `whatsapp:process-orders --limit=10` is a separate command with a bounded limit; this installer never runs it and makes no scheduling assumption.

Initial activation captures global existing-message and raw-event cutoffs so old inbox history is not automatically treated as new orders. AI extracts a draft; the live branch catalog and POS services determine the actual items, prices, delivery/customer data and final phone order. Review mode requires central confirmation. Automatic mode additionally requires configured actor/branch access and the deployed branch opening-hours checks. Test-marker conversations must not create orders.

Automatic analysis applies the maximum of the configured message cutoff, the conversation's stored automatic floor and its last dispatched ceiling before reusing a cached draft. Advancing the cutoff resets a bounded retry sequence only when no live analysis lease is held. A changed message/event cutoff or advanced stored floor invalidates an in-flight automatic result. Stale nondispatched suggestions are reanalyzed in place, preserving command UUIDs; dispatched receipts remain immutable.

Before enabling automatic dispatch, quiesce processes with older loaded configuration and competing projection, drain runnable historical raw capture through a verified event ceiling, and then establish fresh message/event cutoffs consistently. Projected messages do not carry raw event provenance, and configuration changes cannot revoke an already running process's loaded configuration. Preserve quarantine records, customer/order data and dispatched receipts. These runtime guards do not by themselves authorize activation or install a scheduler.

`deployment/whatsapp_orders_readiness.php` provides a read-only inventory of eligible persisted central actor IDs, accessible Fasakhansta branches and local delivery readiness, without printing credentials or customer content. Its optional `--test-ai` flag performs one synthetic extraction request; it does not ingest webhooks, create or quote an ERP order, send WhatsApp messages, or activate automation. A successful no-order connection test establishes API/schema connectivity; representative order extraction and the live inbox conversation linkage still require separate acceptance checks.

Manual text or voice replies require fresh central access and an open WhatsApp 24-hour customer-service window. Outside that window, approved templates are required; template sending is not implemented here and the manual composer must block the attempt. The server selects the linked conversation recipient. A reply marked accepted means Meta accepted the request; it does not prove delivery. An `UNKNOWN` outcome blocks further sends for that conversation pending explicit delivery review. There is no automatic retry or reconciliation of an uncertain send.

Voice replies require PHP `posix`/`proc_open` and executable `/usr/bin/ffmpeg` and `/usr/bin/ffprobe`. The server validates an upload capped at 8 MiB and 60 seconds, converts it to Ogg/Opus and keeps temporary media private outside the web root. The composer permits preview and cancel before sending. Missing media tools leave voice unavailable and do not abort installation.

Read state records the highest displayed message ID per persisted user and conversation. Red unread indicators use that user's read position and do not alter old inbox payloads or customer/POS records.

## Recovery and validation

Backups and cache candidates are kept in owner-only `whatsapp-release-backups/orders-*` directories. File publication requires Linux `renameat2`: new files use no-overwrite publication, existing files use atomic exchange and an interruption-safe journal. Failure restores only this install's unchanged publications. Tables and any data are retained; no migration rollback or table drop runs. Concurrently edited anchors are preserved together with all new dependencies and require a review of the reported backup before retrying. A partial previous install that already contains a feature include also retains dependencies repaired by this run if a later check fails.

The installer fixture suite tests guarded upgrades and repeats, cached/uncached operation, private cache preservation, each targeted migration failure, smoke failure, atomic publication interruption, concurrent UI/route/cache edits, dependency retention, symlinks and source guards. The PHP cache candidate fixture checks that unrelated effective values survive exactly and that an identical repeat retains cache bytes. Application PHP/SQLite and UI fixtures are separate validation; deployment performs PHP lint and isolated extraction fixtures before publishing.
