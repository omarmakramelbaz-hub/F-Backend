#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$#" -eq 2
net_rev=$1
net_mode=$2
[[ "$net_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$net_mode" == --apply || "$net_mode" == --check ]]
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
net_root=/home/fasakha/public_html
test ! -L "$net_root"
test "$(realpath "$net_root")" = "$net_root"
test "$(stat -c '%U' "$net_root")" = fasakha
test -f "$net_root/artisan"
test -f "$net_root/vendor/autoload.php"
net_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$net_php" || net_php=$(command -v php)
"$net_php" -r 'exit(PHP_VERSION_ID>=80100 && PHP_INT_SIZE===8 && function_exists("getimagesize") && function_exists("ctype_digit") ? 0 : 1);'
net_stage=$(mktemp -d /var/tmp/fasakhansta-net-fillet.XXXXXXXX)
trap 'rm -rf -- "$net_stage"' EXIT
chmod 0755 "$net_stage"
while read -r net_hash net_file; do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 90 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$net_rev/deployment/net-fillet-menu-20261114/$net_file" \
    -o "$net_stage/$net_file"
  printf '%s  %s\n' "$net_hash" "$net_stage/$net_file" | sha256sum --check --status
  chmod 0444 "$net_stage/$net_file"
done <<'MANIFEST'
ea76afa63e978c7d32467f2e0f60a895dababc399ce79c79c5f9e0a05feb44ea add_menu.php
59e6b26f9cbea3ff554cc809b30e42da70251495e5080f89d0de148c274f3439 recipe_contract.php
f9d1d34146e6a0bf1a1cd2351b936cabf8d0385cca7b84b5421edd97154ff6bf test_menu.php
3e5ec31452a37e0c780541d21350a9b34b501acf0e4a63fbfb6acbc930dfeebe test_recipe_contract.php
MANIFEST
for net_file in add_menu.php recipe_contract.php test_menu.php test_recipe_contract.php; do
  "$net_php" -l "$net_stage/$net_file" >/dev/null
done
runuser -u fasakha -- "$net_php" "$net_stage/test_recipe_contract.php"
runuser -u fasakha -- "$net_php" "$net_stage/test_menu.php"
runuser -u fasakha -- "$net_php" "$net_stage/add_menu.php" --check
if [[ "$net_mode" == --check ]]; then exit 0; fi
runuser -u fasakha -- "$net_php" "$net_stage/add_menu.php" --apply
runuser -u fasakha -- "$net_php" "$net_stage/add_menu.php" --check > "$net_stage/verified.json"
"$net_php" -r '
$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(($r["status"]??"")!=="ready"||($r["category"]??"")!=="مخلي صافي بالوزن"||($r["unit"]??"")!=="kg"||($r["branch_count"]??0)!==4||($r["location_count"]??0)<4||($r["expected_menu_items"]??0)!==3*$r["location_count"]||($r["recipes_verified"]??0)!==$r["expected_menu_items"]||($r["store_mappings_verified"]??-1)!==3*$r["store_count"]||array_sum($r["missing"]??[1])!==0)exit(1);
$prices=["feseekh"=>[1000,1400],"herring"=>[600,800],"sardine"=>[600,800]];
foreach($prices as $family=>[$branch,$store])if(($r["prices_egp"][$family]["branch_price"]??0)!==$branch||($r["prices_egp"][$family]["store_price"]??0)!==$store)exit(1);
$seen=[];foreach($r["samples"]??[] as $s){$family=$s["family"]??"";$u=parse_url($s["url"]??"");if(!isset($prices[$family])||isset($seen[$family])||($u["scheme"]??"")!=="https"||!in_array($u["host"]??"",["fasakhaninja.com","www.fasakhaninja.com"],true)||isset($u["user"])||isset($u["pass"])||isset($u["query"])||isset($u["fragment"])||!preg_match("#^/storage/products/[1-9][0-9]*/[^/]+$#D",$u["path"]??"")||!preg_match("/^[a-f0-9]{64}$/D",$s["sha256"]??""))exit(1);$seen[$family]=true;echo $family,"\t",$s["url"],"\t",$s["sha256"],"\n";}exit(count($seen)===3?0:1);
' "$net_stage/verified.json" > "$net_stage/urls.tsv"
while IFS=$'\t' read -r net_family net_url net_sha; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --connect-timeout 15 --max-time 60 "$net_url" -o "$net_stage/http-$net_family.image"
  printf '%s  %s\n' "$net_sha" "$net_stage/http-$net_family.image" | sha256sum --check --status
done < "$net_stage/urls.tsv"
cat "$net_stage/verified.json"
echo 'NET_FILLET_MENU_APPLIED_AND_VERIFIED'
