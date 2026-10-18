# Validation

The release was checked locally against exact repository source references.

- 11 sender-capture tests cover canonical-number deduplication, field and consent preservation, transactional rollback/retry, sender-only identity, and status/outbound exclusions.
- 15 delivery lookup tests cover F employee visibility, the WhatsApp source including mixed GS associations, existing branch restrictions, phone formats, saved addresses, and the eight-choice cap.
- 17 lookup installer tests cover original/V1/V2 upgrade, no-write checks, idempotence, exact restore, and unknown-source refusal.
- 11 capture installer tests cover method-only replacement, durable consumer callback verification, schema checks, private backups, idempotence, restore, and source drift.
- 14 customer-list installer tests cover both targets, no-write checks, preserving unrelated methods and file modes, exact restore, partial-version completion, and second-target failure rollback. They also verify the exact newer follow-up-template frontend from commit `3232f1ec6d6e62ddcd0baaffd0d3cbbe3ac40167`, later unrelated bytes, partially applied expressions, and ambiguous-anchor refusal.
- 5 tokenizer tests cover braces in strings/comments, unrelated methods, and unknown source refusal.
- 14 shell orchestration tests cover every failed preflight, all checks before writes, exact lookup and UI rollback, already-installed combinations, restore-refusal reporting, malformed receipts, and successful replay.
- Conditional customer-edit validation was exercised for missing/null/empty/zero/existing IDs; manual WhatsApp creation remains excluded. JavaScript syntax checks passed.

Behavior fixtures use real SQLite transactions and synthetic contacts. The local
container maps only UID 0, so installer smoke tests use disposable release copies
with only root-denial checks removed. Production owner/root guards are intact.
The production MySQL schema, application owner, exact live source compatibility,
and successful real incoming-message processing still require the server's
guarded preflight and deployment output. No live deployment or customer import
is claimed by these local tests.
