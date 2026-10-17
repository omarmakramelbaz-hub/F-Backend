# Fasakhansta storefront: location-first service coverage

Reviewed update for `/home/fasakha/public_html`.

- Browser tab and Apple touch icon use the actual restaurant logo from the storefront header.
- Confirmed map pin, Egyptian phone number, area and detailed address precede store discovery.
- Uses the application's union of saved delivery circles from `resturant_areas`, including its boundary rule. Matching public stores are sorted nearest first.
- Catalog, quote and checkout recheck current coverage server-side; quote tokens bind session and delivery context. Completed order retries remain idempotent after context changes.
- Address search and discovery use POST; the page bootstrap contains no customer information or store directory. Existing delivery charge calculation is retained.
- Upgrades the original site and both earlier reviewed storefront versions with backups, conflict checks and rollback.

Source release: `01b8805ef49cc05c315edbde7909ad6489954190`.

Archive size: 1272794 bytes.

SHA-256: `18686efa7f2a61bae872a8b8f6a2e10c68fb9bf8d763bcae1b4d911a37fca8e3`.

Extract in a root-private directory, run `bash INSTALL.sh --check`, then `bash INSTALL.sh`. Success: `WEB_STOREFRONT_READY`.

Validation: 109 Laravel/SQLite runtime checks, 8 installer tests, DOM interaction coverage, compiled Blade/source syntax checks, and independent source/archive review. Readiness checking uses an isolated memory session without customer/order writes. Uploading this archive does not install it on production.
