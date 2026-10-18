#!/usr/bin/env bash
set -euo pipefail
umask 077

directory_mode=check
directory_seen=0
directory_php="$(command -v php || true)"
directory_zip=/home/fasakha/Fasakhansta-Customer-Import-20261018.zip
directory_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
while (($#)); do
    case "$1" in
        --check|--apply)
            if ((directory_seen)); then printf '%s\n' 'Choose one mode.' >&2; exit 2; fi
            directory_seen=1; directory_mode="${1#--}"; shift ;;
        --php|--input-zip)
            if (($#<2)); then printf '%s\n' 'Option requires a value.' >&2; exit 2; fi
            if [[ "$1" == --php ]]; then directory_php="$2"; else directory_zip="$2"; fi
            shift 2 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

# WhatsApp sender capture and delivery lookup are independent of the private ZIP.
bash "$directory_code/apply_whatsapp_customer_capture_root.sh" "--$directory_mode" --php "$directory_php"
if [[ ! -f "$directory_zip" ]]; then
    printf '%s\n' "CUSTOMER_IMPORT_PENDING: private ZIP missing at $directory_zip. Upload it there and rerun this command."
    exit 0
fi
bash "$directory_code/../customer-directory-import-20261019/apply_customer_directory_import_root.sh" \
    "--$directory_mode" --input-zip "$directory_zip" --php "$directory_php"
printf '%s\n' 'CUSTOMER_DIRECTORY_AND_WHATSAPP_OK'
