# Fasakhansta public product guides and search metadata

Reviewed incremental update for the installed location-first storefront at `/home/fasakha/public_html`.

- Public server-rendered /products directory and four finite category guides: feseekh, renga, sardines and bottarga.
- Unique Arabic titles/descriptions, trusted canonical URLs, social metadata, Organization/WebSite and collection/breadcrumb structured data.
- Six-URL XML sitemap and additive robots.txt sitemap directive.
- Homepage links and admin-login noindex added without replacing independent login design or authentication changes.
- Customer location, address and phone remain required before eligible-store discovery or branch catalog/order access.
- No migrations, customer/order writes, pricing or service-area changes. No fabricated offers, ratings or guaranteed rankings.

Source release: `2ec847b6c58575bf56677094a5964a96e168149a`.

Archive size: 43419 bytes.
SHA-256: `9c8aff9f8d72688968361e8cbf91722bcf8ee4036dd0776bcf069fa511fbb3df`.

Extract in a root-private directory, run `bash INSTALL.sh --check`, then `bash INSTALL.sh`.
Success: `WEB_STOREFRONT_SEO_READY`.

Validation: 105 public-page/metadata/router/XML checks; 109 existing Laravel/SQLite order checks; 12 installer tests; DOM interaction regression; real deployment checker executed in an isolated Laravel fixture; desktop/mobile visual checks; archive CRC, source blob and baseline/idempotence checks.

Uploading the archive does not install it on production. After installation, submit https://fasakhaninja.com/sitemap.xml to the verified Search Console property. Search ranking is determined by the search engine.
