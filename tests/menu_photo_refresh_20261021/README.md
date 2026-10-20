# Full-menu image publication checks

Run `php -n tests/menu_photo_refresh_20261021/run.php` with PHP 8.0+.

The actual deployment class is exercised with a complete fixture: 32 branches, 1,473 visible non-side menu rows, 42 JPEG assets, 219 excluded side rows, 152 hidden rows, and inventory/recipe/GO sentinels. The wholesale branch contributes 20 images. JPEG publication, old originals/derivatives, and private receipts use real temporary filesystem operations.

Database, Laravel Storage, Spatie models and HTTP are isolated doubles. The Media double exposes supported Spatie 9.6 `getPath()` and `getUrl()` methods and deliberately omits `getPathRelativeToRoot()`. The Storage double caches its URL at construction, matching the stale CLI disk behavior, while the manifest carries the established `/storage/products/{mediaId}/{filename}` catalog URLs. The suite primes an incorrect `/storage` disk and proves the runner changes only its process-local URL setting and evicts that cached disk. The injected HTTP verifier checks every destination JPEG and SHA before the database double permits any update. It also injects HTTP 404/body mismatches.

The suite checks read-only preview; complete reviewed scope; publication before reference changes; stable media IDs; preserved menu names/prices/descriptions/visibility, side and hidden items, recipes, inventory and GO rows; retained old originals/derivatives; JPEG metadata; cleared derivative flags; preserved custom properties; first-application timestamps; repeat idempotence; asset/collision/scope/media-identity errors; concurrent edits; both possible commit-acknowledgement outcomes with retries; reuse of all published files and the unchanged first backup after an HTTP failure; and rejection of invalid, off-origin, wrong-media-ID, query, fragment, encoded-slash and raw control-character catalog URLs before any private/media/database writes.

The fixture intentionally uses one small repository JPEG for its 42 test assets to keep the suite fast. It does not inspect the final food photography or replace live Laravel/Spatie/MariaDB integration and real HTTP verification. No production environment or network is accessed.
