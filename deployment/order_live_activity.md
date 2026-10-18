# Customer order tracking and iOS Live Activity

The screenshot shows one order using two different presentation identifiers: the
Live Activity displays database ID `7375`, while the customer notification displays
public order number `R363-16897`. The public number is the customer-facing identity;
the database ID remains the lookup and authorization key.

The retained backend emitted ordinary FCM notifications and Pusher messages. The
customer application never registered its per-activity Apple push token, and the
widget read its mutable state from shared defaults. An ordinary notification cannot
reliably update that widget after the application is terminated.

This release gives all legacy restaurant order transitions one tracking state,
adds missing customer broadcasts on dispatch and completion, and isolates tracking
from unrelated notification failures. The accompanying F-customer source change
uses the public order number, registers the Apple activity token for its owner and
order, and renders push content directly in the widget.
The existing Laravel scheduler retries failed or missed updates every minute with
bounded batches and backoff. Activity registrations expire after eight hours, and
an acknowledged terminal update removes the stored push-token values.

## Deployment

Use the pinned `codex/order-live-activity-sync-20261018` release and its
`deployment/apply_order_live_activity_root.sh` entry point. Run `--check` first.
The installer applies only the reviewed PHP hunks and new dependency files,
preserves independent live changes, saves a private source backup and receipt,
and applies only `2026_10_18_200000_create_order_live_activities_table.php`.
It clears the route cache so the authenticated registration routes become visible.

Deploying the backend does not update the installed iPhone application. Build and
install the accompanying customer app release before testing terminated-app remote
updates. Existing activities need to be recreated by the updated app so that their
tokens and content schema match. Do not replace production with a whole branch
checkout or run unrelated migrations.

## Device verification

1. Place a new order in the updated app. Compare the number on tracking, the Live
   Activity, the customer notification and the dashboard.
2. With the app open, move the same order through preparation, dispatch and delivery.
   Confirm that tracking and the Live Activity show the corresponding phase.
3. Repeat with the phone locked and the app terminated. Confirm dispatch updates
   the widget and completion ends it. Repeat cancellation.
4. Check that a second customer's activity remains unchanged and a customer's
   second order is not overwritten by the first order's update.

FCM acceptance is evidence that the provider accepted the message, not evidence
that a physical iPhone displayed it. A signed device check remains necessary for
APNs entitlements, notification settings and the final installed build.
