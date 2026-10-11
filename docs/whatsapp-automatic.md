# Automatic WhatsApp delivery orders

Incoming WhatsApp carts and final confirmations are analyzed by a dedicated server timer, without opening the dashboard. Only a grounded, complete delivery order is dispatched. Missing agent messages, ambiguous products or locations, a pending team verification, and changed confirmations remain visible for review.

## Runtime

The oneshot service runs `whatsapp:process-orders --limit=10` as `fasakha` with the fixed cPanel PHP 8.2 executable. Each invocation projects at most 500 raw events, preserves quarantine, then analyzes up to ten settled conversations. The timer waits five seconds after the previous invocation finishes; a shared runtime lock prevents overlap. The ten-second debounce avoids analysis while messages are still arriving. The dashboard polls the resulting state; browser code never triggers automatic AI or dispatch.

A cached draft can be rechecked against changed branch hours, catalog or ERP availability without another AI request. A transient dispatch failure retries after a bounded delay. Conversation leases, immutable dispatched receipts, confirmation keys and POS command idempotency prevent duplicate tickets.

## Carts and confirmations

Standard Meta cart messages contain catalog IDs, retailer IDs, counts and quoted prices, but no product titles. The inbox now displays all cart lines and the exact quoted subtotal from existing encrypted records. Verified public catalog titles can be configured for presentation. Retailer IDs are never treated as ERP product IDs.

Explicit mappings bind each catalog/retailer/branch combination to a live ERP product, its sale unit, quantity per catalog unit and optional variant. A quarter-kilo catalog item with count two requires exactly half a kilo of the mapped ERP product. The latest cart must be complete; partial carts, missing lines, substituted products and unconfirmed variants cannot dispatch. The final business summary must support the selected product, quantity and variant. A message promising later team verification is a handoff and does not establish a confirmed order, even below an “Order confirmation” heading.

The branch catalog and canonical POS quote supply the actual unpaid order prices. A WhatsApp estimate is displayed as an estimate and never overwrites ERP pricing. Both order and delivery quote hashes are checked again inside dispatch before customer or ticket changes.

## Existing delivery and customer services

`PosServicePhone::customers` performs the same exact-phone lookup used by phone delivery orders. `BranchCustomers::save` creates or updates the branch customer with the same normalized phone, revision checks, name, address, notes and coordinates. The order uses `PosServiceTicket::save('phone', ...)` and the existing kitchen/branch printing path.

`PhoneDelivery::settings` and `PhoneDelivery::quote` supply the existing branch location, road distance when available, fallback distance, kilometer rate and rounded delivery fee. No separate WhatsApp fee calculation is introduced. A native customer location can be used automatically. An unchanged exact phone/address can reuse a verified current branch customer pin or immutable phone-order history pin; conflicting or unproven pins remain for review. A neighborhood name or first geocoder result is not an exact customer location.

The review screen uses the existing location picker, saved addresses, Photon suggestions or the configured maps fallback, route preview and canonical delivery quote. Changing branch, customer identity, address or pin invalidates confirmation and the quote. Fresh persisted central access is checked on every endpoint.

## Incremental deployment and activation

For the installed feature release plus historical workflow guard, stage and verify `install_whatsapp_inbox.py` and `install_whatsapp_automatic.py`, then run the latter as `fasakha` with `--root /home/fasakha/public_html --release FULL_40_CHARACTER_RELEASE_SHA`. It accepts only the known deployed blobs or the exact target release and changes a fixed WhatsApp manifest. Shared POS, map assets, admin routes/sidebar, credentials and effective cached settings are preflighted and preserved. An obsolete compiled route cache is moved to a private backup only when needed for the new map endpoints; a fresh bootstrap verifies their central middleware. Rollback never overwrites competing changes.

Run the separately verified root helper `install_whatsapp_automatic_root.sh --install` to install inactive dedicated systemd units. It never enables the timer during installation.

While catalog mappings are being prepared, `install_whatsapp_capture_root.sh` maintains a separate projection-only inbox timer. It runs the pinned `whatsapp:consume-inbox --limit=500` command as `fasakha`, preserves quarantined events and uses the same pipeline lock as the order service. It makes no AI calls, sends no messages and creates no orders. Capture runs throughout the day, independently of the Business Agent reply schedule. Stop this capture timer before activating the full order pipeline.

Before activation, run `whatsapp_capture_gap_diagnostic.php` to inspect whether agent replies were captured, normalized and projected in the correct native conversation. It outputs temporary identity labels and fixed statuses, without customer text or real phone/user/message IDs. Names and callback numbers must never be used to merge conversations. If Meta did not send an agent reply, the application cannot reconstruct it.

The capture diagnostic defaults to the latest 30 events, described newest first. `--limit=1..50`, `--after-event=ID` and `--through-event=ID` select a bounded, frozen raw-event ID range. Wider historical coverage and selected-range completeness are reported separately, along with the next older page boundary. Projection lookups remain live observations. An incomplete range cannot prove that an agent reply was absent. Output size limits retain whole newest events and explicitly count omissions instead of discarding every event.

The Meta Business Agent replies from 01:00 to 11:00 in `Africa/Cairo`. Stage the pinned capture diagnostic beside `whatsapp_agent_window_diagnostic.php` to inspect that stored morning window. `--date=YYYY-MM-DD` selects a Cairo calendar day; the default is the current Cairo day. The bounded report prioritizes native echoes and carts and states capture coverage, detail omissions and live projection observations. This schedule does not restrict the server's AI processing; existing ERP branch opening hours still govern dispatch.

Use `whatsapp_catalog_mapping_readiness.php --catalog=OBSERVED_CATALOG_ID --branch=363` for a bounded read-only catalog/ERP comparison. It uses the stored WhatsApp token without displaying it. Catalog access may require separate asset permissions. Candidate mappings are reported but never applied automatically.

If catalog 627343999792522 returns Graph code 100, use `whatsapp_catalog_access_diagnostic.php` for compact read-only object, minimal-edge and product-field probes. It reports fixed error classifications and token scope checks without raw API errors, tokens or customer data. Code 100 alone does not distinguish inaccessible assets from unsupported requests; successful reads and explicit permission evidence are needed before changing the configuration.

For the main four branches, stage the pinned catalog helper beside `whatsapp_cart_setup_readiness.php`. The setup helper discovers public catalog/retailer identities from scoped stored carts and recent raw capture, fetches each observed catalog once, and reports public ERP identities and exact candidates for branches 94, 301, 307 and 363 using fresh persisted actor access. It discloses no customer fields or quoted cart prices and creates no orders or mappings. Both the local discovery and public product lists are bounded; omission and incomplete-window flags make an empty result inconclusive. Optional `--after-event=ID` and `--through-event=ID` allow older raw-event pages to be inspected without including later arrivals. Public candidates still require explicit verified mappings before automatic activation.

The activation helper takes a persisted actor, explicit accessible branch IDs and an optional verified public mapping JSON file. Run the projection-only capture helper with `--stop`, then the dedicated order root helper with `--stop`, before activation to quiesce both timers and workers. The activation helper requires the new runtime guards, drains historical runnable capture without analyzing it, and establishes raw-event/message cutoffs plus a UTC activation timestamp under raw-event locks. It preserves quarantine and existing credentials/model/reply settings. The consumer rechecks quarantine after acquiring the event lock, preventing a preselected browser batch from bypassing the activation boundary. Automatic commitment and business-confirmation timestamps must be after activation even if a delayed historical webhook receives a newer database ID. Manual historical review remains possible.

Only after fresh effective automatic readiness passes does `install_whatsapp_automatic_root.sh --start` enable the timer. `--stop` stops only these dedicated units. Activation is separate from installing the reviewed code so that catalog mapping and native conversation capture can be verified before processing new confirmed orders.

## Validation and remaining acceptance

PHP/SQLite fixtures cover grounded extraction, complete cart mapping, canonical pricing, customer revisions, trustworthy pins, activation boundaries, background retries and idempotency. UI fixtures exercise cart escaping and map state invalidation. Python fixtures cover pinned publication, cached and uncached configuration, private backups, guarded rollback, concurrent edits, historical drain and dedicated timer lifecycle.

Actual browser microphone/map use, live Meta agent reply linkage, catalog permissions/mappings and one fresh confirmed order dispatched exactly once require live acceptance. Synthetic AI connectivity proves the model/API schema only. Existing red per-user unread badges and central text/voice reply behavior remain intact.
