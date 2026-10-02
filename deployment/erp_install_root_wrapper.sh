#!/usr/bin/env bash
# Root-only permission bridge for the reviewed ERP installer.
# Temporarily grants the application owner access only to paths blocked by the
# existing root-owned checkout, then restores original ACLs. No database restore.
set -Eeuo pipefail
umask 077
export LC_ALL=C
unset TAR_OPTIONS GZIP POSIXLY_CORRECT

root=${1:?application root required}
backup=${2:?verified backup directory required}
target=${3:?reviewed ERP target required}
owner=${4:?owner id required}
owner_name=${5:?owner name required}
installer_commit=${6:?installer commit required}
base=7f34909e446f718d83ee1d05fb92bcaaac97cc42

[[ $EUID -eq 0 ]] || { echo 'STOP: run this wrapper as root.'; exit 1; }
[[ $(realpath "$root") == "$root" && $(realpath "$backup") == "$backup" ]] || { echo 'STOP: noncanonical path.'; exit 1; }
cd "$root"
for tool in git getfacl setfacl runuser mktemp stat php; do command -v "$tool" >/dev/null || { echo "STOP: missing $tool"; exit 1; }; done
app_user=$(stat -c %U .env)
[[ $app_user != root && -n $app_user ]] || { echo 'STOP: unexpected application owner.'; exit 1; }
[[ $(runuser -u "$app_user" -- git rev-parse HEAD) == "$base" ]] || { echo 'STOP: live commit is not the reviewed base.'; exit 1; }
[[ -z $(runuser -u "$app_user" -- git --no-optional-locks status --porcelain --untracked-files=no) ]] || { echo 'STOP: tracked checkout is not clean.'; exit 1; }
runuser -u "$app_user" -- git cat-file -e "$target^{commit}"
runuser -u "$app_user" -- git cat-file -e "$installer_commit:deployment/erp_install.sh"

run=$(mktemp -d "$(dirname "$root")/erp-root-bridge-$(date -u +%Y%m%dT%H%M%S)-XXXXXX")
acl="$run/acl.restore"
log="$run/private.log"
touch "$acl" "$log"
printf 'ROOT_BRIDGE_DIRECTORY=%s\n' "$run"

# Save only metadata that we may temporarily change.
paths=(.github .github/workflows deployment docs .git/HEAD .git/logs/HEAD)
for p in "${paths[@]}"; do
  [[ -e $p || -L $p ]] || { echo "STOP: expected path missing: $p"; exit 1; }
  getfacl -p -n -- "$p" >> "$acl"
done

restored=0
restore_acl() {
  code=$?
  trap - EXIT INT TERM HUP
  set +e
  if setfacl -P --restore="$acl" >>"$log" 2>&1; then
    restored=1
    echo 'ORIGINAL_DEPLOYMENT_ACLS_RESTORED'
  else
    echo "OPERATOR_REVIEW_REQUIRED; ACL_BACKUP=$acl"
    code=1
  fi
  exit "$code"
}
trap restore_acl EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

uid=$(id -u "$app_user")
for p in .github .github/workflows deployment docs; do
  setfacl -m "u:$uid:rwx" -- "$p"
done
for p in .git/HEAD .git/logs/HEAD; do
  setfacl -m "u:$uid:rw-" -- "$p"
done

# Verify the exact previously blocked capabilities before maintenance.
for p in .github/workflows deployment docs; do
  runuser -u "$app_user" -- test -w "$p"
done
runuser -u "$app_user" -- test -w .git/HEAD
runuser -u "$app_user" -- test -w .git/logs/HEAD
echo 'TEMPORARY_DEPLOYMENT_ACCESS_VERIFIED'

installer="$run/erp_install.sh"
runuser -u "$app_user" -- git show "$installer_commit:deployment/erp_install.sh" > "$installer"
chmod 700 "$installer"
bash -n "$installer"

set +e
runuser -u "$app_user" -- bash "$installer" "$root" "$backup" "$target" "$owner" "$owner_name"
status=$?
set -e

if [[ $status -ne 0 ]]; then
  echo "ERP_INSTALLER_EXIT=$status"
  # The installer should recover itself. Refuse to hide a mixed checkout.
  head=$(runuser -u "$app_user" -- git rev-parse HEAD || true)
  dirty=$(runuser -u "$app_user" -- git --no-optional-locks status --porcelain --untracked-files=no || true)
  if [[ $head != "$base" || -n $dirty ]]; then
    echo 'OPERATOR_REVIEW_REQUIRED; INSTALLER_LEFT_NONBASE_TRACKED_STATE'
    exit 1
  fi
  echo 'INSTALLER_FAILED_BUT_BASE_TRACKED_STATE_IS_CLEAN'
  exit "$status"
fi

[[ $(runuser -u "$app_user" -- git rev-parse HEAD) == "$target" ]] || { echo 'STOP: successful installer did not leave reviewed target checked out.'; exit 1; }
[[ -z $(runuser -u "$app_user" -- git --no-optional-locks status --porcelain --untracked-files=no) ]] || { echo 'STOP: target checkout is dirty after installer.'; exit 1; }
echo 'ROOT_PERMISSION_BRIDGE_INSTALL_VERIFIED'
