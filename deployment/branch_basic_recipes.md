# Frozen Mahalla menu recipes

The release captures the saved menu recipes of Mahalla, restaurant ID 94 (`f:94`), once into the singleton `branch_basic_recipes` table. The source ID and normalized branch name must identify Mahalla, and the source must have saved recipes. The snapshot preserves menu identity, recipe quantities, units, and portion definitions. Later edits to Mahalla recipes do not change the captured baseline. Rerunning the installer or freeze command never replaces a valid existing snapshot.

Each branch's goods page exposes `استخدم الوصفة الأساسية` to the same administration/owner roles that can manage recipes. The confirmation previews the number of matching, changed, unchanged, and skipped recipes. On confirmation, only matching branch-local recipe records are changed. Menu prices, ingredient balances, stock movements, and sales remain unchanged. Missing or ambiguous products and incompatible portions/components are skipped with reasons; unmatched branch recipes remain intact. Target recipe updates use the branch transaction lock, an up-to-date preview hash, and an idempotency key.

## Deployment

Fetch the pinned release branch without checking it out. Verify the root wrapper bytes against the Git blob at the pinned commit. Run `apply_branch_basic_recipes_root.sh FULL_RELEASE_REVISION --check` as root, then run the same wrapper without `--check`. The wrapper delegates to `fasakha` with `/opt/cpanel/ea-php82/root/usr/bin/php`; the application root is `/home/fasakha/public_html`.

`apply_branch_basic_recipes.py` verifies its exact source manifest against base commit `a1a2764cab407a8bc2a871403dfcdee304bba220`, stages only reviewed hunks, and preserves nonoverlapping live edits. Every new path must be absent or exactly match the release. Staged PHP and Blade are parsed with the live runtime; JavaScript syntax is checked when Node is available. Read-only schema checks verify the necessary columns and transactional MySQL tables. A staged `BranchBasicRecipes::freeze(true)` call validates the source or existing immutable baseline without requiring the new snapshot table to exist. A deterministic content hash detects source edits during preflight and is pinned again under the source transaction lock when the snapshot is captured. Check mode publishes no application files and writes no database records.

After preflight, the installer saves private source backups and a JSON receipt beside the application in `branch-basic-recipes-backups`. It publishes the dormant service, command, and migration before their callers, then runs only:

```sh
php artisan migrate --force --path=database/migrations/2026_10_18_190000_create_branch_basic_recipes.php
php artisan inventory:freeze-basic-recipes --expected-content-hash=CHECKED_SOURCE_HASH
```

The migration is skipped when already recorded. The freeze command is skipped when the valid singleton already exists. The migration creates only the snapshot table; the freeze inserts only the snapshot row and never applies recipes to another branch. Existing recipes change only when an authorized operator uses the button. No broad migrations or Git resets run. After callers are published, `php artisan route:clear` makes the new endpoints visible on installations using cached routes. The route cache is not rebuilt; the next request loads the current source routes.

A failure reverses only files whose complete installed bytes still match the release, preserving concurrent live edits for review. Additive schema and the immutable snapshot may remain after a database attempt, even when code is restored. The receipt records migration/freeze attempts and the possibility of remaining database changes. The installer never drops the snapshot or rolls database records back. A cleared route cache can remain cleared after a failure; restored source routes remain available.

For manual read-only validation after deployment:

```sh
/opt/cpanel/ea-php82/root/usr/bin/php artisan inventory:freeze-basic-recipes --check
```

## Verification

`python3 tests/test_branch_basic_recipes_install.py` passes 24 tests using private real Git fixtures and mocked database/runtime operations. Coverage includes read-only preflight, publication order, exact migration scope, preserved edits and modes, new-path collisions, existing snapshot reuse, safe retry, migration/freeze/cache-clear failures, code rollback, concurrent edits, symlinks, wrapper path identity, and strict schema/source reply validation. The suite never accesses a live database. Business behavior is covered separately in `tests/branch_basic_recipes_test.php` and UI behavior in `tests/browser/branch-basic-recipes-dom.cjs`.
