#!/usr/bin/env bash
set -euo pipefail
umask 077

followup_mode=check
followup_seen=0
followup_root=/home/fasakha/public_html
followup_backups=/home/fasakha/customer-service-consent-backups
followup_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
followup_php=/opt/cpanel/ea-php82/root/usr/bin/php
if [[ ! -x "$followup_php" ]]; then followup_php="$(command -v php || true)"; fi
while (($#)); do
    case "$1" in
        --check|--apply)
            if ((followup_seen)); then printf '%s\n' 'Choose one mode.' >&2; exit 2; fi
            followup_seen=1; followup_mode="${1#--}"; shift ;;
        --php)
            if (($#<2)); then printf '%s\n' 'Missing PHP path.' >&2; exit 2; fi
            followup_php="$2"; shift 2 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -n "$followup_php"
test -x "$followup_php"
test -d "$followup_root"
test ! -L "$followup_root"
test "$(realpath -- "$followup_root")" = "$followup_root"
test "$(stat -c '%u' -- "$followup_root")" = "$(id -u fasakha)"
test -f "$followup_code/enable_imported_followup.php"
test ! -L "$followup_code/enable_imported_followup.php"
runuser -u fasakha -- test -x "$followup_php"

followup_stage="$(mktemp -d /tmp/customer-service-followup.XXXXXXXX)"
trap 'rm -rf -- "$followup_stage"' EXIT
cp -- "$followup_code/enable_imported_followup.php" "$followup_stage/enable_imported_followup.php"
chmod 0700 -- "$followup_stage"
chmod 0600 -- "$followup_stage/enable_imported_followup.php"
chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$followup_stage"

runuser -u fasakha -- "$followup_php" -d memory_limit=512M \
    "$followup_stage/enable_imported_followup.php" --root "$followup_root" --dry-run
if [[ "$followup_mode" == apply ]]; then
    runuser -u fasakha -- "$followup_php" -d memory_limit=512M \
        "$followup_stage/enable_imported_followup.php" --root "$followup_root" \
        --backup-dir "$followup_backups" --apply
fi
