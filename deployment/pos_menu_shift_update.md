# Faster POS menu navigation and shift closure checks

Category, search and page requests opt into a lightweight catalog response. Prices,
stock, authorization and tax/service policy remain current. Financial summaries and
owner drawer reconciliation continue on ordinary POS entry and the existing account,
checkout and activity paths. Category selection updates immediately and rapid category
clicks coalesce into one request.

Shift closure includes an authoritative server check for pending expenses. A shift with
unapproved expenses is blocked until those expenses are approved or rejected; the closure
interface presents pending counts and expense identifiers so the operator can act before closing.

`apply_pos_menu_shift_update.py` publishes exactly nine application files, with service
dependencies before controllers and browser callers. It verifies the pinned release's
direct parent is `2ed0dfbf3947e7ccadf52b2766dbc826e3382e6f`. Three-way merges retain
independent live edits; a conflict blocks every application write. All candidate PHP
source files must pass the supplied PHP binary's syntax check. JavaScript syntax is checked
when Node is available. The Blade template's literal changes are covered by the release's
isolated template checks; `php -l` is deliberately not treated as a Blade compiler.

Run as the application owner with `--root /home/fasakha/public_html --revision FULL_SHA
--php /opt/cpanel/ea-php82/root/usr/bin/php`; add `--check` for preflight only. An
external `GIT_DIR` containing the verified release and its parent is supported, allowing
deployment from an isolated temporary fetch when production Git refs are damaged.

Before publication, originals and a hash/metadata receipt are saved under
`/home/fasakha/pos-menu-shift-update-backups/`. Files are replaced atomically with their
original ownership group and modes. A failed publication rolls back files still matching
the installer's candidate; concurrent edits are preserved and identified in the receipt.
No application bootstrap, database write or printing operation is performed. Refresh
the dashboard after success.
