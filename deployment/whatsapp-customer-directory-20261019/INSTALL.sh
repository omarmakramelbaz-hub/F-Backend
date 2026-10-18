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

# Check every change before any application target is written.
"$capture_php" "$capture_bundle/install_whatsapp_customer_capture.php" --root "$capture_root" --check
"$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --dry-run
"$capture_php" "$capture_bundle/ui/install_customer_crm_whatsapp_ui.php" --root "$capture_root" --check
if [[ "$capture_mode" == check ]]; then
    printf '%s\n' 'WHATSAPP_DIRECTORY_CHECK_OK: capture and delivery lookup checked; application unchanged.'
    exit 0
fi

capture_ui_status=
capture_ui_backup=
capture_lookup_status=
capture_lookup_backup=
capture_receipt_uncertain=0

capture_receipt_field() {
    "$capture_php" -r '$r=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); $v=$r[$argv[2]]??null; if(!is_string($v)||$v==="")exit(1); echo $v;' -- "$1" "$2"
}

# Only receipts from successful writes made by this run authorize a restore.
# Continue to the independent UI restore if the lookup restore is refused.
capture_restore_new_changes() {
    local capture_restore_ok=1
    if ((capture_receipt_uncertain)); then capture_restore_ok=0; fi
    if [[ "$capture_lookup_status" == installed ]]; then
        if [[ -n "$capture_lookup_backup" ]] && "$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --restore-from "$capture_lookup_backup" --apply; then
            printf '%s\n' 'WHATSAPP_DIRECTORY_LOOKUP_RESTORED: exact predecessor restored.' >&2
        else
            capture_restore_ok=0
            printf '%s\n' 'WHATSAPP_DIRECTORY_LOOKUP_RESTORE_REFUSED: inspect the private lookup backup.' >&2
        fi
    fi
    if [[ "$capture_ui_status" == CRM_WHATSAPP_UI_INSTALLED ]]; then
        if [[ -n "$capture_ui_backup" ]] && "$capture_php" "$capture_bundle/ui/install_customer_crm_whatsapp_ui.php" --root "$capture_root" --restore-from "$capture_ui_backup" --apply; then
            printf '%s\n' 'WHATSAPP_DIRECTORY_UI_RESTORED: exact predecessor restored.' >&2
        else
            capture_restore_ok=0
            printf '%s\n' 'WHATSAPP_DIRECTORY_UI_RESTORE_REFUSED: inspect the private UI backup.' >&2
        fi
    fi
    if ((capture_restore_ok)); then
        printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: later installation failed; earlier changes from this run were restored. Pre-existing installations were retained.' >&2
    else
        printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: automatic recovery is incomplete; inspect the private backups before retrying.' >&2
    fi
}

if capture_ui_result="$("$capture_php" "$capture_bundle/ui/install_customer_crm_whatsapp_ui.php" --root "$capture_root" --backup-dir "$capture_backups/ui" --apply)"; then
    printf '%s\n' "$capture_ui_result"
else
    capture_exit=$?
    printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: UI installation failed; lookup and capture were not applied.' >&2
    exit "$capture_exit"
fi
if ! capture_ui_status="$(capture_receipt_field "$capture_ui_result" status)"; then
    printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: UI installation receipt is invalid; inspect the private UI backup before retrying.' >&2
    exit 1
fi
case "$capture_ui_status" in
    CRM_WHATSAPP_UI_INSTALLED)
        if ! capture_ui_backup="$(capture_receipt_field "$capture_ui_result" backup)"; then
            capture_restore_new_changes
            exit 1
        fi ;;
    CRM_WHATSAPP_UI_ALREADY_INSTALLED) ;;
    *) printf '%s\n' 'WHATSAPP_DIRECTORY_FAILED: UI installation status is unknown; inspect the private UI backup before retrying.' >&2; exit 1 ;;
esac

if capture_lookup_result="$("$capture_php" "$capture_bundle/lookup/install_phone_lookup.php" --root "$capture_root" --backup-dir "$capture_backups/lookup" --apply)"; then
    printf '%s\n' "$capture_lookup_result"
else
    capture_exit=$?
    capture_restore_new_changes
    exit "$capture_exit"
fi
if ! capture_lookup_status="$(capture_receipt_field "$capture_lookup_result" status)"; then
    capture_receipt_uncertain=1
    capture_restore_new_changes
    exit 1
fi
case "$capture_lookup_status" in
    installed)
        if ! capture_lookup_backup="$(capture_receipt_field "$capture_lookup_result" backup_file)"; then
            capture_restore_new_changes
            exit 1
        fi ;;
    already_installed) ;;
    *) capture_receipt_uncertain=1; capture_restore_new_changes; exit 1 ;;
esac
if "$capture_php" "$capture_bundle/install_whatsapp_customer_capture.php" --root "$capture_root" --backup-dir "$capture_backups" --apply; then
    printf '%s\n' 'WHATSAPP_DIRECTORY_OK: incoming sender capture and delivery lookup installed.'
else
    capture_exit=$?
    capture_restore_new_changes
    exit "$capture_exit"
fi
