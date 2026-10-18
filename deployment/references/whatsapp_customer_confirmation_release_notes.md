# Customer-confirmed WhatsApp order recap

The native Meta agent remains the responder. In `ANALYZE_ONLY`, a customer can
copy its complete labeled recap into the WhatsApp conversation, then send a
separate whole approval such as `كل شيء تمام`. The workflow parses that literal
customer receipt without generating a reply or requiring invisible agent text.

The owner's compact `طلبك المؤكد:` customer-copy format is a separate protocol.
It has item lines with explicit weight or piece quantities, a total, the name, address, contact number and literal
location coordinates in the combined `البيانات:` line or separate labeled fields,
and the documented delivery footer. A complete matching message copied by the customer is its own
confirmation; its receipt explicitly records `CUSTOMER_SELF_CONFIRMED_RECAP` and
uses that original customer message for both recap and approval. The same text
from the business, an incomplete message, an arbitrary embedded phrase or a later
order change does not authorize an order. Legacy `ملخص طلبك:` recaps still require
their separate whole-positive customer approval.
The observed `ملخص الأوردر - فرع نبروه ✅` summary is another strict legacy
format: its `الأصناف:` section, structured customer fields and delivery footer are
recognized, but the headline does not confirm the order. A genuine customer
location pin after this recap and before a separate whole-positive turn such as
`تمام كله تمام` can provide its location proof. The pin and approval must belong
to the same authenticated conversation and current order. Business summaries,
question-like approvals and later material changes remain non-authoritative.
Packaging descriptors remain part of each item name and do not imply a kilogram
quantity or an approved catalog substitute. The branch named in the headline is
informational; the proven location still determines the eligible physical branch.
Recognizing all five copied lines does not establish live catalog eligibility:
generic sandwich names with several possible products, package names, prices,
recipes and stock still require the existing exact catalog and preparation checks.
Three reviewed Mansoura mappings require their precise live identities and units:
the 400-gram roe box remains one piece of product 2551; an explicit quarter-weight
herring salad maps to one existing quarter-pack piece of product 2566; and the
observed feminine herring wording maps to weight product 2576. These rules retain
catalog availability, option and price validation. The catalog diagnostic shows
both the customer's original quantity/unit and the mapped sale quantity/unit.
The quarter-pack identity also accepts the live catalog's `select` unit only
when its exact product ID/name and genuine empty-ID whole preparation agree.
It still submits one piece of that existing quarter pack, without treating
arbitrary selectable products as pieces or converting another recapped weight.
There is no global alias choosing a variant for a generic feseekh sandwich.
Only the latest recognized customer-confirmed recap supplies an order. Earlier
recaps do not contribute items or contact data, and a malformed latest recap does
not fall back to an earlier valid one. The current Cairo-day rule still applies
to the selected recap and its own confirmation rows.

- The recap and approval remain authentic customer messages. No business echo,
  business evidence citation or native location event is manufactured.
- Names, address, contact numbers, quantities and every item qualifier must be
  present in the recap. The first contact number is primary; alternates are kept
  in delivery notes. Fish count inside a kilogram descriptor is not sale quantity.
- A labeled coordinate pair means latitude,longitude exactly as written. Full
  Google Maps coordinate query URLs and explicit latitude/longitude labels are
  also supported. Short URLs and geographic coordinate swapping are unsupported.
- A native pin can substitute only with earlier matching customer address proof.
  A later pin conflicting with the confirmed recap point, or later order changes,
  requires review. A new explicit recap point supersedes earlier location history.
- Only unique available live catalog names and explicit option labels map to ERP
  items. Approximate copied prices do not override current ERP/delivery quotes.
- A complete self-contained receipt can begin its own evidence range in a long
  conversation. All later messages, currentness, capture, policy and consent
  checks remain in force; activation and scan floors are not reset.
- The same effective order resent under different native IDs on the current Cairo
  calendar day requires manual review. A new current-day confirmation may repeat
  yesterday's completed basket; the original native command is never reused.
- Automatic dispatch accepts only a customer recap and its confirmation authored
  on the current calendar day in `Africa/Cairo`. A later greeting or new approval
  cannot renew a previous day's recap. The day is checked again when creating the
  order, including a midnight crossing; yesterday's queued receipt is not sent
  when a configured dispatch window opens on the following day.
  Other automatic extraction paths apply the same date cutoff to their actual
  committing customer requests and cited business confirmation evidence.
- Automatic analysis and order dispatch retain their separate existing schedules,
  permissions, quote hashes and idempotent ticket/print operations. The existing
  owner-approved physical-branch-closed exception applies only to this verified
  customer-receipt path; global dispatch hours remain unchanged.

For customer receipts, the proven location point determines the nearest eligible
physical branch using its existing canonical delivery pin. The customer's written
address and branch wording do not select the branch. The unique closest physical
branch accessible to the existing automatic actor must also be permitted by the
automatic-order configuration; missing geometry, ambiguous distances and
conflicting customer pins continue to require review. The stored address remains
delivery information. Current branch catalog and quote checks run for the selected
branch, and the previously approved Mansoura-only product aliases do not extend to
another branch.

The narrow installer publishes only the new parser and changed WhatsApp workflow.
It preserves installed policy, configuration, activation cutoffs and timer states.
This includes the currently saved all-day dispatch choice; the installer does not
run a dispatch-hours repair or reactivate the control.
It does not call analysis, send replies, create orders, replace units or modify
the independent CRM, storefront or logo releases. The existing worker picks up
eligible captured recaps when its analysis schedule is open.

The status CLI is read-only and reports receipt/candidate and ERP receipt metadata
without customer text, phone numbers, addresses, coordinates or credentials.
Application tests use only synthetic identities and isolated SQLite data.
The optional format diagnostic reports fixed generic titles and approval-basis
flags only. The catalog diagnostic uses the same proven point and nearest-branch
helper, and repeats routing geometry, snapshot and catalog observations for
stability without exposing coordinates or creating an ERP order.
The status diagnostic separately reports whether the proved recap and approval
rows share the current Cairo date. This observation never treats the latest
customer message as a replacement for those actual receipt rows.

The installer and read-only status helper accept the original inbox protocol and
the reviewed CRM receipt-projection extension. The extension only adds validated
delivery-status DTOs; message, cart and location parsing are unchanged. Neither
protocol variant is published or replaced by this installer.

The recap footer also accepts the observed copy instruction `عشان نأكد طلبك`.
This is only a spelling/phrasing alternative; the copied instruction never
replaces the separate customer approval. Unknown or material footer changes,
including cancellation, quantities, delivery-time or fee conditions, still
require review.

This workaround does not restore missing native-agent reply bodies to the inbox.
