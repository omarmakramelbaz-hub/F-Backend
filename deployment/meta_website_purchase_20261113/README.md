# Website checkout Purchase tracking

Production checkout creates a `PosServiceTicket` and a `web_storefront_orders` receipt, while the existing Meta observer watches a different `Order` model. This release adds a separate website Purchase path for newly placed cash-on-delivery orders. It does not report payment collection or fulfillment.

The checkout hook runs after a successful transaction and ignores receipt replays. The sender verifies the receipt and ticket, reads the persisted total, hashes the normalized purchaser phone, captures the purchaser's browser user agent and any valid existing Meta attribution cookies, and queues an encrypted event. Browser opt-out headers are respected. It never sends the delivery address, map location, quote token, or session identifier.

The event uses dataset configuration already in the application, `action_source=website`, `event_source_url=https://fasakhaninja.com/`, and a stable ticket-based event ID. A dedicated command retries queued events with the original payload and timestamp, marks success only when Meta acknowledges receipt, and stops retrying expired events. No historical orders are backfilled. Existing app/POS tracking is preserved.

## Deployment

`run_root.sh COMMIT check` verifies pinned release hashes, current production file hashes, PHP syntax, and the existing dataset configuration. `apply` backs up changed files outside the public application, installs only this release, runs only its additive migration, checks readiness without transmitting an event, and installs a dedicated cron job. It never places a test order or activates an ad campaign.

The supported production baseline is WebStorefront Git blob `2d3995aaaab1c48a2a951cb8460721e93a46e5f6`. Unexpected edits or conflicting new files stop publication. The release is intended for host `srv1836524`, application `/home/fasakha/public_html`, PHP 8.2, and runtime user `fasakha`.

Rollback restores code and the dedicated cron file from its private backup after checking that no newer code has replaced the release. It preserves the dedicated queue table and migration receipt, so it does not delete order or tracking records.

## Validation

- PHP syntax and standalone behavior tests use synthetic requests, fake facades and fake HTTP responses; they never contact Meta.
- Checkout behavior tests cover fresh orders, replays, failed transactions, deadlock retries, and tracking failures.
- Deployment tests cover source/hash guards, preflight failure before writes, idempotence, and rollback protections.
- `php artisan meta:website-purchases --check` is a read-only production readiness check. Installation alone does not prove that Meta has received a website Purchase. Confirm the first genuine new website order in Events Manager before activating the prepared Sales campaign.
