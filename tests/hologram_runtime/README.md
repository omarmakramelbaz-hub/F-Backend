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
```

Do not commit the disposable runtime's `vendor` directory. Standard full-site
feature tests remain in `tests/Feature/DashboardHomeHologramMetricsTest.php`.
