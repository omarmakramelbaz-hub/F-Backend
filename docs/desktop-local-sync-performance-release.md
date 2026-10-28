# Local dashboard responsiveness release candidate

This prepares `0.4.4-preview.61` from `f182ca39e0a093f068913db858624d251db33abe`. It is not a production deployment or evidence of improved timing on an owner's computer.

The changes avoid a document reload after an unchanged data check, admit a settled application order board to the existing guarded data handoff, bound safe navigation and order-feed reads, preserve body cancellation, distinguish actual changed select values from implicit HTML defaults, and show incoming-data freshness separately from upload connectivity. Original command identities, mutation transports, printing effects, authority checks and data cutover fences remain in force.

The candidate inventory is an **expected post-patch inventory**, derived from the captured inventory of the currently published `d1976ce02c553939032241e7e724d26a9bbe7190`. Only these intended application files change:

- `public/dashboard/js/dashboard-spa.js`
- `public/dashboard/js/order-board.js`
- `public/dashboard/js/desktop-client.js`

This derivation does not attest that those bytes have already been installed on the server. Fresh live source checks and acceptance for the exact final candidate and installer are required before publication.

Production rollout also requires a reviewed manual worker seed/cohort upgrade covering the changed native files. The completed shared-workspace activation operator is not an upgrade operator for this release. Keep automatic updates disabled, preserve the active database/outbox and original recovery records, and do not run a schema migration for this frontend/native patch.

Local validation at `f182ca39` passed 267 tests with one PHP-only test skipped because PHP was unavailable. The separate Windows workflows provide native/runtime evidence; their result must be checked for the exact final release source. No installed-device startup improvement, complete offline route parity or incremental change-journal protocol is claimed. The unbounded external-script initialization path remains separate work.
