# Isolated hologram counter tests

The harness executes the production `HomeHologramMetrics`, `TakeawayAccess`, and
`OrderBoardService` against an in-memory SQLite database. It never bootstraps the
site configuration, reads `.env`, connects to a live database, or changes real data.
A minimal Illuminate authentication model stands in for the application's
unrelated media and permission traits. Restaurant relationships and all counter
SQL, status classification, and website membership checks remain production code.

Install this small runtime in a disposable directory with the provided
`composer.json`, then run:

```sh
HOLOGRAM_TEST_AUTOLOAD=/absolute/disposable/runtime/vendor/autoload.php php tests/hologram_runtime/run.php
HOLOGRAM_TEST_AUTOLOAD=/absolute/disposable/runtime/vendor/autoload.php php tests/hologram_runtime/owner_drawer.php
HOLOGRAM_TEST_AUTOLOAD=/absolute/disposable/runtime/vendor/autoload.php php tests/hologram_runtime/named_voice.php
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/hologram_runtime -p 'test_voice_worker.py' -v
```

The Owner harness preserves the distinction between the primary Owner's signed
currency card and other Admin accounts' shift-closing count. The named-voice
harness tests private cache publication, actor/tenant/session/login-ticket
isolation, real protected routes and Range/HEAD response headers, pronunciation
resolution and inventory, and login behavior when generation is unavailable.
Its worker output fixture uses an existing bundled MPEG; it never generates
speech or judges pronunciation/voice quality. The Python worker suite uses
temporary roots and fake processes to verify atomic publication, integrity,
provenance, capacity, retry and locking behavior without downloading a model.

Browser coverage runs the production opening controller/markup and deferred
readiness/finance responses. Set `CHROMIUM_PATH` to an installed Chromium when
Playwright's browser cache is absent:

```sh
CHROMIUM_PATH=/absolute/chromium node tests/browser/hologram-opening.cjs
DESKTOP_TEST_BROWSER_EXECUTABLE=/absolute/chromium node tests/browser/owner-drawer-lazy.cjs
```

Do not commit the disposable runtime's `vendor` directory. Standard full-site
feature tests remain in `tests/Feature/DashboardHomeHologramMetricsTest.php`.
