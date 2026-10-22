# Repair the existing public images that block GO ERP preparation

The observed deployment has 307 actual PNG/JPEG/WebP images with extensionless media filenames, two JPEG files named `.png`, and eight unavailable public image paths. The existing PC and PHP media validators require matching image extensions.

`deployment/repair_go_erp_media_root.sh --check` inspects the current Owner enrollment and plans repairs; `--apply` executes them. Neither mode replaces application source or an installer. Apply copies each verified original into a filename with the detected extension, retaining the original path and exact bytes. It changes only the corresponding `media.file_name`, `mime_type`, and `size` columns. Every other media field and every accounting table remain unchanged. Nonempty derivative metadata requires a separate review.

For the eight observed unavailable paths, the root helper checks only identical relative paths under the existing cPanel backup's two known homedir layouts. It never chooses another branch's same-named image. Different backup bytes are reported as ambiguous. A restore also requires the current media row's exact size and MIME and a matching filename extension. Unavailable or conflicting originals remain unresolved and continue to prevent preparation.

Before any database transaction, the application user durably writes all original rows, intended changes, byte hashes, and paths to a private directory below `/home/fasakha/go-erp-media-repair-backups`. Existing destination files must have identical bytes; links, executable extensions, non-image content, changed rows and changed source bytes are rejected. All media updates share one database transaction. Failed runs retain verified copied files and original source files for recoverable retries.

Apply prints `media_complete`, `issues_remaining`, counts and the backup path. Preparation should be retried only after `media_complete` is true. Completing the repair still requires initial preparation on the PC.

Tests reproduce all 309 repairs using actual PNG/JPEG/WebP bytes and SQLite, restore eight images without changing database rows, verify untouched originals and accounting, and exercise collision, link, content, conversion, concurrent edit and transaction rollback guards. The separate backup scanner tests exact paths, conflicting backups and symlink refusal. The CI workflow runs the PHP tests on PHP 8.2, matching the deployed PHP major/minor version.
