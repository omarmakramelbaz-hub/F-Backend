# Imported customer service follow-up

One-time administrator-directed service follow-up activation for the exact imported
dataset `a49073c70061ea9ef497d7ec423af54f2666d7e2af16216a9b21e3a1340b2f14`.
It targets the canonical customer records linked to that import, without enrolling
other contacts or inventing a telephone number for invalid source profiles.

Existing service approvals remain intact. A recorded global opt-out, or a prior
service decision with evidence/actor/timestamp, is retained. Marketing consent,
contact details, purchases, customer sources, and messaging settings are unchanged.
The new evidence truthfully identifies this as the owner's bulk directive and
does not claim an individually recorded verbal approval. Actor ID 1 is checked as
a central administrator before applying.

The root entry is pinned to srv1836524 and the fasakha-owned application. It stages
code privately and runs both the read-only check and apply as fasakha using the
existing PHP 8.2 CLI. It requires the complete pinned import and transactional
MySQL tables. Apply takes customer row locks, writes a private before snapshot,
then commits the eligible service fields in one transaction. It retains explicit
withdrawals, and repeating it leaves previously changed approvals untouched.

No sender, queue, purchase, template, or campaign operation is invoked. Existing
follow-up automation continues to own message delivery.

Validation: 12 local PDO/SQLite tests passed, covering the complete pinned record
counts, deduplication, outside-dataset preservation, existing approvals and
withdrawals, marketing and unrelated-table preservation, private backups, replay,
identity/actor rejection, and atomic rollback after a later update chunk fails.
Production MySQL checks run on the server before writes.
