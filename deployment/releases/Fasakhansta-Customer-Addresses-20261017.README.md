# Fasakhansta automatic location and saved addresses

Source release: `2c597530e88c78e39f54bc68351246732a566fa2`. Baseline: `01b8805ef49cc05c315edbde7909ad6489954190`.

- Requests browser geolocation automatically for first registration; permission cannot be bypassed, approximate or denied GPS has a manual fallback.
- Saves normalized delivery fields plus name on the same browser/device, with up to ten addresses in an upper selector, add-new and clear-device actions.
- Rediscovery/coverage remains server enforced. No public phone lookup is added. Late GPS/quote results cannot replace selected context. Pending order retries retain the same request token.
- Updates only home/JS/CSS. Preserves independent SEO edits; rejects unknown asset changes; backup and rollback; only clears compiled views.

Validation: complete DOM interaction suite, 16 installer tests, compiled Blade, JS/Python/shell syntax and bundle integrity/source checks. Actual parallel SEO merge and repeat install verified. No real customer orders submitted.

SHA-256: `3ffa65815992fd610785e3ed5668740853c37102aa7eaab3e2ee1a7cc79446a4`. Archive: 62795 bytes.

Extract outside the public web root as root; run `bash INSTALL.sh --check` then `bash INSTALL.sh`. Success: `WEB_CUSTOMER_ADDRESSES_READY`. Publishing this package does not install it on production.
