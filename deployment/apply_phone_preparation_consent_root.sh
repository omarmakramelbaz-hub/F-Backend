#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
phone_root=/home/fasakha/public_html
phone_php=/opt/cpanel/ea-php82/root/usr/bin/php
phone_prior_revision=43dd121c06c37076a086e452a21b32d151df91f9
phone_prior_source=deployment/apply_pos_first_print_policy.py
phone_prior_blob=95aca9602e365d9ffca93275bd811c412daa604a
phone_source=deployment/apply_phone_preparation_consent.py
[[ -x "$phone_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
phone_tmp=$(mktemp -d /tmp/phone-preparation-consent.XXXXXXXX)
trap 'rm -rf -- "$phone_tmp"' EXIT
chmod 0755 "$phone_tmp"

runuser -u fasakha -- git -C "$phone_root" cat-file -e "$revision^{commit}"
runuser -u fasakha -- git -C "$phone_root" merge-base --is-ancestor "$phone_prior_revision" "$revision"
phone_blob=$(runuser -u fasakha -- git -C "$phone_root" rev-parse "$revision:$phone_source")
[[ "$phone_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$phone_root" show "$revision:$phone_source" > "$phone_tmp/apply.py"
[[ $(git hash-object --no-filters "$phone_tmp/apply.py") = "$phone_blob" ]] || {
  printf '%s\n' 'PHONE_INSTALLER_BLOB_MISMATCH'
  exit 1
}
[[ $(runuser -u fasakha -- git -C "$phone_root" rev-parse "$phone_prior_revision:$phone_prior_source") = "$phone_prior_blob" ]] || {
  printf '%s\n' 'FIRST_PRINT_RELEASE_BLOB_MISMATCH'
  exit 1
}
runuser -u fasakha -- git -C "$phone_root" show "$phone_prior_revision:$phone_prior_source" > "$phone_tmp/first-print.py"
[[ $(git hash-object --no-filters "$phone_tmp/first-print.py") = "$phone_prior_blob" ]] || {
  printf '%s\n' 'FIRST_PRINT_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$phone_tmp/apply.py" "$phone_tmp/first-print.py"

# A full exact-hunk proof also recognizes the prerequisite after phone hunks
# overlap it, so reruns do not try to reinstall the earlier source revision.
if runuser -u fasakha -- python3 "$phone_tmp/apply.py" \
    --root "$phone_root" --revision "$revision" --php "$phone_php" --check-first-print; then
  :
else
  phone_prerequisite_status=$?
  [[ "$phone_prerequisite_status" = 2 ]] || exit "$phone_prerequisite_status"
  runuser -u fasakha -- python3 "$phone_tmp/first-print.py" \
    --root "$phone_root" --revision "$phone_prior_revision" --php "$phone_php"
fi
runuser -u fasakha -- python3 "$phone_tmp/apply.py" \
  --root "$phone_root" --revision "$revision" --php "$phone_php"
