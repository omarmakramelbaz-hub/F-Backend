# WhatsApp webhook foundation

This adds `GET` and `POST /api/whatsapp/webhook`. GET answers Meta's verification challenge. POST authenticates the exact raw body with HMAC-SHA256 using the Meta App Secret, filters entries by configured WABA IDs, and stores an encrypted event before acknowledging delivery. Persistence failures return 503 so Meta can retry. Identical scoped payloads have a unique SHA256 key, including concurrent retries.

Messages and status notifications are retained in `whatsapp_webhook_events`. Payloads use Laravel's existing `APP_KEY`; keep that key to retain decryption access. There is no inbox UI, event consumer, outbound response, or automatic customer/order creation in this release. Those require a later dashboard integration. Default API rate limiting remains in effect; review it before production traffic. Retention/processing must be implemented with the consumer.

## Additive installation on the current server

The server checkout is ahead of/different from GitHub main. Do not replace it with this branch or reset it. Fetch this branch and run the installer from its pinned commit as `fasakha`. It adds only the listed files and a route loader, backs up `.env` and `routes/api.php` outside `public_html`, and runs only the WhatsApp migration. It stops if any destination file differs. On failure it restores configuration/routes and removes newly added files; an already-created event table is retained to avoid data loss. No existing migration, table, or application checkout is rolled back.

The hidden prompt accepts **Meta App Secret**, found in the app's **App settings > Basic > App secret > Show**. This is different from both the generated access token and the verification token. Credentials must remain in the server environment, outside Git and chat. Access tokens are unnecessary for receiving webhooks; sending messages later needs a separate server-side token.

The installer generates `WHATSAPP_VERIFY_TOKEN` and sets `WHATSAPP_ALLOWED_ACCOUNT_IDS=1636131124838697` only if unset. This ID is the observed Meta **test** WABA, not proof that the real business number is onboarded. Add/replace the real WABA ID after confirming production setup. `WHATSAPP_APP_SECRET` is saved from the hidden prompt; `APP_URL` must already be the correct public HTTPS URL.

Success prints `INSTALLATION_OK`, `CALLBACK_URL` and `VERIFY_TOKEN`. Copy the latter two directly to Meta's Configure Webhooks form, press Verify and save, then subscribe to the `messages` field. Do not share the verification token in screenshots. For production, follow the app publication requirement shown in Meta and confirm registration/coexistence for the existing business number before changing it.

## Validation

CI runs PHP 8.2 syntax checks, shell syntax checks, and standalone protocol checks for the challenge, exact-body signature, malformed input, payload size, Arabic text, JSON object preservation and WABA isolation. The server installer additionally exercises the actual Laravel HTTP kernel for a valid handshake, a wrong token, and an unsigned POST, and checks that the event table exists. These checks do not send WhatsApp messages or create fake events/orders/customers. Verify an actual signed test delivery from Meta after installing; inspect event counts and keep message contents/keys private.
