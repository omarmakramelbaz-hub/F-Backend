# Customer import

The public code folder contains no customer data. The guarded root entry reads the original private V1 ZIP only as a data source, verifies its exact SHA-256, and extracts only its pinned `data/customers.json`. It never runs V1 scripts. Place the original ZIP at `/root/Fasakhansta-Customer-Import-20261018.zip`, then run the pinned code entry from the root terminal:

```bash
bash apply_customer_directory_import_root.sh --check
bash apply_customer_directory_import_root.sh --apply
```

Use `--input-zip /private/path/original.zip` for another private archive path. The entry is pinned to server `srv1836524` and the application owner `fasakha`. No network fetch or production Git checkout is performed by this entry.

This bundle imports the prepared customer extract. It preserves every raw customer and every phone/address profile in `legacy_customer_import_*` tables. It adds valid canonical phones to CRM and fills only empty, unambiguous fields. Populated fields, messaging consents, purchases and message queues are preserved. Source coordinates are retained in profiles when provided; this extract has no usable coordinates.

**V2 enables employee delivery lookup from the unified customer directory.** Authorized Fasakhansta branch employees can search matching phone numbers and choose CRM or imported address profiles, including contacts from other Fasakhansta branches and global imported contacts. Saved branch profiles keep priority; directory/import alternatives precede old order history. GS-only identities are excluded from the new directory fallback, and this fallback is disabled in GS-only scopes. Bulk customer lists, branch selection, local customer ID resolution and order permissions retain their existing authorization.

Imported records remain global directory contacts; the old system's seven branch IDs have not been mapped to current branch IDs. No mapping is inferred. `--branch f:ID` assigns the entire dataset to one verified existing branch and must only be used with a deliberately prepared branch-specific dataset; do not guess an ID.

Extract the bundle into a private directory **outside** `/home/fasakha/public_html`. From a root terminal, the wrapper stages a private copy under `/home/fasakha/customer-imports/`, assigns ownership of that copy to `fasakha`, then runs PHP as `fasakha`. It never changes live application ownership or boots Laravel as root. Direct execution as the `fasakha` owner also works. Missing accounts, unexpected root ownership or unsafe staging paths stop before bootstrap. Use the site's PHP 8.1+ CLI and normal Laravel environment. The wrapper grants PHP a 1024 MiB memory limit for this extract. Review the check counts before applying:

```bash
bash INSTALL.sh --check
bash INSTALL.sh --apply
```

For a different PHP binary or private backup directory:

```bash
bash INSTALL.sh --check --php /path/to/php
bash INSTALL.sh --apply --php /path/to/php --backup-dir /home/fasakha/legacy-customer-import-backups
```

`--check` changes no application code, database schema or data. Root execution may create its private staging copy. It validates the pinned extract hash, target CRM schema, branch scope, known phone-lookup source and candidate PHP syntax. Actual CRM insert/enrichment counts depend on the current database.

`--apply` repeats both checks, backs up and installs the minimal phone-lookup insertion, then imports all customer/provenance rows in one transaction. New import table DDL precedes that transaction. Failed imports may leave empty import tables. A failed customer transaction triggers restoration of a newly installed lookup only while it still matches this release. Pre-existing lookup installations are retained. Private snapshots use directory mode 0700 and file mode 0600.

V2 adds a standalone `phone_key` index to the retained import profiles, including an index-only upgrade when a V1 dataset was already imported. Dry-run reports `lookup_phone_index_pending` and performs no DDL. This avoids scanning shared profile rows on each phone suggestion request. No schema change occurs inside the lookup endpoint.

The lookup installer accepts the reviewed `PosServicePhone.php` from commit `9984a1f6ee065ced2fcc5eefcec5bd2f599914df`, the exact V1 import lookup, or this exact V2 insertion. It upgrades V1 without duplicating fallback code and can restore its verified V1 backup after a failed import. An unknown live version stops before data application and needs compatibility review. It preserves branch authorization, regular customer matches, the eight-result cap and local customer ID resolution. Additional imported alternatives can exceed the eight displayed results.

Repeated application of the same extract is idempotent. A recorded dataset cannot silently change branch scope. Source phone conflicts retain all alternatives and do not project conflicting names or addresses into CRM. Invalid/ambiguous phones remain in immutable raw/profile provenance and do not become CRM contacts.

Direct importer flags: `--root PATH --data PATH [--dry-run | --apply --backup-dir PATH] [--branch f:ID] [--expected-data-sha256 HEX]`. The wrapper defaults root to `/home/fasakha/public_html`, backup directory to `/home/fasakha/legacy-customer-import-backups`, and extract to `data/customers.json`. A custom `--data` requires an explicit `--expected-data-sha256`.

Do not upload customer extracts, backup snapshots or this private bundle into a publicly served directory. Counts and hashes are safe to share; the saved snapshots contain customer data.

## WhatsApp-compatible lookup release

This release installs unified lookup V3 and recognizes the explicit global `whatsapp` source. The guarded installer accepts exact original, V1, V2, and current V3 phone lookup sources. It preserves the exact predecessor on rollback. The root entry defaults to the private ZIP at `/home/fasakha/Fasakhansta-Customer-Import-20261018.zip`; the ZIP contents and customer JSON hashes remain unchanged. Automatic WhatsApp sender capture is a separate code-only deployment and does not require this ZIP.
