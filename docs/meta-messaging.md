# Native Meta messaging for Fasakhansta

The `/admin/meta` inbox uses the same chat, per-user unread markers and order-review interaction as WhatsApp, with All / Messenger / Instagram filters. Central Owner/Admin access is refreshed from the database; branch accounts cannot read private conversations. Replies, orders and receipts are scoped by channel, business account and native customer ID. A phone mentioned in chat is an order contact, never a reason to merge conversation identities.

Signed `page` and `instagram` envelopes arrive at `/api/meta/messaging/webhook`. Only configured accounts are stored. Raw events, customer identities, messages, extracts and reply audits are encrypted. Acknowledgement follows durable persistence. Projection handles inbound messages, native business echoes, standby copies, text, media indicators and native locations. Replays do not duplicate messages. Conflicting capture remains quarantined.

The order service shares the deployed WhatsApp workflow through channel hooks, with independent Meta tables, leases, cutoffs and confirmation keys. The AI extracts evidence and sends no replies. Grounded matching confirmation, complete customer/product/branch/location data, business hours and canonical POS/delivery quotes govern automatic dispatch. Existing branch-customer, unpaid phone-order and kitchen/printing services handle the order. Missing business echoes and ambiguous data remain for review. External Business Agent prompts, schedules and settings are unchanged.

Manual text replies use the account token, native recipient and standard 24-hour response window. Durable UUID claims prevent repeated sends. Uncertain delivery blocks a subsequent send; it never retries automatically. Incoming voice/media appear with their media type. Outgoing voice recording is not enabled for these two channels in this release; WhatsApp voice behavior remains available.

## Installation and accounts

Use the pinned root helper. The incremental installer checks installed WhatsApp workflow/UI blobs, backs up replaced code privately and preserves unrelated installed route/menu changes. It migrates only the five Meta migrations, rebuilds existing caches and verifies routes. Unknown blobs fail before writing. Failure restores code/caches without dropping additive tables or deleting captured data. It does not reset a branch or overwrite credentials.

Route verification directly checks all twenty Meta routes, controller methods, HTTP methods, actual request matching, API exclusions and dashboard access/session middleware. It avoids Laravel 8's broad `route:list` inspection, which can resolve unrelated controllers before applying its path filter. An actual Meta verification failure still restores code and reports structural diagnostics; raw PHP output remains in a private backup log. After a failed installation, retrying leaves the already applied migrations in place.

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

## Validation and acceptance

Local PHP/Laravel SQLite fixtures verify signatures, channel/account isolation, encrypted storage, replay prevention, unread state, native reply endpoints, network calls outside transactions, uncertainty, access revocation, native voice/location normalization and shared transcript integrity. Existing WhatsApp workflow and UI/reply regressions remain required. CI uses PHP 8.2.

Live acceptance requires configuring actual assets/permissions, confirming fresh message/echo capture on both channels and checking that one confirmed order reaches its branch exactly once. Local tests send no live customer messages and do not deploy the server.

References: [Meta Messenger collection](https://www.postman.com/meta/messenger-platform-api/documentation/iyp204x/messenger-platform-api), [Meta Instagram collection](https://www.postman.com/meta/workspace/instagram/documentation/23987686-9386f468-7714-490f-9bfc-9442db5c8f00), [Meta Messenger webhooks](https://developers.facebook.com/docs/messenger-platform/webhooks/).
