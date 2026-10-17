#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
login_session_root=/home/fasakha/public_html
login_session_revision="${1:?Full reviewed release SHA required}"
[[ "$login_session_revision" =~ ^[a-f0-9]{40}$ ]] || exit 1
shift
login_session_flags=()
for login_session_flag in "$@"; do
  case "$login_session_flag" in
    --check)
      [[ ${#login_session_flags[@]} == 0 ]] || { echo DUPLICATE_OPTION; exit 1; }
      login_session_flags+=("$login_session_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

login_session_tmp="$(mktemp -d /tmp/dashboard-login-session.XXXXXX)"
trap 'rm -rf -- "$login_session_tmp"' EXIT
chmod 0755 "$login_session_tmp"
login_session_helper=install_dashboard_login_session.py
runuser -u fasakha -- git -C "$login_session_root" show \
  "$login_session_revision:deployment/$login_session_helper" > "$login_session_tmp/$login_session_helper"
login_session_blob="$(runuser -u fasakha -- git -C "$login_session_root" rev-parse \
  "$login_session_revision:deployment/$login_session_helper")"
[[ "$(git hash-object --no-filters "$login_session_tmp/$login_session_helper")" == "$login_session_blob" ]] || exit 1
chmod 0644 "$login_session_tmp/$login_session_helper"
python3 "$login_session_tmp/$login_session_helper" --root "$login_session_root" \
  --release "$login_session_revision" "${login_session_flags[@]}"
