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
  a2a17e9241bbb3b84669141ffd199f51e3e682ea1b8f0a4d612afa17d296291f "$checkout_run/publish.py" \
  34c4b44b440afd71c810f7913f300e288d2478e3630c97a09704b2ec752d0cf5 "$checkout_run/checking_out.php.template" | sha256sum --check
python3 "$checkout_run/publish.py" "$@"
