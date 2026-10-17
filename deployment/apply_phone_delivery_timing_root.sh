#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
timing_root=/home/fasakha/public_html
timing_php=/opt/cpanel/ea-php82/root/usr/bin/php
timing_source=deployment/apply_phone_delivery_timing.py
[[ -x "$timing_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
timing_tmp=$(mktemp -d /tmp/phone-delivery-timing.XXXXXXXX)
trap 'rm -rf -- "$timing_tmp"' EXIT
chmod 0755 "$timing_tmp"

runuser -u fasakha -- git -C "$timing_root" cat-file -e "$revision^{commit}"
for timing_base in \
  a7d7b5572390bff8ca4b2503172cc63470370dae \
  6582750c2ef925500504a83d89141e49f0bd62a3; do
  if ! runuser -u fasakha -- git -C "$timing_root" cat-file -e "$timing_base^{commit}" 2>/dev/null; then
    runuser -u fasakha -- git -C "$timing_root" fetch \
      --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$timing_base"
    [[ $(runuser -u fasakha -- git -C "$timing_root" rev-parse FETCH_HEAD) = "$timing_base" ]] || exit 1
  fi
  [[ $(runuser -u fasakha -- git -C "$timing_root" rev-parse "$timing_base^{commit}") = "$timing_base" ]] || exit 1
done
timing_blob=$(runuser -u fasakha -- git -C "$timing_root" rev-parse "$revision:$timing_source")
[[ "$timing_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$timing_root" show "$revision:$timing_source" > "$timing_tmp/apply.py"
[[ $(git hash-object --no-filters "$timing_tmp/apply.py") = "$timing_blob" ]] || {
  printf '%s\n' 'PHONE_DELIVERY_TIMING_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$timing_tmp/apply.py"
runuser -u fasakha -- python3 "$timing_tmp/apply.py" \
  --root "$timing_root" --revision "$revision" --php "$timing_php"
