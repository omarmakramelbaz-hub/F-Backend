# Customer marketing template setup

The campaign dialog now explains when no approved Marketing template is available
and provides an explicit action to prepare the Arabic campaign template for Meta
review. The central admin writes the actual static message (up to1024 Unicode
characters, without template variables); no offer text is invented. Names are
derived from normalized text so repeating a request reuses its exact template
and a different offer can have its own template. Approved templates can be selected for a campaign; pending, rejected,
conflicting and unavailable templates display their status separately.

Preparing a template submits its displayed text for review only. It does not queue
or send a campaign, enable messaging, or change customer consent. After Meta
approval, refresh the template status, select the approved Marketing template and
review the campaign before explicitly submitting it to the existing campaign queue.
Existing customer consent and sending controls remain in effect.

The installer is pinned to `cba1f9feda124573bc961aaa4d2dce63d2dd5a59`. It stages
only `CustomerMessagingService.php`, `BranchOperationsController.php`,
`CustomerCrmRouteCheck.php`, `branch_operations.php` and `customer-crm.js`. A
three-way merge preserves independent live edits and rejects conflicts before any
application source is changed. Private backups and a receipt accompany publication;
failed post-install checks restore only the files still matching the installed
bytes, preserving concurrent operator changes for review.

Preflight checks PHP and JavaScript syntax when Node is installed, plus the exact
Marketing preparation route and its admin, session and CSRF protections without
dispatching a request. Publication clears route/config/view caches and verifies
the live route plus the read-only `crm:check` contract. It does not create a Meta
template, send or queue messages, change messaging settings, run migrations, import
customers, restart workers, or enable timers.

Run the pinned root entry point on `srv1836524` with the full release commit:

```sh
bash deployment/apply_customer_marketing_template_root.sh FULL_COMMIT --check
bash deployment/apply_customer_marketing_template_root.sh FULL_COMMIT
```

`--check` stages and validates the update without publishing application sources or
clearing application caches. Repeating an installed release is safe.
