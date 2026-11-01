#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_release="$1"
test "$2" = --apply
[[ "$go_release" =~ ^[0-9a-f]{40}$ ]]

go_stage=$(mktemp -d /var/tmp/go-independent-owners.XXXXXXXX)
trap 'rm -rf -- "$go_stage"' EXIT
chmod 0755 "$go_stage"
for go_file in install_root.py worker.py manifest.json central_setup.php tenant_http.php tenant_console.php; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_release/deployment/owner_dashboards/$go_file" \
        -o "$go_stage/$go_file"
done
printf '%s  %s\n' \
    6b1c996221f3810730a76d8df2da1fdf7abf993cf19c100ddaf8b2e5f4125d2d "$go_stage/install_root.py" \
    70ded7a038b422da6697e48829ca6676deabec3a9f2b232aa6dc3b795eef356f "$go_stage/worker.py" \
    968617f2419ff0e54eb65f07dd79fa544a4a06baabbac375bc3af1d9b103f483 "$go_stage/manifest.json" \
    aaafdab079dc8c1ae59ed773f1d5cf6d7382163e9f55420b2d3c5c2a35f63ea4 "$go_stage/central_setup.php" \
    45adcae4adae5c352773d9c2f489c28267275c4ae79ca985a4643e54633805e0 "$go_stage/tenant_http.php" \
    03fddfb060cd5ddc2f3f670b486dec7263a325b52f981d1b3a57f455008664b3 "$go_stage/tenant_console.php" \
    | sha256sum --check --status
python3 -I -B "$go_stage/install_root.py" --apply
