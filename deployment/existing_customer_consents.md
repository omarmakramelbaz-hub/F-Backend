# Owner-confirmed existing customer permissions

On 17 October 2026 the owner confirmed that the registered customers had actually agreed to service followup and promotional messages. This is a one-time recording of that declaration, not an automatic approval rule for new customers.

Run the pinned `record_existing_customer_consents_root.sh FULL_REVISION` helper as root. It executes a temporary reviewed PHP script as the application user and does not replace live application files. The helper prints a count preview, then saves a private before-state backup outside the public directory and records eligible permissions and one durable database receipt in the same transaction. The backup includes permission audit fields and IDs, and omits customer profile fields such as names, addresses and phone numbers.

Global opt-outs and independent purpose withdrawals remain effective. Existing approvals and their original audit fields are preserved. The recording evidence attributes the owner's declaration and the current recording time; the original acquisition date and method are unknown. The fixed event receipt makes retries return the original result without approving later customers or undoing a later STOP.

This tool does not enable messaging, create a campaign, change a purchase or call WhatsApp. If normal feedback sending is already enabled, its existing worker can process eligible purchases under those settings after the permission record is updated.

For a read-only count preview, run the PHP script with `--root=/home/fasakha/public_html`. Application requires both `--apply` and `--owner-confirmed`; this event has already been authorized by the owner. No further interactive confirmation is needed.
