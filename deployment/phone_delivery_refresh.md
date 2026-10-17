# Delivery preparation timer and required checkout follow-up

This release combines the pending invoice quantity/print/logo update with the delivery screen refresh and the latest checkout requirement.

- Preparing delivery cards show elapsed time from the persisted `created_at` timestamp. The badge is green through 15:00 and red from 15:01; it updates every second without a request or database write. Edits, polling and reloads keep the original timestamp. Invalid/missing timestamps have no fabricated timer. Paid, cancelled and dispatched cards have no preparing timer.
- A separate stylesheet improves the queue headings, counts, card hierarchy, actions and responsive layout. Existing phone CSS and product workspace geometry are never overwritten. Desktop, laptop and mobile layouts were inspected with long customer names and hour-long timers.
- Final takeaway, dining and delivery collection requires the current service follow-up checkbox. Batch delivery collection requires each invoice's checkbox. Focus and Enter do not select approval; explicit click or Space does. New UI writes send service approval and no marketing approval. Existing phone/customer validations remain in use.
- Dashboard controllers require consent on new collection commands before financial writes. Exact already-committed replays remain recoverable; trusted internal callers retain their existing optional parameter defaults. The takeaway duplicate check is repeated under the till lock to retain safe concurrent replay behavior.

The root wrapper loads the pinned, hash-verified quantity installer at `6582750c2ef925500504a83d89141e49f0bd62a3`. A combined temporary preflight checks the quantity and refresh layers, including real server route/schema/Blade readiness, before either layer publishes source. The quantity layer is applied with its own receipt, then this release publishes its declared refresh/consent files with a separate private backup and rollback. If the latter fails, the successful quantity layer remains recorded; the reported refresh result distinguishes rollback from review-needed concurrent edits. Repeated and partial installations are checked before writing.

The verified quantity module omits the phone script from its historical workflow prerequisite list. Exact quantity and refresh mutation guards, pricing-backend proofs, DOM checks and translation checks stay active, allowing later independent customer-entry code to remain in place.

The phone script preimage reconstructs the deployed `642dbfe` script plus the pinned quantity feature patch. Later compatible keyboard and presentation changes survive targeted patch application. No migrations, financial backfills or broad cache clears run.

Isolated tests use mocked browser endpoints and SQLite fixtures; installer tests stub only the database-dependent Laravel probe. The production wrapper runs that real read-only probe on the server.
