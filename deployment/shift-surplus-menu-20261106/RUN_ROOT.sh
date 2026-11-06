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
40d35b7c49546506b3416b3ac91ff5afa6a1c41f91e7925ef5582d4db6700be2 add_menu.php
a95d7fff16aa5c3ca905a2efebb89977980cc123b0b1cfa71b0950316ef35367 test.php
60dbb8a6cbd0eb7062182243c096b17666ba0739626ef86d2575c24e124ecbb8 deployed-schema-columns.json
4a78be65c5fdc79a2d975e314b9456b2a28b087bf1cf1dc05ff591ca63ce5ba5 repair_images.php
b15b1fe4b7941056bef71e4da8c2f72ca42e62c97bd1dde3630aac818c757a16 test_image_repair.php
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
runuser -u fasakha -- "$surplus_php" "$surplus_stage/repair_images.php" --apply
runuser -u fasakha -- "$surplus_php" "$surplus_stage/repair_images.php" --check > "$surplus_stage/verified.json"
"$surplus_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($r["status"]??"")==="ready"&&($r["images_checked"]??0)>=4&&($r["desktop_compatible_images"]??0)===$r["images_checked"]?0:1);' "$surplus_stage/verified.json"
"$surplus_php" -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$seen=[];foreach($r["samples"]as$s){$v=$s["value"];$u=parse_url($s["url"]);if(!in_array($v,[1,5,10,100],true)||isset($seen[$v])||($u["scheme"]??"")!=="https"||!in_array($u["host"]??"",["fasakhaninja.com","www.fasakhaninja.com"],true)||!preg_match("#^/storage/(?:products/)?[1-9][0-9]*/egp-(?:1|5|10|100)\\.jpg$#D",$u["path"]??"")||!preg_match("/^[a-f0-9]{64}$/D",$s["sha256"]))exit(1);$seen[$v]=true;echo $v,"\t",$s["url"],"\t",$s["sha256"],"\n";}exit(count($seen)===4?0:1);' "$surplus_stage/verified.json" > "$surplus_stage/urls.tsv"
while IFS=$'\t' read -r surplus_value surplus_url surplus_sha; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    --connect-timeout 15 --max-time 60 "$surplus_url" -o "$surplus_stage/http-$surplus_value.jpg"
  printf '%s  %s\n' "$surplus_sha" "$surplus_stage/http-$surplus_value.jpg" | sha256sum --check --status
done < "$surplus_stage/urls.tsv"
cat "$surplus_stage/verified.json"
echo 'SHIFT_SURPLUS_IMAGES_REPAIRED_AND_HTTP_VERIFIED'
echo 'SHIFT_SURPLUS_MENU_APPLIED_AND_VERIFIED'
