# Automatic WhatsApp delivery orders

After explicit activation, incoming WhatsApp carts and final confirmations are analyzed by a dedicated server timer, without opening the dashboard. Only a grounded, complete delivery order is dispatched. Installation initially keeps projection-only capture running and automatic order processing disabled. Missing agent messages, ambiguous products or locations, a pending team verification, and changed confirmations remain visible for review.

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

## Cairo store origins and Shubra fulfillment

`FasakhanstaDeliveryOrigin::CAIRO_ORIGINS` is an explicit registry of the 27 current Cairo retail stores: 309–313, 336–350 and 354–360. Each registered ID must still match its reviewed live public name. Wholesale store 308 is excluded. Physical branches 94, 301, 307 and 363 remain their own fulfillment destinations even if a later ownership hierarchy links one to Shubra. An unknown future child of 301 is not automatically classified as a Cairo origin.

For a registered Cairo store, `delivery_origin_branch` identifies the store whose saved pin and kilometer rate calculate the store-to-customer route and delivery charge. Fulfillment remains `f:301`: Shubra owns the customer record, product catalog, canonical item pricing, unpaid POS ticket, kitchen receipt and branch print job. The origin and fulfillment identities are stored separately in the canonical delivery snapshot and sealed review. The phone-order map uses the store origin while the ticket remains visible to Shubra staff.

The inspected inventory has null `parent_id` values. This policy does not write ownership links. A registered origin permits a live null, zero or 301 parent; another parent, changed name, invalid pin/rate, closed fulfillment branch or changed quote blocks automatic dispatch. Storefront visibility and open/closed status do not gate a registered virtual store used only as a delivery billing origin, and routing does not change those flags. Shubra's existing access, opening hours and effective availability still govern automatic fulfillment. Exact public store names, their approved trailing `ستور`/`استور`/`store` suffix removal, and configured whole-label aliases can resolve an origin. Conflicting or duplicate labels remain for review; addresses are not searched for a nearest store or an inferred geographic match.

Omitting an origin from a legacy form retains the trusted sealed origin when fulfillment is unchanged. Changing or removing it cannot reuse an existing dispatch confirmation; a new canonical quote and dispatch confirmation are required. Existing ticket corrections retain their saved origin, and dispatch rechecks current origin identity, rate and quote hashes before any ERP write.

## Native Standby text capture

The parser accepts the canonical text echo at `field=standby`, inside `value.standby.message_echoes[]`, with outer `id`/`timestamp` and the inner Send Message API `message`. It preserves native scope, ID, sent time, recipient and literal text. A sender is not manufactured, and Standby provenance does not identify the author as AI. Conflicting identities or envelopes, unsupported templates/media/interactive shapes and contradictory copies remain quarantined. Strict body comparisons also retain a conflict when copies differ in fields such as `preview_url`.

The rollout preserves existing quarantines and supports future normal capture; it does not backfill missing Agent replies or replay historical orders. A phone-only echo cannot be joined to a user-ID conversation without explicit identity evidence. Dashboard API reply audit bubbles are separate display evidence and do not become native AI transcript messages.

## Incremental deployment and activation

The initial inbox/order installers established the existing feature baseline. The Cairo extension uses the pinned `apply_whatsapp_cairo_origins.py` manifest through `enable_whatsapp_cairo_automatic_root.sh`. It changes only the reviewed parser, origin service, delivery/POS support, phone controller/UI and workflow files. Credentials, effective configuration, activation boundaries, shared map provider/assets, access services, customer service and unrelated dashboard files are guarded. Rollback never overwrites competing changes. No schema migration or ownership rewrite is required.

The coordinated wrapper uses the verified `install_whatsapp_automatic_root.sh --install` to install or validate inactive dedicated systemd units. That unit-install action does not enable the timer.

While capture and mappings are being verified, the installed projection-only inbox timer runs the pinned `whatsapp:consume-inbox --limit=500` command as `fasakha`, preserves quarantined events and uses the same pipeline lock as the order service. It makes no AI calls, sends no messages and creates no orders. Capture runs throughout the day, independently of the configured Business Agent reply schedule. The coordinated helper pauses it during publication and restores capture before returning from installation; do not disable it separately.

Before activation, run `whatsapp_capture_gap_diagnostic.php` to inspect whether agent replies were captured, normalized and projected in the correct native conversation. It outputs temporary identity labels and fixed statuses, without customer text or real phone/user/message IDs. Names and callback numbers must never be used to merge conversations. If Meta did not send an agent reply, the application cannot reconstruct it.

The capture diagnostic defaults to the latest 30 events, described newest first. `--limit=1..50`, `--after-event=ID` and `--through-event=ID` select a bounded, frozen raw-event ID range. Wider historical coverage and selected-range completeness are reported separately, along with the next older page boundary. Projection lookups remain live observations. An incomplete range cannot prove that an agent reply was absent. Output size limits retain whole newest events and explicitly count omissions instead of discarding every event.

The configured Meta Business Agent reply window is 01:00 to 11:00 in `Africa/Cairo`. Stage the pinned capture diagnostic beside `whatsapp_agent_window_diagnostic.php` to inspect that stored morning window. `--date=YYYY-MM-DD` selects a Cairo calendar day; the default is the current Cairo day. The bounded report prioritizes native echoes and carts and states capture coverage, detail omissions and live projection observations. This schedule does not restrict the server's AI processing; existing ERP branch opening hours still govern dispatch.

Use `whatsapp_catalog_mapping_readiness.php --catalog=OBSERVED_CATALOG_ID --branch=363` for a bounded read-only catalog/ERP comparison. It uses the stored WhatsApp token without displaying it. Catalog access may require separate asset permissions. Candidate mappings are reported but never applied automatically.

If catalog 627343999792522 returns Graph code 100, use `whatsapp_catalog_access_diagnostic.php` for compact read-only object, minimal-edge and product-field probes. It reports fixed error classifications and token scope checks without raw API errors, tokens or customer data. Code 100 alone does not distinguish inaccessible assets from unsupported requests; successful reads and explicit permission evidence are needed before changing the configuration.

For a new same-app token with catalog and WhatsApp permissions, stage the pinned `rotate_whatsapp_catalog_token.py`, its pinned base helper and the catalog access diagnostic. The rotation prompts only for the new token through a hidden terminal. Before publication it verifies the app, token validity, required permissions, successful catalog product reads and read access to the configured WhatsApp phone. Catalog association errors and the optional business portfolio scope do not block otherwise successful product reads. It changes only the reply access token while preserving the model, AI key, order configuration, mappings, activation boundaries and unrelated settings. Rotation requires the existing review mode and retains private backups with guarded rollback.

For the main four branches, stage the pinned catalog helper beside `whatsapp_cart_setup_readiness.php`. The setup helper discovers public catalog/retailer identities from scoped stored carts and recent raw capture, fetches each observed catalog once, and reports public ERP identities and exact candidates for branches 94, 301, 307 and 363 using fresh persisted actor access. It discloses no customer fields or quoted cart prices and creates no orders or mappings. Both the local discovery and public product lists are bounded; omission and incomplete-window flags make an empty result inconclusive. Optional `--after-event=ID` and `--through-event=ID` allow older raw-event pages to be inspected without including later arrivals. Public candidates still require explicit verified mappings before automatic activation.

For the current installation, first stage verified root-owned helpers and run as root:

```sh
bash /ROOT_STAGED/enable_whatsapp_cairo_automatic_root.sh \
  --install-only --release FULL_40_CHARACTER_RELEASE_SHA \
  --legacy-helper /ROOT_STAGED/enable_whatsapp_automatic_root.sh
```

The supplied legacy helper must be the root-owned pinned blob `d30c13bd13ca73e5aad54f558a259e18fb95147f`; it supplies reviewed process-group and unit-control functions, not a separate activation command. The new wrapper verifies the release payload, source profiles, service units and private configuration metadata. Helpers stay in a root-owned directory under sticky `/tmp`; app-user code never becomes a root-executed deployment helper.

`--install-only` publishes the verified runtime extension, preserves the current review mode and all cutoffs/configuration, disables dedicated order workers and leaves capture enabled and active. It performs no AI connectivity call, message send or order creation. On an already validated automatic installation it preserves configuration while pausing automation and restoring projection-only capture.

The latest reviewed subscription diagnostic reports `READY=true`. Business Suite Standby visibility and its terms remain unverified, and zero raw Standby events were found in the inspected current window. Subscription readiness and historical `smb_message_echoes` counts do not prove that Business Agent replies reach the native transcript. Keep the installation in review/capture mode until that evidence is available.

In the verified Meta routing interface, open **Manage Conversation Routing → Conversation visibility**, choose **All conversations**, select app `2177982783065133` and save. Check any account/phone-specific override and the applicable terms separately. The app's Standby subscription is already ready; another subscription POST is not required. The existence of these controls does not establish that the current visibility setting or terms have been completed.

Enable with the same pinned release and root-owned legacy helper only after a recent native Standby text reply is present in raw capture and explicitly bound to its projected customer conversation:

```sh
bash /ROOT_STAGED/enable_whatsapp_cairo_automatic_root.sh \
  --enable --release FULL_40_CHARACTER_RELEASE_SHA \
  --legacy-helper /ROOT_STAGED/enable_whatsapp_automatic_root.sh
```

The helper checks a bounded recent two-hour window for an unquarantined processed raw reply, matching native text/identity projection and preceding inbound text in that same conversation. It requires explicit phone/user identity binding. Missing proof leaves the prior pipeline running. It then makes one fixed synthetic greeting extraction through the saved AI provider; a failed model, API or response-schema check leaves the prior pipeline and configuration unchanged. This connectivity test cannot authorize a customer order.

After the AI check passes, the entry point pauses capture without disabling its boot state and stops dedicated order workers. When enabling the current review installation, it configures persisted actor 1 and main branches 94, 301, 307 and 363. The activation helper drains historical runnable capture without analyzing it, establishes raw-event/message cutoffs plus the next whole UTC second as the activation boundary under raw-event locks, and retains quarantined events. It preserves the existing model, AI key, WhatsApp reply token and all unrelated effective configuration. Only the top-level cached order/cart namespaces change; class constants, static expressions and serialized objects elsewhere keep their original source bytes.

The consumer rechecks quarantine after acquiring the event lock, preventing a preselected browser batch from bypassing the activation boundary. Automatic commitment and business-confirmation timestamps must be after activation even if a delayed historical webhook receives a newer database ID. Manual historical review remains possible. Empty catalog mappings are permitted for text orders; catalog carts with unmapped retailer IDs stay for review.

The entry point starts the dedicated order timer only after native projection proof is rechecked and effective automatic readiness passes, and disables the superseded capture timer only after the order timer is active. An existing validated automatic configuration retains its original cutoffs. If publication, activation or startup fails after the pause, it disables order workers, validates the unchanged units and source files, and restores projection-only capture. A verified published extension is retained. If automatic configuration was already published, it remains with order workers stopped; capture resumes without dispatching orders. Private application/configuration backups remain available for diagnosis. Source or unit drift stops the operation rather than replacing unrelated live application files.

## Validation and remaining acceptance

PHP/SQLite fixtures cover grounded extraction, complete cart mapping, canonical pricing, customer revisions, trustworthy pins, activation boundaries, background retries and idempotency. UI fixtures exercise cart escaping and map state invalidation. Python fixtures cover pinned publication, cached and uncached configuration, private backups, guarded rollback, concurrent edits, historical drain and dedicated timer lifecycle.

The frozen extension passed 22 publisher tests, 36 root-wrapper tests, 541 workflow checks, 212 backend delivery/POS/printing checks, 55 phone-order UI checks, 65 Standby parser checks and nine Standby projection/replay checks. Browser launch was blocked by environment `EPERM`; the UI fixtures do not establish live browser/map acceptance.

The focused POS fixtures use verified copies of the existing access, money and live printing services in `test-fixtures/cairo-pos-core`. From the repository root, run them with:

```sh
WA_POS_SOURCE_ROOT="$PWD/test-fixtures/cairo-pos-core" \
WA_POS_PRINTING_SOURCE="$PWD/test-fixtures/cairo-pos-core/app/Services/Dashboard/PosBranchPrinting.php" \
php tests/whatsapp_cairo_delivery_origin_test.php
```

Set `WA_INBOX_TEST_VENDOR` when the Laravel test dependencies are installed outside the repository's `vendor` directory.

After enabling, one fresh complete delivery order must still prove live AI extraction, a genuine customer commitment and matching native business confirmation, scoped customer creation, dispatch exactly once to the correct fulfillment branch, the selected store-to-customer delivery origin and the branch print queue. Historical messages, a synthetic customer-only confirmation or an unrelated generic acceptance cannot establish this result. Avoid a `WA-####` test marker in this acceptance order because marked transcripts are classified as tests. A pending print job does not prove that a physical printer completed it. Catalog permissions and mappings remain a separate acceptance item for cart orders. Synthetic AI connectivity proves the model/API schema only. Existing red per-user unread badges and central text/voice reply behavior remain intact.
