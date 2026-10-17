#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
refresh_root=/home/fasakha/public_html
refresh_php=/opt/cpanel/ea-php82/root/usr/bin/php
refresh_source=deployment/apply_phone_delivery_refresh.py
[[ -x "$refresh_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
refresh_tmp=$(mktemp -d /tmp/phone-delivery-refresh.XXXXXXXX)
trap 'rm -rf -- "$refresh_tmp"' EXIT
chmod 0755 "$refresh_tmp"

runuser -u fasakha -- git -C "$refresh_root" cat-file -e "$revision^{commit}"
for refresh_base in \
  74a0070907d9ffbaa0f3b5aa7b6082bc1c345878 \
  4d20f13dc3dd2b64d63660d09ad88553c7f3affe \
  6df7fecf05555c9f88ddf85013bd7aa887f3dbb0 \
  cab9f03a8449ea47b3e6a3b5fc965247e5a143e8 \
  9f4cce8792467d1899927066f2c951cb2fa10cb5 \
  4189747822205224ced35b7980abe511af534540 \
  6582750c2ef925500504a83d89141e49f0bd62a3 \
  642dbfe4be0dc1097435bdb81e2606804b8b8955; do
  if ! runuser -u fasakha -- git -C "$refresh_root" cat-file -e "$refresh_base^{commit}" 2>/dev/null; then
    runuser -u fasakha -- git -C "$refresh_root" fetch \
      --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$refresh_base"
    [[ $(runuser -u fasakha -- git -C "$refresh_root" rev-parse FETCH_HEAD) = "$refresh_base" ]] || exit 1
  fi
  [[ $(runuser -u fasakha -- git -C "$refresh_root" rev-parse "$refresh_base^{commit}") = "$refresh_base" ]] || exit 1
done
refresh_blob=$(runuser -u fasakha -- git -C "$refresh_root" rev-parse "$revision:$refresh_source")
[[ "$refresh_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$refresh_root" show "$revision:$refresh_source" > "$refresh_tmp/apply.py"
[[ $(git hash-object --no-filters "$refresh_tmp/apply.py") = "$refresh_blob" ]] || {
  printf '%s\n' 'PHONE_DELIVERY_REFRESH_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$refresh_tmp/apply.py"
runuser -u fasakha -- python3 "$refresh_tmp/apply.py" \
  --root "$refresh_root" --revision "$revision" --php "$refresh_php"
