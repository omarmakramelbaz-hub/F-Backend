The CRM browser fixture opens the original `/admin/customers` page in a real
browser. It uses the original Blade, `customer-crm.js`, login, CSRF protection,
permission middleware, controllers and durable journal. It does not inject DOM,
replace handlers or substitute messaging providers. All records are synthetic.

The disposable source database contains the 106 inspected table shapes plus
the three tables created by the original CRM migration. Enrollment, export and
the independently verified import run before the browser starts. A private PHP
server and database are stopped and dropped after the proof.

The browser creates a profile, edits its name and address, records explicit
approval for service and marketing messages, then withdraws service approval
while retaining marketing approval. It also clicks approval again after that
recorded withdrawal and verifies the original offline 409 boundary. The fixture
checks the persisted profile, actor, purpose-specific evidence, four distinct
encrypted journal commands and four original operation receipts. It hashes all
106 remaining imported tables before and after, including purchases, money,
stock, orders and provider data.

For the lost-response case, the network harness delivers the original CREATE to
the actual PHP server and drops its successful response. The original UI locks
the form, offers its real retry control and recovers the saved result with the
same UUID. The browser sends exactly one CREATE POST; recovery is a real GET.

Run against an untouched generated bundle and a private MariaDB port:

```text
php tests/desktop_dashboard_runtime/customer-directory-browser-http.php application maria-port existing-artifacts source-revision
```

CI requires exact Git-bound helper bytes, original runtime source hashes and
fingerprint, Windows Edge, no JavaScript errors, source/helper preservation and
cleanup. It retains the browser proof, HTTP log, receipt and final screenshot.
Local runs may pass an explicit fifth argument containing a frozen working-tree
manifest and use `working-tree` as the revision. That mode records its source
fingerprint and base revision and is rejected in CI. A Linux Chromium result
does not certify Windows Edge or physical printing. `fullDashboard` stays false.
