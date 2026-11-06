# Invoice date review

The takeaway invoice dialog now accepts any specific invoice date, including dates in previous months. Open **فواتير اليوم**, select **تاريخ الفواتير**, and press **عرض الفواتير**. **اليوم** returns to the current operating day from the server.

The selected day follows pagination. Its count and total are displayed inside the dialog; today's header badge, the till and the active sale remain unchanged. Invoice details and printing retain their existing permissions. Dates use the existing Cairo operating day, 06:00 to 06:00 the next day.

No API, database or order changes are required. The existing receipts endpoint already validates and filters `date=Y-m-d` using `business_date` and the actor's allowed branches.

## Server application

Download `RUN_ROOT.sh` at the reviewed immutable commit, verify its SHA256, then run:

```bash
bash RUN_ROOT.sh COMMIT_SHA --check
bash RUN_ROOT.sh COMMIT_SHA --apply
```

Run on `srv1836524` as root. The runner verifies and downloads only the pinned installer and patch specification. The installer checks the existing date API and asset versioning, stages and syntax-checks four candidate files, backs up changed files, and replaces only the matching blocks. It preserves unrelated live edits, ownership and modes, forces new asset versions, rejects unexpected source changes, verifies installed files, and rolls back its own writes if verification fails. Repeating the same release is safe.

Success prints `INVOICE_DATE_REVIEW_APPLIED_AND_VERIFIED`. Reload the dashboard with Ctrl+F5 after application.

## Validation

- JavaScript syntax with `node --check`.
- Arabic and English translation syntax with `php -l`.
- Production modal functions tested against a controlled DOM/API fixture: past dates, pagination, reset, validation, empty/error results, stale requests, close aborts, branch selection, invoice actions and unchanged sale state.
- Patch transformation, repeated application, conflict rejection, backup and rollback checks.

`tests/invoice_date_review_ui.cjs` requires Playwright for browser mode; DOM mode uses jsdom. Browser screenshots require an environment that allows Chromium to launch.
