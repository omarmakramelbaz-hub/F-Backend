#!/usr/bin/env bash
set -euo pipefail
umask 077

app_root=${1:?Application root required}
release_commit=${2:?Pinned release commit required}
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] || { echo 'Expected a complete release commit SHA.' >&2; exit 1; }
[[ $(id -u) -ne 0 ]] || { echo 'Run as the application owner (fasakha), not root.' >&2; exit 1; }
cd "$app_root"
app_root=$(pwd -P)
[[ -f vendor/autoload.php && -f bootstrap/app.php && -f routes/api.php && -f .env ]] || { echo 'Incomplete Laravel application.' >&2; exit 1; }
[[ -O .env && -O routes/api.php ]] || { echo 'The current user must own .env and routes/api.php.' >&2; exit 1; }
git -c safe.directory="$app_root" cat-file -e "${release_commit}^{commit}"

files=(
    app/Support/WhatsAppWebhookProtocol.php
    app/Http/Controllers/Api/V1/WhatsAppWebhookController.php
    config/whatsapp.php
    routes/whatsapp.php
    database/migrations/2026_10_09_200000_create_whatsapp_webhook_events_table.php
    deployment/whatsapp_webhook_setup.php
    tests/whatsapp_webhook_protocol_test.php
)
backup_parent="$(dirname "$app_root")/whatsapp-release-backups"
mkdir -p "$backup_parent"
chmod 700 "$backup_parent"
backup=$(mktemp -d "$backup_parent/release-XXXXXXXX")
mkdir "$backup/staged"
cp -p .env "$backup/env.before"
cp -p routes/api.php "$backup/api.before.php"
new_files=()
changed=0
complete=0
rollback() {
    local result=$?
    trap - EXIT
    if [[ "$complete" -eq 0 && "$changed" -eq 1 ]]; then
        cp -p "$backup/env.before" .env
        cp -p "$backup/api.before.php" routes/api.php
        for file in "${new_files[@]}"; do rm -f -- "$file"; done
        php artisan config:clear >/dev/null 2>&1 || true
        php artisan route:clear >/dev/null 2>&1 || true
        echo "Installation stopped; original environment/routes restored. Backup: $backup" >&2
        echo 'Any newly created event table is retained; no existing table or data was dropped.' >&2
    fi
    exit "$result"
}
trap rollback EXIT

for file in "${files[@]}"; do
    mkdir -p "$backup/staged/$(dirname "$file")"
    git -c safe.directory="$app_root" show "${release_commit}:${file}" > "$backup/staged/$file"
    php -l "$backup/staged/$file" >/dev/null
    if [[ -L "$file" ]] || { [[ -e "$file" ]] && ! cmp -s "$file" "$backup/staged/$file"; }; then
        echo "Existing file differs; stopped for review: $file" >&2
        exit 1
    fi
done
php "$backup/staged/tests/whatsapp_webhook_protocol_test.php"
if [[ "${3:-}" == '--secret-stdin' ]]; then
    # The root wrapper reads silently before su creates a session without /dev/tty.
    IFS= read -r whatsapp_setup_secret
elif [[ -z "${3:-}" ]]; then
    if ! { exec 3<>/dev/tty; } 2>/dev/null; then
        echo 'No controlling terminal. Run the root wrapper to provide the secret securely.' >&2
        exit 1
    fi
    printf '\nPaste Meta App Secret (App settings > Basic > Show). Input is hidden.\n' >&3
    IFS= read -r -s -p 'App Secret: ' whatsapp_setup_secret <&3
    printf '\n' >&3
    exec 3>&-
else
    echo 'Unknown installer input mode.' >&2
    exit 1
fi
[[ -z "$whatsapp_setup_secret" || "$whatsapp_setup_secret" =~ ^[a-fA-F0-9]{32}$ ]] || { echo 'Expected the 32-character Meta App Secret.' >&2; exit 1; }

# Preserve simultaneous edits made after staging; never replace the server checkout.
cmp -s .env "$backup/env.before" && cmp -s routes/api.php "$backup/api.before.php" || { echo 'Environment/routes changed during preparation; rerun.' >&2; exit 1; }
changed=1
for file in "${files[@]}"; do
    if [[ ! -e "$file" ]]; then
        mkdir -p "$(dirname "$file")"
        new_files+=("$file")
        install -m 644 "$backup/staged/$file" "$file"
    fi
done
printf '%s' "$whatsapp_setup_secret" | php deployment/whatsapp_webhook_setup.php configure
unset whatsapp_setup_secret
php deployment/whatsapp_webhook_setup.php attach-route
php -l routes/api.php >/dev/null
php artisan config:clear
php artisan route:clear
# Only this migration is run. Other pending application migrations are untouched.
php artisan migrate --path=database/migrations/2026_10_09_200000_create_whatsapp_webhook_events_table.php --force
php deployment/whatsapp_webhook_setup.php smoke
php deployment/whatsapp_webhook_setup.php details
complete=1
printf 'INSTALLATION_OK\nBACKUP=%s\nRELEASE=%s\n' "$backup" "$release_commit"
