# Customer-confirmed WhatsApp order recap

The native Meta agent remains the responder. In `ANALYZE_ONLY`, a customer can
copy its complete labeled recap into the WhatsApp conversation, then send a
separate whole approval such as `كل شيء تمام`. The workflow parses that literal
customer receipt without generating a reply or requiring invisible agent text.

- The recap and approval remain authentic customer messages. No business echo,
  business evidence citation or native location event is manufactured.
- Names, address, contact numbers, quantities and every item qualifier must be
  present in the recap. The first contact number is primary; alternates are kept
  in delivery notes. Fish count inside a kilogram descriptor is not sale quantity.
- A labeled coordinate pair means latitude,longitude exactly as written. Full
  Google Maps coordinate query URLs and explicit latitude/longitude labels are
  also supported. Short URLs and geographic coordinate swapping are unsupported.
- A native pin can substitute only with earlier matching customer address proof.
  Conflicting points and later order changes require review.
- Only unique available live catalog names and explicit option labels map to ERP
  items. Approximate copied prices do not override current ERP/delivery quotes.
- A complete self-contained receipt can begin its own evidence range in a long
  conversation. All later messages, currentness, capture, policy and consent
  checks remain in force; activation and scan floors are not reset.
- The same effective order resent under different native IDs within 24 hours
  requires manual review. A genuine repeated order can still be reviewed by staff.
- Automatic analysis and order dispatch retain their separate existing schedules,
  branch hours, permissions, quote hashes and idempotent ticket/print operations.

The narrow installer publishes only the new parser and changed WhatsApp workflow.
It preserves installed policy, configuration, activation cutoffs and timer states.
It does not call analysis, send replies, create orders, replace units or modify
the independent CRM, storefront or logo releases. The existing worker picks up
eligible captured recaps when its analysis schedule is open.

The status CLI is read-only and reports receipt/candidate and ERP receipt metadata
without customer text, phone numbers, addresses, coordinates or credentials.
Application tests use only synthetic identities and isolated SQLite data.

This workaround does not restore missing native-agent reply bodies to the inbox.
