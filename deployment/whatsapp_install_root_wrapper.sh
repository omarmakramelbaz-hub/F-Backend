#!/usr/bin/env bash
set -euo pipefail
umask 077

app_root=${1:?Application root required}
release_commit=${2:?Pinned release commit required}
site_owner=${3:-fasakha}
[[ $(id -u) -eq 0 ]] || { echo 'Run this wrapper from the root terminal.' >&2; exit 1; }
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] || { echo 'Expected a complete release commit SHA.' >&2; exit 1; }
[[ "$site_owner" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo 'Invalid application owner.' >&2; exit 1; }
[[ $(id -u "$site_owner") -ne 0 ]] || { echo 'Application owner must not be root.' >&2; exit 1; }
cd "$app_root"
app_root=$(pwd -P)
git -c safe.directory="$app_root" cat-file -e "${release_commit}^{commit}"

runner_dir=$(mktemp -d /tmp/fasakhansta-whatsapp-run.XXXXXXXX)
cleanup() {
    unset whatsapp_setup_secret
    rm -rf -- "$runner_dir"
}
trap cleanup EXIT
git -c safe.directory="$app_root" show "${release_commit}:deployment/install_whatsapp_webhook.sh" > "$runner_dir/install.sh"
bash -n "$runner_dir/install.sh"
chmod 700 "$runner_dir/install.sh"
chown "$site_owner" "$runner_dir" "$runner_dir/install.sh"

# Read in the original root session, before su removes the controlling terminal.
if ! { exec 3<>/dev/tty; } 2>/dev/null; then
    echo 'Open an interactive root terminal, then rerun this wrapper.' >&2
    exit 1
fi
printf '\nPaste Meta App Secret (App settings > Basic > Show). Input is hidden.\n' >&3
IFS= read -r -s -p 'App Secret: ' whatsapp_setup_secret <&3
printf '\n' >&3
exec 3>&-
[[ -z "$whatsapp_setup_secret" || "$whatsapp_setup_secret" =~ ^[a-fA-F0-9]{32}$ ]] || { echo 'Expected the 32-character Meta App Secret.' >&2; exit 1; }

# The secret travels only through stdin, never CLI arguments, environment, or a file.
printf -v child_command '%q ' bash "$runner_dir/install.sh" "$app_root" "$release_commit" --secret-stdin
printf '%s\n' "$whatsapp_setup_secret" | su -s /bin/bash -c "$child_command" -- "$site_owner"
unset whatsapp_setup_secret
