#!/usr/bin/env bash
set -euo pipefail
umask 077

migration_mode=check
migration_mode_seen=0
migration_input_zip=/root/Fasakhansta-Customer-Import-20261018.zip
migration_php="$(command -v php || true)"
migration_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
migration_root=/home/fasakha/public_html
migration_base=/home/fasakha/customer-imports
migration_backups=/home/fasakha/legacy-customer-import-backups

while (($#)); do
    case "$1" in
        --check|--apply)
            if ((migration_mode_seen)); then printf '%s\n' 'Choose exactly one of --check or --apply.' >&2; exit 2; fi
            migration_mode_seen=1; migration_mode="${1#--}"; shift ;;
        --input-zip|--php)
            if (($#<2)); then printf '%s\n' 'Option requires a value.' >&2; exit 2; fi
            if [[ "$1" == --input-zip ]]; then migration_input_zip="$2"; else migration_php="$2"; fi
            shift 2 ;;
        --help)
            printf '%s\n' 'Usage: bash apply_customer_directory_import_root.sh [--check | --apply] [--input-zip PRIVATE_ZIP] [--php PHP_BINARY]'
            printf '%s\n' 'Default ZIP: /root/Fasakhansta-Customer-Import-20261018.zip; default mode: check.'
            exit 0 ;;
        *) printf '%s\n' 'Unknown option.' >&2; exit 2 ;;
    esac
done

if [[ "$(id -u)" != 0 ]]; then printf '%s\n' 'Run this guarded entry from the root terminal.' >&2; exit 2; fi
if [[ "$(hostname -s)" != srv1836524 ]]; then printf '%s\n' 'Unexpected server; this entry is pinned to srv1836524.' >&2; exit 2; fi
if [[ ! -f "$migration_input_zip" ]]; then
    printf '%s\n' 'PRIVATE_ZIP_MISSING: upload the original private ZIP to /root/Fasakhansta-Customer-Import-20261018.zip, then rerun (or supply --input-zip PATH).' >&2
    exit 2
fi
if [[ -z "$migration_php" || ! -x "$migration_php" ]]; then printf '%s\n' 'Select an executable PHP CLI with --php.' >&2; exit 2; fi
command -v python3 >/dev/null || { printf '%s\n' 'python3 is required for safe ZIP extraction.' >&2; exit 2; }
command -v runuser >/dev/null || { printf '%s\n' 'runuser is required to avoid Laravel bootstrap as root.' >&2; exit 2; }
migration_uid="$(id -u fasakha 2>/dev/null || true)"
if [[ -z "$migration_uid" || "$migration_uid" == 0 || ! -d "$migration_root" || "$(stat -c '%u' -- "$migration_root")" != "$migration_uid" ]]; then
    printf '%s\n' 'Expected fasakha-owned application root is unavailable; stopped before bootstrap.' >&2; exit 2
fi
if ! runuser -u fasakha -- test -x "$migration_php"; then printf '%s\n' 'Selected PHP is not executable by fasakha.' >&2; exit 2; fi
migration_files=(INSTALL.sh import_customers.php install_phone_lookup.php phone_lookup_insertion.txt phone_lookup_v1_reference.txt README-import.md)
for migration_file in "${migration_files[@]}"; do
    if [[ ! -f "$migration_code/$migration_file" || -L "$migration_code/$migration_file" ]]; then printf '%s\n' 'Required committed V2 code file is missing or symlinked.' >&2; exit 2; fi
done

# Check the private data archive before creating any staging paths.
python3 - "$migration_input_zip" <<'PY'
import hashlib,sys
expected='f842c1a0b8106bb884f1cb915113fa0e366e1823b86e0e5435279ec901ae9e6e'
try:
    with open(sys.argv[1],'rb') as f:
        h=hashlib.file_digest(f,'sha256').hexdigest() if hasattr(hashlib,'file_digest') else None
        if h is None:
            f.seek(0); d=hashlib.sha256()
            for block in iter(lambda:f.read(1024*1024),b''): d.update(block)
            h=d.hexdigest()
    if h!=expected: raise ValueError('private ZIP checksum mismatch')
except Exception:
    print('PRIVATE_ZIP_INVALID: the original pinned private ZIP is required.',file=sys.stderr); sys.exit(2)
PY

if [[ -L "$migration_base" ]]; then printf '%s\n' 'Private staging base must not be symlinked.' >&2; exit 2; fi
mkdir -p -- "$migration_base"
if [[ "$(realpath -- "$migration_base")" != "$migration_base" ]]; then printf '%s\n' 'Private staging base resolves elsewhere.' >&2; exit 2; fi
chown -- fasakha:"$(id -gn fasakha)" "$migration_base"
chmod 0700 -- "$migration_base"
migration_stage="$(mktemp -d "$migration_base/customer-directory-v2-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")"
for migration_file in "${migration_files[@]}"; do cp -- "$migration_code/$migration_file" "$migration_stage/$migration_file"; done
mkdir -m 0700 -- "$migration_stage/data"

# Read just one exact member. Never extract V1 scripts, paths, or other ZIP members.
python3 - "$migration_input_zip" "$migration_stage/data/customers.json" <<'PY'
import hashlib,os,stat,sys,zipfile
archive_sha='f842c1a0b8106bb884f1cb915113fa0e366e1823b86e0e5435279ec901ae9e6e'
data_sha='a49073c70061ea9ef497d7ec423af54f2666d7e2af16216a9b21e3a1340b2f14'
member='Fasakhansta-Customer-Import-20261018/data/customers.json'
try:
    with open(sys.argv[1],'rb') as source:
        digest=hashlib.sha256()
        for block in iter(lambda:source.read(1024*1024),b''): digest.update(block)
        if digest.hexdigest()!=archive_sha: raise ValueError('archive changed')
        source.seek(0)
        with zipfile.ZipFile(source) as z:
            entries=[x for x in z.infolist() if x.filename==member]
            if len(entries)!=1: raise ValueError('ambiguous member')
            info=entries[0]
            if info.file_size!=39462976 or stat.S_ISLNK(info.external_attr>>16) or info.flag_bits&1: raise ValueError('unexpected member metadata')
            digest=hashlib.sha256(); total=0
            fd=os.open(sys.argv[2],os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
            with os.fdopen(fd,'wb') as target,z.open(info,'r') as data:
                for block in iter(lambda:data.read(1024*1024),b''):
                    total+=len(block)
                    if total>39462976: raise ValueError('member too large')
                    digest.update(block); target.write(block)
                target.flush(); os.fsync(target.fileno())
            if total!=39462976 or digest.hexdigest()!=data_sha: raise ValueError('extract checksum mismatch')
except Exception:
    print('PRIVATE_DATA_INVALID: exact pinned customers.json could not be extracted; application unchanged.',file=sys.stderr); sys.exit(2)
PY

chmod -R u+rwX,go-rwx -- "$migration_stage"
chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$migration_stage"
printf '%s\n' "PRIVATE_STAGING=$migration_stage"
exec runuser -u fasakha -- bash "$migration_stage/INSTALL.sh" "--$migration_mode" \
    --root "$migration_root" --backup-dir "$migration_backups" --php "$migration_php"
