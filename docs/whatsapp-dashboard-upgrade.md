# WhatsApp dashboard upgrade

The upgrade publishes a fixed set of 17 dashboard files. It adds conversation pagination and updates, unread conversation counts, a compact text/voice/attachment composer, protected image previews and document downloads, native quotes, forwarding and reactions, and per-operator dashboard hiding. “Select all” uses a server snapshot across the full saved history, including accepted API replies beyond the currently displayed page.

The paperclip selects one JPG/PNG image (application limit 5 MiB) or PDF/UTF-8 TXT document (application limit 8 MiB). Effective upload limits also respect the serving PHP runtime. Selection shows a compact preview, sanitized filename and size; it never sends a message. An optional caption is limited to 1,024 characters. Enter sends the selected attachment, while Shift+Enter adds a line. Switching conversations, losing access, cancelling or sending clears the temporary selection. Text, voice and attachments cannot send concurrently.

Attachment sends recheck the recipient, authorization, recent inbound window and quoted source before upload and again before messaging. A durable request claim prevents duplicate sends; an uncertain upload or send remains UNKNOWN without an automatic retry. The private temporary file is removed after the operation. Protected downloads validate the saved receipt, conversation, media digest and type; documents are downloaded as attachments rather than executed in the dashboard. Native business echoes retain the accepted API reply's protected media bridge.

Hiding affects the operator’s dashboard only. Raw webhook records, encrypted messages, reply audit records, customer records and ERP orders are retained. Native actions require the existing central Owner/Admin authorization, authenticated sessions, CSRF checks, the configured WhatsApp phone and a scoped source/target. Uncertain sends retain the existing durable UNKNOWN state rather than sending again.

## Deployment

Use the reviewed `deployment/apply_whatsapp_dashboard_upgrade_root.sh` from the pinned release. Run the root-owned, private helper with:

```sh
bash /absolute/private/path/apply_whatsapp_dashboard_upgrade_root.sh --release PINNED_COMMIT
```

The root loader reads only pinned Git blobs as `fasakha`, verifies the exact helper/manifest/runtime hashes, stages root-owned directories at 0755 and files at 0644, then runs the publisher as `fasakha`. It never checks out a branch or runs Composer. The publisher refuses unknown live file changes with a named path before bootstrapping the application.

The reviewed production inbox CSS preimage is `d4e1f32b816da1219b32de856ea484f07cecd1fa`: the original 8,036-byte stylesheet plus the exact 261-byte Meta channel controls addition reported by the read-only diagnostic. That addition is retained unchanged at the end of the new stylesheet. This is an exact, reviewed preimage; other live edits still stop publication before PHP or database work.

Publication is dependency-first and uses atomic exchanges. Known hardlinked files are supported: immutable, separate copies preserve their original bytes, while publication replaces only the selected path. The two templates publish last. Each candidate PHP file is linted with the server PHP 8.2 binary; both templates are compiled privately and linted. Node syntax checks run when Node is available. Compiled production views expire through the changed source timestamps; no global cache clear is used.

The `.env`, effective configuration cache, installed Cairo/native/order code, other dashboard dependencies and actual live footer `886f076b7d57dc4d71fd24e63c27bd9fcf34f11e` retain their bytes and metadata. Capture and order services/timers are not controlled by this installer. Installing the dashboard does not activate automatic orders or alter confirmation floors, branch routing, prices or delivery settings.

## Metadata and route cache

The single new migration creates only additive dashboard metadata tables: per-operator hide markers, accepted-reply projection keys and accepted-reaction targets. Exact columns, uniqueness constraints and foreign keys are checked. Existing migration records must remain unchanged. Private `database-before.json` and `database-after.json` receipts record schema and migration metadata; they contain no message text or token.

The bounded, idempotent metadata backfill validates legacy accepted receipts in chunks before the new interface is published, and runs again at postflight. It reports numeric valid/invalid counts. Invalid receipts remain distinct accepted history items without guessed native identity. Original receipt/content rows are not changed.

An existing canonical routes cache is privately backed up and atomically moved out so the new endpoints load. Only `bootstrap/cache/routes-v7.php` or `routes.php` is eligible; the configuration cache remains intact. An initially uncached installation remains uncached. The installer neither rebuilds all caches nor clears unrelated bootstrap files.

## Failure and rollback

The publisher prints a private `BACKUP=/home/fasakha/whatsapp-release-backups/dashboard-ui-…` path. If publication or postflight fails, it restores its own known file versions in reverse dependency order and restores an unchanged displaced route cache. A competing public edit or a peer-created route cache stops restoration and retains the complete dependency set needed by that entrypoint. It never overwrites a competing edit.

New metadata tables and their rows are retained on code rollback, including per-operator hide markers and derived accepted-reply/reaction records. Do not run the migration’s `down()` or a broad `migrate:rollback` to reverse this UI release. A later database rollback requires a separate data review and backup; deleting these nonempty tables would lose dashboard state. No automatic schema deletion or historical message deletion is performed.

The compatible Cairo activation helper still verifies the original pinned legacy wrapper, changes only its five known dashboard source pins in place, and verifies all 17 installed dashboard target hashes. Its original seven Cairo/runtime pins, synthetic AI preflight and confirmation cutoffs remain enforced. Native readiness accepts a verified outbound `standby` or `smb_message_echoes` event with prior customer input in the same conversation. It validates processed raw events, payload hashes, canonical projections, native message keys and explicit peer bindings; the source alone does not identify the author as the Agent or prove a final order. Fresh customer commitment and a final business summary after activation remain required for dispatch. Cairo delivery uses the virtual store as the billing origin and physical Shubra branch 301 for fulfillment.

## Validation

Run the publisher transaction tests, root-loader tests and activation compatibility tests. Backend tests use real Laravel 8.83.29, SQLite, encryption and HTTP fakes to exercise the migration, scoped actions, media checks, CSRF/roles, full-history selection, late acceptance and reaction persistence. Frontend suites cover both the existing DOM and redesigned hooks. Actual Blade compilation and PHP lint are included. Browser visual inspection is reported separately; a generated static fixture does not establish browser QA.
