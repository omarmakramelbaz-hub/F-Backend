#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$#" -eq 2
surplus_rev=$1
surplus_mode=$2
[[ "$surplus_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$surplus_mode" == --apply || "$surplus_mode" == --check ]]
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
surplus_root=/home/fasakha/public_html
test ! -L "$surplus_root"
test "$(realpath "$surplus_root")" = "$surplus_root"
test "$(stat -c '%U' "$surplus_root")" = fasakha
test -f "$surplus_root/artisan"
test -f "$surplus_root/vendor/autoload.php"
surplus_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$surplus_php" || surplus_php=$(command -v php)
"$surplus_php" -r 'exit(PHP_VERSION_ID>=80100 && function_exists("getimagesize") ? 0 : 1);'
surplus_stage=$(mktemp -d /var/tmp/fasakhansta-shift-surplus.XXXXXXXX)
trap 'rm -rf -- "$surplus_stage"' EXIT
chmod 0755 "$surplus_stage"
install -d -m 0755 "$surplus_stage/assets"
while read -r surplus_hash surplus_file; do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --connect-timeout 20 --max-time 120 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$surplus_rev/deployment/shift-surplus-menu-20261106/$surplus_file" \
    -o "$surplus_stage/$surplus_file"
  printf '%s  %s\n' "$surplus_hash" "$surplus_stage/$surplus_file" | sha256sum --check --status
  chmod 0444 "$surplus_stage/$surplus_file"
done <<'MANIFEST'
af3eed3e1cb12378f4e26d14f9336028e22744938c9d8c6db7e54b25725e6764 add_menu.php
7320036d9d49df61ac44349980b71dfa397ba664d935c9741fcaacb0be7a4375 test.php
3004ded40f36593ac76e178aaadb263d82276bf980c5301c4fe3836428424aca assets.json
ff2ec965e1dd6753a35375fdc58363488e2d692e782e6f070a3a7949c93df37c assets/egp-1.jpg
b86e6c127e6b16a1c179adeb5490a11454559f5e508b09b341837d50420e6fc9 assets/egp-5.jpg
35c005e91ea546a536d64a8a23344129bde159fb04c083378b56b5ddb456847b assets/egp-10.jpg
448fb141ebdd1e3ef26cdace0aa6c861ab889a765aa74f2987755bb7ca4a4d6f assets/egp-100.jpg
MANIFEST
"$surplus_php" -l "$surplus_stage/add_menu.php" >/dev/null
"$surplus_php" -l "$surplus_stage/test.php" >/dev/null
runuser -u fasakha -- "$surplus_php" "$surplus_stage/test.php"
runuser -u fasakha -- "$surplus_php" "$surplus_stage/add_menu.php" --check
if [[ "$surplus_mode" == --check ]]; then exit 0; fi
runuser -u fasakha -- "$surplus_php" "$surplus_stage/add_menu.php" --apply
runuser -u fasakha -- "$surplus_php" "$surplus_stage/add_menu.php" --check > "$surplus_stage/verified.json"
"$surplus_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($r["status"]??"")==="ready"&&($r["values_egp"]??[])===[1,5,10,100]&&($r["branch_store_count"]??0)>=4&&($r["expected_menu_items"]??0)===4*$r["branch_store_count"]&&($r["missing_catalog_items"]??1)===0&&($r["missing_menu_items"]??1)===0&&($r["missing_images"]??1)===0?0:1);' "$surplus_stage/verified.json"
cat "$surplus_stage/verified.json"
echo 'SHIFT_SURPLUS_MENU_APPLIED_AND_VERIFIED'
