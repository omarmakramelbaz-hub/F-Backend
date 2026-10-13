# Native Meta messaging for Fasakhansta

The `/admin/meta` inbox uses the same chat, per-user unread markers and order-review interaction as WhatsApp, with All / Messenger / Instagram filters. Central Owner/Admin access is refreshed from the database; branch accounts cannot read private conversations. Replies, orders and receipts are scoped by channel, business account and native customer ID. A phone mentioned in chat is an order contact, never a reason to merge conversation identities.

Signed `page` and `instagram` envelopes arrive at `/api/meta/messaging/webhook`. Only configured accounts are stored. Raw events, customer identities, messages, extracts and reply audits are encrypted. Acknowledgement follows durable persistence. Projection handles inbound messages, native business echoes, standby copies, text, media indicators and native locations. Replays do not duplicate messages. Conflicting capture remains quarantined.

The order service shares the deployed WhatsApp workflow through channel hooks, with independent Meta tables, leases, cutoffs and confirmation keys. The AI extracts evidence and sends no replies. Grounded matching confirmation, complete customer/product/branch/location data, business hours and canonical POS/delivery quotes govern automatic dispatch. Existing branch-customer, unpaid phone-order and kitchen/printing services handle the order. Missing business echoes and ambiguous data remain for review. External Business Agent prompts, schedules and settings are unchanged.

Manual text replies use the account token, native recipient and standard 24-hour response window. Durable UUID claims prevent repeated sends. Uncertain delivery blocks a subsequent send; it never retries automatically. The Meta inbox has its own chat assets, preserving the installed WhatsApp screen. Incoming photos open in a lightbox; audio/video have native playback and documents have download links. Users can copy text, forward an explicitly selected text/media part to another conversation on the same business account, and delete a message from their own dashboard. Per-user tombstones leave original capture, unread chronology and order evidence intact. Manual forwards use the same recipient/window/UUID controls as replies.

Uploads support validated photos, MP4 video, audio and Messenger documents. Voice recordings are inspected and converted to AAC M4A, with a 60-second/8-MB input limit; ffmpeg/ffprobe are required. Native attachment upload is used for Messenger and Instagram with Facebook Login. Instagram Login receives a private prepared file through a signed ten-minute URL. Only validated media is exposed; files live outside the web root and expired staging is purged. Uploads never send messages. A customer-send uncertainty marker is persisted before the send, and uncertain requests are resolved by UUID without an automatic retry. Instagram document uploads are unavailable. File/media type availability is returned to the picker.

## Installation and accounts

Use the pinned root helper. The incremental installer checks installed WhatsApp workflow/UI blobs, backs up replaced code privately and preserves unrelated installed route/menu changes. It migrates only the six Meta migrations, rebuilds existing caches and verifies routes. Unknown blobs fail before writing. Failure restores code/caches without dropping additive tables or deleting captured data. It does not reset a branch or overwrite credentials.

Route verification directly checks all twenty-five Meta routes, controller methods, HTTP methods, actual request matching, API exclusions and dashboard access/session middleware. It avoids Laravel 8's broad `route:list` inspection, which can resolve unrelated controllers before applying its path filter. An actual Meta verification failure still restores code and reports structural diagnostics; raw PHP output remains in a private backup log. After a failed installation, retrying leaves the already applied migrations in place.

The dedicated service runs as `fasakha`; its timer waits 30 seconds after the previous invocation. Each run projects up to 500 events and processes up to ten threads. A worker lock prevents overlap. The WhatsApp timer is untouched. Automatic dispatch remains independently configured.

Configure credentials through hidden SSH terminal prompts:

```bash
cd /home/fasakha/public_html
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php artisan meta:configure
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
```

Enter the App Secret for the app subscribed to both webhook objects. Messenger needs the Facebook Page ID and Page access token. Instagram needs its professional account ID and either an Instagram-login token or its connected Page's Facebook-login token; select the matching login type. The command prints the callback and verify token. Credentials stay in `storage/app/private/meta-messaging.json` with mode 0600 and are never committed or requested in chat.

Register this callback under the app's Page/Messenger and Instagram webhook settings, preserving the WhatsApp callback. Subscribe Messenger messages and message echoes, and Instagram messages. Attach the actual Page/Instagram account to the app; callback registration alone does not attach an account. Complete the required messaging permissions/access for real customers. Instagram with Facebook Login requires a professional account linked to its Page. Instagram Login uses `instagram_business_manage_messages` and does not require a linked Page.

Verify an incoming message and a native business reply in the same thread on each channel before automatic orders. Configure the existing WhatsApp extraction credentials, automation actor and allowed branch IDs first. Establish independent Meta cutoffs:

```bash
systemctl stop fasakhansta-meta-messaging.timer fasakhansta-meta-messaging.service
cd /home/fasakha/public_html
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php artisan meta:configure --activate-orders
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php artisan config:cache
systemctl start fasakhansta-meta-messaging.timer
```

Activation drains a bounded historical capture before recording new cutoffs. Capture failures block activation; delayed historical messages fail the existing event-time guard. Agent reply settings are unchanged.

## Missing incoming messages or customer names

Business echoes alone prove outbound capture, not inbound delivery. The normalizer accepts native incoming messages and standby copies; an echo-first conversation joins the later incoming message by the same channel, account and native peer. Only a genuine recent inbound message opens the reply window. Historical messages from before a working subscription are not fetched by this webhook feature.

Customer display names are enriched separately through the native user-profile GET API. Messenger uses the customer's Page-scoped ID with its Page token; Instagram uses its scoped ID and the configured login/token type. Responses must identify the same native peer. Names and retry metadata remain encrypted, known names are preserved, and failed profile requests are throttled. Profile lookup runs after capture/order work and never supplies order facts or opens a response window.

For an existing installation, use `deployment/apply_meta_chat_root.sh` with the pinned release. The feature updater validates old file hashes, publishes only the listed Meta files, applies the new additive hide migration, rebuilds the existing routes cache when needed and verifies all twenty-five routes. It preserves shared WhatsApp files and the native sidebar. The dedicated Meta timer is restarted if it was active. Code/routes cache are restored after verification failure; additive tables and captured data remain.

The separate profile-only `deployment/update_meta_profiles.py` remains available for a profile-only upgrade. This narrow updater stages and lints three profile-related files, validates the known prior worker blob and unchanged Meta foundations, checks the existing encrypted storage, and keeps a private rollback copy. It does not reinstall the inbox or modify shared WhatsApp code, routes, credentials, caches, subscriptions or automatic-order settings. The original full feature installer also accepts only explicitly pinned previous feature blobs when upgrading.

The `meta:refresh-profiles --limit=10` command fills missing display names without processing orders or sending messages. The regular Meta timer continues filling names for later conversations.

Run `deployment/meta_inbound_diagnostic.php` from the application working directory as its user. It prints raw capture versus normalized incoming/outgoing counts, pending/quarantined events, bounded name-availability counts, timestamps, and read-only app/account subscription checks. No customer names, peer IDs, message contents or tokens are printed. Sampling limits and completeness are explicit; a zero incoming count in an incomplete sample cannot prove that no inbound event has ever arrived. Graph failures are reported as fixed categories rather than raw error bodies. This diagnostic never changes subscriptions or thread control. Use its results before recommending an unavailable `standby` setting or a routing change.

Profile implementation references: [Messenger user profile](https://developers.facebook.com/docs/messenger-platform/identity/user-profile/), [Instagram user profile](https://developers.facebook.com/docs/messenger-platform/instagram/features/user-profile).

## Validation and acceptance

Local PHP/Laravel SQLite fixtures verify signatures, channel/account isolation, encrypted storage, replay prevention, unread state, native reply endpoints, network calls outside transactions, uncertainty, access revocation, voice conversion, upload expiry, media proxy/hide scope, source forwarding and shared transcript integrity. Node fixtures cover rich chat actions, exact media URL validation, destination selection, stale requests, uncertain sends and SPA cleanup. The local preview fixture supports desktop/mobile layout review; a real browser and live Meta assets are required for final visual acceptance. Existing WhatsApp workflow and UI/reply regressions remain required. CI uses PHP 8.2.

Live acceptance requires configuring actual assets/permissions, confirming fresh message/echo capture on both channels and checking that one confirmed order reaches its branch exactly once. Local tests send no live customer messages and do not deploy the server.

References: [Meta Messenger collection](https://www.postman.com/meta/messenger-platform-api/documentation/iyp204x/messenger-platform-api), [Meta Instagram collection](https://www.postman.com/meta/workspace/instagram/documentation/23987686-9386f468-7714-490f-9bfc-9442db5c8f00), [Meta Messenger webhooks](https://developers.facebook.com/docs/messenger-platform/webhooks/).
