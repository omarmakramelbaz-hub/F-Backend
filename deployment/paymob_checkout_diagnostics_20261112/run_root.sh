#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524

diagnostic_rev=${1:?Usage: run_root.sh FULL_COMMIT_SHA}
[[ "$diagnostic_rev" =~ ^[a-f0-9]{40}$ ]]
test "$#" -eq 1
diagnostic_run=$(mktemp -d /var/tmp/paymob-readonly.XXXXXXXX)
trap 'rm -rf -- "$diagnostic_run"' EXIT

for diagnostic_file in diagnostics.py publish.py checking_out.php.template; do
  if [[ "$diagnostic_file" = diagnostics.py ]]; then
    diagnostic_package=paymob_checkout_diagnostics_20261112
  else
    diagnostic_package=paymob_checkout_speed_20261112
  fi
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$diagnostic_rev/deployment/$diagnostic_package/$diagnostic_file" \
    -o "$diagnostic_run/$diagnostic_file"
done
printf '%s  %s\n' \
  845ab7f71f1b8ec0c75188232364018e40e63f48f4c4fb10ebed40f66dfc780e "$diagnostic_run/diagnostics.py" \
  ddb275eb550d554e6ae2abd3f42d06fadaea23cd0407147ee3ffe0af382896b9 "$diagnostic_run/publish.py" \
  52aca681dfad03927021f168b8878391fc27f4679957b5360c2c68b4c55492ac "$diagnostic_run/checking_out.php.template" | sha256sum --check
python3 "$diagnostic_run/diagnostics.py"
