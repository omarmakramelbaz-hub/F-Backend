# Canonical website domain migration: faskhansta.com

Moves website URL generation, the asset origin, public literal links, product SEO,
app-download links, robots/sitemap origin and WhatsApp website links from the old
host to https://faskhansta.com. Existing site files are transformed in place against
the exact SHA-256 fingerprints reported by srv1836524 on 2026-11-17. It never
overlays the production application with the default branch.

## Run

`python3 migrate.py` performs preflight only.

`python3 migrate.py --apply` applies the reviewed transformations with a private
backup under `/var/backups/faskhansta-canonical.*`, preserved owner/group/mode/xattrs,
and automatic rollback if verification fails. The final output includes an explicit
manual rollback command. A changed baseline stops before publication; reruns do not
blindly overwrite later edits. Do not re-run the old domain-connection script to
revert application changes.

## Publication and validation

- Checks exact source/config/payment-controller fingerprints and the audited .env
  domain settings. Private .env values and payment keys are never printed.
- Updates APP_URL and ASSET_URL, uses host-only secure sessions, and adds canonical
  Laravel URL generation without replacing existing provider behavior.
- Preserves unrelated .env fields, all database records, mail identity and keys.
- Performs PHP lint before writes and reloads the existing PHP 8.2 FPM service.
- Clears compiled views only; it does not flush sessions or general application
  caches, migrate a database, or introduce config caching.
- Starts browser redirects as 302, checks HTTPS responses and rendered links, then
  promotes to 301 and checks again. Paths, escaping and query strings are preserved.
- Checks apex/public pages, the new www host, old apex redirects, API settings on
  both hosts, Android/iOS app-store redirects, generated URLs/assets, and GO payment
  route origins. No real checkout, charge or authenticated login is executed.

## Compatibility and follow-up

The old apex and www browser links redirect to the new canonical site. API paths,
payment/OAuth callback paths, signed-query URLs, certificate validation and
non-GET/HEAD requests are deliberately not externally redirected. Existing
installed clients and registered webhook endpoints must keep working until their
origins and provider registrations have been changed and verified. This is a
compatibility bridge, not a completed retirement of the old domain.

The reviewed order/wallet Paymob controller derives notification and redirect
URLs from app.url. GO-services payment routes use canonical URL generation.
Already-created payment sessions and external provider settings are preserved.

Mail sender setup/DNS authentication, stored database URL values (if any),
provider dashboards, released mobile/desktop clients, supervised queue worker
reloads and authenticated order/payment tests remain separate follow-up work.
The script reports running queue processes without terminating unsupervised work.
It cannot verify or update an installed app binary or third-party provider setting.

## Inventory and tests

`audit.py` is read-only: no Laravel boot, database access, network or source edits.
It reports hashes/counts and selected non-secret settings; source text and URL
paths/queries are omitted.

Run `python3 -m unittest discover -s deployment/domain-migration-20261117 -p 'test_*.py' -v`.
The scoped GitHub Actions workflow installs Apache/PHP and runs real redirect
integration tests, PHP behavior checks, env/link preservation and rollback tests.
It fetches only this package, uses read-only repository permission and has no
production access.
