#!/usr/bin/env bash
# Run explicitly in the authorized hosting console, not through the deploy-only
# SSH key. Backups remain outside the public web directory. No gateway settings
# or existing customer/partner wallet balances are edited by this launcher.
set -euo pipefail
umask 077
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$ROOT"
RUN=$(date -u +%Y%m%d%H%M%S)
PHP=$(command -v php)
test -f artisan && test -f .env
test "$(git rev-parse --show-toplevel)" = "$ROOT"
php -l deployment/go_service_release.php
php deployment/go_service_release.php inspect "$ROOT" "$RUN"
php deployment/go_service_release.php backup "$ROOT" "$RUN"
php deployment/go_service_release.php test "$ROOT" "$RUN"

# Install only this reviewed additive migration. Do not apply unrelated pending
# menu/price migrations as an accidental side effect of enabling GO services.
php artisan migrate --force --path=database/migrations/2026_09_26_090000_create_go_service_marketplace.php
php artisan optimize:clear
php artisan route:list --path=go-services | grep -F 'go-services/capabilities'
php artisan schedule:list | grep -F 'go-services:dispatch'

# Confirm a scheduler for this exact app. If none is visible, stop rather than
# silently installing duplicate cron entries or promising timed dispatch.
SCHEDULED=0
for USERNAME in "$(id -un)" "$(stat -c %U "$ROOT")"; do
  if { crontab -u "$USERNAME" -l 2>/dev/null || true; } | awk -v root="$ROOT" '
    $0 !~ /^[[:space:]]*#/ && index($0,root) && /artisan[[:space:]]+(schedule:run|schedule:work|go-services:dispatch)/ { found=1 }
    END { exit !found }'; then SCHEDULED=1; fi
done
if [[ "$SCHEDULED" != 1 ]]; then
  echo 'STOP: the scheduler for this application was not confirmed in crontab.' >&2
  echo 'Have the server administrator verify its existing cron/systemd scheduler before activation.' >&2
  echo 'No GO feature flag was enabled.' >&2
  exit 1
fi

ARMED=0
rollback_flag() {
  status=$?
  if [[ "$status" != 0 && "$ARMED" == 1 ]]; then
    php deployment/go_service_release.php disable "$ROOT" "$RUN" || true
    php artisan optimize:clear || true
  fi
  exit "$status"
}
trap rollback_flag EXIT
ARMED=1
php deployment/go_service_release.php activate "$ROOT" "$RUN"
php artisan optimize:clear
curl --fail --silent --show-error --connect-timeout 10 --max-time 30 \
  -H 'Accept: application/json' https://fasakhaninja.com/api/go-services/capabilities |
  php -r '$r=json_decode(stream_get_contents(STDIN),true);$d=$r["data"]??[];if(($r["status"]??null)!=="Success"||($d["schema_ready"]??false)!==true||($d["enabled"]??false)!==true||!in_array("cash",$d["payment_methods"]??[],true)||!in_array("wallet",$d["payment_methods"]??[],true)){fwrite(STDERR,"Public GO activation could not be verified.\n");exit(1);}echo "PUBLIC GO CASH/WALLET MARKETPLACE ENABLED\n";'
ARMED=0
trap - EXIT
echo 'Launch verified. Dedicated card/mobile-wallet/Apple Pay/Google Pay checkout was not enabled.'
echo 'Existing payment integrations remain unchanged. No live test charges were made.'
