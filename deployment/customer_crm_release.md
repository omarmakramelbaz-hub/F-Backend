# Customer directory and WhatsApp followup

Takeaway and dining settlement require a valid customer phone on the server and in the dashboard. One normalized phone identifies a customer across branches, with separate takeaway, dining, application and delivery badges. Old customer records are imported without creating feedback messages for historical sales.

An eligible, completed purchase creates one feedback due time at its actual completion plus 24 hours. The worker checks the original purchase and current customer consent before sending. Existing WhatsApp credentials are reused, and incoming replies remain with the external agent. Unknown send outcomes require review and are never retried automatically.

## Deployment

Fetch the pinned `codex/customer-crm-feedback-20261017` revision, then run `deployment/apply_customer_crm_root.sh FULL_REVISION` as root. It applies only files listed in `customer_crm_manifest.json`, preserves unrelated live changes through checked patches, takes a private backup, runs the explicit CRM migrations, imports historical profiles and enables a one-minute systemd timer. Conflicting edits stop publication before replacement. Application-check failures restore code while retaining database additions and imported customer data.

The installer does not reset the server checkout or change login/sidebar behavior. New offline catalog snapshots enforce the phone requirement. Already-paid events from older server-stored snapshots qualify for contact-free historical import only when their sale time predates the persisted installation cutover; their cash and inventory effects remain intact, without inventing a customer phone or sending a survey.

Publication finishes with `crm:check`, which verifies the actual 13 customer, POS and authenticated app-consent route contracts, authentication/session/CSRF middleware and route matching without dispatching requests. It does not resolve unrelated legacy controllers. The first successful publication activates the offline phone cutover; failed installation attempts do not advance that activation time on a later retry.

## WhatsApp setup

Open the customer list, then **متابعة العملاء ورسائل واتساب**. Sending starts disabled. Select an approved UTILITY template whose body matches:

> رأيك يهمنا في المنتج والخدمة المقدمة، يُرجى تقديم ملاحظات حضرتك في هذه الرسالة لتحسين جودة المنتج والخدمة.
> طلب رقم {{1}} – فرع {{2}}

Record explicit service-followup consent separately from promotional consent. Phone collection alone never grants either. The template must be approved by Meta before enabling feedback. The dashboard displays the required body and compatible approved templates.

After asking the customer, staff can click **وافق على المتابعة والعروض** once in the customer list. No evidence text or consent dialog is required: the server records a staff attestation of verbal permission, its timestamp and the authenticated employee automatically. **اختيار نوع الرسائل** allows service or promotion changes independently, and **إيقاف رسائل واتساب** revokes both. Takeaway checkout uses one optional, unchecked choice for followup and offers, and records its source automatically; restored older invoices keep any separate per-purpose choice until staff change that control. Mobile customer applications must present an unchecked consent choice in their own phone-entry UI; merely creating an application account does not grant consent.

The Fasakhansta mobile update posts selected positive choices only after successful login to `POST /api/user/whatsapp-consent`, using its existing bearer and `X-App-Scope: fasakhansta`. The versioned body accepts `whatsapp_consent_version: 2026-10-17.v1` and optional boolean `service_consent` / `marketing_consent`. An omitted purpose is preserved; explicit false revokes it. The protected endpoint derives the phone from the current authenticated customer and rejects client-supplied target identities. This endpoint does not authorize messages for generic GO accounts. Mobile UI source is a separate F-customer release; installing the dashboard alone does not update distributed app binaries.

For promotions, choose an approved MARKETING template and review its substituted text and eligible recipient count. The bulk button queues one recipient entry per consented phone across all branches. It sends in bounded batches, excludes opted-out customers and displays campaign outcomes. No campaign or customer message is sent as part of installation or automated tests.

## Takeaway counter layout

The takeaway screen uses one numeric pad without a piece/weight toggle. Catalog stock metadata selects the product unit automatically; typed amounts survive switching between weighted products and piece products, and known piece products reject fractional counts. Older products without unit metadata retain decimal support with a generic product-unit label. The catalog is narrower, keypad and received-cash controls are larger, customer name and phone share one row, and cash plus the combined consent share another row. The keypad grows into the full available panel height. Received cash occupies one full-width rectangle with an inside amount-paid label and bold figures; change remains beside the grand total. Enter after choosing items focuses customer name, then a valid phone, then the unchecked combined consent. A separate Enter explicitly affirms both purposes and stays on the choice; the next distinct Enter focuses and selects cash. Tab can skip the optional consent. Held, modified or composing Enter cannot advance checkout or grant approval, and payment requires two distinct Enter presses: the first validates cash and focuses the finish button, and the second uses the existing checkout and receipt-printing flow. Invalid or insufficient cash stays in its field; ambiguous checkout retries reuse the frozen payload. Compact totals and a separate invoice-line scroll keep at least ten added items visible at 1920×1006 and 1366×768, including when the discount reason is shown; smaller screens retain a responsive vertical layout.

## Printed receipt

The shared receipt uses a balanced logo and branch header, clear invoice metadata, readable item columns, and emphasized total, paid cash and change. The thermal layout fits 80 mm paper, wraps complete product names and notes, and repeats table headers for longer bills. Takeaway, dining and phone-delivery receipts retain their stored values, context, payment breakdowns and unpaid/cancelled states. Screen details remain a separate preview without automatic printing.

## Validation

Focused real SQLite tests cover checkout, customer deduplication and branch scope, explicit consent, app observers and historical reconciliation, feedback scheduling, cancellation/refund checks, webhook statuses, ambiguous transport outcomes and campaign retries. Browser tests render the production Blade/JS/CSS on desktop and mobile. Native desktop tests cover offline sales and bill contact completion. The installer is tested against unrelated live edits, conflicts, replay and rollback.
