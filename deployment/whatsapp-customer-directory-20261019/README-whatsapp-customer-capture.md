# WhatsApp customer directory capture

Every newly verified incoming WhatsApp sender with a valid telephone number is
created or merged into the shared customer directory. The caller identity comes
from the existing authenticated inbox ingestion. Outgoing echoes, status
receipts, and telephone numbers contained inside message bodies are excluded.

The sender's WhatsApp profile name fills an empty customer name only. Established
names, addresses, location pins, notes, consent, and opt-out preferences remain
intact. The source association is `whatsapp`, with an empty branch meaning a
shared customer. This is not a purchase or a delivery order and creates neither.

The existing inbox transaction includes customer capture. Duplicate messages keep
the existing replay guard, including the protection against replaying an old
opt-out after a newer consent decision. Existing feedback and status handling
remain byte-for-byte outside the one changed inbound method call.

## Deployment

The guarded root entry is pinned to `srv1836524` and `/home/fasakha/public_html`.
It stages the pinned release privately, then runs PHP as `fasakha`. It requires
the existing CRM schema and unique customer phone/source indexes. MySQL CRM
tables must use InnoDB. It performs no migration, outgoing message, or AI call.
The private legacy-customer ZIP is not needed for this WhatsApp deployment.

Run `bash apply_whatsapp_customer_capture_root.sh --check` for a read-only
application and database preflight, then use `--apply` to install. The release
accepts only the reviewed `CustomerMessageEvents::inbound` method or its exact
already-installed replacement; unrelated methods are retained. An unknown
target method or durable inbox callback stops before code writes. A pre-existing
different `WhatsAppCustomerDirectory.php` is never overwritten.

The new service is installed before its inbound hook. Both targets use atomic
publication with checks immediately before replacement. An installation failure
restores its own exact hook backup when the current file still matches this
release. Rerunning an installed release is safe.

The customer-list frontend receives two reviewed, unique expression replacements
for the WhatsApp label and global-customer help. Every byte outside those edits
is preserved, including the later follow-up template setup. Each expression must
match its exact before or after form once; missing or ambiguous anchors are
refused. Backend editing still uses the exact reviewed method guard. If lookup
or capture installation fails later, the wrapper restores only the UI and lookup
changes made by that run, using their exact private receipts. It retains changes
that were already installed before the run.

The success record includes the private backup directory. Exact code rollback
is available through the staged installer as `fasakha`:

```bash
runuser -u fasakha -- php PRIVATE_STAGE/install_whatsapp_customer_capture.php \
  --root /home/fasakha/public_html --apply --restore-from PRIVATE_BACKUP_RUN
```

The rollback refuses later changes. It does not delete already captured customer
records. The root entry also installs unified delivery phone lookup V3, including
the explicit global WhatsApp source. The customer list labels this source in
Arabic and allows staff with existing edit permission to complete its profile.
No historical inbox replay is included; capture applies to newly ingested
messages after installation.

The combined `apply_customer_directory_and_whatsapp_root.sh` first installs
WhatsApp capture, customer editing, and delivery lookup. If the pinned private ZIP
exists at `/home/fasakha/Fasakhansta-Customer-Import-20261018.zip`, it then runs the
separate guarded legacy importer. If missing, it prints `CUSTOMER_IMPORT_PENDING`
after WhatsApp succeeds. Upload the private ZIP outside `public_html`, then rerun
the same combined command. Already installed code remains unchanged.
