# Automatically activate the saved delivery address

Source release: `b2ec35c91224af06bf1c8f9c2f0e54402f2291f9`. Required previous release: `2c597530e88c78e39f54bc68351246732a566fa2`.

- Returning visitors automatically discover eligible stores without re-submitting personal details. The destination selector is in the persistent header.
- Selecting a saved destination rediscovers stores; new/edit uses the registration form. Current cart/pending-order safeguards remain.
- Same normalized destination preserves the server context nonce so reopening in another tab does not invalidate an in-flight review. Different/invalid details still invalidate old contexts.
- Four exact files: homepage, storefront JS/CSS, existing WebStorefront service. Independent SEO changes are retained; source hashes, backups and rollback; no migration or route changes.

Validation: full DOM interaction suite, Laravel/SQLite runtime assertions,18 installer tests, compiled Blade and JS/Python/shell/PHP syntax, archive integrity/source hashes, original-SEO-to-both-customer-patches chain and repeat install. No real customer orders submitted.

SHA-256: `d960e9c28997055c2bb2f32c0dd309dc8b5a317fba6fb07e2a711a2a2ea53188`. Archive: 78091 bytes.

Extract outside the public web root as root, run `bash INSTALL.sh --check` then `bash INSTALL.sh`. Success: `WEB_CUSTOMER_ADDRESSES_READY`. Uploading this package does not install it on production.
