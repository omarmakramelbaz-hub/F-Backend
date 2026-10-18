#!/usr/bin/env bash
set -euo pipefail
umask 077

migration_bundle="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
migration_mode=check
migration_root=/home/fasakha/public_html
migration_data="$migration_bundle/data/customers.json"
migration_backups=/home/fasakha/legacy-customer-import-backups
migration_branch=''
migration_php="$(command -v php || true)"
migration_expected=a49073c70061ea9ef497d7ec423af54f2666d7e2af16216a9b21e3a1340b2f14
migration_custom_data=0
migration_custom_hash=0
migration_mode_seen=0

while (($#)); do
    case "$1" in
        --check|--apply)
            if ((migration_mode_seen)); then printf '%s\n' 'Choose exactly one of --check or --apply.' >&2; exit 2; fi
            migration_mode_seen=1; migration_mode="${1#--}"; shift ;;
        --root|--data|--backup-dir|--branch|--php|--expected-data-sha256)
            migration_option="$1"
            if (($#<2)); then printf '%s\n' 'Option requires a value.' >&2; exit 2; fi
            case "$migration_option" in
                --root) migration_root="$2" ;;
                --data) migration_data="$2"; migration_custom_data=1 ;;
                --backup-dir) migration_backups="$2" ;;
                --branch) migration_branch="$2" ;;
                --php) migration_php="$2" ;;
                --expected-data-sha256) migration_expected="$2"; migration_custom_hash=1 ;;
            esac
            shift 2 ;;
        --help)
            printf '%s\n' 'Usage: bash INSTALL.sh [--check | --apply] [--root PATH] [--backup-dir PRIVATE_PATH] [--branch f:ID] [--php PHP_BINARY]'
            printf '%s\n' 'Default: check only; /home/fasakha/public_html; unified directory records searchable by authorized F delivery staff.'
            printf '%s\n' 'Custom data requires --data PATH --expected-data-sha256 HEX.'
            exit 0 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

if [[ -z "$migration_php" || ! -x "$migration_php" ]]; then printf '%s\n' 'Select an executable PHP 8.1+ CLI with --php.' >&2; exit 2; fi
if ((migration_custom_data && !migration_custom_hash)); then printf '%s\n' 'Custom data requires an explicit expected SHA-256.' >&2; exit 2; fi
if [[ ! "$migration_expected" =~ ^[a-f0-9]{64}$ ]]; then printf '%s\n' 'Invalid expected SHA-256.' >&2; exit 2; fi

# The normal entry point is a root terminal. Laravel must run as its app owner.
# Stage only this private bundle; never chown the live application tree.
migration_app_uid="$(id -u fasakha 2>/dev/null || true)"
if [[ -z "$migration_app_uid" || "$migration_app_uid" == 0 ]]; then printf '%s\n' 'The fasakha application account is unavailable; stopped before Laravel bootstrap.' >&2; exit 2; fi
if [[ ! -d "$migration_root" || "$(stat -c '%u' -- "$migration_root")" != "$migration_app_uid" ]]; then printf '%s\n' 'Laravel root must be owned by fasakha; stopped before bootstrap.' >&2; exit 2; fi
if [[ "$(id -u)" == 0 ]]; then
    migration_stage_base=/home/fasakha/customer-imports
    if [[ -L "$migration_stage_base" ]]; then printf '%s\n' 'Private staging base must not be symlinked.' >&2; exit 2; fi
    mkdir -p -- "$migration_stage_base"
    if [[ "$(realpath -- "$migration_stage_base")" != "$migration_stage_base" ]]; then printf '%s\n' 'Private staging base resolves elsewhere; stopped.' >&2; exit 2; fi
    chown -- fasakha:"$(id -gn fasakha)" "$migration_stage_base"
    chmod 0700 -- "$migration_stage_base"
    migration_stage="$(mktemp -d "$migration_stage_base/customer-import-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")"
    cp -a -- "$migration_bundle/." "$migration_stage/"
    if ((migration_custom_data)); then
        mkdir -p -- "$migration_stage/data"
        cp -- "$migration_data" "$migration_stage/data/customers.json"
    fi
    chmod -R u+rwX,go-rwx -- "$migration_stage"
    chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$migration_stage"
    if ! runuser -u fasakha -- test -x "$migration_php"; then printf '%s\n' 'Selected PHP is not executable by fasakha.' >&2; exit 2; fi
    printf '%s\n' "PRIVATE_STAGING=$migration_stage"
    exec runuser -u fasakha -- bash "$migration_stage/INSTALL.sh" "--$migration_mode" \
        --root "$migration_root" --backup-dir "$migration_backups" --branch "$migration_branch" \
        --php "$migration_php" --data "$migration_stage/data/customers.json" --expected-data-sha256 "$migration_expected"
fi
if [[ "$(id -u)" != "$migration_app_uid" ]]; then printf '%s\n' 'Run as root for private staging or as the fasakha application owner.' >&2; exit 2; fi

"$migration_php" -l "$migration_bundle/import_customers.php" >/dev/null
"$migration_php" -l "$migration_bundle/install_phone_lookup.php" >/dev/null

migration_import_args=(--root "$migration_root" --data "$migration_data" --branch "$migration_branch" --expected-data-sha256 "$migration_expected")

# Both preflights run before any code or customer database writes.
"$migration_php" -d memory_limit=1024M "$migration_bundle/import_customers.php" "${migration_import_args[@]}" --dry-run
"$migration_php" "$migration_bundle/install_phone_lookup.php" --root "$migration_root" --dry-run

if [[ "$migration_mode" == check ]]; then
    printf '%s\n' 'CHECK_OK: compatibility and input checked; application code and database unchanged.'
    exit 0
fi

migration_lookup_result="$("$migration_php" "$migration_bundle/install_phone_lookup.php" --root "$migration_root" --backup-dir "$migration_backups" --apply)"
printf '%s\n' "$migration_lookup_result"
migration_lookup_status="$("$migration_php" -r '$r=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $r["status"]??"";' -- "$migration_lookup_result")"

if "$migration_php" -d memory_limit=1024M "$migration_bundle/import_customers.php" "${migration_import_args[@]}" --backup-dir "$migration_backups" --apply; then
    printf '%s\n' 'IMPORT_OK: customer provenance and eligible profiles committed.'
else
    migration_import_exit=$?
    if [[ "$migration_lookup_status" == installed ]]; then
        migration_lookup_backup="$("$migration_php" -r '$r=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $r["backup_file"]??"";' -- "$migration_lookup_result")"
        if "$migration_php" "$migration_bundle/install_phone_lookup.php" --root "$migration_root" --restore-from "$migration_lookup_backup" --apply; then
            printf '%s\n' 'IMPORT_FAILED: customer transaction rolled back; newly installed lookup restored.' >&2
        else
            printf '%s\n' 'IMPORT_FAILED: automatic code restore was refused; inspect the verified private backup.' >&2
        fi
    else
        printf '%s\n' 'IMPORT_FAILED: customer transaction rolled back; existing lookup retained.' >&2
    fi
    exit "$migration_import_exit"
fi
