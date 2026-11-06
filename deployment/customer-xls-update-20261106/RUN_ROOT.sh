#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$#" -eq 2 || { echo 'Usage: RUN_ROOT.sh COMMIT --apply|--check' >&2; exit 1; }
xls_rev=$1
xls_mode=$2
[[ "$xls_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$xls_mode" == --apply || "$xls_mode" == --check ]]
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -f /home/fasakha/public_html/artisan
test -f /home/fasakha/public_html/vendor/autoload.php
test ! -L /home/fasakha/public_html
id fasakha >/dev/null
xls_php=/opt/cpanel/ea-php82/root/usr/bin/php
if ! test -x "$xls_php"; then xls_php=$(command -v php); fi
"$xls_php" -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("openssl") && extension_loaded("zlib") && extension_loaded("mbstring") ? 0 : 1);'
xls_code=$(mktemp -d /var/tmp/fasakhansta-customer-xls.XXXXXXXX)
trap 'rm -rf -- "$xls_code"' EXIT
chmod 0755 "$xls_code"
while read -r xls_hash xls_file; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --connect-timeout 20 --max-time 180 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$xls_rev/deployment/customer-xls-update-20261106/$xls_file" \
    -o "$xls_code/$xls_file"
  printf '%s  %s\n' "$xls_hash" "$xls_code/$xls_file" | sha256sum --check --status
  chmod 0444 "$xls_code/$xls_file"
done <<'MANIFEST'
7280f491050c34fad9b1259629c88ebabf88a6c2afc0ec4bbc134c8db716498f import_customers.php
f42e211f1f8fa2fbb09df4c30d4325572ec34d8e5960318edff96a2615cb4147 unpack_customers.php
cea2f02f431752d58fe446a517e653c1bb74870b3a0c2bf69130f9016088f0e6 metadata.json
7e7ad606b774734117ab3fddabbb63f3e2c72a746ff87d4df1438e525b0828b7 payload.b64
MANIFEST
"$xls_php" -l "$xls_code/import_customers.php" >/dev/null
"$xls_php" -l "$xls_code/unpack_customers.php" >/dev/null
test ! -L /home/fasakha/customer-imports
if ! test -d /home/fasakha/customer-imports; then
  install -d -m 0700 -o fasakha -g "$(id -gn fasakha)" /home/fasakha/customer-imports
fi
test "$(stat -c '%U' /home/fasakha/customer-imports)" = fasakha
xls_data=$(mktemp -d /home/fasakha/customer-imports/xls-update-20261106.XXXXXXXX)
chown "fasakha:$(id -gn fasakha)" "$xls_data"
chmod 0700 "$xls_data"
runuser -u fasakha -- "$xls_php" -d memory_limit=1536M "$xls_code/unpack_customers.php" \
  "$xls_code/payload.b64" "$xls_code/metadata.json" "$xls_data/customers.json"
xls_args=(--root /home/fasakha/public_html --data "$xls_data/customers.json" --expected-data-sha256 3d4ac4be1e0b4aebdcb564c60e767716f3e9b1d602d2fe8657724dd9c32ace19)
runuser -u fasakha -- "$xls_php" -d memory_limit=1536M "$xls_code/import_customers.php" \
  "${xls_args[@]}" --dry-run | tee "$xls_data/check.json"
if [[ "$xls_mode" == --check ]]; then echo 'CUSTOMER_XLS_CHECK_COMPLETE'; exit 0; fi
runuser -u fasakha -- "$xls_php" -d memory_limit=1536M "$xls_code/import_customers.php" \
  "${xls_args[@]}" --apply --backup-dir /home/fasakha/legacy-customer-import-backups | tee "$xls_data/applied.json"
runuser -u fasakha -- "$xls_php" -d memory_limit=1536M "$xls_code/import_customers.php" \
  "${xls_args[@]}" --dry-run | tee "$xls_data/verified.json"
"$xls_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($r["status"]??"")==="already_imported"?0:1);' "$xls_data/verified.json"
echo 'CUSTOMER_XLS_UPDATE_APPLIED_AND_VERIFIED'
