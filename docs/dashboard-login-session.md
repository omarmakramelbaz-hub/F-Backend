# Dashboard login and remembered sessions

The login page keeps only the last submitted username/email/mobile in
`localStorage["fasakhansta.dashboard.username"]`. It never serializes the form,
persists a password, or puts an authentication token into web storage.

Without **Remember me**, the dashboard session cookie is a browser-session
cookie. Browser session restoration can retain session cookies, so closing a
window is not equivalent to explicitly signing out. With **Remember me**, the
existing Laravel admin guard issues its encrypted, HttpOnly remember cookie and
restores authentication after the ordinary session is gone. The existing
account-type redirect and branch context are retained. Explicit sign-out
invalidates the session and rotates the remember token.

Passwords are submitted only through the normal login request; existing server
password hashing is unchanged. Failed logins flash only `email` and `remember`.
The password field is cleared on initial load and history restoration; browser
autofill is discouraged using form hints and password-manager attributes.
Websites cannot delete passwords previously saved in Chrome or force every
password manager to obey these hints. Existing saved site credentials must be
removed from Chrome and password saving disabled there if required. No browser
password database is accessed by this change.

## Publish

Fetch the reviewed `codex/dashboard-login-session-20261017` release and execute
`deployment/apply_dashboard_login_session_root.sh RELEASE_SHA` as root. The
wrapper verifies and extracts its Python helper from that exact commit. Optional
`--check` performs a preflight without application writes or backups.

The installer applies only the reviewed hunks to the five existing PHP/Blade
sources and adds `DashboardSessionCookies.php`. It refuses ambiguous/conflicting
edits, lints the final merged PHP, preserves unrelated live changes, saves source
backups outside the public root, and rolls back sources if publication or view
cache clearing fails. It does not switch/merge/reset the live checkout, change
environment settings, migrate the database, or publish other branch changes.

## Validation

`LOGIN_TEST_VENDOR=/path/to/laravel8/vendor php tests/dashboard_login_session_runtime.php`
exercises the real Laravel Eloquent auth provider, guard, session and encrypted
cookie middleware with SQLite: ordinary login, remembered fresh-session
authentication, secure cookie flags, all account redirects, restored branch
context, logout/replay rejection, failure handling, and unrelated route policy.

`NODE_PATH=... PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=... node tests/dashboard_login_browser.cjs`
exercises the actual login form/script in Chromium with intercepted local test
responses: username-only storage, checkbox submission, navigation, empty
passwords on history/reload, server old-input precedence, and unavailable storage.
It does not test an installed third-party password manager.

`python -m unittest discover -s tests/deployment -p test_dashboard_login_session.py`
checks narrow publication, preflight, idempotence, rollback and conflict refusal.
