# Explicit substitutes for eight unavailable public images

The operator's server receipt confirms that the earlier repair committed 309
extension corrections (`normalized: 309`, `database_committed: true`). It did
not restore any original image. The exact-path scan found none of the remaining
eight images in the two inspected cPanel backup storage locations.

This separate tool proposes a visible substitute saying **الصورة الأصلية غير متاحة**
and **يمكن إضافة الصورة الأصلية لاحقًا** at exactly those eight paths: six pending
vendor media files and two user profile media files. These are substitutes,
not recovered originals. Obtain the operator's agreement to the displayed
preview before executing the apply mode. Preparing this branch does not modify
the production server.

Preview: [deployment/assets/unavailable-image.png](../deployment/assets/unavailable-image.png).
Both encodings are 360×240 pixels. The tool pins the SHA-256, byte count and
detected MIME of each encoding. JPEG paths receive actual JPEG data; the PNG
path receives actual PNG data. The original filenames, media IDs, model links,
collections, custom properties and timestamps are retained. Only `mime_type`
and `size` are updated to describe the substitute bytes. No records are deleted.

The root entry point is `deployment/fill_go_erp_missing_images_root.sh`.
Its default `--check` mode reads only and does not create a backup directory.
The separately named `--apply-missing-placeholders` mode is an explicit operator
choice. The wrapper requires host `srv1836524`, the expected fasakha-owned
application and private backup permissions, then executes PHP 8.2 as `fasakha`.
Package directories must be traversable by that account and files readable.
Pin and verify the entire Git revision before extracting the package.

The application CLI validates the active owner-1 device, its actor's full
administration access, enrolled branches, default media path generator, public
local disk roots and each exact media row's identity/model/disk/path. It reads
only the eight target media rows instead of materializing the dashboard dataset.
All targets are planned before any changes. Any existing image other than the
exact reviewed placeholder causes refusal, including an original restored or
uploaded after the initial diagnosis. Symlinks and changed source bytes are
rejected. Original files are never overwritten. Identical placeholders left by
an interrupted run can be reused.

The tool archives every complete original media row, destination, source hash
and explicit substitute provenance under
`/home/fasakha/go-erp-missing-image-backups/<run>/before.json` before changes.
Rows are locked and compared inside a single database transaction. Receipts
`committed.json` or `failed.json` record the outcome. A failed transaction may
leave known placeholder files, with original metadata retained in the audit;
the retry validates those exact bytes. A later real original must be handled
using the ordinary media upload flow or a separately reviewed restoration using
the archived metadata, not by rerunning this tool over the original.

Successful application verifies the eight files and their current metadata.
It deliberately returns `offline_ready: false`: other dashboard content may have
changed. Open GO ERP online and choose **Program > Prepare for offline work**,
wait for success, then verify operation after disconnecting. No write request is
retried, and no offline completeness or financial result is inferred from an
image repair receipt.

Validation: real JPEG/PNG bytes and SQLite transactions exercise the eight
allowlisted paths, preserved fields and unrelated data, private durable audit,
read-only planning, retries, tampered assets, symlinks, concurrent original
uploads and later-row rollback. The existing 309-image repair tests remain
required. The GitHub Actions workflow runs these tests on PHP 8.2; local tests
also use PHP 8.3. No production database or desktop preparation is exercised by
these fixture tests.
