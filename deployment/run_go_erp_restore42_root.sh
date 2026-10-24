#!/usr/bin/env bash
# Cancel the reviewed pending Preview 43 and restore the accepted Preview 42 download.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --complete
go_prior=2d9e23a5157782882382c797a44ad15c317ae845
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/preview42-restore.XXXXXXXX")
go_done=0
go_probe=
go_timer_paused=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if [[ -n "$go_probe" ]]; then rm -rf -- "$go_probe"; fi
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_PREVIEW42_RESTORE_PACKAGE_RETAINED %s\n' "$go_stage" >&2
    fi
    if ((go_timer_paused)); then
        printf '%s\n' GO_ERP_PREVIEW42_TIMER_PAUSED_PUBLICATION_NOT_CONFIRMED >&2
    fi
    exit "$go_status"
}
trap go_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
go_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_TERMINAL_PROMPT=0 \
        git --git-dir="$go_stage/release.git" -c credential.helper= \
        -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
git -c core.hooksPath=/dev/null init --bare --quiet "$go_stage/release.git"
go_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision" "$go_prior"
test "$(go_git rev-parse "$go_revision^{commit}")" = "$go_revision"
test "$(go_git rev-parse "$go_prior^{commit}")" = "$go_prior"
mkdir "$go_stage/package"
go_export() {
    local go_ref=$1 go_source=$2 go_target=$3
    mkdir -p -- "$(dirname -- "$go_stage/package/$go_target")"
    go_git show "$go_ref:$go_source" > "$go_stage/package/$go_target"
    chmod 0644 "$go_stage/package/$go_target"
}
for go_file in restore_go_erp_preview42_root.py complete_go_erp_windows_fixtures_root.py \
    upgrade_go_erp_windows_fixtures_root.py upgrade_go_erp_fullscreen_watcher_root.py; do
    go_export "$go_revision" "deployment/$go_file" "$go_file"
done
go_export "$go_prior" deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "$go_prior" "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in go_erp_program_application_patch.py refresh_desktop_dashboard_server_manifest.py; do
    go_export "$go_prior" "deployment/$go_file" "$go_file"
done
go_timer=go-erp-live-updates.timer
go_service=go-erp-live-updates.service
systemctl disable --now "$go_timer"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive
go_timer_paused=1
systemctl stop "$go_service"
for ((go_elapsed=0; go_elapsed<=180; go_elapsed+=10)); do
    go_state=$(systemctl show "$go_service" -p ActiveState --value)
    case "$go_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if ((go_elapsed==180)); then
                printf '%s\n' GO_ERP_EXISTING_WORKER_STILL_RUNNING_RETRY_LATER
                exit 75
            fi
            printf 'GO_ERP_WAITING_EXISTING_WORKER seconds=%s\n' "$go_elapsed"
            sleep 10 ;;
        *) printf '%s\n' GO_ERP_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done
go_git show "$go_revision:deployment/diagnose_go_erp_download.php" > "$go_stage/download-check.php"
if /usr/bin/python3 -I -B "$go_stage/package/restore_go_erp_preview42_root.py" \
    --package "$go_stage/package" --apply > "$go_stage/restored.json"; then
    cat "$go_stage/restored.json"
else
    go_status=$?
    cat "$go_stage/restored.json"
    exit "$go_status"
fi
/usr/bin/python3 -I -B -c '
import json,sys
v=json.load(open(sys.argv[1],encoding="utf-8"))
if not(v.get("format")==1 and v.get("kind")=="go-erp-preview42-restoration" and v.get("ok") is True
    and v.get("version")=="0.4.4-preview.42" and v.get("published") is True
    and v.get("application_contents_changed") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False):raise SystemExit(78)
' "$go_stage/restored.json"
go_probe=$(mktemp -d /var/tmp/go-erp-download-check.XXXXXXXX)
chmod 0755 "$go_probe"
cp -- "$go_stage/download-check.php" "$go_probe/check.php"
chmod 0644 "$go_probe/check.php"
if runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php \
    -d display_errors=0 -d log_errors=0 "$go_probe/check.php" > "$go_stage/web-check.txt"; then
    :
else
    printf '%s\n' GO_ERP_PREVIEW42_WEB_CHECK_FAILED >&2
    exit 1
fi
/usr/bin/python3 -I -B -c '
import json,sys
s=open(sys.argv[1],encoding="utf-8").read();prefix="GO_ERP_DOWNLOAD_DIAGNOSTIC "
if not s.startswith(prefix):raise SystemExit(78)
v=json.loads(s[len(prefix):])
if not(v.get("kind")=="go-erp-download-diagnostic" and v.get("mode")=="read_only"
    and v.get("status")=={"state":"ready","version":"0.4.4-preview.42"}
    and v.get("source_matches") is True and v.get("gui",{}).get("matches") is True
    and v.get("application_writes") is False and v.get("database_accessed") is False
    and v.get("credentials_disclosed") is False):raise SystemExit(78)
' "$go_stage/web-check.txt"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive
case "$(systemctl show "$go_service" -p ActiveState --value)" in
    inactive|failed) ;;
    *) printf '%s\n' GO_ERP_PREVIEW42_WORKER_RESTARTED >&2; exit 78 ;;
esac
go_timer_paused=0
go_done=1
printf '%s\n' GO_ERP_PREVIEW42_DOWNLOAD_READY_AUTOMATIC_UPDATES_DISABLED
