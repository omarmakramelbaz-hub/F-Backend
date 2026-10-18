#!/usr/bin/env bash
set -euo pipefail
umask 077

capture_mode=check
capture_seen=0
capture_php="$(command -v php || true)"
capture_root=/home/fasakha/public_html
capture_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
capture_stage_base=/home/fasakha/whatsapp-customer-capture-releases
capture_backups=/home/fasakha/whatsapp-customer-capture-backups

while (($#)); do
    case "$1" in
        --check|--apply)
            if ((capture_seen)); then printf '%s\n' 'Choose one of --check or --apply.' >&2; exit 2; fi
            capture_seen=1; capture_mode="${1#--}"; shift ;;
        --php)
            if (($#<2)); then printf '%s\n' '--php requires a value.' >&2; exit 2; fi
            capture_php="$2"; shift 2 ;;
        --help)
            printf '%s\n' 'Usage: bash apply_whatsapp_customer_capture_root.sh [--check | --apply] [--php PHP_BINARY]'
            printf '%s\n' 'Independent WhatsApp capture deployment; no private customer ZIP is required.'
            exit 0 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

if [[ "$(id -u)" != 0 ]]; then printf '%s\n' 'Run this guarded entry from the root terminal.' >&2; exit 2; fi
if [[ "$(hostname -s)" != srv1836524 ]]; then printf '%s\n' 'Unexpected server; expected srv1836524.' >&2; exit 2; fi
if [[ -z "$capture_php" || ! -x "$capture_php" ]]; then printf '%s\n' 'Select an executable PHP CLI with --php.' >&2; exit 2; fi
capture_uid="$(id -u fasakha 2>/dev/null || true)"
if [[ -z "$capture_uid" || "$capture_uid" == 0 || ! -d "$capture_root" || -L "$capture_root" || "$(realpath -- "$capture_root")" != "$capture_root" || "$(stat -c '%u' -- "$capture_root")" != "$capture_uid" ]]; then
    printf '%s\n' 'Expected fasakha-owned application root unavailable; stopped before bootstrap.' >&2; exit 2
fi
command -v runuser >/dev/null || { printf '%s\n' 'runuser is required.' >&2; exit 2; }
if ! runuser -u fasakha -- test -x "$capture_php"; then printf '%s\n' 'Selected PHP is not executable by fasakha.' >&2; exit 2; fi

# The release manifest is pinned by the committed root entry; only listed code is staged.
capture_manifest_sha=6d0347338faeb06785bee39d59da04e305e02be4379a21ca8c61c497b39cb2e2
if [[ ! -f "$capture_code/release-files.sha256" || -L "$capture_code/release-files.sha256" || "$(sha256sum -- "$capture_code/release-files.sha256" | cut -d ' ' -f 1)" != "$capture_manifest_sha" ]]; then
    printf '%s\n' 'RELEASE_MANIFEST_INVALID: stopped before application access.' >&2; exit 2
fi
(cd -- "$capture_code" && sha256sum --check --strict --quiet release-files.sha256) || { printf '%s\n' 'RELEASE_CODE_INVALID.' >&2; exit 2; }

if [[ -L "$capture_stage_base" ]]; then printf '%s\n' 'Private staging base must not be symlinked.' >&2; exit 2; fi
mkdir -p -- "$capture_stage_base"
if [[ "$(realpath -- "$capture_stage_base")" != "$capture_stage_base" ]]; then printf '%s\n' 'Private staging base resolves elsewhere.' >&2; exit 2; fi
chown -- fasakha:"$(id -gn fasakha)" "$capture_stage_base"
chmod 0700 -- "$capture_stage_base"
capture_stage="$(mktemp -d "$capture_stage_base/release-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")"
while read -r capture_hash capture_file; do
    if [[ ! "$capture_hash" =~ ^[a-f0-9]{64}$ || "$capture_file" == /* || "$capture_file" == *..* || -L "$capture_code/$capture_file" || ! -f "$capture_code/$capture_file" ]]; then
        printf '%s\n' 'RELEASE_MEMBER_INVALID.' >&2; exit 2
    fi
    mkdir -p -- "$capture_stage/$(dirname -- "$capture_file")"
    cp -- "$capture_code/$capture_file" "$capture_stage/$capture_file"
done < "$capture_code/release-files.sha256"
chmod -R u+rwX,go-rwx -- "$capture_stage"
chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$capture_stage"
printf '%s\n' "PRIVATE_STAGING=$capture_stage"

# Laravel bootstraps only as its application owner; no migration or outgoing send.
exec runuser -u fasakha -- bash "$capture_stage/INSTALL.sh" \
    --root "$capture_root" --backup-dir "$capture_backups" --php "$capture_php" "--$capture_mode"
