#!/usr/bin/env bash
set -euo pipefail

desktop_pos_release_main() {
    test "$(id -u)" = 0 || { echo 'Run as root.' >&2; return 1; }
    local release="${1:?Pass the reviewed release SHA}" expected="${2:?Pass the current server SHA}"
    local project="${3:-/home/fasakha/public_html}" owner="${4:-fasakha}"
    [[ "$release" =~ ^[0-9a-f]{40}$ && "$expected" =~ ^[0-9a-f]{40}$ ]] || return 1
    [[ "$project" = /* && "$owner" =~ ^[a-z_][a-z0-9_-]*$ ]] || return 1
    test "$(realpath "$project")" = "$project" && test ! -L "$project" && test ! -L "$project/.git"
    test -d "$project/.git" && test -f "$project/artisan" && test -f "$project/vendor/autoload.php"
    test -f "$project/.env" && test ! -L "$project/.env"
    test "$(id -u "$owner")" != 0
    local php_binary previous backup helper old_config=0 changed=0 env_tmp=''
    php_binary="$(command -v php)"
    command -v flock >/dev/null
    exec 9>"$(dirname "$project")/.desktop-pos-release.lock"
    flock -n 9 || { echo 'Another desktop installation is running.' >&2; return 1; }
    previous="$(runuser -u "$owner" -- git -C "$project" rev-parse HEAD)"
    test "$previous" = "$expected" || { echo 'Server version changed. No application files were changed.' >&2; return 1; }
    test -z "$(runuser -u "$owner" -- git -C "$project" status --porcelain --untracked-files=no)" || { echo 'Save tracked server edits before activation.' >&2; return 1; }
    runuser -u "$owner" -- git -C "$project" fetch --no-tags --no-prune --no-recurse-submodules --refmap= origin refs/heads/codex/desktop-offline-pos-20261007
    test "$(runuser -u "$owner" -- git -C "$project" rev-parse FETCH_HEAD)" = "$release" || { echo 'Reviewed release changed. No application files were changed.' >&2; return 1; }
    runuser -u "$owner" -- git -C "$project" merge-base --is-ancestor "$previous" "$release" || { echo 'Server history diverged. No application files were changed.' >&2; return 1; }

    local backup_root="$(dirname "$project")/desktop-pos-release-backups"
    test ! -L "$backup_root"
    install -d -m 700 -o "$owner" -g "$owner" "$backup_root"
    backup="$(mktemp -d "$backup_root/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")"
    chown "$owner:$owner" "$backup"
    helper="$backup/desktop_pos_release.php"
    runuser -u "$owner" -- git -C "$project" show "$release:deployment/desktop_pos_release.php" > "$helper"
    chown "$owner:$owner" "$helper"; chmod 600 "$helper"
    runuser -u "$owner" -- "$php_binary" "$helper" preflight "$project"
    cp -p "$project/.env" "$backup/env"
    local cached
    for cached in config.php routes.php routes-v7.php; do
        test ! -L "$project/bootstrap/cache/$cached"
        if test -f "$project/bootstrap/cache/$cached"; then cp -p "$project/bootstrap/cache/$cached" "$backup/$cached"; fi
    done
    if test -f "$backup/config.php"; then old_config=1; fi
    runuser -u "$owner" -- "$php_binary" "$helper" backup "$project" "$backup"
    runuser -u "$owner" -- git -C "$project" branch "backup/before-desktop-pos-$(basename "$backup")" "$previous"

    # Repair only the reviewed source paths and their existing directory parents.
    local relative target parent
    while IFS= read -r -d '' relative; do
        [[ "$relative" != /* && "$relative" != *'..'* ]] || return 1
        target="$project/$relative"; parent="$(dirname "$target")"
        while [[ "$parent" = "$project" || "$parent" = "$project/"* ]]; do
            test ! -L "$parent"
            if test -e "$parent"; then
                test -d "$parent" && test "$(realpath "$parent")" = "$parent"
                chown --no-dereference "$owner:$owner" "$parent"; chmod u+rwx "$parent"
            fi
            test "$parent" != "$project" || break
            parent="$(dirname "$parent")"
        done
        test ! -L "$target"
        if test -e "$target"; then chown --no-dereference "$owner:$owner" "$target"; chmod u+rw "$target"; fi
    done < <(runuser -u "$owner" -- git -C "$project" diff --name-only -z "$previous" "$release")
    for relative in bootstrap bootstrap/cache storage storage/app storage/framework storage/framework/views; do
        target="$project/$relative"
        test -d "$target" && test ! -L "$target"
        chown --no-dereference "$owner:$owner" "$target"; chmod u+rwx "$target"
    done
    for cached in config.php routes.php routes-v7.php; do
        if test -f "$project/bootstrap/cache/$cached"; then chown "$owner:$owner" "$project/bootstrap/cache/$cached"; chmod u+rw "$project/bootstrap/cache/$cached"; fi
    done

    desktop_pos_release_rollback() {
        local failure=$? restored=1
        trap - ERR
        if test "$changed" = 1; then
            runuser -u "$owner" -- git -C "$project" checkout --detach "$previous" || restored=0
            cp -p "$backup/env" "$project/.env" || restored=0
            for cached in config.php routes.php routes-v7.php; do
                if test -f "$backup/$cached"; then cp -p "$backup/$cached" "$project/bootstrap/cache/$cached" || restored=0
                else rm -f "$project/bootstrap/cache/$cached" || restored=0; fi
            done
            runuser -u "$owner" -- bash -c 'cd "$1"; "$2" artisan view:clear' _ "$project" "$php_binary" || restored=0
        fi
        if test -n "$env_tmp"; then rm -f "$env_tmp"; fi
        if test "$restored" = 1; then echo "Activation failed; previous code/environment restored. Backup: $backup" >&2
        else echo "Activation failed; automatic restoration was incomplete. Check backup: $backup" >&2; fi
        echo 'Additive desktop tables and any recorded operations were retained.' >&2
        exit "$failure"
    }
    trap desktop_pos_release_rollback ERR
    changed=1
    runuser -u "$owner" -- git -C "$project" merge --ff-only "$release"
    runuser -u "$owner" -- bash -s -- "$project" "$php_binary" <<'DESKTOP_SCHEMA'
set -euo pipefail
cd "$1"
"$2" -l app/Services/Dashboard/DesktopPos.php
"$2" -l app/Services/Dashboard/DesktopPosQuote.php
"$2" -l app/Http/Controllers/Api/DesktopPosController.php
"$2" -l app/Http/Controllers/Dashboard/DesktopPosController.php
"$2" artisan migrate --path=database/migrations/2026_10_07_210000_create_desktop_pos.php --force
DESKTOP_SCHEMA
    env_tmp="$(mktemp "$project/.env.desktop-pos.XXXXXXXX")"
    sed -E '/^[[:blank:]]*(export[[:blank:]]+)?DESKTOP_POS_ENABLED[[:blank:]]*=/d' "$project/.env" > "$env_tmp"
    printf '\nDESKTOP_POS_ENABLED=true\n' >> "$env_tmp"
    chmod --reference="$project/.env" "$env_tmp"; chown "$owner:$owner" "$env_tmp"
    mv -f "$env_tmp" "$project/.env"; env_tmp=''
    test ! -L "$project/storage/app/desktop-pos"
    install -d -m 750 -o "$owner" -g "$owner" "$project/storage/app/desktop-pos"
    runuser -u "$owner" -- bash -s -- "$project" "$php_binary" "$old_config" <<'DESKTOP_CACHE'
set -euo pipefail
cd "$1"
if test "$3" = 1; then "$2" artisan config:cache; else "$2" artisan config:clear; fi
"$2" artisan route:clear
"$2" artisan view:clear
DESKTOP_CACHE
    runuser -u "$owner" -- "$php_binary" "$helper" verify "$project"
    trap - ERR
    echo "DESKTOP POS READY: $release"
    echo "Private backup: $backup"
}

if [[ "${BASH_SOURCE[0]}" = "$0" ]]; then desktop_pos_release_main "$@"; fi
