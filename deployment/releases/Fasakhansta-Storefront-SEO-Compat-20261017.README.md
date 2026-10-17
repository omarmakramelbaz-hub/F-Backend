# Fasakhansta storefront SEO compatibility update

Incremental SEO installer for /home/fasakha/public_html. This corrects SOURCE_PATCH_CONFLICT on the homepage when the customer-address and automatic-location update is already installed.

The reviewed SEO edits are merged individually with unique exact before/after text. Two insertions require allowlisted HTML anchors. Saved customer/address fields, geolocation help, privacy text and the existing CSS/JavaScript versions remain intact. Route merging, owner/permission checks, conflict detection, private backups and rollback remain active.

Public category pages, trusted metadata, brand/collection/breadcrumb structured data, sitemap and login noindex are the same reviewed SEO implementation. No database migration, customer/order write or pricing/coverage change.

Source release: c4a3a119e0a232a198c0633bd9aedf3547ee1d57
Archive: Fasakhansta-Storefront-SEO-Compat-20261017.zip
Size: 44320 bytes
SHA-256: ad200f0f835d677784e28c992e1e653ff925fddf4bb06306bdca13541fa58219

Extract in a root-private directory, run bash INSTALL.sh --check, then bash INSTALL.sh.
Success: WEB_STOREFRONT_SEO_READY.

Validation: 14 installer tests, independent 284 partial-install/duplicate-atom checks, exact pinned customer-address merge, 105 public-page checks, original and customer-address DOM flows, and the unchanged deployment checker against the merged homepage in an isolated Laravel fixture. Source blobs and ZIP CRC verified. Uploading this archive does not install it on production.
