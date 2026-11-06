#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$#" -eq 2
policy_rev=$1
policy_mode=$2
[[ "$policy_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$policy_mode" == --apply || "$policy_mode" == --check ]]
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
policy_root=/home/fasakha/public_html
test ! -L "$policy_root"
test "$(realpath "$policy_root")" = "$policy_root"
test "$(stat -c '%U' "$policy_root")" = fasakha
test -f "$policy_root/vendor/autoload.php"
policy_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$policy_php" || policy_php=$(command -v php)
"$policy_php" -r 'exit(PHP_VERSION_ID>=80100&&extension_loaded("mbstring")?0:1);'
policy_stage=$(mktemp -d /var/tmp/fasakhansta-whatsapp-consent.XXXXXXXX)
trap 'rm -rf -- "$policy_stage"' EXIT
chmod 0755 "$policy_stage"
while read -r policy_hash policy_name policy_path; do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$policy_rev/$policy_path" \
    -o "$policy_stage/$policy_name"
  printf '%s  %s\n' "$policy_hash" "$policy_stage/$policy_name" | sha256sum --check --status
  chmod 0444 "$policy_stage/$policy_name"
  "$policy_php" -l "$policy_stage/$policy_name" >/dev/null
done <<'POLICY_MANIFEST'
c32dff9593e7a9d3ae310b20e6ced336ca65ce739f2460117034b8febffc5418 install.php deployment/whatsapp-contact-consent-20261106/install.php
3238b006a5a5692b69fd1548d3fcf697bc59a3bc68f6489f096c12465ab7e752 WhatsAppCustomerDirectory.php app/Services/Dashboard/WhatsAppCustomerDirectory.php
POLICY_MANIFEST
policy_args=(env -u WCP_TESTING "$policy_php" -d memory_limit=768M "$policy_stage/install.php")
runuser -u fasakha -- "${policy_args[@]}" --check "$policy_stage/WhatsAppCustomerDirectory.php"
if [[ "$policy_mode" == --check ]]; then exit 0; fi
runuser -u fasakha -- "${policy_args[@]}" --apply "$policy_stage/WhatsAppCustomerDirectory.php"
if systemctl is-active --quiet ea-php82-php-fpm.service; then
  systemctl reload ea-php82-php-fpm.service
fi
# Reconcile undecided contacts from any request already in flight during publication.
for policy_pass in 1 2 3; do
  runuser -u fasakha -- "${policy_args[@]}" --check "$policy_stage/WhatsAppCustomerDirectory.php" > "$policy_stage/verified.json"
  if "$policy_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($r["counts"]["changed"]??-1)===0?0:1);' "$policy_stage/verified.json"; then break; fi
  runuser -u fasakha -- "${policy_args[@]}" --apply "$policy_stage/WhatsAppCustomerDirectory.php"
done
runuser -u fasakha -- "${policy_args[@]}" --check "$policy_stage/WhatsAppCustomerDirectory.php" > "$policy_stage/verified.json"
"$policy_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($r["status"]??"")==="already_applied"&&($r["counts"]["changed"]??-1)===0?0:1);' "$policy_stage/verified.json"
cat "$policy_stage/verified.json"
echo 'WHATSAPP_CUSTOMER_CONSENT_POLICY_APPLIED_AND_VERIFIED'
