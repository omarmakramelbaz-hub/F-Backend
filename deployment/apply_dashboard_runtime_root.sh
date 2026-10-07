#!/usr/bin/env bash
set -euo pipefail

# A minute scheduler must cover this exact checkout. Other cron jobs are retained.
dashboard_minute_scheduler() {
    awk -v project="$1" -v task="${2:-order-board:advance}" '
        function word(value, quote) {
            quote = substr(value, 1, 1)
            if ((quote == "\042" || quote == "\047") && substr(value, length(value), 1) == quote)
                return substr(value, 2, length(value) - 2)
            if (value ~ /[\042\047]/) return ""
            return value
        }
        function php(value) {
            value = word(value)
            return value ~ /^(php[0-9]*(\.[0-9]+)?|\/[^[:space:]]*\/php[0-9]*(\.[0-9]+)?)$/
        }
        /^[[:space:]]*#/ || NF < 6 { next }
        {
            sub(/[[:space:]]+#.*/, "")
            minute = 1
            for (i = 1; i <= 5; i++) if ($i != "*" && $i != "*/1") minute = 0
            if (!minute) next
            start = ($6 == "root" || $6 == "fasakha") ? 7 : 6
            command = ""
            for (i = start; i <= NF; i++) command = command (i == start ? "" : " ") $i
            gsub(/&&/, " \\&\\& ", command)
            split(command, args, /[[:space:]]+/)
            position = 1
            if (word(args[position]) == "cd") {
                position++
                if (args[position] == "--") position++
                if (word(args[position++]) != project || args[position++] != "&&") next
                if (!php(args[position++]) || word(args[position++]) != "artisan") next
            } else {
                if (!php(args[position++]) || word(args[position++]) != project "/artisan") next
            }
            if (word(args[position]) ~ /^(schedule:run|schedule:work)$/ || word(args[position]) == task) found = 1
        }
        END { exit !found }
    '
}

dashboard_runtime_main() {
    test "$(id -u)" = 0 || { echo 'Run this installer as root.'; return 1; }
    local release="${1:?Pass the reviewed release SHA}"
    [[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; return 1; }
    local project=/home/fasakha/public_html owner=fasakha
    test -d "$project/.git"
    test ! -L "$project"
    test ! -L "$project/.git"
    test "$(realpath "$project")" = "$project"
    id "$owner" >/dev/null
    command -v crontab >/dev/null
    local php_binary
    php_binary="$(command -v php)"

    runuser -u "$owner" -- git -C "$project" fetch --no-tags origin refs/heads/codex/unified-app-orders-20261003
    test "$(runuser -u "$owner" -- git -C "$project" rev-parse FETCH_HEAD)" = "$release"
    local previous
    previous="$(runuser -u "$owner" -- git -C "$project" rev-parse HEAD)"
    runuser -u "$owner" -- git -C "$project" merge-base --is-ancestor "$previous" "$release"

    # Repair only reviewed source paths and their existing parents; no recursive
    # ownership changes to uploads, dependencies, environment or customer data.
    local relative target parent
    while IFS= read -r -d '' relative; do
        [[ "$relative" != /* && "$relative" != *'..'* ]] || return 1
        target="$project/$relative"
        parent="$(dirname "$target")"
        while [[ "$parent" == "$project" || "$parent" == "$project/"* ]]; do
            test ! -L "$parent" || return 1
            if test -e "$parent"; then
                test -d "$parent" && test ! -L "$parent" || return 1
                test "$(realpath "$parent")" = "$parent" || return 1
                chown --no-dereference "$owner:$owner" "$parent"
                chmod u+rwx "$parent"
            fi
            test "$parent" != "$project" || break
            parent="$(dirname "$parent")"
        done
        test ! -L "$target" || return 1
        if test -e "$target"; then
            test "$(realpath "$target")" = "$target" || return 1
            chown --no-dereference "$owner:$owner" "$target"
            chmod u+rw "$target"
        fi
    done < <(runuser -u "$owner" -- git -C "$project" diff --name-only -z "$previous" "$release")
    for relative in bootstrap bootstrap/cache storage storage/app storage/framework storage/framework/views; do
        target="$project/$relative"
        test -d "$target" && test ! -L "$target" || return 1
        chown --no-dereference "$owner:$owner" "$target"
        chmod u+rwx "$target"
    done
    if test -f "$project/bootstrap/cache/config.php"; then
        test ! -L "$project/bootstrap/cache/config.php" || return 1
        chown --no-dereference "$owner:$owner" "$project/bootstrap/cache/config.php"
        chmod u+rw "$project/bootstrap/cache/config.php"
    fi

    local scheduled=0 cron_user cron_file
    for cron_user in "$owner" root; do
        if { crontab -u "$cron_user" -l 2>/dev/null || true; } | dashboard_minute_scheduler "$project"; then scheduled=1; fi
    done
    for cron_file in /etc/crontab /etc/cron.d/*; do
        if test -f "$cron_file" && dashboard_minute_scheduler "$project" < "$cron_file"; then scheduled=1; fi
    done

    runuser -u "$owner" -- bash -s -- "$project" "$release" <<'APP_RELEASE'
set -euo pipefail
cd "$1"
release="$2"
installer="$(mktemp)"
trap 'rm -f "$installer"' EXIT
git show "$release:deployment/apply_order_board.sh" > "$installer"
bash "$installer" "$release"
APP_RELEASE

    if test "$scheduled" = 0; then
        local cron_copy
        cron_copy="$(mktemp)"
        { crontab -u "$owner" -l 2>/dev/null || true; } > "$cron_copy"
        local cron_backup="/root/order-board-release-backups/cron-$(date -u +%Y%m%dT%H%M%SZ)-${release:0:8}"
        (umask 077; mkdir -p "$cron_backup"; cp "$cron_copy" "$cron_backup/crontab-before.txt")
        printf '\n# Accepted delivery orders: 15/90 minute lifecycle.\n* * * * * cd %s && %s artisan order-board:advance >> /dev/null 2>&1\n' "$project" "$php_binary" >> "$cron_copy"
        crontab -u "$owner" "$cron_copy"
        rm -f "$cron_copy"
        crontab -u "$owner" -l | dashboard_minute_scheduler "$project"
        echo 'ORDER CLOCK SCHEDULER READY: application-owner minute cron installed.'
        echo "Previous application-owner crontab: $cron_backup/crontab-before.txt"
    else
        echo 'ORDER CLOCK SCHEDULER READY: existing application minute scheduler retained.'
    fi
    # Existing installs sometimes run only order-board:advance, not schedule:run.
    # Add this dedicated dispatcher without enabling unrelated legacy schedules.
    local push_scheduled=0
    for cron_user in "$owner" root; do
        if { crontab -u "$cron_user" -l 2>/dev/null || true; } | dashboard_minute_scheduler "$project" dashboard-push:dispatch; then push_scheduled=1; fi
    done
    for cron_file in /etc/crontab /etc/cron.d/*; do
        if test -f "$cron_file" && dashboard_minute_scheduler "$project" dashboard-push:dispatch < "$cron_file"; then push_scheduled=1; fi
    done
    if test "$push_scheduled" = 0; then
        local push_cron push_backup
        push_cron="$(mktemp)"
        { crontab -u "$owner" -l 2>/dev/null || true; } > "$push_cron"
        push_backup="/root/order-board-release-backups/push-cron-$(date -u +%Y%m%dT%H%M%SZ)-${release:0:8}"
        (umask 077; mkdir -p "$push_backup"; cp "$push_cron" "$push_backup/crontab-before.txt")
        printf '\n# Continue saved manual notification campaigns.\n* * * * * cd %s && %s artisan dashboard-push:dispatch >> /dev/null 2>&1\n' "$project" "$php_binary" >> "$push_cron"
        crontab -u "$owner" "$push_cron"
        rm -f "$push_cron"
    fi
    echo 'MANUAL NOTIFICATION DISPATCH SCHEDULER READY'
    echo "DASHBOARD UPDATE READY: $release"
}

if [[ "${BASH_SOURCE[0]}" = "$0" ]]; then dashboard_runtime_main "$@"; fi
