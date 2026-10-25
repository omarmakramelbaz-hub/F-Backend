#!/usr/bin/env bash
# Download and verify an exact, owner-requested retention installation.
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_retention_rev=$1
test "$2" = --complete
[[ "$go_retention_rev" =~ ^[a-f0-9]{40}$ ]]

go_retention_stage=$(mktemp -d /var/tmp/go-erp-retention.XXXXXXXX)
trap 'rm -rf -- "$go_retention_stage"' EXIT
chmod 0700 "$go_retention_stage"

for go_retention_file in \
  go_erp_release_retention_root.py \
  apply_go_erp_release_retention_root.py \
  publish_go_erp_update.py
do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_retention_rev/deployment/$go_retention_file" \
    -o "$go_retention_stage/$go_retention_file"
done

printf '%s  %s\n' \
  'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293' "$go_retention_stage/go_erp_release_retention_root.py" \
  '683b2468dbbe44a3d5639d30d0c7529c0ffc197ec32c0d53c221db713faa5c07' "$go_retention_stage/apply_go_erp_release_retention_root.py" \
  '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490' "$go_retention_stage/publish_go_erp_update.py" \
  | sha256sum --check --status

/usr/bin/python3 "$go_retention_stage/apply_go_erp_release_retention_root.py" --complete
