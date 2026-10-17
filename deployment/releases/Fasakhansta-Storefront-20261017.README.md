# Fasakhansta storefront release — 2026-10-17

Standalone, reviewed installation package for `/home/fasakha/public_html`.

- Navy/orange Arabic RTL storefront, all eligible public Fasakhansta stores, branch menus and native unpaid delivery-order checkout.
- Archive SHA-256: `60b5a54d7a38219a0ccc0f61969c71da4adc232e069a09c0132f255cd2a83c20`.
- Archive size: 850949 bytes. Production source release: `1aa34ec649d974926c7e5f9b9c3b3489e1912031`.
- The archive contains the release files, reviewed baseline and previous source snapshots, installation notes, and installer.
- Fetch this branch, extract the archive into a root-private temporary directory, then run `INSTALL.sh --check` followed by `INSTALL.sh` as root.
- Installs only the storefront files with backups, conflict checks, and a single additive order-mapping migration. Preserves unrelated dashboard changes.
- Success marker: `WEB_STOREFRONT_READY`. Uploading this package does not install it on the production server.

Validation completed before packaging: 54 Laravel runtime checks, 3 installer tests, DOM interaction checks, source syntax checks, and independent release/archive review. No production order was created by these tests.
