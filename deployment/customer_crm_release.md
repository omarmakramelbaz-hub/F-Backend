# Customer directory and WhatsApp followup

Takeaway and dining settlement require a valid customer phone on the server and in the dashboard. One normalized phone identifies a customer across branches, with separate takeaway, dining, application and delivery badges. Old customer records are imported without creating feedback messages for historical sales.

An eligible, completed purchase creates one feedback due time at its actual completion plus 24 hours. The worker checks the original purchase and current customer consent before sending. Existing WhatsApp credentials are reused, and incoming replies remain with the external agent. Unknown send outcomes require review and are never retried automatically.

## Deployment

Fetch the pinned `codex/customer-crm-feedback-20261017` revision, then run `deployment/apply_customer_crm_root.sh FULL_REVISION` as root. It applies only files listed in `customer_crm_manifest.json`, preserves unrelated live changes through checked patches, takes a private backup, runs the explicit CRM migrations, imports historical profiles and enables a one-minute systemd timer. Conflicting edits stop publication before replacement. Application-check failures restore code while retaining database additions and imported customer data.

The installer does not reset the server checkout or change login/sidebar behavior. New offline catalog snapshots enforce the phone requirement. Already-paid events from older server-stored snapshots qualify for contact-free historical import only when their sale time predates the persisted installation cutover; their cash and inventory effects remain intact, without inventing a customer phone or sending a survey.

Publication finishes with `crm:check`, which verifies the actual 12 customer and POS route contracts, authentication/session/CSRF middleware and route matching without dispatching requests. It does not resolve unrelated legacy controllers. The first successful publication activates the offline phone cutover; failed installation attempts do not advance that activation time on a later retry.

## WhatsApp setup

Open the customer list, then **متابعة العملاء ورسائل واتساب**. Sending starts disabled. Select an approved UTILITY template whose body matches:

> رأيك يهمنا في المنتج والخدمة المقدمة، يُرجى تقديم ملاحظات حضرتك في هذه الرسالة لتحسين جودة المنتج والخدمة.
> طلب رقم {{1}} – فرع {{2}}

Record explicit service-followup consent separately from promotional consent. Phone collection alone never grants either. The template must be approved by Meta before enabling feedback. The dashboard displays the required body and compatible approved templates.

For promotions, choose an approved MARKETING template and review its substituted text and eligible recipient count. The bulk button queues one recipient entry per consented phone across all branches. It sends in bounded batches, excludes opted-out customers and displays campaign outcomes. No campaign or customer message is sent as part of installation or automated tests.

## Validation

Focused real SQLite tests cover checkout, customer deduplication and branch scope, explicit consent, app observers and historical reconciliation, feedback scheduling, cancellation/refund checks, webhook statuses, ambiguous transport outcomes and campaign retries. Browser tests render the production Blade/JS/CSS on desktop and mobile. Native desktop tests cover offline sales and bill contact completion. The installer is tested against unrelated live edits, conflicts, replay and rollback.
