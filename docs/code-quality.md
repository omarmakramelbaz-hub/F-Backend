# Code quality and maintenance

The backend is a Laravel 8 application. Runtime code lives in `app/`, API and dashboard routes in `routes/`, views in `resources/views/`, and database changes in `database/migrations/`. PHP dependencies belong in `composer.json`; JavaScript asset dependencies belong in `package.json`.

## Local checks

```sh
composer install --no-scripts --no-plugins
find app routes config database tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/notification_dispatch_unit.php
php tests/go_services_unit.php
vendor/bin/phpunit tests/Feature/AuthAddressSafetyTest.php
npm ci --ignore-scripts
npm run production
```

The route safety tests create an in-memory SQLite database, fake mail, and use a local geocoding fixture. They must never target a production database. Tests generate their own test-only application keys. The notification unit check uses fake recipients and delivery; it sends no real notifications.

Laravel Mix 6 uses a private Webpack helper removed in newer Webpack releases. Webpack is pinned to the verified compatible version in `package.json` and `package-lock.json`; update them together and run the production build before changing that pin. Generated assets are not included in this review.

## Editing conventions

- Follow `.editorconfig` and keep changed PHP code readable with four-space indentation.
- Put request validation in Form Requests where appropriate. Use validated fields for writes and bind ownership on the server.
- Keep money, order transitions, inventory, payment and role rules in focused changes with behavior tests.
- Extract helpers from large controllers gradually; broad reformatting obscures functional review.
- Read the existing feature branches before selecting a deployment baseline. `main` is not a complete snapshot of all recent incremental server updates.

The code-quality workflow runs checks on pull requests and manual requests. The existing production deployment workflow is separate; this review does not merge or deploy changes. Composer currently has no tracked lock file, so the PHP dependency set must be reconciled with the live installation before adopting a new production lock.
