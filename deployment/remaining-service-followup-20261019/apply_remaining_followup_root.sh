#!/usr/bin/env bash
set -euo pipefail
umask 077

remaining_mode=check
remaining_seen=0
remaining_root=/home/fasakha/public_html
remaining_backups=/home/fasakha/customer-service-consent-backups
remaining_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
remaining_php=/opt/cpanel/ea-php82/root/usr/bin/php
if [[ ! -x "$remaining_php" ]]; then remaining_php="$(command -v php || true)"; fi
while (($#)); do
    case "$1" in
        --check|--apply)
            if ((remaining_seen)); then printf '%s\n' 'Choose one mode.' >&2; exit 2; fi
            remaining_seen=1; remaining_mode="${1#--}"; shift ;;
        --php)
            if (($#<2)); then printf '%s\n' 'Missing PHP path.' >&2; exit 2; fi
            remaining_php="$2"; shift 2 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -n "$remaining_php"
test -x "$remaining_php"
test -d "$remaining_root"
test ! -L "$remaining_root"
test "$(realpath -- "$remaining_root")" = "$remaining_root"
test "$(stat -c '%u' -- "$remaining_root")" = "$(id -u fasakha)"
test -f "$remaining_code/enable_remaining_followup.php"
test ! -L "$remaining_code/enable_remaining_followup.php"
runuser -u fasakha -- test -x "$remaining_php"

remaining_stage="$(mktemp -d /tmp/customer-remaining-followup.XXXXXXXX)"
trap 'rm -rf -- "$remaining_stage"' EXIT
cp -- "$remaining_code/enable_remaining_followup.php" "$remaining_stage/enable_remaining_followup.php"
chmod 0700 -- "$remaining_stage"
chmod 0600 -- "$remaining_stage/enable_remaining_followup.php"
chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$remaining_stage"

runuser -u fasakha -- "$remaining_php" -d memory_limit=512M \
    "$remaining_stage/enable_remaining_followup.php" --root "$remaining_root" --dry-run
if [[ "$remaining_mode" == apply ]]; then
    runuser -u fasakha -- "$remaining_php" -d memory_limit=512M \
        "$remaining_stage/enable_remaining_followup.php" --root "$remaining_root" \
        --backup-dir "$remaining_backups" --apply
fi
