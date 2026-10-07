# Manual dashboard notifications — October 6

The dashboard creates a durable campaign from the authorized account type, selected people or zone. The browser submits one UUID; replay with the same payload retrieves the existing campaign, while a changed replay is rejected. Tokens are deduplicated and encrypted at rest. Terminal device tokens are removed from the campaign records after processing. The campaign status never includes credentials, tokens, provider response text or message contents.

The page shows Firebase-accepted, rejected, uncertain, invalid, remaining and in-flight device counts. Firebase acceptance is not proof that the device displayed a notification. Recent campaigns survive reload; progress requests are sender-scoped and require the send permission. GET status never sends anything. A browser POST processes a batch, and `php artisan dashboard-push:dispatch` continues authorized queued campaigns without an open browser.

The root installer installs the new `2026_10_06_160000_create_dashboard_push_campaigns` migration and ensures minute dispatch through the existing application scheduler or a dedicated command cron. A cron that runs only `order-board:advance` does not cover notifications. The installer retains and backs up the existing crontab; it does not enable unrelated schedules.

Each batch claims at most ten devices under a database lock and completes outside the transaction. Live claims prevent duplicate workers. An interrupted/expired claim becomes uncertain and is never automatically resent. Accepted and device-rejected records are terminal. Global authentication/project/permission failures pause the campaign; after correcting settings, the explicit Resume button processes only unattempted devices and definite global refusals. Connection timeouts are never assumed to mean the request failed before acceptance.

## Firebase project and errors

`FCM_PROJECT_ID` can explicitly configure the target project, including cross-project service accounts. Its default preserves `fasakhaninjatest`, the project already used by the dashboard Firebase SDK; no unverified project switch is made. If the configured project is empty, the manual sender falls back to `project_id` in `config('firebase.credentials')`. Match the target to the installed app, not merely to an unrelated service account. The existing project config also serves Firestore, so coordinate a deliberate project change with app/dashboard configuration. Do not print or commit the service-account JSON/private key.

The sending service account needs Firebase Cloud Messaging API authorization on the **target** project. Device tokens must belong to that app/project. `SENDER_ID_MISMATCH` is a device-specific HTTP 403 and no longer stops the whole campaign; `UNREGISTERED` is reported separately. Actual global 401/403 failures pause the campaign. A connection failure for one pooled request no longer erases acknowledgements from other devices. Diagnostic codes are translated without leaking raw provider responses.

The legacy synchronous endpoint remains compatible for small requests; more than 100 stored tokens automatically uses durable dispatch. The new dashboard form always uses durable dispatch. Invalid input/credentials or missing schema are never reported as successful delivery.

## Validation and production limits

Tests use synthetic device tokens and mocked OAuth/FCM. They cover a 4,646-device campaign, request replay, revoked permissions, actor isolation, project selection, mixed connection/provider responses, live/expired claims and safe resumption. Browser checks exercise real local Laravel views/controllers, lost-response recovery, batching, history and a fresh draft. No live user broadcast is performed as part of testing or installation.

Check Google/Firebase setup on the production account. If the campaign pauses, the displayed diagnosis identifies the configuration to correct. Fixing server code cannot enable cloud APIs, grant IAM roles or renew a token in a user's installed app.

References:
- https://firebase.google.com/docs/cloud-messaging/send/v1-api
- https://firebase.google.com/docs/cloud-messaging/error-codes

## October 7 — throughput and audience counts

A durable claim now processes up to 50 device requests concurrently under the existing 24-second sender deadline and two-minute claim lease. Completed outcomes are saved in groups rather than one update per device. An open page starts the next queued batch after 200 ms; when another worker holds the claim it waits 1.5 seconds. No accepted, device-rejected or uncertain attempt is retried, and an existing campaign retains exactly its saved recipients. Actual throughput still depends on Firebase and server connectivity; the change does not promise five-times faster end-to-end delivery.

The authorized audience preview counts all accounts matching the current type, areas or selected users, including accounts without usable token records. It separately reports users with at least one syntactically valid token, users without one, unique token/device targets, and invalid token records. Multiple devices do not inflate the user count; shared tokens are sent once. These are registrations, not proof of delivery or currently valid Firebase tokens. The endpoint never sends and returns no tokens. Recipient/account-family authorization is unchanged. Legacy `users.fcm_id`/`device_token` fields are not silently reintroduced into delivery, including tokens removed at logout.

New campaigns store these audience counts at creation in the nullable `audience` field added by `2026_10_07_170000_add_audience_to_dashboard_push_campaigns`. Request replay retains the original snapshot and device set even if users register more devices later. Existing campaigns have no historical user snapshot and explicitly show that their progress counts devices only. They continue without rebuilding the campaign. The pinned installer includes the additive migration and runtime check.

The submit button now settles to “Notification saved” instead of retaining the generic dashboard spinner throughout campaign processing. The progress area continues to show provider acceptance/failures separately. To inspect a live total of 11,050 accounts, use the audience preview for the corresponding account type and selection after deployment; screenshots of device counters cannot establish how many distinct users lack tokens.
