# Invoice date review

Invoice review now accepts any specific invoice date, including dates in previous months, from all three POS screens. In takeaway, open **فواتير اليوم**. In dining and delivery, open the new **مراجعة الفواتير** button. Select **تاريخ الفواتير** and press **عرض الفواتير**. **اليوم** returns to the current operating day from the server.

The review lists finalized receipts for all three order types, with a channel column. Dining's existing ticket manager and delivery's operational board keep their current actions.

The selected day follows pagination. Its count and total are displayed inside the dialog; today's header badge, the till and the active sale remain unchanged. Invoice details and printing retain their existing permissions. Dates use the existing Cairo operating day, 06:00 to 06:00 the next day.

No API, database or order changes are required. The existing receipts endpoint already validates and filters `date=Y-m-d` using `business_date` and the actor's allowed branches.

## Server application

Download `RUN_ROOT.sh` at the reviewed immutable commit, verify its SHA256, then run:

```bash
bash RUN_ROOT.sh COMMIT_SHA --check
bash RUN_ROOT.sh COMMIT_SHA --apply
```

Run on `srv1836524` as root. The runner verifies and downloads only the pinned installer and patch specification. The installer checks the existing date API and asset versioning, stages eleven candidate files, backs up changed files, and replaces only the matching blocks. It preserves unrelated live edits, ownership and modes, forces new asset versions, rejects unexpected source changes, verifies installed files, and rolls back its own writes if verification fails. Repeating the same release is safe.

The installer locates and verifies the available PHP CLI, preferring the established cPanel EA82 runtime. Node is an optional extra server syntax check; the JavaScript is validated locally before publishing this immutable release. A server without Node reports `javascript=skipped_no_node_prevalidated_locally` and proceeds without installing software. Missing or unusable PHP remains a blocking error. The original `INVOICE_HISTORY_PHP_AND_NODE_REQUIRED` failure occurred before applying any files.

Success prints `INVOICE_DATE_REVIEW_APPLIED_AND_VERIFIED`. Reload the dashboard with Ctrl+F5 after application.

## Validation

- JavaScript syntax with `node --check`.
- Arabic and English translation syntax with `php -l`.
- Production modal functions tested against a controlled DOM/API fixture: past dates, pagination, reset, validation, empty/error results, stale requests, close aborts, branch selection, invoice actions and unchanged sale state.
- Patch transformation, repeated application, conflict rejection, backup and rollback checks.

`tests/invoice_date_review_ui.cjs` requires Playwright for browser mode; DOM mode uses jsdom. Browser screenshots require an environment that allows Chromium to launch.
