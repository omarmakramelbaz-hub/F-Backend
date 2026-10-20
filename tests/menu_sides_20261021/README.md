# Menu sides deployment checks

Run `php tests/menu_sides_20261021/run.php` with PHP 8.0+.

These isolated tests require the actual deployment runner and exercise its public `run()` method using in-memory database facade/media doubles. Image assets and receipt/backup files use the real temporary filesystem. No deployed `.env`, credentials, Laravel application, network, or external service is used.

Coverage includes read-only preview; all branch/storefront coverage; category and EGP 15/30 prices; null optional charges; actual image SHA and public URL-to-file mapping verification; unchanged existing fish and GO products; idempotence; MySQL-style JSON key reordering; receipt recovery and commit uncertainty; category and duplicate-name conflicts; changed installed values/scope; obsolete schemas; missing or cross-origin public media mapping; and rollback after a mid-install image failure.

This does not replace a live Laravel/Spatie/MariaDB preflight. The server wrapper performs that preflight before the explicit application step.
