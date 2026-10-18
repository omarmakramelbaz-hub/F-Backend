#!/usr/bin/env bash
set -euo pipefail
umask 077

capture_bundle="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
capture_root=/home/fasakha/public_html
capture_backups=/home/fasakha/whatsapp-customer-capture-backups
capture_php="$(command -v php || true)"
capture_mode=check
capture_seen=0
while (($#)); do
    case "$1" in
        --check|--apply)
            if ((capture_seen)); then printf '%s\n' 'Choose one of --check or --apply.' >&2; exit 2; fi
            capture_seen=1; capture_mode="${1#--}"; shift ;;
        --root|--backup-dir|--php)
            if (($#<2)); then printf '%s\n' 'Option requires a value.' >&2; exit 2; fi
            case "$1" in
                --root) capture_root="$2" ;;
                --backup-dir) capture_backups="$2" ;;
                --php) capture_php="$2" ;;
            esac
            shift 2 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done
if [[ "$(id -u)" == 0 || -z "$capture_php" || ! -x "$capture_php" ]]; then
    printf '%s\n' 'Use the guarded root entry to stage and run as the application owner.' >&2; exit 2
fi

# Check both changes before either target is written.
"$capture_php" "$capture_bundle/install_whatsapp_customer_capture.php" --root "$capture_root" --check
"$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --dry-run
"$capture_php" "$capture_bundle/ui/install_customer_crm_whatsapp_ui.php" --root "$capture_root" --check
if [[ "$capture_mode" == check ]]; then
    printf '%s\n' 'WHATSAPP_DIRECTORY_CHECK_OK: capture and delivery lookup checked; application unchanged.'
    exit 0
fi

"$capture_php" "$capture_bundle/ui/install_customer_crm_whatsapp_ui.php" --root "$capture_root" --backup-dir "$capture_backups/ui" --apply
capture_lookup_result="$("$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --backup-dir "$capture_backups/lookup" --apply)"
printf '%s\n' "$capture_lookup_result"
capture_lookup_status="$("$capture_php" -r '$r=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $r["status"]??"";' -- "$capture_lookup_result")"
if "$capture_php" "$capture_bundle/install_whatsapp_customer_capture.php" --root "$capture_root" --backup-dir "$capture_backups" --apply; then
    printf '%s\n' 'WHATSAPP_DIRECTORY_OK: incoming sender capture and delivery lookup installed.'
else
    capture_exit=$?
    if [[ "$capture_lookup_status" == installed ]]; then
        capture_lookup_backup="$("$capture_php" -r '$r=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $r["backup_file"]??"";' -- "$capture_lookup_result")"
        if "$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --restore-from "$capture_lookup_backup" --apply; then
            printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: newly installed lookup restored to its exact predecessor.' >&2
        else
            printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: lookup restore refused; inspect the verified private backup.' >&2
        fi
    fi
    exit "$capture_exit"
fi
