# faskhansta.com migration inventory

The new apex and www domains already serve the production Laravel site over HTTPS.
This inventory is the prerequisite for moving all site links to the new canonical
domain: production contains additional source files beyond the default branch.
Do not deploy a checkout of the default branch over that application.

Run `python3 audit.py` on srv1836524. The program reads the known application
directory and outputs filenames, SHA-256 fingerprints, domain reference counts,
and selected non-secret domain settings. It does not boot Laravel, run Artisan,
access the database, make network requests, or modify application files. Source
lines, URL paths and queries, and credentials are not emitted. Symlinks and upload
directories are excluded. Large files and output truncation are explicitly reported.

Test with `python3 -m unittest discover -s deployment/domain-migration-20261117 -p 'test_*.py'`.

The migration remains pending until production output is reviewed. Its next steps
are to update application URL generation, literal site/asset links and cookie
settings; verify login/cart behavior and callback URLs; and redirect old browser
links. Existing customer clients still contain the old API base URL, so blanket
redirects must not be enabled before reviewing API/webhook/signed-link handling.
No customer app release, provider callback change, or live payment is performed
by this inventory.
