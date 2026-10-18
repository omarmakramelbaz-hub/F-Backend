#!/usr/bin/env bash
set -euo pipefail
umask 077

activity_revision=${1:?Full revision is required}
[[ "$activity_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
activity_root=/home/fasakha/public_html
activity_php=${FASAKHANSTA_PHP:-/opt/cpanel/ea-php82/root/usr/bin/php}
if [[ ! -x "$activity_php" ]]; then activity_php=$(command -v php || true); fi
activity_base=00e4f1f7df6a9eede836600f834cef6c33d55e26
activity_source=deployment/apply_order_live_activity.py
[[ -n "$activity_php" && -x "$activity_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
"$activity_php" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { printf '%s\n' 'PHP82_OR_NEWER_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$activity_root" cat-file -e "$activity_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$activity_root" cat-file -e "$activity_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$activity_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$activity_base"
fi
runuser -u fasakha -- git -C "$activity_root" merge-base --is-ancestor "$activity_base" "$activity_revision"
activity_blob=$(runuser -u fasakha -- git -C "$activity_root" rev-parse "$activity_revision:$activity_source")
[[ "$activity_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
activity_tmp=$(mktemp -d /tmp/order-live-activity.XXXXXXXX)
trap 'rm -rf -- "$activity_tmp"' EXIT
chmod 0755 "$activity_tmp"
runuser -u fasakha -- git -C "$activity_root" show "$activity_revision:$activity_source" > "$activity_tmp/apply.py"
[[ $(git hash-object --no-filters "$activity_tmp/apply.py") == "$activity_blob" ]] || {
  printf '%s\n' 'ORDER_LIVE_ACTIVITY_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$activity_tmp/apply.py"
activity_options=()
if [[ $# == 2 ]]; then activity_options+=(--check); fi
runuser -u fasakha -- python3 "$activity_tmp/apply.py" \
  --root "$activity_root" --revision "$activity_revision" --php "$activity_php" "${activity_options[@]}"
