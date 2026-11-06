#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$#" -eq 2
consent_rev=$1
consent_mode=$2
[[ "$consent_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$consent_mode" == --apply || "$consent_mode" == --check ]]
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
consent_root=/home/fasakha/public_html
test ! -L "$consent_root"
test "$(realpath "$consent_root")" = "$consent_root"
test "$(stat -c '%U' "$consent_root")" = fasakha
test -f "$consent_root/artisan"
test -f "$consent_root/vendor/autoload.php"
consent_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$consent_php" || consent_php=$(command -v php)
"$consent_php" -r 'exit(PHP_VERSION_ID>=80100&&extension_loaded("mbstring")?0:1);'
consent_stage=$(mktemp -d /var/tmp/fasakhansta-xls-consent.XXXXXXXX)
trap 'rm -rf -- "$consent_stage"' EXIT
chmod 0755 "$consent_stage"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$consent_rev/deployment/customer-xls-consent-20261106/consent.php" \
  -o "$consent_stage/consent.php"
printf '%s  %s\n' d43c31091f55c4e7f4d1e4852357d159d6c7c9ca3461bf38526674fd809dc1d3 "$consent_stage/consent.php" | sha256sum --check --status
chmod 0444 "$consent_stage/consent.php"
"$consent_php" -l "$consent_stage/consent.php" >/dev/null
consent_args=(env -u XLS_CONSENT_TESTING "$consent_php" -d memory_limit=768M "$consent_stage/consent.php")
runuser -u fasakha -- "${consent_args[@]}" --check
if [[ "$consent_mode" == --check ]]; then exit 0; fi
runuser -u fasakha -- "${consent_args[@]}" --apply
runuser -u fasakha -- "${consent_args[@]}" --check > "$consent_stage/verified.json"
"$consent_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$old=$r["previous_application"]??[];exit(($r["status"]??"")==="already_applied"&&($old["counts"]["total"]??0)===37351&&($old["counts"]["service_eligible"]??0)===37351&&($old["counts"]["marketing_eligible"]??0)===37351?0:1);' "$consent_stage/verified.json"
cat "$consent_stage/verified.json"
echo 'CUSTOMER_XLS_CONSENT_APPLIED_AND_VERIFIED'
