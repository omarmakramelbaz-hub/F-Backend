# Incremental delivery card timing update

This update starts from the confirmed delivery refresh at `a7d7b5572390bff8ca4b2503172cc63470370dae`.

It changes five source files: the phone board service, phone JavaScript, the separate delivery refresh stylesheet, and Arabic/English phone labels. The preparation counter continues into the courier queue from the same creation time. Finished cards use the durable completion timestamp returned by the board service. Existing menu styles, customer entry behavior and application-order editor assets remain in place.

The new installer is `deployment/apply_phone_delivery_timing.py`; its root launcher is `deployment/apply_phone_delivery_timing_root.sh`. It loads only filesystem/Git utility functions from the exact hash-verified installer blob `a438bfdcb17f43ef11605bcb48de926c190b3f73` at `6582750c2ef925500504a83d89141e49f0bd62a3`. It never runs earlier feature installers or their historical prerequisite proofs.

Every declared source delta is generated from its pinned baseline. Forward/reverse checks support fresh, repeated and partially completed updates, preserve compatible independent edits, and reject overlapping changes. All new PHP and translation files are linted before publication. A focused readonly Laravel probe checks the effective phone board route and middleware, public board/presenter methods, and existing ticket, command, receipt and dispatch columns. It reads schema metadata only and returns named success/failure markers without configuration or customer data.

Source publication uses checked Git patches and a final live snapshot check. Backups and receipts are written under `/home/fasakha/phone-delivery-timing-backups/` with private parent permissions. A failed post-check restores original source when it still matches this update. A concurrent operator edit preserves the source group and reports `PHONE_DELIVERY_TIMING_ROLLBACK_REVIEW_REQUIRED` for review. The update preserves source modes and restores the caller's umask.

Use the supplied full release SHA, extract its root launcher, verify the launcher Git blob, and run it as root. The launcher validates its Python installer blob and runs it as `fasakha` against `/home/fasakha/public_html` with cPanel PHP82. Success is `PHONE_DELIVERY_TIMING_OK`; `already_current=1 files=0` confirms a repeat. Direct `--check` mode on the Python installer performs preflight without publishing source or writing backup receipts.

No migrations, data backfills, order mutations or broad cache clears run. Existing filemtime asset URLs pick up the changed files.
