# GO ERP Git read recovery

A root console fetch performed with umask 077 created an otherwise valid Git pack and remote tracking references owned only by root. The measured repository owner cannot read the objects. This recovery changes ownership of the exact checked pack triplet, validated root-owned remote tracking refs, and validated root-only 0700 directories beneath the origin tracking directory to fasakha, preserving bytes and modes. It checks object checksums, commit availability, unchanged HEAD/index/config/local refs, saves a private metadata audit, and verifies reads as the repository owner. It performs no checkout, reset, ref deletion, application write, or database access.

Fetch this recovery from a separate temporary bare repository, verify the exact commit and helper blob, and execute the helper from a private root-owned directory with --repair. Do not use the affected application repository to download the repair.

After repair, collect a fresh source identity report using the reviewed diagnose_go_erp_current_root.sh launcher (blob 5e592b2319b69c3c62b02c3f0008c6b5bab429a6) from release 8ad022856b9feacdc96a3449ff6d075c29bb2ae4 and its fixed argument 91364fbb1210a0834b9d7b05835a97b5307bec19. This launcher prints bounded file hashes and metadata without bootstrapping PHP or opening the database.

The newly installed POS branch-selection change modifies a protected source file. The old activation manifest must be reviewed against the new report before activation continues. Existing GO ERP release branches and the accepted Windows installer remain unchanged.

Validation uses real Git-generated pack, index, and reverse-index bytes, checksum validation, symlink/hardlink/race rejection, private audits, preservation of unrelated files, and idempotence. On UID0-only execution namespaces the ownership transition and actor permission checks use an explicit test adapter; unrestricted root Linux uses real ownership syscalls and runuser. The production helper always uses real syscalls and verifies reads as fasakha.
