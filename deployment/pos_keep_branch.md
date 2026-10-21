# Keep the selected POS branch between empty invoices

Empty, unsold invoice tabs inherit the currently selected branch when opened. Existing draft,
completed, uncertain and quarantined invoices retain their original branch and sale state.
The existing all-branches default when initially opening the POS is retained.

The installer inserts only the reviewed `switchInvoice` guard into the live
`public/dashboard/js/takeaway-pos.js`. Other live source differences are preserved. A missing
or ambiguous anchor blocks installation. The release source must contain exactly that one
block change compared with `2ce66fdb11030c54593f1d6941d7354936077c4a`.

After fetching the pinned release revision, extract and verify
`deployment/apply_pos_keep_branch_root.sh`, then run it as root on `srv1836524` with the
full revision as its first argument. Add `--check` for preflight only.

The live JavaScript is backed up outside the web root under
`/home/fasakha/pos-keep-branch-backups/` before atomic replacement. The receipt records the
original metadata and both hashes. No application bootstrap, database or printing operation
is performed. Node syntax validation runs when Node is available; otherwise the installer
reports that local parse validation is unavailable. Refresh the dashboard after installation.
