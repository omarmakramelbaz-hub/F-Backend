#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524

checkout_rev=${1:?Usage: run_root.sh FULL_COMMIT_SHA [--apply | --rollback BACKUP_PATH]}
shift
[[ "$checkout_rev" =~ ^[a-f0-9]{40}$ ]]
checkout_run=$(mktemp -d /var/tmp/paymob-checkout.XXXXXXXX)
trap 'rm -rf -- "$checkout_run"' EXIT

for checkout_file in publish.py checking_out.php.template; do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$checkout_rev/deployment/paymob_checkout_speed_20261112/$checkout_file" \
    -o "$checkout_run/$checkout_file"
done

printf '%s  %s\n' \
  d9c0f96d940e36d768329504a10cc537259b29c0673a5697768f3e267be24ff9 "$checkout_run/publish.py" \
  52aca681dfad03927021f168b8878391fc27f4679957b5360c2c68b4c55492ac "$checkout_run/checking_out.php.template" | sha256sum --check
python3 "$checkout_run/publish.py" "$@"
